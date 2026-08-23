# Remembered Knowledge Reuse

MCMA-OpenMemory is intended to remember not only personal facts and project state, but also knowledge that an AI system has already obtained for the user.

The goal is simple: if a question has already been researched, answered, accepted and preserved, a compatible system should be able to retrieve that knowledge directly instead of paying a model to rediscover the same thing every time.

## Knowledge capture

An implementation MAY capture the useful result of a consultation as memory after applying policy and user controls.

Recommended default behavior is to preserve:

- the derived answer or user-approved summary;
- the question or intent that produced it;
- provenance/source references when available;
- capture time;
- confidence at capture time;
- validation state;
- freshness/revalidation policy;
- relationships to other memories.

Systems SHOULD avoid indiscriminately copying entire third-party documents when a summary, derived fact and source reference are sufficient.

## Direct memory answer path

When a new request matches previously stored knowledge, MCMA can choose a memory-first path:

```text
New question
    ↓
Encrypted index lookup
    ↓
matching knowledge memory found
    ↓
check confidence + validation + freshness policy
    ↓
retrieve and decrypt memory
    ↓
return remembered answer
```

If policy permits, no generative-model call is required for this path.

This can reduce:

- repeated inference cost;
- latency;
- dependence on a particular AI provider;
- repeated web/API retrieval;
- unnecessary context-token consumption.

## When not to reuse blindly

Stored knowledge is not automatically true forever. A system SHOULD revalidate or escalate when:

- the information is time-sensitive;
- confidence is below policy threshold;
- the memory is disputed or contradicted;
- a source has changed;
- the user asks for current/latest information;
- the new question materially differs from the remembered one.

## Epistemic metadata

MCMA can preserve how strongly a result was believed and why.

A knowledge-memory record MAY include fields conceptually equivalent to:

```json
{
  "confidence": 0.86,
  "validation_state": "supported",
  "evidence_count": 3,
  "source_types": ["documentation", "working-test"],
  "captured_at": "<timestamp>",
  "last_validated_at": "<timestamp>",
  "freshness": "stable",
  "reuse_policy": "reuse-unless-stale"
}
```

Suggested validation states:

```text
unverified
plausible
supported
verified
disputed
retracted
```

`confidence` is not proof. It is an auditable belief/quality signal that can be updated as evidence changes.

## Learning from validation

If remembered knowledge is later confirmed, corrected or rejected, MCMA can preserve that transition instead of silently overwriting history.

Conceptually:

```text
answer captured
    ↓
confidence = 0.65 / plausible
    ↓
working test succeeds
    ↓
confidence = 0.95 / verified
```

or:

```text
answer captured
    ↓
later contradiction
    ↓
disputed
    ↓
corrected memory linked as successor
```

This gives agents a way to reason about not only **what was remembered**, but **how trustworthy that memory currently is**.

## Portable continuity

Knowledge reuse is model-independent. The memory should not require the same model that originally produced it.

A user may move the encrypted memory store to another provider or connect another compatible AI system and still recover:

- prior knowledge;
- project context;
- preferences;
- procedures;
- user-defined persona/behavioral context;
- provenance and confidence metadata.

Different models can still produce different behavior, so MCMA does not promise identical model personality. It provides portable context and memory that a compatible system can use to preserve continuity.

## User control

A mature implementation SHOULD allow policies such as:

```text
remember all approved answers
remember only project knowledge
ask before remembering
never remember specified scopes
expire dynamic knowledge after N days
require revalidation before direct reuse
```

Memory ownership includes the ability to inspect, export, migrate, cool, freeze, correct and delete remembered knowledge.