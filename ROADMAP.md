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

## Storage abstraction — complete

- [x] stable StorageAdapter interface
- [x] LocalFilesystemAdapter
- [x] GitHubStorageAdapter
- [x] S3StorageAdapter
- [x] WebDavStorageAdapter
- [x] Local/GitHub/S3/WebDAV compare-and-swap strategy
- [x] provider-neutral storage-copy
- [x] byte-preserving provider migration tests

## Identity, permissions and security — next/active

- [ ] permission policy engine
- [ ] actor/action/resource enforcement
- [ ] encrypted permissions object
- [ ] vault container implementation
- [ ] vault metadata-only listing
- [ ] internal use_secret boundary
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
