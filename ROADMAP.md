# MCMA 1.0 Roadmap

## Foundation — complete

- [x] Modular Cognitive Memory Archive
- [x] user-owned memory
- [x] file-first/database-free core
- [x] provider-independent architecture
- [x] AI-optional operation
- [x] encrypted indexes and virtual temperature views

## Identity/container contract — complete

- [x] manifest.mcma
- [x] stable library_id and object_id
- [x] SHA-256 storage_hash
- [x] AES-256-GCM + HKDF-SHA256
- [x] RFC 8785 canonical AAD
- [x] memory:// addressing
- [x] schemas and conformance vector

## Manual core and hardening — complete

- [x] init/open/info/write/read/verify/list/tree
- [x] update preserving object_id
- [x] temperature transitions preserving object_id
- [x] encrypted revisions
- [x] recovery export/import
- [x] historical V1/V2 migration
- [x] local concurrency protection

## Storage abstraction — Local + GitHub + S3 complete

- [x] stable StorageAdapter interface
- [x] refactor core away from direct filesystem access
- [x] LocalFilesystemAdapter
- [x] GitHubStorageAdapter
- [x] GitHub optimistic manifest CAS
- [x] S3StorageAdapter
- [x] AWS Signature Version 4
- [x] S3 If-None-Match create-only writes
- [x] S3 If-Match manifest CAS
- [x] S3 prefix listing
- [x] custom S3-compatible endpoint/path-style support
- [x] storage-copy command
- [x] Local ↔ S3 byte-preservation tests
- [ ] WebDAV adapter

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
