# MCMA — Current State

Date: 2026-08-29

Status: **MCMA 1.0 has provider-neutral storage plus a first implemented Permissions + Vault security layer.**

## Storage

Implemented adapters:

~~~text
Local
GitHub
S3 / S3-compatible
WebDAV
~~~

Provider migration preserves exact encrypted bytes.

## Permissions

The encrypted policy lives at:

~~~text
memory://access/permissions
~~~

The first engine evaluates actor + action + resource with deny-by-default behavior and resource-specific overrides.

CLI memory operations are actor-aware and default to owner.

## Vault

The encrypted vault lives at:

~~~text
memory://access/vault
~~~

Its MCMA envelope uses:

~~~text
container = vault
key_context = vault
~~~

Ordinary raw reads of the vault are blocked.

Vault listing exposes metadata only. Trusted code can use a secret through an in-process callback after use_secret permission succeeds.

## Security CLI

~~~text
mcma access-init
mcma access-check
mcma permissions-show
mcma permissions-set
mcma vault-put
mcma vault-list
mcma vault-delete
~~~

There is no vault-get/vault-read command.

## Tests

New security integration coverage verifies AI normal read, AI write/update denial, AI vault denial, owner management, security-agent internal secret use, metadata-only vault listing, vault crypto role, plaintext-secret absence from stored bytes, resource-specific denial and whole-library verification.

## Next

Build the Knowledge/AI layer on top of this authorization boundary, starting with provenance, validation/freshness and the librarian/security-agent execution model.
