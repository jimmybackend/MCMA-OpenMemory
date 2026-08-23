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

The architecture is open source and provider-agnostic. Model vendors and storage vendors are consumers or adapters around the memory layer, not owners of the memory format.

## MCMA Core

The core is responsible for logical memory operations rather than provider APIs. It should understand:

- logical MCMA addresses;
- memory temperature;
- cognitive classification;
- memory scope;
- memory identifiers;
- routing and retrieval policy;
- controlled movement between lifecycle states;
- capture/reuse policy for remembered knowledge;
- validation and confidence metadata.

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
- preserve validation/confidence state;
- determine whether a memory should remain active, cool down or be frozen;
- request retrieval through indexes;
- decide, under policy, whether a previously validated memory can answer without another model call.

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

A mature implementation should support separate derivation contexts or keys for memory objects, catalogs, semantic indexes and backups.

## Index Engine

The Index Engine is the routing layer that answers **where is the memory I need?**

Indexes are accelerators and catalogs around authoritative encrypted memory objects.

Possible index types:

- semantic/vector;
- lexical/full-text;
- topic/category;
- entity/relation graph;
- recency/activity;
- summaries;
- retrieval scores;
- routing hints;
- provenance/validation state;
- memory-ID to logical-URI mappings.

### Encrypted catalog design

A privacy-oriented deployment should not leave a readable map of the user's memory beside encrypted objects. MCMA therefore supports the concept of an encrypted root catalog and encrypted shards.

```text
mcma://system/index/root
        │
        ├── encrypted hot index
        ├── encrypted warm index
        ├── encrypted cold index
        ├── encrypted frozen index
        ├── encrypted cognitive-layer shards
        └── encrypted semantic-index manifests
```

The root should be deterministic to locate, while the entries and shard contents remain encrypted.

Large stores can shard by temperature, cognitive layer, scope, project, topic, time range or another deterministic policy.

The Index Engine should decrypt only the minimum shard necessary to resolve the requested memory.

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
encrypted index lookup
    ↓
logical MCMA object reference
    ↓
storage adapter GET
    ↓
Crypto Engine authentication + decrypt
    ↓
selected plaintext memory
    ↓
agent context or direct remembered answer
```

The goal is to retrieve only relevant objects instead of sending an entire memory repository into the model context.

## Memory-first answer flow

When a question has already been researched and stored as durable knowledge, MCMA can attempt to avoid unnecessary model inference.

```text
New request
    ↓
resolve remembered knowledge
    ↓
check validation_state
    ↓
check confidence
    ↓
check freshness / current-data requirement
    ↓
┌───────────────────────┬────────────────────────┐
│ policy allows reuse   │ revalidation required  │
│                       │                        │
▼                       ▼
return memory        source/model/tool refresh
without LLM call         ↓
                        update memory state
```

This mechanism is intended as a knowledge cache with provenance, not as an assumption that every stored answer is permanently true.

## Portable continuity flow

```text
Model A + Storage A
        │
        ▼
 encrypted MCMA memory
 encrypted MCMA indexes
        │
        ├── export/copy
        ▼
Model B + Storage B
        │
        ▼
configure compatible adapter + authorized keys
        │
        ▼
continue with prior context and knowledge
```

Portable continuity can include user preferences, project state, procedures, prior knowledge and user-defined behavioral/persona context. Different underlying models may still behave differently; MCMA transports the memory/context layer, not the model itself.

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
