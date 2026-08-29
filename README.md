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

This first implementation is exact-intent, not semantic search.

## Deterministic agents

Librarian and SecurityAgent work without a model provider.

A future AI layer can call these boundaries but does not own memory, permissions or secrets.

## Knowledge CLI

~~~text
mcma knowledge-put
mcma knowledge-check
mcma knowledge-show
mcma knowledge-validate
~~~

## License

MIT License.
