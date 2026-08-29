# MCMA 1.0 Key Recovery Bundle

Status: **Experimental implementation profile**

The portable MCMA library does not contain its plaintext master key.

The first local implementation supports an encrypted recovery bundle so a library key can be backed up separately and restored on another authorized device.

## Format

Recovery files use:

~~~text
mcma-key-backup-1
~~~

They are not normal memory objects and are not stored inside the MCMA library by default.

Protected header:

~~~json
{
  "format": "mcma-key-backup-1",
  "cipher": "AES-256-GCM",
  "kdf": "PBKDF2-HMAC-SHA256",
  "iterations": 600000,
  "salt_b64u": "...",
  "iv_b64u": "..."
}
~~~

The encrypted payload contains library_id, key_version, master_key_b64 and created_at.

The protected header is RFC 8785 JCS canonicalized and used as AES-GCM AAD.

## CLI

~~~text
mcma key-export LIBRARY recovery.mcma-key
mcma key-import recovery.mcma-key
~~~

The passphrase is read from MCMA_RECOVERY_PASSPHRASE by default, or another environment variable named with --passphrase-env.

Passphrases are intentionally not accepted as command-line values.

## Security rules

- recovery files MUST be stored separately from ordinary library copies when practical;
- recovery passphrases SHOULD be long and unique;
- exported bundles MUST NOT be committed to source repositories;
- importing a different key over an existing library key requires explicit replacement;
- recovery does not replace platform key stores, KMS/HSM, passkeys or hardware-backed protection.

The repository .gitignore excludes *.mcma-key.
