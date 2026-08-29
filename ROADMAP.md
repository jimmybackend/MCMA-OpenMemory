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

## Semantic retrieval — incremental/top-K implementation complete
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
- [x] incremental single-record index upsert
- [x] incremental semantic entry removal hook
- [x] Librarian-triggered semantic refresh when configured
- [x] Top-K actor-visible candidate inspection
- [x] deterministic local reranking
- [x] reusable candidates prioritized over revalidate/reject candidates

## Ask orchestration — first implementation complete
- [x] provider-neutral GenerationProvider
- [x] exact reusable memory before any model call
- [x] semantic reusable memory after exact miss
- [x] optional generation fallback
- [x] generated answer capture through Librarian
- [x] generated knowledge defaults to unverified / 0.5 confidence
- [x] existing non-reusable exact records are preserved, not overwritten
- [x] Amazon Bedrock Converse generation connector
- [x] Bedrock bearer and SigV4 generation authentication
- [x] mcma ask CLI

## Local AI — first implementation complete
- [x] Ollama local embedding provider
- [x] Ollama local generation provider
- [x] llama.cpp local embedding provider
- [x] llama.cpp local generation provider
- [x] Ollama /api/embed and /api/chat integration
- [x] llama.cpp /v1/embeddings and /v1/chat/completions integration
- [x] mcma ask selectable with Ollama or llama.cpp
- [x] semantic commands selectable with Ollama or llama.cpp
- [x] provider-specific embedding index identity
- [x] configurable embedding compatibility fingerprint/prefix for llama.cpp
- [x] no Bedrock credentials required for local mode

## Next
- [ ] real EC2 + Bedrock end-to-end smoke test
- [ ] real EC2 + Ollama end-to-end smoke test
- [ ] real EC2 + llama.cpp end-to-end smoke test
- [ ] additional provider AI connectors outside core
- [ ] relation graph
- [ ] automatic maturity and temperature policies
