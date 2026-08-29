# Local AI with Ollama

MCMA can use a local Ollama process for both provider interfaces while keeping the core and memory format unchanged.

## Architecture

~~~text
mcma CLI / API
      ↓
AskService
 ┌────┴─────────────┐
 ↓                  ↓
OllamaEmbedding   OllamaGeneration
 /api/embed        /api/chat
      ↓
SemanticIndex / Librarian
      ↓
StorageAdapter
Local | S3 | GitHub | WebDAV
~~~

The AI provider and the memory storage provider are independent.

## Environment

~~~bash
export MCMA_OLLAMA_BASE_URL=http://127.0.0.1:11434
export MCMA_OLLAMA_EMBED_MODEL=REPLACE_WITH_INSTALLED_EMBED_MODEL
export MCMA_OLLAMA_CHAT_MODEL=REPLACE_WITH_INSTALLED_CHAT_MODEL
export MCMA_OLLAMA_MAX_TOKENS=1024
export MCMA_OLLAMA_TEMPERATURE=0.2
~~~

Only set the model variable for the capability you intend to use.

## Semantic commands

~~~bash
mcma semantic-index LOCATION \
  --embedding-provider=ollama

mcma semantic-topk LOCATION question.txt \
  --actor=ai \
  --embedding-provider=ollama \
  --top-k=5
~~~

## Full local ask

~~~bash
mcma ask LOCATION question.txt \
  --actor=ai \
  --embedding-provider=ollama \
  --generation-provider=ollama \
  --top-k=5
~~~

`LOCATION` can be a local path, S3, GitHub or WebDAV location supported by MCMA.

## Mixed providers

The two provider roles can also be mixed.

Local embeddings + Bedrock generation:

~~~text
--embedding-provider=ollama
--generation-provider=bedrock-converse
~~~

Bedrock embeddings + local generation:

~~~text
--embedding-provider=bedrock-titan-v2
--generation-provider=ollama
~~~

Because semantic indexes are provider-specific derived data, changing the embedding provider creates/uses the index associated with that provider rather than changing KnowledgeRecord identity.

## Network boundary

The default Ollama URL is loopback.

Do not expose the Ollama service port directly to the public Internet for the MCMA web architecture. Nginx should expose the MCMA application/API over HTTPS; the application then talks to Ollama locally.
