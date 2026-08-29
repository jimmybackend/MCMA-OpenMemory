# MCMA 1.0 Specification

This directory contains the active interoperability contract for **MCMA 1.0 — Modular Cognitive Memory Archive**.

Status: **experimental specification; identity/container baseline frozen for the first implementation**

Historical prototype formats remain under `reference/compatibility/` only for reading and migration.

## Normative files

- `PRINCIPLES.md` — architectural invariants.
- `IDENTITY.md` — library/object stable identifiers.
- `MANIFEST.md` — deterministic library bootstrap.
- `CONTAINER.md` — envelope and storage-hash rules.
- `CRYPTOGRAPHY.md` — AES-GCM, HKDF and AAD.
- `ADDRESSING.md` — `memory://` logical aliases.
- `INDEX.md` — root/sharded index bootstrap.
- `MIGRATION.md` — historical compatibility migration.
- `CONFORMANCE.md` — required interoperability tests.
- `schema/` — machine-readable JSON Schemas.
- `test-vectors/` — synthetic cryptographic vectors.

## First profile

```text
format          mcma-1.0
cipher          AES-256-GCM
kdf             HKDF-SHA256
canonical JSON  RFC 8785 JCS
library ID      lib_<UUIDv4>
object ID       obj_<UUIDv4>
logical alias   memory://...
physical object hash  SHA-256
```

## Important compatibility rule

Existing historical encrypted memories are not renamed or edited in place.

A migration reader authenticates/decrypts them with their original rules and writes a new MCMA 1.0 object with a stable identity.

## Next implementation milestone

With this contract defined, the next block is the manual local core:

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

No AI or database is required for that milestone.
