# Remembered Knowledge Reuse

MCMA now has an executable first implementation of remembered knowledge reuse.

## Why

If a question has already been answered, preserved, supported by evidence and remains fresh enough, MCMA can return that remembered answer without paying a model to rediscover it.

The core never assumes remembered knowledge is permanently true.

## First implemented route

~~~text
question
    ↓
normalize exact intent
    ↓
SHA-256 key
    ↓
memory://knowledge/q-...
    ↓
Permission Engine
    ↓
validation + confidence + freshness
    ↓
reuse | revalidate | reject | miss
~~~

This first route is exact/deterministic, not semantic.

That distinction matters: MCMA currently does not claim that differently worded questions are the same intent.

## Stored evidence

Knowledge records preserve:

- original question;
- normalized intent and hash;
- answer;
- structured provenance;
- confidence;
- validation state;
- evidence count;
- capture/validation timestamps;
- validation history;
- freshness class;
- maximum age;
- reuse policy;
- logical relations.

## Validation states

~~~text
unverified
plausible
supported
verified
disputed
retracted
~~~

Only supported/verified records are directly reusable by the default assessment.

disputed/retracted records are rejected.

## Freshness

~~~text
immutable
stable
dynamic
volatile
~~~

Non-immutable records carry max_age_seconds.

A caller can explicitly mark a request as requiring current data. That forces revalidation for non-immutable knowledge even when it has not reached max age.

## No blind answer on failure

A revalidate/reject decision deliberately does not include the remembered answer in the direct-answer result.

This helps prevent stale or disputed content from silently becoming the answer simply because it was found.

## Object identity

Correcting or revalidating stored knowledge updates the encrypted revision while preserving the same stable object_id.

The normal MCMA previous_storage_hash linkage preserves revision lineage.

## Provenance is not truth

A source reference and a confidence number are metadata about why a result is believed.

They are not proof.

The caller/Librarian is responsible for providing real evidence rather than invented citations.

## Model-independent agents

The deterministic Librarian wraps capture/validation/recall.

The deterministic SecurityAgent wraps permission decisions, vault metadata and trusted secret use.

Neither requires an AI provider.

## Next retrieval layer

The next optional step is semantic retrieval/indexing so paraphrased questions can find candidate knowledge records.

Candidate retrieval must still pass the same permission, validation, confidence and freshness gates before direct reuse.
