# Encrypted Memory Index

MCMA-OpenMemory treats the memory index as sensitive data.

The encrypted `.mcma` objects protect memory content, but an unprotected catalog can still reveal what a user remembers, what projects exist, which topics are active, and how memories relate to each other. For that reason, privacy-oriented MCMA deployments SHOULD encrypt the memory catalog/index as well as the memory objects themselves.

## Goal

The Index Engine should be able to answer:

```text
What memory should I retrieve?
        ↓
logical memory reference
        ↓
fetch exactly that encrypted object
        ↓
authenticate + decrypt
```

without scanning or decrypting the complete memory store.

## Root catalog

A deployment SHOULD have a deterministic bootstrap location for the encrypted root catalog. The physical location is adapter-specific, but the logical identity remains provider-independent.

Conceptually:

```text
mcma://system/index/root
```

The root catalog does not need to contain every memory entry. It can point to encrypted index shards.

```text
Encrypted root catalog
        │
        ├── hot catalog
        ├── warm catalog
        ├── cold catalog
        ├── frozen catalog
        ├── cognitive-layer catalogs
        └── semantic/vector index manifests
```

This avoids a single ever-growing index file.

## Index shards

A large memory store MAY shard its encrypted indexes by one or more dimensions:

```text
temperature
cognitive layer
scope
project
topic
time range
hash prefix
```

Example conceptual hierarchy:

```text
index/root
index/hot/90-projects
index/hot/40-semantic
index/warm/50-procedural
index/cold/40-semantic
index/frozen/30-episodic
```

The exact physical filenames SHOULD be opaque when privacy is a priority.

## What the encrypted catalog may contain

Authorized index plaintext may contain entries such as:

```json
{
  "memory_id": "mem_01K...",
  "logical_uri": "mcma://hot/40-semantic/project/mcma/storage/mem_01K...",
  "temperature": "hot",
  "cognitive_layer": "40-semantic",
  "scope": "project",
  "topics": ["mcma", "storage"],
  "relations": ["mem_01J..."],
  "created_at": "<timestamp>",
  "updated_at": "<timestamp>",
  "retrieval_hints": ["portable memory", "storage adapter"]
}
```

This example describes index plaintext after authorized decryption. A storage provider should normally see only encrypted catalog bytes.

## Retrieval

A typical retrieval flow is:

```text
User / agent query
        ↓
intent + scope detection
        ↓
decrypt minimum required index shard
        ↓
resolve memory_id / logical URI
        ↓
Storage Adapter GET
        ↓
Crypto Engine authenticates + decrypts one or more selected memories
        ↓
return authorized memory to the caller
```

The design goal is minimum necessary disclosure and minimum necessary decryption.

## Rebuildability

Some indexes can be regenerated from authorized memory objects, for example lexical indexes, semantic embeddings, topic maps and activity scores. Implementations SHOULD distinguish:

- **authoritative memory objects** — the durable source of truth;
- **rebuildable indexes** — derived accelerators;
- **authoritative catalog metadata** — identifiers, versions or routing data that may require durable backup.

## Provider compromise

Encrypting both memory objects and indexes reduces the information exposed if a storage location is copied or disclosed. It does not make indefinite security guarantees: cryptographic strength, implementations and key-management practices can change over time. MCMA therefore treats key rotation, algorithm versioning and migration as part of long-lived memory maintenance.

## Key separation

A deployment SHOULD be able to use separate derivation contexts or keys for:

```text
memory objects
index/catalog objects
semantic/vector indexes
snapshots/backups
```

Compromise of one derived object key should not expose unrelated memory objects.

## Storage independence

The encrypted index MUST NOT depend on GitHub, S3, GCS, Azure or any other provider-specific identity. Storage adapters translate logical index objects to physical locations.

A user should be able to copy the encrypted memory store and its encrypted indexes to another compatible provider and continue resolving the same logical memories after configuring the new adapter and presenting the authorized keys.