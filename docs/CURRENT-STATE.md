# MCMA — Current State

Date: 2026-09-02

Status: **Portable multi-cloud storage + encrypted multi-user libraries + OIDC web/API + persistent Chat + bounded conversational context + scored multi-memory RAG + AI metering/credits/SuperAdmin + Stripe + Permissions/Vault + Knowledge Reuse + semantic Top-K + provider-neutral ask orchestration.**

## Production deployment — 2026-09-02

The live deployment at `https://mailit.click/mcma/` is running commit `b54302cca5979280ebafffbc4eaabcffd080be49`, which includes persistent Chat plus bounded conversational context for generation fallback.

Verified directly on the EC2 production checkout:

- exact deployed code SHA matched `b54302cca5979280ebafffbc4eaabcffd080be49`;
- PHP lint passed for the conversation Context Builder, interaction archive, Ask/billing and web application classes;
- persistent interaction/context integration passed;
- Ask orchestration passed;
- Bedrock Converse, Ollama and llama.cpp context-provider simulations passed;
- web multi-user routing, static base-path, Free quota and billing/admin integrations passed;
- dedicated `php-fpm-mcma` service restarted and reported `Ready to handle connections`;
- public health returned `ok=true`, `multi_user=true`, `billing_enabled=true`, `stripe_enabled=true`;
- the current live instance reports `api_keys_enabled=false`;
- public HTML served the bounded-context UI plus `app.css?v=20260902-5` and `app.js?v=20260902-5`;
- CloudFront fetched the new JavaScript and the origin response uses `no-cache, no-store, must-revalidate`;
- unauthenticated `GET /mcma/v1/conversations` returned HTTP 401 with `authentication_required`.

GitHub CI was also green for the feature PR and again after its squash merge to `main`.

## Semantic retrieval

MCMA performs exact lookup first. Semantic retrieval is attempted only after an exact miss and only when an EmbeddingProvider is configured.

The semantic index is encrypted, derived and provider-specific. Incremental updates regenerate only the affected KnowledgeRecord vector when its storage_hash changes.

Top-K candidate visibility is reconstructed through actor-aware APIs. Permissions, validation, confidence and freshness remain mandatory after similarity ranking.

Multi-memory RAG reuses the same query embedding and ranked pool. Direct semantic reuse keeps its strict threshold; generation-only RAG discovery may use a wider floor. RAG candidates are canonically re-read through `ai`, filtered to supported/verified + minimum confidence, then prioritized by similarity, confidence, freshness, provenance and validation under a configurable memory-count/token budget.

## mcma ask

The ask orchestrator is provider-neutral.

~~~text
question
  ↓
exact knowledge
  ├── reusable → memory answer
  ↓ exact miss
optional semantic Top-K
  ├── reusable → memory answer
  ↓ no reusable memory
optional GenerationProvider
  ↓
fresh generated answer
  ↓
optional Librarian capture
  ↓
KnowledgeRecord + provenance
  ↓
incremental semantic refresh when embeddings are configured
~~~

The core can use exact memory without any AI or embedding provider.

A generation provider is called only when no reusable memory answer exists.

Fresh model output can be returned immediately, but when it is captured it defaults to:

~~~text
validation_state = unverified
confidence = 0.5
~~~

This prevents a model-generated answer from automatically becoming reusable truth.

If an exact KnowledgeRecord already exists but requires revalidation or is otherwise non-reusable, mcma ask does not overwrite it merely because a model generated new text. Its history is preserved for an explicit Librarian/validation workflow.

## Bedrock generation connector

MCMA now has an optional Amazon Bedrock Converse GenerationProvider.

Configuration:

~~~text
MCMA_BEDROCK_CHAT_MODEL
MCMA_BEDROCK_MAX_TOKENS
MCMA_BEDROCK_CHAT_TEMPERATURE
MCMA_BEDROCK_SYSTEM_PROMPT
~~~

Authentication uses the same Bedrock bearer API key or explicit SigV4 environment credentials as the embedding connector.

Credentials remain outside MCMA storage.

## CLI

~~~text
mcma semantic-index
mcma semantic-check
mcma semantic-topk
mcma ask
~~~

For ask, both providers are optional. Bedrock example:

~~~text
--embedding-provider=bedrock-titan-v2
--generation-provider=bedrock-converse
~~~

Local Ollama example:

~~~text
--embedding-provider=ollama
--generation-provider=ollama
~~~

Local llama.cpp example:

~~~text
--embedding-provider=llamacpp
--generation-provider=llamacpp
~~~

Ollama defaults to `http://127.0.0.1:11434`.

llama.cpp defaults to separate local servers:

~~~text
chat       http://127.0.0.1:8080/v1/chat/completions
embeddings http://127.0.0.1:8081/v1/embeddings
~~~

For llama.cpp embeddings, `MCMA_LLAMACPP_EMBED_PREFIX` and `MCMA_LLAMACPP_EMBED_ID` participate in semantic-index identity so incompatible vectors are not silently mixed.

Local mode does not require Bedrock credentials. Memory storage remains independent: Local, GitHub, S3/S3-compatible, WebDAV, Google Cloud Storage, Google Drive, Azure Blob Storage and Alibaba OSS are available StorageAdapters.

## Verification status

CI tests use simulated providers and make no real Bedrock network calls.

The real EC2 S3 + Titan + Nova Micro + billing path is verified. Remaining provider-specific operational smoke tests are Ollama and llama.cpp; both continue to use the same MCMA memory architecture and any implemented StorageAdapter.


## PHP object-oriented architecture

Production PHP logic follows an object-oriented boundary.

The CLI is implemented by `MCMA\Core\Cli\CliApplication`; provider construction is isolated in `ProviderFactory`. The executable `apps/cli/mcma` contains only the minimal bootstrap/delegation needed to start the application.

Core, storage adapters, security, knowledge, semantic retrieval, agents and AI connectors remain class/interface based.

CI includes an OOP conformance check that rejects new global function declarations inside production source directories.


## Multi-cloud storage

Implemented storage locations:

~~~text
/local/path
github://OWNER/REPO/prefix?branch=main
s3://BUCKET/prefix?region=us-east-1
gcs://BUCKET/prefix
gdrive://ROOT_FOLDER_ID
azure://ACCOUNT/CONTAINER/prefix
oss://BUCKET/prefix?region=cn-hangzhou
webdav+https://HOST/library/root
~~~

Google Cloud Storage uses object generations and conditional generation matching for CAS.

Azure Blob Storage uses ETags and conditional HTTP headers for CAS.

Alibaba OSS uses its S3-compatible API through the existing S3 signing/storage engine and virtual-hosted addressing.

Google Drive preserves exact encrypted bytes and exposes Drive's monotonically increasing file version, but is intentionally declared single-writer: MCMA does not claim atomic CAS for Drive.

Provider credentials remain external to MCMA storage.


## Multi-user mode

MCMA now supports multiple users on the same physical storage backend without SQL/database persistence.

~~~text
ROOT
├── system/user-registry/...
└── memories/
    ├── usr_<hmac-sha256>/...
    └── usr_<hmac-sha256>/...
~~~

The system registry is an encrypted MCMA library. Each user is also a distinct MCMA library with a different `library_id`, KeyStore key, permissions, indexes, vault and objects.

The user path is derived from the authenticated identity using HMAC-SHA256 and `MCMA_MULTIUSER_PEPPER`. The original issuer/subject is not stored.

Multi-user mode deliberately rejects `MCMA_MASTER_KEY_B64`; it requires per-library keys through the KeyStore.

The web/application layer must authenticate the request first and pass verified issuer + subject to `MultiUserService::resolve()`. MCMA does not trust a browser-supplied user id.


## Web application

MCMA now includes a same-origin web application under `apps/web/public`.

It provides OIDC Authorization Code + PKCE login, RS256/JWKS ID-token validation, encrypted HttpOnly sessions, multi-user library resolution, `/mcma/v1/me`, `/mcma/v1/ask`, optional registration, logout and a browser chat UI.

The primary **Chat** view now includes a conversation sidebar, search, temporal grouping, project filters, persistent message history and a stable composer. Persistent turns remain canonical under `memory://interactions/...`; a private encrypted derived index stores conversation summaries and canonical references for zero-AI navigation. `GET /mcma/v1/conversations` and `GET /mcma/v1/conversations/conv_<32-hex>` list/open that archive without model calls.

Bounded conversational context is deployed. It never injects the complete transcript automatically. A small recent candidate window is discovered from the encrypted index, canonical turns are re-read through `ai` permissions, retracted/disputed turns are excluded, recent continuity anchors are combined with deterministic lexical relevance, and selection is capped by both max turns and a conservative context budget.

The browser cannot select user id, storage, actor, embedding provider, generation provider, model or credentials.

The live EC2 HTTPS + Google OIDC flow is verified. The 2026-09-02 deployment additionally verifies the persistent Chat assets, conversation-index API protection and focused integration coverage. Manual browser UX verification of creating/reopening multiple conversations remains an operational check, not a missing backend implementation.

The bounded conversational Context Builder is deployed and was re-verified directly on EC2 after pulling `b54302c`, restarting `php-fpm-mcma`, checking health and confirming the `20260902-5` public assets.

Scored multi-memory RAG is merged into `main` at code commit `5a6b22fcde52bf952df94a190ad08b9847549903`. PR #8 CI and the post-merge `main` push workflow both passed. Multi-memory RAG is **not yet claimed as deployed on EC2** until that main commit is pulled into `/var/www/memory`, tested there and `php-fpm-mcma` is restarted.


## Metering and billing

MCMA now has encrypted per-user daily ledgers for AI usage, credits, reservations, payments and adjustments.

Generation and embedding calls are metered separately. Provider-reported token counts are preferred; fallback estimates are explicitly marked as estimates.

Billing uses integer credit units and integer currency micros. Pricing snapshots are persisted with each usage event.

External API keys are stored as HMAC only and resolve to the same user library/billing account as OIDC web sessions.

SuperAdmin can inspect account/billing totals, change plans, adjust credits, suspend service/access, configure provider pricing and record already verified payments. No SuperAdmin route reads private memory content or Vault secrets.

Stripe one-time Checkout, recurring subscriptions, renewal credit fulfillment and verified webhook lifecycle handling are implemented. Live PayPal/Mercado Pago checkout-webhook connectors remain future payment milestones.


## Stripe payments

MCMA supports Stripe-hosted Checkout for versioned one-time and recurring packages.

One-time packages are fulfilled from paid Checkout Session webhooks. Subscription packages are linked during Checkout but credits are granted only by paid invoices, including renewal invoices. Each Stripe invoice id can be credited only once.

Subscription state is persisted in the encrypted billing account. Active subscriptions activate the configured paid plan; `past_due` is recorded while Stripe retries payment; canceled, unpaid, paused or expired subscriptions return the paid plan to Free without deleting the user's memory or existing credit balance.

Webhook fulfillment verifies Stripe-Signature against the raw body, validates user/package binding, package fingerprint, live/test mode, Stripe Price, amount and currency, and rejects stale subscription/invoice events when another subscription is already current.

Stripe credentials remain server-side. Billing-disabled personal installations do not expose Checkout.


## Installer

The repository now includes `install.sh` and `scripts/mcma-doctor.sh`.

The installer can prepare a Linux/EC2 host from the Git checkout, create/preserve the protected runtime environment, configure PHP-FPM, retain the deployment as a Git repository, and run CLI smoke checks. Host Nginx/Apache/CDN configuration is explicitly operator-managed and is never written or reloaded by MCMA.

It does not invent or commit AWS, OIDC, Stripe or other provider credentials. Existing `/etc/mcma/mcma.env` values are preserved.

The next operational milestone is the real EC2 installation test with actual S3/Bedrock/OIDC settings, followed by Stripe test-mode validation.

## Host web-server ownership boundary — 2026-09-03

MCMA does not manage the host's Nginx, Apache, CloudFront or equivalent reverse-proxy configuration. The temporary repository-managed mailit.click Nginx adoption approach was removed before becoming an accepted deployment mechanism.

The repository keeps only `config/nginx/mcma-web.conf.example` as operator guidance. `install.sh` does not install Nginx, write under `/etc/nginx`, restart/reload Nginx, or migrate virtual-host locations. Production operators remain responsible for reviewing and applying their own server configuration.

Application-level `request_id` recovery remains the portable protection against interrupted HTTP requests; operators may independently choose longer upstream/FastCGI timeouts such as 180 seconds.
