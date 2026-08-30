<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class FileWriterService
{
    private string $angularPath;   // chemin RELATIF dans le repo GitHub (pas un chemin disque)
    private string $laravelPath;
    private GitHubWriterService $github;      // repo "app" (generated-app / Angular)
    private GitHubWriterService $githubBack;  // repo "back" (generator-back / Laravel)

    public function __construct(GitHubWriterService $github)
    {
        $this->angularPath = 'src/app';
        $this->laravelPath = base_path();
        $this->github = $github;

        // Instance dédiée au repo "back", pour committer les fichiers Laravel générés
        // et qu'ils survivent aux redémarrages/redéploiements du conteneur (Render en
        // particulier redémarre très fréquemment sur le plan gratuit, ce qui efface
        // sinon systématiquement le disque local à chaque fois).
        $this->githubBack = new GitHubWriterService(
            config('services.github_back.owner'),
            config('services.github_back.repo'),
            config('services.github_back.branch'),
            config('services.github_back.token'),
        );
    }

    public function write(array $generated): array
    {
        $writtenFiles = [];
        $action = $generated['action'] ?? 'create';

        $this->fixEnvironment();

        if (isset($generated['angular']['files'])) {
            $componentName = $generated['angular']['component_name'];
            $componentFolder = strtolower(str_replace('Component', '', $componentName));
            $componentDir = $this->angularPath . '/' . $componentFolder;

            $filesToCommit = [];

            foreach ($generated['angular']['files'] as $filename => $code) {
                if (str_ends_with($filename, '.ts')) {
                    $code = $this->cleanAngularCode($code);
                    $code = $this->enforceComponentNameConsistency($code, $componentName, $componentFolder);
                    $code = $this->fixAngularImports($code);
                    $code = $this->fixAngularComponent($code);
                    $code = $this->fixApiUrl($code);
                    $code = $this->fixStorageUrl($code);
                    $code = $this->ensureEnvironmentExposed($code);
                    $code = $this->fixDuplicateApiPath($code);
                    $code = $this->fixSuccessMessage($code);
                    $code = $this->fixCommonModule($code);
                    $code = $this->fixArrayType($code);
                    $code = $this->fixOptionalValueArithmetic($code);
                    $code = $this->fixUpdateIdPattern($code);
                    $code = $this->fixDirectUpdatePattern($code);
                    $code = $this->ensureRequiredImports($code);
                    $code = $this->balanceBraces($code);
                } elseif (str_ends_with($filename, '.html')) {
                    $code = $this->fixUpdateIdPatternHtml($code);
                    $code = $this->fixStorageUrl($code);
                } elseif (str_ends_with($filename, '.css')) {
                    $code = $this->ensureButtonStyles($code);
                }
                // ✅ FIX: force le nom de fichier RÉEL des fichiers de composant à
                // correspondre exactement à $componentFolder (celui utilisé dans
                // templateUrl/styleUrls/imports partout ailleurs), au lieu du nom
                // brut choisi par Mistral dans le JSON (ex: "offre-recrutement.
                // component.ts" avec un tiret, alors que le dossier et les imports
                // utilisent "offrerecrutement" sans tiret). Sans ça, app.routes.ts
                // référence un fichier qui n'existe pas sous ce nom exact -> échec
                // de build Vercel (TS2307). On ne touche PAS aux fichiers annexes
                // (ex: un pipe personnalisé "file-size.pipe.ts") pour ne pas les
                // écraser par erreur avec le nom du composant.
                $normalizedFilename = $filename;
                if (preg_match('/\.component\.(ts|html|css)$/', $filename, $extMatch)) {
                    $normalizedFilename = "{$componentFolder}.component.{$extMatch[1]}";
                    if ($normalizedFilename !== $filename) {
                        \Log::warning("Nom de fichier normalisé: '{$filename}' -> '{$normalizedFilename}' pour correspondre au dossier '{$componentFolder}'.");
                    }
                }
                $filePath = $componentDir . '/' . $normalizedFilename;
                $filesToCommit[$filePath] = $code;
                $writtenFiles[] = $filePath;
            }

            // Préparer app.config.ts dans le même commit groupé
            $filesToCommit[$this->angularPath . '/app.config.ts'] = $this->getAppConfigContent();
            $writtenFiles[] = $this->angularPath . '/app.config.ts';

            // Préparer app.routes.ts dans le même commit groupé (toujours vérifié, create ou update)
            $updatedRoutes = $this->buildUpdatedRoutes($generated, $componentName, $componentFolder);
            if ($updatedRoutes !== null) {
                $filesToCommit[$this->angularPath . '/app.routes.ts'] = $updatedRoutes;
                $writtenFiles[] = $this->angularPath . '/app.routes.ts';
            }

            // UN SEUL commit atomique pour tous les fichiers du composant + routes + config
            $this->github->putFiles($filesToCommit, "Generate/update {$componentFolder} component");
        }

        // Accumule tous les fichiers Laravel modifiés dans cette génération, pour un
        // commit groupé unique sur le repo "back" à la fin de cette méthode. Chaque
        // fichier est TOUJOURS écrit localement en plus (comportement inchangé), pour
        // que le serveur en cours d'exécution en dispose immédiatement sans attendre
        // le redéploiement déclenché par ce commit.
        $laravelFilesToCommit = [];

        if (isset($generated['laravel']['controller'])) {
            $controllerRelPath = $generated['laravel']['controller']['file'];
            $controllerPath = base_path($controllerRelPath);
            File::ensureDirectoryExists(dirname($controllerPath));
            $code = $generated['laravel']['controller']['code'];
            $code = $this->deduplicatePhpBlock($code);
            if (!str_starts_with(trim($code), '<?php')) {
                $code = '<?php' . "\n\n" . $code;
            }
            $code = $this->ensureStorageImport($code);
            if (File::exists($controllerPath)) {
                $this->mergeController($controllerPath, $code);
            } else {
                File::put($controllerPath, $code);
            }
            $writtenFiles[] = $controllerPath;
            $laravelFilesToCommit[$controllerRelPath] = $code;
        }

        $table = $generated['database']['table'] ?? null;
        if ($table) {
            // ✅ FIX: on utilise le nom de classe que Mistral a EXPLICITEMENT fourni
            // dans generated.laravel.model.name (celui-là même que le controller
            // référence via "use App\Models\Xxx;"), au lieu de le recalculer nous-
            // mêmes depuis le nom de la table. L'ancien calcul
            // ucfirst(rtrim($table, 's')) ne gérait que les tables en un seul mot
            // (ex: "notes" -> "Note") et cassait dès qu'il y avait un underscore
            // (ex: "team_members" -> "Team_member" au lieu de "TeamMember"),
            // provoquant une erreur fatale "Class App\Models\Xxx not found" au
            // moment de l'insertion, car le controller référence un nom de classe
            // différent de celui du fichier réellement écrit sur le disque.
            $modelName = $generated['laravel']['model']['name']
                ?? Str::studly(Str::singular($table));
            $modelRelPath = "app/Models/{$modelName}.php";
            $modelPath = base_path($modelRelPath);

            $fields = $generated['database']['fields'] ?? [];
            $fillable = array_filter($fields, fn($f) => !in_array($f, ['id', 'created_at', 'updated_at']));
            $fillableStr = "'" . implode("', '", $fillable) . "'";

            // ✅ FIX: on écrase TOUJOURS le model avec les champs actuels (comme le
            // controller), au lieu de ne le créer qu'une seule fois. Sinon, un vieux
            // model existant avec un $fillable obsolète (ex: d'une génération
            // précédente avec d'autres champs) bloque SILENCIEUSEMENT l'assignation
            // de masse des nouveaux champs — Article::create($request->all()) ignore
            // sans erreur tout champ absent de $fillable, ce qui crée des lignes
            // vides sans qu'aucune erreur n'apparaisse dans les logs.
            $modelCode  = '<?php' . PHP_EOL . PHP_EOL;
            $modelCode .= 'namespace App\Models;' . PHP_EOL . PHP_EOL;
            $modelCode .= 'use Illuminate\Database\Eloquent\Model;' . PHP_EOL . PHP_EOL;
            $modelCode .= 'class ' . $modelName . ' extends Model' . PHP_EOL;
            $modelCode .= '{' . PHP_EOL;
            $modelCode .= '    protected $table = \'' . $table . '\';' . PHP_EOL;
            $modelCode .= '    protected $fillable = [' . $fillableStr . '];' . PHP_EOL;
            $modelCode .= '}' . PHP_EOL;

            File::put($modelPath, $modelCode);
            $writtenFiles[] = $modelPath;
            $laravelFilesToCommit[$modelRelPath] = $modelCode;
            \Log::info("Model écrit (créé ou mis à jour): {$modelPath}");
        }

        if (isset($generated['laravel']['migration'])) {
            $timestamp = date('Y_m_d_His');
            $table = $generated['database']['table'] ?? 'unknown';
            $existingLocal = glob(base_path("database/migrations/*_create_{$table}_table.php"));

            // Vérifie aussi sur le repo GitHub "back" (pas seulement le disque local),
            // car le disque local peut avoir été réinitialisé par un redémarrage récent
            // alors que la migration existe déjà bel et bien sur GitHub.
            $existingRemote = false;
            foreach ($this->githubBack->listDirectory('database/migrations') as $item) {
                if (($item['type'] ?? '') === 'file' && str_ends_with($item['name'] ?? '', "_create_{$table}_table.php")) {
                    $existingRemote = true;
                    break;
                }
            }

            if (empty($existingLocal) && !$existingRemote) {
                $migrationRelPath = "database/migrations/{$timestamp}_create_{$table}_table.php";
                $migrationPath = base_path($migrationRelPath);
                $migCode = $generated['laravel']['migration']['code'];
                if (!str_starts_with(trim($migCode), '<?php')) {
                    $migCode = '<?php' . "\n\n" . $migCode;
                }
                File::put($migrationPath, $migCode);
                $writtenFiles[] = $migrationPath;
                $laravelFilesToCommit[$migrationRelPath] = $migCode;
            }
        }

        if (isset($generated['laravel']['routes'])) {
            $this->addLaravelRoute($generated['laravel']['routes']);
            $writtenFiles[] = base_path('routes/api.php');
            // On committe le contenu final (déjà fusionné avec l'existant par addLaravelRoute)
            $laravelFilesToCommit['routes/api.php'] = File::get(base_path('routes/api.php'));
        }

        $userModelChange = $this->patchUserModel();
        if ($userModelChange !== null) {
            $laravelFilesToCommit['app/Models/User.php'] = $userModelChange;
        }

        if (!empty($laravelFilesToCommit)) {
            $featureLabel = $generated['feature'] ?? 'feature';
            $this->githubBack->putFiles($laravelFilesToCommit, "Generate/update Laravel backend for {$featureLabel}");
        }

        return $writtenFiles;
    }

    private function fixArrayType(string $code): string
    {
        $code = preg_replace(
            '/^(\s*)(\w+)\s*=\s*\[\];/m',
            '$1$2: any[] = [];',
            $code
        );
        return $code;
    }

    /**
     * Force la cohérence entre le nom du composant attendu (déterminé par le dossier créé)
     * et ce que le code TS contient réellement (classe, templateUrl, styleUrls, selector).
     */
    private function enforceComponentNameConsistency(string $code, string $expectedComponentName, string $componentFolder): string
    {
        if (!str_contains($code, '@Component')) {
            return $code;
        }

        if (preg_match('/export\s+class\s+(\w+)/', $code, $matches)) {
            $actualClassName = $matches[1];

            if ($actualClassName !== $expectedComponentName) {
                \Log::warning("Incohérence de nom de composant détectée: '{$actualClassName}' remplacé par '{$expectedComponentName}'.");
                $code = preg_replace('/\b' . preg_quote($actualClassName, '/') . '\b/', $expectedComponentName, $code);
            }
        }

        // ✅ FIX: ces 3 remplacements s'appliquent TOUJOURS (plus seulement quand le
        // nom de classe est incohérent). Le nom de fichier physique du composant est
        // désormais toujours normalisé vers $componentFolder (voir write()), donc
        // templateUrl/styleUrls doivent l'être aussi systématiquement — même quand
        // Mistral avait déjà choisi le bon nom de classe, il peut avoir utilisé un
        // nom de fichier différent (ex: avec un tiret) dans templateUrl/styleUrls,
        // ce qui casse le build Vercel (NG2008: template file not found) si on ne
        // corrige pas ces lignes dans ce cas-là aussi.
        $code = preg_replace(
            '/templateUrl\s*:\s*[\'"]\.\/[\w.-]+\.component\.html[\'"]/',
            "templateUrl: './{$componentFolder}.component.html'",
            $code
        );
        $code = preg_replace(
            '/styleUrls\s*:\s*\[\s*[\'"]\.\/[\w.-]+\.component\.css[\'"]\s*\]/',
            "styleUrls: ['./{$componentFolder}.component.css']",
            $code
        );

        $code = preg_replace(
            '/selector\s*:\s*[\'"]app-[\w-]+[\'"]/',
            "selector: 'app-{$componentFolder}'",
            $code
        );

        return $code;
    }

    /**
     * Corrige les opérations arithmétiques utilisant .value avec optional chaining (?.value),
     * qui provoquent des erreurs TypeScript (TS2533/TS2363) car la valeur peut être null/undefined.
     */
    private function fixOptionalValueArithmetic(string $code): string
    {
        // Ne cible que les cas où le ?.value est immédiatement suivi (après espaces optionnels)
        // d'un opérateur arithmétique (* + - /), ce qui indique un vrai calcul, pas un simple envoi de valeur.
        // Évite les faux positifs comme formData.append('title', this.form.get('title')?.value) qui ne doit
        // JAMAIS être encapsulé dans Number().
        $pattern = '/(?<!Number\()(\bthis\.\w+\.get\([\'"]\w+[\'"]\)\?\.value)(?=\s*[*+\-\/]\s*(?:this\.|\d))/';

        $newCode = preg_replace_callback($pattern, function ($matches) {
            return "Number({$matches[1]})";
        }, $code);

        if ($newCode !== $code) {
            \Log::warning('Correctif appliqué: encapsulation Number() sur les valeurs ?.value utilisées dans des calculs.');
        }

        return $newCode;
    }

    /**
     * Corrige le pattern défaillant où la méthode d'édition (onUpdate/onEdit/etc.) utilise
     * this.form.get('id').value pour récupérer l'ID, alors que le FormGroup ne contient
     * pas de champ 'id'. Fonctionne peu importe le nom de la méthode utilisée par Groq.
     */
    private function fixUpdateIdPattern(string $code): string
    {
        if (!str_contains($code, "form.get('id')")) {
            return $code;
        }

        \Log::warning('Pattern défaillant form.get(id) détecté, correction automatique appliquée (ajout de selectedId).');

        if (!str_contains($code, 'selectedId')) {
            $code = preg_replace(
                '/(\w+\s*:\s*any\[\]\s*=\s*\[\];)/',
                "$1\n  selectedId: number | null = null;",
                $code,
                1
            );
        }

        // Trouve n'importe quelle méthode (onUpdate, onEdit, editItem, etc.) qui fait patchValue(item)
        // et lui ajoute la mémorisation de l'ID
        $code = preg_replace_callback(
            '/(on\w+|edit\w+)\(item:\s*any\)\s*\{\s*this\.form\.patchValue\(item\);\s*\}/i',
            function ($matches) {
                return "{$matches[1]}(item: any) {\n    this.selectedId = item.id;\n    this.form.patchValue(item);\n  }";
            },
            $code
        );

        // Remplace toutes les utilisations de this.form.get('id')?.value ou .value par this.selectedId
        $code = preg_replace("/this\.form\.get\('id'\)\?\.\?value/", 'this.selectedId', $code);
        $code = preg_replace("/this\.form\.get\('id'\)\.value/", 'this.selectedId', $code);
        $code = preg_replace("/Number\(this\.selectedId\)/", 'this.selectedId', $code);

        // Réinitialise selectedId après une mise à jour réussie (dans n'importe quelle méthode de submit)
        $code = preg_replace(
            '/(next:\s*\([^)]*\)\s*=>\s*\{[^}]*)(this\.form\.reset\(\);)/s',
            '$1$2' . PHP_EOL . '        this.selectedId = null;',
            $code
        );

        return $code;
    }

    /**
     * Corrige le pattern défaillant où le bouton "Modifier" appelle directement
     * une méthode qui PUT l'objet cliqué tel quel (sans jamais charger le formulaire),
     * empêchant toute vraie modification des valeurs par l'utilisateur.
     * Transforme en: onEdit() qui charge le form + onSubmit() qui POST ou PUT selon selectedId.
     */
    private function fixDirectUpdatePattern(string $code): string
    {
        if (!preg_match(
            '/on(Update|Modify)\((\w+):\s*any\)\s*\{\s*this\.http\.put\(([^,]+),\s*\2\)\.subscribe/',
            $code,
            $m
        )) {
            return $code;
        }

        \Log::warning('Pattern défaillant "update direct sans formulaire" détecté, correction automatique appliquée.');

        $methodName = 'on' . $m[1];
        $itemVar = $m[2];
        $putUrlExpr = trim($m[3]);

        // Construit l'URL de base (sans l'ID concaténé) pour la réutiliser dans onSubmit
        $baseUrlExpr = preg_replace("/\s*\+\s*" . preg_quote($itemVar, '/') . "\.id\s*$/", '', $putUrlExpr);

        if (!str_contains($code, 'selectedId')) {
            $code = preg_replace(
                '/(\w+\s*:\s*any\[\]\s*=\s*\[\];)/',
                "$1\n  selectedId: number | null = null;",
                $code,
                1
            );
        }

        // Remplace l'ancienne méthode par une version qui charge le formulaire
        $oldMethodPattern = '/' . preg_quote($methodName, '/') . '\(' . preg_quote($itemVar, '/') . ':\s*any\)\s*\{[^{}]*\{[^{}]*\}[^{}]*\}/s';
        $newMethod = "onEdit({$itemVar}: any) {\n    this.selectedId = {$itemVar}.id;\n    this.form.patchValue({$itemVar});\n  }";
        $newCode = preg_replace($oldMethodPattern, $newMethod, $code, 1);
        if ($newCode !== null) {
            $code = $newCode;
        }

        // Rend onSubmit() capable de POST ou PUT selon selectedId
        $code = preg_replace_callback(
            '/onSubmit\(\)\s*\{\s*this\.http\.post\(([^,]+),\s*this\.form\.value\)\.subscribe\(\{\s*next:\s*\(([^)]*)\)\s*=>\s*\{(.*?)\},\s*error:\s*\(([^)]*)\)\s*=>\s*([^}]*)\}\s*\}\);?\s*\}/s',
            function ($sm) use ($baseUrlExpr) {
                $postUrl = trim($sm[1]);
                $nextParam = $sm[2];
                $nextBody = $sm[3];
                $errorParam = $sm[4];
                $errorBody = $sm[5];

                return "onSubmit() {\n"
                    . "    if (this.selectedId) {\n"
                    . "      this.http.put({$baseUrlExpr} + this.selectedId, this.form.value).subscribe({\n"
                    . "        next: ({$nextParam}) => {{$nextBody}          this.selectedId = null;\n        },\n"
                    . "        error: ({$errorParam}) => {$errorBody}\n"
                    . "      });\n"
                    . "    } else {\n"
                    . "      this.http.post({$postUrl}, this.form.value).subscribe({\n"
                    . "        next: ({$nextParam}) => {{$nextBody}},\n"
                    . "        error: ({$errorParam}) => {$errorBody}\n"
                    . "      });\n"
                    . "    }\n"
                    . "  }";
            },
            $code
        );

        return $code;
    }

    /**
     * Corrige le HTML : remplace la condition d'affichage du bouton "Modifier"
     * qui se base sur form.get('id')?.value (toujours faux) par selectedId.
     * Corrige aussi les boutons qui appellent directement onUpdate(item)/onModify(item)
     * pour qu'ils appellent onEdit(item) à la place (cohérent avec fixDirectUpdatePattern).
     */
    private function fixUpdateIdPatternHtml(string $code): string
    {
        if (str_contains($code, "form.get('id')")) {
            \Log::warning('Correctif HTML appliqué: form.get(id) remplacé par selectedId.');
            $code = preg_replace("/form\.get\('id'\)\?\.\?value/", 'selectedId', $code);
            $code = preg_replace("/form\.get\('id'\)\.value/", 'selectedId', $code);
        }

        // Si le bouton appelle onUpdate(item) ou onModify(item) directement, bascule vers onEdit(item)
        $code = preg_replace(
            '/\(click\)="on(Update|Modify)\((\w+)\)"/',
            '(click)="onEdit($2)"',
            $code
        );

        return $code;
    }

    /**
     * Ajoute un style par défaut aux boutons d'action (Modifier/Supprimer) dans la liste,
     * si le CSS généré ne les stylise pas déjà.
     */
    private function ensureButtonStyles(string $code): string
    {
        if (str_contains($code, '.list-item button')) {
            return $code;
        }

        $code .= <<<CSS


.list-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}
.list-item button {
  padding: 6px 14px;
  border: none;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: transform 0.2s, box-shadow 0.2s;
  margin-left: 6px;
  color: white;
}
.list-item button:first-of-type {
  background: linear-gradient(135deg, #17a2b8, #117a8b);
}
.list-item button:last-of-type {
  background: linear-gradient(135deg, #dc3545, #a71d2a);
}
.list-item button:hover {
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
CSS;

        return $code;
    }

    /**
     * Ajoute les accolades fermantes manquantes en fin de fichier (garde-fou basique).
     */
    private function balanceBraces(string $code): string
    {
        $openCount = substr_count($code, '{');
        $closeCount = substr_count($code, '}');

        if ($openCount > $closeCount) {
            $missing = $openCount - $closeCount;
            \Log::warning("Fichier TS déséquilibré : {$missing} accolade(s) manquante(s), correction automatique appliquée.");
            $code = rtrim($code) . PHP_EOL . str_repeat('}', $missing) . PHP_EOL;
        }

        return $code;
    }

    /**
     * Vérifie que tous les symboles Angular utilisés (ReactiveFormsModule, FormsModule, etc.)
     * ont bien leur import correspondant, peu importe où ils sont référencés dans le fichier.
     */
    private function ensureRequiredImports(string $code): string
    {
        $knownImports = [
            'ReactiveFormsModule' => "@angular/forms",
            'FormsModule'         => "@angular/forms",
            'FormGroup'           => "@angular/forms",
            'FormControl'         => "@angular/forms",
            'Validators'          => "@angular/forms",
            'CommonModule'        => "@angular/common",
            'HttpClient'          => "@angular/common/http",
            'Router'              => "@angular/router",
            'RouterModule'        => "@angular/router",
        ];

        $byModule = [];

        foreach ($knownImports as $symbol => $module) {
            $isUsed = preg_match('/\b' . preg_quote($symbol, '/') . '\b/', $code);
            $isImported = preg_match('/import\s*\{[^}]*\b' . preg_quote($symbol, '/') . '\b[^}]*\}\s*from\s*[\'"]' . preg_quote($module, '/') . '[\'"]/', $code);

            if ($isUsed && !$isImported) {
                $byModule[$module][] = $symbol;
            }
        }

        if (!empty($byModule)) {
            $newImportLines = '';
            foreach ($byModule as $module => $symbols) {
                $unique = array_unique($symbols);
                $newImportLines .= "import { " . implode(', ', $unique) . " } from '{$module}';" . PHP_EOL;
            }
            $code = $newImportLines . $code;
            \Log::warning("Imports manquants ajoutés automatiquement: " . json_encode($byModule));
        }

        return $code;
    }

    private function fixCommonModule(string $code): string
    {
        if (str_contains($code, 'CommonModule') && !str_contains($code, "from '@angular/common'")) {
            $code = str_replace(
                "import { Component",
                "import { CommonModule } from '@angular/common';\nimport { Component",
                $code
            );
        }

        if (str_contains($code, 'ReactiveFormsModule') && !str_contains($code, "from '@angular/forms'")) {
            $code = str_replace(
                "import { Component",
                "import { ReactiveFormsModule, FormGroup, FormControl } from '@angular/forms';\nimport { Component",
                $code
            );
        }

        if (!str_contains($code, 'CommonModule') && !str_contains($code, "from '@angular/common'")) {
            $code = str_replace(
                "import { Component",
                "import { CommonModule } from '@angular/common';\nimport { Component",
                $code
            );
            $code = preg_replace(
                '/imports\s*:\s*\[([^\]]*)\]/',
                'imports: [CommonModule, $1]',
                $code
            );
            $code = preg_replace('/\[\s*,\s*/', '[', $code);
        }

        return $code;
    }

    private function fixSuccessMessage(string $code): string
    {
        $code = str_replace(
            'console.log(r)',
            "this.successMessage='✅ Données enregistrées avec succès !';this.form.reset()",
            $code
        );

        if (!str_contains($code, 'successMessage')) {
            $code = preg_replace(
                '/(export class \w+ \{)/',
                "$1\nsuccessMessage='';\n",
                $code
            );
        }

        return $code;
    }

    private function fixEnvironment(): void
    {
        $envPath = 'src/environment.ts';

        if (!$this->github->fileExists($envPath)) {
            $content  = 'export const environment = {' . PHP_EOL;
            $content .= "  apiUrl: 'https://generator-back.onrender.com/api'" . PHP_EOL;
            $content .= '};' . PHP_EOL;
            $this->github->putFile($envPath, $content, 'Create environment.ts');
        }
    }

    private function fixApiUrl(string $code): string
    {
        if (preg_match('/http:\/\/localhost:\d+\/api\/([^\'\"]+)/', $code)) {
            $code = preg_replace(
                "/['\"]http:\/\/localhost:\d+\/api\/([^'\"]+)['\"]/",
                "environment.apiUrl + '/$1'",
                $code
            );
        }

        // Corrige aussi les URLs Railway complètes codées en dur, pour toujours passer par environment.apiUrl
        $code = preg_replace(
            "/['\"]https:\/\/generator-back-production\.up\.railway\.app\/api\/([^'\"]+)['\"]/",
            "environment.apiUrl + '/$1'",
            $code
        );
        $code = preg_replace(
            '/`https:\/\/generator-back-production\.up\.railway\.app\/api\/([^`]+)`/',
            'environment.apiUrl + `/$1`',
            $code
        );

        if (str_contains($code, 'environment.apiUrl') && !str_contains($code, "from '../../environment'")) {
            $code = str_replace(
                "import { Component",
                "import { environment } from '../../environment';\nimport { Component",
                $code
            );
        }

        return $code;
    }

    /**
     * GARDE-FOU: corrige les liens vers des fichiers stockés (PDF, images, etc.)
     * qui utilisent par erreur "environment.apiUrl + '/storage/...'". Comme
     * environment.apiUrl inclut déjà le suffixe '/api' (ex: '.../api'), ce pattern
     * génère une URL '.../api/storage/xxx.pdf' qui n'existe pas côté Laravel — les
     * fichiers stockés via Storage::disk('public') sont servis directement à la
     * racine du domaine ('.../storage/xxx.pdf'), jamais sous '/api/storage/'. Sans
     * ce correctif, tout lien de téléchargement de fichier uploadé renvoie un 404,
     * peu importe si le fichier a bien été uploadé et existe sur le serveur.
     */
    private function fixStorageUrl(string $code): string
    {
        $pattern = "/environment\.apiUrl(\.replace\([^)]*\))?\s*\+\s*(['\"`])\/storage\//";

        $newCode = preg_replace_callback($pattern, function ($matches) {
            $quote = $matches[2];
            return "environment.apiUrl.replace('/api', '') + {$quote}/storage/";
        }, $code);

        if ($newCode !== $code) {
            \Log::warning("Correctif appliqué: URL de storage corrigée pour retirer le préfixe '/api' erroné (fichiers uploadés servis à la racine, pas sous /api).");
        }

        return $newCode;
    }

     * "environment.xxx" DIRECTEMENT DANS LE TEMPLATE HTML provoque une erreur de compilation Angular
     * (TS2339: Property 'environment' does not exist on type 'XxxComponent'), car le HTML ne peut accéder
     * qu'aux propriétés de la classe, jamais aux imports bruts du fichier .ts.
     *
     * On ajoute donc systématiquement "environment = environment;" comme première ligne de la classe
     * dès que l'import est présent et que l'exposition ne l'est pas déjà — que le HTML l'utilise ou non,
     * ce correctif est sans danger et évite tout crash de build sur les composants avec upload de fichiers
     * (liens de téléchargement type environment.apiUrl + '/storage/...' dans le template).
     */
    private function ensureEnvironmentExposed(string $code): string
    {
        $hasImport = preg_match('/import\s*\{\s*environment\s*\}\s*from\s*[\'"][^\'"]+[\'"]/', $code);

        if (!$hasImport) {
            return $code;
        }

        // Déjà exposé sous une forme ou une autre (ex: "environment = environment;")
        if (preg_match('/^\s*environment\s*=\s*environment\s*;/m', $code)) {
            return $code;
        }

        if (!preg_match('/(export\s+class\s+\w+[^{]*\{)/', $code, $matches)) {
            return $code;
        }

        \Log::warning("Propriété 'environment' non exposée à la classe détectée, ajout automatique de 'environment = environment;' pour éviter un crash de build (TS2339) si le template l'utilise.");

        $classOpening = $matches[1];
        $code = preg_replace(
            '/' . preg_quote($classOpening, '/') . '/',
            $classOpening . PHP_EOL . '  environment = environment;',
            $code,
            1
        );

        return $code;
    }

    /**
     * Retire les doublons '/api/api/' causés par Groq qui ajoute parfois /api
     * en plus de environment.apiUrl qui le contient déjà.
     */
    private function fixDuplicateApiPath(string $code): string
    {
        return str_replace("environment.apiUrl + '/api/", "environment.apiUrl + '/", $code);
    }

    /**
     * Détecte et supprime les blocs PHP dupliqués que Groq génère parfois
     * (le même controller répété plusieurs fois d'affilée dans le code renvoyé,
     * chaque répétition recommençant par "<?php"), ce qui casse la syntaxe PHP
     * et provoque une erreur fatale "Cannot redeclare class Xxx".
     * Ne garde que la première occurrence complète.
     */
    private function deduplicatePhpBlock(string $code): string
    {
        // Compte les occurrences de "<?php" (avec ou sans namespace juste après)
        $occurrences = substr_count($code, '<?php');

        if ($occurrences <= 1) {
            return $code;
        }

        // Coupe tout ce qui vient à partir de la 2e occurrence de "<?php"
        $firstPos = strpos($code, '<?php');
        $secondPos = strpos($code, '<?php', $firstPos + 5);

        if ($secondPos !== false) {
            \Log::warning("Code PHP dupliqué détecté ({$occurrences} occurrences de '<?php'), troncature au premier bloc.");
            $code = substr($code, 0, $secondPos);
        }

        return rtrim($code);
    }

    private function mergeController(string $controllerPath, string $newCode): void
    {
        File::put($controllerPath, $newCode);
        \Log::info("Controller écrasé: {$controllerPath}");
    }

    /**
     * Ajoute automatiquement l'import de la façade Storage si le controller
     * l'utilise (Storage::...) sans l'avoir importé, ce qui cause une erreur fatale
     * "Class App\Http\Controllers\Storage not found".
     */
    private function ensureStorageImport(string $code): string
    {
        if (str_contains($code, 'Storage::') && !str_contains($code, 'use Illuminate\Support\Facades\Storage;')) {
            \Log::warning('Import Storage manquant détecté, ajout automatique.');
            $code = preg_replace(
                '/(namespace App\\\\Http\\\\Controllers;)/',
                "$1\n\nuse Illuminate\\Support\\Facades\\Storage;",
                $code,
                1
            );
        }
        return $code;
    }

    /**
     * Lit les fichiers existants du repo GitHub (au lieu du disque).
     */
    public function readExistingFiles(string $feature = ''): string
    {
        $result = '';
        $items = $this->github->listDirectory($this->angularPath);

        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'dir') continue;

            $folderName = $item['name'];
            if (in_array($folderName, ['app', 'assets', 'environments'])) continue;
            if (!empty($feature) && $folderName !== strtolower($feature)) continue;

            $files = $this->github->listDirectory($this->angularPath . '/' . $folderName);
            foreach ($files as $file) {
                if (($file['type'] ?? '') !== 'file') continue;
                $relativePath = $folderName . '/' . $file['name'];
                $content = $this->github->getFile($this->angularPath . '/' . $folderName . '/' . $file['name']);
                if ($content !== null) {
                    $result .= "\n--- {$relativePath} ---\n{$content}\n";
                }
            }
        }

        return $result;
    }

    /**
     * Liste les noms des dossiers de composants existants dans generated-app.
     */
    public function listGeneratedFolders(): array
    {
        $items = $this->github->listDirectory($this->angularPath);
        $folders = [];

        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'dir') continue;
            $folderName = $item['name'];
            if (in_array($folderName, ['app', 'assets', 'environments'])) continue;
            $folders[] = $folderName;
        }

        return $folders;
    }

    /**
     * Construit le contenu mis à jour de app.routes.ts, sans l'écrire directement.
     * Retourne null si aucune modification n'est nécessaire (route déjà présente).
     */
    private function buildUpdatedRoutes(array $generated, string $componentName, string $componentFolder): ?string
    {
        $route = ltrim($generated['angular']['route'] ?? $componentFolder, '/');
        $routesPath = $this->angularPath . '/app.routes.ts';
        $existingContent = $this->github->getFile($routesPath) ?? '';

        if (str_contains($existingContent, "path: '{$route}'")) {
            return null;
        }

        if (empty(trim($existingContent))) {
            $content  = "import { Routes } from '@angular/router';" . PHP_EOL;
            $content .= "import { {$componentName} } from './{$componentFolder}/{$componentFolder}.component';" . PHP_EOL . PHP_EOL;
            $content .= "export const routes: Routes = [" . PHP_EOL;
            $content .= "  { path: '', redirectTo: '/{$route}', pathMatch: 'full' }," . PHP_EOL;
            $content .= "  { path: '{$route}', component: {$componentName} }" . PHP_EOL;
            $content .= "];" . PHP_EOL;
            return $content;
        }

        $importLine = "import { {$componentName} } from './{$componentFolder}/{$componentFolder}.component';";
        if (!str_contains($existingContent, $importLine)) {
            $existingContent = str_replace(
                "import { Routes } from '@angular/router';",
                "import { Routes } from '@angular/router';" . PHP_EOL . $importLine,
                $existingContent
            );
        }

        $protectedRoutes = ['dashboard', 'admin'];
        $needsGuard = in_array($route, $protectedRoutes);

        if ($needsGuard && !str_contains($existingContent, 'authGuard')) {
            $existingContent = str_replace(
                "import { Routes } from '@angular/router';",
                "import { Routes } from '@angular/router';" . PHP_EOL . "import { authGuard } from './auth.guard';",
                $existingContent
            );
        }

        $newRoute = $needsGuard
            ? "  { path: '{$route}', component: {$componentName}, canActivate: [authGuard] },"
            : "  { path: '{$route}', component: {$componentName} },";

        $existingContent = preg_replace(
            '/export const routes: Routes = \[/',
            "export const routes: Routes = [" . PHP_EOL . $newRoute,
            $existingContent
        );

        return $existingContent;
    }

    private function addLaravelRoute(string $newRoutes): void
    {
        // Convertir les \n échappés littéralement (texte brut) en vrais retours à la ligne,
        // au cas où Groq les envoie sous cette forme au lieu de vrais sauts de ligne.
        $newRoutes = str_replace('\\n', "\n", $newRoutes);

        $lines = array_filter(array_map('trim', explode("\n", $newRoutes)));

        $anyAdded = false;
        foreach ($lines as $line) {
            $added = $this->addSingleLaravelRoute($line);
            if ($added) {
                $anyAdded = true;
            }
        }

        if ($anyAdded) {
            \Artisan::call('route:clear');
            \Artisan::call('route:cache');
            \Log::info('Cache des routes régénéré après ajout de ' . count($lines) . ' route(s).');
        }
    }

    private function addSingleLaravelRoute(string $newRoute): bool
    {
        // Garde-fou supplémentaire: ignore toute ligne qui ne ressemble pas à une vraie déclaration de route
        if (!preg_match('/^Route::\w+\(/', trim($newRoute))) {
            \Log::warning("Ligne de route invalide ignorée: {$newRoute}");
            return false;
        }

        $routesPath = base_path('routes/api.php');
        $existingRoutes = File::get($routesPath);

        $cleanRoute = preg_replace("/Route::(\w+)\('\/api\//", "Route::$1('/", $newRoute);

        preg_match("/Route::(\w+)\('([^']+)'/", $cleanRoute, $matches);
        $httpMethod = $matches[1] ?? '';
        $routePath = $matches[2] ?? '';

        $routeSignature = "Route::{$httpMethod}('{$routePath}'";
        if (!empty($routePath) && str_contains($existingRoutes, $routeSignature)) {
            \Log::info("Route déjà existante, ignorée: {$cleanRoute}");
            return false;
        }

        preg_match('/\[(\w+)::class/', $cleanRoute, $controllerMatches);
        $controllerName = $controllerMatches[1] ?? '';

        if (!empty($controllerName) && !str_contains($existingRoutes, "use App\\Http\\Controllers\\{$controllerName}")) {
            $existingRoutes = str_replace(
                "use App\\Http\\Controllers\\AuthController;",
                "use App\\Http\\Controllers\\AuthController;" . PHP_EOL . "use App\\Http\\Controllers\\{$controllerName};",
                $existingRoutes
            );
        }

        $existingRoutes .= PHP_EOL . $cleanRoute;
        File::put($routesPath, $existingRoutes);

        \Log::info("Route ajoutée: {$cleanRoute}");
        return true;
    }

    /**
     * Retourne le nouveau contenu de User.php si une modification a été appliquée
     * (pour permettre de le committer sur le repo "back"), ou null si le fichier
     * n'existe pas ou était déjà à jour.
     */
    private function patchUserModel(): ?string
    {
        $userModelPath = base_path('app/Models/User.php');
        if (!File::exists($userModelPath)) return null;

        $content = File::get($userModelPath);
        if (!str_contains($content, 'HasApiTokens')) {
            $content = str_replace(
                "use Illuminate\\Foundation\\Auth\\User as Authenticatable;",
                "use Illuminate\\Foundation\\Auth\\User as Authenticatable;" . PHP_EOL . "use Laravel\\Sanctum\\HasApiTokens;",
                $content
            );
            $content = str_replace(
                'use Notifiable;',
                'use HasApiTokens, Notifiable;',
                $content
            );
            File::put($userModelPath, $content);
            return $content;
        }

        return null;
    }

    private function fixAngularImports(string $code): string
    {
        $code = preg_replace('/,?\s*HttpClientModule\s*,?/', '', $code);

        $code = preg_replace_callback(
            "/import\s*\{([^}]*)\}\s*from\s*'@angular\/common\/http';/",
            function ($matches) {
                $imports = $matches[1];
                $formsModules = [];
                $httpModules = [];
                foreach (array_map('trim', explode(',', $imports)) as $imp) {
                    $imp = trim($imp);
                    if (empty($imp)) continue;
                    if (in_array($imp, ['ReactiveFormsModule', 'FormsModule', 'FormGroup', 'FormControl', 'Validators'])) {
                        $formsModules[] = $imp;
                    } else {
                        $httpModules[] = $imp;
                    }
                }
                $result = '';
                if (!empty($httpModules)) {
                    $result .= "import { " . implode(', ', $httpModules) . " } from '@angular/common/http';";
                }
                if (!empty($formsModules)) {
                    $result .= PHP_EOL . "import { " . implode(', ', $formsModules) . " } from '@angular/forms';";
                }
                return $result;
            },
            $code
        );

        if (str_contains($code, 'HttpClient') && !str_contains($code, "from '@angular/common/http'")) {
            $code = "import { HttpClient } from '@angular/common/http';" . PHP_EOL . $code;
        }

        $code = preg_replace('/\[\s*,\s*/', '[', $code);
        $code = preg_replace('/,\s*\]/', ']', $code);
        $code = preg_replace('/,\s*,/', ',', $code);

        return $code;
    }

    private function fixAngularComponent(string $code): string
    {
        if (str_contains($code, 'FormGroup') || str_contains($code, 'formGroup')) {

            if (!str_contains($code, 'ReactiveFormsModule')) {
                if (str_contains($code, "from '@angular/forms'")) {
                    $code = preg_replace(
                        "/import\s*\{([^}]*)\}\s*from\s*'@angular\/forms'/",
                        "import { ReactiveFormsModule, \$1 } from '@angular/forms'",
                        $code
                    );
                } else {
                    $code = "import { ReactiveFormsModule, FormGroup, FormControl, Validators } from '@angular/forms';" . PHP_EOL . $code;
                }
            }

            $code = preg_replace(
                '/imports\s*:\s*\[\s*\]/',
                'imports: [ReactiveFormsModule]',
                $code
            );

            if (!preg_match('/imports\s*:\s*\[.*ReactiveFormsModule.*\]/s', $code)) {
                $code = preg_replace(
                    '/imports\s*:\s*\[([^\]]*)\]/',
                    'imports: [ReactiveFormsModule, $1]',
                    $code
                );
                $code = preg_replace('/\[\s*,\s*/', '[', $code);
            }
        }

        return $code;
    }

    private function getAppConfigContent(): string
    {
        return <<<'TS'
import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withInterceptors, HttpInterceptorFn } from '@angular/common/http';
import { routes } from './app.routes';

const authInterceptor: HttpInterceptorFn = (req, next) => {
  const token = localStorage.getItem('token');
  if (token) {
    const authReq = req.clone({
      headers: req.headers.set('Authorization', `Bearer ${token}`)
    });
    return next(authReq);
  }
  return next(req);
};

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes),
    provideHttpClient(withInterceptors([authInterceptor])),
  ]
};
TS;
    }

    private function cleanAngularCode(string $code): string
    {
        $code = preg_replace('/import\s*\{[^}]*(?:AuthService|UserService|DataService|ApiService|BlogService|ProductService|ClientService)[^}]*\}\s*from\s*[\'"][^\'\"]+[\'"];\n?/', '', $code);
        $code = preg_replace('/,?\s*private\s+\w+(?:Service|Helper)\s*:\s*\w+(?:Service|Helper)\s*,?/', '', $code);
        $code = preg_replace('/\(\s*,\s*/', '(', $code);
        $code = preg_replace('/,\s*,/', ',', $code);
        $code = preg_replace('/,\s*\)/', ')', $code);

        return $code;
    }
}
