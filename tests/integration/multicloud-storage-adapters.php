<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Storage\AlibabaOssStorageAdapter;
use MCMA\Core\Storage\AzureBlobStorageAdapter;
use MCMA\Core\Storage\GoogleCloudStorageAdapter;
use MCMA\Core\Storage\GoogleDriveStorageAdapter;
use MCMA\Core\Storage\StorageFactory;

function assert_multi(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/* Google Cloud Storage */
$gcsState = [];
$gcsGeneration = 10;
$gcsRequester = function(string $method, string $url, array $headers, string $body) use (&$gcsState, &$gcsGeneration): array {
    assert_multi(($headers['authorization'] ?? null) === 'Bearer gcs-token', 'GCS bearer token missing');
    $parts = parse_url($url);
    parse_str((string)($parts['query'] ?? ''), $query);
    $path = (string)($parts['path'] ?? '');

    if ($method === 'POST' && str_contains($path, '/upload/storage/v1/b/test-bucket/o')) {
        $name = (string)($query['name'] ?? '');
        $current = $gcsState[$name] ?? null;
        $match = $query['ifGenerationMatch'] ?? null;
        if ($match === '0' && $current !== null) return [412, '', []];
        if ($match !== null && $match !== '0' && ($current === null || (string)$current['generation'] !== (string)$match)) return [412, '', []];
        $gcsGeneration++;
        $gcsState[$name] = ['bytes'=>$body,'generation'=>(string)$gcsGeneration];
        return [200, json_encode(['generation'=>(string)$gcsGeneration], JSON_THROW_ON_ERROR), []];
    }

    if ($method === 'GET' && str_contains($path, '/storage/v1/b/test-bucket/o/')) {
        $encoded = substr($path, strpos($path, '/o/') + 3);
        $name = rawurldecode($encoded);
        if (!isset($gcsState[$name])) return [404, '', []];
        if (($query['alt'] ?? null) === 'media') return [200, $gcsState[$name]['bytes'], []];
        return [200, json_encode(['generation'=>$gcsState[$name]['generation']], JSON_THROW_ON_ERROR), []];
    }

    if ($method === 'GET' && str_ends_with($path, '/storage/v1/b/test-bucket/o')) {
        $prefix = (string)($query['prefix'] ?? '');
        $items = [];
        foreach ($gcsState as $name=>$item) {
            if ($prefix === '' || str_starts_with($name, $prefix)) $items[] = ['name'=>$name,'generation'=>$item['generation']];
        }
        return [200, json_encode(['items'=>$items], JSON_THROW_ON_ERROR), []];
    }

    if ($method === 'DELETE' && str_contains($path, '/storage/v1/b/test-bucket/o/')) {
        $name = rawurldecode(substr($path, strpos($path, '/o/') + 3));
        if (!isset($gcsState[$name])) return [404, '', []];
        $match = $query['ifGenerationMatch'] ?? null;
        if ($match !== null && (string)$gcsState[$name]['generation'] !== (string)$match) return [412, '', []];
        unset($gcsState[$name]);
        return [204, '', []];
    }

    throw new RuntimeException('Unexpected GCS request: '.$method.' '.$url);
};

$gcs = new GoogleCloudStorageAdapter('test-bucket', 'mcma', 'gcs-token', $gcsRequester);
$g1 = $gcs->put('manifest.mcma', 'gcs-v1', null, true);
assert_multi($gcs->get('manifest.mcma')['bytes'] === 'gcs-v1', 'GCS get mismatch');
$g2 = $gcs->put('manifest.mcma', 'gcs-v2', $g1);
assert_multi($g2 !== $g1, 'GCS generation did not change');
$gcsConflict = false;
try { $gcs->put('manifest.mcma', 'stale', $g1); } catch (RuntimeException $e) { $gcsConflict = str_contains($e->getMessage(), 'version conflict'); }
assert_multi($gcsConflict, 'GCS stale CAS accepted');
assert_multi($gcs->list() === ['manifest.mcma'], 'GCS list mismatch');
assert_multi(($gcs->capabilities()['compare_and_swap'] ?? false) === true, 'GCS CAS capability missing');

/* Azure Blob */
$azureState = [];
$azureVersion = 0;
$azureRequester = function(string $method, string $url, array $headers, string $body) use (&$azureState, &$azureVersion): array {
    assert_multi(str_contains($url, 'sig=test-sas'), 'Azure SAS missing');
    assert_multi(($headers['x-ms-version'] ?? null) === '2023-11-03', 'Azure API version missing');
    $parts = parse_url($url);
    parse_str((string)($parts['query'] ?? ''), $query);
    $path = rawurldecode((string)($parts['path'] ?? ''));

    if ($method === 'GET' && ($query['restype'] ?? null) === 'container' && ($query['comp'] ?? null) === 'list') {
        $prefix = (string)($query['prefix'] ?? '');
        $xml = '<?xml version="1.0"?><EnumerationResults><Blobs>';
        foreach ($azureState as $name=>$item) {
            if ($prefix === '' || str_starts_with($name, $prefix)) $xml .= '<Blob><Name>'.htmlspecialchars($name, ENT_XML1).'</Name></Blob>';
        }
        return [200, $xml.'</Blobs><NextMarker></NextMarker></EnumerationResults>', []];
    }

    $needle = '/container/';
    $pos = strpos($path, $needle);
    if ($pos === false) throw new RuntimeException('Unexpected Azure path: '.$path);
    $name = substr($path, $pos + strlen($needle));

    if ($method === 'PUT') {
        $current = $azureState[$name] ?? null;
        if (($headers['if-none-match'] ?? null) === '*' && $current !== null) return [412, '', []];
        if (isset($headers['if-match']) && ($current === null || trim((string)$headers['if-match'], '"') !== $current['etag'])) return [412, '', []];
        $azureVersion++;
        $etag = 'azure-'.$azureVersion;
        $azureState[$name] = ['bytes'=>$body,'etag'=>$etag];
        return [201, '', ['etag'=>'"'.$etag.'"']];
    }
    if ($method === 'HEAD') {
        if (!isset($azureState[$name])) return [404, '', []];
        return [200, '', ['etag'=>'"'.$azureState[$name]['etag'].'"']];
    }
    if ($method === 'GET') {
        if (!isset($azureState[$name])) return [404, '', []];
        return [200, $azureState[$name]['bytes'], ['etag'=>'"'.$azureState[$name]['etag'].'"']];
    }
    if ($method === 'DELETE') {
        if (!isset($azureState[$name])) return [404, '', []];
        if (isset($headers['if-match']) && trim((string)$headers['if-match'], '"') !== $azureState[$name]['etag']) return [412, '', []];
        unset($azureState[$name]);
        return [202, '', []];
    }

    throw new RuntimeException('Unexpected Azure request: '.$method.' '.$url);
};

$azure = new AzureBlobStorageAdapter('acctest', 'container', 'mcma', 'sig=test-sas', null, null, $azureRequester);
$a1 = $azure->put('manifest.mcma', 'azure-v1', null, true);
assert_multi($azure->get('manifest.mcma')['bytes'] === 'azure-v1', 'Azure get mismatch');
$a2 = $azure->put('manifest.mcma', 'azure-v2', $a1);
assert_multi($a2 !== $a1, 'Azure ETag did not change');
$azureConflict = false;
try { $azure->put('manifest.mcma', 'stale', $a1); } catch (RuntimeException $e) { $azureConflict = str_contains($e->getMessage(), 'version conflict'); }
assert_multi($azureConflict, 'Azure stale CAS accepted');
assert_multi($azure->list() === ['manifest.mcma'], 'Azure list mismatch');
assert_multi(($azure->capabilities()['compare_and_swap'] ?? false) === true, 'Azure CAS capability missing');

/* Google Drive */
$driveState = [];
$driveNextId = 1;
$driveRequester = function(string $method, string $url, array $headers, string $body) use (&$driveState, &$driveNextId): array {
    assert_multi(($headers['authorization'] ?? null) === 'Bearer drive-token', 'Drive bearer token missing');
    $parts = parse_url($url);
    parse_str((string)($parts['query'] ?? ''), $query);
    $path = (string)($parts['path'] ?? '');

    if ($method === 'GET' && $path === '/drive/v3/files') {
        $q = (string)($query['q'] ?? '');
        $files = [];
        foreach ($driveState as $locator=>$item) {
            if (str_contains($q, 'name = ') && !str_contains($q, $item['name'])) continue;
            $files[] = [
                'id'=>$item['id'],
                'name'=>$item['name'],
                'version'=>(string)$item['version'],
                'md5Checksum'=>md5($item['bytes']),
                'appProperties'=>['mcma_locator'=>$locator],
            ];
        }
        return [200, json_encode(['files'=>$files], JSON_THROW_ON_ERROR), []];
    }

    if ($method === 'POST' && $path === '/upload/drive/v3/files') {
        if (!preg_match('/"mcma_locator":"([^"]+)"/', $body, $m)) throw new RuntimeException('Drive create metadata locator missing');
        $locator = stripcslashes($m[1]);
        if (!preg_match('/"name":"([^"]+)"/', $body, $n)) throw new RuntimeException('Drive create metadata name missing');
        $name = $n[1];
        if (isset($driveState[$locator])) return [409, '', []];

        $boundaryEnd = strpos($body, "\r\n--", strpos($body, "Content-Type: application/octet-stream"));
        $payloadStart = strpos($body, "\r\n\r\n", strpos($body, "Content-Type: application/octet-stream")) + 4;
        $payload = substr($body, $payloadStart, $boundaryEnd - $payloadStart);

        $id = 'file-'.$driveNextId++;
        $driveState[$locator] = ['id'=>$id,'name'=>$name,'version'=>1,'bytes'=>$payload];
        return [200, json_encode(['id'=>$id,'version'=>'1','md5Checksum'=>md5($payload),'appProperties'=>['mcma_locator'=>$locator]], JSON_THROW_ON_ERROR), []];
    }

    if (preg_match('#^/upload/drive/v3/files/([^/]+)$#', $path, $m) && $method === 'PATCH') {
        $id = rawurldecode($m[1]);
        foreach ($driveState as $locator=>&$item) {
            if ($item['id'] !== $id) continue;
            $item['version']++;
            $item['bytes'] = $body;
            return [200, json_encode(['id'=>$id,'version'=>(string)$item['version'],'md5Checksum'=>md5($body),'appProperties'=>['mcma_locator'=>$locator]], JSON_THROW_ON_ERROR), []];
        }
        unset($item);
        return [404, '', []];
    }

    if (preg_match('#^/drive/v3/files/([^/]+)$#', $path, $m)) {
        $id = rawurldecode($m[1]);
        foreach ($driveState as $locator=>$item) {
            if ($item['id'] !== $id) continue;
            if ($method === 'GET' && ($query['alt'] ?? null) === 'media') return [200, $item['bytes'], []];
            if ($method === 'DELETE') { unset($driveState[$locator]); return [204, '', []]; }
        }
        return [404, '', []];
    }

    throw new RuntimeException('Unexpected Drive request: '.$method.' '.$url);
};

$drive = new GoogleDriveStorageAdapter('root_folder_123', 'drive-token', $driveRequester);
$d1 = $drive->put('manifest.mcma', 'drive-v1', null, true);
assert_multi($drive->get('manifest.mcma')['bytes'] === 'drive-v1', 'Drive get mismatch');
$d2 = $drive->put('manifest.mcma', 'drive-v2', $d1);
assert_multi($d2 !== $d1, 'Drive version did not change');
$driveConflict = false;
try { $drive->put('manifest.mcma', 'stale', $d1); } catch (RuntimeException $e) { $driveConflict = str_contains($e->getMessage(), 'version conflict'); }
assert_multi($driveConflict, 'Drive stale version check accepted');
assert_multi($drive->list() === ['manifest.mcma'], 'Drive list mismatch');
assert_multi(($drive->capabilities()['compare_and_swap'] ?? true) === false, 'Drive must not claim atomic CAS');
assert_multi(($drive->capabilities()['writer_model'] ?? null) === 'single-writer', 'Drive writer model missing');

/* Alibaba OSS through S3 compatibility */
$ossState = [];
$ossRequester = function(string $method, string $url, array $headers, string $body) use (&$ossState): array {
    assert_multi(str_contains($url, 'test-bucket.s3.oss-cn-hangzhou.aliyuncs.com'), 'OSS virtual-hosted endpoint mismatch');
    assert_multi(str_starts_with((string)($headers['authorization'] ?? ''), 'AWS4-HMAC-SHA256 '), 'OSS S3 SigV4 missing');
    $parts = parse_url($url);
    $name = ltrim(rawurldecode((string)($parts['path'] ?? '')), '/');

    if ($method === 'PUT') {
        if (($headers['if-none-match'] ?? null) === '*' && isset($ossState[$name])) return [412, '', []];
        $etag = md5($body);
        $ossState[$name] = ['bytes'=>$body,'etag'=>$etag];
        return [200, '', ['etag'=>'"'.$etag.'"']];
    }
    if ($method === 'HEAD') {
        if (!isset($ossState[$name])) return [404, '', []];
        return [200, '', ['etag'=>'"'.$ossState[$name]['etag'].'"']];
    }
    if ($method === 'GET') {
        if (!isset($ossState[$name])) return [404, '', []];
        return [200, $ossState[$name]['bytes'], ['etag'=>'"'.$ossState[$name]['etag'].'"']];
    }
    throw new RuntimeException('Unexpected OSS request: '.$method.' '.$url);
};

$oss = new AlibabaOssStorageAdapter('test-bucket', 'cn-hangzhou', 'mcma', null, 'AKID', 'SECRET', null, $ossRequester);
$o1 = $oss->put('manifest.mcma', 'oss-v1', null, true);
assert_multi($oss->get('manifest.mcma')['bytes'] === 'oss-v1', 'OSS get mismatch');
assert_multi(($oss->capabilities()['protocol'] ?? null) === 's3-compatible', 'OSS protocol capability missing');
assert_multi(($oss->capabilities()['provider'] ?? null) === 'alibaba-oss', 'OSS provider capability missing');

/* StorageFactory routing */
putenv('MCMA_GCS_ACCESS_TOKEN=factory-gcs');
putenv('MCMA_GDRIVE_ACCESS_TOKEN=factory-drive');
putenv('MCMA_AZURE_SAS_TOKEN=sig=factory');
putenv('MCMA_OSS_ACCESS_KEY_ID=AKID');
putenv('MCMA_OSS_ACCESS_KEY_SECRET=SECRET');

assert_multi(StorageFactory::fromLocation('gcs://factory-bucket/mcma') instanceof GoogleCloudStorageAdapter, 'StorageFactory GCS route failed');
assert_multi(StorageFactory::fromLocation('gdrive://root_folder_456') instanceof GoogleDriveStorageAdapter, 'StorageFactory Drive route failed');
assert_multi(StorageFactory::fromLocation('azure://account1/container/mcma') instanceof AzureBlobStorageAdapter, 'StorageFactory Azure route failed');
assert_multi(StorageFactory::fromLocation('oss://factory-bucket/mcma?region=cn-hangzhou') instanceof AlibabaOssStorageAdapter, 'StorageFactory OSS route failed');

echo "MCMA multi-cloud storage adapters simulation passed.\n";
