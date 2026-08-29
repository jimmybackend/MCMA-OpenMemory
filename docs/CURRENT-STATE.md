# MCMA — Current State

Date: 2026-08-29

Status: **Portable storage + Permissions/Vault + Knowledge Reuse + incremental semantic Top-K/reranking.**

## JCS numbers

The PHP canonical writer supports finite IEEE-754 floating-point JSON values using ECMAScript/JCS number notation. NaN and Infinity remain rejected.

Knowledge confidence is stored as a real JSON number, and embedding vectors are persisted as floats.

## Incremental semantic index

The semantic index remains encrypted, derived and provider-specific.

A single KnowledgeRecord can now be refreshed with `SemanticIndexService::indexOne()`. Only that record's embedding is regenerated when its current `storage_hash` changes. If the indexed `object_id` and `storage_hash` already match, no embedding call is made.

The Librarian can be configured with a `SemanticIndexService` and `EmbeddingProvider`; then `remember()` and `validate()` refresh only the affected semantic entry.

`SemanticIndexService::remove()` removes one derived semantic entry without changing the knowledge object's identity. This is the hook for future ordinary-memory deletion workflows.

The full `indexAll()` rebuild remains available.

## Top-K retrieval and deterministic reranking

MCMA still performs exact lookup first. Semantic retrieval runs only after exact miss.

~~~text
question
  ↓
exact lookup
  ↓ miss
EmbeddingProvider
  ↓
encrypted derived semantic index
  ↓
actor-visible + current-storage_hash filter
  ↓
cosine candidates
  ↓
KnowledgeRecord::assess() on every candidate
  ↓
deterministic local reranker
  ↓
reuse | revalidate | reject | miss
~~~

Top-K candidates expose retrieval metadata only:

- similarity
- object_id
- logical_ref
- validation
- confidence
- freshness
- permission_eligible
- maturity
- evidence_count
- recency

Top-K does not return remembered answers or provenance payloads.

The deterministic reranker combines similarity, confidence, validation, freshness, recency, maturity and evidence count. Decision class is authoritative: a reusable candidate ranks ahead of a candidate requiring revalidation or rejection. Similarity never authorizes reuse by itself.

Permissions are applied before a candidate can enter Top-K. A memory unreadable by the requesting actor is not returned as a semantic candidate.

## Revision safety

Each vector is bound to the concrete knowledge revision `storage_hash`.

`object_id` remains stable across knowledge revisions. If a KnowledgeRecord changes outside the incremental Librarian path, its old vector remains stale and cannot be used until refreshed or rebuilt.

## Bedrock connector

The first real embedding connector uses Amazon Titan Text Embeddings V2.

Defaults:

~~~text
amazon.titan-embed-text-v2:0
256 dimensions
normalize=true
~~~

Authentication supports Bedrock bearer API keys or AWS SigV4 credentials.

## CLI

~~~text
mcma semantic-index
mcma semantic-check
mcma semantic-topk
~~~

`semantic-check` and `semantic-topk` accept `--top-k=1..100`.

## Next

Build optional `mcma ask` orchestration on top of the same exact-first, permission and epistemic gates.
