<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

final class AlibabaOssStorageAdapter implements StorageAdapter
{
    private readonly S3StorageAdapter $delegate;

    public function __construct(
        private readonly string $bucket,
        private readonly string $region,
        private readonly string $prefix = '',
        ?string $endpoint = null,
        ?string $accessKey = null,
        ?string $secretKey = null,
        ?string $sessionToken = null,
        ?callable $requester = null
    ) {
        $endpoint ??= 'https://s3.oss-' . $this->region . '.aliyuncs.com';
        $this->delegate = new S3StorageAdapter(
            $this->bucket,
            $this->region,
            $this->prefix,
            $endpoint,
            false,
            $accessKey,
            $secretKey,
            $sessionToken,
            $requester
        );
    }

    public function id(): string
    {
        return 'oss:' . $this->bucket . '@' . $this->region . '/' . trim($this->prefix, '/');
    }

    public function get(string $locator): array
    {
        return $this->delegate->get($locator);
    }

    public function exists(string $locator): bool
    {
        return $this->delegate->exists($locator);
    }

    public function put(string $locator, string $bytes, ?string $expectedVersion = null, bool $createOnly = false): string
    {
        return $this->delegate->put($locator, $bytes, $expectedVersion, $createOnly);
    }

    public function delete(string $locator, ?string $expectedVersion = null): void
    {
        $this->delegate->delete($locator, $expectedVersion);
    }

    public function list(string $prefix = ''): array
    {
        return $this->delegate->list($prefix);
    }

    public function withWriteLock(callable $callback): mixed
    {
        return $this->delegate->withWriteLock($callback);
    }

    public function capabilities(): array
    {
        $capabilities = $this->delegate->capabilities();
        $capabilities['protocol'] = 's3-compatible';
        $capabilities['provider'] = 'alibaba-oss';
        return $capabilities;
    }
}
