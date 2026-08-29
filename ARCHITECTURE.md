# MCMA 1.0 Architecture

~~~text
Optional AI / Agents
        ↓
 Permission Engine
        ↓
     MCMA Core
   ┌────┼─────┐
 Memory Crypto Index
   └────┼─────┘
        ↓
  StorageAdapter
   ├── Local
   ├── GitHub
   ├── S3-compatible
   └── WebDAV
~~~

## Portable identity

~~~text
memory://...
   ↓
encrypted index
   ↓
object_id
   ↓
storage_hash
   ↓
provider-neutral locator
   ↓
StorageAdapter
~~~

## Authorization

Actor-facing operations evaluate:

~~~text
actor + action + resource
~~~

The encrypted policy object is memory://access/permissions.

Mutating actor checks occur within the write coordination window after the library reloads current mutable state.

## Vault boundary

~~~text
AI/tool request
      ↓
Permission Engine
      ↓
Security Agent / trusted client
      ↓
vault container (key_context=vault)
      ↓
useVaultSecret callback
      ↓
external action
      ↓
allowed result
~~~

Ordinary memory reads cannot return the vault payload.

## Storage

Local uses an exclusive filesystem lock. GitHub, S3 and WebDAV use provider versions/ETags with optimistic compare-and-swap for mutable manifest publication.

Provider migration copies exact encrypted bytes.

## Compatibility

Historical V1/V2 objects remain under their original cryptographic rules until explicit authenticated migration.
