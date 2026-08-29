<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

use RuntimeException;

final class PrefixStorageAdapter implements StorageAdapter
{
    public function __construct(
        private readonly StorageAdapter $delegate,
        private readonly string $prefix
    ) {
        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        if ($prefix === '' || str_contains($prefix, '..') || str_contains($prefix, "\0") || !preg_match('#^[A-Za-z0-9._/-]+$#', $prefix)) {
            throw new RuntimeException('Invalid storage namespace prefix');
        }
    }

    public function id(): string
    {
        return $this->delegate->id() . '#prefix=' . trim($this->prefix, '/');
    }

    public function get(string $locator): array
    {
        return $this->delegate->get($this->locator($locator));
    }

    public function exists(string $locator): bool
    {
        return $this->delegate->exists($this->locator($locator));
    }

    public function put(string $locator, string $bytes, ?string $expectedVersion = null, bool $createOnly = false): string
    {
        return $this->delegate->put($this->locator($locator), $bytes, $expectedVersion, $createOnly);
    }

    public function delete(string $locator, ?string $expectedVersion = null): void
    {
        $this->delegate->delete($this->locator($locator), $expectedVersion);
    }

    public function list(string $prefix = ''): array
    {
        $relative = $this->clean($prefix, true);
        $fullPrefix = $this->locator($relative, true);
        $root = trim($this->prefix, '/') . '/';
        $out = [];

        foreach ($this->delegate->list($fullPrefix) as $locator) {
            if (!str_starts_with($locator, $root)) continue;
            $child = substr($locator, strlen($root));
            if ($child === '') continue;
            if ($relative === '' || str_starts_with($child, $relative)) $out[] = $child;
        }

        sort($out, SORT_STRING);
        return array_values(array_unique($out));
    }

    public function withWriteLock(callable $callback): mixed
    {
        return $this->delegate->withWriteLock($callback);
    }

    public function capabilities(): array
    {
        $capabilities = $this->delegate->capabilities();
        $capabilities['namespace_prefix'] = trim($this->prefix, '/');
        return $capabilities;
    }

    public function prefix(): string
    {
        return trim($this->prefix, '/');
    }

    private function locator(string $locator, bool $allowEmpty = false): string
    {
        $locator = $this->clean($locator, $allowEmpty);
        $base = trim($this->prefix, '/');
        return $locator === '' ? $base . '/' : $base . '/' . $locator;
    }

    private function clean(string $locator, bool $allowEmpty = false): string
    {
        $locator = trim(str_replace('\\', '/', $locator), '/');
        if ($allowEmpty && $locator === '') return '';
        if ($locator === '' || str_contains($locator, '..') || str_contains($locator, "\0") || !preg_match('#^[A-Za-z0-9._/-]+$#', $locator)) {
            throw new RuntimeException('Invalid namespaced storage locator');
        }
        return $locator;
    }
}
