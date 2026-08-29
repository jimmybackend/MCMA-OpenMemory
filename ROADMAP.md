# MCMA 1.0 Roadmap

MCMA-OpenMemory now follows one active development line: **MCMA 1.0**.

Historical prototype envelope identifiers are preserved only for compatibility.

## Foundation — complete

- [x] Define MCMA as Modular Cognitive Memory Archive
- [x] Adopt user-owned memory principle
- [x] Adopt file-first/database-free core
- [x] Adopt provider-independent storage
- [x] Adopt AI-optional operation
- [x] Adopt living `profile.mcma`
- [x] Adopt knowledge maturity states
- [x] Adopt independent permissions
- [x] Adopt `vault.mcma` security boundary
- [x] Adopt normal/private metadata modes
- [x] Adopt stable-object direction
- [x] Adopt hierarchical indexes
- [x] Adopt virtual HOT/WARM/COLD/FROZEN views
- [x] Preserve working prototype compatibility code separately

## Next block — MCMA 1.0 identity and container contract

- [ ] Define `manifest.mcma`
- [ ] Define library ID
- [ ] Define stable object ID
- [ ] Define canonical object hash
- [ ] Freeze MCMA 1.0 envelope/header
- [ ] Define KDF identity and AES-GCM AAD
- [ ] Define authenticated metadata
- [ ] Define `memory://` URI resolution
- [ ] Define migration from historical prototype envelopes
- [ ] Add machine-readable schemas
- [ ] Add synthetic conformance test vectors

## Manual portable core

- [ ] `mcma init`
- [ ] `mcma open`
- [ ] `mcma tree`
- [ ] `mcma list`
- [ ] `mcma info`
- [ ] `mcma read`
- [ ] `mcma write`
- [ ] `mcma verify`
- [ ] Local filesystem adapter
- [ ] Tests without AI or database

## Storage abstraction

- [ ] Stable adapter interface
- [ ] Git-backed adapter
- [ ] S3-compatible adapter
- [ ] WebDAV adapter
- [ ] Import/export
- [ ] Provider migration tests

## Index and lifecycle

- [ ] Encrypted root catalog
- [ ] Encrypted index shards
- [ ] Opaque physical object names
- [ ] Lexical index
- [ ] Semantic/vector interface
- [ ] HOT/WARM/COLD/FROZEN transition policy
- [ ] Reactivation policy
- [ ] Rebuildable-index rules

## Identity, permissions and security

- [ ] Profile schema
- [ ] History schema
- [ ] Permission schema
- [ ] Vault schema
- [ ] Recovery design
- [ ] Key rotation design
- [ ] Device authorization model

## Knowledge and AI

- [ ] Knowledge provenance
- [ ] Confidence and validation metadata
- [ ] Reuse/freshness policy
- [ ] Direct memory answer path
- [ ] Librarian/security agent boundaries
- [ ] Optional `mcma ask`

## Long-term

- additional storage adapters;
- KMS/HSM integrations;
- desktop/mobile clients;
- snapshots and recovery;
- algorithm migration;
- signed manifests/objects where required;
- cross-model continuity tests.

Features remain unchecked until implemented and tested.
