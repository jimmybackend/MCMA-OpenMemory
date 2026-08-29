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

## Floating-point canonicalization

Finite IEEE-754 doubles are canonicalized using ECMAScript-compatible JCS notation. NaN and Infinity are rejected.

## Production hardening

Future work should include independent vault unlock/hardware key release, key rotation, device authorization, audit-event design, incremental semantic indexing and external cryptographic/security review.
