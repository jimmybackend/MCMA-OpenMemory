# MCMA — Foundational Vision

## Name

**MCMA — Modular Cognitive Memory Archive**

En español: **Archivo Modular de Memoria Cognitiva**.

## Principle

> **Intelligence can change. Memory belongs to the person.**
> **La inteligencia puede cambiar. La memoria pertenece a la persona.**

MCMA is intended to be an open, portable, encrypted and document-oriented memory format that belongs to the user rather than to a model provider, cloud provider, application or database engine.

The AI is a replaceable consumer of authorized memory. The storage provider is a replaceable byte store. The user owns the memory.

## What MCMA is

MCMA is designed as:

- file-first;
- database-free at its core;
- portable between devices and storage providers;
- independent of a specific AI provider;
- usable with or without AI;
- encrypted by default for private content;
- permission-aware;
- self-describing;
- document-oriented and extensible;
- suitable for long-lived personal memory.

A library may live on USB media, a local filesystem, a phone or tablet, a desktop computer, a NAS, a server, S3-compatible storage, WebDAV or future adapters.

## Separation of responsibilities

~~~text
MCMA Library
    │ owns memory
    ▼
MCMA Client
    │ locates, authenticates, decrypts, reads and writes
    ▼
Optional AI / Agents
      interpret authorized context
~~~

The library must continue to exist and remain usable when the AI changes or is absent.

## User-owned continuity

A user should be able to move the same encrypted library:

~~~text
USB → laptop → mobile → S3 → NAS → another provider
~~~

without changing the meaning or ownership of the memory.

## Document-first identity

MCMA does not attempt to describe a human being through rigid relational tables.

A person is represented by evolving documents such as:

~~~text
identity/profile.mcma
identity/preferences.mcma
identity/relationships.mcma
identity/important-dates.mcma
identity/professional.mcma
identity/communication.mcma
~~~

These documents can gain new fields, sections, references and free-form knowledge over time without database migrations.

## Information becomes knowledge

Proposed knowledge maturity states:

~~~text
raw
  ↓
observed
  ↓
classified
  ↓
knowledge
  ↓
confirmed
~~~

A casual statement must not automatically become a permanent truth about a person.

Provenance, confidence and confirmation should remain available so a compatible client can distinguish a user-confirmed fact from an AI inference.

## Memory temperature

MCMA uses four relevance states:

~~~text
HOT
WARM
COLD
FROZEN
~~~

Temperature describes cognitive activity, not privacy.

A FROZEN memory may become HOT again when it becomes relevant.

## Privacy is independent

A memory can be HOT + PRIVATE, FROZEN + PUBLIC or WARM + SECRET.

Temperature, knowledge maturity and access policy are independent dimensions.

## Normal and private libraries

### Normal library

Human-readable names and logical categories may remain visible.

### Private library

Opaque names, hash-based object locations and encrypted indexes can hide semantic meaning that would otherwise leak through filenames or directories.

## AI is optional

Essential operations must be possible without AI, including:

~~~text
mcma tree
mcma list
mcma info
mcma read
mcma verify
mcma export
~~~

AI adds interpretation and memory management; it is not required for the existence of the archive.
