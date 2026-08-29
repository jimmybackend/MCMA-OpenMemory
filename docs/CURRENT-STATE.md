# MCMA — Current State

Date: 2026-08-29

Status: **Portable multi-cloud storage + encrypted multi-user libraries + Permissions/Vault + Knowledge Reuse + semantic Top-K + ask orchestration + optional Bedrock, Ollama or llama.cpp AI.**

## Semantic retrieval

MCMA performs exact lookup first. Semantic retrieval is attempted only after an exact miss and only when an EmbeddingProvider is configured.

The semantic index is encrypted, derived and provider-specific. Incremental updates regenerate only the affected KnowledgeRecord vector when its storage_hash changes.

Top-K candidate visibility is reconstructed through actor-aware APIs. Permissions, validation, confidence and freshness remain mandatory after similarity ranking.

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

The next operational milestones are real EC2 smoke tests with Bedrock, Ollama and llama.cpp. All use the same MCMA memory architecture and any implemented StorageAdapter.


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
