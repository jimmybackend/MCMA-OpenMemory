<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

use RuntimeException;

final class WebDavStorageAdapter implements StorageAdapter
{
    /** @var null|callable */
    private $requester;
    private string $scheme;
    private string $host;
    private string $rootPath;

    public function __construct(
        string $endpoint,
        private readonly string $authMode = 'none',
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly ?string $token = null,
        ?callable $requester = null
    ) {
        $parts = parse_url($endpoint);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) throw new RuntimeException('Invalid WebDAV endpoint');
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new RuntimeException('WebDAV endpoint must not contain credentials, query strings or fragments');
        if (!in_array($parts['scheme'], ['http', 'https'], true)) throw new RuntimeException('WebDAV endpoint must use http or https');
        $this->scheme = $parts['scheme'];
        $this->host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->rootPath = '/' . trim((string)($parts['path'] ?? ''), '/');
        if ($this->rootPath === '/') $this->rootPath = '';
        if (!in_array($authMode, ['none', 'basic', 'bearer'], true)) throw new RuntimeException('Invalid WebDAV auth mode');
        if ($authMode === 'basic' && (($username ?? '') === '' || $password === null)) throw new RuntimeException('WebDAV basic auth requires username/password');
        if ($authMode === 'bearer' && ($token ?? '') === '') throw new RuntimeException('WebDAV bearer auth requires token');
        $this->requester = $requester;
    }

    public function id(): string { return 'webdav:' . $this->scheme . '://' . $this->host . $this->rootPath; }

    public function get(string $locator): array
    {
        [$status, $body, $headers] = $this->request('GET', $this->path($locator));
        if ($status === 404) throw new RuntimeException('WebDAV storage object not found: ' . $locator);
        if ($status !== 200) throw new RuntimeException('WebDAV storage read failed: HTTP ' . $status);
        return ['bytes' => $body, 'version' => $this->etag($headers)];
    }

    public function exists(string $locator): bool
    {
        [$status] = $this->request('HEAD', $this->path($locator));
        if ($status === 200) return true;
        if ($status === 404) return false;
        throw new RuntimeException('WebDAV storage HEAD failed: HTTP ' . $status);
    }

    public function put(string $locator, string $bytes, ?string $expectedVersion = null, bool $createOnly = false): string
    {
        $clean = self::cleanLocator($locator);
        $this->ensureParents($clean);
        $headers = ['content-type' => 'application/octet-stream'];
        if ($createOnly) $headers['if-none-match'] = '*';
        elseif ($expectedVersion !== null) $headers['if-match'] = self::quoteEtag($expectedVersion);

        [$status, , $responseHeaders] = $this->request('PUT', $this->path($clean), $bytes, $headers);
        if ($status === 412) throw new RuntimeException('WebDAV storage version conflict: ' . $locator);
        if (!in_array($status, [200, 201, 204], true)) throw new RuntimeException('WebDAV storage write failed: HTTP ' . $status);
        $etag = $this->etag($responseHeaders, false);
        if ($etag !== null) return $etag;
        [, , $headHeaders] = $this->request('HEAD', $this->path($clean));
        return $this->etag($headHeaders);
    }

    public function delete(string $locator, ?string $expectedVersion = null): void
    {
        $headers = [];
        if ($expectedVersion !== null) $headers['if-match'] = self::quoteEtag($expectedVersion);
        [$status] = $this->request('DELETE', $this->path($locator), '', $headers);
        if ($status === 404) return;
        if ($status === 412) throw new RuntimeException('WebDAV storage version conflict: ' . $locator);
        if (!in_array($status, [200, 202, 204], true)) throw new RuntimeException('WebDAV storage delete failed: HTTP ' . $status);
    }

    public function list(string $prefix = ''): array
    {
        $prefix = self::cleanLocator($prefix, true);
        $collection = $prefix;
        if ($collection !== '' && !str_ends_with($collection, '/')) {
            $collection = str_contains(basename($collection), '.') ? dirname($collection) : $collection;
            if ($collection === '.') $collection = '';
        }
        $out = [];
        $this->walk($collection, $out, []);
        $out = array_values(array_filter(array_unique($out), static fn(string $locator): bool => $prefix === '' || str_starts_with($locator, $prefix)));
        sort($out, SORT_STRING);
        return $out;
    }

    public function withWriteLock(callable $callback): mixed
    {
        // Coordination relies on HTTP conditional writes (ETag CAS) at manifest publication.
        return $callback();
    }

    public function capabilities(): array
    {
        return [
            'atomic_put' => false,
            'compare_and_swap' => true,
            'exclusive_lock' => false,
            'conditional_create' => true,
            'list_prefix' => true,
            'byte_preserving' => true,
            'version' => 'etag',
            'mkdir' => 'MKCOL',
        ];
    }

    private function walk(string $relativeCollection, array &$out, array $seen): void
    {
        $key = trim($relativeCollection, '/');
        if (isset($seen[$key])) return;
        $seen[$key] = true;
        $path = $this->path($key, true);
        $body = '<?xml version="1.0" encoding="utf-8" ?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getetag/></d:prop></d:propfind>';
        [$status, $xml] = $this->request('PROPFIND', $path, $body, ['depth' => '1', 'content-type' => 'application/xml; charset=utf-8']);
        if ($status === 404) return;
        if (!in_array($status, [207, 200], true)) throw new RuntimeException('WebDAV PROPFIND failed: HTTP ' . $status);
        foreach (self::parseMultistatus($xml) as $entry) {
            $relative = $this->hrefToLocator($entry['href']);
            if ($relative === null || $relative === $key || rtrim($relative, '/') === rtrim($key, '/')) continue;
            if ($entry['collection']) $this->walk(rtrim($relative, '/'), $out, $seen);
            else $out[] = $relative;
        }
    }

    private function ensureParents(string $locator): void
    {
        $dir = trim(dirname($locator), '.');
        if ($dir === '' || $dir === '/') return;
        $current = '';
        foreach (explode('/', trim($dir, '/')) as $segment) {
            $current = $current === '' ? $segment : $current . '/' . $segment;
            [$status] = $this->request('MKCOL', $this->path($current, true));
            if (!in_array($status, [201, 204, 301, 302, 405], true)) throw new RuntimeException('WebDAV MKCOL failed for ' . $current . ': HTTP ' . $status);
        }
    }

    private function path(string $locator, bool $collection = false): string
    {
        $locator = self::cleanLocator($locator, true);
        $path = $this->rootPath;
        if ($locator !== '') $path .= '/' . $locator;
        if ($path === '') $path = '/';
        if ($collection && !str_ends_with($path, '/')) $path .= '/';
        return $path;
    }

    private function hrefToLocator(string $href): ?string
    {
        $path = parse_url($href, PHP_URL_PATH);
        if (!is_string($path)) $path = $href;
        $path = rawurldecode($path);
        $root = $this->rootPath === '' ? '/' : rtrim($this->rootPath, '/') . '/';
        if ($path === rtrim($this->rootPath, '/') || $path === $root) return '';
        if (!str_starts_with($path, $root)) return null;
        return ltrim(substr($path, strlen($root)), '/');
    }

    /** @return list<array{href:string,collection:bool}> */
    private static function parseMultistatus(string $xml): array
    {
        $out = [];
        if (!preg_match_all('/<(?:[A-Za-z0-9_-]+:)?response\\b[^>]*>(.*?)<\\/(?:[A-Za-z0-9_-]+:)?response>/si', $xml, $responses)) return [];
        foreach ($responses[1] as $block) {
            if (!preg_match('/<(?:[A-Za-z0-9_-]+:)?href\\b[^>]*>(.*?)<\\/(?:[A-Za-z0-9_-]+:)?href>/si', $block, $m)) continue;
            $href = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $collection = (bool)preg_match('/<(?:[A-Za-z0-9_-]+:)?collection\\b/i', $block);
            $out[] = ['href' => $href, 'collection' => $collection];
        }
        return $out;
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function request(string $method, string $path, string $body = '', array $headers = []): array
    {
        $url = $this->scheme . '://' . $this->host . implode('/', array_map('rawurlencode', explode('/', $path)));
        $headers = array_change_key_case($headers, CASE_LOWER);
        if ($this->authMode === 'bearer') $headers['authorization'] = 'Bearer ' . $this->token;
        if ($this->authMode === 'basic') $headers['authorization'] = 'Basic ' . base64_encode((string)$this->username . ':' . (string)$this->password);
        if ($this->requester !== null) return ($this->requester)(strtoupper($method), $url, $headers, $body);
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for WebDAV storage');

        $responseHeaders = [];
        $ch = curl_init($url);
        $wireHeaders = [];
        foreach ($headers as $name => $value) $wireHeaders[] = $name . ': ' . $value;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $wireHeaders,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $len = strlen($line); $pos = strpos($line, ':');
                if ($pos !== false) $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                return $len;
            },
        ]);
        if ($body !== '' || in_array(strtoupper($method), ['PUT', 'PROPFIND'], true)) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        if (strtoupper($method) === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
        $responseBody = curl_exec($ch);
        if ($responseBody === false) { $error = curl_error($ch); curl_close($ch); throw new RuntimeException('WebDAV HTTP error: ' . $error); }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        return [$status, (string)$responseBody, $responseHeaders];
    }

    private function etag(array $headers, bool $required = true): ?string
    {
        $etag = $headers['etag'] ?? null;
        if (!is_string($etag) || trim($etag) === '') {
            if ($required) throw new RuntimeException('WebDAV response did not include ETag; safe CAS is unavailable');
            return null;
        }
        $etag = trim($etag);
        if (str_starts_with($etag, 'W/')) $etag = substr($etag, 2);
        return trim($etag, '"');
    }

    private static function quoteEtag(string $etag): string
    {
        $etag = trim($etag, " \t\n\r\0\x0B\"");
        if ($etag === '' || str_contains($etag, '"')) throw new RuntimeException('Invalid WebDAV ETag version');
        return '"' . $etag . '"';
    }

    private static function cleanLocator(string $locator, bool $allowEmpty = false): string
    {
        $locator = trim(str_replace('\\\\', '/', $locator), '/');
        if ($allowEmpty && $locator === '') return '';
        if ($locator === '' || str_contains($locator, '..') || str_contains($locator, "\0") || !preg_match('#^[A-Za-z0-9._/-]+$#', $locator)) throw new RuntimeException('Invalid WebDAV storage locator');
        return $locator;
    }
}
