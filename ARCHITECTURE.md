# MCMA 1.0 Architecture

MCMA separates memory semantics, cryptography, indexing and physical storage so each layer can evolve independently.

## High-level architecture

```text
Optional AI / Agents
        │
        ▼
     MCMA Core
        │
 ┌──────┼──────────┐
 │      │          │
Memory Crypto     Index
Engine Engine     Engine
 │      │          │
 └──────┼──────────┘
        │
 Permission / Vault boundary
        │
        ▼
  Storage Adapter
        │
 ┌──────┼────────────────────────┐
 │      │       │       │        │
Local  S3    WebDAV   Git     future
```

The AI and storage provider are replaceable consumers/adapters around the archive. They do not own the memory format.

## Library

An MCMA library is a portable set of encrypted/self-describing objects, indexes and bootstrap metadata.

The library must remain usable without AI and without a mandatory database server.

A future `manifest.mcma` provides the deterministic bootstrap needed to open the library.

## Stable object identity

MCMA 1.0 separates four things that the prototype partially combined:

```text
logical reference
stable object identity
physical locator
cognitive views/metadata
```

Example:

```text
memory://identity/profile
        ↓
authorized catalog/index
        ↓
object_id
        ↓
hash / physical locator
        ↓
storage adapter
        ↓
.mcma object
```

Changing a logical alias, storage provider, category or temperature must not create a new permanent object identity merely because the visible route changed.

## Memory Engine

The Memory Engine manages:

- stable memory identity;
- cognitive classification;
- scope;
- temperature;
- provenance;
- history/relations;
- knowledge maturity;
- consolidation and lifecycle policy.

## Crypto Engine

The Crypto Engine owns encryption, authentication, key derivation and cryptographic-version handling.

The working prototype proved AES-256-GCM + HKDF-SHA256 with per-object derived keys. MCMA 1.0 retains those algorithms as the current baseline while redefining identity/AAD rules around stable object identity.

The exact MCMA 1.0 cryptographic contract is being frozen in `spec/1.0/CONTAINER.md`.

## Index Engine

The Index Engine answers:

> Which object or objects are needed for this request?

Indexes may include:

- stable object ID → locator mappings;
- logical `memory://` aliases;
- cognitive classification;
- scope;
- HOT/WARM/COLD/FROZEN views;
- topic/person/project mappings;
- lexical and semantic indexes;
- provenance and validation state;
- relation graphs;
- recency/activity metadata.

Private libraries should be able to encrypt semantic index contents.

The client should decrypt only the minimum index shard needed for an operation.

## Virtual temperature views

Temperature is a relevance dimension:

```text
HOT ↔ WARM ↔ COLD ↔ FROZEN
```

It is not permanent identity and is not a privacy level.

A temperature transition should update authorized catalog/index state rather than forcing physical movement or re-encryption solely because the memory cooled or reactivated.

## Permission and vault boundary

Permissions are independent from temperature.

The MCMA client/security layer decides what an owner, AI, librarian, security agent, application, tool or device may read, write, decrypt, classify, summarize, delete or share.

Raw `vault.mcma` contents never become ordinary AI context.

## Physical storage

Physical location is adapter-specific.

A private/hash-based store may look conceptually like:

```text
objects/
└── 7a/
    └── 21/
        └── 7a21....mcma
```

The same logical memory may live on local disk, USB, S3-compatible storage, WebDAV, Git-backed storage or another provider without changing its semantic identity.

## Storage Adapter

Conceptual operations:

```text
put(object_locator, bytes)
get(object_locator)
exists(object_locator)
delete(object_locator)
list(prefix)
```

Provider credentials and endpoints are configuration, not part of portable memory identity.

## Retrieval without AI

```text
user/tool request
      ↓
memory:// reference or listing request
      ↓
authorized index lookup
      ↓
object_id + locator
      ↓
storage adapter GET
      ↓
authenticate + decrypt
      ↓
decode payload
      ↓
return authorized document
```

## Retrieval with AI

```text
user request
      ↓
MCMA routing/index lookup
      ↓
permission evaluation
      ↓
minimum authorized memory set
      ↓
decrypt selected objects
      ↓
AI / agent receives only allowed context
```

AI is optional. The archive remains valid when no model is connected.

## Compatibility boundary

Historical prototype objects remain readable through `reference/compatibility/`.

Those objects may bind cryptographic identity to path + filename. MCMA 1.0 does not silently reinterpret that ciphertext. Migration reads the historical object using its original rules and writes a new stable-identity object.
