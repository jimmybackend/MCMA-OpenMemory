# MCMA 1.0 Security Model

MCMA-OpenMemory is experimental R&D. This document defines security invariants for the active MCMA 1.0 architecture and records which protections are inherited from the working prototype.

## Threat model

Storage may be observable or copied independently of the key-holding runtime.

A storage provider should be able to store encrypted MCMA bytes without receiving plaintext memory or master keys.

## Current cryptographic baseline

The strongest working prototype uses:

```text
AES-256-GCM
HKDF-SHA256
12-byte random IV
16-byte authentication tag
per-object derived keys
```

MCMA 1.0 keeps this as the current algorithmic baseline while redefining stable identity, canonical AAD and authenticated metadata.

The final MCMA 1.0 cryptographic contract is not frozen until conformance vectors exist.

## Secret classes

Examples:

```text
MCMA_MASTER_KEY_B64
MCMA_API_TOKEN
MCMA_BRIDGE_TOKEN
provider-specific credentials
recovery material
```

Secrets MUST NOT be embedded in portable memory envelopes merely for convenience.

## Never commit

Do not commit:

- master keys;
- bearer tokens;
- provider secret keys;
- plaintext private memories;
- populated production environment files;
- decrypted vault contents;
- logs containing authorization headers or secrets.

## Key boundary

Encryption/decryption should occur inside an authorized runtime.

```text
authorized caller
      ↓
MCMA client / crypto boundary
      ↓
load protected key material
      ↓
derive/use object key internally
      ↓
encrypt or decrypt
      ↓
return only authorized result
```

Master and derived keys must not be returned to ordinary clients or AI context.

## Vault boundary

`access/vault.mcma` represents a protected secret boundary.

An AI may request an operation that requires a secret, but the client/security agent must use that secret internally and return only the permitted result.

Raw vault content MUST NOT become normal model context.

## Environment and server deployment

A server may keep protected configuration outside the repository, for example:

```text
/etc/mcma/mcma.env
```

with restrictive OS permissions and explicit secret injection to the required process.

The historical PHP-FPM deployment pattern is preserved under:

```text
reference/compatibility/DEPLOYMENT-EC2-PHP-FPM.md
```

## Storage is not the key store

Storage credentials, MCMA encryption keys and AI-provider credentials are separate responsibilities.

Changing storage provider must not require exposing the MCMA master key to that provider.

## Metadata privacy

Encryption of payloads alone does not hide:

- filenames;
- directory names;
- object sizes;
- timestamps;
- access patterns;
- plaintext catalogs.

Private libraries should use opaque physical identifiers and encrypted semantic indexes where required.

## Stable identity and authenticated metadata

MCMA 1.0 must bind cryptographic identity to stable object identity rather than mutable physical paths.

Security-sensitive metadata must have a defined integrity boundary.

The specification must explicitly define which fields participate in AAD/canonical authentication and which fields are encrypted or rebuildable.

## Biometric boundary

Raw fingerprints, face templates or equivalent biometrics should not be ordinary MCMA memory.

Use platform mechanisms such as Secure Enclave, TPM, Android Keystore, Windows Hello or passkeys to unlock keys without exposing biometric material to MCMA or AI.

## Permissions

Authorization is independent from temperature.

Policies may distinguish owner, AI, librarian, security agent, applications, tools and devices.

## Compatibility

Historical encrypted memories retain their original cryptographic rules and are read only through compatibility code.

Do not modify historical envelope metadata and relabel the result as MCMA 1.0.

## Production hardening

Production deployments should consider:

- managed KMS/HSM or secret manager;
- key rotation;
- recovery testing;
- separate service identities;
- rate limiting;
- network segmentation;
- audit logs without plaintext/secrets;
- token expiration;
- intrusion detection;
- formal cryptographic review;
- signed releases and protected source branches.

## Responsible disclosure

Before encouraging broad production use, the project should publish a dedicated private security contact and disclosure process.
