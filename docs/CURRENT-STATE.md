# MCMA — Current State

Date: 2026-08-29

Status: **MCMA 1.0 architecture consolidation.**

## Active project identity

**MCMA — Modular Cognitive Memory Archive**

> **Intelligence can change. Memory belongs to the person.**

The repository now presents one active public development line: **MCMA 1.0**.

## Working implementation inherited from the prototype

The strongest existing implementation already proves:

- AES-256-GCM encryption/decryption;
- HKDF-SHA256 per-object derivation;
- protected server-side key handling;
- encrypted `.mcma` envelopes;
- HOT / WARM / COLD / FROZEN metadata;
- external persistence;
- manual PHP retrieval/decryption;
- real encrypted memories stored outside the MCMA source repository.

That code is preserved unchanged where possible under `reference/compatibility/`.

## What MCMA 1.0 changes

The historical prototype tied cryptographic identity to logical path + filename.

MCMA 1.0 requires:

- stable object IDs;
- physical-path independence;
- provider independence;
- hash-based physical storage;
- `memory://` logical references;
- virtual temperature views;
- hierarchical indexes;
- living profile documents;
- independent permissions and vault isolation.

Existing ciphertext is not silently relabeled. Migration must be explicit.

## Current repository organization

Active specification work:

```text
spec/1.0/
```

Historical working code/specification:

```text
reference/compatibility/
```

Conceptual and design documentation remains under:

```text
docs/
```

Implementation directories are not created until actual implementation begins.

## Next exact block

Freeze the MCMA 1.0 identity/container contract:

1. manifest/library identity;
2. stable object ID;
3. canonical hash;
4. envelope/header;
5. KDF + AAD;
6. `memory://` resolution;
7. index bootstrap;
8. migration from historical objects;
9. conformance vectors.

After that, implement the manual local CLI core before AI.
