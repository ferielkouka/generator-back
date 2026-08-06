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

    public function putFile(string $path, string $content, string $commitMessage = 'Update generated file'): bool
    {
        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/contents/{$path}";

        $sha = $this->getFileSha($path);

        $payload = [
            'message' => $commitMessage,
            'content' => base64_encode($content),
            'branch' => $this->branch,
        ];

        if ($sha) {
            $payload['sha'] = $sha;
        }

        $response = Http::withToken($this->token)->put($url, $payload);

        if ($response->failed()) {
            Log::error("GitHub write failed for {$path}: " . $response->body());
            return false;
        }

        Log::info("GitHub file written: {$path}");
        return true;
    }

    public function getFile(string $path): ?string
    {
        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/contents/{$path}";

        $response = Http::withToken($this->token)->get($url, ['ref' => $this->branch]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['content'])) {
                return base64_decode($data['content']);
            }
        }

        return null;
    }

    public function fileExists(string $path): bool
    {
        return $this->getFileSha($path) !== null;
    }

    public function listDirectory(string $path): array
    {
        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/contents/{$path}";

        $response = Http::withToken($this->token)->get($url, ['ref' => $this->branch]);

        if ($response->successful()) {
            $data = $response->json();
            return is_array($data) ? $data : [];
        }

        return [];
    }

    private function getFileSha(string $path): ?string
    {
        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/contents/{$path}";

        $response = Http::withToken($this->token)->get($url, ['ref' => $this->branch]);

        if ($response->successful()) {
            return $response->json('sha');
        }

        return null;
    }
}