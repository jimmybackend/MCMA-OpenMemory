# Remembered Knowledge Reuse

MCMA implements exact remembered-knowledge reuse plus optional semantic candidate retrieval.

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

Only reuse returns the remembered answer.

## Semantic route

Semantic retrieval runs only after an exact miss.

~~~text
exact miss
   ↓
EmbeddingProvider
   ↓
encrypted semantic index
   ↓
cosine-ranked candidate
   ↓
requesting actor visibility
   ↓
KnowledgeRecord::assess()
   ↓
reuse | revalidate | reject | miss
~~~

Similarity does not override permissions or epistemic policy.

## Revision safety

Each semantic vector is stored with the knowledge object's current storage_hash.

When a knowledge record is corrected or revalidated and receives a new storage_hash, its previous semantic vector is stale and cannot authorize a direct answer until reindexing.

object_id remains stable.

## Provenance and confidence

Knowledge preserves provenance, floating-point confidence, validation state/history, freshness, reuse policy and relations.

Confidence is metadata, not proof.

## Current/latest requests

A caller can set currentRequired=true. Non-immutable remembered knowledge then requires revalidation even when semantic similarity is high.

## Provider independence

Semantic retrieval depends on EmbeddingProvider, not on a specific AI company.

The first optional connector is Amazon Titan Text Embeddings V2 through Bedrock.

The semantic index is derived encrypted data and can be rebuilt with another embedding provider without changing knowledge identity.
