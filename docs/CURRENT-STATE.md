# MCMA — Current State

Date: 2026-08-29

Status: **MCMA 1.0 local core hardened with revisions, locking, recovery and historical migration.**

## Implemented commands

~~~text
mcma init
mcma open
mcma info
mcma write
mcma update
mcma temperature
mcma read
mcma verify
mcma list
mcma tree
mcma key-export
mcma key-import
mcma migrate
~~~

## Stable revision behavior

~~~text
object_id stays stable
storage_hash changes
revision increments
previous_storage_hash is retained inside encrypted metadata
~~~

Changing HOT/WARM/COLD/FROZEN follows the same stable-identity rule.

Old content-addressed revisions are retained; the encrypted root index points to the current revision.

## Concurrent writes

Mutating operations use an exclusive .mcma.lock with flock(). After acquiring the lock, the library reloads the current manifest before updating indexes.

## Recovery

The master key remains outside the portable library. The CLI can export/import a passphrase-encrypted mcma-key-backup-1 recovery bundle using AES-256-GCM and PBKDF2-HMAC-SHA256.

## Historical migration

The first migrator can authenticate/decrypt historical mcma-v1 and mcma-v2 files when the authorized historical master key is supplied.

Migration creates a new MCMA 1.0 object_id, preserves encrypted provenance, defaults to COLD + observed unless explicitly classified, detects repeated import of the same encrypted source, and never deletes the source.

## Tests

~~~text
tests/conformance/run.php
tests/integration/local-core.php
tests/integration/historical-migration.php
~~~

The hardening tests cover update identity, temperature identity, recovery export/import and synthetic historical V2 migration.

## Real historical user memory

jimmybackend/jimmybackend/memories contains real historical encrypted objects. This development environment does not expose the private historical master key, so no real user memory was decrypted or copied during this block.

## Next block

Define the reusable Storage Adapter interface, move local filesystem access behind it, then implement Git-backed storage while preserving the same MCMA 1.0 bytes and identities.
