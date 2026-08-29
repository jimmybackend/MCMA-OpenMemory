# MCMA 1.0 Security Model

MCMA-OpenMemory is experimental R&D.

## Storage/key separation

Storage providers receive encrypted MCMA bytes, not plaintext master keys.

Current object crypto:

```text
AES-256-GCM
HKDF-SHA256
RFC 8785 canonical protected header/AAD
```

## Never commit

Do not commit master keys, bearer tokens, GitHub tokens, AWS/S3 credentials, plaintext private memories, production environment files, recovery passphrases or `*.mcma-key` recovery bundles.

## Storage credentials

GitHub uses `MCMA_GITHUB_TOKEN`.

S3 uses `MCMA_S3_ACCESS_KEY_ID`, `MCMA_S3_SECRET_ACCESS_KEY` and optional session token, with standard AWS environment variables supported as fallbacks.

Credentials are process/deployment configuration. They are never written into `manifest.mcma`, memory objects or encrypted indexes.

Use least-privilege provider permissions limited to the configured repository/bucket/prefix.

## S3 request authentication

The S3 adapter implements AWS Signature Version 4 with SHA-256 payload hashing.

The repository includes a conformance test against the published AWS S3 signing example.

## Concurrency

Local storage uses an exclusive filesystem lock plus compare-and-swap versions.

GitHub uses Git blob SHA optimistic compare-and-swap.

S3 uses atomic conditional `PutObject` behavior:

```text
If-None-Match: *        for new immutable locators
If-Match: <ETag>        for mutable manifest updates
```

If another writer publishes first, a stale writer fails instead of silently overwriting newer library state.

Encrypted objects written before a failed manifest CAS may remain unreferenced; this is preferable to corrupting authoritative library routing state.

## Recovery

The separate `mcma-key-backup-1` bundle uses PBKDF2-HMAC-SHA256 and AES-256-GCM. Recovery material should be stored separately from ordinary library copies.

## Historical migration

Historical V1/V2 objects are authenticated under their original cryptographic rules before plaintext is accepted. Migration is non-destructive.

## Vault and biometrics

Raw vault contents must never become ordinary model context. Raw biometric templates should not be ordinary MCMA memory; secure platform mechanisms/passkeys should unlock keys without exposing biometrics to MCMA or AI.

## Production hardening

Production deployments should consider KMS/HSM or workload identities, short-lived credentials, key rotation, least privilege, bucket policies, recovery testing, secret-free audit logs, intrusion detection and formal cryptographic review.
