# Storage Adapters

MCMA 1.0 now has an implemented provider-neutral storage boundary.

## Interface

```text
id()
get(locator) -> bytes + provider version
exists(locator)
put(locator, bytes, expectedVersion?, createOnly?)
delete(locator, expectedVersion?)
list(prefix)
withWriteLock(callback)
capabilities()
```

The core uses portable locators such as:

```text
manifest.mcma
objects/ab/cd/<sha256>.mcma
```

It does not use bucket names, repository paths or local filesystem paths as memory identity.

## Local filesystem adapter

Implemented in:

```text
packages/core/src/Storage/LocalFilesystemAdapter.php
```

It provides atomic file replacement, SHA-256 compare-and-swap versions, prefix listing and exclusive `.mcma.lock` writes.

## GitHub adapter

Implemented in:

```text
packages/core/src/Storage/GitHubStorageAdapter.php
```

Location syntax:

```text
github://OWNER/REPO/optional/prefix?branch=main
```

Authentication is supplied through:

```text
MCMA_GITHUB_TOKEN
```

The branch must already exist.

GitHub object versions use Git blob SHA values. The adapter does not pretend to have a distributed lock; instead the core publishes mutable library state by updating `manifest.mcma` with compare-and-swap semantics. A stale manifest SHA causes the write to fail instead of silently overwriting another writer.

## Byte-preserving provider migration

`mcma storage-copy SOURCE DESTINATION` copies all content-addressed objects first and publishes `manifest.mcma` last.

Each copied object's exact bytes are read back and compared.

Moving storage therefore does not change:

- encrypted envelope bytes;
- `object_id`;
- `storage_hash`;
- cryptographic identity.

The encryption key remains outside the storage provider and is not copied by `storage-copy`.

## Capabilities

Adapters expose capabilities such as:

```json
{
  "compare_and_swap": true,
  "exclusive_lock": false,
  "list_prefix": true,
  "byte_preserving": true
}
```

Remote adapters must define their concurrency behavior explicitly.

## Next adapters

S3-compatible and WebDAV remain future implementations.
