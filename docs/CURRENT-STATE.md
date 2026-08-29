# MCMA — Current State

Date: 2026-08-29

Status: **MCMA 1.0 storage abstraction completed across Local, GitHub, S3-compatible and WebDAV.**

## Storage boundary

```text
Library
  ↓
StorageAdapter
  ├── LocalFilesystemAdapter
  ├── GitHubStorageAdapter
  ├── S3StorageAdapter
  └── WebDavStorageAdapter
```

The core uses provider-neutral locators only.

## WebDAV

The WebDAV adapter implements:

- GET / HEAD / PUT / DELETE;
- PROPFIND depth-1 recursive listing;
- MKCOL parent creation;
- ETag versions;
- `If-None-Match: *` create-only writes;
- `If-Match` compare-and-swap updates;
- Basic authentication;
- Bearer authentication;
- credential-free storage URLs.

Location:

```text
webdav+https://HOST/existing/library/root
```

The root WebDAV collection must already exist. MCMA creates child collections beneath it.

## Tests

```text
tests/integration/webdav-storage-adapter.php
tests/integration/provider-migration-webdav.php
```

The provider migration test performs:

```text
Local → WebDAV → Local
```

and verifies exact encrypted bytes after the round trip.

## Next block

Permissions + Vault.
