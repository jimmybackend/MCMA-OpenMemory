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
- [x] schemas and conformance vectors

## Manual core and hardening — complete

- [x] init/open/info/write/read/verify/list/tree
- [x] update preserving object_id
- [x] temperature transitions preserving object_id
- [x] encrypted revisions
- [x] recovery export/import
- [x] historical V1/V2 migration

## Storage abstraction — complete

- [x] stable StorageAdapter interface
- [x] LocalFilesystemAdapter
- [x] GitHubStorageAdapter
- [x] S3StorageAdapter
- [x] WebDavStorageAdapter
- [x] provider-specific CAS/write coordination
- [x] provider-neutral storage-copy
- [x] byte-preserving migration tests

## Permissions and Vault — first implementation complete

- [x] actor/action/resource Permission Engine
- [x] deny-by-default policy
- [x] encrypted memory://access/permissions
- [x] owner anti-lockout invariant
- [x] actor-aware read/write/update/temperature/list/tree
- [x] encrypted vault container
- [x] vault-specific HKDF context
- [x] metadata-only vault listing
- [x] internal useVaultSecret boundary
- [x] CLI without raw-secret retrieval
- [x] first encrypted recovery bundle
- [ ] independent vault unlock factor / hardware-backed release
- [ ] key rotation
- [ ] device authorization

## Knowledge and AI — next

- [ ] provenance engine
- [ ] confidence/validation policies
- [ ] reuse/freshness policy
- [ ] direct memory answer path
- [ ] librarian/security agents
- [ ] optional mcma ask
