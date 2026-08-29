# Storage Adapters

MCMA 1.0 treats physical storage as a replaceable implementation detail.

## Design rule

The core operates on stable object identities and provider-neutral locators.

A storage adapter should store/retrieve encrypted bytes without understanding plaintext memory.

## Conceptual interface

```text
put(locator, bytes)
get(locator) -> bytes
exists(locator) -> bool
delete(locator)
list(prefix) -> locators
```

Optional capabilities may include:

```text
copy
version
metadata
health
atomic_put
etag
```

## Logical vs physical

Logical reference:

```text
memory://projects/mcma
```

Authorized catalog resolution:

```text
memory://projects/mcma
        ↓
object_id
        ↓
objects/7a/21/7a21....mcma
```

Possible backend mappings:

```text
Local:  /var/lib/mcma/objects/7a/21/7a21....mcma
Git:    objects/7a/21/7a21....mcma
S3:     objects/7a/21/7a21....mcma
WebDAV: objects/7a/21/7a21....mcma
```

The identical relative mapping is convenient but not required.

## Migration

Provider migration should support byte-preserving copy for MCMA 1.0 objects:

```text
source GET
   ↓
exact encrypted bytes
   ↓
destination PUT
```

Because physical location is not permanent object identity, changing provider or locator should not by itself require re-encryption.

Historical prototype objects may have path-bound cryptographic identity and must follow their compatibility/migration rules.

## Credentials

Adapters obtain credentials from deployment-specific secret sources, not from portable object payloads.

Possible sources:

- environment variables;
- workload identity;
- instance roles;
- secret managers;
- protected local configuration;
- short-lived OAuth/access tokens.

## Capability discovery

Adapters may report capabilities such as:

```json
{
  "versioning": true,
  "atomic_put": true,
  "list_prefix": true,
  "server_side_copy": false,
  "etag": true
}
```

## Separation of responsibilities

Storage Adapter:

> Where are encrypted bytes stored?

Crypto Engine:

> How are they protected and authenticated?

Index Engine:

> How do logical references resolve to stable objects?

Memory Engine:

> What does the memory mean and when is it relevant?
