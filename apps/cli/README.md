# MCMA 1.0 CLI

The CLI requires PHP 8.2+ with OpenSSL. GitHub storage also requires PHP cURL.

It has no AI or database dependency.

## Storage locations

Local:

```text
/var/lib/mcma/my-library
```

GitHub:

```text
github://OWNER/REPO/optional/prefix?branch=main
```

For GitHub set `MCMA_GITHUB_TOKEN`. The target branch must already exist.

## Commands

```text
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
mcma storage-copy
```

Example provider migration:

```bash
export MCMA_GITHUB_TOKEN='...'
./apps/cli/mcma storage-copy ~/memory.mcma-library 'github://owner/repo/memory?branch=main'
```

The copy is byte-preserving. Keys are not moved into GitHub.

## Concurrency

Local storage uses an exclusive filesystem lock.

GitHub uses optimistic compare-and-swap on the manifest's Git blob SHA. If another writer changes the manifest first, the stale update fails rather than overwriting it.

## Recovery and historical migration

Recovery and historical V1/V2 migration remain available exactly as before. Secrets and passphrases are read from protected files/environment variables, not literal command-line values.
