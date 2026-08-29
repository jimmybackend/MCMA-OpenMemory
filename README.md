# MCMA-OpenMemory

## MCMA 1.0 — Modular Cognitive Memory Archive

**Archivo Modular de Memoria Cognitiva**

> **Intelligence can change. Memory belongs to the person.**  
> **La inteligencia puede cambiar. La memoria pertenece a la persona.**

MCMA is an open, portable, encrypted, file-first memory archive controlled by the user. AI is optional.

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

~~~text
GET  /login
GET  /callback
GET  /mcma/v1/health
GET  /mcma/v1/me
POST /mcma/v1/register
POST /mcma/v1/ask
POST /logout
~~~

See `docs/WEB.md` and `config/nginx/mcma-web.conf.example`.


## Metering, credits and SuperAdmin

MCMA can meter AI usage without SQL. Each user has encrypted daily billing ledgers with input, output, cached and embedding tokens, provider/model identity, pricing snapshot, credit charge and cost in integer currency micros.

The web application supports user balances, API keys, external Bearer API calls and a SuperAdmin panel at `/admin.html`.

SuperAdmin can manage plans, credits, service/access state, pricing and already verified payment records without receiving an API for reading private memory or Vault contents.

See `docs/BILLING.md`.
