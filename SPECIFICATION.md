# MCMA 1.0 Working Specification

Status: **Working specification — format contract not yet frozen**

MCMA means **Modular Cognitive Memory Archive**.

> **Intelligence can change. Memory belongs to the person.**

This is the active public specification line. Historical prototype envelope formats are compatibility inputs only and do not define the final MCMA 1.0 object model.

## 1. Core requirements

A conforming MCMA 1.0 library is designed to be:

- file-first;
- usable without a database server;
- portable between storage providers;
- usable with or without AI;
- encrypted for private content;
- document-oriented and extensible;
- independent of a physical storage path;
- permission-aware;
- self-describing.

## 2. Stable identity

An MCMA 1.0 object MUST have a stable identity independent of:

- filename;
- physical directory;
- storage provider;
- memory temperature;
- cognitive category view.

The exact object-ID syntax remains to be frozen.

## 3. Logical references

Human and application-facing references use the `memory://` namespace conceptually, for example:

```text
memory://identity/profile
memory://identity/preferences
memory://projects/example
memory://topics/mcma
```

A logical reference resolves through authorized catalog/index data to a stable object identity and then to its physical storage locator.

## 4. Temperature

Valid relevance states are:

```text
HOT
WARM
COLD
FROZEN
```

Temperature is a mutable logical attribute/view. Changing temperature MUST NOT change permanent object identity.

## 5. Knowledge maturity

MCMA adopts:

```text
raw → observed → classified → knowledge → confirmed
```

Provenance, confidence and confirmation history should be preserved when information becomes durable knowledge.

## 6. Identity documents

`profile.mcma` is the living consolidated profile entry point.

History is separate from the current profile and should be linked through stable references.

## 7. Permissions and vault

Permissions are independent from temperature.

`access/vault.mcma` defines a protected security boundary for secrets, secret references, recovery material and authorizations.

Raw vault contents MUST NOT become normal AI context.

## 8. Container formats

JSON is the recommended initial structured payload format.

MCMA containers are expected to declare their payload type and may support:

```text
json
xml
text
markdown
binary
```

The exact MCMA 1.0 envelope, canonical hash rules, KDF context and authenticated metadata are still being finalized in `spec/1.0/CONTAINER.md`.

## 9. Indexes

Large libraries MUST NOT require a full scan for each lookup.

Indexes should be hierarchical and shardable by dimensions such as:

- hash prefix;
- topic;
- person;
- project;
- date;
- scope;
- cognitive category;
- temperature;
- privacy policy.

Private libraries should be able to encrypt semantic catalog/index contents and use opaque physical names.

## 10. Storage independence

Storage adapters translate MCMA object operations to physical backends.

The core must not require GitHub, S3, WebDAV, a local filesystem or another specific provider.

## 11. Compatibility

Real encrypted objects produced by the working prototype remain valid historical data.

Compatibility code is isolated under `reference/compatibility/`.

MCMA 1.0 migration MUST read historical objects using their original cryptographic rules and create new MCMA 1.0 objects. Historical ciphertext MUST NOT be relabeled as MCMA 1.0 without a real migration.

## 12. Specification structure

Active specification work lives under:

```text
spec/1.0/
```

The next format-definition block must freeze, in this order:

1. library/manifest identity;
2. stable object ID;
3. canonical hash rules;
4. envelope/header;
5. KDF and AAD identity;
6. `memory://` resolution;
7. index bootstrap format;
8. migration behavior;
9. conformance test vectors.

Until those are frozen and tested, MCMA 1.0 remains a working specification.
