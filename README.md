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

Local, GitHub, S3/S3-compatible and WebDAV are implemented.

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

The first real embedding connector is Amazon Titan Text Embeddings V2 on Bedrock. It is optional; MCMA core remains model/provider independent. Semantic indexes are derived encrypted caches and can be rebuilt without changing knowledge object identity.


## Optional ask orchestration

`mcma ask` can reuse exact or semantic memory before calling any generation model. Generation is provider-neutral through `GenerationProvider`.

The first optional generation connector uses Amazon Bedrock Converse. Fresh generated answers are returned to the caller but, when captured, default to unverified knowledge until an explicit validation workflow promotes them for reuse.
