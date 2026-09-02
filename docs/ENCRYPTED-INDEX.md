# Encrypted Memory Index

MCMA 1.0 treats the memory index as sensitive infrastructure.

Encrypted objects protect memory content, but an unprotected catalog can reveal topics, people, projects, activity and relationships.

## Goal

Resolve the smallest required set of objects without scanning a complete multi-year library.

```text
request / logical reference
        ↓
encrypted root catalog
        ↓
minimum required encrypted shard
        ↓
stable object_id + locator
        ↓
fetch selected .mcma object
        ↓
authenticate + decrypt
```

## Root catalog

A deployment needs a deterministic bootstrap reference, conceptually:

```text
memory://system/index/root
```

The root may point to encrypted shards instead of containing every memory entry.

## Sharding

Indexes may be partitioned by:

```text
hash prefix
topic
person
project
date/time range
scope
cognitive layer
temperature
privacy policy
```

Temperature shards represent views, not permanent object identity.

## Authorized index plaintext

Conceptually an index entry may contain:

```json
{
  "object_id": "mem_01...",
  "logical_refs": ["memory://projects/mcma"],
  "locator": "objects/7a/21/7a21....mcma",
  "temperature": "hot",
  "cognitive_layer": "90-projects",
  "scope": "project",
  "topics": ["mcma"],
  "relations": []
}
```

A private storage provider should normally see encrypted catalog bytes and opaque locators, not this semantic plaintext.

## Authoritative vs rebuildable

Implementations should distinguish:

- authoritative encrypted memory objects;
- authoritative identity/routing metadata;
- rebuildable lexical/semantic/topic indexes;
- caches.

## Key separation

MCMA should support independent derivation contexts or key domains for:

```text
memory objects
catalog/index objects
semantic/vector indexes
snapshots/backups
vault/security material
```

## Storage independence

Indexes must not require a GitHub, S3, WebDAV or other provider-specific identity.

The storage adapter maps portable object references/locators to the configured backend.

## Derived conversation index

The web Chat sidebar now uses another encrypted derived index:

~~~text
memory://system/conversation-index
~~~

Its purpose is to avoid decrypting the full durable interaction archive on every sidebar render.

The authoritative transcript remains:

~~~text
memory://interactions/YYYY/MM/DD/conv_<id>/req_<id>
~~~

The conversation index contains only derived conversation metadata and canonical interaction references, including conversation id, display title, first/last timestamps, interaction count, project labels and ordered interaction references. It does not become a second authoritative question/answer database.

Each archived interaction updates the derived index incrementally. On read, MCMA compares the actor-visible canonical interaction reference count plus a SHA-256 reference fingerprint with the index. A missing, malformed or stale index is rebuilt from canonical encrypted interactions.

Conversation-index version `1.1` additionally keeps a bounded `recent_interactions` list of at most 32 `{ref, at}` pointers per conversation. These pointers exist only to avoid scanning/decrypting the entire archive when building conversational context. They contain no duplicated question/answer text. The context builder uses the private index for discovery and must still perform actor-aware canonical reads before a turn can be selected.

List/detail navigation does not invoke an embedding or generation provider. The system index remains private; clients receive safe conversation summaries/turns, not the `memory://system/...` index reference.

