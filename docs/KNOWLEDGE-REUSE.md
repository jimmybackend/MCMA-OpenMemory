# Remembered Knowledge Reuse

MCMA implements exact remembered-knowledge reuse plus optional semantic Top-K retrieval.

## Exact route

~~~text
question
    ↓
normalize exact intent
    ↓
SHA-256 logical reference
    ↓
permission + validation + confidence + freshness
    ↓
reuse | revalidate | reject | miss
~~~

Only `reuse` returns the remembered answer.

## Semantic route

Semantic retrieval runs only after an exact miss.

~~~text
exact miss
   ↓
EmbeddingProvider
   ↓
encrypted semantic index
   ↓
actor-visible current revisions
   ↓
cosine Top-K
   ↓
KnowledgeRecord::assess() for each candidate
   ↓
deterministic reranking
   ↓
reuse | revalidate | reject | miss
~~~

Similarity does not override permissions or epistemic policy.

The local reranker considers similarity, confidence, validation, freshness, recency, maturity and evidence count. A reusable candidate is ranked before a candidate whose policy result is `revalidate` or `reject`.

Top-K candidate inspection returns metadata, not answers or provenance contents.

## Incremental indexing

`SemanticIndexService::indexOne()` updates one semantic entry.

The semantic entry stores:

- logical_ref
- object_id
- current storage_hash
- float vector

If the current `object_id` and `storage_hash` are already indexed, the embedding provider is not called again.

If the KnowledgeRecord receives a new revision, `object_id` remains stable while `storage_hash` changes. The affected semantic entry is refreshed without recalculating unrelated embeddings.

`SemanticIndexService::remove()` removes one derived entry and is intended as the semantic cleanup hook when an ordinary KnowledgeRecord deletion API is added.

The Librarian accepts optional semantic dependencies. When configured, `remember()` and `validate()` automatically call the one-record incremental refresh.

## Permission filtering

The encrypted semantic index is an internal derived cache.

Before ranking is exposed to a caller, MCMA reconstructs visibility through actor-aware memory APIs. A candidate the actor cannot read is excluded from Top-K and cannot produce an answer.

## Revision safety

Each semantic vector is bound to the knowledge object's concrete `storage_hash`.

If knowledge changes without an incremental semantic refresh, the old vector is stale and is skipped. It cannot authorize direct reuse.

## Provenance and confidence

Knowledge preserves provenance, floating-point confidence, validation state/history, freshness, reuse policy and relations.

Confidence and provenance are epistemic metadata, not absolute proof.

## Current/latest requests

A caller can set `currentRequired=true`. Non-immutable remembered knowledge then requires revalidation even when semantic similarity is high.

## Provider independence

Semantic retrieval depends on `EmbeddingProvider`, not on a specific AI company.

The first optional connector is Amazon Titan Text Embeddings V2 through Bedrock.

The semantic index is derived encrypted data and can be rebuilt with another embedding provider without changing knowledge identity.
