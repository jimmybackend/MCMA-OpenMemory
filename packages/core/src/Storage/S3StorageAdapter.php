<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

use RuntimeException;

final class S3StorageAdapter implements StorageAdapter
{
    /** @var null|callable */
    private $requester;
    private string $scheme;
    private string $host;
    private string $endpointBasePath;

    public function __construct(
        private readonly string $bucket,
        private readonly string $region,
        private readonly string $prefix = '',
        ?string $endpoint = null,
        private readonly bool $pathStyle = false,
        private readonly ?string $accessKey = null,
        private readonly ?string $secretKey = null,
        private readonly ?string $sessionToken = null,
        ?callable $requester = null
    ) {
        if (!preg_match('/^[A-Za-z0-9._-]{1,255}$/', $bucket)) throw new RuntimeException('Invalid S3 bucket name');
        if ($region === '' || !preg_match('/^[A-Za-z0-9-]+$/', $region)) throw new RuntimeException('Invalid S3 region');
        $this->requester = $requester;

        if ($endpoint === null || trim($endpoint) === '') {
            $this->scheme = 'https';
            $this->host = $pathStyle ? 's3.' . $region . '.amazonaws.com' : $bucket . '.s3.' . $region . '.amazonaws.com';
            $this->endpointBasePath = '';
        } else {
            $parts = parse_url($endpoint);
            if ($parts === false || !isset($parts['scheme'], $parts['host'])) throw new RuntimeException('Invalid S3 endpoint');
            if (!in_array($parts['scheme'], ['http', 'https'], true)) throw new RuntimeException('S3 endpoint must use http or https');
            $this->scheme = $parts['scheme'];
            $host = $parts['host'];
            if (!$pathStyle) $host = $bucket . '.' . $host;
            if (isset($parts['port'])) $host .= ':' . $parts['port'];
            $this->host = $host;
            $this->endpointBasePath = trim((string)($parts['path'] ?? ''), '/');
        }

        if ($this->requester === null && (($accessKey ?? '') === '' || ($secretKey ?? '') === '')) {
            throw new RuntimeException('S3 credentials are required');
        }
    }

    public function id(): string
    {
        return 's3:' . $this->bucket . '@' . $this->region . '/' . trim($this->prefix, '/');
    }

    public function get(string $locator): array
    {
        [$status, $body, $headers] = $this->request('GET', $this->key($locator));
        if ($status === 404) throw new RuntimeException('S3 storage object not found: ' . $locator);
        if ($status !== 200) throw new RuntimeException('S3 storage read failed: HTTP ' . $status);
        return ['bytes' => $body, 'version' => $this->etagFromHeaders($headers)];
    }

    public function exists(string $locator): bool
    {
        [$status] = $this->request('HEAD', $this->key($locator));
        if ($status === 200) return true;
        if ($status === 404) return false;
        throw new RuntimeException('S3 storage HEAD failed: HTTP ' . $status);
    }

    public function put(string $locator, string $bytes, ?string $expectedVersion = null, bool $createOnly = false): string
    {
        $headers = ['content-type' => 'application/octet-stream'];
        if ($createOnly) $headers['if-none-match'] = '*';
        elseif ($expectedVersion !== null) $headers['if-match'] = self::quoteEtag($expectedVersion);

        [$status, , $responseHeaders] = $this->request('PUT', $this->key($locator), [], $bytes, $headers);
        if (in_array($status, [409, 412], true)) throw new RuntimeException('S3 storage version conflict: ' . $locator);
        if (!in_array($status, [200, 201], true)) throw new RuntimeException('S3 storage write failed: HTTP ' . $status);

        $etag = $this->etagFromHeaders($responseHeaders, false);
        if ($etag !== null) return $etag;
        [, , $headHeaders] = $this->request('HEAD', $this->key($locator));
        return $this->etagFromHeaders($headHeaders);
    }

    public function delete(string $locator, ?string $expectedVersion = null): void
    {
        if ($expectedVersion !== null) {
            [$status, , $headers] = $this->request('HEAD', $this->key($locator));
            if ($status === 404) return;
            if ($status !== 200) throw new RuntimeException('S3 storage HEAD failed before delete: HTTP ' . $status);
            if (!hash_equals($expectedVersion, $this->etagFromHeaders($headers))) throw new RuntimeException('S3 storage version conflict: ' . $locator);
        }
        [$status] = $this->request('DELETE', $this->key($locator));
        if (!in_array($status, [200, 204, 404], true)) throw new RuntimeException('S3 storage delete failed: HTTP ' . $status);
    }

    public function list(string $prefix = ''): array
    {
        $relativePrefix = self::cleanLocator($prefix, true);
        $fullPrefix = $this->key($relativePrefix, true);
        $query = ['list-type' => '2', 'encoding-type' => 'url', 'prefix' => $fullPrefix];
        $out = [];

        do {
            [$status, $body] = $this->request('GET', '', $query);
            if ($status !== 200) throw new RuntimeException('S3 ListObjectsV2 failed: HTTP ' . $status);

            foreach (self::xmlTagValues($body, 'Key') as $encodedKey) {
                $key = rawurldecode($encodedKey);
                $base = trim($this->prefix, '/');
                if ($base !== '') {
                    if ($key === $base) $relative = '';
                    elseif (str_starts_with($key, $base . '/')) $relative = substr($key, strlen($base) + 1);
                    else continue;
                } else $relative = $key;
                if ($relative !== '' && ($relativePrefix === '' || str_starts_with($relative, $relativePrefix))) $out[] = $relative;
            }

            $truncated = strtolower(self::xmlTagValue($body, 'IsTruncated') ?? 'false') === 'true';
            if ($truncated) {
                $token = rawurldecode(self::xmlTagValue($body, 'NextContinuationToken') ?? '');
                if ($token === '') throw new RuntimeException('S3 listing is truncated without continuation token');
                $query['continuation-token'] = $token;
            }
        } while ($truncated);

        sort($out, SORT_STRING);
        return array_values(array_unique($out));
    }

    public function withWriteLock(callable $callback): mixed
    {
        // S3 writer coordination uses atomic If-Match / If-None-Match on mutable manifest publication.
        return $callback();
    }

    public function capabilities(): array
    {
        return [
            'atomic_put' => true,
            'compare_and_swap' => true,
            'exclusive_lock' => false,
            'conditional_create' => true,
            'conditional_delete' => false,
            'list_prefix' => true,
            'byte_preserving' => true,
            'version' => 'etag',
        ];
    }

    private function key(string $locator, bool $allowEmpty = false): string
    {
        $locator = self::cleanLocator($locator, $allowEmpty);
        $prefix = trim($this->prefix, '/');
        if ($prefix !== '' && (str_contains($prefix, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $prefix))) throw new RuntimeException('Invalid S3 storage prefix');
        if ($prefix === '') return $locator;
        return $locator === '' ? $prefix . '/' : $prefix . '/' . $locator;
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function request(string $method, string $key, array $query = [], string $body = '', array $headers = []): array
    {
        $rawPath = $this->requestPath($key);
        $signed = AwsSigV4::sign(
            $method,
            $this->host,
            $rawPath,
            $query,
            $headers,
            $body,
            (string)$this->accessKey,
            (string)$this->secretKey,
            $this->region,
            's3',
            null,
            $this->sessionToken
        );
        $url = $this->scheme . '://' . $this->host . AwsSigV4::canonicalUri($rawPath);
        $canonicalQuery = AwsSigV4::canonicalQuery($query);
        if ($canonicalQuery !== '') $url .= '?' . $canonicalQuery;

        if ($this->requester !== null) return ($this->requester)($method, $url, $signed['headers'], $body);
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for S3 storage');

        $responseHeaders = [];
        $ch = curl_init($url);
        $wireHeaders = [];
        foreach ($signed['headers'] as $name => $value) $wireHeaders[] = $name . ': ' . $value;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $wireHeaders,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $len = strlen($line);
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    $value = trim(substr($line, $pos + 1));
                    if ($name !== '') $responseHeaders[$name] = $value;
                }
                return $len;
            },
        ]);
        if (strtoupper($method) === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
        if (in_array(strtoupper($method), ['PUT', 'POST'], true)) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch); curl_close($ch); throw new RuntimeException('S3 HTTP error: ' . $error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string)$responseBody, $responseHeaders];
    }

    private function requestPath(string $key): string
    {
        $parts = [];
        if ($this->endpointBasePath !== '') $parts[] = trim($this->endpointBasePath, '/');
        if ($this->pathStyle) $parts[] = $this->bucket;
        if ($key !== '') $parts[] = ltrim($key, '/');
        return '/' . implode('/', $parts);
    }

    private function etagFromHeaders(array $headers, bool $required = true): ?string
    {
        $etag = $headers['etag'] ?? null;
        if (!is_string($etag) || trim($etag) === '') {
            if ($required) throw new RuntimeException('S3 response did not include ETag');
            return null;
        }
        $etag = trim($etag);
        if (str_starts_with($etag, 'W/')) $etag = substr($etag, 2);
        return trim($etag, '"');
    }

    private static function quoteEtag(string $etag): string
    {
        $etag = trim($etag, " \t\n\r\0\x0B\"");
        if ($etag === '' || str_contains($etag, '"')) throw new RuntimeException('Invalid S3 ETag version');
        return '"' . $etag . '"';
    }

    private static function xmlTagValues(string $xml, string $tag): array
    {
        $quoted = preg_quote($tag, '/');
        if (!preg_match_all('/<' . $quoted . '>(.*?)<\/' . $quoted . '>/s', $xml, $matches)) return [];
        return array_map(static fn(string $value): string => html_entity_decode(trim($value), ENT_QUOTES | ENT_XML1, 'UTF-8'), $matches[1]);
    }

    private static function xmlTagValue(string $xml, string $tag): ?string
    {
        $values = self::xmlTagValues($xml, $tag);
        return $values[0] ?? null;
    }

    private static function cleanLocator(string $locator, bool $allowEmpty = false): string
    {
        $locator = trim(str_replace('\\', '/', $locator), '/');
        if ($allowEmpty && $locator === '') return '';
        if ($locator === '' || str_contains($locator, '..') || str_contains($locator, "\0") || !preg_match('#^[A-Za-z0-9._/-]+$#', $locator)) throw new RuntimeException('Invalid storage locator');
        return $locator;
    }
}
