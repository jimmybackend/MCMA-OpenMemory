# MCMA 1.0 Security Model

MCMA-OpenMemory is experimental R&D.

## Storage/key separation

Storage providers receive encrypted MCMA bytes, not plaintext master keys.

## Never commit

Do not commit master keys, bearer tokens, GitHub tokens, AWS/S3 credentials, WebDAV credentials, plaintext private memories, recovery passphrases or `*.mcma-key` bundles.

## Provider credentials

Credentials are process/deployment configuration only.

- GitHub: `MCMA_GITHUB_TOKEN`
- S3: MCMA/AWS credential environment variables
- WebDAV: `MCMA_WEBDAV_AUTH`, username/password or bearer token

WebDAV URLs containing credentials are rejected.

## Conditional writes

Remote providers use standard conditional requests to prevent silent lost updates.

WebDAV uses ETag-based `If-Match` and `If-None-Match`; a WebDAV server without ETags is considered unsafe for mutable MCMA state.

## Recovery

The separate `mcma-key-backup-1` bundle remains outside the portable library.

## Next boundary

Permissions and `vault.mcma` must prevent ordinary AI/model context from receiving raw vault contents.
