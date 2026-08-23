# MCMA-OpenMemory Specification v0.1

Status: **Experimental draft**

This document defines the first public draft of MCMA-OpenMemory. It separates the logical memory model from storage providers and from the model or agent consuming that memory.

## 1. Principles

1. Memory belongs to the user or system that owns the keys.
2. MCMA-OpenMemory is an open-source, provider-agnostic memory architecture.
3. Storage providers are interchangeable adapters.
4. An `.mcma` object must be portable between providers.
5. The plaintext must not be required by the storage provider.
6. Memory meaning and memory temperature are independent.
7. Retrieval should be selective: an agent should be able to address one relevant memory instead of loading an entire store.
8. Sensitive catalogs and indexes should be encrypted when privacy matters.
9. Previously acquired knowledge may be reused without a new model call when confidence, freshness and policy allow it.
10. Cryptographic format changes must be explicitly versioned.
11. Portability should preserve user-controlled context across compatible model and storage providers.

## 2. Logical addressing

A canonical logical address may use the form:

```text
mcma://{temperature}/{cognitive-layer}/{scope}/{topic...}/{memory-id}
```

Example:

```text
mcma://hot/50-procedural/project/mcma/github/mem_01K34WQ8VGC7TJ6P6KJQ
```

The URI identifies logical placement. It does not imply a physical storage provider.

Reserved logical identities MAY be defined for system objects such as the root encrypted catalog, for example:

```text
mcma://system/index/root
```

The physical location of such objects remains adapter-specific.

## 3. Temperature states

Valid lifecycle states:

```text
hot
warm
cold
frozen
```

Suggested semantics:

- `hot`: active, high retrieval probability, immediately available to normal routing/indexing.
- `warm`: relevant but not continuously active; retrieved when topic/scope matches.
- `cold`: long-term memory; normally found through an index, search or explicit lookup.
- `frozen`: historical preservation; excluded from normal automatic retrieval unless explicitly requested or reactivated.

Moving an object between temperatures MUST NOT change its cognitive meaning.

## 4. Cognitive layers

The initial taxonomy is:

```text
00-system
10-self
20-working
30-episodic
40-semantic
50-procedural
60-relational
70-preferences
80-goals
90-projects
95-world-model
99-meta
```

Implementations MAY add subcategories below these layers. They SHOULD preserve the top-level identifiers when interoperability matters.

## 5. Scope

Common scope identifiers:

```text
global
user
project
agent
session
system
```

Implementations MAY define additional scopes.

## 6. Current envelope format

The current reference envelope is identified as `mcma-v2`.

Required fields:

```json
{
  "format": "mcma-v2",
  "cipher": "AES-256-GCM",
  "kdf": "HKDF-SHA256",
  "key_version": "mcma-key-v2",
  "key_id": "<non-secret identifier>",
  "logical_path": "<logical path>",
  "file": "<name>.mcma",
  "temperature": "hot|warm|cold|frozen",
  "created_at": "<RFC3339 timestamp>",
  "iv_b64": "<base64>",
  "tag_b64": "<base64>",
  "ciphertext_b64": "<base64>"
}
```

The envelope MUST NOT contain:

- the master key;
- a derived encryption key;
- provider credentials;
- bearer tokens;
- plaintext memory.

## 7. Key derivation in the reference implementation

The PHP reference uses:

```text
key_version = mcma-key-v2
identity    = key_version + "\n" + logical_path + "/" + filename
derived_key = HKDF-SHA256(master_key, 32 bytes, identity, "MCMA")
key_id      = first 16 hex characters of SHA-256(identity)
```

The current authenticated additional data is:

```text
MCMA2|{key_id}|{logical_path}|{filename}
```

This binds ciphertext authentication to the logical identity of the object.

Because the logical path and filename participate in key derivation and AAD, renaming or moving an existing encrypted object requires a controlled re-encryption/migration operation. A storage adapter MUST NOT silently rename an encrypted object and expect decryption to remain valid.

## 8. Encryption

The current reference implementation uses:

```text
Cipher: AES-256-GCM
IV:     12 random bytes
Tag:    16 bytes
KDF:    HKDF-SHA256
```

Implementations MUST generate a fresh cryptographically secure IV for each encryption.

## 9. Storage adapter contract

A storage adapter SHOULD expose provider-neutral operations conceptually equivalent to:

```text
put(logical_object, bytes)
get(logical_object) -> bytes
exists(logical_object) -> bool
delete(logical_object)
list(prefix) -> object references
```

Provider authentication, bucket names, repository names, filesystem roots and API endpoints belong to adapter configuration, not the core envelope specification.

## 10. Encrypted indexes and catalogs

Indexes may include:

- lexical indexes;
- semantic embeddings;
- RAG chunk maps;
- relation graphs;
- recency/activity scores;
- agent-generated summaries;
- routing metadata;
- memory IDs and logical URI mappings;
- validation/provenance metadata.

A privacy-oriented implementation SHOULD protect index contents because metadata can reveal information even when the underlying `.mcma` objects remain encrypted.

A scalable implementation SHOULD be able to use an encrypted root catalog that points to encrypted index shards. Sharding MAY be based on:

```text
temperature
cognitive layer
scope
project
topic
time range
hash prefix
```

The Index Engine SHOULD retrieve and decrypt only the minimum shards required to resolve the target memory.

Indexes SHOULD be rebuildable from authorized memory content when practical. Implementations MUST distinguish rebuildable derived indexes from authoritative catalog metadata that requires durable backup.

Implementations SHOULD support separate derivation contexts or keys for memory objects, catalogs/indexes, semantic/vector indexes and backups.

See `docs/ENCRYPTED-INDEX.md`.

## 11. File names

Human-readable filenames are permitted, but privacy-oriented deployments SHOULD prefer opaque identifiers, for example:

```text
mem_01K34WQ8VGC7TJ6P6KJQ.mcma
```

Human meaning can be maintained in an encrypted index rather than exposed in storage filenames.

## 12. Remembered knowledge and direct reuse

MCMA MAY preserve the useful result of a prior AI consultation as durable knowledge memory.

A reusable knowledge memory SHOULD be able to retain:

- question or intent;
- derived answer or approved summary;
- provenance/source references when available;
- capture time;
- confidence;
- validation state;
- freshness/revalidation policy;
- relations to superseding or contradicting memories.

Suggested validation states are:

```text
unverified
plausible
supported
verified
disputed
retracted
```

A compatible implementation MAY answer directly from remembered knowledge without making a new generative-model call when all applicable reuse policy conditions are satisfied.

A system SHOULD NOT blindly reuse stored knowledge when the request requires current information, the memory is stale, confidence is insufficient, the memory is disputed/retracted, or the new intent materially differs from the stored one.

Confidence MUST be treated as an auditable belief/quality signal, not proof of truth.

When knowledge is later confirmed, corrected or rejected, implementations SHOULD preserve provenance and state transitions rather than silently erasing the history when practical.

See `docs/KNOWLEDGE-REUSE.md`.

## 13. Portable continuity

A conforming memory store SHOULD be exportable together with its encrypted catalogs and indexes without requiring the original storage provider.

A compatible consumer MAY use the same memory store to recover:

- prior knowledge;
- project state;
- procedural memory;
- preferences;
- user-defined behavioral/persona context;
- provenance and confidence metadata.

MCMA does not require the model that originally produced a memory to be the model that later consumes it.

Different models may interpret context differently; therefore portable memory does not guarantee identical model personality or output. The goal is continuity of user-controlled memory and context.

## 14. Provider migration

A conforming migration between providers should be possible without decrypting and re-encrypting each object, provided the object's logical path and filename remain unchanged and the destination preserves the exact envelope bytes.

The encrypted catalog/index SHOULD also remain portable or reconstructible under the new adapter.

If the logical identity changes, the object must be re-encrypted under the new identity.

## 15. Capture policy and user control

Implementations SHOULD expose policy controls over what becomes durable memory.

Examples:

```text
remember approved answers
remember project knowledge
ask before remembering
exclude specified scopes
delete selected memory
freeze selected memory
expire dynamic knowledge after N days
require revalidation before direct reuse
```

Open memory ownership includes the ability to inspect through authorized tools, export, migrate, classify, cool, freeze, correct and delete memories.

## 16. Versioning

The repository release version and envelope format version are independent.

This repository begins at `0.1.0`. Existing working prototypes already created `mcma-v1` and `mcma-v2` envelopes; therefore the current envelope keeps the identifier `mcma-v2` instead of being renamed for presentation purposes.

## 17. Compatibility

The reference repository may preserve legacy readers/decryptors for older envelope versions. New code SHOULD write only the currently recommended envelope version unless compatibility requirements dictate otherwise.
