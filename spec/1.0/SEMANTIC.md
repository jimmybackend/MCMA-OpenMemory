# MCMA 1.0 Semantic Retrieval

Status: **Experimental implemented profile**

Semantic retrieval is an optional candidate-discovery layer. It never replaces MCMA permission, validation, confidence or freshness checks.

## Order

~~~text
question
  ↓
exact knowledge lookup
  ↓ miss only
semantic embedding
  ↓
internal encrypted semantic index
  ↓
visible candidate filtering for requesting actor
  ↓
cosine ranking
  ↓
top candidate
  ↓
KnowledgeRecord::assess()
  ↓
reuse | revalidate | reject | miss
~~~

If an exact record exists but requires revalidation or is rejected, MCMA does not fall through to a semantic alternative.

## Derived semantic index

Each embedding provider has its own derived encrypted index:

~~~text
memory://system/semantic-index/p-<sha256(provider_id)>
~~~

The index contains logical refs, object IDs, the knowledge revision storage_hash and embedding vectors.

The storage_hash binds a vector to the exact knowledge revision. If knowledge changes, the old vector is considered stale and cannot authorize direct reuse.

The semantic index is rebuildable derived data. It does not define knowledge identity.

## Privacy boundary

The default permission profile denies ordinary actors direct read access to memory://system/semantic-index/*.

The semantic engine may read this derived cache internally, but candidate visibility is reconstructed with actor-aware list/read calls. A candidate the requesting actor cannot read is not eligible.

## Similarity

Vectors are normalized and ranked by cosine similarity.

The reference semantic threshold is 0.78 and is caller-configurable.

Similarity alone never authorizes an answer.

## Embedding providers

The core defines a provider-neutral EmbeddingProvider interface.

The first optional connector is Amazon Titan Text Embeddings V2 on Amazon Bedrock.

The provider identity includes model, dimensions and normalization behavior, but not credentials.

## Bedrock defaults

~~~text
model: amazon.titan-embed-text-v2:0
dimensions: 256
normalize: true
~~~

Supported dimensions: 256, 512, 1024.

Authentication may use AWS_BEARER_TOKEN_BEDROCK or SigV4 AWS credentials.

## Reindexing

Changing provider/model/dimensions creates a different semantic-index logical reference.

Updating a knowledge record changes storage_hash and invalidates its previous vector until the semantic index is rebuilt.
