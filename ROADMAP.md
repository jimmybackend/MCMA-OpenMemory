# MCMA 1.0 Roadmap

## Foundation — complete
- [x] user-owned, file-first, provider-independent memory
- [x] AI-optional operation
- [x] encrypted indexes and temperature views

## Identity/container — complete
- [x] manifest, stable library_id/object_id
- [x] storage_hash
- [x] AES-256-GCM + HKDF-SHA256
- [x] RFC 8785 JCS including IEEE-754 floating-point numbers
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
- [x] provenance
- [x] floating-point confidence
- [x] validation states/history
- [x] freshness + max age
- [x] direct memory answer path
- [x] Librarian and SecurityAgent boundaries

## Semantic retrieval — first implementation complete
- [x] provider-neutral EmbeddingProvider
- [x] encrypted derived semantic index
- [x] float embedding vectors
- [x] cosine ranking
- [x] exact-first / semantic-on-miss routing
- [x] permission-filtered candidates
- [x] validation/confidence/freshness gates after ranking
- [x] storage_hash stale-vector detection
- [x] Amazon Titan Text Embeddings V2 connector
- [x] Bedrock bearer and SigV4 authentication

## Next
- [ ] incremental semantic index updates
- [ ] multiple semantic candidate inspection/reranking
- [ ] optional local embedding provider
- [ ] optional mcma ask orchestration
- [ ] provider AI connectors outside core
