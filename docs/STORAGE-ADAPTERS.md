# Storage Adapters

MCMA-OpenMemory treats physical storage as a replaceable implementation detail.

The core should operate on logical MCMA objects while adapters translate those operations to a concrete backend.

## Design rule

A storage adapter must not need to understand plaintext memory content.

It should receive an encrypted `.mcma` envelope or other provider-neutral bytes plus a logical object reference.

## Conceptual interface

```text
put(objectRef, bytes)
get(objectRef) -> bytes
exists(objectRef) -> bool
delete(objectRef)
list(prefix) -> objectRefs
```

Optional capabilities may include:

```text
copy(source, destination)
move(source, destination)
version(objectRef)
metadata(objectRef)
health()
```

## Adapter configuration

Provider configuration belongs outside the portable envelope.

Examples:

```text
Local:
  root = /var/lib/mcma

S3-compatible:
  endpoint / region / bucket / credential source

Git-backed:
  owner / repository / branch / credential source

WebDAV:
  base URL / credential source
```

The core specification must not require any one of these fields.

## Logical to physical mapping

Logical address:

```text
mcma://cold/40-semantic/project/mcma/storage/mem_01K....
```

Possible filesystem mapping:

```text
/var/lib/mcma/memories/cold/40-semantic/project/mcma/storage/mem_01K....mcma
```

Possible Git-backed mapping:

```text
memories/cold/40-semantic/project/mcma/storage/mem_01K....mcma
```

Possible object-store key:

```text
memories/cold/40-semantic/project/mcma/storage/mem_01K....mcma
```

The identical relative mapping is convenient but not mandatory. What matters is that the adapter can deterministically resolve the logical reference.

## Migration

Provider migration should support a byte-preserving mode:

```text
source adapter GET
        ↓
exact .mcma bytes
        ↓
destination adapter PUT
```

If the logical path and filename stay unchanged, the current `mcma-v2` reference envelope can be moved without decrypting it.

If migration changes the logical path or filename, the current identity-bound cryptography requires controlled decrypt/re-encrypt under the new identity.

## Provider examples

Planned adapters include:

- Local filesystem
- Git-backed storage
- Amazon S3 or S3-compatible APIs
- MinIO
- Google Cloud Storage
- Azure Blob Storage
- WebDAV
- NAS-mounted filesystems

These are examples, not normative dependencies.

## Credentials

Each adapter should obtain credentials from a deployment-specific secret source rather than embedding them in code or `.mcma` objects.

Credential sources may include:

- environment variables;
- workload identity;
- instance roles;
- secret managers;
- local protected configuration;
- short-lived OAuth/access tokens.

## Adapter capability discovery

Future versions may allow adapters to report capabilities such as:

```json
{
  "versioning": true,
  "atomic_put": true,
  "list_prefix": true,
  "server_side_copy": false,
  "etag": true
}
```

The MCMA Core can then choose safe migration and update strategies without assuming all backends behave like filesystems.

## Important distinction

A provider adapter answers:

> Where are the encrypted bytes stored?

The Crypto Engine answers:

> How are the bytes protected and authenticated?

The Memory Engine answers:

> What does this memory represent and when should it be retrieved?

Keeping those questions separate is the central architectural goal.
