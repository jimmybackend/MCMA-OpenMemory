# Storage Adapters

MCMA 1.0 has an implemented provider-neutral storage boundary.

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

Portable locators:

```text
manifest.mcma
objects/ab/cd/<sha256>.mcma
```

Provider paths never define memory identity.

## Local filesystem

`LocalFilesystemAdapter` provides atomic file replacement, SHA-256 versions, prefix listing and exclusive `.mcma.lock` writes.

## GitHub

`GitHubStorageAdapter` uses GitHub Contents/Git tree APIs.

```text
github://OWNER/REPO/optional/prefix?branch=main
```

The mutable manifest is protected by optimistic compare-and-swap using its Git blob SHA.

## S3 / S3-compatible

`S3StorageAdapter` uses AWS Signature Version 4 and ETag-based versions.

```text
s3://BUCKET/optional/prefix?region=us-east-1
```

Environment configuration:

```text
MCMA_S3_REGION
MCMA_S3_ACCESS_KEY_ID
MCMA_S3_SECRET_ACCESS_KEY
MCMA_S3_SESSION_TOKEN
MCMA_S3_ENDPOINT
MCMA_S3_PATH_STYLE
```

Standard `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`, `AWS_REGION` and `AWS_DEFAULT_REGION` are fallback inputs.

Without a custom endpoint, MCMA uses normal AWS S3 regional virtual-hosted addressing. For custom compatible endpoints, path-style defaults to enabled unless explicitly overridden.

S3 write coordination uses:

```text
new immutable object  → If-None-Match: *
manifest update       → If-Match: <current ETag>
```

A stale mutable write fails instead of silently replacing newer library state.

## Byte-preserving provider migration

`mcma storage-copy SOURCE DESTINATION` copies content-addressed objects first, checks the returned bytes, and publishes `manifest.mcma` last.

Storage migration therefore preserves:

- exact encrypted bytes;
- `object_id`;
- `storage_hash`;
- cryptographic identity.

Keys are not copied into the storage provider.

## Tests

```text
tests/integration/storage-adapters.php
tests/integration/github-storage-adapter.php
tests/integration/s3-storage-adapter.php
tests/integration/provider-migration-s3.php
tests/conformance/aws-sigv4-s3.php
```

## Next adapter

WebDAV remains unimplemented.
