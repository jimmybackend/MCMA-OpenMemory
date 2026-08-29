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

The first connector is:

~~~text
BedrockConverseGenerationProvider
~~~

It is outside the core under the AWS connector package.

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
