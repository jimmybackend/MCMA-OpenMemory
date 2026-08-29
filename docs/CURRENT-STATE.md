# MCMA — Current State

Date: 2026-08-29

Status: **First executable MCMA 1.0 local core implemented.**

## Active project

**MCMA — Modular Cognitive Memory Archive**

> **Intelligence can change. Memory belongs to the person.**

## Specification baseline

The repository defines:

- stable library and object IDs;
- `manifest.mcma`;
- MCMA 1.0 envelope;
- AES-256-GCM + HKDF-SHA256;
- RFC 8785 canonical JSON profile;
- authenticated protected headers;
- SHA-256 storage hashes;
- `memory://` aliases;
- encrypted root-index bootstrap;
- explicit historical migration;
- schemas and conformance vector.

## Implemented local core

The first PHP core now exists under:

```text
packages/core/
apps/cli/
```

Implemented commands:

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

The implementation:

- works without AI;
- works without a database;
- creates stable library/object UUIDv4 identities;
- stores the master key outside the portable library;
- creates encrypted `manifest.mcma`;
- stores encrypted revisions under content-addressed `objects/<hash>`;
- maintains an encrypted root index;
- resolves `memory://` references;
- verifies storage hashes and AES-GCM authentication.

## Tests

```text
tests/conformance/run.php
tests/integration/local-core.php
```

The conformance test reproduces the published synthetic vector.

The integration test executes:

```text
init → verify → write → reopen → read → verify
```

without AI or a database.

## Compatibility

Historical working PHP remains under:

```text
reference/compatibility/
```

Existing historical encrypted memories are not modified.

## Current limitation

The first PHP JCS writer rejects floating-point JSON values rather than risk emitting non-RFC-8785 canonical number serialization. Integer JSON, text, Markdown, XML and binary payloads are supported.

## Next block

Harden the local core before adding remote providers:

1. library write locking / concurrent update protection;
2. explicit update/revision command preserving `object_id`;
3. temperature transition command without changing object identity;
4. key export/import/recovery workflow;
5. migrate one real historical prototype memory into MCMA 1.0;
6. then define the reusable Storage Adapter interface and Git/S3 adapters.
