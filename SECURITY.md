# MCMA 1.0 Security Model

MCMA-OpenMemory is experimental R&D.

## Permissions and Vault

The Permission Engine remains deny-by-default.

Vault secret bytes remain isolated behind useVaultSecret and cannot be retrieved through ordinary memory reads.

## Semantic retrieval

Semantic similarity is never an authorization decision.

The retrieval order requires:

- exact route first;
- semantic route only on exact miss;
- actor-visible candidate filtering;
- current knowledge storage_hash matching the indexed vector revision;
- supported/verified validation under default reuse rules;
- confidence threshold;
- freshness/current-data policy.

A stale, disputed, retracted, low-confidence or permission-denied candidate cannot return its remembered answer.

## Semantic index privacy

memory://system/semantic-index/* is denied to ordinary actors by the default policy. owner/librarian can manage it.

The trusted SemanticIndexService may read the derived cache internally, then reconstruct candidate visibility using actor-aware Library calls.

## Bedrock credentials

Bedrock API keys/AWS credentials are deployment secrets and are never persisted in semantic indexes or knowledge objects.

Supported environment inputs include AWS_BEARER_TOKEN_BEDROCK and standard AWS access-key variables.

## Web, API and billing secrets

OIDC client secrets, MCMA session secrets, API-key peppers, Stripe secret keys and Stripe webhook endpoint secrets are deployment secrets and are not persisted in user memory.

External MCMA API keys are stored only as HMAC values. The plaintext token is returned only at creation.

Stripe webhook requests are verified against the raw request body and Stripe-Signature before any payment or subscription state is accepted. Stripe Checkout binds the authenticated MCMA user and package through server-generated metadata. One-time payments are idempotent by Checkout Session id and recurring credits are idempotent by invoice id.

SuperAdmin can manage account state, plans, credits, pricing and payment records but has no administrative route for reading private user memory or Vault secret bytes.

## Floating-point canonicalization

Finite IEEE-754 doubles are canonicalized using ECMAScript-compatible JCS notation. NaN and Infinity are rejected.

## Production hardening

Future hardening should include independent vault unlock/hardware key release, key rotation, pepper rotation, device authorization, Stripe Customer Portal/self-service subscription management, broader payment-provider connectors and external cryptographic/security review.

Encrypted admin audit events, incremental semantic indexing, multi-user isolation and billing ledgers are already implemented.
