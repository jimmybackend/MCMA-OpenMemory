# MCMA — Current State

Date: 2026-08-29

Status: **Provider-neutral MCMA 1.0 core with Local and GitHub storage adapters implemented.**

## Core/storage separation

The active core is now:

```text
Library
  ↓
StorageAdapter
  ├── LocalFilesystemAdapter
  └── GitHubStorageAdapter
```

`Library` operates only on portable locators such as `manifest.mcma` and `objects/...mcma`.

The compatibility class `LocalLibrary` remains as a thin wrapper for existing local callers.

## Implemented storage behavior

Local:

- atomic file writes;
- exclusive write lock;
- compare-and-swap versions;
- prefix listing.

GitHub:

- GitHub Contents API byte storage;
- Git blob SHA versions;
- optimistic compare-and-swap for mutable manifest publication;
- recursive prefix listing;
- optional repository prefix and branch.

## Provider migration

```text
mcma storage-copy SOURCE_LOCATION DESTINATION_LOCATION
```

copies all encrypted content-addressed objects byte-for-byte and writes the manifest last.

Tests verify exact byte preservation.

## Tests

Added:

```text
tests/integration/storage-adapters.php
tests/integration/github-storage-adapter.php
```

The GitHub adapter test uses a simulated API transport; it does not require or expose a real token.

## Existing functionality retained

Stable revisions, temperature transitions, recovery, historical V1/V2 migration, encrypted index routing and conformance behavior remain in the core.

## Next block

Implement the S3-compatible adapter and provider migration tests Local ↔ S3-compatible. After that, implement WebDAV or begin the permissions/vault layer.
