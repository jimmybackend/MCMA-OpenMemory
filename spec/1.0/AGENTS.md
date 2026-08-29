# MCMA 1.0 Deterministic Agent Boundaries

Status: **Experimental implemented non-generative profile**

MCMA provides two first agent/service boundaries without requiring a model provider.

## Librarian

The Librarian wraps KnowledgeService with the librarian permission role.

Implemented operations:

~~~text
remember
validate
recall
~~~

It can capture and revise knowledge, append validation evidence and evaluate reuse.

The Librarian does not autonomously invent confidence, provenance or validation evidence. A caller must supply those inputs.

## Security Agent

The SecurityAgent wraps the vault/permission boundary with the security-agent role.

Implemented operations:

~~~text
decision
vaultMetadata
useSecret
~~~

useSecret passes secret material only to a trusted callback and returns the callback result.

The SecurityAgent API does not expose a raw-secret getter.

## AI independence

These classes are deterministic orchestration boundaries.

A future AI client may call them, but the MCMA core does not require Bedrock, OpenAI, Anthropic or any other model provider.

## Future model integration

A model-facing layer should:

1. identify an intent;
2. ask KnowledgeService for an authorized direct answer;
3. return it without model inference when decision=reuse and policy permits;
4. revalidate/research when decision=revalidate;
5. never turn vault secret bytes into model context;
6. submit new or corrected knowledge through the Librarian boundary with provenance.
