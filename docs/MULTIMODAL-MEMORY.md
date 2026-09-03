# MCMA Multimodal Memory Roadmap

Date: 2026-09-03

Status: **Design and roadmap. Text memory, canonical versioning, Knowledge synchronization and semantic refresh are implemented. Binary media ingestion is not implemented yet.**

## Purpose

MCMA is evolving from a text-first cognitive archive into a **user-owned multimodal memory library**.

The goal is not merely to store files. A user should be able to remember a photo, document, recording or video by describing what they remember about it, then retrieve the original encrypted asset and its derived knowledge without knowing its filename, storage provider or physical object key.

Examples:

~~~text
Muéstrame la foto donde estoy pintando la iglesia.
Busca el PDF del amparo que subí.
¿Dónde está el audio donde hablamos de MCMA?
Enséñame el video donde explicamos el respaldo de EC2.
~~~

MCMA must preserve the same invariants already used for text memory:

- canonical source stored once;
- encrypted user-owned content;
- stable logical identity;
- actor-aware permissions;
- derived indexes that can be rebuilt;
- semantic retrieval without bypassing filters;
- provenance and validation;
- versioned updates;
- provider-independent storage and AI connectors.

## Current memory-update foundation

The text-memory foundation needed by multimodal memory already exists.

~~~text
memory://user/...
       ↓
canonical object
       ↓
revision N
       ↓
Knowledge mirror
       ↓
embedding
       ↓
semantic index
~~~

After a canonical memory update, MCMA now versions the same object, preserves object_id, increments revision, links previous_storage_hash, preserves or creates a stable retrieval question, synchronizes verified Knowledge and refreshes semantic indexing when embeddings are configured.

Legacy memories without retrieval metadata gain a stable retrieval intent during their next versioned update.

### Contextual mutation safety

A phrase such as:

~~~text
Actualiza ese conocimiento con: ...
~~~

must not mean "update the last memory I happened to read."

When several canonical memories have recently been recalled, MCMA compares the proposed update against recent actor-visible canonical references. It considers logical reference, title, retrieval question, category path and canonical content.

A strong unique match may be versioned. An ambiguous update must not modify any memory.

Example:

~~~text
recall mailit.click
recall fechas de nacimiento
update with index.php, pvisit, /var/www/, analytics...
~~~

The update belongs to the mailit.click memory, not to the newer birth-date recall.

### Correcting a memory changed incorrectly

Because canonical updates are versioned, a bad update does not need to destroy history.

Safe workflow:

1. open Biblioteca;
2. locate the exact canonical memory://user/... object;
3. inspect its current content and revision chain;
4. explicitly identify that canonical reference when correcting it;
5. replace its current content with the complete corrected information;
6. let MCMA create the next revision and refresh Knowledge + semantic indexing.

A future web enhancement should add **Actualizar en Chat** from the library object detail. That action must bind the exact canonical reference to the composer. Recency must never override an explicitly selected object.

## Multimodal canonical asset model

Binary media must follow the same canonical/derived separation as text memory.

Future logical identities may look like:

~~~text
memory://assets/images/<asset-id>
memory://assets/documents/<asset-id>
memory://assets/audio/<asset-id>
memory://assets/video/<asset-id>
~~~

The exact taxonomy may evolve before implementation, but the architectural rule is fixed:

> The original user file is canonical. OCR, captions, transcripts, thumbnails, labels, summaries and embeddings are derived artifacts.

A conceptual asset manifest should include:

- stable asset_id;
- MIME/media type;
- original filename;
- size;
- content hash;
- capture/upload timestamps;
- category path;
- cognitive layer, scope, temperature and maturity;
- canonical encrypted-byte reference;
- derived preview/description/transcript/semantic references.

The manifest must not expose provider credentials or unauthorized physical storage locations.

## Encrypted binary storage

~~~text
user file
   ↓
content hash
   ↓
MCMA encryption
   ↓
encrypted binary object
   ↓
StorageAdapter
   ├── Local
   ├── S3
   ├── WebDAV
   ├── NAS
   ├── GitHub where appropriate
   └── other providers
~~~

The provider should not need to understand the user's media.

Large binary assets should use streaming/chunked encryption rather than requiring the full plaintext file in memory.

## Derived multimodal understanding

Derived analysis is optional and rebuildable.

### Images

Possible derived data:

- thumbnail/preview;
- natural-language description;
- detected objects/scenes/concepts;
- OCR text;
- user labels;
- project/person/entity relations;
- visual or multimodal embedding;
- confidence and provenance.

Optional AWS connectors may include Amazon Rekognition for image/video labels and concepts, Amazon Textract for photographed/scanned documents, and multimodal Amazon Bedrock models for richer description or visual question answering.

AI-generated descriptions remain observations until user confirmation makes them trusted personal knowledge.

### PDFs and documents

A PDF remains canonical. Derived data may include:

- extracted text;
- page boundaries;
- tables;
- forms/key-value pairs;
- document layout;
- page previews;
- semantic chunks;
- summaries;
- entities/topics/projects;
- page-level provenance.

Amazon Textract is a suitable optional AWS connector for OCR, handwriting, forms, tables and layout. Multimodal Bedrock models may optionally provide higher-level analysis.

The extracted text is a derived index, never a replacement for the original PDF.

### Audio

Audio remains canonical. Derived data may include:

- speech transcription;
- timestamps;
- speaker segmentation when available;
- language;
- topics/entities;
- summary;
- semantic chunks linked to exact time ranges.

Amazon Transcribe is a suitable optional AWS connector for recorded or streaming speech-to-text.

### Video

Video remains canonical. Derived data may combine:

- audio transcription;
- scene/keyframe extraction;
- objects/actions/concepts;
- timestamps;
- visual descriptions;
- summaries;
- multimodal embeddings.

Optional AWS connectors may include Amazon Transcribe for speech, Amazon Rekognition Video for visual analysis and multimodal Bedrock services/models for richer interpretation.

Time-scoped derived chunks must point back to the same canonical video instead of duplicating a giant transcript into multiple folders.

## Semantic retrieval across modalities

~~~text
user query
   ↓
intent / query embedding
   ↓
actor-visible derived indexes
   ├── text Knowledge
   ├── image descriptions/embeddings
   ├── document chunks
   ├── audio transcript chunks
   └── video transcript/visual chunks
   ↓
permissions + validation + confidence + freshness
   ↓
ranked canonical assets
   ↓
chat cards / previews
~~~

The user should not need to know whether a match came from OCR, transcript text, labels or a multimodal embedding.

Similarity must never bypass user isolation, permissions, explicit denies, retracted/disputed state, provenance, confidence/freshness or frozen/never-direct policy.

Derived vectors remain replaceable caches.

## Chat experience

Future chat answers may return media memory cards.

### Images

Show one or more authorized thumbnails first. Clicking/tapping opens the canonical asset detail with full media, description, date/category/project, provenance and related memories.

The user may browse visual matches before asking the AI for more interpretation.

### PDF

Show title/filename, page count, matching page/chunk, a short excerpt and an Open/View action.

### Audio/video

Show title, duration, matching timestamp, a short transcript excerpt and a Play/Open action.

No permanent public media URL should be required.

## Biblioteca multimodal

Zero-AI file navigation should include:

~~~text
Archivos
├── Imágenes
├── Documentos
│   └── PDF
├── Audio
└── Video
~~~

Additional views should include category, project, date, entities where explicitly supported, source/device, temperature, validation/maturity, recently uploaded and recently recalled.

Browsing metadata and already-generated previews must not require an AI call.

## Ingestion pipeline

~~~text
upload
  ↓
validate MIME / size / policy
  ↓
hash
  ↓
encrypt canonical bytes
  ↓
persist through StorageAdapter
  ↓
write canonical asset manifest
  ↓
basic local metadata
  ↓
optional analysis
  ├── OCR
  ├── transcription
  ├── vision labels/caption
  ├── preview/transcode
  └── multimodal embedding
  ↓
derived Knowledge/index objects
  ↓
Biblioteca + Chat retrieval
~~~

The canonical upload must become durable before optional analysis is considered complete.

Analysis jobs need explicit state:

~~~text
pending
processing
complete
partial
failed
~~~

A failed OCR/transcription/vision job must never make the original file disappear.

## Provider-neutral interfaces

AWS may be the first hosted implementation, but MCMA must describe capabilities rather than depend on AWS names.

Possible future interfaces:

~~~text
DocumentAnalysisProvider
SpeechToTextProvider
VisionAnalysisProvider
MultimodalEmbeddingProvider
PreviewProvider
~~~

Moving from S3 to another StorageAdapter must not change canonical asset identity.

## Privacy and security

Multimodal memory increases sensitivity. Required properties include:

- original files encrypted at rest under MCMA control;
- no permanent public media URLs;
- authenticated and authorized retrieval;
- short-lived signed/streamed delivery where needed;
- per-user isolation;
- file size/type policy;
- metadata minimization;
- provider analysis visible in provenance;
- ability to disable cloud AI processing;
- Vault remains separate from ordinary assets.

## Open-source and hosted commercial model

MCMA-OpenMemory remains MIT-licensed and usable as a self-hosted project.

A hosted MCMA service may charge for real operating resources while preserving portable user ownership.

Possible hosted billing dimensions:

- stored GB-month;
- media analysis jobs;
- OCR pages;
- transcription minutes;
- image/video analysis;
- multimodal embedding usage;
- preview/transcode processing;
- outbound transfer/egress;
- premium retention/backup policies.

The user must be able to distinguish:

~~~text
MCMA software        free/open source
self-hosted storage  chosen by the user
hosted MCMA service  optional commercial service
AI/media processing  metered by provider/plan
~~~

### GitHub

The GitHub StorageAdapter remains useful for portable memory and smaller files where repository storage is appropriate.

MCMA must not assume GitHub is the correct backend for large photo/audio/video libraries. Large assets should be able to live in S3, WebDAV, Local/NAS or another suitable adapter while keeping the same logical MCMA organization.

## Implementation phases

### M1 — Asset foundation

- [ ] canonical asset manifest schema
- [ ] encrypted binary/chunk storage abstraction
- [ ] content hash + MIME + size metadata
- [ ] asset logical references
- [ ] upload API
- [ ] actor-aware download/stream route
- [ ] version/retention rules
- [ ] conformance tests

### M2 — Biblioteca files

- [ ] Images/Documents/Audio/Video shelves
- [ ] category/project/date filters
- [ ] file detail panel
- [ ] zero-AI metadata browsing
- [ ] authorized previews
- [ ] Actualizar en Chat exact-ref binding

### M3 — Images

- [ ] thumbnail pipeline
- [ ] VisionAnalysisProvider
- [ ] AWS Rekognition connector
- [ ] optional Bedrock multimodal description
- [ ] image semantic retrieval
- [ ] visual result cards in Chat

### M4 — PDF/documents

- [ ] PDF upload/view
- [ ] DocumentAnalysisProvider
- [ ] Amazon Textract connector
- [ ] page/chunk provenance
- [ ] document semantic retrieval
- [ ] matching-page result cards

### M5 — Audio

- [ ] audio player
- [ ] SpeechToTextProvider
- [ ] Amazon Transcribe connector
- [ ] timestamped transcript chunks
- [ ] semantic retrieval by spoken content

### M6 — Video

- [ ] video player
- [ ] keyframe/scene pipeline
- [ ] transcription
- [ ] video vision connector
- [ ] timestamped multimodal retrieval
- [ ] video result cards

### M7 — Hosted storage billing

- [ ] storage usage ledger
- [ ] GB-month accounting
- [ ] media-analysis usage components
- [ ] plan quotas for storage/files/processing
- [ ] egress accounting where applicable
- [ ] user export/migration UX
- [ ] hosted pricing without changing the open-source core

## Non-goals

Multimodal MCMA must not become:

- a database-required DAM system;
- an AWS-only file manager;
- an unencrypted public gallery;
- a place where generated captions silently replace originals;
- a duplicate transcript per topic folder;
- a semantic index that becomes the canonical source;
- a billing lock-in mechanism that prevents export.

> **Intelligence can change. Memory belongs to the person — including the person's files, images, documents, recordings and videos.**
