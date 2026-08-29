# MCMA — Current State

Date: 2026-08-29

Status: **Provider-neutral MCMA 1.0 core with Local, GitHub and S3-compatible storage implemented.**

## Storage boundary

```text
Library
  ↓
StorageAdapter
  ├── LocalFilesystemAdapter
  ├── GitHubStorageAdapter
  └── S3StorageAdapter
```

The core uses only portable locators such as `manifest.mcma` and `objects/...mcma`.

## S3 implementation

The S3 adapter implements:

- AWS Signature Version 4;
- GET/HEAD/PUT/DELETE;
- ListObjectsV2 prefix listing;
- ETag provider versions;
- `If-None-Match: *` create-only writes;
- `If-Match` compare-and-swap updates;
- AWS S3 default endpoints;
- custom S3-compatible endpoints;
- optional path-style addressing;
- temporary/session credentials.

Credentials remain outside MCMA objects.

## Locations

```text
/local/path
github://OWNER/REPO/prefix?branch=main
s3://BUCKET/prefix?region=us-east-1
```

Custom S3-compatible services are configured through `MCMA_S3_ENDPOINT` and `MCMA_S3_PATH_STYLE`.

## Tests

Added:

```text
tests/conformance/aws-sigv4-s3.php
tests/integration/s3-storage-adapter.php
tests/integration/provider-migration-s3.php
```

The SigV4 test reproduces the official AWS S3 signature vector.

The provider migration test performs:

```text
Local → S3-compatible → Local
```

and verifies exact bytes after the round trip.

## Existing behavior retained

Stable object IDs, storage hashes, encrypted revisions, temperature transitions, recovery and historical V1/V2 migration are unchanged.

## Next block

Implement WebDAV or move upward into the permissions/vault layer.
