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

Do not commit master keys, bearer tokens, provider secrets, plaintext private memories, production environment files, recovery passphrases or `*.mcma-key` recovery bundles.

## Storage credentials

GitHub uses `MCMA_GITHUB_TOKEN` from process configuration. It is not written to `manifest.mcma`, memory objects or indexes.

Use the narrowest repository permissions compatible with the configured library path.

## Concurrency

Local storage uses an exclusive filesystem lock plus compare-and-swap versions.

GitHub does not use a fake distributed lock. It uses the current Git blob SHA of `manifest.mcma` as an optimistic compare-and-swap version. If another writer publishes first, a stale writer fails rather than silently overwriting the newer manifest.

Immutable content-addressed objects written before a failed manifest CAS may remain as unreferenced encrypted objects; this is preferable to corrupting authoritative library routing state.

## Recovery

The separate `mcma-key-backup-1` bundle uses PBKDF2-HMAC-SHA256 and AES-256-GCM. Recovery material should be stored separately from ordinary library copies.

## Historical migration

Historical V1/V2 objects are authenticated under their original cryptographic rules before plaintext is accepted. Migration is non-destructive.

## Vault and biometrics

Raw vault contents must never become ordinary model context. Raw biometric templates should not be ordinary MCMA memory; secure platform mechanisms/passkeys should unlock keys without exposing biometrics to MCMA or AI.

## Production hardening

Production deployments should consider KMS/HSM, key rotation, least privilege, token rotation, protected branches/repositories, recovery testing, secret-free audit logs, intrusion detection and formal cryptographic review.
