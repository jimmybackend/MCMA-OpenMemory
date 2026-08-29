# MCMA 1.0 Roadmap

## Foundation — complete

- [x] Modular Cognitive Memory Archive
- [x] user-owned memory
- [x] file-first/database-free core
- [x] provider-independent architecture
- [x] AI-optional operation
- [x] profile, knowledge maturity, permissions and vault direction
- [x] encrypted indexes and virtual temperature views

## Identity/container contract — complete

- [x] manifest.mcma
- [x] stable library_id
- [x] stable object_id
- [x] SHA-256 storage_hash
- [x] AES-256-GCM + HKDF-SHA256
- [x] RFC 8785 canonical AAD
- [x] memory:// addressing
- [x] root index bootstrap
- [x] schemas and conformance vector

## Manual local core — complete

- [x] init/open/info/write/read/verify/list/tree
- [x] content-addressed local storage
- [x] tests without AI/database

## Local hardening and compatibility — complete

- [x] exclusive write locking
- [x] manifest reload under lock
- [x] mcma update preserving object_id
- [x] encrypted revision chain
- [x] mcma temperature preserving object_id
- [x] encrypted key export/import recovery bundle
- [x] historical mcma-v1 reader
- [x] historical mcma-v2 reader
- [x] mcma migrate
- [x] duplicate historical migration detection
- [x] non-destructive migration tests

## Next — storage abstraction

- [ ] stable Storage Adapter interface
- [ ] refactor local filesystem behind adapter
- [ ] Git-backed adapter
- [ ] S3-compatible adapter
- [ ] WebDAV adapter
- [ ] library export/import
- [ ] provider migration tests preserving exact MCMA bytes

## Identity, permissions and security

- [ ] profile/history schemas beyond base payload
- [ ] permission enforcement
- [ ] vault implementation
- [x] first encrypted recovery bundle
- [ ] key rotation
- [ ] device authorization

## Knowledge and AI

- [ ] provenance engine
- [ ] confidence/validation policies
- [ ] reuse/freshness policy
- [ ] direct memory answer path
- [ ] librarian/security agents
- [ ] optional mcma ask
