# MCMA Design Principles

This document records the current architectural principles agreed for the project.

## 1. User ownership

Memory belongs to the person or entity controlling the authorized keys.

## 2. File-first

The canonical memory representation is stored as files/objects, not as rows in a mandatory database.

Databases may be used by optional clients as caches or acceleration layers, but they are not required for interoperability.

## 3. Database-free core

Opening and traversing an MCMA library must not require MySQL, PostgreSQL, Redis, Elasticsearch or another database server.

## 4. Provider independence

Storage and AI providers are replaceable.

MCMA must not require a specific model vendor or cloud vendor.

## 5. Portable references

Internal references should not depend on a current physical location such as a local path, bucket name or provider URL.

Examples:

~~~text
memory://identity/profile
memory://projects/example
memory://topics/security
~~~

## 6. Stable object identity

Objects must have stable IDs independent of human-facing views and temperature.

Changing HOT to WARM must not conceptually change what the memory is.

The current mcma-v2 reference envelope binds logical_path + filename into key derivation/AAD. Therefore this principle requires an explicit future format/migration design rather than silently changing existing encrypted objects.

## 7. Hierarchical indexes

A client should not scan an entire multi-year library for each query.

Indexes should be hierarchical and shardable by hash prefix, topic, person, project, date, scope, cognitive category, temperature or privacy policy.

## 8. Virtual views

HOT, WARM, COLD and FROZEN should be representable as logical views derived from indexes.

Physical object placement does not have to mirror the user-visible tree.

## 9. Document-oriented memory

Documents may contain structured fields, nested structures, references, free-form text, provenance, confidence, history and extensions not known when the schema was first written.

## 10. Self-description

An MCMA object should expose enough authorized metadata to tell a compatible client what it is, how it is encoded, which format version it uses and how to process it.

## 11. Encrypted content

Private payloads are encrypted. Metadata exposure is policy-dependent.

## 12. Vault isolation

Highly sensitive secrets are handled through access/vault.mcma or equivalent secure vault mechanisms.

A model must never receive raw vault contents merely because it requested an operation.

## 13. Permission separation

Permissions are independent from memory temperature.

Policies may distinguish owner, AI, librarian agent, security agent, application, external tool and device.

Operations may include read, write, delete, decrypt, classify, summarize and share.

## 14. Biometric boundary

Raw fingerprints, face templates or equivalent biometric material should not be stored as ordinary MCMA memory.

Platform mechanisms such as Secure Enclave, TPM, Android Keystore, Windows Hello and passkeys may unlock keys without revealing biometric data to MCMA or to an AI.

## 15. Knowledge maturity

Information may evolve through:

~~~text
raw → observed → classified → knowledge → confirmed
~~~

State transitions should preserve provenance and history.

## 16. AI optionality

Manual and programmatic reading, listing, verification, migration and export must remain possible without AI.

## 17. Long-lived evolution

Formats must be versioned so encryption, indexes and document structures can evolve over years without losing old libraries.

## 18. Open implementation

The repository is MIT licensed so independent clients can implement the format without depending on the original application.
