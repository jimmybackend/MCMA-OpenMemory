# MCMA-OpenMemory

## MCMA 1.0 — Modular Cognitive Memory Archive

**Archivo Modular de Memoria Cognitiva**

> **Intelligence can change. Memory belongs to the person.**  
> **La inteligencia puede cambiar. La memoria pertenece a la persona.**

MCMA is an open, portable, encrypted, file-first memory archive controlled by the user. AI is an optional authorized consumer of memory.

## Active version

This repository has one active public development line:

~~~text
MCMA 1.0
~~~

Historical prototype envelope identifiers are preserved only for compatibility with real encrypted memories.

## Identity model

~~~text
memory:// logical reference
        ↓
stable object_id
        ↓
current storage_hash
        ↓
storage adapter
        ↓
encrypted .mcma bytes
~~~

Temperature, physical path and storage provider do not define permanent memory identity.

## Implemented local core

The PHP implementation currently supports:

~~~text
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
~~~

It works without AI and without a database.

Updates and temperature transitions preserve object_id while creating new encrypted revisions/storage hashes. Mutating local operations use an exclusive write lock. The master key remains outside the portable library and can be backed up in a separate passphrase-encrypted recovery bundle.

The migrator can authenticate/decrypt historical mcma-v1 and mcma-v2 objects when the authorized historical key is supplied. Migration never deletes the historical source.

## Repository layout

~~~text
MCMA-OpenMemory/
├── spec/1.0/
├── packages/core/
├── apps/cli/
├── tests/
├── docs/
├── config/
└── reference/compatibility/
~~~

## Compatibility rule

Existing encrypted prototype memories are not relabeled as MCMA 1.0. Migration means authenticated historical decrypt followed by a real MCMA 1.0 encrypted write with a new stable identity.

## Next block

The next implementation block is the Storage Adapter abstraction:

1. define the adapter interface;
2. move local filesystem access behind it;
3. preserve exact MCMA 1.0 bytes;
4. implement Git-backed storage;
5. then implement S3-compatible storage.

See SPECIFICATION.md, ARCHITECTURE.md, SECURITY.md, ROADMAP.md, spec/1.0/ and apps/cli/README.md.

## License

MIT License.
