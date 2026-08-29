# MCMA 1.0 Container and Identity Design

Status: **Working design — exact envelope not yet frozen**

The working prototype proved authenticated encrypted `.mcma` objects, but its object identity depended on logical path + filename.

MCMA 1.0 changes that boundary.

## Required identity model

An object has a stable ID independent of:

- filename;
- physical path;
- storage provider;
- HOT/WARM/COLD/FROZEN;
- human-readable category.

Conceptually:

```text
memory://identity/profile
        ↓
authorized index/catalog
        ↓
stable object_id
        ↓
object hash / locator
        ↓
storage adapter
        ↓
.mcma bytes
```

## Container concept

```text
MCMA envelope/header
    │
    ├── minimum processable metadata
    └── authenticated encrypted payload
```

The exact field names are not frozen.

A future envelope will need at least concepts equivalent to:

```text
format/profile identifier
library identity
stable object identity
object/content type
content format
cryptographic profile
creation/update metadata
ciphertext
authentication tag
```

## Content formats

Initial recommended structured format:

```text
json
```

The container must be extensible enough to declare:

```text
xml
text
markdown
binary
```

## Hash-based storage

Physical storage may use a content/object hash hierarchy such as:

```text
objects/
└── 7a/
    └── 21/
        └── 7a21....mcma
```

The canonical hash input must be defined before implementation. The design must avoid self-referential hashing if a hash value is itself stored in the envelope.

## Authenticated metadata

Any metadata used for security, identity or routing decisions must have a defined integrity boundary.

The working prototype did not authenticate every exposed metadata field. MCMA 1.0 must explicitly define which header fields are:

- public and authenticated;
- public but informational;
- encrypted;
- derived/rebuildable.

## KDF and AAD

The MCMA 1.0 derivation context must bind keys to stable identity rather than mutable paths.

AAD must protect the metadata required to prevent substitution or semantic tampering.

Exact canonical serialization is still an open item.

## Temperature

Temperature is not permanent identity.

Changing HOT to WARM should update authorized index/catalog state without requiring the memory object to become a different identity.

## Compatibility

Historical encrypted objects must be read with their original rules.

Migration flow:

```text
historical object
    ↓
validate/authenticate using compatibility reader
    ↓
decrypt in authorized runtime
    ↓
create stable MCMA 1.0 object identity
    ↓
encrypt using MCMA 1.0 rules
    ↓
index + provenance link
```

Do not edit historical envelopes in place and call them MCMA 1.0.

## Open items before freeze

- manifest format;
- library ID;
- object ID syntax;
- canonical hash input;
- exact envelope fields;
- metadata privacy levels;
- KDF context;
- canonical AAD serialization;
- index object format;
- key rotation;
- migration metadata;
- signing/integrity beyond AEAD where required;
- conformance vectors.
