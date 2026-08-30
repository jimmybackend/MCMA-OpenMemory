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
