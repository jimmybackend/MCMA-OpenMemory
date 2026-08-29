<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

use RuntimeException;

final class StorageFactory
{
    public static function fromLocation(string $location): StorageAdapter
    {
        if (str_starts_with($location, 'github://')) return self::github($location);
        if (str_starts_with($location, 's3://')) return self::s3($location);
        if (str_starts_with($location, 'gcs://')) return self::gcs($location);
        if (str_starts_with($location, 'gdrive://')) return self::gdrive($location);
        if (str_starts_with($location, 'azure://')) return self::azure($location);
        if (str_starts_with($location, 'oss://')) return self::oss($location);
        if (str_starts_with($location, 'webdav+https://') || str_starts_with($location, 'webdav+http://')) return self::webdav($location);
        return new LocalFilesystemAdapter(rtrim($location, DIRECTORY_SEPARATOR));
    }

    private static function github(string $location): StorageAdapter
    {
        $parts = parse_url($location);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'github' || empty($parts['host'])) throw new RuntimeException('Invalid github:// storage location');
        $owner = (string)$parts['host'];
        $segments = array_values(array_filter(explode('/', trim((string)($parts['path'] ?? ''), '/')), 'strlen'));
        if ($segments === []) throw new RuntimeException('GitHub storage location requires repository name');
        $repo = array_shift($segments);
        $prefix = implode('/', $segments);
        parse_str((string)($parts['query'] ?? ''), $query);
        $branch = (string)($query['branch'] ?? 'main');
        $token = getenv('MCMA_GITHUB_TOKEN');
        return new GitHubStorageAdapter($owner, $repo, $branch, $prefix, is_string($token) ? $token : null);
    }

    private static function s3(string $location): StorageAdapter
    {
        $parts = parse_url($location);
        if ($parts === false || ($parts['scheme'] ?? null) !== 's3' || empty($parts['host'])) throw new RuntimeException('Invalid s3:// storage location');
        $bucket = (string)$parts['host'];
        $prefix = trim((string)($parts['path'] ?? ''), '/');
        parse_str((string)($parts['query'] ?? ''), $query);

        $region = (string)($query['region'] ?? self::firstEnv(['MCMA_S3_REGION', 'AWS_REGION', 'AWS_DEFAULT_REGION']) ?? 'us-east-1');
        $endpoint = isset($query['endpoint']) ? (string)$query['endpoint'] : self::firstEnv(['MCMA_S3_ENDPOINT']);
        $pathStyleRaw = $query['path_style'] ?? self::firstEnv(['MCMA_S3_PATH_STYLE']);
        $pathStyle = $pathStyleRaw === null ? ($endpoint !== null && $endpoint !== '') : self::boolValue((string)$pathStyleRaw);

        $accessKey = self::firstEnv(['MCMA_S3_ACCESS_KEY_ID', 'AWS_ACCESS_KEY_ID']);
        $secretKey = self::firstEnv(['MCMA_S3_SECRET_ACCESS_KEY', 'AWS_SECRET_ACCESS_KEY']);
        $sessionToken = self::firstEnv(['MCMA_S3_SESSION_TOKEN', 'AWS_SESSION_TOKEN']);

        return new S3StorageAdapter($bucket, $region, $prefix, $endpoint, $pathStyle, $accessKey, $secretKey, $sessionToken);
    }

    private static function gcs(string $location): StorageAdapter
    {
        $parts = parse_url($location);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'gcs' || empty($parts['host'])) {
            throw new RuntimeException('Invalid gcs:// storage location');
        }

        $bucket = (string)$parts['host'];
        $prefix = trim((string)($parts['path'] ?? ''), '/');
        $token = self::firstEnv(['MCMA_GCS_ACCESS_TOKEN']);

        return new GoogleCloudStorageAdapter($bucket, $prefix, $token);
    }

    private static function gdrive(string $location): StorageAdapter
    {
        $parts = parse_url($location);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'gdrive' || empty($parts['host'])) {
            throw new RuntimeException('Invalid gdrive:// storage location');
        }
        if (trim((string)($parts['path'] ?? ''), '/') !== '' || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('gdrive:// location must contain only the root folder id');
        }

        $rootFolderId = (string)$parts['host'];
        $token = self::firstEnv(['MCMA_GDRIVE_ACCESS_TOKEN', 'MCMA_GOOGLE_DRIVE_ACCESS_TOKEN']);

        return new GoogleDriveStorageAdapter($rootFolderId, $token);
    }

    private static function azure(string $location): StorageAdapter
    {
        $parts = parse_url($location);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'azure' || empty($parts['host'])) {
            throw new RuntimeException('Invalid azure:// storage location');
        }

        $account = strtolower((string)$parts['host']);
        $segments = array_values(array_filter(explode('/', trim((string)($parts['path'] ?? ''), '/')), 'strlen'));
        if ($segments === []) throw new RuntimeException('azure:// storage location requires container name');

        $container = strtolower((string)array_shift($segments));
        $prefix = implode('/', $segments);
        parse_str((string)($parts['query'] ?? ''), $query);

        $endpoint = isset($query['endpoint']) ? (string)$query['endpoint'] : self::firstEnv(['MCMA_AZURE_BLOB_ENDPOINT']);
        $sasToken = self::firstEnv(['MCMA_AZURE_SAS_TOKEN']);
        $bearerToken = self::firstEnv(['MCMA_AZURE_BEARER_TOKEN']);

        return new AzureBlobStorageAdapter($account, $container, $prefix, $sasToken, $bearerToken, $endpoint);
    }

    private static function oss(string $location): StorageAdapter
    {
        $parts = parse_url($location);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'oss' || empty($parts['host'])) {
            throw new RuntimeException('Invalid oss:// storage location');
        }

        $bucket = (string)$parts['host'];
        $prefix = trim((string)($parts['path'] ?? ''), '/');
        parse_str((string)($parts['query'] ?? ''), $query);

        $region = (string)($query['region'] ?? self::firstEnv(['MCMA_OSS_REGION']) ?? 'cn-hangzhou');
        $endpoint = isset($query['endpoint']) ? (string)$query['endpoint'] : self::firstEnv(['MCMA_OSS_ENDPOINT']);
        $accessKey = self::firstEnv(['MCMA_OSS_ACCESS_KEY_ID', 'ALIBABA_CLOUD_ACCESS_KEY_ID']);
        $secretKey = self::firstEnv(['MCMA_OSS_ACCESS_KEY_SECRET', 'ALIBABA_CLOUD_ACCESS_KEY_SECRET']);
        $sessionToken = self::firstEnv(['MCMA_OSS_SECURITY_TOKEN', 'ALIBABA_CLOUD_SECURITY_TOKEN']);

        return new AlibabaOssStorageAdapter($bucket, $region, $prefix, $endpoint, $accessKey, $secretKey, $sessionToken);
    }

    private static function webdav(string $location): StorageAdapter
    {
        $endpoint = preg_replace('#^webdav\\+#', '', $location);
        if (!is_string($endpoint) || $endpoint === $location) throw new RuntimeException('Invalid WebDAV storage location');

        $token = self::firstEnv(['MCMA_WEBDAV_TOKEN']);
        $username = self::firstEnv(['MCMA_WEBDAV_USERNAME']);
        $password = self::firstEnv(['MCMA_WEBDAV_PASSWORD']);
        $auth = self::firstEnv(['MCMA_WEBDAV_AUTH']);
        if ($auth === null) {
            if ($token !== null) $auth = 'bearer';
            elseif ($username !== null || $password !== null) $auth = 'basic';
            else $auth = 'none';
        }
        return new WebDavStorageAdapter($endpoint, strtolower($auth), $username, $password, $token);
    }

    private static function firstEnv(array $names): ?string
    {
        foreach ($names as $name) {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') return trim($value);
        }
        return null;
    }

    private static function boolValue(string $value): bool
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) return true;
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) return false;
        throw new RuntimeException('Invalid boolean storage option: ' . $value);
    }
}
