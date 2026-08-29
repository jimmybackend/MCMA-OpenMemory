# MCMA 1.0 Specification

Status: **experimental specification; identity/container baseline implemented locally**

Normative files include PRINCIPLES.md, IDENTITY.md, MANIFEST.md, CONTAINER.md, CRYPTOGRAPHY.md, ADDRESSING.md, INDEX.md, MIGRATION.md, RECOVERY.md and CONFORMANCE.md.

## Stable revisions

~~~text
object_id stays stable
storage_hash changes per revision
~~~

The local implementation preserves the previous revision hash inside encrypted object metadata.

## Compatibility

Existing historical encrypted memories are never edited or relabeled in place. Migration authenticates/decrypts them with their original rules and writes a new MCMA 1.0 object.

## Implementation status

The PHP local core implements update/revision handling, temperature transitions, locking, recovery backup and historical V1/V2 migration.

The next boundary is the provider-neutral Storage Adapter interface.
