<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

use JsonException;
use RuntimeException;

final class GoogleCloudStorageAdapter implements StorageAdapter
{
    /** @var null|callable */
    private $requester;

    public function __construct(
        private readonly string $bucket,
        private readonly string $prefix = '',
        private readonly ?string $accessToken = null,
        ?callable $requester = null
    ) {
        if (!preg_match('/^[A-Za-z0-9._-]{1,222}$/', $this->bucket)) {
            throw new RuntimeException('Invalid Google Cloud Storage bucket name');
        }
        self::validatePrefix($this->prefix);
        if ($requester === null && ($this->accessToken ?? '') === '') {
            throw new RuntimeException('Google Cloud Storage OAuth access token is required');
        }
        $this->requester = $requester;
    }

    public function id(): string
    {
        return 'gcs:' . $this->bucket . '/' . trim($this->prefix, '/');
    }

    public function get(string $locator): array
    {
        $name = $this->objectName($locator);
        [$metaStatus, $metaBody] = $this->request('GET', $this->objectMetadataUrl($name, ['fields'=>'generation']));
        if ($metaStatus === 404) throw new RuntimeException('Google Cloud Storage object not found: ' . $locator);
        if ($metaStatus !== 200) throw new RuntimeException('Google Cloud Storage metadata read failed: HTTP ' . $metaStatus);
        $meta = self::jsonObject($metaBody, 'Google Cloud Storage metadata response');
        $generation = (string)($meta['generation'] ?? '');
        if ($generation === '') throw new RuntimeException('Google Cloud Storage metadata response did not include generation');

        [$status, $body] = $this->request('GET', $this->objectMetadataUrl($name, ['alt'=>'media']));
        if ($status !== 200) throw new RuntimeException('Google Cloud Storage object read failed: HTTP ' . $status);

        return ['bytes'=>$body,'version'=>$generation];
    }

    public function exists(string $locator): bool
    {
        [$status] = $this->request('GET', $this->objectMetadataUrl($this->objectName($locator), ['fields'=>'generation']));
        if ($status === 200) return true;
        if ($status === 404) return false;
        throw new RuntimeException('Google Cloud Storage existence check failed: HTTP ' . $status);
    }

    public function put(string $locator, string $bytes, ?string $expectedVersion = null, bool $createOnly = false): string
    {
        $query = [
            'uploadType'=>'media',
            'name'=>$this->objectName($locator),
            'fields'=>'generation',
        ];
        if ($createOnly) $query['ifGenerationMatch'] = '0';
        elseif ($expectedVersion !== null) {
            if (!preg_match('/^\d+$/', $expectedVersion)) throw new RuntimeException('Invalid Google Cloud Storage generation');
            $query['ifGenerationMatch'] = $expectedVersion;
        }

        $url = 'https://storage.googleapis.com/upload/storage/v1/b/' . rawurlencode($this->bucket) . '/o?' . self::query($query);
        [$status, $body] = $this->request('POST', $url, $bytes, ['content-type'=>'application/octet-stream']);
        if (in_array($status, [409,412], true)) throw new RuntimeException('Google Cloud Storage version conflict: ' . $locator);
        if (!in_array($status, [200,201], true)) throw new RuntimeException('Google Cloud Storage write failed: HTTP ' . $status);

        $response = self::jsonObject($body, 'Google Cloud Storage write response');
        $generation = (string)($response['generation'] ?? '');
        if ($generation === '') throw new RuntimeException('Google Cloud Storage write response did not include generation');
        return $generation;
    }

    public function delete(string $locator, ?string $expectedVersion = null): void
    {
        $query = [];
        if ($expectedVersion !== null) {
            if (!preg_match('/^\d+$/', $expectedVersion)) throw new RuntimeException('Invalid Google Cloud Storage generation');
            $query['ifGenerationMatch'] = $expectedVersion;
        }
        [$status] = $this->request('DELETE', $this->objectMetadataUrl($this->objectName($locator), $query));
        if (in_array($status, [409,412], true)) throw new RuntimeException('Google Cloud Storage version conflict: ' . $locator);
        if (!in_array($status, [204,404], true)) throw new RuntimeException('Google Cloud Storage delete failed: HTTP ' . $status);
    }

    public function list(string $prefix = ''): array
    {
        $relativePrefix = self::cleanLocator($prefix, true);
        $fullPrefix = $this->objectName($relativePrefix, true);
        $query = [
            'prefix'=>$fullPrefix,
            'fields'=>'nextPageToken,items(name,generation)',
        ];
        $out = [];

        do {
            $url = 'https://storage.googleapis.com/storage/v1/b/' . rawurlencode($this->bucket) . '/o?' . self::query($query);
            [$status, $body] = $this->request('GET', $url);
            if ($status !== 200) throw new RuntimeException('Google Cloud Storage list failed: HTTP ' . $status);
            $response = self::jsonObject($body, 'Google Cloud Storage list response');

            foreach (($response['items'] ?? []) as $item) {
                if (!is_array($item) || !is_string($item['name'] ?? null)) continue;
                $relative = $this->relativeName($item['name']);
                if ($relative !== null && $relative !== '' && ($relativePrefix === '' || str_starts_with($relative, $relativePrefix))) {
                    $out[] = $relative;
                }
            }

            $token = is_string($response['nextPageToken'] ?? null) ? $response['nextPageToken'] : '';
            if ($token !== '') $query['pageToken'] = $token;
            else unset($query['pageToken']);
        } while ($token !== '');

        sort($out, SORT_STRING);
        return array_values(array_unique($out));
    }

    public function withWriteLock(callable $callback): mixed
    {
        return $callback();
    }

    public function capabilities(): array
    {
        return [
            'atomic_put'=>true,
            'compare_and_swap'=>true,
            'exclusive_lock'=>false,
            'conditional_create'=>true,
            'conditional_delete'=>true,
            'list_prefix'=>true,
            'byte_preserving'=>true,
            'version'=>'generation',
        ];
    }

    private function objectMetadataUrl(string $name, array $query = []): string
    {
        $url = 'https://storage.googleapis.com/storage/v1/b/' . rawurlencode($this->bucket) . '/o/' . rawurlencode($name);
        if ($query !== []) $url .= '?' . self::query($query);
        return $url;
    }

    private function objectName(string $locator, bool $allowEmpty = false): string
    {
        $locator = self::cleanLocator($locator, $allowEmpty);
        $base = trim($this->prefix, '/');
        if ($base === '') return $locator;
        return $locator === '' ? $base . '/' : $base . '/' . $locator;
    }

    private function relativeName(string $name): ?string
    {
        $base = trim($this->prefix, '/');
        if ($base === '') return $name;
        if ($name === $base) return '';
        if (!str_starts_with($name, $base . '/')) return null;
        return substr($name, strlen($base) + 1);
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function request(string $method, string $url, string $body = '', array $headers = []): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        if (($this->accessToken ?? '') !== '') $headers['authorization'] = 'Bearer ' . $this->accessToken;

        if ($this->requester !== null) {
            $result = ($this->requester)(strtoupper($method), $url, $headers, $body);
            if (!is_array($result) || count($result) < 2) throw new RuntimeException('Invalid Google Cloud Storage requester result');
            return [(int)$result[0], (string)$result[1], is_array($result[2] ?? null) ? $result[2] : []];
        }
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for Google Cloud Storage');

        $responseHeaders = [];
        $ch = curl_init($url);
        $wireHeaders = [];
        foreach ($headers as $name=>$value) $wireHeaders[] = $name . ': ' . $value;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CUSTOMREQUEST=>strtoupper($method),
            CURLOPT_HTTPHEADER=>$wireHeaders,
            CURLOPT_TIMEOUT=>60,
            CURLOPT_HEADERFUNCTION=>static function($ch,string $line) use (&$responseHeaders): int {
                $len=strlen($line); $pos=strpos($line,':');
                if($pos!==false) $responseHeaders[strtolower(trim(substr($line,0,$pos)))] = trim(substr($line,$pos+1));
                return $len;
            },
        ]);
        if (in_array(strtoupper($method), ['POST','PUT','PATCH'], true)) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $responseBody = curl_exec($ch);
        if ($responseBody === false) { $error=curl_error($ch); curl_close($ch); throw new RuntimeException('Google Cloud Storage HTTP error: '.$error); }
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
        return [$status,(string)$responseBody,$responseHeaders];
    }

    private static function jsonObject(string $body, string $label): array
    {
        try { $value=json_decode($body,true,512,JSON_THROW_ON_ERROR); }
        catch(JsonException $e){ throw new RuntimeException($label . ' is not valid JSON',0,$e); }
        if(!is_array($value)||array_is_list($value)) throw new RuntimeException($label . ' is not a JSON object');
        return $value;
    }

    private static function query(array $query): string
    {
        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function validatePrefix(string $prefix): void
    {
        $prefix=trim(str_replace('\\','/',$prefix),'/');
        if($prefix!=='' && (str_contains($prefix,'..')||str_contains($prefix,"\0")||!preg_match('#^[A-Za-z0-9._/-]+$#',$prefix))) {
            throw new RuntimeException('Invalid Google Cloud Storage prefix');
        }
    }

    private static function cleanLocator(string $locator, bool $allowEmpty = false): string
    {
        $locator=trim(str_replace('\\','/',$locator),'/');
        if($allowEmpty && $locator==='') return '';
        if($locator===''||str_contains($locator,'..')||str_contains($locator,"\0")||!preg_match('#^[A-Za-z0-9._/-]+$#',$locator)) {
            throw new RuntimeException('Invalid Google Cloud Storage locator');
        }
        return $locator;
    }
}
