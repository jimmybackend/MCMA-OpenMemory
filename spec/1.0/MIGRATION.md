# MCMA 1.0 Compatibility Migration

Status: **Normative migration rule with first PHP implementation**

Historical encrypted prototype objects are real user data and MUST NOT be edited in place or relabeled as MCMA 1.0.

## Supported historical readers

The first migrator detects and authenticates:

~~~text
mcma-v1
mcma-v2
~~~

using their original path/filename-bound HKDF and AES-GCM AAD rules.

## Migration pipeline

~~~text
historical .mcma
    ↓
detect original format
    ↓
validate original identity
    ↓
authenticate + decrypt with authorized historical key
    ↓
assign new MCMA 1.0 stable object_id
    ↓
encrypt with MCMA 1.0 rules
    ↓
calculate storage_hash
    ↓
update encrypted root index
    ↓
verify new object
~~~

## Conservative default

The migration CLI defaults imported objects to:

~~~text
cold
observed
~~~

unless an authorized operator explicitly supplies another target classification.

## Provenance and duplicate detection

Migration preserves encrypted provenance: source format, non-secret historical key ID, original logical path, original filename, source reference and migration timestamp.

The implementation computes an internal SHA-256 fingerprint from historical encrypted-envelope identity/ciphertext fields and stores it only inside the encrypted root index. A second attempt to migrate the same encrypted object is rejected.

## CLI

~~~text
mcma migrate LIBRARY HISTORICAL.mcma memory://topics/example
~~~

Historical key material is supplied through either a protected --legacy-key-file or MCMA_LEGACY_MASTER_KEY_B64. The raw key is never accepted as a command-line argument.

The source file is never deleted by mcma migrate.
