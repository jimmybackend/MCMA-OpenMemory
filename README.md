# MCMA-OpenMemory

## MCMA 1.0 — Modular Cognitive Memory Archive

**Archivo Modular de Memoria Cognitiva**

> **Intelligence can change. Memory belongs to the person.**  
> **La inteligencia puede cambiar. La memoria pertenece a la persona.**

MCMA is an open, portable, encrypted, file-first memory archive controlled by the user.

The memory is not owned by an AI provider, database, device, cloud vendor or application. AI is an optional authorized consumer of memory.

## Active version line

This repository now has one active public development line:

```text
MCMA 1.0
```

Earlier prototype envelope identifiers are preserved only for compatibility with real encrypted memories that already exist. They are implementation lineage, not competing public versions of MCMA.

MCMA 1.0 is being defined from the strongest working prototype while changing the architecture where required for stable identity, portability and long-lived libraries.

## Core principles

- file-first;
- database-free core;
- provider-independent;
- storage-independent;
- usable with AI or without AI;
- document-oriented;
- self-describing;
- encrypted by default for private content;
- permission-aware;
- portable across local, removable, mobile, server and cloud storage;
- prepared for libraries that can live for many years.

## Architecture

```text
MCMA Library
    │
    ▼
MCMA Core
    │
    ├── Memory Engine
    ├── Crypto Engine
    ├── Index Engine
    └── Permission / Vault boundary
    │
    ▼
Storage Adapter
    │
    ├── Local
    ├── S3-compatible
    ├── WebDAV
    ├── Git-backed
    └── future providers

Optional AI / Agents
    │
    └── consume only authorized memory
```

## MCMA 1.0 direction

The active architecture adopts:

- stable object identity independent of physical path;
- logical references such as `memory://identity/profile`;
- `profile.mcma` as the living consolidated profile;
- separate historical objects;
- knowledge maturity: `raw → observed → classified → knowledge → confirmed`;
- independent permissions;
- `access/vault.mcma` as a protected secret boundary;
- normal and private library modes;
- hierarchical encrypted indexes;
- hash-based physical object storage;
- virtual HOT / WARM / COLD / FROZEN views;
- JSON as the recommended first structured payload, with extensibility for XML, text, Markdown and binary content.

Temperature describes cognitive relevance. It is not privacy and must not define permanent object identity.

## Existing working baseline

Before MCMA 1.0 consolidation, the prototype successfully produced and read encrypted `.mcma` objects using AES-256-GCM and HKDF-SHA256, server-side key handling, authenticated encryption and external persistence.

Those exact compatibility readers and prototype files are preserved under:

```text
reference/compatibility/
```

They are not renamed in place because real encrypted memories depend on their original cryptographic identity.

## Repository layout today

```text
MCMA-OpenMemory/
├── README.md
├── ARCHITECTURE.md
├── SPECIFICATION.md
├── SECURITY.md
├── ROADMAP.md
├── spec/
│   └── 1.0/
│       ├── README.md
│       ├── PRINCIPLES.md
│       └── CONTAINER.md
├── docs/
├── config/
└── reference/
    └── compatibility/
```

Implementation directories such as `apps/`, `packages/`, `connectors/`, `tests/` and `tools/` will be created only when the corresponding implementation actually begins.

## Compatibility rule

Existing encrypted prototype memories must remain readable.

MCMA 1.0 will **not** pretend that an older encrypted object already has stable-ID semantics. A future migration operation will read the historical object with its original rules and write a new MCMA 1.0 object with the new identity contract.

## Current implementation

The first non-AI MCMA 1.0 local core is now implemented in PHP:

```text
mcma init
mcma open
mcma info
mcma write
mcma read
mcma verify
mcma list
mcma tree
```

It uses the local filesystem adapter, content-addressed `objects/` storage, `manifest.mcma`, stable object IDs and the MCMA 1.0 cryptographic contract. It has no AI or database dependency.

See [apps/cli/README.md](apps/cli/README.md).

See:

- [SPECIFICATION.md](SPECIFICATION.md)
- [ARCHITECTURE.md](ARCHITECTURE.md)
- [SECURITY.md](SECURITY.md)
- [ROADMAP.md](ROADMAP.md)
- [spec/1.0/](spec/1.0/)
- [docs/DESIGN-INDEX.md](docs/DESIGN-INDEX.md)

## License

MIT License.
