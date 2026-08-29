# Permissions and vault.mcma

## Independent permission model

Memory temperature does not determine access.

Examples:

~~~text
HOT + PRIVATE
WARM + SECRET
FROZEN + PUBLIC
~~~

## Resource policies

A policy may conceptually describe:

~~~json
{
  "resource": "memory://identity/profile",
  "permissions": {
    "owner": {
      "read": true,
      "write": true,
      "delete": true,
      "decrypt": true
    },
    "ai": {
      "read": true,
      "write": false,
      "decrypt": false,
      "summarize": true
    },
    "librarian": {
      "read": true,
      "write": true,
      "classify": true
    },
    "external_tools": {
      "read_metadata": true,
      "decrypt": false
    }
  }
}
~~~

The exact schema remains to be finalized.

## vault.mcma

MCMA adopts the concept:

~~~text
access/vault.mcma
~~~

The vault is a special security boundary for highly sensitive material.

Possible contents or references include cloud credentials, API tokens, encryption keys, device authorizations, recovery material and secret references.

## AI boundary

A model must not receive the raw contents of vault.mcma.

~~~text
AI requests operation
        │
        ▼
MCMA Security Agent / Client
        │
        ▼
evaluate permission
        │
        ▼
use vault material internally
        │
        ▼
perform authorized operation
        │
        ▼
return only allowed result
~~~

The model can know that a capability or credential reference exists without learning the credential itself.

## Portable/offline vault

For a portable USB/local-first deployment, MCMA may support an encrypted vault carried with the library.

That vault must still require an independent unlock factor or authorized key.

Plaintext cloud credentials must never be stored in normal MCMA documents.

## Device-secure vault

Where available, clients should prefer Secure Enclave, TPM, Android Keystore, Windows Hello, passkeys or managed KMS/HSM for server deployments.

## Metadata privacy modes

### Normal mode

Human-readable names and directory structure are allowed.

### Private mode

Semantic names are hidden behind opaque IDs and encrypted indexes.

## Recovery

Vault design must eventually define backup, recovery keys, loss scenarios, device replacement, key rotation and compromise response.

Recovery must not require a model provider.
