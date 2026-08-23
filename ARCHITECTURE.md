# Architecture

MCMA-OpenMemory separates memory semantics, cryptography, indexing and physical storage so each layer can evolve independently.

## High-level architecture

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
    ┌────────────────┼──────────────────────┐
    │        │        │       │             │
  Local     S3      GCS    Azure         GitHub
    │
  MinIO / NAS / WebDAV / future adapters
```

## MCMA Core

The core is responsible for logical memory operations rather than provider APIs. It should understand:

- logical MCMA addresses;
- memory temperature;
- cognitive classification;
- memory scope;
- memory identifiers;
- routing and retrieval policy;
- controlled movement between lifecycle states.

The core should not require provider-specific concepts such as bucket names, repositories or cloud account IDs.

## Memory Engine

The Memory Engine manages the lifecycle and logical identity of memories.

Responsibilities may include:

- classify a memory;
- assign scope;
- assign an opaque memory ID;
- choose or change temperature;
- consolidate related memories;
- preserve provenance;
- determine whether a memory should remain active, cool down or be frozen;
- request retrieval through indexes.

## Crypto Engine

The Crypto Engine owns cryptographic transformations.

Current reference behavior:

```text
plaintext
   ↓
logical identity
   ↓
HKDF-SHA256 derived object key
   ↓
AES-256-GCM + authenticated metadata
   ↓
.mcma envelope
```

The storage layer never needs the master key or plaintext.

## Index Engine

Indexes are optional accelerators, not the source of truth.

Possible index types:

- semantic/vector;
- lexical/full-text;
- topic/category;
- entity/relation graph;
- recency/activity;
- summaries;
- retrieval scores;
- routing hints.

A future design should allow indexes to be rebuilt from authorized memory where possible.

## Storage Adapter

The adapter translates provider-neutral MCMA operations to a physical backend.

Conceptual interface:

```text
put(object, bytes)
get(object)
exists(object)
delete(object)
list(prefix)
```

Possible adapters:

```text
local
s3
gcs
azure
github
minio
webdav
nas
```

No adapter is part of the required core specification.

## Separation of logical and physical identity

Example logical address:

```text
mcma://warm/50-procedural/project/mcma/deployment/mem_01K...
```

A local adapter could map that to:

```text
/var/lib/mcma/memories/warm/50-procedural/project/mcma/deployment/mem_01K....mcma
```

A Git-backed adapter could map it to a repository path, and an object-store adapter could map it to an object key.

The AI consuming the memory should not need to know which mapping was chosen.

## Retrieval flow

```text
Agent question
    ↓
intent / topic / scope detection
    ↓
index lookup
    ↓
logical MCMA object reference
    ↓
storage adapter GET
    ↓
Crypto Engine authentication + decrypt
    ↓
selected plaintext memory
    ↓
agent context
```

The goal is to retrieve only relevant objects instead of sending an entire memory repository into the model context.

## Lifecycle flow

```text
HOT
 │ active / frequently retrieved
 ▼
WARM
 │ relevant but less active
 ▼
COLD
 │ long-term / index-driven retrieval
 ▼
FROZEN
   preserved outside normal retrieval
```

An implementation may also reactivate a memory:

```text
Frozen → Cold → Warm → Hot
```

Temperature changes should be controlled by policy, agents or explicit user decisions.
