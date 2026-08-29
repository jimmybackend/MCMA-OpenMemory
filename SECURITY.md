# MCMA 1.0 Security Model

MCMA-OpenMemory is experimental R&D.

## Core separation

Storage providers receive encrypted bytes, not MCMA master keys.

Provider credentials are deployment configuration and are never embedded in portable MCMA objects.

## Never commit

Do not commit master keys, provider tokens/passwords, AWS/S3 credentials, WebDAV credentials, plaintext private memories, recovery passphrases, recovery bundles or vault secrets.

## Permissions

The first Permission Engine is deny-by-default and evaluates actor/action/resource.

Policies are encrypted at memory://access/permissions.

Policy updates cannot remove the owner's own manage_permissions and manage_vault recovery authority.

Low-level no-actor Library calls are trusted owner/runtime primitives; actor-facing clients should use actor-aware APIs.

## Vault

The vault is encrypted with:

~~~text
container=vault
key_context=vault
~~~

The ordinary memory read path explicitly rejects memory://access/vault.

Vault metadata can be listed only with vault_metadata permission. Secret bytes are available only inside the trusted useVaultSecret callback after use_secret authorization.

There is no CLI raw-secret retrieval command.

The current vault key is domain-separated via HKDF but still descends from the library master key. Hardware/KMS/independent unlock remains future hardening.

## Remote concurrency

GitHub, S3 and WebDAV use conditional version checks when publishing mutable library state. Stale writers fail instead of silently overwriting newer routing/policy/vault revisions.

## Recovery

The separate encrypted recovery bundle remains outside the portable library.

## Production hardening

Future production work should include hardware/KMS key release, key rotation, device authorization, audit-event design, secure-agent isolation and external cryptographic/security review.
