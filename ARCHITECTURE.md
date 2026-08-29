# MCMA 1.0 Architecture

~~~text
Optional AI / UI / Tools
          ↓
   Agent Boundaries
          ↓
 Exact Knowledge Lookup
          ↓ miss
  Semantic Retriever
          ↓
 Permission + Epistemic Gates
          ↓
       MCMA Core
          ↓
    StorageAdapter
   ├── Local
   ├── GitHub
   ├── S3-compatible
   └── WebDAV
~~~

## Semantic index

Embedding vectors are derived data, not knowledge identity.

~~~text
knowledge object
  object_id stable
  storage_hash revision
        ↓
EmbeddingProvider
        ↓
memory://system/semantic-index/p-<provider hash>
~~~

The index stores the knowledge storage_hash with each vector. A revision mismatch makes that vector stale.

## Security order

Candidate discovery and answer authorization are separate:

~~~text
semantic similarity
      ↓
candidate
      ↓
actor visibility
      ↓
validation
      ↓
confidence
      ↓
freshness/current-data
      ↓
answer only if reusable
~~~

The derived semantic index is internally readable by the semantic service, but ordinary actors are denied direct index access by the default policy.

## Provider independence

The core depends only on EmbeddingProvider.

Amazon Bedrock Titan is the first optional connector; other embedding providers can implement the same interface without changing MCMA knowledge/storage identity.
