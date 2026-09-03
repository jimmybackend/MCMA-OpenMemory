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

## Explicit user memory — first implementation complete
- [x] natural-language save intent detection (`Guarda esto`, `Recuerda esto`, English equivalents)
- [x] explicit `POST /mcma/v1/memory` API
- [x] model-assisted correction and structured taxonomy classification
- [x] server-derived canonical `memory://user/<category...>/<slug>-<fingerprint>` route
- [x] AI-proposed, server-validated dynamic thematic `category_path` (1–5 levels)
- [x] natural folder trees such as recipes/cooking and configurations/servers/host
- [x] original source text preserved beside normalized durable content
- [x] cognitive layer / scope / temperature / freshness persisted on the canonical object
- [x] verified KnowledgeService recovery mirror linked to the canonical memory
- [x] incremental semantic indexing without bypassing validation/confidence/freshness filters
- [x] billing metering for organizer and embedding calls
- [x] deterministic no-model/invalid-model fallback that preserves the source
- [x] route/classification confirmation returned to the user
- [x] integration coverage for classification, revision, semantic recovery and fallback
- [x] recipe-family context preserved across save-command parsing
- [x] server/hostname context preserved for configuration memories
- [x] semantic recovery tested across dynamic thematic folders

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
- [x] MiChat-inspired MCMA Chat workspace without importing MiChat database/backend
- [x] encrypted derived per-user conversation index backed by canonical interaction references
- [x] zero-AI conversation list/detail routes
- [x] conversation search, temporal grouping, optional project filters and stable composer
- [x] reload-safe current conversation selection through sessionStorage
- [x] archived conversation reopening without automatic full-history prompt injection
- [x] session status/profile chip, favicon and answer route/token badges
- [x] per-answer copy and browser-native read-aloud controls
- [x] zero-AI memory explorer with decrypted question/answer browsing
- [x] persistent encrypted question/answer interaction archive beyond the 50 context traces
- [x] tab-scoped conversation IDs + explicit Nueva conversación control
- [x] cognitive library virtual views by session/date/topic/project/person/character/entity/source/state
- [x] owner interaction approve/retract workflow
- [x] approval-time cataloging + Knowledge promotion with billing-aware model usage
- [x] library browser isolation from system/Vault objects
- [x] permission-filtered visual `memory://user/` tree with collapsible thematic folders
- [x] zero-AI decrypted canonical memory detail from tree leaves
- [x] tree/list toggle preserving existing knowledge search workflow
- [x] memory text search, temperature/validation filters and pagination
- [x] owner confirm/retract workflow with zero-embedding semantic hash refresh
- [x] Chat / Biblioteca / Contexto MCMA primary tab navigation
- [x] delegated click + keyboard tab activation with ARIA tab semantics
- [x] mutable UI cache revalidation guidance for Nginx/CloudFront
- [x] encrypted per-user context traces for the latest 50 questions
- [x] exact injected-memory transparency for generation requests
- [x] owner-only context trace policy and zero-AI context inspection
- [x] no database dependency
- [x] real EC2 HTTPS + live Google OIDC smoke test (verified 2026-09-01)
- [x] real EC2 persistent Chat + conversation-index deployment smoke test (verified 2026-09-02)
- [x] real EC2 bounded conversational context deployment smoke test (verified 2026-09-02)

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
- [x] guarded single-memory Context Builder for supported/verified revalidation candidates
- [x] persistent encrypted recent-turn/session archive without storing an unbounded plaintext transcript
- [x] private encrypted derived conversation index with incremental maintenance and stale/missing rebuild
- [x] recent conversation candidate window in encrypted index for bounded context selection
- [x] permission-aware canonical turn reads for AI conversational context
- [x] deterministic recent-anchor + lexical relevance selection
- [x] conservative context token budget and max-turn limits
- [x] selected-turn metering and Contexto MCMA transparency
- [x] multi-memory semantic candidate pool reused from a single query embedding
- [x] strict direct-semantic gate preserved while RAG discovery can use a wider similarity floor
- [x] multi-memory ranking by similarity, confidence, freshness, provenance and validation
- [x] actor-aware canonical re-read before any memory enters RAG context
- [x] configurable RAG memory count and conservative token budget
- [x] selected RAG provenance/relations preserved when generated synthesis is remembered
- [x] multi-memory RAG metering and Contexto MCMA transparency
- [x] token-budgeted selection of recent interaction turns for generation context
- [x] token-budgeted multi-memory RAG context assembly with provenance and freshness metadata
- [x] explicit owner validation/promotion/retraction workflow in web memory explorer
- [x] real EC2 + Bedrock end-to-end web smoke test (S3 + Titan + Nova Micro + billing, verified 2026-09-01)
- [ ] real EC2 + Ollama end-to-end smoke test
- [ ] real EC2 + llama.cpp end-to-end smoke test
- [ ] additional provider AI connectors outside core
- [ ] relation graph
- [ ] automatic maturity and temperature policies
