<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\Conversation;
use App\Services\FileWriterService;
use App\Services\ProjectLauncherService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class GeneratorController extends Controller
{
    private FileWriterService $fileWriter;
    private ProjectLauncherService $launcher;

    public function __construct(FileWriterService $fileWriter, ProjectLauncherService $launcher)
    {
        $this->fileWriter = $fileWriter;
        $this->launcher = $launcher;
    }

    public function generate(Request $request)
    {
        \Log::info('Request reçue', $request->all());
        $request->validate(['message' => 'required|string']);

        $project = \App\Models\Project::firstOrCreate(
            ['name' => 'mon-projet'],
            ['path' => base_path('../../generator-front')]
        );

        \App\Models\Conversation::create([
            'project_id' => $project->id,
            'role'       => 'user',
            'message'    => $request->input('message'),
        ]);

        $feature = $this->detectFeature($request->input('message'));
        $existingFiles = $this->fileWriter->readExistingFiles($feature);

        $userMessage = $request->input('message');
        if (!empty($existingFiles)) {
            $truncated = substr($existingFiles, 0, 200);
            $userMessage .= "\n\n[FICHIERS ACTUELS]\n" . $truncated;
        }

        if ($this->detectFileUploadNeed($request->input('message'))) {
            $userMessage .= $this->getFileUploadInstructions();
        }

        $systemPrompt = $this->getSystemPrompt();

        $maxRetries = 2;
        $generated = null;
        $rawJson = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {

            $currentUserMessage = $userMessage;
            if ($attempt > 1) {
                $currentUserMessage .= "\n\nIMPORTANT: Ta réponse précédente ne contenait pas la section \"laravel\" complète (controller, model, migration, routes). Tu DOIS impérativement inclure cette section cette fois-ci, en plus du code Angular.";
            }

            $response = \Illuminate\Support\Facades\Http::timeout(60)->withHeaders([
                'Authorization' => 'Bearer ' . config('services.groq.key'),
                'Content-Type'  => 'application/json',
            ])->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'           => 'llama-3.3-70b-versatile',
                'messages'        => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $currentUserMessage],
                ],
                'max_tokens'      => 8000,
                'response_format' => ['type' => 'json_object'],
            ]);

            \Log::info("Groq response status (tentative {$attempt}): " . $response->status());

            if ($response->status() === 429) {
                return response()->json(['error' => 'Quota IA dépassé, réessaie dans quelques secondes.'], 429);
            }

            if (!$response->successful()) {
                \Log::error("Groq error body (tentative {$attempt}): " . $response->body());
                if ($attempt === $maxRetries) {
                    return response()->json(['error' => 'Erreur API Groq: ' . $response->status()], 502);
                }
                continue;
            }

            $rawJson = $response->json('choices.0.message.content');
            \Log::info("Raw JSON (tentative {$attempt}): " . $rawJson);

            $parsed = json_decode($rawJson, true);

            if (!$parsed) {
                \Log::error("JSON parse error (tentative {$attempt}): " . json_last_error_msg());
                if ($attempt === $maxRetries) {
                    return response()->json([
                        'error' => 'Réponse IA invalide: ' . json_last_error_msg(),
                        'raw'   => substr($rawJson, 0, 500)
                    ], 422);
                }
                continue;
            }

            $generated = $parsed;

            // Normaliser les clés à points AVANT de vérifier si la section laravel est complète
            if (isset($generated['angular.files'])) {
                $generated['angular'] = [
                    'component_name' => $generated['angular.component_name'] ?? '',
                    'route'          => $generated['angular.route'] ?? '/',
                    'files'          => $generated['angular.files'] ?? [],
                ];
            }

            if (isset($generated['laravel.controller'])) {
                $generated['laravel'] = [
                    'controller' => $generated['laravel.controller'] ?? [],
                    'model'      => $generated['laravel.model'] ?? [],
                    'migration'  => $generated['laravel.migration'] ?? [],
                    'routes'     => $generated['laravel.routes'] ?? '',
                ];
            }

            $hasLaravel = !empty($generated['laravel']['controller']);

            if ($hasLaravel) {
                \Log::info("Section Laravel présente dès la tentative {$attempt}.");
                break;
            }

            \Log::warning("Section Laravel manquante à la tentative {$attempt}, " . ($attempt < $maxRetries ? 'nouvelle tentative...' : 'abandon après max de tentatives.'));
        }

        if (empty($generated['angular']['files'])) {
            \Log::error('IA na pas généré de code Angular !');
            return response()->json([
                'error' => 'L\'IA n\'a pas généré de code Angular - réessaie !',
                'raw'   => substr($rawJson, 0, 500)
            ], 422);
        }

        $summary = sprintf(
            'action:%s feature:%s files:%s',
            $generated['action'] ?? 'create',
            $generated['feature'] ?? 'unknown',
            implode(',', array_keys($generated['angular']['files'] ?? []))
        );

        \App\Models\Conversation::create([
            'project_id' => $project->id,
            'role'       => 'assistant',
            'message'    => $summary,
        ]);

        $writtenFiles = $this->fileWriter->write($generated);

        $table = $generated['database']['table'] ?? null;
        $fields = $generated['database']['fields'] ?? [];

        if ($table && !Schema::hasTable($table)) {
            try {
                Schema::create($table, function(Blueprint $t) use ($fields) {
                    $t->id();
                    foreach ($fields as $field) {
                        if (in_array($field, ['id', 'created_at', 'updated_at'])) continue;
                        $t->string($field)->nullable();
                    }
                    $t->timestamps();
                });
                \Log::info("Table créée: {$table}");
            } catch (\Exception $e) {
                \Log::error('Schema error: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success'       => true,
            'feature'       => $generated['feature'] ?? 'unknown',
            'action'        => $generated['action'] ?? 'create',
            'generated'     => $generated,
            'files_written' => $writtenFiles,
        ]);
    }

    private function detectFeature(string $message): string
    {
        $message = strtolower($message);
        $items = $this->fileWriter->listGeneratedFolders();

        foreach ($items as $folderName) {
            if (str_contains($message, $folderName)) {
                return $folderName;
            }
        }

        return $items[0] ?? '';
    }

    /**
     * Détecte si la demande implique un upload de fichier (PDF, image, document),
     * et enrichit le message avec des instructions précises pour que Groq génère
     * un vrai formulaire multipart fonctionnel (Angular FormData + Laravel Storage).
     */
    private function detectFileUploadNeed(string $message): bool
    {
        $keywords = ['fichier', 'upload', 'pdf', 'image', 'photo', 'document', 'pièce jointe', 'télécharger'];
        $message = strtolower($message);

        foreach ($keywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function getFileUploadInstructions(): string
    {
        return <<<'TXT'

CRITICAL - THIS REQUEST INVOLVES FILE UPLOAD. You MUST copy this EXACT pattern, do NOT use FormControl for file fields under any circumstance:

"xxx.component.ts": "import { Component, OnInit } from '@angular/core';\nimport { CommonModule } from '@angular/common';\nimport { ReactiveFormsModule, FormGroup, FormControl } from '@angular/forms';\nimport { HttpClient } from '@angular/common/http';\nimport { environment } from '../../environment';\n\n@Component({\n  selector: 'app-xxx',\n  standalone: true,\n  imports: [CommonModule, ReactiveFormsModule],\n  templateUrl: './xxx.component.html',\n  styleUrls: ['./xxx.component.css']\n})\nexport class XxxComponent implements OnInit {\n  successMessage = '';\n  items: any[] = [];\n  selectedId: number | null = null;\n  selectedFile1: File | null = null;\n  selectedFile2: File | null = null;\n  form = new FormGroup({\n    titre: new FormControl('')\n  });\n\n  constructor(private http: HttpClient) {}\n\n  ngOnInit() {\n    this.http.get(environment.apiUrl + '/xxx').subscribe({\n      next: (r: any) => { this.items = r; },\n      error: (e: any) => console.error(e)\n    });\n  }\n\n  onFile1Selected(event: any) {\n    this.selectedFile1 = event.target.files[0];\n  }\n\n  onFile2Selected(event: any) {\n    this.selectedFile2 = event.target.files[0];\n  }\n\n  onSubmit() {\n    const formData = new FormData();\n    formData.append('titre', this.form.get('titre')?.value);\n    if (this.selectedFile1) { formData.append('fichier_examen', this.selectedFile1); }\n    if (this.selectedFile2) { formData.append('fichier_correction', this.selectedFile2); }\n    this.http.post(environment.apiUrl + '/xxx', formData).subscribe({\n      next: (r: any) => {\n        this.successMessage = '✅ Enregistré avec succès !';\n        this.form.reset();\n        this.selectedFile1 = null;\n        this.selectedFile2 = null;\n        this.items.push(r);\n      },\n      error: (e: any) => console.error(e)\n    });\n  }\n\n  onDelete(id: number) {\n    this.http.delete(environment.apiUrl + '/xxx/' + id).subscribe({\n      next: () => {\n        this.items = this.items.filter((i: any) => i.id !== id);\n        this.successMessage = '✅ Supprimé avec succès !';\n      },\n      error: (e: any) => console.error(e)\n    });\n  }\n}"

Use this exact structure adapted to the actual field names. The HTML file input must be: <input type="file" (change)="onFile1Selected($event)"/> — NEVER formControlName on a file input.

For the Laravel controller, ALWAYS add "use Illuminate\Support\Facades\Storage;" right after the namespace line, and handle files like this:

public function store(Request $request)
{
    $data = $request->only(['titre']);
    if ($request->hasFile('fichier_examen')) {
        $data['fichier_examen'] = $request->file('fichier_examen')->store('uploads', 'public');
    }
    if ($request->hasFile('fichier_correction')) {
        $data['fichier_correction'] = $request->file('fichier_correction')->store('uploads', 'public');
    }
    $item = Xxx::create($data);
    return response()->json($item, 201);
}

Migration columns for files must be nullable string columns using the exact snake_case names used in formData.append() (e.g. fichier_examen, fichier_correction), matching exactly what the controller expects.
TXT;
    }

    private function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a code generator. Output ONLY this JSON structure with real code:

{
  "feature": "name",
  "action": "create",
  "angular": {
    "component_name": "XxxComponent",
    "route": "/xxx",
    "files": {
      "xxx.component.ts": "import { Component, OnInit } from '@angular/core';\nimport { CommonModule } from '@angular/common';\nimport { ReactiveFormsModule, FormGroup, FormControl } from '@angular/forms';\nimport { HttpClient } from '@angular/common/http';\nimport { Router } from '@angular/router';\nimport { environment } from '../../environment';\n\n@Component({\n  selector: 'app-xxx',\n  standalone: true,\n  imports: [CommonModule, ReactiveFormsModule],\n  templateUrl: './xxx.component.html',\n  styleUrls: ['./xxx.component.css']\n})\nexport class XxxComponent implements OnInit {\n  successMessage = '';\n  items: any[] = [];\n  selectedId: number | null = null;\n  form = new FormGroup({\n    field: new FormControl('')\n  });\n\n  constructor(private http: HttpClient, private router: Router) {}\n\n  ngOnInit() {\n    this.http.get(environment.apiUrl + '/xxx').subscribe({\n      next: (r: any) => { this.items = r; },\n      error: (e: any) => console.error(e)\n    });\n  }\n\n  onSubmit() {\n    if (this.selectedId) {\n      this.http.put(environment.apiUrl + '/xxx/' + this.selectedId, this.form.value).subscribe({\n        next: (r: any) => {\n          this.successMessage = '✅ Mis à jour avec succès !';\n          this.form.reset();\n          this.items = this.items.map((i: any) => i.id === this.selectedId ? r : i);\n          this.selectedId = null;\n        },\n        error: (e: any) => console.error(e)\n      });\n    } else {\n      this.http.post(environment.apiUrl + '/xxx', this.form.value).subscribe({\n        next: (r: any) => {\n          this.successMessage = '✅ Données enregistrées avec succès !';\n          this.form.reset();\n          this.items.push(r);\n        },\n        error: (e: any) => console.error(e)\n      });\n    }\n  }\n\n  onEdit(item: any) {\n    this.selectedId = item.id;\n    this.form.patchValue(item);\n  }\n\n  onDelete(id: number) {\n    this.http.delete(environment.apiUrl + '/xxx/' + id).subscribe({\n      next: () => {\n        this.items = this.items.filter((i: any) => i.id !== id);\n        this.successMessage = '✅ Supprimé avec succès !';\n      },\n      error: (e: any) => console.error(e)\n    });\n  }\n}",
      "xxx.component.html": "<div class=\"container\">\n  <h2>Title</h2>\n  <div *ngIf=\"successMessage\" class=\"success-msg\">{{successMessage}}</div>\n  <form [formGroup]=\"form\" (ngSubmit)=\"onSubmit()\">\n    <div class=\"form-group\">\n      <label>Field</label>\n      <input formControlName=\"field\" type=\"text\"/>\n    </div>\n    <button type=\"submit\">{{ selectedId ? 'Modifier' : 'Ajouter' }}</button>\n  </form>\n  <div class=\"list-container\">\n    <div *ngFor=\"let item of items\" class=\"list-item\">\n      <strong>{{item.field}}</strong>\n      <div class=\"actions\">\n        <button (click)=\"onEdit(item)\">Modifier</button>\n        <button (click)=\"onDelete(item.id)\">Supprimer</button>\n      </div>\n    </div>\n  </div>\n</div>",
      "xxx.component.css": "* { box-sizing: border-box; margin: 0; padding: 0; }\n.container {\n  width: 500px;\n  margin: 60px auto;\n  padding: 30px;\n  background: white;\n  border-radius: 12px;\n  box-shadow: 0 4px 20px rgba(0,0,0,0.1);\n}\nh2 {\n  text-align: center;\n  color: #333;\n  margin-bottom: 25px;\n  font-size: 24px;\n}\n.success-msg {\n  background: linear-gradient(135deg, #d4edda, #c3e6cb);\n  color: #155724;\n  padding: 12px;\n  border-radius: 8px;\n  margin-bottom: 20px;\n  text-align: center;\n}\n.form-group {\n  margin-bottom: 20px;\n}\n.form-group label {\n  display: block;\n  margin-bottom: 6px;\n  color: #555;\n  font-weight: 600;\n  font-size: 14px;\n}\n.form-group input,\n.form-group textarea,\n.form-group select {\n  width: 100%;\n  padding: 10px 14px;\n  border: 2px solid #e1e5e9;\n  border-radius: 8px;\n  font-size: 14px;\n  transition: border-color 0.3s;\n  outline: none;\n}\n.form-group input:focus,\n.form-group textarea:focus {\n  border-color: #007bff;\n}\nbutton[type=submit] {\n  width: 100%;\n  padding: 12px;\n  background: linear-gradient(135deg, #007bff, #0056b3);\n  color: white;\n  border: none;\n  border-radius: 8px;\n  font-size: 16px;\n  font-weight: 600;\n  cursor: pointer;\n  transition: transform 0.2s, box-shadow 0.2s;\n}\nbutton[type=submit]:hover {\n  transform: translateY(-2px);\n  box-shadow: 0 4px 15px rgba(0,123,255,0.4);\n}\n.list-container {\n  margin-top: 30px;\n}\n.list-item {\n  display: flex;\n  align-items: center;\n  justify-content: space-between;\n  gap: 10px;\n  flex-wrap: wrap;\n  padding: 15px;\n  background: #f8f9fa;\n  border-radius: 8px;\n  margin-bottom: 10px;\n  border-left: 4px solid #007bff;\n  transition: all 0.2s;\n}\n.list-item:hover {\n  background: #e9ecef;\n  transform: translateX(5px);\n}\n.actions button {\n  padding: 6px 14px;\n  border: none;\n  border-radius: 6px;\n  font-size: 13px;\n  font-weight: 600;\n  cursor: pointer;\n  margin-left: 6px;\n  color: white;\n  transition: transform 0.2s;\n}\n.actions button:first-child {\n  background: linear-gradient(135deg, #17a2b8, #117a8b);\n}\n.actions button:last-child {\n  background: linear-gradient(135deg, #dc3545, #a71d2a);\n}\n.actions button:hover {\n  transform: translateY(-1px);\n}"
    }
  },
  "laravel": {
    "controller": {"name":"XxxController","file":"app/Http/Controllers/XxxController.php","code":"<?php\nnamespace App\\Http\\Controllers;\nuse Illuminate\\Http\\Request;\nuse App\\Models\\Xxx;\n\nclass XxxController extends Controller\n{\n  public function index()\n  {\n    return response()->json(Xxx::all());\n  }\n\n  public function store(Request $request)\n  {\n    $item = Xxx::create($request->all());\n    return response()->json($item, 201);\n  }\n\n  public function update(Request $request, $id)\n  {\n    $item = Xxx::findOrFail($id);\n    $item->update($request->all());\n    return response()->json($item);\n  }\n\n  public function destroy($id)\n  {\n    Xxx::destroy($id);\n    return response()->json(['message' => 'Deleted']);\n  }\n}"},
    "model": {"name":"Xxx","file":"app/Models/Xxx.php","code":"<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Xxx extends Model\n{\n  protected $fillable = ['field'];\n}"},
    "migration": {"file":"database/migrations/2024_01_01_create_xxx_table.php","code":"<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n  public function up(): void\n  {\n    Schema::create('xxx', function (Blueprint $table) {\n      $table->id();\n      $table->string('field');\n      $table->timestamps();\n    });\n  }\n\n  public function down(): void\n  {\n    Schema::dropIfExists('xxx');\n  }\n};"},
    "routes": "Route::get('/xxx',[XxxController::class,'index']);\nRoute::post('/xxx',[XxxController::class,'store']);\nRoute::put('/xxx/{id}',[XxxController::class,'update']);\nRoute::delete('/xxx/{id}',[XxxController::class,'destroy']);"
  },
  "database": {"table":"xxx","fields":["id","field","created_at","updated_at"]}
}

Replace xxx/Xxx with the actual feature name and fields.
Always use environment.apiUrl for API calls, never hardcode the full URL, never add '/api' prefix since environment.apiUrl already includes it.
Always import CommonModule and add to imports array.
Always include ReactiveFormsModule in imports array.
Always add successMessage='' and items: any[] = [] properties.
Always add selectedId: number | null = null property for edit/update tracking.
Always load items in ngOnInit with GET request.
CRITICAL UPDATE PATTERN: onSubmit() must check "if (this.selectedId)" to decide between PUT (update) and POST (create). NEVER put an object directly in http.put() from a button click without first loading it into the form via an onEdit(item) method that sets selectedId and calls form.patchValue(item).
Always provide an onEdit(item) method that sets this.selectedId = item.id and calls this.form.patchValue(item), for every component with edit/update capability.
Always reset selectedId to null after a successful update.
Always show success message with *ngIf="successMessage".
For CRUD components (with edit/delete), ALWAYS generate exactly 4 Laravel routes: GET (index), POST (store), PUT with {id} (update), DELETE with {id} (destroy). Output each route on its own line separated by a real newline character, never as literal backslash-n text.
Always use beautiful CSS with shadows, gradients and transitions, including styled action buttons (Modifier/Supprimer) with distinct colors.
AuthController ALWAYS has BOTH login() AND register() methods.
PROMPT;
    }
}