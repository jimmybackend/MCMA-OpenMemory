# Roadmap

MCMA-OpenMemory is being developed from a working encrypted-memory prototype toward a provider-independent memory architecture.

## v0.1 — Public foundation

- [x] Define project philosophy and provider independence
- [x] Preserve existing `mcma-v2` envelope compatibility
- [x] Document AES-256-GCM + HKDF-SHA256 reference format
- [x] Define Hot / Warm / Cold / Frozen lifecycle
- [x] Define initial cognitive memory taxonomy
- [x] Document secure Linux/PHP-FPM secret handling
- [x] Publish PHP crypto reference implementation
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
- [ ] Provider migration tests preserving envelope bytes

## v0.3 — Addressing and indexes

- [ ] Formal `mcma://` URI grammar
- [ ] Encrypted memory catalog/index
- [ ] Opaque memory identifiers
- [ ] Lexical index
- [ ] Semantic/vector index interface
- [ ] RAG chunk mapping
- [ ] Rebuildable index rules

## v0.4 — Memory lifecycle engine

- [ ] Explicit temperature transition rules
- [ ] Activity/recency scoring
- [ ] Hot → Warm → Cold → Frozen policies
- [ ] Reactivation policies
- [ ] Consolidation and summarization hooks
- [ ] Conflict/version handling

## v0.5 — Agent-managed memory

- [ ] Agent routing API
- [ ] Memory write proposals
- [ ] Human approval policies
- [ ] Agent-driven classification
- [ ] Retrieval budgets
- [ ] Provenance and traceability
- [ ] Multi-agent memory coordination

## Future

Potential future work includes:

- GCS and Azure adapters
- KMS/HSM integrations
- multi-user key separation
- tenant-aware memory stores
- signed envelopes/manifests
- encrypted metadata/indexes
- distributed synchronization
- snapshots and recovery
- cross-model interoperability
- reference SDKs in additional languages

The roadmap intentionally separates working implementation from future research. Features remain unchecked until implemented and tested.
