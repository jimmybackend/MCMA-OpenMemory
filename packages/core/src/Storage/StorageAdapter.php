<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

interface StorageAdapter
{
    public function id(): string;
    /** @return array{bytes:string,version:string} */
    public function get(string $locator): array;
    public function exists(string $locator): bool;
    /** Returns provider version/etag. expectedVersion enables CAS; createOnly refuses overwrite. */
    public function put(string $locator, string $bytes, ?string $expectedVersion = null, bool $createOnly = false): string;
    public function delete(string $locator, ?string $expectedVersion = null): void;
    /** @return list<string> */
    public function list(string $prefix = ''): array;
    public function withWriteLock(callable $callback): mixed;
    /** @return array<string,bool|string|int> */
    public function capabilities(): array;
}
