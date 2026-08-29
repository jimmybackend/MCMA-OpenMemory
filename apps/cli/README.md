# MCMA 1.0 Local CLI

The local CLI requires PHP 8.2+ with OpenSSL and has no AI or database dependency.

## Commands

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

## Stable revisions

mcma update and mcma temperature preserve object_id. Each change creates a new encrypted revision and storage_hash, while the previous storage hash is retained inside encrypted metadata.

## Recovery

~~~bash
export MCMA_RECOVERY_PASSPHRASE='a-long-unique-passphrase'
./apps/cli/mcma key-export ~/memory.mcma-library /secure/library.mcma-key
./apps/cli/mcma key-import /secure/library.mcma-key
~~~

Passphrases are read from environment variables, not CLI values.

## Historical migration

~~~bash
export MCMA_LEGACY_MASTER_KEY_B64='...'
./apps/cli/mcma migrate ~/memory.mcma-library /path/historical.mcma memory://topics/imported
~~~

Historical mcma-v1 and mcma-v2 are supported. Migration is non-destructive.

## Concurrency

All library-changing operations acquire an exclusive .mcma.lock filesystem lock and reload the latest manifest while holding that lock.

MCMA_LOCK_TIMEOUT_SECONDS controls the local wait timeout; default is 10 seconds.

## Current JCS limitation

The first PHP canonical JSON writer accepts integers but rejects floating-point JSON values rather than silently emit non-conformant RFC 8785 number serialization.
