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
   ├── S3 / S3-compatible
   └── WebDAV
```

Locations:

```text
/local/path
github://OWNER/REPO/optional/prefix?branch=main
s3://BUCKET/optional/prefix?region=us-east-1
webdav+https://HOST/existing/library/root
```

Master keys and provider credentials remain outside storage.

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

## Provider concurrency

- Local: exclusive filesystem lock + compare-and-swap.
- GitHub: Git blob SHA compare-and-swap.
- S3: ETag + conditional PUT.
- WebDAV: ETag + HTTP conditional PUT.

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
