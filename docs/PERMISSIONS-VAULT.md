# Permissions and Vault

MCMA 1.0 now implements its first authorization and secret-use boundary.

## Canonical resources

~~~text
memory://access/permissions
memory://access/vault
~~~

Both are encrypted MCMA resources and are referenced by the library manifest.

## Permission Engine

Decisions use:

~~~text
actor + action + memory:// resource
~~~

Initial actor roles:

~~~text
owner
ai
librarian
security-agent
application
tool
device
~~~

The default policy is deny-by-default.

Resource rules can override role defaults. More-specific resource/actor rules win; deny wins ties.

The owner cannot install a policy that removes the owner's own permission-management or vault-management recovery authority.

## Actor-aware operations

User/tool/AI-facing clients should use:

~~~text
readAs
writeAs
updateAs
setTemperatureAs
listAs
treeAs
~~~

The CLI uses these actor-aware methods and defaults to --actor=owner.

Mutating permission checks run inside the storage write-lock/CAS window after the current manifest is reloaded.

## Vault

The vault object uses the dedicated MCMA cryptographic role:

~~~text
container = vault
key_context = vault
~~~

This domain-separates its derived key from ordinary memory objects.

Raw vault content is blocked from the ordinary read API.

There is deliberately no CLI command that returns a secret.

## Vault commands

~~~text
mcma vault-put LOCATION NAME ENV_VAR [--type=secret] [--actor=owner]
mcma vault-list LOCATION [--actor=owner]
mcma vault-delete LOCATION NAME [--actor=owner]
~~~

vault-put reads secret bytes from the named environment variable.

vault-list returns only entry name/type/timestamps.

Trusted code can call:

~~~text
useVaultSecret(name, actor, callback)
~~~

The callback receives the secret internally. The caller should return only the result of the authorized operation, not the secret.

## AI boundary

~~~text
AI request
   ↓
Permission Engine
   ↓
Security Agent / trusted MCMA client
   ↓
useVaultSecret
   ↓
external operation
   ↓
safe result
~~~

Raw vault secrets are never normal AI context.

## Current cryptographic limitation

The vault has a separate HKDF key context but currently derives from the same MCMA library master-key hierarchy.

Future clients may add an independent unlock factor, secure enclave/TPM/Android Keystore, KMS/HSM or passkey-backed key release.

## Schemas

~~~text
spec/1.0/schema/permissions.schema.json
spec/1.0/schema/vault-payload.schema.json
~~~
