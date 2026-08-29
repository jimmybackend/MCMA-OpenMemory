# MCMA 1.0 Security Model

MCMA-OpenMemory is experimental R&D.

## Core separation

Storage providers receive encrypted bytes, not MCMA master keys.

Provider credentials are deployment configuration and are never embedded in portable MCMA objects.

## Permissions

The Permission Engine is deny-by-default and evaluates actor/action/resource.

Policies are encrypted at memory://access/permissions.

Actor-aware KnowledgeService reads and writes pass through the same Permission Engine.

## Vault

The vault is encrypted with container=vault and key_context=vault.

Ordinary memory reads reject memory://access/vault.

Secret bytes are available only inside the trusted useVaultSecret callback after authorization.

## Knowledge safety

A remembered answer is not returned directly unless KnowledgeRecord assessment permits reuse.

The default gate requires:

- supported or verified validation state;
- confidence at or above the caller threshold;
- freshness within policy;
- no explicit current-data revalidation requirement;
- authorized read access.

disputed/retracted knowledge is rejected.

revalidate/reject results do not include the remembered answer as a direct answer.

Provenance and confidence are metadata, not proof.

## Agent boundaries

Librarian and SecurityAgent are deterministic wrappers. They do not grant extra permissions beyond their configured MCMA roles.

## Production hardening

Future work should include independent vault unlock/hardware key release, key rotation, device authorization, audit-event design, semantic retrieval review and external cryptographic/security review.
