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

## Implemented adapters

### Local filesystem

Atomic replacement, local exclusive lock, SHA-256 versions and prefix listing.

### GitHub

```text
github://OWNER/REPO/prefix?branch=main
```

Uses Git blob SHA compare-and-swap.

### S3 / S3-compatible

```text
s3://BUCKET/prefix?region=us-east-1
```

Uses SigV4, ETag versions and conditional PUT.

### WebDAV

```text
webdav+https://HOST/existing/library/root
```

Authentication is configured only through environment variables:

```text
MCMA_WEBDAV_AUTH=basic|bearer|none
MCMA_WEBDAV_USERNAME
MCMA_WEBDAV_PASSWORD
MCMA_WEBDAV_TOKEN
```

Credentials, query strings and fragments are rejected in the WebDAV location itself.

MCMA uses `MKCOL` for child collections and `PROPFIND` Depth: 1 recursively for listing.

Conditional writes use:

```text
If-None-Match: *        create-only
If-Match: <ETag>        mutable CAS
```

A server that does not return ETags is rejected for safe mutable library operation.

## Byte-preserving migration

`mcma storage-copy SOURCE DESTINATION` copies encrypted objects first, verifies bytes, then publishes `manifest.mcma`.

Tested provider round trips include Local ↔ S3-compatible and Local ↔ WebDAV.
