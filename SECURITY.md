# MCMA 1.0 Security Model

MCMA-OpenMemory is experimental R&D.

## Storage/key separation

A storage provider should be able to store encrypted MCMA bytes without receiving plaintext memory or master keys.

Current object crypto:

~~~text
AES-256-GCM
HKDF-SHA256
12-byte random IV
16-byte tag
RFC 8785 canonical protected header/AAD
~~~

## Never commit

Do not commit master keys, bearer tokens, provider secrets, plaintext private memories, production environment files, decrypted vault content, recovery passphrases or *.mcma-key recovery bundles.

## Local key boundary

The default local key store is outside the portable library:

~~~text
~/.config/mcma/keys/<library_id>.key
~~~

## Recovery

The first recovery profile uses a separate mcma-key-backup-1 bundle protected with:

~~~text
PBKDF2-HMAC-SHA256
600000 iterations
16-byte random salt
AES-256-GCM
12-byte random IV
16-byte tag
~~~

The passphrase is supplied through an environment variable, not a command-line value. Recovery files and passphrases should not be stored together.

## Concurrent writers

Mutating local operations use .mcma.lock with flock(). After lock acquisition the current manifest is reloaded before index modification.

This is local filesystem coordination, not a distributed lock. Remote adapters must define their own concurrency semantics.

## Stable revisions

Content and temperature changes preserve object_id and create a new encrypted storage_hash. Previous content-addressed revisions remain until an explicit future cleanup policy removes them.

## Historical migration

Historical V1/V2 objects are authenticated with their original crypto rules before plaintext is accepted. Historical keys are supplied by protected file/environment variable and never as CLI values. Migration is non-destructive.

## Vault and biometrics

Raw vault contents must never become normal model context. Raw biometric templates should not be ordinary MCMA memory; platform secure hardware/passkeys should unlock keys without exposing biometrics to MCMA or AI.

## Production hardening

Production deployments should consider KMS/HSM, key rotation, recovery testing, least privilege, network segmentation, secret-free audit logs, intrusion detection, formal cryptographic review, signed releases and protected branches.
