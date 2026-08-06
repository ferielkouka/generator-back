<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class FileWriterService
{
    private string $angularPath;   // chemin RELATIF dans le repo GitHub (pas un chemin disque)
    private string $laravelPath;
    private GitHubWriterService $github;

    public function __construct(GitHubWriterService $github)
    {
        $this->angularPath = 'src/app';
        $this->laravelPath = base_path();
        $this->github = $github;
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

            foreach ($generated['angular']['files'] as $filename => $code) {
                if (str_ends_with($filename, '.ts')) {
                    $code = $this->cleanAngularCode($code);
                    $code = $this->fixAngularImports($code);
                    $code = $this->fixAngularComponent($code);
                    $code = $this->fixApiUrl($code);
                    $code = $this->fixSuccessMessage($code);
                    $code = $this->fixCommonModule($code);
                    $code = $this->fixArrayType($code);
                }
                $filePath = $componentDir . '/' . $filename;
                $this->github->putFile($filePath, $code, "Add/update {$filename}");
                $writtenFiles[] = $filePath;
            }

            if ($action === 'create') {
                $this->addRoute($generated, $componentName, $componentFolder);
                $writtenFiles[] = $this->angularPath . '/app.routes.ts';
            }

            $this->fixAppConfig();
        }

        if (isset($generated['laravel']['controller'])) {
            $controllerPath = base_path($generated['laravel']['controller']['file']);
            File::ensureDirectoryExists(dirname($controllerPath));
            $code = $generated['laravel']['controller']['code'];
            if (!str_starts_with(trim($code), '<?php')) {
                $code = '<?php' . "\n\n" . $code;
            }
            if (File::exists($controllerPath)) {
                $this->mergeController($controllerPath, $code);
            } else {
                File::put($controllerPath, $code);
            }
            $writtenFiles[] = $controllerPath;
        }

        $table = $generated['database']['table'] ?? null;
        if ($table) {
            $modelName = ucfirst(rtrim($table, 's'));
            $modelPath = base_path("app/Models/{$modelName}.php");

            if (!File::exists($modelPath)) {
                $fields = $generated['database']['fields'] ?? [];
                $fillable = array_filter($fields, fn($f) => !in_array($f, ['id', 'created_at', 'updated_at']));
                $fillableStr = "'" . implode("', '", $fillable) . "'";

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
                \Log::info("Model créé: {$modelPath}");
            }
        }

        if (isset($generated['laravel']['migration'])) {
            $timestamp = date('Y_m_d_His');
            $table = $generated['database']['table'] ?? 'unknown';
            $existing = glob(base_path("database/migrations/*_create_{$table}_table.php"));

            if (empty($existing)) {
                $migrationPath = base_path("database/migrations/{$timestamp}_create_{$table}_table.php");
                $migCode = $generated['laravel']['migration']['code'];
                if (!str_starts_with(trim($migCode), '<?php')) {
                    $migCode = '<?php' . "\n\n" . $migCode;
                }
                File::put($migrationPath, $migCode);
                $writtenFiles[] = $migrationPath;
            }
        }

        if (isset($generated['laravel']['routes'])) {
            $this->addLaravelRoute($generated['laravel']['routes']);
            $writtenFiles[] = base_path('routes/api.php');
        }

        $this->patchUserModel();

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
            $content .= "  apiUrl: 'https://generator-back-production.up.railway.app/api'" . PHP_EOL;
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

        if (str_contains($code, 'environment.apiUrl') && !str_contains($code, "from '../../environment'")) {
            $code = str_replace(
                "import { Component",
                "import { environment } from '../../environment';\nimport { Component",
                $code
            );
        }

        return $code;
    }

    private function mergeController(string $controllerPath, string $newCode): void
    {
        File::put($controllerPath, $newCode);
        \Log::info("Controller écrasé: {$controllerPath}");
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

    private function addRoute(array $generated, string $componentName, string $componentFolder): void
    {
        $route = ltrim($generated['angular']['route'] ?? $componentFolder, '/');
        $routesPath = $this->angularPath . '/app.routes.ts';
        $existingContent = $this->github->getFile($routesPath) ?? '';

        if (str_contains($existingContent, "path: '{$route}'")) {
            return;
        }

        if (empty(trim($existingContent))) {
            $content  = "import { Routes } from '@angular/router';" . PHP_EOL;
            $content .= "import { {$componentName} } from './{$componentFolder}/{$componentFolder}.component';" . PHP_EOL . PHP_EOL;
            $content .= "export const routes: Routes = [" . PHP_EOL;
            $content .= "  { path: '', redirectTo: '/{$route}', pathMatch: 'full' }," . PHP_EOL;
            $content .= "  { path: '{$route}', component: {$componentName} }" . PHP_EOL;
            $content .= "];" . PHP_EOL;
            $this->github->putFile($routesPath, $content, "Create app.routes.ts");
            return;
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

        $this->github->putFile($routesPath, $existingContent, "Add route {$route}");
    }

    private function addLaravelRoute(string $newRoute): void
    {
        $routesPath = base_path('routes/api.php');
        $existingRoutes = File::get($routesPath);

        $cleanRoute = preg_replace("/Route::(\w+)\('\/api\//", "Route::$1('/", $newRoute);

        preg_match("/Route::\w+\('([^']+)'/", $cleanRoute, $matches);
        $routePath = $matches[1] ?? '';

        if (!empty($routePath) && str_contains($existingRoutes, "'{$routePath}'")) {
            return;
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
    }

    private function patchUserModel(): void
    {
        $userModelPath = base_path('app/Models/User.php');
        if (!File::exists($userModelPath)) return;

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
        }
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

    private function fixAppConfig(): void
    {
        $configPath = 'src/app/app.config.ts';

        $config = <<<'TS'
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

        $this->github->putFile($configPath, $config, 'Update app.config.ts');
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