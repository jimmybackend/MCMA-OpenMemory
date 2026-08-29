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

The remaining operational milestone is a live EC2 + HTTPS + real OIDC provider smoke test.


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
