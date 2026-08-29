# MCMA-OpenMemory

## MCMA 1.0 — Modular Cognitive Memory Archive

**Archivo Modular de Memoria Cognitiva**

> **Intelligence can change. Memory belongs to the person.**  
> **La inteligencia puede cambiar. La memoria pertenece a la persona.**

MCMA is an open, portable, encrypted, file-first memory archive controlled by the user. AI is optional.

## Active version

The repository has one active public line: **MCMA 1.0**.

Historical prototype formats remain only for compatibility and migration.

## Identity model

```text
memory:// logical reference
        ↓
stable object_id
        ↓
current storage_hash
        ↓
StorageAdapter
        ↓
encrypted .mcma bytes
```

Temperature, physical path and provider do not define permanent memory identity.

## Implemented storage adapters

```text
MCMA Core
   ↓
StorageAdapter
   ├── Local filesystem
   ├── GitHub
   └── S3 / S3-compatible
```

Locations:

```text
/local/path
github://OWNER/REPO/optional/prefix?branch=main
s3://BUCKET/optional/prefix?region=us-east-1
```

S3-compatible custom endpoints are configured outside the library with `MCMA_S3_ENDPOINT` and optional `MCMA_S3_PATH_STYLE`.

## Implemented CLI

```text
mcma init
mcma open
mcma info
mcma write
mcma update
mcma temperature
mcma read
mcma verify
mcma list
mcma tree
mcma key-export
mcma key-import
mcma migrate
mcma storage-copy
```

`storage-copy` performs byte-preserving provider migration and publishes the manifest only after encrypted objects have been copied and checked.

Master keys and provider credentials remain outside storage.

## S3 security/concurrency

The S3 adapter signs requests with AWS Signature Version 4.

Immutable object creation uses conditional create semantics, and mutable `manifest.mcma` updates use ETag compare-and-swap. A stale writer fails instead of silently replacing newer library state.

## Repository

```text
spec/1.0/
packages/core/
apps/cli/
tests/
docs/
reference/compatibility/
```

See `docs/STORAGE-ADAPTERS.md`, `SECURITY.md`, `ROADMAP.md` and `apps/cli/README.md`.

## License

MIT License.
