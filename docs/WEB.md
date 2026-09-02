# MCMA Web Application

MCMA includes a database-free multi-user web application for Nginx + PHP-FPM.

## Request flow

~~~text
Browser
  ↓ HTTPS
Nginx
  ↓
apps/web/public/index.php
  ↓
OIDC Authorization Code + PKCE
  ↓
encrypted HttpOnly session
  ↓
verified issuer + subject
  ↓
MultiUserService
  ↓
user-specific MCMA Library
  ↓
AskService
  ├── exact memory
  ├── semantic memory
  └── configured generation provider
  ↓
encrypted storage
~~~

The client never supplies a trusted user id, storage location, actor, model or provider.

## Routes

Public:

~~~text
GET /login
GET /callback
GET /mcma/v1/health
GET /
~~~

Authenticated:

~~~text
GET  /mcma/v1/me
POST /mcma/v1/register
POST /mcma/v1/ask
POST /logout
~~~

## Hosting under a base path

When `MCMA_WEB_BASE_PATH` is configured (for example `/mcma`), serve the static UI at the trailing-slash home (`/mcma/`) and route only the dynamic authentication/API endpoints to the PHP front controller. The bundled HTML uses relative asset/auth links so the same files work both at the origin root and under a base path.

For a `/mcma` deployment, the intended split is:

~~~text
/mcma                  -> redirect to /mcma/
/mcma/                 -> apps/web/public/index.html
/mcma/app.css          -> static
/mcma/app.js           -> static
/mcma/admin.html       -> static
/mcma/admin.js         -> static
/mcma/login            -> PHP front controller
/mcma/callback         -> PHP front controller
/mcma/logout           -> PHP front controller
/mcma/v1/*             -> PHP front controller
~~~

Legacy or provider-specific routes under other prefixes should remain in their own more-specific Nginx locations.

## Authentication

The built-in first web authentication profile uses generic OpenID Connect Authorization Code flow with PKCE.

Configuration:

~~~text
MCMA_OIDC_ISSUER
MCMA_OIDC_CLIENT_ID
MCMA_OIDC_CLIENT_SECRET
MCMA_OIDC_SCOPE
~~~

The redirect URI is derived from:

~~~text
MCMA_WEB_PUBLIC_ORIGIN/callback
~~~

ID tokens currently require RS256 and are checked against the provider JWKS.

Validation includes:

- signing algorithm fixed to RS256;
- signing key id;
- RSA key size at least 2048 bits;
- signature;
- issuer;
- audience;
- authorized party when relevant;
- expiration;
- not-before;
- issued-at future skew;
- nonce;
- subject.

## Session

State and session cookies are encrypted with AES-256-GCM using a server-only secret:

~~~text
MCMA_WEB_SESSION_SECRET
~~~

Cookies are:

- Secure;
- HttpOnly;
- Path=/;
- SameSite=Lax for the temporary OIDC state cookie;
- SameSite=Strict for the application session.

The session cookie is opaque and carries the verified issuer/subject only inside authenticated encryption.

When the OIDC provider returns standard `email`, `name` and `picture` claims, MCMA may also keep those whitelisted display claims inside the encrypted session cookie and expose them through `GET /mcma/v1/me` for the account chip. They are not used to derive storage paths and are not copied into the encrypted user registry or user knowledge library.

For Google login, request the standard scopes:

~~~text
MCMA_OIDC_SCOPE='openid email profile'
~~~

## User provisioning

Two modes exist:

~~~text
MCMA_WEB_AUTO_REGISTER=false
MCMA_WEB_SELF_REGISTER=false
~~~

Auto-register creates/resolves the MCMA library automatically after login.

Self-register permits an authenticated identity to call:

~~~text
POST /mcma/v1/register
~~~

If both are false, users must be pre-registered administratively.

## Ask endpoint

Request:

~~~json
{
  "question": "What do you remember about this project?",
  "current": false,
  "remember": true
}
~~~

The browser may only provide those application-level fields.

The response UI also reports the route that actually answered the request:

- **Memoria exacta** — direct reusable memory, normally zero provider tokens;
- **Memoria semántica** — semantic retrieval; embedding tokens may be charged;
- **IA + memoria MCMA** — generation used guarded validated memory context;
- **IA / Nova Micro** — generation provider answered without injected memory context.

When billing is enabled, the UI displays the per-request token and credit totals returned by the billing context.

It cannot override:

- user id;
- actor;
- storage;
- embedding provider;
- generation provider;
- model;
- thresholds owned by the server;
- credentials;
- vault access.


## Explicit user memory capture

MCMA also supports deliberate durable memory that is different from ordinary Q&A capture.

A user can write naturally:

~~~text
Guarda esto: MCMA debe usar valores flotantes para la similitud semántica y no debe saltarse los filtros.
~~~

The authenticated `POST /mcma/v1/ask` route detects explicit save intent before normal exact/semantic/generation routing. Clients that do not want natural-language intent detection can call:

~~~text
POST /mcma/v1/memory
~~~

with:

~~~json
{
  "text": "MCMA debe usar valores flotantes para la similitud semántica."
}
~~~

The explicit-memory flow is:

~~~text
explicit save request
  ↓
source text preserved
  ↓
memory librarian organization/classification
  ↓
canonical classified encrypted object
  ↓
verified knowledge recovery mirror
  ↓
optional encrypted semantic index
  ↓
confirmation with classification + logical route
~~~

The organizer may correct spelling, grammar, punctuation and presentation, but it is instructed not to invent facts or follow instructions embedded inside the material being stored. The storage path is never accepted from model output. The librarian may propose a bounded conceptual `category_path` array such as `["recetas","cocina"]` or `["configuraciones","servidores","mailit.click"]`. MCMA validates and slug-normalizes those folder labels, then derives the canonical `memory://user/<category...>/<title>-<fingerprint>` reference on the server using the normalized thematic hierarchy plus a deterministic source fingerprint.

The canonical object stores both the normalized durable content and the original user-provided memory text. It also stores the thematic category labels/slugs. Its outer MCMA metadata uses the selected cognitive layer, scope, temperature and `confirmed` maturity. The thematic folders are independent from the cognitive layer: for example, a recipe can live under `recetas/cocina` while using `50-procedural`, and a live Nginx configuration can live under `configuraciones/servidores/mailit-click` with dynamic freshness. A separate verified knowledge mirror points back to the canonical object so current exact/semantic retrieval can find the memory without collapsing the canonical taxonomy into `memory://knowledge/q-*`.

Supported organizer dimensions are the MCMA 1.0 taxonomy. For this first explicit-memory API, scope is constrained to `user` or `project`; the model cannot create arbitrary scopes or logical references.

If no generation provider is configured, the provider fails, or its structured result is invalid, MCMA still preserves the source text with a deterministic safe fallback: `40-semantic`, `user`, `hot`, `stable`. The response explicitly reports that fallback instead of pretending model organization succeeded.

A successful response uses route `memory-capture` and includes:

~~~text
answer.value                              human-readable confirmation
logical_ref                               canonical thematic route
storage.classification.category_path      human folder labels
storage.classification.category_slugs     canonical folder segments
storage.classification                    layer/scope/temperature/freshness
storage.retrieval.logical_ref             verified recovery mirror
storage.retrieval.semantic_index          optional semantic entry
~~~

Example user interactions:

~~~text
Guarda la receta de mi abuela: pollo a la Coca...
→ memory://user/recetas/cocina/receta-de-la-abuela-pollo-a-la-coca-cola-...

Guarda esta configuración de nginx del EC2 mailit.click:
server {
    server_name mailit.click www.mailit.click;
    ...
}
→ memory://user/configuraciones/servidores/mailit-click/configuracion-nginx-de-mailit-click-...
~~~

Later, a question such as `¿Tienes guardada configuración de nginx para mailit.click?` is answered through the verified knowledge/semantic mirror and returns the stored configuration while still applying permission, validation, confidence and freshness gates.

When billing is enabled, organizer generation and semantic embedding calls go through the same reservation/metering controls as ordinary Ask traffic. Merely writing encrypted bytes is not counted as AI token usage.

A bare command such as `Guarda esto` with no memory content is rejected rather than storing the command itself. Credentials and secrets should continue to use the MCMA Vault rather than ordinary user memory.

## Zero-AI memory explorer

Authenticated web users can browse and decrypt memory without invoking an embedding or generation model.

The default **Tree** view exposes only the permission-filtered personal branch:

~~~text
GET /mcma/v1/memory-tree
GET /mcma/v1/memory-object?ref=memory://user/...
~~~

`/mcma/v1/memory-tree` is derived from `Library::treeAs('owner')` but returns only the `memory://user/` subtree. Internal `memory://knowledge/`, `memory://system/` and `memory://access/` branches are not part of this response. The decrypted-object route accepts only `memory://user/...` references and performs an actor-aware Library read before returning content.

The UI renders collapsible thematic folders and allows selecting a leaf to see its decrypted canonical content, original user text when present, logical route, cognitive layer, scope, temperature, maturity, revision, object id and storage hash.

The existing **List** view remains available for knowledge-record search, validation and pagination:

~~~text
GET  /mcma/v1/memories
GET  /mcma/v1/memories/{knowledge_id}
POST /mcma/v1/memories/{knowledge_id}/validation
~~~

The list route supports deterministic text search over decrypted questions/answers, temperature filters, validation-state filters and pagination. The detail route returns the selected question, answer and epistemic/freshness metadata from the user's isolated MCMA library.

Validation accepts only:

~~~json
{"action":"confirm"}
{"action":"discard"}
~~~

`confirm` promotes the owner-confirmed memory to `verified` with confidence `0.95`. `discard` marks it `retracted` with confidence `0.0`. Repeating the same action is idempotent.

Tree navigation, canonical-object decryption and the existing list/validation operations report zero AI tokens and zero AI credits because they use only authenticated Library reads/decryption and deterministic metadata updates. Storage requests (for example S3 GET/PUT operations) still occur and are not the same thing as model-token usage.

When a validation update changes the knowledge object's `storage_hash`, MCMA refreshes the existing semantic-index entry's object/hash linkage while preserving its existing vector. No new embedding is generated for that metadata-only update.

The explorer is web-session-only: an authenticated session resolves exactly one user library, and the endpoints do not accept a library id or arbitrary storage path from the browser.

## Context transparency tab

The authenticated web UI has three main tabs: `Preguntar`, `Mi memoria` and `Contexto MCMA`.

Tab navigation uses one delegated click listener on the tablist plus ArrowLeft/ArrowRight/Home/End keyboard navigation. Each tab exposes ARIA tab/tabpanel relationships. This avoids per-button listener drift and makes the navigation resilient when panels are expanded later.

A deployment can otherwise show new tab markup with an older cached JavaScript file. The Nginx example therefore marks mutable `index.html`, `app.js` and `app.css` as `no-cache, no-store, must-revalidate`. CDN deployments should invalidate `/mcma/*` after a UI release when the CDN cache policy can ignore query strings.

After every `/mcma/v1/ask` request, MCMA writes a compact encrypted trace to:

~~~text
memory://system/context-traces
~~~

The trace is stored inside that user's isolated library and contains the request id/time, question, route, provider id, memory-attempt summary, whether generated knowledge was stored, billing summary and the exact validated memory context supplied to generation when one was injected. It never stores provider credentials, KeyStore keys, OIDC tokens or server secrets.

Only the latest 50 traces are retained in this first implementation. The context-trace object is protected as owner-only system memory. Existing libraries are hardened on first use; new default permission policies include the restriction directly.

The read-only transparency route is:

~~~text
GET /mcma/v1/context
~~~

It returns:

- persistent knowledge counts and reuse counts;
- validation/temperature summary;
- recent knowledge records whose provenance says `source_type=model`;
- internal `memory://system/*` object metadata;
- the latest encrypted request-context traces.

Opening this view does not invoke Titan, Nova or another model and reports zero AI tokens/credits. It may perform normal encrypted storage reads.

## Memory and context behavior

The web application does not rely on an ever-growing plaintext chat transcript as its durable memory. A remembered answer is stored as an encrypted knowledge record keyed by normalized question intent.

~~~text
question
  ↓
normalized intent
  ↓
memory://knowledge/q-<sha256>
  ↓
answer + provenance + validation + confidence + freshness
  ↓
optional encrypted semantic index entry
~~~

The request order is exact-first:

1. check the deterministic exact knowledge reference;
2. if the record is reusable, return it without calling an AI provider;
3. otherwise, when semantic retrieval is configured, embed the incoming question and search the encrypted semantic index;
4. a semantically matched candidate must still pass permission, validation, confidence and freshness gates;
5. only when memory cannot be reused does MCMA call the generation provider;
6. when `remember=true`, a new generated answer can be captured through the Librarian and incrementally indexed.

This distinction matters for tokens. Exact reusable memory costs zero provider tokens. Semantic retrieval can consume embedding tokens. Generation fallback consumes the provider tokens actually reported or conservatively estimated by the connector.

Generated web captures default to `validation_state=unverified` and `confidence=0.5`. The default reuse threshold is `0.75`, and reusable knowledge normally must also be `supported` or `verified`. Therefore a repeated question does not automatically become zero-token merely because its previous generated answer was saved. The stored record is preserved, but it must pass the epistemic and freshness gates before direct reuse.


### Guarded Context Builder — first implementation

As of 2026-09-01, generation fallback can receive a guarded MCMA memory context.

The first implementation is intentionally conservative. A memory candidate is injected into generation only when it is already `supported` or `verified`, meets the configured confidence threshold, and requires revalidation for reasons such as freshness or an explicit current-data request. Unverified, disputed, retracted, low-confidence and `never-direct` records are not injected.

The provider receives the memory as clearly delimited reference data plus validation/freshness metadata. Bedrock Converse, Ollama and llama.cpp also receive a higher-priority instruction that memory content is untrusted reference data and instructions embedded inside memory must not be followed.

Billing remains fail-safe: the lazy reservation includes a conservative generation-context allowance, and fallback metering includes the serialized context when a provider cannot report exact usage.

This is the first Context Builder, not the final multi-record RAG layer. Future work remains for token-budgeted multi-memory assembly, provenance ordering and recent-turn/session context.

Provider selection is server-side:

~~~text
MCMA_WEB_EMBEDDING_PROVIDER
MCMA_WEB_GENERATION_PROVIDER
~~~

Supported values correspond to the installed MCMA providers:

~~~text
embedding: none | bedrock-titan-v2 | ollama | llamacpp
generation: none | bedrock-converse | ollama | llamacpp
~~~

## Storage

The web root storage is configured with:

~~~text
MCMA_WEB_STORAGE_LOCATION
~~~

It can use any implemented StorageAdapter.

Every authenticated user still resolves to an isolated namespace and a distinct encrypted MCMA library.

## Nginx

An example include is available at:

~~~text
config/nginx/mcma-web.conf.example
~~~

It assumes the public root:

~~~text
apps/web/public
~~~

TLS should terminate at Nginx or a trusted HTTPS proxy in front of it.

## Security boundaries

- OIDC issuer is configured server-side.
- OIDC discovery issuer must exactly match the configured issuer.
- OIDC/JWKS/token endpoints must be HTTPS.
- POST requests reject a mismatched Origin header.
- UI and API are same-origin by default.
- No permissive CORS is enabled.
- Secrets are environment/server configuration only.
- Errors returned to the browser do not include stack traces.
- The provider/storage configuration is not client-controlled.
- Multi-user mode continues to reject a shared MCMA_MASTER_KEY_B64.

## Deployment variables

Minimum web variables:

~~~text
MCMA_WEB_STORAGE_LOCATION
MCMA_WEB_PUBLIC_ORIGIN
MCMA_WEB_SESSION_SECRET
MCMA_MULTIUSER_PEPPER
MCMA_KEY_DIR
MCMA_OIDC_ISSUER
MCMA_OIDC_CLIENT_ID
~~~

A confidential OIDC client additionally uses:

~~~text
MCMA_OIDC_CLIENT_SECRET
~~~

Then choose AI provider variables, or leave both web providers as none to use exact memory only.

## Current verification

CI contains:

- OIDC RSA-2048/JWKS signature validation;
- nonce rejection;
- unauthenticated route rejection;
- automatic creation of two isolated user libraries;
- authenticated /me;
- authenticated /ask;
- cross-origin POST rejection;
- assertion that raw provider subjects do not appear in stored MCMA bytes.

Live production verification completed on 2026-09-01:

- `https://mailit.click/mcma/` serves the web application over HTTPS;
- Google OIDC login and callback complete successfully;
- registration resolves the authenticated identity to an isolated encrypted library;
- the browser maintains an encrypted HttpOnly MCMA session cookie;
- `GET /mcma/v1/health` returns HTTP 200 with `multi_user=true` and `billing_enabled=true`;
- the application uses a dedicated PHP-FPM service/socket, separate from historical V1/V2 compatibility routes;
- S3 storage, Titan embeddings and Nova Micro generation work end to end.


## Billing and external API

When `MCMA_BILLING_ENABLED=true`, the web/API layer uses the billing ledger before and after AI provider calls.

User routes:

~~~text
GET    /mcma/v1/billing
GET    /mcma/v1/billing/usage?date=YYYY-MM-DD
GET    /mcma/v1/api-keys
POST   /mcma/v1/api-keys
DELETE /mcma/v1/api-keys/{key_id}
~~~

External clients authenticate with:

~~~text
Authorization: Bearer mcma_api_...
~~~

The API key resolves to the same isolated MCMA user library. The billing event records origin=api and the non-secret API key id.

SuperAdmin routes are documented in `docs/BILLING.md`. The browser panel is `/admin.html`.

Billing is disabled by default. Configure pricing and credits before enabling paid AI calls.


## Stripe Checkout

When billing and Stripe are configured, authenticated users can list purchasable one-time or subscription packages and start Stripe Checkout:

~~~text
GET  /mcma/v1/billing/stripe/packages
POST /mcma/v1/billing/stripe/checkout
~~~

Stripe redirects the browser to its hosted payment page. Package `billing_mode` controls whether Checkout uses `mode=payment` or `mode=subscription`.

The webhook endpoint is public but cryptographically verified:

~~~text
POST /mcma/v1/billing/stripe/webhook
~~~

The webhook must receive the original raw request body unchanged. No browser session or CORS permission is used for the webhook.

For subscription packages, Checkout links the user and Stripe Subscription but does not grant recurring credits. Credits and paid-plan activation are fulfilled from verified `invoice.paid` events; future paid invoices automatically renew the configured credit grant.

Subscription lifecycle events are also processed. A payment failure records the Stripe state while retries remain possible; cancellation/unpaid/paused states return the paid plan to Free without deleting the MCMA library.

When `MCMA_BILLING_ENABLED=false`, package listing and Checkout creation are disabled. A correctly signed webhook can still finish a Checkout or renewal that was already in flight.

See `docs/BILLING.md` for package configuration and fulfillment rules.
