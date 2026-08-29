# Local AI with Ollama or llama.cpp

MCMA can use Ollama or llama.cpp for both provider interfaces while keeping the core and memory format unchanged.

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


## llama.cpp

MCMA uses the OpenAI-compatible llama.cpp server routes:

~~~text
chat       /v1/chat/completions
embeddings /v1/embeddings
~~~

Recommended local layout:

~~~text
127.0.0.1:8080  chat model
127.0.0.1:8081  embedding model
~~~

Environment:

~~~bash
export MCMA_LLAMACPP_CHAT_URL=http://127.0.0.1:8080
export MCMA_LLAMACPP_EMBED_URL=http://127.0.0.1:8081
export MCMA_LLAMACPP_CHAT_MODEL=mcma-chat
export MCMA_LLAMACPP_EMBED_MODEL=mcma-embed
export MCMA_LLAMACPP_EMBED_PREFIX='query: '
export MCMA_LLAMACPP_EMBED_ID=multilingual-e5-small-mean-l2-v1
~~~

Example server layout:

~~~bash
./llama-server -m /models/chat-model.gguf \
  --host 127.0.0.1 --port 8080 --alias mcma-chat

./llama-server -m /models/multilingual-e5-small.gguf \
  --host 127.0.0.1 --port 8081 --alias mcma-embed \
  --embedding --pooling mean --embd-normalize 2
~~~

Full local ask:

~~~bash
mcma ask LOCATION question.txt \
  --actor=ai \
  --embedding-provider=llamacpp \
  --generation-provider=llamacpp \
  --top-k=5
~~~

Equal vector dimension is not enough to claim compatibility. Reuse the same `MCMA_LLAMACPP_EMBED_ID` only when weights, tokenizer, pooling and normalization are compatible. The configured input prefix is also fingerprinted into the provider id.

Ollama and llama.cpp indexes remain separate by default even when they appear to use the same model. That prevents accidental cross-runtime vector mixing.
