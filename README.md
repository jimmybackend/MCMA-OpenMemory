# MCMA-OpenMemory

## MCMA 1.0 — Modular Cognitive Memory Archive

**Archivo Modular de Memoria Cognitiva**

> **Intelligence can change. Memory belongs to the person.**  
> **La inteligencia puede cambiar. La memoria pertenece a la persona.**

MCMA is an open, portable, encrypted, file-first memory archive controlled by the user. AI is optional and receives only authorized memory.

## Identity

~~~text
memory:// logical reference
        ↓
stable object_id
        ↓
current storage_hash
        ↓
StorageAdapter
        ↓
encrypted .mcma bytes
~~~

Physical path, storage provider and HOT/WARM/COLD/FROZEN temperature do not define permanent memory identity.

## Storage adapters

~~~text
StorageAdapter
├── Local filesystem
├── GitHub
├── S3 / S3-compatible
└── WebDAV
~~~

Example locations:

~~~text
/local/path
github://OWNER/REPO/prefix?branch=main
s3://BUCKET/prefix?region=us-east-1
webdav+https://HOST/existing/library/root
~~~

mcma storage-copy moves exact encrypted bytes between providers without changing object IDs or storage hashes.

## Permissions + Vault

MCMA now implements encrypted access control:

~~~text
memory://access/permissions
memory://access/vault
~~~

Permission decisions use actor + action + resource and default to deny.

The vault uses the dedicated MCMA vault container and HKDF key context. Raw vault reads are blocked from the ordinary memory API and there is no CLI command that prints a vault secret.

## CLI

Core:

~~~text
mcma init/open/info
mcma write/update/read
mcma temperature/list/tree/verify
mcma storage-copy
mcma migrate
mcma key-export/key-import
~~~

Security:

~~~text
mcma access-init
mcma access-check
mcma permissions-show
mcma permissions-set
mcma vault-put
mcma vault-list
mcma vault-delete
~~~

Memory commands accept --actor=... and default to owner.

## Repository

~~~text
spec/1.0/
packages/core/
apps/cli/
tests/
docs/
reference/compatibility/
~~~

## License

MIT License.
