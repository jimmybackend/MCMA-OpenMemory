# MCMA 1.0 Cryptographic Contract

Status: **Normative baseline for first implementation**

## Cryptographic suite

The first MCMA 1.0 profile uses:

```text
Cipher: AES-256-GCM
KDF: HKDF-SHA256
IV: 12 random bytes
Tag: 16 bytes
Master key: 32 random bytes
Canonical JSON: RFC 8785 JCS
```

HKDF follows RFC 5869.

## Protected header

Every encrypted container has a `protected` JSON object.

For the first profile it contains:

```json
{
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
}
```

Allowed `container` values:

```text
manifest
object
index
vault
```

Required `key_context` mapping:

```text
manifest → manifest
object   → memory
index    → index
vault    → vault
```

## Base64 encoding

Binary envelope fields use RFC 4648 base64url without padding.

The 12-byte IV therefore encodes to 16 base64url characters.

The 16-byte GCM tag encodes to 22 base64url characters without `==` padding.

## Key derivation

Let:

```text
IKM = 32-byte library master key
library_id = canonical library identifier
object_id = canonical object identifier
key_context = protected crypto.key_context
key_version = protected crypto.key_version
```

Derive:

```text
salt = SHA-256( UTF8("MCMA1|" + library_id) )

info = UTF8(
  "MCMA1|" +
  key_context + "|" +
  object_id + "|" +
  key_version
)

PRK = HKDF-Extract-SHA256(salt, IKM)

object_key = HKDF-Expand-SHA256(PRK, info, 32)
```

`key_version` is a non-secret identifier that selects the authorized master-key generation from the client's key store.

The actual master key MUST NOT appear in the envelope.

## AAD

Before encryption:

1. generate a fresh 12-byte IV;
2. place its base64url value in `protected.crypto.iv_b64u`;
3. canonicalize the complete `protected` object with RFC 8785 JCS;
4. encode that canonical JSON as UTF-8.

The resulting bytes are the AES-GCM AAD:

```text
AAD = UTF8( JCS(protected) )
```

This authenticates:

- format;
- container role;
- library identity;
- object identity;
- cipher/KDF declaration;
- key version;
- key context;
- IV representation.

Any modification to protected metadata MUST cause authentication failure.

## Encryption

```text
AES-256-GCM(
  key = object_key,
  iv = decoded protected.crypto.iv_b64u,
  plaintext = payload bytes,
  aad = AAD,
  tag_length = 16
)
```

A fresh cryptographically secure IV MUST be generated for every encryption operation, including a new revision of the same `object_id`.

## Payload JSON

When the encrypted plaintext is a structured MCMA JSON payload, writers MUST serialize it using RFC 8785 JCS before encryption.

This gives deterministic cross-language test vectors and avoids ambiguous JSON representations.

Binary payload content is represented inside the encrypted payload frame as base64url without padding.

## Key rotation

Rotating the active master key:

- changes `key_version`;
- re-encrypts the object with a fresh IV;
- preserves `object_id`;
- creates a new `storage_hash`.

Old key versions may remain available only as required for authorized historical reads/migration.

## Security note

This specification defines interoperability, not a claim of formal cryptographic audit.

Production use should still employ protected key stores, KMS/HSM where appropriate, least privilege, recovery controls and independent review.
