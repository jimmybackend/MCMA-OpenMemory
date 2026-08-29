# MCMA 1.0 Design Principles

## 1. User ownership

Memory belongs to the person or entity controlling the authorized keys.

## 2. File-first

The canonical interoperable representation is files/objects, not rows in a mandatory database.

Optional clients may use databases as caches or accelerators.

## 3. Database-free core

Opening and traversing an MCMA library must not require MySQL, PostgreSQL, Redis, Elasticsearch or another database server.

## 4. Provider independence

Storage and AI providers are replaceable.

## 5. Portable logical references

Internal references must not depend on a current local path, bucket, repository or provider URL.

Examples:

```text
memory://identity/profile
memory://projects/example
memory://topics/security
```

## 6. Stable object identity

Objects must have stable IDs independent of human-facing views, physical location and temperature.

Historical prototype objects tied identity to path + filename. MCMA 1.0 must solve this through a new explicit identity contract and migration, not by relabeling old ciphertext.

## 7. Hierarchical indexes

A client should not scan an entire multi-year library for each query.

Indexes should be hierarchical and shardable.

## 8. Virtual views

HOT, WARM, COLD and FROZEN are logical relevance views. Physical placement does not define temperature.

## 9. Document-oriented memory

Documents may contain structured fields, nested structures, references, free-form text, provenance, confidence, history and extensions.

## 10. Self-description

An authorized compatible client must be able to determine format, encoding and processing requirements.

## 11. Encryption

Private payloads are encrypted. Metadata exposure is policy-dependent.

## 12. Vault isolation

Highly sensitive secrets belong behind the vault/security boundary.

Raw vault content must never become normal AI context.

## 13. Permission separation

Permissions are independent from temperature and may distinguish owner, AI, librarian, security agent, application, external tool and device.

## 14. Biometric boundary

Raw biometric templates should not be ordinary MCMA memory.

Platform secure hardware and passkeys may unlock keys without exposing biometric data to MCMA or AI.

## 15. Knowledge maturity

```text
raw → observed → classified → knowledge → confirmed
```

State changes should preserve provenance and history.

## 16. AI optionality

Manual reading, listing, verification, migration and export must remain possible without AI.

## 17. Long-lived evolution

Formats and cryptographic profiles must be versioned internally so libraries can evolve without losing old data.

## 18. Open implementation

MCMA is MIT licensed so independent compatible clients can be built.
