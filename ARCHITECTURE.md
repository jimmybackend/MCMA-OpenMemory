# MCMA 1.0 Architecture

~~~text
Web / CLI / External API
          ↓
 OIDC session / API key
          ↓
  MultiUserService
          ↓
 Billing / Credits / Plans
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
   ├── S3 / S3-compatible
   ├── Google Cloud Storage
   ├── Google Drive
   ├── Azure Blob
   ├── Alibaba OSS
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

The core remains provider-neutral.

Embedding providers currently include Bedrock Titan V2, Ollama and llama.cpp. Generation providers include Bedrock Converse, Ollama and llama.cpp. Provider choice does not change MCMA knowledge or storage identity.

## Multi-user and commercial boundary

The web/API layer resolves an authenticated identity to one isolated MCMA Library. Billing, credits, API keys and Stripe operate around that Library without becoming part of the portable memory format.

~~~text
OIDC / Bearer API key
        ↓
user_id
        ↓
isolated Library
        ↓
optional BillingService
        ↓
AskService
~~~

Stripe Checkout supports one-time and recurring packages. Recurring credits are fulfilled from verified paid invoices, while subscription lifecycle state is stored in the encrypted billing account.

## Persistent Chat and conversation archive

The web Chat layer is a view over the same file-first MCMA library; it is not a second persistence system.

~~~text
authenticated web session
        ↓
conversation_id
        ↓
AskService / explicit memory flow
        ↓
canonical encrypted interaction
memory://interactions/YYYY/MM/DD/conv_<id>/req_<id>
        ↓
private derived encrypted conversation index
memory://system/conversation-index
        ↓
GET /mcma/v1/conversations
GET /mcma/v1/conversations/conv_<32-hex>
        ↓
sidebar / reopened chronological turns
~~~

Question and answer content is stored once in the canonical interaction object. The conversation index stores summary metadata plus canonical interaction references; it does not duplicate the transcript. It is incrementally maintained and can be rebuilt from actor-visible canonical interactions when its count/fingerprint is missing or stale.

Conversation list/detail navigation performs authenticated storage reads/decryption only and reports zero AI tokens and zero AI credits. Opening an archived conversation keeps its `conversation_id` for future grouping and still does not inject the full archived transcript.

When exact/semantic memory cannot answer and generation is required, the bounded conversational path is:

~~~text
conversation_id
      ↓
private conversation index
(recent refs only; discovery)
      ↓
canonical readAs(ai, ref)
      ↓
permission filter
      ↓
exclude retracted/disputed turns
      ↓
recent anchors + lexical relevance
      ↓
rank by relevance + recency
      ↓
max turns + conservative token budget
      ↓
conversation_context
      ↓
GenerationProvider
~~~

The index cannot authorize context use: every canonical turn is re-read through the requesting actor. The selected conversation data is episodic continuity, not verified Knowledge, and is sent as untrusted reference data. Bedrock Converse, Ollama and llama.cpp share this provider-neutral context contract.

The system conversation index is private derived state and is not exposed through the normal library browser or returned as a logical reference to clients. Its current derived format keeps at most 32 recent `{ref, at}` candidate pointers per conversation in addition to the canonical interaction-reference list.

## Multi-memory RAG assembly

Multi-memory RAG is a generation-context layer above the existing semantic index; it does not create a second knowledge store.

~~~text
SemanticIndexService::topK
        ↓
actor-visible ranked metadata
        ↓
strict direct semantic gate
        ↓ miss
MultiMemoryContextBuilder
        ↓
readAs(ai, logical_ref)
        ↓
validation + confidence
        ↓
similarity + confidence + freshness + provenance + validation score
        ↓
memory-count + context-budget packing
        ↓
multi_memory_context
        ↓
Bedrock / Ollama / llama.cpp
~~~

The semantic query vector is generated once and reused by both direct selection and RAG assembly. The RAG pool may use a wider discovery similarity floor, but `answerFromTopK()` preserves the original direct similarity/rerank gates.

The derived semantic index is candidate-discovery infrastructure only. Every selected memory is re-read canonically with the requesting actor before its answer or provenance can reach the model.

Multi-memory context and bounded conversation context are independent inputs and may coexist in one generation request. Billing reserves for both configured budgets when embedding is the first paid provider call, and final generation metering includes the actual serialized contexts.

