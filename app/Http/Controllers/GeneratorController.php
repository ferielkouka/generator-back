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

        $systemPrompt = $this->getSystemPrompt();

        $response = \Illuminate\Support\Facades\Http::timeout(60)->withHeaders([
            'Authorization' => 'Bearer ' . config('services.groq.key'),
            'Content-Type'  => 'application/json',
        ])->post('https://api.groq.com/openai/v1/chat/completions', [
            'model'           => 'llama-3.3-70b-versatile',
            'messages'        => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens'      => 4000,
            'response_format' => ['type' => 'json_object'],
        ]);

        \Log::info('Groq response status: ' . $response->status());

        if ($response->status() === 429) {
            return response()->json(['error' => 'Quota IA dépassé, réessaie dans quelques secondes.'], 429);
        }

        if (!$response->successful()) {
            \Log::error('Groq error body: ' . $response->body());
            return response()->json(['error' => 'Erreur API Groq: ' . $response->status()], 502);
        }

        $rawJson = $response->json('choices.0.message.content');
        \Log::info('Raw JSON: ' . $rawJson);

        $generated = json_decode($rawJson, true);

        if (!$generated) {
            \Log::error('JSON parse error: ' . json_last_error_msg());
            return response()->json([
                'error' => 'Réponse IA invalide: ' . json_last_error_msg(),
                'raw'   => substr($rawJson, 0, 500)
            ], 422);
        }

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
      "xxx.component.ts": "import { Component } from '@angular/core';\nimport { CommonModule } from '@angular/common';\nimport { ReactiveFormsModule, FormGroup, FormControl } from '@angular/forms';\nimport { HttpClient } from '@angular/common/http';\nimport { Router } from '@angular/router';\nimport { environment } from '../../environment';\n\n@Component({\n  selector: 'app-xxx',\n  standalone: true,\n  imports: [CommonModule, ReactiveFormsModule],\n  templateUrl: './xxx.component.html',\n  styleUrls: ['./xxx.component.css']\n})\nexport class XxxComponent {\n  successMessage = '';\n  items: any[] = [];\n  form = new FormGroup({\n    field: new FormControl('')\n  });\n\n  constructor(private http: HttpClient, private router: Router) {}\n\n  ngOnInit() {\n    this.http.get(environment.apiUrl + '/xxx').subscribe({\n      next: (r: any) => { this.items = r; },\n      error: (e: any) => console.error(e)\n    });\n  }\n\n  onSubmit() {\n    this.http.post(environment.apiUrl + '/xxx', this.form.value).subscribe({\n      next: (r: any) => {\n        this.successMessage = '✅ Données enregistrées avec succès !';\n        this.form.reset();\n        this.items.push(r);\n      },\n      error: (e: any) => console.error(e)\n    });\n  }\n}",
      "xxx.component.html": "<div class=\"container\">\n  <h2>Title</h2>\n  <div *ngIf=\"successMessage\" class=\"success-msg\">{{successMessage}}</div>\n  <form [formGroup]=\"form\" (ngSubmit)=\"onSubmit()\">\n    <div class=\"form-group\">\n      <label>Field</label>\n      <input formControlName=\"field\" type=\"text\"/>\n    </div>\n    <button type=\"submit\">Ajouter</button>\n  </form>\n  <div class=\"list-container\">\n    <div *ngFor=\"let item of items\" class=\"list-item\">\n      <strong>{{item.field}}</strong>\n    </div>\n  </div>\n</div>",
      "xxx.component.css": "* { box-sizing: border-box; margin: 0; padding: 0; }\n.container {\n  width: 500px;\n  margin: 60px auto;\n  padding: 30px;\n  background: white;\n  border-radius: 12px;\n  box-shadow: 0 4px 20px rgba(0,0,0,0.1);\n}\nh2 {\n  text-align: center;\n  color: #333;\n  margin-bottom: 25px;\n  font-size: 24px;\n}\n.success-msg {\n  background: linear-gradient(135deg, #d4edda, #c3e6cb);\n  color: #155724;\n  padding: 12px;\n  border-radius: 8px;\n  margin-bottom: 20px;\n  text-align: center;\n}\n.form-group {\n  margin-bottom: 20px;\n}\n.form-group label {\n  display: block;\n  margin-bottom: 6px;\n  color: #555;\n  font-weight: 600;\n  font-size: 14px;\n}\n.form-group input,\n.form-group textarea,\n.form-group select {\n  width: 100%;\n  padding: 10px 14px;\n  border: 2px solid #e1e5e9;\n  border-radius: 8px;\n  font-size: 14px;\n  transition: border-color 0.3s;\n  outline: none;\n}\n.form-group input:focus,\n.form-group textarea:focus {\n  border-color: #007bff;\n}\n.form-group textarea {\n  height: 120px;\n  resize: vertical;\n}\nbutton[type=submit] {\n  width: 100%;\n  padding: 12px;\n  background: linear-gradient(135deg, #007bff, #0056b3);\n  color: white;\n  border: none;\n  border-radius: 8px;\n  font-size: 16px;\n  font-weight: 600;\n  cursor: pointer;\n  transition: transform 0.2s, box-shadow 0.2s;\n}\nbutton[type=submit]:hover {\n  transform: translateY(-2px);\n  box-shadow: 0 4px 15px rgba(0,123,255,0.4);\n}\n.list-container {\n  margin-top: 30px;\n}\n.list-item {\n  padding: 15px;\n  background: #f8f9fa;\n  border-radius: 8px;\n  margin-bottom: 10px;\n  border-left: 4px solid #007bff;\n  transition: all 0.2s;\n}\n.list-item:hover {\n  background: #e9ecef;\n  transform: translateX(5px);\n}"
    }
  },
  "laravel": {
    "controller": {"name":"XxxController","file":"app/Http/Controllers/XxxController.php","code":"<?php\nnamespace App\\Http\\Controllers;\nuse Illuminate\\Http\\Request;\nuse App\\Models\\Xxx;\n\nclass XxxController extends Controller\n{\n  public function store(Request $request)\n  {\n    $item = Xxx::create($request->all());\n    return response()->json($item, 201);\n  }\n\n  public function index()\n  {\n    return response()->json(Xxx::all());\n  }\n}"},
    "model": {"name":"Xxx","file":"app/Models/Xxx.php","code":"<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Xxx extends Model\n{\n  protected $fillable = ['field'];\n}"},
    "migration": {"file":"database/migrations/2024_01_01_create_xxx_table.php","code":"<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n  public function up(): void\n  {\n    Schema::create('xxx', function (Blueprint $table) {\n      $table->id();\n      $table->string('field');\n      $table->timestamps();\n    });\n  }\n\n  public function down(): void\n  {\n    Schema::dropIfExists('xxx');\n  }\n};"},
    "routes": "Route::post('/xxx',[XxxController::class,'store']);\nRoute::get('/xxx',[XxxController::class,'index']);"
  },
  "database": {"table":"xxx","fields":["id","field","created_at","updated_at"]}
}

Replace xxx/Xxx with the actual feature name and fields.
Always use https://generator-back-production.up.railway.app/api for API calls
Always import CommonModule and add to imports array.
Always include ReactiveFormsModule in imports array.
Always add successMessage='' and items: any[] = [] properties.
Always load items in ngOnInit with GET request.
Always push new item to items array after successful POST.
Always show success message with *ngIf="successMessage".
Always use beautiful CSS with shadows, gradients and transitions.
AuthController ALWAYS has BOTH login() AND register() methods.
PROMPT;
    }
}