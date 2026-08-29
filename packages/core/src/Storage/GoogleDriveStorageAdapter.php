<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

use JsonException;
use RuntimeException;

final class GoogleDriveStorageAdapter implements StorageAdapter
{
    /** @var null|callable */
    private $requester;

    public function __construct(
        private readonly string $rootFolderId,
        private readonly ?string $accessToken = null,
        ?callable $requester = null
    ) {
        if (!preg_match('/^[A-Za-z0-9_-]{3,256}$/', $this->rootFolderId)) {
            throw new RuntimeException('Invalid Google Drive root folder id');
        }
        if ($requester === null && ($this->accessToken ?? '') === '') {
            throw new RuntimeException('Google Drive OAuth access token is required');
        }
        $this->requester = $requester;
    }

    public function id(): string
    {
        return 'gdrive:' . $this->rootFolderId;
    }

    public function get(string $locator): array
    {
        $locator = self::cleanLocator($locator);
        $meta = $this->findMetadata($locator);
        if ($meta === null) throw new RuntimeException('Google Drive storage object not found: ' . $locator);

        [$status, $body] = $this->request(
            'GET',
            'https://www.googleapis.com/drive/v3/files/' . rawurlencode((string)$meta['id']) . '?alt=media'
        );
        if ($status === 404) throw new RuntimeException('Google Drive storage object not found: ' . $locator);
        if ($status !== 200) throw new RuntimeException('Google Drive storage read failed: HTTP ' . $status);

        return ['bytes'=>$body,'version'=>(string)$meta['version']];
    }

    public function exists(string $locator): bool
    {
        return $this->findMetadata(self::cleanLocator($locator)) !== null;
    }

    public function put(string $locator, string $bytes, ?string $expectedVersion = null, bool $createOnly = false): string
    {
        $locator = self::cleanLocator($locator);
        $meta = $this->findMetadata($locator);

        if ($createOnly && $meta !== null) {
            throw new RuntimeException('Google Drive storage version conflict: ' . $locator);
        }

        if ($expectedVersion !== null) {
            if ($meta === null || !hash_equals($expectedVersion, (string)$meta['version'])) {
                throw new RuntimeException('Google Drive storage version conflict: ' . $locator);
            }
        }

        if ($meta !== null) {
            $url = 'https://www.googleapis.com/upload/drive/v3/files/' . rawurlencode((string)$meta['id'])
                . '?uploadType=media&fields=id,version,md5Checksum,appProperties';
            [$status, $body] = $this->request('PATCH', $url, $bytes, ['content-type'=>'application/octet-stream']);
            if ($status === 404) throw new RuntimeException('Google Drive storage object disappeared during update: ' . $locator);
            if (!in_array($status, [200,201], true)) throw new RuntimeException('Google Drive storage update failed: HTTP ' . $status);
            $updated = self::jsonObject($body, 'Google Drive update response');
            $version = (string)($updated['version'] ?? '');
            if ($version === '') throw new RuntimeException('Google Drive update response did not include version');
            return $version;
        }

        if ($expectedVersion !== null) throw new RuntimeException('Google Drive storage version conflict: ' . $locator);

        $boundary = 'mcma-' . substr(hash('sha256', $locator), 0, 24);
        $metadata = [
            'name' => self::fileName($locator),
            'parents' => [$this->rootFolderId],
            'appProperties' => ['mcma_locator'=>$locator],
        ];
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $multipart = '--'.$boundary."\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . $metadataJson."\r\n"
            . '--'.$boundary."\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $bytes."\r\n"
            . '--'.$boundary."--\r\n";

        $url = 'https://www.googleapis.com/upload/drive/v3/files'
            . '?uploadType=multipart&fields=id,version,md5Checksum,appProperties';
        [$status, $body] = $this->request(
            'POST',
            $url,
            $multipart,
            ['content-type'=>'multipart/related; boundary='.$boundary]
        );
        if (!in_array($status, [200,201], true)) throw new RuntimeException('Google Drive storage create failed: HTTP ' . $status);
        $created = self::jsonObject($body, 'Google Drive create response');
        $version = (string)($created['version'] ?? '');
        if ($version === '') throw new RuntimeException('Google Drive create response did not include version');
        return $version;
    }

    public function delete(string $locator, ?string $expectedVersion = null): void
    {
        $locator = self::cleanLocator($locator);
        $meta = $this->findMetadata($locator);
        if ($meta === null) return;

        if ($expectedVersion !== null && !hash_equals($expectedVersion, (string)$meta['version'])) {
            throw new RuntimeException('Google Drive storage version conflict: ' . $locator);
        }

        [$status] = $this->request(
            'DELETE',
            'https://www.googleapis.com/drive/v3/files/' . rawurlencode((string)$meta['id'])
        );
        if (!in_array($status, [200,204,404], true)) {
            throw new RuntimeException('Google Drive storage delete failed: HTTP ' . $status);
        }
    }

    public function list(string $prefix = ''): array
    {
        $prefix = self::cleanLocator($prefix, true);
        $pageToken = '';
        $out = [];

        do {
            $query = [
                'q' => "'" . self::escapeQuery($this->rootFolderId) . "' in parents and trashed = false",
                'spaces' => 'drive',
                'pageSize' => '1000',
                'fields' => 'nextPageToken,files(id,name,version,md5Checksum,appProperties)',
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];
            if ($pageToken !== '') $query['pageToken'] = $pageToken;

            [$status, $body] = $this->request(
                'GET',
                'https://www.googleapis.com/drive/v3/files?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            );
            if ($status !== 200) throw new RuntimeException('Google Drive storage list failed: HTTP ' . $status);
            $response = self::jsonObject($body, 'Google Drive list response');

            foreach (($response['files'] ?? []) as $file) {
                if (!is_array($file)) continue;
                $locator = $file['appProperties']['mcma_locator'] ?? null;
                if (!is_string($locator)) continue;
                try { $locator = self::cleanLocator($locator); }
                catch (RuntimeException) { continue; }
                if ($prefix === '' || str_starts_with($locator, $prefix)) $out[] = $locator;
            }

            $pageToken = is_string($response['nextPageToken'] ?? null) ? $response['nextPageToken'] : '';
        } while ($pageToken !== '');

        sort($out, SORT_STRING);
        return array_values(array_unique($out));
    }

    public function withWriteLock(callable $callback): mixed
    {
        // Google Drive has no object-store CAS primitive for this file workflow.
        // MCMA therefore exposes this adapter as single-writer / best-effort version checked.
        return $callback();
    }

    public function capabilities(): array
    {
        return [
            'atomic_put'=>false,
            'compare_and_swap'=>false,
            'exclusive_lock'=>false,
            'conditional_create'=>false,
            'conditional_delete'=>false,
            'list_prefix'=>true,
            'byte_preserving'=>true,
            'version'=>'drive-version',
            'writer_model'=>'single-writer',
        ];
    }

    private function findMetadata(string $locator): ?array
    {
        $query = [
            'q' => "'" . self::escapeQuery($this->rootFolderId) . "' in parents"
                . " and name = '" . self::escapeQuery(self::fileName($locator)) . "'"
                . ' and trashed = false',
            'spaces' => 'drive',
            'pageSize' => '10',
            'fields' => 'files(id,name,version,md5Checksum,appProperties)',
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ];
        [$status, $body] = $this->request(
            'GET',
            'https://www.googleapis.com/drive/v3/files?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)
        );
        if ($status !== 200) throw new RuntimeException('Google Drive metadata lookup failed: HTTP ' . $status);
        $response = self::jsonObject($body, 'Google Drive metadata response');

        $matches = [];
        foreach (($response['files'] ?? []) as $file) {
            if (!is_array($file)) continue;
            if (($file['appProperties']['mcma_locator'] ?? null) === $locator) $matches[] = $file;
        }
        if ($matches === []) return null;
        if (count($matches) > 1) throw new RuntimeException('Google Drive storage contains duplicate MCMA locator: ' . $locator);

        $version = (string)($matches[0]['version'] ?? '');
        $id = (string)($matches[0]['id'] ?? '');
        if ($version === '' || $id === '') throw new RuntimeException('Google Drive metadata missing id/version');
        return $matches[0];
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function request(string $method, string $url, string $body = '', array $headers = []): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        if (($this->accessToken ?? '') !== '') $headers['authorization'] = 'Bearer ' . $this->accessToken;

        if ($this->requester !== null) {
            $result = ($this->requester)(strtoupper($method), $url, $headers, $body);
            if (!is_array($result) || count($result) < 2) throw new RuntimeException('Invalid Google Drive requester result');
            return [(int)$result[0], (string)$result[1], is_array($result[2] ?? null) ? $result[2] : []];
        }
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for Google Drive storage');

        $responseHeaders = [];
        $wireHeaders = [];
        foreach ($headers as $name=>$value) $wireHeaders[] = $name . ': ' . $value;
        $ch = curl_init($url);
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
        $responseBody=curl_exec($ch);
        if($responseBody===false){$error=curl_error($ch);curl_close($ch);throw new RuntimeException('Google Drive HTTP error: '.$error);}
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        return [$status,(string)$responseBody,$responseHeaders];
    }

    private static function fileName(string $locator): string
    {
        return 'mcma-' . hash('sha256', $locator) . '.mcma';
    }

    private static function escapeQuery(string $value): string
    {
        return str_replace(["\\","'"], ["\\\\","\\'"], $value);
    }

    private static function jsonObject(string $body, string $label): array
    {
        try { $value=json_decode($body,true,512,JSON_THROW_ON_ERROR); }
        catch(JsonException $e){ throw new RuntimeException($label . ' is not valid JSON',0,$e); }
        if(!is_array($value)||array_is_list($value)) throw new RuntimeException($label . ' is not a JSON object');
        return $value;
    }

    private static function cleanLocator(string $locator, bool $allowEmpty = false): string
    {
        $locator=trim(str_replace('\\','/',$locator),'/');
        if($allowEmpty && $locator==='') return '';
        if($locator===''||str_contains($locator,'..')||str_contains($locator,"\0")||!preg_match('#^[A-Za-z0-9._/-]+$#',$locator)) {
            throw new RuntimeException('Invalid Google Drive storage locator');
        }
        return $locator;
    }
}
