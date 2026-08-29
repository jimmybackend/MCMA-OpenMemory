# MCMA — Current State

Date: 2026-08-29

Status: **Portable storage + Permissions/Vault + Knowledge Reuse + semantic Top-K + ask orchestration + optional Bedrock, Ollama or llama.cpp AI.**

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

Local mode does not require Bedrock credentials. Memory storage remains independent: Local, S3, GitHub or WebDAV all remain valid StorageAdapters.

## Verification status

CI tests use simulated providers and make no real Bedrock network calls.

The next operational milestones are real EC2 smoke tests with Bedrock, Ollama and llama.cpp. All use the same MCMA memory architecture and any implemented StorageAdapter.


## PHP object-oriented architecture

Production PHP logic follows an object-oriented boundary.

The CLI is implemented by `MCMA\Core\Cli\CliApplication`; provider construction is isolated in `ProviderFactory`. The executable `apps/cli/mcma` contains only the minimal bootstrap/delegation needed to start the application.

Core, storage adapters, security, knowledge, semantic retrieval, agents and AI connectors remain class/interface based.

CI includes an OOP conformance check that rejects new global function declarations inside production source directories.
