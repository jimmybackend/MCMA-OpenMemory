# MCMA Container Format — Next-Revision Design Draft

Status: **Design draft; not yet the current interoperability specification.**

The repository currently documents and implements the mcma-v2 reference envelope. This document records the architectural direction for a future revision.

## Existing compatibility constraint

The current reference derives per-object identity from:

~~~text
logical_path + filename
~~~

and authenticates those values through AES-GCM AAD.

Therefore renaming or moving an existing mcma-v2 logical object requires controlled re-encryption.

The new design goal is to make object identity stable while temperature and human-visible tree placement become mutable views.

That change must be versioned.

## Container concept

A future MCMA object should conceptually separate:

~~~text
MCMA envelope/header
    │
    ├── minimum processable metadata
    └── encrypted payload
~~~

## Example conceptual envelope

Synthetic example only:

~~~json
{
  "format": "mcma-next-draft",
  "object_id": "mem_01...",
  "object_hash": "sha256:...",
  "object_type": "person_profile",
  "content_format": "json",
  "created_at": "2026-08-29T00:00:00Z",
  "updated_at": "2026-08-29T00:00:00Z",
  "crypto": {
    "cipher": "AES-256-GCM",
    "key_ref": "non-secret-key-reference",
    "iv_b64": "...",
    "tag_b64": "..."
  },
  "payload_b64": "..."
}
~~~

The exact format is not yet frozen.

## Content formats

JSON is the recommended structured payload format.

The container should be capable of declaring other payload types:

~~~text
json
xml
text
markdown
binary
~~~

## Stable object identity

An object should have a stable ID, for example mem_01....

Human meaning and temperature should be represented through encrypted indexes/metadata rather than by making the physical storage path the permanent identity.

## Hash-based physical storage

~~~text
objects/
└── 7a/
    └── 21/
        └── 7a21f8....mcma
~~~

The user-facing tree can then be reconstructed from indexes.

## Logical references

Proposed logical references:

~~~text
memory://identity/profile
memory://identity/preferences
memory://projects/example
memory://topics/mcma
~~~

The URI should resolve through the catalog/index to a stable object ID/hash.

The storage provider location is external to the reference.

## Virtual temperature

Temperature should become an attribute/view rather than permanent physical identity.

Changing hot to warm should ideally update authorized index state without changing the immutable identity of the payload object.

## Visible metadata

Normal libraries may expose useful names.

Private libraries should be able to hide semantic metadata behind encrypted indexes and opaque filenames.

## External non-AI readers

A compatible tool should be able to:

1. resolve a logical path;
2. locate the object;
3. check permissions;
4. unlock the required key;
5. authenticate and decrypt the payload;
6. decode the declared content format.

No AI is required for this flow.

## Open design questions

Before a next envelope is frozen, define:

- exact public header;
- metadata privacy levels;
- stable object ID format;
- canonical hash algorithm;
- key derivation context;
- AAD identity;
- index object format;
- key rotation;
- manifest format;
- migration from mcma-v2;
- signing/integrity beyond encryption where needed.
