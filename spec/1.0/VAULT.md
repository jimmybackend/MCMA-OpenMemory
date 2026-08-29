# MCMA 1.0 Vault

Status: **Experimental implemented profile**

The canonical vault resource is:

```text
memory://access/vault
```

## Cryptographic boundary

The vault is an MCMA envelope with:

```text
protected.container = vault
crypto.key_context  = vault
```

Therefore its derived encryption key is domain-separated from normal memory objects under the MCMA 1.0 HKDF contract.

The current implementation still derives from the library master-key hierarchy. A future hardware/KMS or independent unlock factor may strengthen that boundary without changing the logical vault resource.

## No ordinary read

Raw vault contents cannot be read through the ordinary memory API, even by owner.

There is intentionally no CLI command named `vault-read`, `vault-get` or equivalent.

## Operations

Implemented management operations:

```text
vaultPut
vaultList
vaultDelete
useVaultSecret
```

`vaultList` returns metadata only:

- entry name;
- type;
- created_at;
- updated_at.

It never returns secret bytes or the internal encoded secret field.

## Secret-use boundary

`useVaultSecret(name, actor, operation)` passes the secret only to a trusted in-process callback after permission evaluation.

Conceptually:

```text
AI / tool requests capability
        ↓
Permission Engine
        ↓
Security Agent / trusted client
        ↓
useVaultSecret(...)
        ↓
provider/API operation
        ↓
allowed result only
```

The secret itself MUST NOT be returned to ordinary AI/model context.

The CLI accepts vault secret material only from an environment variable:

```text
mcma vault-put LOCATION NAME ENV_VAR
```

It does not accept the literal secret as an argument.

## Inner representation

The vault payload is encrypted as a whole. Secret bytes are base64url-encoded only as an inner binary-safe representation before that encrypted payload is produced.

The encoded secret field is not public metadata.

## Schema

See:

```text
schema/vault-payload.schema.json
```
