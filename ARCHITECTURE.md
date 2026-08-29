# MCMA 1.0 Architecture

~~~text
Optional AI / UI / Tools
          ↓
   Agent Boundaries
   ├── Librarian
   └── SecurityAgent
          ↓
 Permission + Knowledge Gates
          ↓
       MCMA Core
   ┌──────┼────────┐
 Memory  Crypto   Index
   └──────┼────────┘
          ↓
    StorageAdapter
   ├── Local
   ├── GitHub
   ├── S3-compatible
   └── WebDAV
~~~

## Knowledge route

~~~text
question
  ↓
exact intent normalization
  ↓
knowledge logical ref
  ↓
Permission Engine
  ↓
KnowledgeRecord assessment
  ├── validation
  ├── confidence
  ├── freshness
  └── reuse policy
  ↓
reuse | revalidate | reject | miss
~~~

The current route requires exact normalized intent. Semantic candidate retrieval is intentionally a later layer.

## Agent boundaries

Librarian is a deterministic wrapper for capture, validation and recall.

SecurityAgent is a deterministic wrapper for authorization and trusted vault usage.

Neither requires a model provider.

## Security invariants

A model-facing client must not bypass Permission Engine, freshness/revalidation decisions, disputed/retracted rejection or the vault secret-use boundary.

## Storage

Physical provider paths do not define identity. Provider migration copies exact encrypted bytes and preserves object_id/storage_hash.
