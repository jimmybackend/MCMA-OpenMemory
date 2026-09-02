# MCMA Ask Orchestration

`mcma ask` is an optional orchestration layer above the file-first MCMA core.

It does not make AI mandatory.

## Route

~~~text
question
  ↓
exact KnowledgeRecord
  ├── reusable → return memory, no model call
  ↓ exact miss
optional semantic retrieval
  ├── reusable → return memory, no model call
  ↓ no reusable memory
optional GenerationProvider
  ↓
return fresh generated answer
  ↓
optional Librarian capture
~~~

Semantic retrieval still occurs only after an exact miss.

## Epistemic rule for generated answers

A newly generated answer is fresh provider output, not pre-validated knowledge.

When `mcma ask` captures it, defaults are:

~~~text
validation_state = unverified
confidence = 0.5
freshness_class = stable
reuse_policy = reuse-unless-stale
~~~

The model provider is recorded as provenance with `source_type=model`.

A later Librarian validation can promote the stored record to `supported` or `verified`. After that, the same question can be answered from memory without another generation call.

## Existing exact records

If the exact KnowledgeRecord already exists but is stale, disputed, retracted, unverified or otherwise non-reusable, `mcma ask` may call the configured generation provider for a fresh response but does not automatically overwrite the existing exact record.

This preserves validation history and prevents a model response from silently erasing a known epistemic state.

## Provider independence

The core interface is:

~~~text
GenerationProvider
  id(): string
  generate(question, context): array
~~~

Implemented generation connectors are:

~~~text
BedrockConverseGenerationProvider
OllamaGenerationProvider
LlamaCppGenerationProvider
~~~

They remain outside the core. Bedrock lives under the AWS connector package; Ollama and llama.cpp live under the local connector package.

## Bedrock configuration

Set a Converse-compatible model or inference profile explicitly:

~~~bash
export MCMA_BEDROCK_REGION=us-east-1
export MCMA_BEDROCK_CHAT_MODEL=REPLACE_WITH_MODEL_OR_INFERENCE_PROFILE_ID
export MCMA_BEDROCK_MAX_TOKENS=1024
export MCMA_BEDROCK_CHAT_TEMPERATURE=0.2
~~~

Use either a Bedrock bearer API key:

~~~bash
export AWS_BEARER_TOKEN_BEDROCK=REPLACE_ON_SERVER
~~~

or explicit SigV4 credentials:

~~~bash
export AWS_ACCESS_KEY_ID=REPLACE_ON_SERVER
export AWS_SECRET_ACCESS_KEY=REPLACE_ON_SERVER
export AWS_SESSION_TOKEN=REPLACE_IF_TEMPORARY
~~~

Do not store provider credentials inside MCMA.

## CLI examples

Memory-only exact ask:

~~~bash
mcma ask /path/to/library question.txt
~~~

Bedrock generation without semantic retrieval:

~~~bash
mcma ask /path/to/library question.txt \
  --generation-provider=bedrock-converse
~~~

Full Bedrock semantic + generation path:

~~~bash
mcma ask /path/to/library question.txt \
  --actor=ai \
  --embedding-provider=bedrock-titan-v2 \
  --generation-provider=bedrock-converse \
  --dimensions=256 \
  --top-k=5
~~~

Generated answers are remembered by default. Disable capture with:

~~~bash
--remember=no
~~~

The real EC2 smoke test is intentionally separate from CI. CI uses simulated Bedrock requests and never requires production credentials.


## Local Ollama configuration

Ollama can provide both semantic embeddings and answer generation without Bedrock.

~~~bash
export MCMA_OLLAMA_BASE_URL=http://127.0.0.1:11434
export MCMA_OLLAMA_EMBED_MODEL=REPLACE_WITH_INSTALLED_EMBED_MODEL
export MCMA_OLLAMA_CHAT_MODEL=REPLACE_WITH_INSTALLED_CHAT_MODEL
export MCMA_OLLAMA_MAX_TOKENS=1024
export MCMA_OLLAMA_TEMPERATURE=0.2
~~~

Full local ask route:

~~~bash
mcma ask /path/to/library question.txt \
  --actor=ai \
  --embedding-provider=ollama \
  --generation-provider=ollama \
  --top-k=5
~~~

The memory location can also be an S3, GitHub or WebDAV LOCATION. Local AI and memory storage are independent choices.

For local deployments, keep Ollama on loopback by default and place any public web access behind the MCMA application/API layer rather than exposing the Ollama port directly.


## Local llama.cpp configuration

llama.cpp can provide both generation and semantic embeddings through separate OpenAI-compatible local servers.

~~~bash
export MCMA_LLAMACPP_CHAT_URL=http://127.0.0.1:8080
export MCMA_LLAMACPP_EMBED_URL=http://127.0.0.1:8081
export MCMA_LLAMACPP_CHAT_MODEL=mcma-chat
export MCMA_LLAMACPP_EMBED_MODEL=mcma-embed
export MCMA_LLAMACPP_EMBED_PREFIX='query: '
export MCMA_LLAMACPP_EMBED_ID=multilingual-e5-small-mean-l2-v1
~~~

Full local ask route:

~~~bash
mcma ask LOCATION question.txt \
  --actor=ai \
  --embedding-provider=llamacpp \
  --generation-provider=llamacpp \
  --top-k=5
~~~

llama.cpp and Ollama semantic indexes remain separate by default. Equal vector dimensions do not imply compatible embeddings.

## Conversation history and AskService

The persistent web Chat groups turns with a validated `conversation_id`, but the durable UI archive is not automatically a generation prompt.

~~~text
archived conversation
  ↓
visible historical turns in Chat
  ↓
new request keeps same conversation_id
  ↓
AskService still follows exact → semantic → guarded generation rules
~~~

MCMA does not silently concatenate the full prior transcript into every model request. The current guarded Context Builder remains knowledge/revalidation oriented. Future recent-turn context must be selected explicitly under permission, relevance and token-budget controls and metered as generation context when used.

Reading the conversation list or opening archived turns makes no generation/embedding call and therefore uses zero AI tokens/credits.

