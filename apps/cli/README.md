# MCMA 1.0 CLI

The CLI requires PHP 8.2+ with OpenSSL. GitHub and S3 storage require PHP cURL.

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

S3 / S3-compatible:

```text
s3://BUCKET/optional/prefix?region=us-east-1
```

For GitHub set `MCMA_GITHUB_TOKEN`.

For S3 use either MCMA-specific variables or standard AWS variables:

```text
MCMA_S3_REGION
MCMA_S3_ACCESS_KEY_ID
MCMA_S3_SECRET_ACCESS_KEY
MCMA_S3_SESSION_TOKEN

AWS_REGION
AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY
AWS_SESSION_TOKEN
```

Optional compatible endpoint:

```text
MCMA_S3_ENDPOINT=https://s3.example.invalid
MCMA_S3_PATH_STYLE=true
```

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

Examples:

```bash
./apps/cli/mcma storage-copy ~/memory.mcma-library 's3://my-bucket/mcma?region=us-east-1'

export MCMA_GITHUB_TOKEN='...'
./apps/cli/mcma storage-copy 's3://my-bucket/mcma?region=us-east-1' 'github://owner/repo/memory?branch=main'
```

The copy is byte-preserving. Keys are not moved into GitHub or S3.

## Concurrency

Local storage uses an exclusive filesystem lock.

GitHub uses optimistic compare-and-swap on the manifest Git blob SHA.

S3 uses conditional writes with ETag compare-and-swap for mutable manifest publication and create-only semantics for immutable content-addressed objects.

## Recovery and historical migration

Recovery and historical V1/V2 migration remain available. Secrets and passphrases are read from protected files/environment variables, not literal command-line values.
