# MCMA — Current State

Date: 2026-08-29

Status: **Portable storage + Permissions/Vault + deterministic Knowledge Reuse core.**

## Storage

Local, GitHub, S3-compatible and WebDAV are implemented.

## Security

~~~text
memory://access/permissions
memory://access/vault
~~~

Permissions are deny-by-default. Vault uses container=vault and key_context=vault.

## Knowledge reuse

~~~text
question
  ↓
normalize
  ↓
sha256
  ↓
memory://knowledge/q-...
  ↓
authorized lookup
  ↓
validation + confidence + freshness
  ↓
reuse | revalidate | reject | miss
~~~

Only reuse returns the remembered answer.

The current implementation is exact-intent, not semantic search.

## Epistemic metadata

Records preserve provenance, confidence, validation state/history, evidence count, freshness, max age, reuse policy and relations.

## Deterministic agents

Librarian wraps remember/validate/recall.

SecurityAgent wraps permission decisions, vault metadata and trusted secret use.

Neither requires an AI provider.

## New CLI

~~~text
mcma knowledge-put
mcma knowledge-check
mcma knowledge-show
mcma knowledge-validate
~~~

## Next

Add optional semantic candidate retrieval while keeping the same permission, validation, confidence and freshness gates.
