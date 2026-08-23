# Roadmap

MCMA-OpenMemory is being developed from a working encrypted-memory prototype toward an open-source, provider-independent memory architecture.

## v0.1 — Public foundation

- [x] Define project philosophy and provider independence
- [x] State open-source, user-owned memory goals
- [x] Preserve existing `mcma-v2` envelope compatibility
- [x] Document AES-256-GCM + HKDF-SHA256 reference format
- [x] Define Hot / Warm / Cold / Frozen lifecycle
- [x] Define initial cognitive memory taxonomy
- [x] Document secure Linux/PHP-FPM secret handling
- [x] Publish PHP crypto reference implementation
- [x] Document encrypted root catalog / sharded index design
- [x] Document remembered-knowledge reuse with confidence and validation metadata
- [x] Document portable continuity across compatible model/storage providers
- [ ] Define machine-readable JSON Schema for `.mcma`
- [ ] Add conformance test vectors using synthetic keys/data

## v0.2 — Storage abstraction

- [ ] Define stable storage adapter interface
- [ ] Local filesystem adapter
- [ ] Git-backed adapter
- [ ] S3-compatible adapter
- [ ] MinIO adapter
- [ ] WebDAV adapter
- [ ] Import/export command
- [ ] Export/import encrypted catalogs together with memory objects
- [ ] Provider migration tests preserving envelope bytes

## v0.3 — Addressing and encrypted indexes

- [ ] Formal `mcma://` URI grammar
- [ ] Implement deterministic encrypted root catalog
- [ ] Implement encrypted sharded memory catalogs
- [ ] Opaque memory identifiers
- [ ] Lexical index
- [ ] Semantic/vector index interface
- [ ] RAG chunk mapping
- [ ] Rebuildable index rules
- [ ] Independent index key-derivation context
- [ ] Minimum-shard retrieval tests

## v0.4 — Memory lifecycle engine

- [ ] Explicit temperature transition rules
- [ ] Activity/recency scoring
- [ ] Hot → Warm → Cold → Frozen policies
- [ ] Reactivation policies
- [ ] Consolidation and summarization hooks
- [ ] Conflict/version handling
- [ ] Per-scope capture policies

## v0.5 — Knowledge reuse engine

- [ ] Durable capture of approved/relevant consultation results
- [ ] Question/intent fingerprints for memory matching
- [ ] Confidence metadata
- [ ] Validation states: unverified / plausible / supported / verified / disputed / retracted
- [ ] Provenance/source references
- [ ] Freshness and expiration policies
- [ ] Direct memory answer path with no LLM call when policy allows
- [ ] Automatic revalidation path for stale/dynamic memories
- [ ] Successor/contradiction relationships instead of silent overwrite
- [ ] Tests proving model-call bypass for validated remembered answers

## v0.6 — Agent-managed memory

- [ ] Agent routing API
- [ ] Memory write proposals
- [ ] Human approval policies
- [ ] Agent-driven classification
- [ ] Retrieval budgets
- [ ] Provenance and traceability
- [ ] Multi-agent memory coordination
- [ ] Agent-managed confidence updates from working tests/evidence

## v0.7 — Portable continuity

- [ ] Complete encrypted memory + index export bundle
- [ ] Import into a different storage adapter
- [ ] Compatibility profile for different AI/agent systems
- [ ] Portable preferences and project context
- [ ] Portable procedural knowledge
- [ ] Portable user-defined behavioral/persona context
- [ ] Cross-model continuity tests
- [ ] Explicit documentation that model behavior may differ even with identical memory

## Future

Potential future work includes:

- GCS and Azure adapters
- KMS/HSM integrations
- multi-user key separation
- tenant-aware memory stores
- signed envelopes/manifests
- distributed synchronization
- snapshots and recovery
- algorithm/key rotation for very long-lived memory
- cross-model interoperability
- reference SDKs in additional languages
- offline/local-first memory consumers
- storage-provider escape/migration tooling

The roadmap intentionally separates working implementation from future research. Features remain unchecked until implemented and tested.
