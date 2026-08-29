<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

use JsonException;
use RuntimeException;

final class GitHubStorageAdapter implements StorageAdapter
{
    /** @var null|callable */
    private $requester;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly string $branch = 'main',
        private readonly string $prefix = '',
        private readonly ?string $token = null,
        ?callable $requester = null,
        private readonly string $apiBase = 'https://api.github.com'
    ) {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $owner) || !preg_match('/^[A-Za-z0-9_.-]+$/', $repo)) throw new RuntimeException('Invalid GitHub owner/repository');
        if ($branch === '' || str_contains($branch, "\0")) throw new RuntimeException('Invalid GitHub branch');
        $this->requester = $requester;
    }

    public function id(): string { return 'github:' . $this->owner . '/' . $this->repo . '@' . $this->branch . '/' . trim($this->prefix, '/'); }

    public function get(string $locator): array
    {
        $path = $this->remotePath($locator);
        [$status, $data] = $this->request('GET', '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo) . '/contents/' . $this->encodePath($path) . '?ref=' . rawurlencode($this->branch));
        if ($status === 404) throw new RuntimeException('GitHub storage object not found: ' . $locator);
        if ($status !== 200 || !is_array($data)) throw new RuntimeException('GitHub storage read failed: HTTP ' . $status);
        if (($data['type'] ?? null) !== 'file' || !isset($data['content'], $data['sha'])) throw new RuntimeException('GitHub storage response is not a file');
        $bytes = base64_decode(str_replace(["\n", "\r"], '', (string)$data['content']), true);
        if ($bytes === false) throw new RuntimeException('Invalid GitHub Base64 content');
        return ['bytes' => $bytes, 'version' => (string)$data['sha']];
    }

    public function exists(string $locator): bool
    {
        try { $this->get($locator); return true; }
        catch (RuntimeException $e) { if (str_contains($e->getMessage(), 'not found')) return false; throw $e; }
    }

    public function put(string $locator, string $bytes, ?string $expectedVersion = null, bool $createOnly = false): string
    {
        $path = $this->remotePath($locator);
        $currentVersion = null;
        try {
            $current = $this->get($locator);
            $currentVersion = $current['version'];
            if ($createOnly) {
                if (hash_equals($current['bytes'], $bytes)) return $currentVersion;
                throw new RuntimeException('GitHub storage object already exists: ' . $locator);
            }
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'not found')) throw $e;
            if ($expectedVersion !== null) throw new RuntimeException('GitHub storage version conflict: expected object is missing: ' . $locator);
        }

        if ($expectedVersion !== null && $currentVersion !== null && !hash_equals($expectedVersion, $currentVersion)) throw new RuntimeException('GitHub storage version conflict: ' . $locator);

        $payload = [
            'message' => 'MCMA storage update: ' . $path,
            'content' => base64_encode($bytes),
            'branch' => $this->branch,
        ];
        if ($currentVersion !== null) $payload['sha'] = $currentVersion;
        [$status, $data] = $this->request('PUT', '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo) . '/contents/' . $this->encodePath($path), $payload);
        if (!in_array($status, [200, 201], true) || !is_array($data)) throw new RuntimeException('GitHub storage write failed: HTTP ' . $status);
        $sha = $data['content']['sha'] ?? null;
        if (!is_string($sha) || $sha === '') throw new RuntimeException('GitHub storage write returned no blob SHA');
        return $sha;
    }

    public function delete(string $locator, ?string $expectedVersion = null): void
    {
        $current = $this->get($locator);
        if ($expectedVersion !== null && !hash_equals($expectedVersion, $current['version'])) throw new RuntimeException('GitHub storage version conflict: ' . $locator);
        $path = $this->remotePath($locator);
        [$status] = $this->request('DELETE', '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo) . '/contents/' . $this->encodePath($path), [
            'message' => 'MCMA storage delete: ' . $path,
            'sha' => $current['version'],
            'branch' => $this->branch,
        ]);
        if ($status !== 200) throw new RuntimeException('GitHub storage delete failed: HTTP ' . $status);
    }

    public function list(string $prefix = ''): array
    {
        $remotePrefix = trim($this->remotePath($prefix, true), '/');
        [$status, $branch] = $this->request('GET', '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo) . '/branches/' . rawurlencode($this->branch));
        if ($status !== 200 || !is_array($branch)) throw new RuntimeException('Unable to resolve GitHub branch');
        $treeSha = $branch['commit']['commit']['tree']['sha'] ?? null;
        if (!is_string($treeSha) || $treeSha === '') throw new RuntimeException('GitHub branch response lacks tree SHA');
        [$status, $tree] = $this->request('GET', '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo) . '/git/trees/' . rawurlencode($treeSha) . '?recursive=1');
        if ($status !== 200 || !is_array($tree) || !isset($tree['tree']) || !is_array($tree['tree'])) throw new RuntimeException('Unable to list GitHub storage tree');
        if (($tree['truncated'] ?? false) === true) throw new RuntimeException('GitHub storage tree listing was truncated');

        $base = trim($this->prefix, '/');
        $out = [];
        foreach ($tree['tree'] as $entry) {
            if (($entry['type'] ?? null) !== 'blob') continue;
            $path = (string)($entry['path'] ?? '');
            if ($base !== '') {
                if (!str_starts_with($path, $base . '/')) continue;
                $relative = substr($path, strlen($base) + 1);
            } else $relative = $path;
            if ($remotePrefix !== '' && !str_starts_with($path, $remotePrefix)) continue;
            if ($prefix !== '' && !str_starts_with($relative, trim($prefix, '/'))) continue;
            $out[] = $relative;
        }
        sort($out, SORT_STRING);
        return $out;
    }

    public function withWriteLock(callable $callback): mixed
    {
        // GitHub has no cross-client lock here. Library manifest CAS prevents lost updates.
        return $callback();
    }

    public function capabilities(): array
    {
        return ['atomic_put' => false, 'compare_and_swap' => true, 'exclusive_lock' => false, 'list_prefix' => true, 'byte_preserving' => true, 'version' => 'git-blob-sha'];
    }

    private function remotePath(string $locator, bool $allowEmpty = false): string
    {
        $locator = trim(str_replace('\\', '/', $locator), '/');
        if (!$allowEmpty && ($locator === '' || str_contains($locator, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $locator))) throw new RuntimeException('Invalid GitHub storage locator');
        $prefix = trim($this->prefix, '/');
        if ($prefix !== '' && (str_contains($prefix, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $prefix))) throw new RuntimeException('Invalid GitHub storage prefix');
        return trim(($prefix === '' ? '' : $prefix . '/') . $locator, '/');
    }

    private function encodePath(string $path): string { return implode('/', array_map('rawurlencode', explode('/', $path))); }

    /** @return array{0:int,1:mixed} */
    private function request(string $method, string $path, ?array $json = null): array
    {
        if ($this->requester !== null) return ($this->requester)($method, $path, $json);
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for GitHub storage');
        $ch = curl_init(rtrim($this->apiBase, '/') . $path);
        $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: MCMA-OpenMemory/1.0'];
        if ($this->token !== null && $this->token !== '') $headers[] = 'Authorization: Bearer ' . $this->token;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
        if ($json !== null) {
            $body = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $raw = curl_exec($ch);
        if ($raw === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException('GitHub HTTP error: ' . $err); }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === '') return [$status, null];
        try { return [$status, json_decode($raw, true, 512, JSON_THROW_ON_ERROR)]; }
        catch (JsonException $e) { throw new RuntimeException('Invalid GitHub API JSON response', 0, $e); }
    }
}
