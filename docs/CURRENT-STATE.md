# MCMA — Current State

Date: 2026-08-29

Status: **MCMA 1.0 identity/container contract defined; implementation next.**

## Active project

**MCMA — Modular Cognitive Memory Archive**

> **Intelligence can change. Memory belongs to the person.**

## Completed architecture baseline

The repository now defines one active MCMA 1.0 line with:

- file-first/database-free core;
- stable library identity;
- stable object identity;
- deterministic encrypted manifest bootstrap;
- physical-path independence;
- provider independence;
- `memory://` logical aliases;
- SHA-256 storage revision hashes;
- AES-256-GCM + HKDF-SHA256;
- authenticated protected headers;
- hierarchical encrypted index bootstrap;
- virtual HOT/WARM/COLD/FROZEN views;
- explicit historical migration;
- JSON Schemas;
- synthetic conformance vector.

## Working historical implementation

The strongest previous PHP prototype remains unchanged under:

```text
reference/compatibility/
```

It proves encrypted memory creation/decryption and real external persistence, but it is not the MCMA 1.0 writer.

## Active specification

```text
spec/1.0/
```

The identity/container contract is now stable enough to implement against.

## Next exact block

Implement the first local, non-AI MCMA 1.0 core:

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

First storage adapter:

```text
local filesystem
```

The implementation must pass the published conformance vector before additional storage adapters or AI are added.
