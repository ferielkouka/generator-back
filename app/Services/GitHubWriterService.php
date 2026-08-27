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

    /**
     * Si $owner/$repo/$branch/$token sont fournis, ils sont utilisés tels quels
     * (permet d'instancier ce service pour n'importe quel repo, ex: le repo
     * "back" en plus du repo "app" par défaut). Sinon, on retombe sur la
     * config par défaut (services.github.*), pour ne rien casser côté
     * injection automatique via le conteneur Laravel.
     */
    public function __construct(
        ?string $owner = null,
        ?string $repo = null,
        ?string $branch = null,
        ?string $token = null
    ) {
        $this->token  = $token  ?? config('services.github.token');
        $this->owner  = $owner  ?? config('services.github.owner');
        $this->repo   = $repo   ?? config('services.github.repo');
        $this->branch = $branch ?? config('services.github.branch', 'main');
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

    /**
     * Crée ou met à jour PLUSIEURS fichiers en un seul commit atomique,
     * via l'API Git Trees de GitHub (évite les états intermédiaires incomplets
     * qui causent des échecs de build Vercel quand un build se déclenche
     * entre deux commits successifs d'un même groupe de fichiers).
     *
     * @param array $files ['chemin/fichier.ts' => 'contenu', ...]
     * @param string $commitMessage
     */
    public function putFiles(array $files, string $commitMessage = 'Batch update'): bool
    {
        if (empty($files)) {
            return true;
        }

        try {
            // 1. Récupérer le dernier commit de la branche
            $refResponse = Http::withToken($this->token)
                ->get("https://api.github.com/repos/{$this->owner}/{$this->repo}/git/refs/heads/{$this->branch}");

            if (!$refResponse->successful()) {
                Log::error("GitHub: impossible de récupérer la ref de la branche: " . $refResponse->body());
                return false;
            }

            $latestCommitSha = $refResponse->json('object.sha');

            // 2. Récupérer le SHA du tree associé à ce commit
            $commitResponse = Http::withToken($this->token)
                ->get("https://api.github.com/repos/{$this->owner}/{$this->repo}/git/commits/{$latestCommitSha}");

            if (!$commitResponse->successful()) {
                Log::error("GitHub: impossible de récupérer le commit: " . $commitResponse->body());
                return false;
            }

            $baseTreeSha = $commitResponse->json('tree.sha');

            // 3. Créer un blob pour chaque fichier
            $treeItems = [];
            foreach ($files as $path => $content) {
                $blobResponse = Http::withToken($this->token)
                    ->post("https://api.github.com/repos/{$this->owner}/{$this->repo}/git/blobs", [
                        'content'  => base64_encode($content),
                        'encoding' => 'base64',
                    ]);

                if (!$blobResponse->successful()) {
                    Log::error("GitHub: échec de création du blob pour {$path}: " . $blobResponse->body());
                    return false;
                }

                $treeItems[] = [
                    'path' => $path,
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha'  => $blobResponse->json('sha'),
                ];
            }

            // 4. Créer un nouveau tree combinant les nouveaux fichiers avec l'existant
            $treeResponse = Http::withToken($this->token)
                ->post("https://api.github.com/repos/{$this->owner}/{$this->repo}/git/trees", [
                    'base_tree' => $baseTreeSha,
                    'tree'      => $treeItems,
                ]);

            if (!$treeResponse->successful()) {
                Log::error("GitHub: échec de création du tree: " . $treeResponse->body());
                return false;
            }

            $newTreeSha = $treeResponse->json('sha');

            // 5. Créer un nouveau commit pointant vers ce tree
            $newCommitResponse = Http::withToken($this->token)
                ->post("https://api.github.com/repos/{$this->owner}/{$this->repo}/git/commits", [
                    'message' => $commitMessage,
                    'tree'    => $newTreeSha,
                    'parents' => [$latestCommitSha],
                ]);

            if (!$newCommitResponse->successful()) {
                Log::error("GitHub: échec de création du commit: " . $newCommitResponse->body());
                return false;
            }

            $newCommitSha = $newCommitResponse->json('sha');

            // 6. Mettre à jour la branche pour pointer vers ce nouveau commit
            $updateRefResponse = Http::withToken($this->token)
                ->patch("https://api.github.com/repos/{$this->owner}/{$this->repo}/git/refs/heads/{$this->branch}", [
                    'sha' => $newCommitSha,
                ]);

            if (!$updateRefResponse->successful()) {
                Log::error("GitHub: échec de mise à jour de la ref: " . $updateRefResponse->body());
                return false;
            }

            Log::info("GitHub: commit groupé créé avec succès (" . count($files) . " fichiers) sur {$this->owner}/{$this->repo}: {$newCommitSha}");
            return true;

        } catch (\Exception $e) {
            Log::error("GitHub: exception lors du commit groupé: " . $e->getMessage());
            return false;
        }
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
