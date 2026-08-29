# llama.cpp Runtime for MCMA

MCMA supports llama.cpp as a second local runtime in addition to Ollama.

## Layout

Use separate local servers for generation and embeddings:

~~~text
127.0.0.1:8080  chat model
127.0.0.1:8081  embedding model
~~~

The generation provider calls `/v1/chat/completions`.
The embedding provider calls `/v1/embeddings`.

## Memory-constrained profile

A practical small-instance profile is:

~~~text
chat runtime:       llama.cpp
chat model class:   ~0.3B–0.6B quantized instruct model
embedding model:    multilingual E5-small class
embedding pooling:  mean
embedding norm:     L2
memory storage:     S3 / GitHub / WebDAV / Local
~~~

Exact model files are operational choices and are not hard-coded into MCMA.

## Server examples

Chat:

~~~bash
./llama-server \
  -m /models/chat-model.gguf \
  --host 127.0.0.1 \
  --port 8080 \
  --alias mcma-chat
~~~

Embeddings:

~~~bash
./llama-server \
  -m /models/multilingual-e5-small.gguf \
  --host 127.0.0.1 \
  --port 8081 \
  --alias mcma-embed \
  --embedding \
  --pooling mean \
  --embd-normalize 2
~~~

## MCMA environment

~~~bash
export MCMA_LLAMACPP_CHAT_URL=http://127.0.0.1:8080
export MCMA_LLAMACPP_EMBED_URL=http://127.0.0.1:8081
export MCMA_LLAMACPP_CHAT_MODEL=mcma-chat
export MCMA_LLAMACPP_EMBED_MODEL=mcma-embed
export MCMA_LLAMACPP_MAX_TOKENS=512
export MCMA_LLAMACPP_TEMPERATURE=0.2
export MCMA_LLAMACPP_EMBED_PREFIX='query: '
export MCMA_LLAMACPP_EMBED_ID=multilingual-e5-small-mean-l2-v1
~~~

If llama-server is started with `--api-key`, use `MCMA_LLAMACPP_API_KEY` or the chat/embed specific key variables.

## CLI

~~~bash
mcma semantic-index LOCATION --embedding-provider=llamacpp

mcma ask LOCATION question.txt \
  --actor=ai \
  --embedding-provider=llamacpp \
  --generation-provider=llamacpp \
  --top-k=5
~~~

## Embedding compatibility

Equal dimensions do not make embeddings compatible.

`MCMA_LLAMACPP_EMBED_ID` identifies the compatible embedding configuration. Reuse it only when model weights, tokenizer, pooling and normalization are compatible. MCMA also fingerprints `MCMA_LLAMACPP_EMBED_PREFIX` into the provider id.

Ollama and llama.cpp indexes are separate by default. Cross-runtime index sharing should only be introduced after a conformance test proves equivalent embeddings within an accepted numeric tolerance.
