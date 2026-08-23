# MCMA-OpenMemory

**Open, provider-agnostic encrypted memory architecture for AI systems and agents.**

> **AI memory should belong to the user, not to the model provider or the storage provider.**

MCMA-OpenMemory explores a portable memory layer in which an AI system can organize, encrypt, retrieve, migrate and eventually manage long-lived memory without tying that memory to one model vendor or one cloud.

The storage provider only needs to store bytes. It does not need to understand the plaintext memory.

## Status

**Public project version:** `0.1.0` — experimental specification and reference implementation.

The working prototype that preceded this repository already produced `mcma-v2` encrypted envelopes. That envelope identifier is intentionally preserved for compatibility. The public project version and the envelope format version are separate concepts.

## Core idea

```text
                 AI / Agents
                     │
                     ▼
                 MCMA Core
                     │
          ┌──────────┼──────────┐
          │          │          │
       Memory      Crypto      Index
       Engine       Engine      Engine
          │          │          │
          └──────────┼──────────┘
                     │
               Storage Adapter
                     │
    ┌────────────────┼─────────────────────┐
    │        │        │       │            │
  Local     S3      GCS    Azure        GitHub
    │
  MinIO / NAS / WebDAV / future adapters
```

The MCMA Core should not need to know whether an object physically lives on a local disk, Git repository, object store, NAS or cloud service.

A logical address can remain stable:

```text
mcma://hot/40-semantic/project/mcma/storage/adapters/<memory-id>
```

while the configured storage driver changes independently.

## Memory has three independent dimensions

### 1. Cognitive meaning — what is this memory?

```text
00-system
10-self
20-working
30-episodic
40-semantic
50-procedural
60-relational
70-preferences
80-goals
90-projects
95-world-model
99-meta
```

### 2. Scope — where does it belong?

Examples:

```text
global
user
project
agent
session
system
```

### 3. Temperature — how active is it?

```text
Hot → Warm → Cold → Frozen
```

Temperature is a lifecycle state, not the meaning of the memory. A semantic or procedural memory can move from Hot to Frozen without changing what it represents.

See [`docs/MEMORY-TAXONOMY.md`](docs/MEMORY-TAXONOMY.md).

## Portable `.mcma` envelope

The current reference format uses:

- AES-256-GCM authenticated encryption
- HKDF-SHA256 key derivation
- per-object derived keys
- authenticated logical identity
- explicit memory temperature
- provider-independent metadata
- Base64-encoded ciphertext for portable JSON envelopes

Example shape, with synthetic values only:

```json
{
  "format": "mcma-v2",
  "cipher": "AES-256-GCM",
  "kdf": "HKDF-SHA256",
  "key_version": "mcma-key-v2",
  "key_id": "example-key-id",
  "logical_path": "memories/hot/40-semantic/project/example",
  "file": "mem_example.mcma",
  "temperature": "hot",
  "created_at": "2026-08-23T00:00:00+00:00",
  "iv_b64": "<base64>",
  "tag_b64": "<base64>",
  "ciphertext_b64": "<base64>"
}
```

No master key, derived key or storage credential belongs inside the envelope.

## Storage independence

A future implementation may resolve the same MCMA logical object through configuration such as:

```text
storage = local
storage = s3
storage = gcs
storage = azure
storage = github
storage = webdav
```

Storage names are **drivers**, not requirements of the specification.

A conforming storage adapter should be replaceable without changing the cryptographic envelope or the cognitive classification model.

## Reference implementation lineage

The initial PHP reference code comes from a working prototype developed in stages:

1. hierarchical key-service experiment;
2. `mcma-v1` encrypted envelopes;
3. server-side `mcma-v2` encryption/decryption so derived keys are no longer returned to clients;
4. temperature metadata: Hot / Warm / Cold / Frozen;
5. authenticated logical path + filename bound into AES-GCM AAD;
6. encrypted memory persisted through an external storage bridge.

The fixed V2 implementation is the basis of the public reference, but the open project starts at **v0.1.0** while keeping `mcma-v2` as the existing envelope identifier.

## Secret isolation

The reference deployment keeps secrets outside the repository and outside application source code. A Linux/PHP-FPM deployment can keep a protected environment file such as:

```text
/etc/mcma/mcma.env
```

owned by root with restrictive permissions and loaded into the service process. Only variable **names** and examples belong in this repository.

See [`docs/DEPLOYMENT-EC2-PHP-FPM.md`](docs/DEPLOYMENT-EC2-PHP-FPM.md) and [`SECURITY.md`](SECURITY.md).

## Design goals

- User ownership of memory
- Provider-independent storage
- Portable encrypted objects
- Explicit lifecycle management
- Selective retrieval instead of loading entire memory stores
- Optional RAG / semantic indexes
- Agent-managed consolidation and temperature changes
- Export/import without vendor lock-in
- Separation of ciphertext, indexes, keys and storage credentials
- Backward-compatible envelope evolution

## Repository map

```text
MCMA-OpenMemory/
├── README.md
├── ARCHITECTURE.md
├── SPECIFICATION.md
├── SECURITY.md
├── ROADMAP.md
├── config/
│   └── mcma.env.example
├── docs/
│   ├── MEMORY-TAXONOMY.md
│   ├── STORAGE-ADAPTERS.md
│   ├── DEPLOYMENT-EC2-PHP-FPM.md
│   └── IMPLEMENTATION-LINEAGE.md
└── reference/
    └── php/
        ├── private/
        │   └── mcma-crypto.php
        ├── public/
        │   └── crypto.php
        └── legacy/
            └── mcma-crypto-v1.php
```

## Security note

MCMA-OpenMemory is experimental R&D. The current reference implementation demonstrates the architecture; it is not yet a formally audited cryptographic product. Use established key-management services, access controls, rotation, monitoring and backups for production deployments.

## License

MIT License.
