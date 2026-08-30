# Deployment Pattern: Linux / EC2 / PHP-FPM

This document describes the deployment pattern used by the original MCMA prototype and generalizes it for a public implementation.

> Current MCMA 1.0 deployments should use the real web root under `apps/web/public`, the example `config/nginx/mcma-web.conf.example`, and the environment names in `config/mcma.env.example`. The older `/mcma/v2/crypto` examples below are retained only as compatibility lineage.

It intentionally documents **where secrets live and how the service receives them** without publishing any production secret values.

## Goals

- keep secrets outside Git repositories;
- keep secrets outside the web document root;
- allow PHP-FPM workers to receive only the variables they require;
- keep private crypto code unreachable as a direct web path;
- expose only intentional HTTP entry points;
- make CLI testing possible without copying secrets into commands or source files.

## Reference filesystem layout

A Linux deployment may use:

```text
/etc/mcma/
└── mcma.env              # secrets/config; root-owned, not public

/var/www/html/mcma/
├── v2/
│   ├── public/            # mapped only through explicit Nginx routes
│   └── private/           # never directly exposed
└── ...
```

The original prototype used `/etc/mcma/mcma.env` as the environment file and kept MCMA application code under `/var/www/html/mcma`.

## Environment file

Create a dedicated directory:

```bash
sudo mkdir -p /etc/mcma
sudo chown root:root /etc/mcma
sudo chmod 700 /etc/mcma
```

Create the environment file:

```bash
sudo touch /etc/mcma/mcma.env
sudo chown root:root /etc/mcma/mcma.env
sudo chmod 600 /etc/mcma/mcma.env
```

Example **names only**:

```dotenv
MCMA_MASTER_KEY_B64=REPLACE_ON_SERVER
MCMA_API_TOKEN=REPLACE_ON_SERVER
MCMA_BRIDGE_TOKEN=REPLACE_ON_SERVER

# Provider-specific adapter configuration belongs outside the core.
# Example for a Git-backed adapter:
MCMA_GITHUB_TOKEN=REPLACE_ON_SERVER
MCMA_GITHUB_OWNER=REPLACE_ON_SERVER
MCMA_GITHUB_REPO=REPLACE_ON_SERVER
MCMA_GITHUB_BRANCH=main

# Internal service endpoint if a bridge and crypto service are separate:
MCMA_CRYPTO_URL=https://example.invalid/mcma/v2/crypto
```

Do **not** commit the real `/etc/mcma/mcma.env` file.

## Generating prototype secrets

For a prototype, generate random material on the server rather than inventing human passwords:

```bash
openssl rand -base64 32
openssl rand -hex 32
```

The first pattern can be used for a 32-byte Base64 master key. Independent random values should be used for authentication tokens.

For production, prefer a managed secret/KMS system and documented rotation procedures.

## systemd EnvironmentFile

A PHP-FPM systemd override can load the protected file into the service environment.

Conceptual override:

```ini
[Service]
EnvironmentFile=/etc/mcma/mcma.env
```

After changing the override or environment file:

```bash
sudo systemctl daemon-reload
sudo systemctl restart php-fpm
sudo systemctl is-active php-fpm
```

Exact unit names vary by Linux distribution and PHP packaging.

## PHP-FPM environment whitelist

Many PHP-FPM configurations clear worker environments. Pass only required variables into the pool.

Example drop-in:

```ini
env[MCMA_MASTER_KEY_B64] = $MCMA_MASTER_KEY_B64
env[MCMA_API_TOKEN] = $MCMA_API_TOKEN
env[MCMA_BRIDGE_TOKEN] = $MCMA_BRIDGE_TOKEN
```

A provider adapter can add only the variables it needs, for example:

```ini
env[MCMA_GITHUB_TOKEN] = $MCMA_GITHUB_TOKEN
env[MCMA_GITHUB_OWNER] = $MCMA_GITHUB_OWNER
env[MCMA_GITHUB_REPO] = $MCMA_GITHUB_REPO
env[MCMA_GITHUB_BRANCH] = $MCMA_GITHUB_BRANCH
env[MCMA_CRYPTO_URL] = $MCMA_CRYPTO_URL
```

Validate PHP-FPM configuration before restart where supported:

```bash
sudo php-fpm -t
```

The design rule is more important than the exact filename: **the operating system owns the secrets; public source code only knows environment variable names.**

## CLI use

A shell does not automatically inherit the systemd environment of PHP-FPM. For controlled administrative testing, load the root-protected file into a privileged shell:

```bash
set -a
source /etc/mcma/mcma.env
set +a
```

Avoid echoing secret values or including them directly in shell history.

## Web permissions

The original prototype kept private bridge and memory entry files readable by the PHP-FPM service account but not world-readable. Exact owner/group values vary by distribution.

General principle:

```text
root owns deployment files
PHP-FPM service group receives only required read access
private code is not directly addressable from HTTP
```

## Nginx pattern

Expose explicit locations rather than making the entire MCMA tree public.

Generic example:

```nginx
location = /mcma/v2/crypto {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/html/mcma/v2/public/crypto.php;
    fastcgi_param SCRIPT_NAME /mcma/v2/crypto;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_pass unix:/run/php-fpm/www.sock;

    add_header Cache-Control "no-store, private" always;
    add_header Pragma "no-cache" always;
}

location ^~ /mcma/ {
    return 404;
}
```

If additional routes such as a bridge or UI are required, define each one explicitly before the catch-all deny rule.

Do not expose:

- `/private/`;
- `.env` files;
- deployment archives;
- README/config backups;
- raw memory directories;
- source PHP files through arbitrary filesystem paths.

## HTTP authorization

The current V2 reference crypto endpoint expects a bearer token supplied in the `Authorization` header. Tokens should never be placed in query strings.

```text
Authorization: Bearer <secret>
```

TLS/HTTPS is mandatory for network exposure.

## Secret separation from storage

A provider adapter may write encrypted `.mcma` envelopes to Git, S3, GCS, Azure, WebDAV, local disk or another backend. The storage credentials and MCMA master key remain separate concerns.

A storage provider should never need `MCMA_MASTER_KEY_B64` merely to persist ciphertext.

## Production evolution

The `/etc/mcma/mcma.env` pattern is useful because it makes the trust boundary concrete, but a production deployment should consider replacing static secrets with:

- AWS KMS + Secrets Manager;
- Google Cloud KMS + Secret Manager;
- Azure Key Vault;
- HashiCorp Vault;
- hardware-backed key storage;
- workload identity or short-lived credentials.

MCMA-OpenMemory does not require a particular provider. The invariant is that secret delivery remains outside the portable memory format and outside the public repository.


## Current MCMA 1.0 web deployment additions

The current web runtime is database-free and uses:

~~~text
apps/web/public
packages/core
packages/connectors
~~~

A multi-user deployment should use a protected `MCMA_KEY_DIR` and leave `MCMA_MASTER_KEY_B64` unset.

Current server-only secrets/config can include:

~~~text
MCMA_MULTIUSER_PEPPER
MCMA_WEB_SESSION_SECRET
MCMA_OIDC_CLIENT_SECRET
MCMA_API_KEY_PEPPER

AWS / storage-provider credentials as required

MCMA_STRIPE_SECRET_KEY
MCMA_STRIPE_WEBHOOK_SECRET
MCMA_STRIPE_PACKAGES_JSON
~~~

Stripe is optional. Personal deployments can keep `MCMA_BILLING_ENABLED=false`.

Commercial Stripe packages support both one-time `payment` and recurring `subscription` modes. The webhook endpoint is:

~~~text
POST /mcma/v1/billing/stripe/webhook
~~~

The raw webhook body must reach PHP unchanged because signature verification authenticates the exact payload bytes.

For recurring subscriptions, paid invoices renew credits idempotently by Stripe invoice id. Subscription cancellation/unpaid/paused lifecycle events remove the paid-plan benefit and return the account to Free while preserving encrypted memory and existing credits.
