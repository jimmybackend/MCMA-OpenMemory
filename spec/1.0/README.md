# MCMA 1.0 Specification

Status: **experimental specification with executable PHP reference implementation**

## Normative / active documents

- PRINCIPLES.md
- IDENTITY.md
- MANIFEST.md
- CONTAINER.md
- CRYPTOGRAPHY.md
- ADDRESSING.md
- INDEX.md
- MIGRATION.md
- RECOVERY.md
- PERMISSIONS.md
- VAULT.md
- CONFORMANCE.md
- schema/
- test-vectors/

## Implemented boundaries

MCMA 1.0 currently has:

- stable identity and encrypted revisions;
- Local/GitHub/S3/WebDAV storage;
- byte-preserving provider migration;
- encrypted deny-by-default permissions;
- dedicated vault container/key context;
- metadata-only vault listing;
- trusted internal secret-use callback.

Historical prototype objects remain compatibility inputs and are never relabeled in place.
