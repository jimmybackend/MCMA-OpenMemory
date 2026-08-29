# MCMA 1.0 Manifest

Status: **Normative baseline for the first implementation**

## Bootstrap location

Every MCMA 1.0 library MUST expose one deterministic bootstrap object at:

```text
manifest.mcma
```

at the root of the library as seen by the configured storage adapter.

The manifest filename is a bootstrap locator, not the cryptographic identity of the manifest object.

## Manifest envelope

`manifest.mcma` MUST use the normal MCMA 1.0 envelope with:

```text
protected.container   = manifest
crypto.key_context    = manifest
```

Its `library_id` and `object_id` are in the authenticated public protected header so an authorized client can determine which library/key context is required before decryption.

The semantic manifest payload remains encrypted.

## Required decrypted manifest payload

The decrypted payload MUST include:

```json
{
  "library_version": "1.0",
  "created_at": "RFC3339 timestamp",
  "metadata_mode": "normal",
  "root_index": {
    "object_id": "obj_...",
    "storage_hash": "sha256:..."
  },
  "entrypoints": {
    "profile": "memory://identity/profile",
    "permissions": "memory://access/permissions",
    "vault": "memory://access/vault"
  },
  "crypto_policy": {
    "active_key_version": "key-1"
  },
  "extensions": {}
}
```

`metadata_mode` MUST be one of:

```text
normal
private
```

## Root index bootstrap

The manifest MUST provide both:

- stable `object_id` of the root index;
- current `storage_hash` of the root index revision.

This avoids a bootstrap cycle.

Opening a library therefore follows:

```text
manifest.mcma
    ↓
authenticate + decrypt
    ↓
root index object_id + storage_hash
    ↓
locate encrypted root index revision
    ↓
authenticate + decrypt minimum required index data
```

## Manifest updates

The manifest keeps one stable `object_id`.

When its encrypted payload changes, it is re-encrypted with a fresh IV and its `storage_hash` changes.

The physical bootstrap filename remains:

```text
manifest.mcma
```

## Privacy

The public manifest header MUST contain only bootstrap/cryptographic metadata required to process the container.

Human names, profile summaries, provider credentials, recovery secrets and semantic library descriptions MUST remain inside encrypted content or outside the portable library security boundary.

## Recovery

MCMA 1.0 does not require the storage provider to be the key-recovery provider.

Recovery/key escrow details are intentionally separate from this bootstrap contract and will be specified by the security/recovery layer.
