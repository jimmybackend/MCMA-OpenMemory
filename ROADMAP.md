# MCMA 1.0 Roadmap

## Foundation — complete
- [x] user-owned, file-first, provider-independent memory
- [x] AI-optional operation
- [x] encrypted indexes and temperature views

## Identity/container — complete
- [x] manifest, stable library_id/object_id
- [x] storage_hash
- [x] AES-256-GCM + HKDF-SHA256
- [x] RFC 8785 JCS including IEEE-754 floating-point numbers
- [x] memory:// addressing
- [x] schemas/conformance

## Manual core — complete
- [x] init/open/info/write/read/verify/list/tree
- [x] update/temperature preserving object_id
- [x] recovery
- [x] historical V1/V2 migration

## Storage — multi-cloud implementation complete
- [x] Local
- [x] GitHub
- [x] S3-compatible
- [x] WebDAV
- [x] Google Cloud Storage
- [x] Google Drive single-writer adapter
- [x] Microsoft Azure Blob Storage
- [x] Alibaba Cloud OSS through S3 compatibility
- [x] CAS/write coordination where provider primitives support it
- [x] byte-preserving provider migration
- [x] provider capability reporting

## Permissions + Vault — first implementation complete
- [x] actor/action/resource engine
- [x] deny-by-default
- [x] encrypted permissions
- [x] owner anti-lockout
- [x] actor-aware memory operations
- [x] vault container/key context
- [x] metadata-only vault listing
- [x] useVaultSecret boundary
- [ ] independent vault unlock / hardware release
- [ ] key rotation
- [ ] device authorization

## Knowledge reuse — first implementation complete
- [x] deterministic exact-intent key
- [x] provenance
- [x] floating-point confidence
- [x] validation states/history
- [x] freshness + max age
- [x] direct memory answer path
- [x] Librarian and SecurityAgent boundaries

## Semantic retrieval — incremental/top-K implementation complete
- [x] provider-neutral EmbeddingProvider
- [x] encrypted derived semantic index
- [x] float embedding vectors
- [x] cosine ranking
- [x] exact-first / semantic-on-miss routing
- [x] permission-filtered candidates
- [x] validation/confidence/freshness gates after ranking
- [x] storage_hash stale-vector detection
- [x] Amazon Titan Text Embeddings V2 connector
- [x] Bedrock bearer and SigV4 authentication
- [x] incremental single-record index upsert
- [x] incremental semantic entry removal hook
- [x] Librarian-triggered semantic refresh when configured
- [x] Top-K actor-visible candidate inspection
- [x] deterministic local reranking
- [x] reusable candidates prioritized over revalidate/reject candidates

## Ask orchestration — first implementation complete
- [x] provider-neutral GenerationProvider
- [x] exact reusable memory before any model call
- [x] semantic reusable memory after exact miss
- [x] optional generation fallback
- [x] generated answer capture through Librarian
- [x] generated knowledge defaults to unverified / 0.5 confidence
- [x] existing non-reusable exact records are preserved, not overwritten
- [x] Amazon Bedrock Converse generation connector
- [x] Bedrock bearer and SigV4 generation authentication
- [x] mcma ask CLI

## Local AI — first implementation complete
- [x] Ollama local embedding provider
- [x] Ollama local generation provider
- [x] llama.cpp local embedding provider
- [x] llama.cpp local generation provider
- [x] Ollama /api/embed and /api/chat integration
- [x] llama.cpp /v1/embeddings and /v1/chat/completions integration
- [x] mcma ask selectable with Ollama or llama.cpp
- [x] semantic commands selectable with Ollama or llama.cpp
- [x] provider-specific embedding index identity
- [x] configurable embedding compatibility fingerprint/prefix for llama.cpp
- [x] no Bedrock credentials required for local mode

## Multi-user — first implementation complete
- [x] encrypted user registry without SQL/database
- [x] provider-neutral PrefixStorageAdapter
- [x] deterministic non-PII user IDs via HMAC-SHA256
- [x] separate library_id and KeyStore key per user
- [x] memories/usr_<digest>/ namespace
- [x] identity marker inside each user library
- [x] register / resolve / info / list / disable
- [x] atomic registry JSON mutation with locking/CAS where supported
- [x] issuer/subject never stored in plaintext
- [x] CLI identity read from environment, not shell arguments
- [x] multi-user mode rejects global MCMA_MASTER_KEY_B64
- [x] production HTTP authentication adapter
- [x] OIDC Authorization Code + PKCE web login
- [x] encrypted HttpOnly web sessions
- [x] multi-user /mcma/v1/me and /mcma/v1/ask
- [x] same-origin web UI
- [x] Nginx/PHP-FPM deployment example
- [ ] pepper rotation/migration
- [ ] bulk multi-user recovery workflow

## Web application — first implementation complete
- [x] OIDC discovery
- [x] RS256/JWKS ID-token validation
- [x] issuer/audience/nonce/expiry validation
- [x] login / callback / logout
- [x] encrypted state and session cookies
- [x] auto-registration or controlled self-registration
- [x] authenticated user library resolution
- [x] AskService connected to web
- [x] server-only AI/storage/provider selection
- [x] origin checking for POST requests
- [x] browser chat UI
- [x] no database dependency
- [x] real EC2 HTTPS + live Google OIDC smoke test (verified 2026-09-01)

## Metering + Billing + Commercial API — first implementation complete
- [x] encrypted daily usage ledger per user
- [x] input/output/cached/embedding token metering
- [x] exact provider counts when available, explicit estimates otherwise
- [x] integer credits and integer currency micros
- [x] lazy credit reservation before first AI call
- [x] settlement and reservation release
- [x] exact-memory responses do not consume AI credits
- [x] pricing snapshots per usage event
- [x] encrypted pricing catalog
- [x] Free / Starter / Pro / Business plan catalog
- [x] request, daily and concurrency controls
- [x] external API keys stored as HMAC only
- [x] web and API requests share the same user billing ledger
- [x] SuperAdmin authorization and encrypted audit ledger
- [x] service/access suspension, plan changes and credit adjustments
- [x] payment ledger with duplicate-payment protection
- [x] recorded Stripe / PayPal / Mercado Pago / bank-transfer / manual payment types
- [x] user billing/API-key dashboard
- [x] SuperAdmin web panel
- [x] Stripe Checkout payment-mode integration
- [x] Stripe Checkout subscription-mode integration
- [x] Stripe webhook signature verification and idempotent fulfillment
- [x] automatic credits + optional plan activation from one-time packages
- [x] recurring credits from each paid subscription invoice
- [x] Stripe subscription lifecycle tracking (active / past_due / canceled / unpaid / paused)
- [x] paid-plan downgrade to Free when the Stripe subscription ends
- [x] Stripe retry-safe renewal handling by invoice id
- [x] live Free-plan billing settlement on EC2 (117 tokens -> 117 credits, encrypted S3 ledger)
- [ ] Stripe Customer Portal / self-service cancellation UI
- [ ] live PayPal / Mercado Pago checkout-webhook connectors

## Server installer — first implementation complete
- [x] root installer for Amazon Linux/RHEL-family and Debian/Ubuntu-family package managers
- [x] protected /etc/mcma/mcma.env creation/preservation
- [x] protected MCMA_KEY_DIR and local storage defaults
- [x] PHP 8.2+ and required-extension validation
- [x] PHP-FPM EnvironmentFile + explicit MCMA env forwarding
- [x] nginx virtual-host generation without catch-all takeover
- [x] CLI smoke test and conditional HTTP health check
- [x] mcma-doctor diagnostics without secret output
- [x] installer shell syntax/help checks in CI
- [ ] real EC2 installation smoke test with S3 + Bedrock + OIDC
- [ ] real Stripe test-mode Checkout/subscription smoke test

## PHP architecture — OOP production boundary complete
- [x] core services/classes/interfaces
- [x] storage adapters as objects
- [x] AI/embedding connectors as objects
- [x] CLI dispatch moved to CliApplication
- [x] provider selection moved to ProviderFactory
- [x] production global-function conformance guard
- [x] minimal executable/bootstrap entrypoints only

## Next
- [ ] Context Builder: inject permission-filtered reusable memory snippets into generation prompts
- [ ] recent-turn/session context policy without storing an unbounded plaintext transcript
- [ ] token-budgeted RAG context assembly with provenance and freshness metadata
- [ ] explicit validation/promotion workflow for newly generated remembered answers
- [x] real EC2 + Bedrock end-to-end web smoke test (S3 + Titan + Nova Micro + billing, verified 2026-09-01)
- [ ] real EC2 + Ollama end-to-end smoke test
- [ ] real EC2 + llama.cpp end-to-end smoke test
- [ ] additional provider AI connectors outside core
- [ ] relation graph
- [ ] automatic maturity and temperature policies
