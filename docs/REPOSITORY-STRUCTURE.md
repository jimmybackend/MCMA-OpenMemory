# MCMA Repository Structure

The repository has one active public line: **MCMA 1.0**. The normative portable-memory contract lives under `spec/1.0/`; the PHP implementation, web/API, billing and connectors are product layers built on top of that contract.

~~~text
MCMA-OpenMemory/
│
├── README.md
├── LICENSE
├── SECURITY.md
├── ARCHITECTURE.md
├── SPECIFICATION.md
├── ROADMAP.md
│
├── .github/
│   └── workflows/
│       └── php-tests.yml
│
├── apps/
│   ├── cli/
│   │   ├── mcma
│   │   └── README.md
│   └── web/
│       └── public/
│           ├── index.php
│           ├── index.html
│           ├── app.js
│           ├── app.css
│           ├── admin.html
│           └── admin.js
│
├── packages/
│   ├── core/
│   │   ├── bootstrap.php
│   │   └── src/
│   │       ├── Agent/
│   │       ├── Ask/
│   │       ├── Billing/
│   │       ├── Cli/
│   │       ├── Knowledge/
│   │       ├── MultiUser/
│   │       ├── Security/
│   │       ├── Semantic/
│   │       ├── Storage/
│   │       └── Web/
│   └── connectors/
│       ├── aws/
│       └── local/
│
├── config/
│   ├── mcma.env.example
│   └── nginx/
│       └── mcma-web.conf.example
│
├── tests/
│   ├── conformance/
│   └── integration/
│
├── docs/
│   ├── ASK.md
│   ├── BILLING.md
│   ├── CURRENT-STATE.md
│   ├── DESIGN-INDEX.md
│   ├── ENCRYPTED-INDEX.md
│   ├── FIRST-RUN-FLOW.md
│   ├── FOUNDATIONAL-VISION.md
│   ├── IDENTITY-PROFILE.md
│   ├── KNOWLEDGE-REUSE.md
│   ├── LLAMACPP.md
│   ├── LOCAL-AI.md
│   ├── MEMORY-TAXONOMY.md
│   ├── MULTIUSER.md
│   ├── MULTIMODAL-MEMORY.md
│   ├── PERMISSIONS-VAULT.md
│   ├── PHP-OOP.md
│   ├── REPOSITORY-STRUCTURE.md
│   ├── STORAGE-ADAPTERS.md
│   ├── STORAGE-PROVIDERS.md
│   └── WEB.md
│
├── spec/
│   └── 1.0/
│       └── ... normative MCMA 1.0 documents
│
└── reference/
    └── compatibility/
        └── ... historical/prototype compatibility material
~~~

## Current implementation boundaries

### Core

The production PHP boundary is object-oriented. The core includes encrypted libraries, addressing, cryptography, indexes, permissions, Vault, knowledge reuse, semantic retrieval and ask orchestration.

### Storage

Implemented StorageAdapters include Local, GitHub, S3/S3-compatible, Google Cloud Storage, Google Drive, Azure Blob Storage, Alibaba OSS and WebDAV.

### AI

Optional connectors include:

~~~text
Embeddings: Bedrock Titan V2 | Ollama | llama.cpp
Generation: Bedrock Converse | Ollama | llama.cpp
~~~

Memory remains usable without an AI provider.

### Multi-user web/API

The web application uses OIDC Authorization Code + PKCE and encrypted HttpOnly sessions. External clients can use HMAC-backed `mcma_api_*` API keys. Both resolve to an isolated user MCMA Library.

### Future multimodal assets

The current runtime is text-first. The planned multimodal layer is documented in docs/MULTIMODAL-MEMORY.md and will add encrypted canonical binary assets plus derived OCR/transcript/vision/embedding artifacts without changing StorageAdapter independence.

### Metering and billing

The Billing package implements AI usage metering, integer credits, plans, encrypted daily ledgers, pricing snapshots, rate/quota controls, SuperAdmin and payment records.

Stripe supports:

~~~text
payment      -> one-time Checkout
subscription -> recurring Checkout + invoice.paid renewal credits
~~~

Webhook retries are idempotent. Subscription lifecycle state is persisted in the encrypted billing account.

## Specification vs implementation

Files under `spec/1.0/` define portable-format interoperability.

Files under `apps/`, `packages/`, `config/` and `tests/` implement the current PHP product/runtime and do not redefine the MCMA 1.0 encrypted container format.

Historical prototype code under `reference/compatibility/` remains available for lineage and migration, not as the active runtime.


## Host configuration boundary

Host web-server configuration is intentionally not represented as an authoritative production artifact in this repository. `config/nginx/mcma-web.conf.example` is guidance only. MCMA does not own or mutate a deployment operator's Nginx/Apache/CDN configuration.
