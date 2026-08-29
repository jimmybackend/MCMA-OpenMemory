# MCMA 1.0 Index Bootstrap

Status: **Normative baseline for first implementation**

## Root index

The decrypted manifest identifies the current root index revision with:

```text
object_id
storage_hash
```

The root index envelope MUST use:

```text
protected.container = index
crypto.key_context  = index
```

## Root index payload

A root index payload conforms to `schema/index-payload.schema.json`.

Conceptually:

```json
{
  "index_version": "1.0",
  "index_type": "root",
  "entries": [
    {
      "object_id": "obj_...",
      "storage_hash": "sha256:...",
      "logical_refs": ["memory://identity/profile"],
      "temperature": "hot",
      "cognitive_layer": "10-self",
      "scope": "user"
    }
  ],
  "shards": []
}
```

Because the complete index payload is encrypted, semantic routing metadata is hidden from a storage provider in private mode.

## Shards

Large libraries SHOULD move detailed routing information into encrypted index shards.

The root can then contain shard references:

```json
{
  "name": "projects",
  "object_id": "obj_...",
  "storage_hash": "sha256:...",
  "selector": {
    "namespace": "projects"
  }
}
```

A client SHOULD decrypt only shards relevant to the requested operation.

## Authoritative mapping

At minimum, authorized index state must be able to resolve:

```text
logical reference → object_id
object_id → current storage_hash
```

This mapping is authoritative library routing data and requires durable backup.

## Derived indexes

Lexical indexes, embeddings, topic maps, retrieval scores and similar accelerators may be rebuildable.

They MUST NOT be the only place where stable object identity/current revision mapping exists.

## Temperature views

Temperature is stored as encrypted index/object metadata.

Changing:

```text
hot → warm
```

updates authorized index state and/or encrypted object metadata according to implementation policy.

It MUST NOT require a new object identity solely because temperature changed.

## Revision update

When an object gets a new encrypted revision:

```text
object_id stays the same
storage_hash changes
```

The authoritative index MUST atomically or recoverably advance to the new current `storage_hash`.

Storage adapters without atomic writes must use a recovery-safe update strategy.
