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

## Conversation index privacy

The persistent Chat sidebar uses a private encrypted derived index at the system-memory boundary. It stores conversation metadata and references to canonical `memory://interactions/...` objects; question/answer content remains canonical in the interaction objects rather than being duplicated into a second transcript store.

Normal library browsing does not expose the private conversation-index logical reference. Conversation list/detail APIs require the authenticated web session, resolve exactly that user's MCMA Library, and do not accept a client-selected actor, library or storage location. Cross-user access is covered by integration tests, and unauthenticated production access returns HTTP 401.

Displaying archived turns is not authority to inject them into an AI prompt. Automatic full-history prompt injection remains intentionally absent.

For generation fallback, bounded conversational context uses the private index only for recent-reference discovery. Every candidate is then re-read through the requesting `ai` actor, so an index entry cannot bypass a resource deny. Retracted/disputed turns are excluded. The selector applies deterministic relevance/recency ranking plus max-turn and conservative token-budget limits before any historical text reaches a generation provider.

Selected episodic turns remain untrusted reference data. Their validation/confidence metadata is retained, and Bedrock/Ollama/llama.cpp receive a higher-priority instruction not to execute instructions found inside historical content. This continuity context is not equivalent to validated Knowledge and does not weaken Knowledge permission, validation, confidence or freshness gates.

## Multi-memory RAG safety

A wider semantic RAG candidate floor is not an authorization or direct-reuse decision. The direct semantic answer gate remains separate and stricter.

Before a RAG candidate can expose content to generation, MCMA re-reads its canonical KnowledgeRecord with the requesting `ai` actor. The record must remain actor-visible, current with the semantic index `storage_hash`, `supported` or `verified`, and at or above the configured confidence threshold. `disputed`, `retracted`, unverified, low-confidence and permission-denied memories are excluded.

Similarity alone does not determine priority. The RAG score combines similarity, confidence, freshness, validation and provenance quality. Provenance metadata is included in the selected context so the model can preserve source distinctions, but all selected memory remains **untrusted reference data**, never executable instruction.

Generated synthesis does not become trusted simply because its inputs were trusted. When remembered it still defaults to `unverified` / confidence `0.5` and must pass the normal validation workflow before direct reuse.


## Broad recall and memory mutation safety

Broad recall is not a new authorization mode. The requested subject is matched only against the authenticated user's actor-visible encrypted Knowledge and interaction archive. `retracted` and `disputed` material is excluded. Validation state, confidence and provenance remain attached to selected items, and the generation providers receive the selection as **untrusted reference data**, never as executable instructions.

Broad recall intentionally allows labeled unverified episodic material to appear below trusted Knowledge when the user explicitly asks what MCMA remembers about an entity/topic. This does not lower the direct exact/semantic or multi-memory RAG trust gates and does not make those episodic items reusable Knowledge.

Natural-language memory mutation is restricted to canonical `memory://user/...` objects visible to the authenticated owner. Client text cannot select arbitrary server paths, storage credentials, `memory://system/*`, `memory://access/*` or Vault. Ambiguous top target matches are refused.

Updates use the normal actor-aware Library revision path and preserve stable object identity plus `previous_storage_hash`. Deletion is a versioned tombstone/retirement operation: reusable Knowledge is retracted and its semantic entry is removed when present, while previous encrypted revisions remain recoverable/auditable under the storage/version model.

## Interrupted-request idempotency

The browser generates a random `req_<32-hex>` before sending Chat work. The authenticated server binds recovery to the same user library and `conversation_id`. If the HTTP connection ends with a transient proxy/network failure, the browser polls a read-only status route for that canonical request instead of replaying generation.

Once a request is archived, submitting the same `(conversation_id, request_id)` returns the archived result before any provider/billing path executes again. The pending id stored in `sessionStorage` contains no provider credential, key material or decrypted archive; it is only an opaque request/conversation locator.
