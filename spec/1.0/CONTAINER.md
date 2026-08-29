# MCMA 1.0 Container Format

Status: **Normative baseline for first implementation**

## Envelope

The MCMA 1.0 encrypted envelope is JSON:

```json
{
  "protected": {
    "format": "mcma-1.0",
    "container": "object",
    "library_id": "lib_...",
    "object_id": "obj_...",
    "crypto": {
      "cipher": "AES-256-GCM",
      "kdf": "HKDF-SHA256",
      "key_version": "key-1",
      "key_context": "memory",
      "iv_b64u": "..."
    }
  },
  "ciphertext_b64u": "...",
  "tag_b64u": "...",
  "storage_hash": "sha256:..."
}
```

The envelope MUST conform to `schema/envelope.schema.json`.

## Public vs encrypted metadata

The public `protected` header contains only information needed to:

- identify the MCMA format;
- identify the library/object cryptographic domain;
- select the cryptographic profile/key generation;
- authenticate/decrypt the object.

Semantic metadata belongs inside encrypted payloads/indexes.

Examples of metadata that normally remain encrypted:

- temperature;
- cognitive category;
- scope;
- logical aliases;
- people/project/topic names;
- provenance;
- confidence;
- permissions;
- timestamps when not needed for bootstrap;
- profile content.

This minimizes metadata leakage in private libraries.

## Storage hash

`storage_hash` identifies one exact encrypted envelope revision.

Algorithm:

1. remove the top-level `storage_hash` member;
2. canonicalize the remaining envelope using RFC 8785 JCS;
3. UTF-8 encode the canonical JSON;
4. calculate SHA-256;
5. encode as lowercase hexadecimal;
6. prefix with `sha256:`.

Formally:

```text
hash_input = UTF8(
  JCS(
    envelope_without_storage_hash
  )
)

storage_hash =
  "sha256:" + lowercase_hex(SHA-256(hash_input))
```

The hash is intentionally not part of its own hash input.

## Physical hash mapping

A default content-addressed adapter mapping is:

```text
sha256:abcdef...

objects/ab/cd/abcdef....mcma
```

Rules:

```text
digest = 64 lowercase hexadecimal characters
level1 = digest[0:2]
level2 = digest[2:4]

objects/{level1}/{level2}/{digest}.mcma
```

The bootstrap manifest is the exception: it remains reachable at `manifest.mcma`.

Adapters MAY use another physical representation if they can deterministically resolve the same `storage_hash`.

## Stable object vs encrypted revision

```text
object_id
   │
   └── current storage_hash
          ↓
       exact encrypted revision
```

Updating content does not require changing `object_id`.

## Encrypted payload frame

A normal memory object decrypts to a payload frame conforming to `schema/payload.schema.json`.

Example:

```json
{
  "content_format": "json",
  "metadata": {
    "created_at": "2026-08-29T00:00:00Z",
    "temperature": "hot",
    "cognitive_layer": "40-semantic",
    "scope": "global",
    "maturity": "confirmed",
    "logical_refs": [
      "memory://topics/mcma"
    ]
  },
  "content": {
    "message": "example"
  }
}
```

Supported initial `content_format` values:

```text
json
xml
text
markdown
binary
```

## Verification order

A reader SHOULD:

1. parse the envelope as JSON;
2. validate the MCMA 1.0 envelope structure;
3. recompute and compare `storage_hash`;
4. validate canonical identifiers and crypto profile;
5. derive the object key;
6. reconstruct AAD from `protected`;
7. authenticate/decrypt AES-GCM;
8. parse/validate the decrypted payload according to container role/content format.

A storage-hash mismatch MUST fail verification before plaintext is trusted.

An AES-GCM authentication failure MUST fail the read.
