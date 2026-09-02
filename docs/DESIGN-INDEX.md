# MCMA Design Documentation Index

## Active MCMA 1.0 specification

- ../SPECIFICATION.md — active working specification entry point.
- ../spec/1.0/README.md — scope and status of MCMA 1.0.
- ../spec/1.0/PRINCIPLES.md — architectural invariants.
- ../spec/1.0/CONTAINER.md — active container/identity design work.
- ../SECURITY.md — security model.

## Foundational design

- FOUNDATIONAL-VISION.md — project purpose, name and ownership model.
- CURRENT-STATE.md — current implementation/design boundary.
- REPOSITORY-STRUCTURE.md — real and incremental repository layout.

## User and application flow

- FIRST-RUN-FLOW.md — create/open library and use it with or without AI.
- MULTIUSER.md — encrypted multi-user registry and isolated per-user libraries.
- WEB.md — OIDC web application, API-key access and HTTP routes.
- BILLING.md — metering, credits, plans, SuperAdmin and Stripe Checkout/subscriptions.
- ASK.md — memory-first ask orchestration.
- IDENTITY-PROFILE.md — living user profile and historical evolution.
- PERMISSIONS-VAULT.md — independent permissions and vault security boundary.

## Retrieval and memory model

- MEMORY-TAXONOMY.md — cognitive memory classification.
- ENCRYPTED-INDEX.md — encrypted catalogs, semantic/derived indexing and the private conversation index used by persistent Chat.
- KNOWLEDGE-REUSE.md — memory-first answer reuse.
- STORAGE-ADAPTERS.md — provider-independent storage contract.
- STORAGE-PROVIDERS.md — implemented Local/GitHub/S3/GCS/Drive/Azure/OSS/WebDAV providers.
- LOCAL-AI.md — local provider architecture.
- LLAMACPP.md — llama.cpp connector.
- PHP-OOP.md — production OOP boundary.

## Compatibility and history

Historical prototype specification, deployment notes and exact reference PHP code live under:

```text
../reference/compatibility/
```

They remain available to read existing encrypted memories but are not the active MCMA 1.0 specification.

## Planning

- ../ROADMAP.md — implementation order and milestones. It is planning, not a normative interoperability document.
