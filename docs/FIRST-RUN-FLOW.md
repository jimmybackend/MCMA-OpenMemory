# MCMA First-Run Flow

## Goal

The first-run experience must make MCMA useful both as an AI-connected memory system and as a normal encrypted document library.

## Entry flow

~~~text
Open MCMA
    │
    ├── Create new library
    │
    └── Open existing library
             │
             ▼
       Read manifest
             │
             ▼
       Identify owner
             │
             ▼
       Authenticate if required
             │
             ▼
       Choose mode
        ┌────┼──────────────┬──────────────┐
        ▼    ▼              ▼              ▼
      With   Without      Configure      Web/API
       AI      AI
~~~

## Create new library

The client should guide the user through:

1. library identity;
2. owner identity;
3. normal or private metadata mode;
4. encryption/key setup;
5. recovery method;
6. initial storage provider;
7. initial permissions;
8. optional AI connection.

AI configuration must not be mandatory.

## Open existing library

A compatible client should:

1. locate manifest.mcma;
2. validate supported format versions;
3. determine required authentication;
4. resolve the root catalog/index;
5. unlock only the minimum material required for the requested operation.

## Mode: With AI

The user may configure a local model, remote provider or compatible agent runtime, plus memory scopes and write permissions.

The AI receives only authorized context selected by the MCMA client.

## Mode: Without AI

The client behaves as a secure document reader and library manager.

Initial manual operations:

~~~text
tree
list
info
read
verify
export
~~~

Later operations may include:

~~~text
write
lock
unlock
search
import
migrate
~~~

## Mode: Configure

Configuration should be divided into storage, security, identity, permissions and AI.

Storage may use Local, GitHub, S3/S3-compatible, Google Cloud Storage, Google Drive, Azure Blob Storage, Alibaba OSS or WebDAV.

## Mode: Web/API

The current PHP web application can authenticate with OIDC, derive an isolated multi-user library and expose the same Ask/Knowledge/Semantic core through HTTPS.

Commercial deployments may optionally enable AI metering, credits, API keys, plans, SuperAdmin and Stripe Checkout. Personal deployments can keep billing disabled.

Stripe packages may be one-time purchases or recurring subscriptions. Recurring packages renew credits from verified `invoice.paid` events.

## Device portability

A new device should not require rebuilding the user's memory.

It should require only an MCMA-compatible client, access to the library location and successful owner authorization.
