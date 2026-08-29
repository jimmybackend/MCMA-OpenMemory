# MCMA 1.0 Knowledge Reuse

Status: **Experimental implemented profile**

MCMA may preserve derived knowledge so a compatible client can reuse an already researched or validated answer without calling a generative model again.

Knowledge is still memory, not truth. Direct reuse is conditional.

## Canonical exact-intent address

The first implementation uses a deterministic exact-intent key.

~~~text
normalized_question
        ↓
SHA-256
        ↓
memory://knowledge/q-<64 lowercase hex>
~~~

Normalization:

- trim leading/trailing whitespace;
- collapse internal Unicode whitespace;
- lowercase Unicode when mbstring is available;
- otherwise lowercase ASCII plus a small Latin fallback map.

This is intentionally **not semantic search**.

Different wording with different normalized bytes may produce different keys even when a human considers the questions equivalent.

Semantic/vector retrieval is a later optional layer.

## Knowledge record

A knowledge record contains:

~~~text
intent
answer
provenance[]
epistemic
freshness
relations[]
~~~

The complete record is encrypted as normal MCMA JSON content.

## Provenance

Each source entry records:

~~~json
{
  "source_type": "documentation",
  "reference": "RFC/example/provider reference",
  "captured_at": "RFC3339 timestamp",
  "content_hash": "sha256:... optional",
  "note": "optional"
}
~~~

Initial source types:

~~~text
user
memory
web
documentation
api
database
working-test
model
file
observation
migration
other
~~~

Provenance is evidence metadata. It is not proof by itself.

## Validation states

~~~text
unverified
plausible
supported
verified
disputed
retracted
~~~

Direct reuse rejects disputed or retracted knowledge.

The default direct-answer policy requires supported or verified knowledge.

## Confidence

The public API and CLI express confidence from 0.0 through 1.0.

The encrypted canonical record stores the same value as integer confidence_ppm from 0 through 1000000. This avoids floating-point canonicalization in the first PHP writer while retaining six decimal places.

Confidence is an auditable quality/belief signal, not a mathematical guarantee of truth.

The direct-answer path has a configurable minimum confidence threshold. The reference default is:

~~~text
0.75
~~~

## Validation history

Validation updates append an event:

~~~json
{
  "at": "timestamp",
  "validation_state": "verified",
  "confidence_ppm": 990000,
  "reason": "working test passed"
}
~~~

The normal MCMA encrypted revision chain also preserves the previous storage_hash while object_id remains stable.

## Freshness

Initial classes:

~~~text
immutable
stable
dynamic
volatile
~~~

Non-immutable records have max_age_seconds.

Freshness is calculated from last_validated_at when available, otherwise captured_at.

## Reuse policies

~~~text
always
reuse-unless-stale
revalidate-if-stale
never-direct
~~~

The explicit always policy may permit reuse after max_age_seconds, but it does not override a request explicitly marked as requiring current data.

## Direct memory answer path

~~~text
question
   ↓
normalize + exact intent hash
   ↓
authorized memory lookup
   ↓
validation state
   ↓
confidence threshold
   ↓
freshness/current-data check
   ↓
reuse / revalidate / reject / miss
~~~

Only the reuse decision returns the remembered answer.

revalidate and reject return decision metadata without returning the remembered answer as a direct answer.

## Current/latest requests

The engine does not attempt to infer words such as "latest" or "today" using an AI model.

The caller supplies currentRequired=true when the request needs current information.

For non-immutable knowledge, that forces revalidation.

## Permissions

Knowledge retrieval uses actor-aware Library reads. Existing MCMA Permission Engine policy therefore applies before a remembered answer can be returned.

## Schema

See schema/knowledge-record.schema.json.
