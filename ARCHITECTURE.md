# MCMA 1.0 Architecture

MCMA separates memory semantics, cryptography, indexing and physical storage.

```text
Optional AI / Agents
        ↓
     MCMA Core
   ┌────┼─────┐
 Memory Crypto Index
   └────┼─────┘
        ↓
  StorageAdapter
   ├── Local
   ├── GitHub
   ├── S3 / S3-compatible
   └── WebDAV (future)
```

## Portable identity

```text
memory://identity/profile
        ↓
encrypted index
        ↓
stable object_id
        ↓
storage_hash
        ↓
provider-neutral locator
        ↓
StorageAdapter
```

No storage provider participates in permanent memory identity.

## Storage contract

The implemented adapter contract provides get/put/exists/delete/list, provider versions, compare-and-swap support, write coordination and capability discovery.

Local storage coordinates writers with an exclusive filesystem lock.

GitHub uses the current Git blob SHA of `manifest.mcma` as an optimistic compare-and-swap version.

S3 uses ETag versions with SigV4-signed conditional writes. Immutable objects use create-only semantics; mutable manifest publication uses compare-and-swap.

Content-addressed revisions are immutable. The manifest is written last.

## Provider migration

MCMA copies exact encrypted bytes between adapters. Storage migration does not decrypt or re-encrypt MCMA 1.0 objects and therefore preserves object IDs and storage hashes.

Tested migration includes:

```text
Local → S3-compatible → Local
```

Keys remain an independent security responsibility.

## Compatibility

Historical V1/V2 readers remain under `reference/compatibility/` and migration creates real MCMA 1.0 objects rather than relabeling old ciphertext.
