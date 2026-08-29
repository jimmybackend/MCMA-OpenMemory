<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

use RuntimeException;

final class LocalFilesystemAdapter implements StorageAdapter
{
    public function __construct(private readonly string $root)
    {
        if ($root === '' || str_contains($root, "\0")) throw new RuntimeException('Invalid local storage root');
    }

    public function id(): string { return 'local:' . $this->root; }
    public function root(): string { return $this->root; }

    public function get(string $locator): array
    {
        $path = $this->path($locator);
        $bytes = @file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Storage object not found: ' . $locator);
        return ['bytes' => $bytes, 'version' => hash('sha256', $bytes)];
    }

    public function exists(string $locator): bool { return is_file($this->path($locator)); }

    public function put(string $locator, string $bytes, ?string $expectedVersion = null, bool $createOnly = false): string
    {
        $path = $this->path($locator);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Unable to create storage directory');

        if (is_file($path)) {
            $current = @file_get_contents($path);
            if ($current === false) throw new RuntimeException('Unable to read existing storage object');
            $version = hash('sha256', $current);
            if ($createOnly) {
                if (hash_equals($current, $bytes)) return $version;
                throw new RuntimeException('Storage object already exists: ' . $locator);
            }
            if ($expectedVersion !== null && !hash_equals($expectedVersion, $version)) throw new RuntimeException('Storage version conflict: ' . $locator);
        } elseif ($expectedVersion !== null) {
            throw new RuntimeException('Storage version conflict: expected object is missing: ' . $locator);
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $bytes, LOCK_EX) === false) throw new RuntimeException('Unable to write storage object');
        @chmod($tmp, 0600);
        if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Unable to install storage object'); }
        @chmod($path, 0600);
        return hash('sha256', $bytes);
    }

    public function delete(string $locator, ?string $expectedVersion = null): void
    {
        $path = $this->path($locator);
        if (!is_file($path)) return;
        if ($expectedVersion !== null) {
            $bytes = @file_get_contents($path);
            if ($bytes === false || !hash_equals($expectedVersion, hash('sha256', $bytes))) throw new RuntimeException('Storage version conflict: ' . $locator);
        }
        if (!@unlink($path)) throw new RuntimeException('Unable to delete storage object: ' . $locator);
    }

    public function list(string $prefix = ''): array
    {
        $prefix = self::cleanLocator($prefix, true);
        $base = $prefix === '' ? rtrim($this->root, DIRECTORY_SEPARATOR) : $this->path($prefix);
        if (!file_exists($base)) return [];
        if (is_file($base)) return [$prefix];

        $out = [];
        $root = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $full = $file->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($full, strlen($root)));
            if ($relative === '.mcma.lock') continue;
            $out[] = $relative;
        }
        sort($out, SORT_STRING);
        return $out;
    }

    public function withWriteLock(callable $callback): mixed
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) throw new RuntimeException('Unable to create local storage root');
        $path = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.mcma.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) throw new RuntimeException('Unable to open MCMA storage lock');
        @chmod($path, 0600);
        $timeout = (float)(getenv('MCMA_LOCK_TIMEOUT_SECONDS') ?: '10');
        if ($timeout <= 0 || $timeout > 300) $timeout = 10.0;
        $deadline = microtime(true) + $timeout;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) { fclose($handle); throw new RuntimeException('Timed out waiting for MCMA storage lock'); }
            usleep(100000);
        }
        try { return $callback(); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function capabilities(): array
    {
        return ['atomic_put' => true, 'compare_and_swap' => true, 'exclusive_lock' => true, 'list_prefix' => true, 'byte_preserving' => true];
    }

    private function path(string $locator): string
    {
        $locator = self::cleanLocator($locator);
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $locator);
    }

    private static function cleanLocator(string $locator, bool $allowEmpty = false): string
    {
        $locator = trim(str_replace('\\', '/', $locator), '/');
        if ($allowEmpty && $locator === '') return '';
        if ($locator === '' || str_contains($locator, '..') || str_contains($locator, "\0") || !preg_match('#^[A-Za-z0-9._/-]+$#', $locator)) throw new RuntimeException('Invalid storage locator');
        return $locator;
    }
}
