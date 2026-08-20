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
                'model'           => 'openai/gpt-oss-20b',
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

        // ✅ FIX: crée la table si elle n'existe pas, OU synchronise les colonnes
        // manquantes si elle existe déjà (évite les erreurs "Column not found"
        // quand une feature est régénérée avec de nouveaux champs).
        if ($table) {
            try {
                if (!Schema::hasTable($table)) {
                    Schema::create($table, function (Blueprint $t) use ($fields) {
                        $t->id();
                        foreach ($fields as $field) {
                            if (in_array($field, ['id', 'created_at', 'updated_at'])) continue;
                            $t->string($field)->nullable();
                        }
                        $t->timestamps();
                    });
                    \Log::info("Table créée: {$table}");
                } else {
                    Schema::table($table, function (Blueprint $t) use ($fields, $table) {
                        foreach ($fields as $field) {
                            if (in_array($field, ['id', 'created_at', 'updated_at'])) continue;
                            if (!Schema::hasColumn($table, $field)) {
                                $t->string($field)->nullable();
                            }
                        }
                    });
                    \Log::info("Colonnes synchronisées: {$table}");
                }
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

CRITICAL - FILE UPLOAD REQUEST. Do NOT use FormControl for file fields. Instead:
- Add one "selectedFileX: File | null = null" property per file field, and an "onFileXSelected(event)" method that sets it from event.target.files[0].
- In the HTML, use <input type="file" (change)="onFileXSelected($event)"/> (no formControlName) for each file field.
- In onSubmit(), build a "new FormData()", append text fields via form.get(field)?.value and each file via its selectedFileX, then POST/PUT the FormData directly (not this.form.value).
- Laravel controller: add "use Illuminate\Support\Facades\Storage;" after the namespace line. For each file field, if ($request->hasFile('field_name')) store it with $request->file('field_name')->store('uploads', 'public') and put the returned path into the data array before Model::create()/update().
- Migration columns for files are nullable strings, named in snake_case exactly matching the FormData field names.
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
      "xxx.component.css": "* { box-sizing: border-box; margin: 0; padding: 0; }\n.container { width: 500px; margin: 60px auto; padding: 30px; background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }\n.success-msg { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; text-align: center; }\n.form-group { margin-bottom: 20px; }\n.form-group input { width: 100%; padding: 10px 14px; border: 2px solid #e1e5e9; border-radius: 8px; }\nbutton[type=submit] { width: 100%; padding: 12px; background: #007bff; color: white; border: none; border-radius: 8px; cursor: pointer; }\n.list-item { display: flex; justify-content: space-between; padding: 15px; background: #f8f9fa; border-radius: 8px; margin-bottom: 10px; border-left: 4px solid #007bff; }\n.actions button { padding: 6px 14px; border: none; border-radius: 6px; color: white; margin-left: 6px; cursor: pointer; }\n.actions button:first-child { background: #17a2b8; }\n.actions button:last-child { background: #dc3545; }"
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
Expand the CSS example above with gradients, box-shadows, hover transitions and better spacing — the example is minimal, make it visually polished, but keep the same class names and structure.
AuthController ALWAYS has BOTH login() AND register() methods.
PROMPT;
    }
}