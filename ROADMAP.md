# MCMA 1.0 Roadmap

## Foundation — complete
- [x] user-owned, file-first, provider-independent memory
- [x] AI-optional operation
- [x] encrypted indexes and temperature views

## Identity/container — complete
- [x] manifest, stable library_id/object_id
- [x] storage_hash
- [x] AES-256-GCM + HKDF-SHA256
- [x] memory:// addressing
- [x] schemas/conformance

## Manual core — complete
- [x] init/open/info/write/read/verify/list/tree
- [x] update/temperature preserving object_id
- [x] recovery
- [x] historical V1/V2 migration

## Storage — complete
- [x] Local
- [x] GitHub
- [x] S3-compatible
- [x] WebDAV
- [x] CAS/write coordination
- [x] byte-preserving provider migration

## Permissions + Vault — first implementation complete
- [x] actor/action/resource engine
- [x] deny-by-default
- [x] encrypted permissions
- [x] owner anti-lockout
- [x] actor-aware memory operations
- [x] vault container/key context
- [x] metadata-only vault listing
- [x] useVaultSecret boundary
- [ ] independent vault unlock / hardware release
- [ ] key rotation
- [ ] device authorization

## Knowledge reuse — first implementation complete
- [x] deterministic exact-intent key
- [x] memory://knowledge namespace
- [x] provenance
- [x] confidence
- [x] validation states/history
- [x] freshness + max age
- [x] reuse policies
- [x] current-data revalidation gate
- [x] direct memory answer path
- [x] correction preserving object_id
- [x] Librarian deterministic boundary
- [x] SecurityAgent deterministic boundary

## Next
- [ ] semantic candidate retrieval for paraphrases
- [ ] optional local/vector index
- [ ] candidate ranking without weakening epistemic gates
- [ ] optional mcma ask orchestration
- [ ] provider AI connectors outside core
