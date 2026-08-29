# MCMA 1.0 Identity Contract

Status: **Normative baseline for the first MCMA 1.0 implementation**

## Library identity

Every library MUST have one stable `library_id`.

Format:

```text
lib_<uuid-v4>
```

Example:

```text
lib_11111111-2222-4333-8444-555555555555
```

The UUID portion MUST be a lowercase UUID version 4 as defined by RFC 9562.

UUIDv4 is used because it is random and does not intentionally encode creation time.

A `library_id` MUST NOT change when the library is:

- copied;
- exported;
- moved to another device;
- moved to another storage provider;
- restored from backup.

A newly cloned library that is intentionally becoming a different independent library MUST receive a new `library_id`.

## Object identity

Every durable MCMA object MUST have one stable `object_id`.

Format:

```text
obj_<uuid-v4>
```

Example:

```text
obj_aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee
```

The UUID portion MUST be lowercase UUIDv4.

The `object_id` MUST NOT depend on:

- filename;
- physical path;
- storage provider;
- content hash;
- HOT/WARM/COLD/FROZEN state;
- cognitive category;
- logical `memory://` alias.

## Object identity vs object revision

`object_id` identifies the long-lived logical object.

`storage_hash` identifies one exact encrypted revision of that object.

Therefore the same object may evolve like this:

```text
object_id = obj_A
    │
    ├── storage_hash = H1
    ├── storage_hash = H2
    └── storage_hash = H3   ← current revision
```

Changing content, key version or IV changes the encrypted bytes and therefore changes `storage_hash`, but does not require a new `object_id`.

A new `object_id` is required when the new content represents a different durable object rather than a revision of the same object.

## Identifier validation

Canonical regexes:

```text
library_id:
^lib_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$

object_id:
^obj_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$
```

Implementations MUST reject non-canonical uppercase or malformed identifiers when writing MCMA 1.0 objects.

Readers MAY normalize externally supplied identifiers before lookup, but canonical stored values remain lowercase.
