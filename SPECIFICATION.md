# MCMA 1.0 Specification

Status: **Experimental — first implementation baseline**

MCMA means **Modular Cognitive Memory Archive**.

> **Intelligence can change. Memory belongs to the person.**

MCMA 1.0 is a file-first, database-free, provider-independent encrypted memory archive designed to remain usable with AI or without AI.

## Active normative specification

The detailed MCMA 1.0 contract lives under:

```text
spec/1.0/
```

The first implementation baseline now defines:

- stable `library_id`;
- stable `object_id`;
- deterministic `manifest.mcma` bootstrap;
- MCMA 1.0 encrypted envelope;
- AES-256-GCM + HKDF-SHA256;
- RFC 8785 JCS for cryptographic JSON canonicalization;
- authenticated protected header/AAD;
- canonical SHA-256 `storage_hash`;
- hash-based default object mapping;
- `memory://` logical addressing;
- encrypted root/sharded index bootstrap;
- explicit migration from historical prototype objects;
- machine-readable schemas;
- a synthetic cross-language conformance vector.

## Fundamental separation

```text
memory:// logical alias
        ↓
stable object_id
        ↓
current storage_hash
        ↓
storage adapter
        ↓
encrypted .mcma bytes
```

Temperature, category, physical path and provider are not permanent object identity.

## Compatibility

Historical encrypted objects remain valid data and are preserved under the compatibility boundary.

New MCMA 1.0 writers MUST emit only the active MCMA 1.0 format.

Historical ciphertext MUST NOT be relabeled as MCMA 1.0 without authenticated decrypt/re-encrypt migration.

## Implementation status

The format contract is now ready to drive the first manual/local implementation.

The CLI/core itself is the next work block and is not claimed as implemented yet.
