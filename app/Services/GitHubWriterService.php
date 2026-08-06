<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubWriterService
{
    private string $token;
    private string $owner;
    private string $repo;
    private string $branch;

public function __construct()
{
    $this->token = config('services.github.token');
    $this->owner = config('services.github.owner');
    $this->repo = config('services.github.repo');
    $this->branch = config('services.github.branch', 'main');
}

    /**
     * Crée ou met à jour un fichier dans le repo GitHub.
     *
     * @param string $path Chemin du fichier dans le repo (ex: src/app/login/login.component.ts)
     * @param string $content Contenu du fichier
     * @param string $commitMessage Message de commit
     */
    public function putFile(string $path, string $content, string $commitMessage = 'Update generated file'): bool
    {
        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/contents/{$path}";

        // 1. Vérifier si le fichier existe déjà (pour récupérer son sha)
        $sha = $this->getFileSha($path);

        $payload = [
            'message' => $commitMessage,
            'content' => base64_encode($content),
            'branch' => $this->branch,
        ];

        if ($sha) {
            $payload['sha'] = $sha;
        }

        $response = Http::withToken($this->token)
            ->put($url, $payload);

        if ($response->failed()) {
            Log::error("GitHub write failed for {$path}: " . $response->body());
            return false;
        }

        Log::info("GitHub file written: {$path}");
        return true;
    }

    /**
     * Récupère le sha d'un fichier existant, ou null s'il n'existe pas.
     */
    private function getFileSha(string $path): ?string
    {
        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/contents/{$path}";

        $response = Http::withToken($this->token)
            ->get($url, ['ref' => $this->branch]);

        if ($response->successful()) {
            return $response->json('sha');
        }

        return null;
    }
}