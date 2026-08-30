# MCMA Multi-user Mode

MCMA can host multiple users on one application and one physical storage backend without a SQL/database dependency.

Each user is a separate MCMA library.

## Layout

The physical backend is namespaced through `PrefixStorageAdapter`:

~~~text
ROOT
├── system/
│   └── user-registry/
│       ├── manifest.mcma
│       ├── objects/...
│       └── ...
└── memories/
    ├── usr_<hmac-sha256>/
    │   ├── manifest.mcma
    │   ├── objects/...
    │   └── ...
    └── usr_<hmac-sha256>/
        ├── manifest.mcma
        ├── objects/...
        └── ...
~~~

The registry is itself an encrypted MCMA library.

Every user library has:

- a distinct `library_id`;
- its own KeyStore key;
- its own encrypted manifest/index/objects/vault;
- its own permissions;
- a non-PII `memory://identity/account` marker.

## No user-controlled path

A browser must never choose:

~~~text
?iduser=123
/mcma/users/123/ask
~~~

and expect MCMA to trust that identifier.

The web authentication layer validates the login and supplies the verified identity:

~~~text
issuer + subject
        ↓
AuthenticatedIdentity
        ↓
HMAC-SHA256(server pepper)
        ↓
usr_<digest>
        ↓
encrypted registry lookup
        ↓
expected library_id + storage_prefix
        ↓
verified user MCMA Library
~~~

The original issuer and subject are not stored in the MCMA storage.

## Server pepper

Multi-user mode requires:

~~~text
MCMA_MULTIUSER_PEPPER
~~~

It must be a persistent server-side secret of at least 32 bytes.

It is used to derive a stable, non-PII user identifier. It must never be sent to a browser or committed to source control.

Changing the pepper changes derived user IDs, so the pepper must be backed up securely. Pepper rotation/migration is a future lifecycle operation.

## Per-library keys

Multi-user mode intentionally rejects a global:

~~~text
MCMA_MASTER_KEY_B64
~~~

Instead, use the KeyStore with a protected key directory:

~~~text
MCMA_KEY_DIR=/secure/path/mcma/keys
~~~

The encrypted registry and every user library receive different `library_id` values and independent KeyStore files.

This prevents a normal multi-user deployment from depending on one shared application master key.

## Registration

A verified web identity is registered with:

~~~php
$service = MultiUserService::fromEnvironment($rootStorage);
$user = $service->register($issuer, $subject);
~~~

Registration is idempotent for the same authenticated identity.

The returned public record contains only:

~~~text
user_id
library_id
storage_prefix
status
created_at
updated_at
~~~

It does not expose the HMAC identity fingerprint.

## Resolution

After the web layer authenticates a request:

~~~php
$library = $service->resolve($issuer, $subject);
~~~

MCMA verifies all of the following before returning the library:

1. the derived user ID exists in the encrypted registry;
2. the record is active;
3. the registered storage prefix is exactly the expected user prefix;
4. the opened manifest has the registered `library_id`;
5. the user library identity marker contains the same non-PII `user_id`.

The application can then construct the normal Knowledge/Semantic/Ask services using that Library. The current web application already performs this resolution for OIDC sessions and external HMAC-backed API keys.

## Web request flow

~~~text
Browser / App
     ↓ HTTPS
Nginx / Web app
     ↓
Session / OIDC / JWT validation
     ↓
verified issuer + subject
     ↓
MultiUserService::resolve()
     ↓
one user's Library
     ↓
AskService / Knowledge / Semantic / Librarian
     ↓
same configured StorageAdapter
~~~

MCMA does not parse or trust an unverified JWT by itself. Authentication belongs at the web/application boundary.

## Administrative CLI

Identity values are read from environment variables rather than command-line arguments so they are not written into shell history.

~~~bash
export MCMA_MULTIUSER_PEPPER='...'
export MCMA_AUTH_ISSUER='https://id.example'
export MCMA_AUTH_SUBJECT='stable-provider-subject'

mcma users-init ROOT_LOCATION
mcma user-register ROOT_LOCATION
mcma user-info ROOT_LOCATION
mcma users-list ROOT_LOCATION
mcma user-disable ROOT_LOCATION
~~~

Alternate environment variable names can be selected with:

~~~text
--issuer-env=NAME
--subject-env=NAME
~~~

## Storage providers

The namespace wrapper is provider-neutral, so the same multi-user structure works with:

- Local
- GitHub
- Amazon S3 / S3-compatible
- Google Cloud Storage
- Google Drive
- Microsoft Azure Blob Storage
- Alibaba Cloud OSS
- WebDAV

Google Drive remains a single-writer backend because its adapter does not claim atomic compare-and-swap.

## No plaintext identity in storage

The encrypted registry stores only an HMAC fingerprint and non-PII routing metadata.

The user prefix is based on the HMAC-derived ID.

Tests explicitly verify that issuer and subject strings do not appear in stored MCMA bytes.

## Current lifecycle

Implemented:

- encrypted registry;
- stable HMAC user IDs;
- per-user libraries;
- per-library KeyStore separation;
- registration;
- resolution;
- listing;
- disable;
- namespace isolation;
- atomic JSON registry mutation with provider CAS/locking where supported.

Additional implemented lifecycle layers:

- OIDC web authentication and encrypted sessions;
- external API keys stored as HMAC only;
- per-user billing account and encrypted daily usage ledger;
- Free / Starter / Pro / Business plans;
- SuperAdmin for plan, credit, access and service administration;
- Stripe one-time Checkout and recurring subscriptions with verified webhook fulfillment.

Future lifecycle work:

- pepper rotation/migration;
- bulk user key recovery/export;
- device authorization;
- optional account deletion/retention policy;
- Stripe Customer Portal/self-service subscription management.
