# MCMA — Current Design State

Date: 2026-08-29

Status: **Architecture definition and prototype consolidation.**

## Name

**MCMA — Modular Cognitive Memory Archive**

## Existing implementation baseline

The repository already contains:

- an experimental mcma-v2 encrypted envelope;
- AES-256-GCM reference encryption;
- HKDF-SHA256 key derivation;
- PHP reference implementation;
- Hot / Warm / Cold / Frozen lifecycle concepts;
- cognitive memory taxonomy;
- encrypted-index design;
- remembered-knowledge reuse design;
- storage-adapter design;
- EC2/PHP-FPM deployment documentation;
- a manual PHP memory reader prototype.

## Newly adopted architectural direction

### User-owned portable library

MCMA is intended to be copied and used from local storage, removable media, mobile devices, servers or cloud storage without requiring a database server.

### With AI or without AI

Opening MCMA should lead to a first-run choice between AI-assisted operation, manual/non-AI reading and management, or configuration.

### Living identity documents

profile.mcma becomes the current consolidated living profile.

Related domains can grow as separate documents.

History remains separate so the current profile does not become an unlimited event log.

### Knowledge maturity

~~~text
raw
observed
classified
knowledge
confirmed
~~~

### Independent permissions

Temperature and access are separate dimensions.

### vault.mcma

Adopted as the protected boundary for highly sensitive secrets or secret references.

Raw vault content is never normal LLM context.

### Normal/private library modes

Normal mode may expose meaningful names.

Private mode may use opaque IDs and encrypted indexes to reduce metadata leakage.

### Stable IDs and hashes

Long-lived objects should use stable identity independent of the current display tree.

Hash-based storage and hierarchical indexes are preferred for scale.

### Virtual views

HOT / WARM / COLD / FROZEN should evolve toward index-generated views rather than forcing physical moves of encrypted objects.

## Compatibility issue to solve

Current mcma-v2 binds logical_path + filename into key derivation and AES-GCM AAD.

The future stable-object/view model should not be retrofitted invisibly.

A next format revision must define stable object ID, canonical addressing, object hash, index mapping, new AAD identity rules and migration from mcma-v2.

## Next specification work

1. Define manifest.mcma.
2. Define next object envelope.
3. Define stable object IDs.
4. Define canonical hash rules.
5. Define hierarchical index format.
6. Define memory:// URI resolution.
7. Define profile.mcma.
8. Define permission schema.
9. Define vault.mcma.
10. Define history objects.
11. Define knowledge maturity metadata.
12. Define temperature transition policy.
13. Define recovery/key rotation.
14. Define portable storage discovery.
15. Define first CLI conformance behavior.

## First implementation milestone

A minimal implementation should work without AI:

~~~text
mcma init
mcma open
mcma tree
mcma list
mcma info
mcma read
mcma write
mcma verify
~~~

AI support should be added after the portable manual core is dependable.

## Foundational principle

> **Intelligence can change. Memory belongs to the person.**
