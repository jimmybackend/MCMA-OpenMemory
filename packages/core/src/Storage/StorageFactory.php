<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

use RuntimeException;

final class StorageFactory
{
    public static function fromLocation(string $location): StorageAdapter
    {
        if (!str_starts_with($location, 'github://')) return new LocalFilesystemAdapter(rtrim($location, DIRECTORY_SEPARATOR));
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
}
