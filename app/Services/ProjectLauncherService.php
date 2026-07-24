<?php

namespace App\Services;

class ProjectLauncherService
{
    public function launchGeneratedApp(): void
    {
        \Log::info('launchGeneratedApp appelé');
        
        $check = shell_exec('netstat -ano | findstr :4201 | findstr LISTENING');
        \Log::info('Port check: ' . ($check ?? 'null'));
        
        if (empty(trim($check ?? ''))) {
            \Log::info('Lancement ng serve...');
            pclose(popen('start /B cmd /c "cd /d C:\\Users\\lenovo\\generated-app && ng serve --port 4201 > nul 2>&1"', 'r'));
            \Log::info('ng serve lancé');
        } else {
            \Log::info('ng serve déjà en cours');
        }
    }

    public function isRunning(): bool
    {
        return true;
    }

    public function runMigrations(): string
    {
        return 'OK';
    }
}