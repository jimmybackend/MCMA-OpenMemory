# MCMA Repository Structure

The repository is being organized incrementally around one active line: **MCMA 1.0**.

Only directories with real content should be created.

~~~text
MCMA-OpenMemory/
│
├── README.md
├── LICENSE
├── SECURITY.md
├── ARCHITECTURE.md
├── SPECIFICATION.md
├── ROADMAP.md
│
├── spec/
│   └── 1.0/
│       ├── README.md
│       ├── PRINCIPLES.md
│       └── CONTAINER.md
│
├── docs/
│   ├── DESIGN-INDEX.md
│   ├── FOUNDATIONAL-VISION.md
│   ├── FIRST-RUN-FLOW.md
│   ├── IDENTITY-PROFILE.md
│   ├── PERMISSIONS-VAULT.md
│   ├── CURRENT-STATE.md
│   ├── MEMORY-TAXONOMY.md
│   ├── ENCRYPTED-INDEX.md
│   ├── KNOWLEDGE-REUSE.md
│   └── STORAGE-ADAPTERS.md
│
├── config/
│   └── mcma.env.example
│
└── reference/
    └── compatibility/
        ├── README.md
        ├── specification-prototype.md
        ├── IMPLEMENTATION-LINEAGE.md
        ├── DEPLOYMENT-EC2-PHP-FPM.md
        └── php/
            ├── current/
            └── legacy/
~~~

## Later, when implementation starts

Create only as required:

~~~text
apps/
packages/
connectors/
examples/
tests/
tools/
~~~

Likely implementation order:

1. freeze the MCMA 1.0 identity/container contract;
2. create the local manual core;
3. add tests and conformance vectors;
4. add storage adapters;
5. add permissions/vault implementation;
6. add optional AI support.

The specification defines interoperability. Compatibility code proves lineage but does not define the new format.
