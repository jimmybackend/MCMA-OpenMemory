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
   └── WebDAV
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

## Concurrency boundary

Local uses an exclusive filesystem lock.

Remote adapters use optimistic compare-and-swap when publishing mutable `manifest.mcma` state:

- GitHub: Git blob SHA;
- S3: ETag + `If-Match`;
- WebDAV: ETag + `If-Match`.

Immutable content-addressed objects use create-only semantics.

## Provider migration

Storage migration copies exact encrypted bytes without decrypt/re-encrypt, preserving `object_id`, `storage_hash` and cryptographic identity.

## Next architectural layer

With provider abstraction complete, the next layer is authorization:

```text
actor
  ↓
Permission Engine
  ↓
resource/action decision
  ↓
Memory/Vault operation
```

The vault remains a special cryptographic/security boundary.
