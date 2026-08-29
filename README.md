# MCMA-OpenMemory

## MCMA — Modular Cognitive Memory Archive

**Open-source, provider-agnostic encrypted memory architecture for AI systems and agents.**

> **AI memory should belong to the user, not to the model provider or the storage provider.**

MCMA-OpenMemory explores a portable memory layer in which an AI system can organize, encrypt, retrieve, migrate and eventually manage long-lived memory without tying that memory to one model vendor or one cloud.

The storage provider only needs to store bytes. It does not need to understand the plaintext memory.

The long-term goal is to prevent memory lock-in by either model capability or storage capacity: a user should be able to keep an encrypted, portable memory route, choose where that memory lives, move it to another compatible storage provider, connect it to another compatible AI system, and continue working with the same remembered context, preferences, project history, procedures and user-defined behavioral/persona context.

MCMA cannot guarantee identical behavior across different models, but it aims to make the **memory and continuity layer portable and user-controlled**.

## Status

**Public project version:** `0.1.0` — experimental specification and reference implementation.

The working prototype that preceded this repository already produced `mcma-v2` encrypted envelopes. That envelope identifier is intentionally preserved for compatibility. The public project version and the envelope format version are separate concepts.

## Why open source

MCMA-OpenMemory is intentionally open source.

The memory layer should not become a private dependency controlled by a single large model vendor, cloud provider or storage company. The format, addressing rules, adapters and reference implementation should be inspectable and implementable by others so users can retain practical ownership of their memory.

The objective is interoperability rather than dependence:

```text
same encrypted memory
        │
        ├── different storage provider
        ├── different compatible AI model
        ├── different compatible agent system
        └── local/private deployment
```

The keys and authorization remain under the user's or deploying system's control.

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

## Encrypted index: find the right memory without exposing the catalog

Encrypting only the `.mcma` objects is not enough for a privacy-oriented memory system. A plaintext catalog could reveal topics, projects, relationships and activity even when every memory body is encrypted.

MCMA therefore treats the index/catalog as sensitive memory infrastructure.

Conceptually:

```text
Question / intent
      ↓
encrypted root catalog
      ↓
minimum required encrypted index shard
      ↓
logical memory reference
      ↓
fetch one or a few .mcma objects
      ↓
authenticate + decrypt
```

A scalable deployment can shard indexes by temperature, cognitive layer, scope, project, topic, time range or another policy instead of building one huge file.

The storage provider should normally see encrypted index bytes and opaque memory identifiers, not a readable map of the user's memory.

See [`docs/ENCRYPTED-INDEX.md`](docs/ENCRYPTED-INDEX.md).

## Remembered knowledge can avoid repeated AI calls

MCMA is also intended to preserve useful knowledge that an AI has already obtained for the user.

If a question was previously researched, answered, accepted and stored, a compatible system can attempt a memory-first path:

```text
new question
    ↓
encrypted index lookup
    ↓
matching remembered knowledge
    ↓
confidence + validation + freshness check
    ↓
return remembered answer
```

When policy permits, that path can avoid another generative-model call entirely.

This can reduce inference cost, latency, repeated web/API retrieval, token use and dependence on a particular AI provider.

Stored knowledge is not assumed to remain true forever. MCMA can preserve epistemic metadata such as confidence, validation state, provenance, last validation time and revalidation policy. That allows an agent to distinguish something merely remembered from something repeatedly verified.

See [`docs/KNOWLEDGE-REUSE.md`](docs/KNOWLEDGE-REUSE.md).

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

The user should be able to export the encrypted memory store and encrypted indexes, choose another compatible destination, configure a new adapter and continue using the same logical memory addresses.

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

- Open-source implementation and specification
- User ownership of memory
- Independence from model vendors and storage vendors
- Provider-independent storage
- Portable encrypted objects and encrypted indexes
- Stable logical addressing across storage migrations
- Explicit Hot / Warm / Cold / Frozen lifecycle management
- Selective retrieval instead of loading entire memory stores
- Optional RAG / semantic indexes
- Agent-managed consolidation and temperature changes
- Knowledge reuse without unnecessary model calls
- Confidence, provenance and validation metadata for remembered knowledge
- Export/import without vendor lock-in
- Portable preferences, project context, procedures and user-defined behavioral/persona context
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
│   ├── ENCRYPTED-INDEX.md
│   ├── KNOWLEDGE-REUSE.md
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


## Current design baseline

The project now explicitly treats MCMA as a **file-first, database-free portable personal memory archive** that can be opened **with AI or without AI**.

The next design baseline adds:

- living identity documents such as profile.mcma;
- knowledge maturity: raw → observed → classified → knowledge → confirmed;
- independent permissions;
- vault.mcma as a protected secret boundary;
- normal and private metadata modes;
- stable object IDs and hash-based storage;
- hierarchical indexes;
- virtual Hot / Warm / Cold / Frozen views;
- manual non-AI tools as a first-class requirement.

See docs/DESIGN-INDEX.md and docs/CURRENT-STATE.md.

> **Intelligence can change. Memory belongs to the person.**

## Security note

MCMA-OpenMemory is experimental R&D. The current reference implementation demonstrates the architecture; it is not yet a formally audited cryptographic product. Use established key-management services, access controls, rotation, monitoring and backups for production deployments.

Long-lived encrypted memory also requires maintenance: no project should claim that one present-day algorithm or key-management arrangement is guaranteed to remain secure indefinitely. MCMA therefore versions cryptographic formats so future migrations remain possible.

## License

MIT License.