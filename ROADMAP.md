# MCMA 1.0 Roadmap

MCMA-OpenMemory follows one active development line: **MCMA 1.0**.

Historical prototype formats are preserved only for compatibility/migration.

## Foundation — complete

- [x] Define Modular Cognitive Memory Archive
- [x] User-owned memory principle
- [x] File-first/database-free core
- [x] Provider-independent storage
- [x] AI-optional operation
- [x] Living `profile.mcma`
- [x] Knowledge maturity states
- [x] Independent permissions
- [x] `vault.mcma` boundary
- [x] Normal/private metadata modes
- [x] Hierarchical indexes
- [x] Virtual HOT/WARM/COLD/FROZEN views
- [x] Historical compatibility boundary

## MCMA 1.0 identity/container contract — complete

- [x] Define `manifest.mcma`
- [x] Define stable library ID
- [x] Define stable object ID
- [x] Define canonical SHA-256 storage hash
- [x] Freeze first MCMA 1.0 envelope/header baseline
- [x] Define HKDF identity
- [x] Define AES-GCM AAD
- [x] Define authenticated metadata boundary
- [x] Define `memory://` resolution
- [x] Define root/sharded index bootstrap
- [x] Define historical migration
- [x] Add machine-readable schemas
- [x] Add synthetic conformance vector

## Manual portable core — implemented

- [x] `mcma init`
- [x] `mcma open`
- [x] `mcma info`
- [x] `mcma write`
- [x] `mcma read`
- [x] `mcma verify`
- [x] `mcma list`
- [x] `mcma tree`
- [x] Local filesystem adapter
- [x] Pass conformance vector
- [x] Tests without AI/database

## Storage abstraction

- [ ] Stable adapter interface
- [ ] Git-backed adapter
- [ ] S3-compatible adapter
- [ ] WebDAV adapter
- [ ] Import/export
- [ ] Provider migration tests

## Identity, permissions and security

- [ ] Profile/history schemas beyond the base payload
- [ ] Permission enforcement implementation
- [ ] Vault implementation
- [ ] Recovery design
- [ ] Key rotation workflow
- [ ] Device authorization model

## Knowledge and AI

- [ ] Knowledge provenance engine
- [ ] Confidence/validation policies
- [ ] Reuse/freshness policy
- [ ] Direct memory answer path
- [ ] Librarian/security agents
- [ ] Optional `mcma ask`

## Long-term

- additional adapters;
- KMS/HSM integrations;
- desktop/mobile clients;
- snapshots/recovery;
- algorithm migration;
- signed manifests/objects where required;
- cross-model continuity tests.

Features remain unchecked until implemented and tested.
