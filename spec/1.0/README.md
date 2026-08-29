# MCMA 1.0 Specification

This directory contains the active specification work for **MCMA 1.0 — Modular Cognitive Memory Archive**.

Status: **working design; interoperability format not yet frozen**

MCMA 1.0 is the only active public development line.

Historical prototype envelope formats remain under `reference/compatibility/` solely so existing encrypted memories can continue to be read and later migrated safely.

## Current files

- `PRINCIPLES.md` — invariants that the implementation must preserve.
- `CONTAINER.md` — stable identity, container, hashing and authenticated-metadata design work.

## Freeze order

Before implementing the new writer, MCMA 1.0 must define:

1. library/manifest identity;
2. stable object IDs;
3. canonical hashing;
4. public/encrypted header boundary;
5. KDF context;
6. AES-GCM AAD;
7. logical `memory://` resolution;
8. index bootstrap;
9. compatibility migration;
10. conformance vectors.

The working prototype is evidence and a compatibility source, not a reason to keep path-bound identity in the new format.
