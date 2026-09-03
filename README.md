# MCMA-OpenMemory

## MCMA 1.0 — Modular Cognitive Memory Archive

**Archivo Modular de Memoria Cognitiva**

> **Intelligence can change. Memory belongs to the person.**  
> **La inteligencia puede cambiar. La memoria pertenece a la persona.**

MCMA is an open, portable, encrypted, file-first memory archive controlled by the user. AI is optional.

## Try MCMA OpenMemory live

A public MCMA-OpenMemory deployment is available at:

**https://mailit.click/mcma/**

Sign in with Google to create or open an isolated encrypted user library and test the web system. The current public Free plan includes the configured AI allowance, while browsing/decrypting already stored memory through **Biblioteca** or reopening archived conversations in **Chat** does not call an AI model.

MCMA-OpenMemory is free/open-source software released under the **MIT License**. The live deployment is a running instance of the same open repository; storage/provider credentials and server secrets are not part of the repository.


## Production status — 2026-09-02

A live MCMA-OpenMemory web deployment is operational at `https://mailit.click/mcma/`.

The `mailit.click` MCMA V1 Nginx routes are versioned in `config/nginx/mcma-mailit-v1.conf`. Production adoption/update is performed with `scripts/deploy-mailit-nginx.sh`, which preserves historical V2 locations, validates `nginx -t`, and rolls back the parent include if validation fails.

Verified end to end on EC2:

- HTTPS web UI behind Nginx/CloudFront;
- Google OpenID Connect login with Authorization Code + PKCE;
- encrypted HttpOnly session cookies and automatic user registration;
- isolated per-user MCMA libraries stored encrypted in Amazon S3;
- per-library KeyStore keys with no shared `MCMA_MASTER_KEY_B64` in multi-user mode;
- Amazon Titan Text Embeddings V2 for semantic memory;
- Amazon Nova Micro through Bedrock Converse for generation;
- Free billing plan active with 20 AI-backed requests/day, 100,000 AI tokens/month and a 100,000-credit monthly top-up;
- encrypted billing plan/pricing catalogs and per-user daily ledgers.

A live billing request was measured successfully: 117 provider tokens, 117 credits charged and 13 USD micros recorded, with the account balance moving from 100,000 to 99,883.

The production web runtime is isolated from the historical V1/V2 compatibility endpoints by a dedicated PHP-FPM service/socket.

The persistent **Chat** workspace and bounded conversational Context Builder are deployed on EC2 at commit `b54302cca5979280ebafffbc4eaabcffd080be49`. Focused context/Ask/provider/web/billing tests passed directly on EC2, `php-fpm-mcma` restarted healthy, and the public UI served the `20260902-5` assets.


## Core model

~~~text
memory:// logical reference
        ↓
stable object_id
        ↓
current storage_hash
        ↓
StorageAdapter
        ↓
encrypted .mcma bytes
~~~

## Storage adapters

Local, GitHub, S3/S3-compatible, WebDAV, Google Cloud Storage, Google Drive, Microsoft Azure Blob Storage and Alibaba Cloud OSS are implemented.

## Permissions + Vault

~~~text
memory://access/permissions
memory://access/vault
~~~

Permissions are deny-by-default. Vault uses a dedicated MCMA container/key context and has no raw-secret CLI getter.

## Knowledge Reuse

MCMA can preserve a question, answer, provenance, confidence, validation state and freshness policy.

~~~text
question
  ↓
normalized exact intent
  ↓
memory://knowledge/q-<sha256>
  ↓
Permission Engine
  ↓
validation + confidence + freshness
  ↓
reuse / revalidate / reject / miss
~~~

Only reuse returns the remembered answer.

Exact intent remains the first route. When it misses, MCMA can optionally use an encrypted semantic index and an EmbeddingProvider to discover a paraphrased candidate. The candidate still has to pass permissions, validation, confidence and freshness before its answer is returned.

Token behavior follows the route:

- exact reusable memory: no embedding or generation provider call, so no AI tokens are consumed;
- semantic lookup: an embedding provider is called for the new query, so embedding tokens may be consumed even when generation is avoided;
- generation fallback: the configured providers are metered and the generated result may be captured as knowledge.

A newly generated answer is intentionally stored as `unverified` with confidence `0.5` by default. With the default reuse threshold of `0.75`, merely checking "remember" does not make that answer immediately reusable as trusted exact memory; it must later satisfy validation/confidence/freshness policy.

## Deterministic agents

Librarian and SecurityAgent work without a model provider.

An optional AI layer can call these boundaries but does not own memory, permissions or secrets.

## Knowledge CLI

~~~text
mcma knowledge-put
mcma knowledge-check
mcma knowledge-show
mcma knowledge-validate
mcma semantic-index
mcma semantic-check
mcma semantic-topk
mcma ask
~~~

## License

MIT License.


## Optional semantic connector

Embedding connectors include Amazon Titan Text Embeddings V2 on Bedrock, local Ollama and local llama.cpp. All are optional; MCMA core remains model/provider independent. Semantic indexes are derived encrypted caches and can be rebuilt without changing knowledge object identity.


## Optional ask orchestration

`mcma ask` can reuse exact or semantic memory before calling any generation model. Generation is provider-neutral through `GenerationProvider`.

Generation connectors include Amazon Bedrock Converse, local Ollama and local llama.cpp. Fresh generated answers are returned to the caller but, when captured, default to unverified knowledge until an explicit validation workflow promotes them for reuse.

A first guarded Context Builder can pass a previously validated `supported/verified` memory into generation when direct reuse is blocked by freshness/current-data requirements. The context is marked as untrusted reference data, preserves validation/freshness metadata, and is included in billing reservation/metering. Unverified or low-confidence memory is not injected.

The same generation request may also receive the bounded conversational context described above. Selected conversation turns are episodic continuity data, remain explicitly marked as untrusted reference data, are metered as generation input, and are recorded in Contexto MCMA so the user can see exactly which historical turns were supplied.

The next layer is now merged in `main` from PR #8: **multi-memory RAG**. When generation is required, MCMA can combine several trusted actor-visible KnowledgeRecords in one request, rank them using similarity + confidence + freshness + provenance + validation, and cap both the number of selected memories and their conservative context budget. Direct exact/semantic reuse remains stricter and unchanged.

Broad memory questions such as **“¿Qué sabes de mailit.click?”** use a separate bounded recall intent. MCMA searches the authenticated user's actor-visible Knowledge and durable interaction archive for that entity/topic, keeps validation/confidence/provenance metadata, excludes retracted/disputed material, and asks the configured generation provider to synthesize the selected memories instead of stopping at one exact/semantic hit. The selected material remains untrusted reference data and is visible in **Contexto MCMA**.


## Local AI

MCMA can run without Bedrock by using Ollama for either or both provider roles:

~~~text
--embedding-provider=ollama
--generation-provider=ollama
~~~

Ollama is expected on loopback by default. The local model process is independent from the memory StorageAdapter, so encrypted MCMA memory may still live in S3, GitHub, WebDAV or the local filesystem.


### llama.cpp local runtime

MCMA also supports llama.cpp through its OpenAI-compatible HTTP server:

~~~text
--embedding-provider=llamacpp
--generation-provider=llamacpp
~~~

Chat and embeddings can run on separate loopback ports, so a small quantized chat model and a dedicated embedding model can be managed independently.


## Multi-cloud storage

MCMA storage is selected independently from the AI runtime.

~~~text
Local
GitHub
Amazon S3 / S3-compatible
Google Cloud Storage
Google Drive
Microsoft Azure Blob Storage
Alibaba Cloud OSS
WebDAV
~~~

GCS, Azure and S3-style providers use their native conditional-write/version primitives. Google Drive is supported as a single-writer backend and does not claim atomic compare-and-swap.


## Multi-user without a database

MCMA can host many users on one storage backend while keeping each user in a separate encrypted library.

~~~text
system/user-registry/
memories/usr_<hmac-sha256>/
memories/usr_<hmac-sha256>/
~~~

The application authenticates the user first, then `MultiUserService` derives a non-PII stable user ID from verified issuer + subject, checks the encrypted registry, verifies the expected `library_id`, and returns only that user's Library.

No raw email/subject is used as a storage path. Multi-user mode uses per-library KeyStore keys and rejects a shared `MCMA_MASTER_KEY_B64`.

See `docs/MULTIUSER.md`.


## Web application

A database-free multi-user web application is available in:

~~~text
apps/web/public
~~~

It includes OIDC Authorization Code + PKCE, encrypted HttpOnly sessions, per-user MCMA library resolution and a same-origin chat UI connected to `AskService`.

The web UI is organized into three primary tabs. Tab switching is handled client-side with delegated click/keyboard events, and mutable UI assets are configured to revalidate so an older cached `app.js` cannot leave newer tab markup non-interactive.


- **Chat** — MiChat-inspired MCMA workspace with a conversation sidebar, search, temporal groups, project filters, persistent message history, **Nueva conversación**, the existing `current`/`remember` controls and per-answer route/token/credit metadata;
- **Biblioteca** — browse personal memory, persistent conversations and Knowledge through virtual shelves by session, date, topic, project, person/character, entity, source and validation state;
- **Contexto MCMA** — inspect the latest encrypted per-request context traces, persistent-memory counts, model-generated memories and internal derived system objects.

Conversation turns are stored once under `memory://interactions/...`; the chat sidebar and library shelves are derived views rather than copies. A private encrypted conversation index stores only conversation metadata plus canonical interaction references, so normal sidebar rendering does not decrypt the whole library. Listing/opening conversations uses zero AI tokens and zero AI credits. An archived interaction starts unverified; the owner can approve it as reusable Knowledge or retract it. Deep catalog classification runs only on approval and is metered when billing is enabled. The separate Context trace window remains capped to the latest 50 requests for operational transparency, while the durable interaction archive is not capped at 50.

Opening a previous conversation restores its visible archived turns and keeps using that `conversation_id` for new turns. Generation fallback can now use a **bounded conversational Context Builder**: MCMA considers only a small recent candidate window, re-reads candidate turns through the `ai` actor permissions, excludes retracted/disputed turns, keeps a couple of recent continuity anchors, adds lexically relevant older turns, and enforces configurable max-turn and conservative token-budget limits. The full transcript is never injected automatically.

Chat requests can carry a client-generated `request_id`. If an upstream proxy/network timeout occurs after PHP/provider work has started, the browser does not resubmit generation: it polls the authenticated request-status route and recovers the canonical encrypted interaction when it appears. The pending id survives a same-tab reload through `sessionStorage`, preventing duplicate generation/credits for the same interrupted request.

Natural-language owner commands can update or retire a canonical `memory://user/...` memory. Mutations are actor-aware and versioned through the normal Library update path: the stable object id is preserved, the revision increments, `previous_storage_hash` links the prior encrypted revision, and semantic/Knowledge mirrors are refreshed or retracted. Ambiguous targets are refused until a unique memory is identified; “delete” creates a versioned tombstone rather than silently erasing history.

~~~text
GET  /login
GET  /callback
GET  /mcma/v1/health
GET  /mcma/v1/me
GET  /mcma/v1/conversations
GET  /mcma/v1/conversations/conv_<32-hex>
GET  /mcma/v1/requests/req_<32-hex>?conversation_id=conv_<32-hex>
POST /mcma/v1/register
POST /mcma/v1/ask
POST /logout
~~~

See `docs/WEB.md` and `config/nginx/mcma-web.conf.example`.


## Metering, credits and SuperAdmin

MCMA can meter AI usage without SQL. Each user has encrypted daily billing ledgers with input, output, cached and embedding tokens, provider/model identity, pricing snapshot, credit charge and cost in integer currency micros.

Per-call provider components are also retained for web requests: embedding and generation calls keep their provider/model id, input/output/embedding token counts and duration. The same component detail is copied into the encrypted interaction/context trace so **Contexto MCMA** can explain where tokens were spent. When billing is disabled, model calls are still metered for transparency; only the monetary/credit charge remains zero.

The web application supports user balances, API keys, external Bearer API calls and a SuperAdmin panel at `/admin.html`.

SuperAdmin can manage plans, credits, service/access state, pricing and already verified payment records without receiving an API for reading private memory or Vault contents.

See `docs/BILLING.md`.


### Stripe Checkout

Optional commercial deployments can enable server-side Stripe Checkout in two modes:

- `payment`: one-time credits and/or paid-plan activation;
- `subscription`: recurring Stripe Price, paid-plan activation and automatic credit renewal on every successful `invoice.paid`.

Subscription renewals are idempotent by Stripe invoice id. `past_due` preserves the current paid plan while Stripe retries collection; canceled, unpaid or paused subscriptions revoke the paid-plan benefit and return the account to Free without deleting memory or already purchased credits.

Personal deployments can keep `MCMA_BILLING_ENABLED=false`; Stripe is not required.

See `docs/BILLING.md`.


## Installation

A first server installer is included:

~~~bash
git clone git@github.com:jimmybackend/MCMA-OpenMemory.git
cd MCMA-OpenMemory
sudo ./install.sh
~~~

It prepares PHP-FPM/nginx, protected runtime directories and `/etc/mcma/mcma.env` without overwriting an existing environment file or generating external-provider credentials.

Diagnostics:

~~~bash
sudo /opt/MCMA-OpenMemory/scripts/mcma-doctor.sh
~~~

See `docs/INSTALL.md`.
