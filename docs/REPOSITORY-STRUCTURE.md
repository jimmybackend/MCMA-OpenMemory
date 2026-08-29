# Target Repository Structure

This document describes the proposed long-term monorepo layout. Directories may be created incrementally as implementation begins.

~~~text
MCMA-OpenMemory/
│
├── README.md
├── LICENSE
├── SECURITY.md
├── CONTRIBUTING.md
├── CHANGELOG.md
├── ARCHITECTURE.md
├── SPECIFICATION.md
├── ROADMAP.md
│
├── docs/
│   ├── FOUNDATIONAL-VISION.md
│   ├── PRINCIPLES.md
│   ├── FIRST-RUN-FLOW.md
│   ├── IDENTITY-PROFILE.md
│   ├── PERMISSIONS-VAULT.md
│   ├── MCMA-CONTAINER-FORMAT-DRAFT.md
│   ├── REPOSITORY-STRUCTURE.md
│   ├── CURRENT-STATE.md
│   ├── MEMORY-TAXONOMY.md
│   ├── ENCRYPTED-INDEX.md
│   ├── KNOWLEDGE-REUSE.md
│   ├── STORAGE-ADAPTERS.md
│   ├── DEPLOYMENT-EC2-PHP-FPM.md
│   └── IMPLEMENTATION-LINEAGE.md
│
├── spec/
│   └── v1/
│       ├── manifest.schema.json
│       ├── object.schema.json
│       ├── index.schema.json
│       ├── profile.schema.json
│       ├── permissions.schema.json
│       ├── knowledge.schema.json
│       ├── history.schema.json
│       └── mcma-v1.md
│
├── apps/
│   ├── cli/
│   ├── desktop/
│   ├── mobile/
│   │   ├── android/
│   │   └── ios/
│   └── server/
│
├── packages/
│   ├── core/
│   ├── container/
│   ├── crypto/
│   ├── identity/
│   ├── permissions/
│   ├── vault/
│   ├── indexer/
│   ├── reader/
│   ├── memory/
│   ├── knowledge/
│   └── agents/
│
├── connectors/
│   ├── local/
│   ├── usb/
│   ├── s3/
│   ├── webdav/
│   ├── google-drive/
│   ├── onedrive/
│   └── custom/
│
├── examples/
│   ├── normal-library/
│   ├── private-library/
│   └── demo-user/
│
├── tests/
│   ├── fixtures/
│   ├── unit/
│   ├── integration/
│   ├── portability/
│   └── security/
│
├── tools/
│   ├── mcma-tree/
│   ├── mcma-reader/
│   ├── mcma-verify/
│   ├── mcma-export/
│   └── mcma-migrate/
│
├── config/
│   └── mcma.env.example
│
└── reference/
    └── php/
~~~

## Separation rule

The specification defines interoperability.

Applications, packages and reference implementations consume the specification.

A third party should be able to build a compatible application without using our UI or server.

## Initial implementation order

1. freeze next container/manifest/index design;
2. build a minimal CLI/reference core;
3. implement local filesystem library;
4. implement manual reader;
5. implement vault/permissions;
6. implement S3/WebDAV adapters;
7. add optional AI flow;
8. expand to desktop/mobile clients.

## Proposed first CLI surface

~~~text
mcma init
mcma open
mcma tree
mcma list
mcma info
mcma read
mcma write
mcma verify
mcma lock
mcma unlock
mcma search
mcma export
mcma import
mcma migrate
mcma ask
~~~

mcma ask is intentionally later than the non-AI core.
