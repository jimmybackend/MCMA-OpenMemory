# MCMA 1.0 Logical Addressing

Status: **Normative baseline for first implementation**

## Purpose

`memory://` is a logical alias namespace.

It describes how an authorized user/client refers to memory.

It does NOT identify:

- a filesystem path;
- a cloud bucket;
- a Git repository path;
- a temperature directory;
- permanent cryptographic object identity.

## Canonical form

```text
memory://<namespace>/<segment>[/<segment>...]
```

Examples:

```text
memory://identity/profile
memory://identity/preferences
memory://projects/mcma
memory://topics/security
memory://knowledge/q-<sha256>
memory://access/vault
memory://system/index/root
memory://object/obj_aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee
```

## Syntax

For MCMA 1.0 core aliases:

- scheme MUST be exactly lowercase `memory`;
- namespace MUST be lowercase ASCII;
- path segments MUST be lowercase ASCII;
- query strings are not allowed;
- fragments are not allowed;
- empty segments are not allowed;
- `.` and `..` are not allowed;
- a trailing slash is not canonical.

Namespace:

```text
[a-z][a-z0-9-]{0,31}
```

Segment:

```text
[a-z0-9][a-z0-9._-]{0,127}
```

Core URI regex:

```text
^memory://[a-z][a-z0-9-]{0,31}(?:/[a-z0-9][a-z0-9._-]{0,127})+$
```

Human-readable Unicode labels may be stored as encrypted metadata, while canonical routing aliases remain simple and deterministic.

## Core namespaces

Reserved initial namespaces:

```text
identity
projects
topics
people
knowledge
access
system
object
```

Extension namespaces SHOULD begin with:

```text
x-
```

## Resolution

Normal alias resolution:

```text
memory://projects/mcma
        ↓
authorized encrypted index
        ↓
object_id
        ↓
current storage_hash
        ↓
storage adapter
```

Direct object alias:

```text
memory://object/<object_id>
```

still requires an authorized object-id-to-storage-hash lookup unless the caller already possesses the current revision locator.

## Alias mutability

Logical aliases may change without changing `object_id`.

A single object MAY have multiple aliases.

Removing an alias MUST NOT delete the underlying object unless a separate authorized deletion operation is performed.

## Temperature

Temperature MUST NOT appear in canonical object identity.

Clients may render virtual views such as HOT/WARM/COLD/FROZEN from encrypted index metadata.
