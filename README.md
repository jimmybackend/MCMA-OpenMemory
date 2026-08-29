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
   └── GitHub
```

Local paths work directly.

GitHub locations use:

```text
github://OWNER/REPO/optional/prefix?branch=main
```

with `MCMA_GITHUB_TOKEN` supplied outside the library.

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

The master key remains outside storage and is never copied into GitHub.

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
