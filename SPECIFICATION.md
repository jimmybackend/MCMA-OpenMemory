# MCMA-OpenMemory Specification v0.1

Status: **Experimental draft**

This document defines the first public draft of MCMA-OpenMemory. It separates the logical memory model from storage providers and from the model or agent consuming that memory.

## 1. Principles

1. Memory belongs to the user or system that owns the keys.
2. Storage providers are interchangeable adapters.
3. An `.mcma` object must be portable between providers.
4. The plaintext must not be required by the storage provider.
5. Memory meaning and memory temperature are independent.
6. Retrieval should be selective: an agent should be able to address one relevant memory instead of loading an entire store.
7. Cryptographic format changes must be explicitly versioned.

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

## 10. Indexes

Indexes are optional and may include:

- lexical indexes;
- semantic embeddings;
- RAG chunk maps;
- relation graphs;
- recency/activity scores;
- agent-generated summaries;
- routing metadata.

An index SHOULD be rebuildable from authorized memory content when practical. Sensitive indexes SHOULD be encrypted or otherwise protected because metadata can reveal information even when the underlying `.mcma` objects remain encrypted.

## 11. File names

Human-readable filenames are permitted, but privacy-oriented deployments SHOULD prefer opaque identifiers, for example:

```text
mem_01K34WQ8VGC7TJ6P6KJQ.mcma
```

Human meaning can be maintained in an encrypted index rather than exposed in storage filenames.

## 12. Provider migration

A conforming migration between providers should be possible without decrypting and re-encrypting each object, provided the object's logical path and filename remain unchanged and the destination preserves the exact envelope bytes.

If the logical identity changes, the object must be re-encrypted under the new identity.

## 13. Versioning

The repository release version and envelope format version are independent.

This repository begins at `0.1.0`. Existing working prototypes already created `mcma-v1` and `mcma-v2` envelopes; therefore the current envelope keeps the identifier `mcma-v2` instead of being renamed for presentation purposes.

## 14. Compatibility

The reference repository may preserve legacy readers/decryptors for older envelope versions. New code SHOULD write only the currently recommended envelope version unless compatibility requirements dictate otherwise.
