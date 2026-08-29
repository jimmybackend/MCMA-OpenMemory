# MCMA 1.0 Compatibility Migration

Status: **Normative migration rule**

## Principle

Historical encrypted prototype objects are real user data.

They MUST NOT be renamed internally, edited in place or relabeled as MCMA 1.0.

## Migration pipeline

```text
historical .mcma
    ↓
detect original format
    ↓
validate with original compatibility rules
    ↓
authenticate + decrypt in authorized runtime
    ↓
classify payload / preserve source metadata
    ↓
assign MCMA 1.0 stable object_id
    ↓
create MCMA 1.0 encrypted payload
    ↓
fresh IV + MCMA 1.0 KDF/AAD
    ↓
calculate storage_hash
    ↓
write new object revision
    ↓
update MCMA 1.0 index
```

## Source preservation

Migration SHOULD preserve provenance inside the encrypted MCMA 1.0 payload.

Conceptual provenance entry:

```json
{
  "type": "migration",
  "source_format": "historical-prototype",
  "source_key_id": "non-secret historical identifier",
  "source_ref": "original authorized storage reference",
  "migrated_at": "RFC3339 timestamp"
}
```

Sensitive source paths MAY be omitted or replaced with an opaque reference in private libraries.

## Identity assignment

Migration MUST create a new MCMA 1.0 `object_id` because historical prototype identity was not the MCMA 1.0 stable-ID contract.

Repeated migration of the same source object SHOULD be detected by migration tooling to avoid accidental duplicates.

The deduplication mechanism must not expose plaintext hashes publicly in private libraries.

## Existing compatibility code

Original readers remain under:

```text
reference/compatibility/
```

That code is authoritative only for reading its historical format.

New MCMA 1.0 writers MUST NOT emit historical envelopes.

## No destructive migration by default

A migration tool SHOULD:

1. write and verify the new MCMA 1.0 object;
2. update the new index;
3. preserve the historical source until an explicit authorized cleanup step.

Successful migration MUST be verifiable before old encrypted data is deleted.
