# MCMA — Current State

Date: 2026-08-29

Status: **Portable storage + Permissions/Vault + Knowledge Reuse + semantic retrieval.**

## JCS numbers

The PHP canonical writer now supports finite IEEE-754 floating-point JSON values using ECMAScript/JCS number notation. NaN and Infinity remain rejected.

Knowledge confidence is stored as a real JSON number again, and embedding vectors can be persisted as floats.

## Semantic retrieval

MCMA uses exact lookup first. Semantic retrieval is attempted only after an exact miss.

~~~text
question
  ↓
exact lookup
  ↓ miss
EmbeddingProvider
  ↓
encrypted derived semantic index
  ↓
actor-visible candidate filter
  ↓
cosine ranking
  ↓
KnowledgeRecord::assess()
  ↓
reuse | revalidate | reject | miss
~~~

Semantic similarity never bypasses permissions, validation, confidence or freshness.

Each vector is bound to the knowledge revision storage_hash, so updating knowledge invalidates the old vector until reindexing.

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
~~~

## Next

Optimize semantic indexing incrementally and add optional ask/orchestration on top of the same security/epistemic gates.
