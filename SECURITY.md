# Security Model

MCMA-OpenMemory is experimental R&D. This document describes the intended security boundary of the current reference implementation and the rules that should remain true as storage adapters are added.

## Threat model

MCMA assumes the storage backend may be observable independently of the key-holding runtime. Therefore storage should be able to hold encrypted memory objects without receiving the plaintext master key.

The current `.mcma` envelope intentionally contains metadata required for identification and authenticated decryption, but not the master key or derived object key.

## Current cryptographic reference

```text
AES-256-GCM
HKDF-SHA256
12-byte random IV
16-byte authentication tag
per-object derived key
logical identity bound into AAD
```

The reference master key is 32 random bytes represented as Base64 in process configuration.

## Secret classes

Examples of secrets used by the current PHP deployment:

```text
MCMA_MASTER_KEY_B64
MCMA_API_TOKEN
MCMA_BRIDGE_TOKEN
```

Storage adapters may require provider-specific secrets such as API tokens. Those credentials are outside the core format and MUST NOT be embedded in `.mcma` files.

Only variable names and placeholders belong in this public repository.

## Never commit

Do not commit:

- master keys;
- bearer tokens;
- storage provider access tokens;
- cloud secret keys;
- decrypted memory content intended to remain private;
- production `.env` files;
- debug output that includes authorization headers.

## Server-side key boundary

The V2 prototype moved encryption/decryption into the server process so clients no longer need to receive derived encryption keys.

Recommended flow:

```text
client / trusted caller
      │ authenticated request
      ▼
MCMA Crypto Service
      │ loads protected process secrets
      ▼
derive object key internally
      │
      ├── encrypt → return encrypted envelope
      └── decrypt → return plaintext only to authorized caller
```

The master key and derived object key must not be returned by the HTTP API.

## Environment file pattern

A Linux deployment may keep secrets in:

```text
/etc/mcma/mcma.env
```

Recommended permissions:

```text
owner: root
mode: 0600
```

The containing directory should also be restricted, for example `0700`.

The environment file should be loaded by the service manager or another secret-delivery mechanism rather than copied into the web root.

## PHP-FPM

When PHP-FPM uses a cleared environment, explicitly pass only the environment variables required by the MCMA worker pool. Do not expose the entire host environment to PHP merely for convenience.

A deployment may use a systemd `EnvironmentFile=` together with explicit PHP-FPM `env[...]` entries for the required names.

See `docs/DEPLOYMENT-EC2-PHP-FPM.md`.

## Storage is not the key store

The storage adapter and the cryptographic key store are separate responsibilities.

A Git, S3, GCS, Azure, WebDAV, NAS or filesystem backend should be replaceable without moving the master key into that backend.

## Metadata privacy

Encryption of the payload does not automatically hide:

- directory names;
- filenames;
- object sizes;
- timestamps;
- access patterns;
- unencrypted indexes.

For stronger privacy, prefer opaque memory IDs and encrypted or privacy-preserving indexes.

Example:

```text
mem_01K34WQ8VGC7TJ6P6KJQ.mcma
```

instead of a filename that reveals a person's name or memory subject.

## Identity-bound encryption

The current reference derives the per-object key from the logical path and filename and also authenticates that identity using GCM AAD.

This prevents an object from being silently moved or renamed and then treated as the same cryptographic identity.

A legitimate rename or logical move therefore requires a controlled decrypt/re-encrypt migration.

## Token permissions

Provider credentials should follow the deployment owner's security policy. For distributable applications and third-party integrations, prefer narrowly scoped credentials. Broad infrastructure credentials should never be embedded into client software or public code.

## Production hardening

A production deployment should consider:

- managed KMS/HSM or secret manager;
- key rotation and key-version migration;
- separate service identities;
- rate limiting;
- network segmentation;
- audit logs that never contain secrets or plaintext;
- backup and recovery testing;
- token expiration monitoring;
- intrusion detection;
- formal cryptographic review;
- authenticated authorization policies beyond possession of one bearer token.

## Responsible disclosure

If this project evolves into a widely used implementation, a dedicated private security contact and disclosure process should be added before encouraging production adoption.
