# Installation

MCMA can be installed from a normal Git checkout.

## Recommended EC2/server flow

~~~bash
git clone git@github.com:jimmybackend/MCMA-OpenMemory.git
cd MCMA-OpenMemory
sudo ./install.sh
~~~

The installer keeps a deployable Git checkout at:

~~~text
/opt/MCMA-OpenMemory
~~~

That checkout retains its `.git` metadata. If the EC2 user's SSH key already has GitHub access, you can edit/test fixes on the server and push normal commits from that checkout.

## What the installer does

- detects Linux package manager;
- installs nginx, PHP 8.2+ runtime/PHP-FPM dependencies when possible;
- refuses PHP older than 8.2;
- deploys/keeps the Git checkout;
- creates `/etc/mcma/mcma.env` with mode 600;
- never overwrites an existing runtime env file;
- creates protected per-library key storage;
- generates MCMA-local random peppers/session secrets on a fresh install;
- never invents AWS/OIDC/Stripe/cloud credentials;
- passes configured env names into PHP-FPM;
- writes an nginx MCMA server block;
- validates nginx;
- runs a CLI smoke test;
- runs the HTTP health check when web/OIDC variables are complete.

## Existing environment file

If you prepare the values separately:

~~~bash
sudo ./install.sh --env-source /secure/path/mcma.env --domain memory.example.com
~~~

The installed file becomes `/etc/mcma/mcma.env`. If that target already exists, the installer preserves it.

## First-run generated defaults

On a fresh installation the installer generates only local MCMA security values:

~~~text
MCMA_KEY_DIR
MCMA_MULTIUSER_PEPPER
MCMA_WEB_SESSION_SECRET
MCMA_API_KEY_PEPPER
~~~

It starts with local storage, no AI provider and billing disabled. Provider credentials remain an explicit deployment choice.

## Variables to complete for web login

Before the web application is fully usable, configure at least:

~~~text
MCMA_WEB_STORAGE_LOCATION
MCMA_WEB_PUBLIC_ORIGIN
MCMA_WEB_SESSION_SECRET
MCMA_MULTIUSER_PEPPER
MCMA_OIDC_ISSUER
MCMA_OIDC_CLIENT_ID
~~~

Then configure the storage/AI credentials you actually use. An AWS deployment can use S3 + Bedrock; another installation can use Local + Ollama.

## Commercial mode

Personal use does not require billing:

~~~text
MCMA_BILLING_ENABLED=false
~~~

Commercial installations can enable billing and optionally Stripe after pricing/credit policy is configured. Stripe packages support both `payment` and `subscription`; subscription renewals are applied from verified `invoice.paid` events.

## TLS

The generated nginx block listens on HTTP port 80. This works when TLS terminates at a trusted reverse proxy/CDN such as CloudFront. If nginx itself terminates TLS, add the certificate/listen configuration appropriate to that server. `MCMA_WEB_PUBLIC_ORIGIN` must still be the public HTTPS origin.

## Diagnostics

~~~bash
sudo /opt/MCMA-OpenMemory/scripts/mcma-doctor.sh
~~~

The doctor checks versions, extensions, protected env presence, multi-user key rules, required web variables, optional Stripe completeness, nginx syntax and service state without printing secret values.

## Updating/fixing from EC2

~~~bash
cd /opt/MCMA-OpenMemory
git status
git pull --ff-only
~~~

After a code/config update:

~~~bash
sudo ./install.sh --skip-packages
~~~

The installer preserves `/etc/mcma/mcma.env`.

If a real EC2 test exposes a bug, fix it in the Git checkout, run the relevant tests, commit, and push over the existing GitHub SSH remote. Never commit `/etc/mcma/mcma.env`, KeyStore files, AWS credentials or Stripe secrets.

## Current public deployment note — 2026-09-02

The public development/production instance at `https://mailit.click/mcma/` predates the installer's recommended `/opt/MCMA-OpenMemory` checkout and currently uses:

~~~text
checkout: /var/www/memory
service:  php-fpm-mcma
public base path: /mcma/
~~~

This is a valid existing deployment layout. The installer continues to use `/opt/MCMA-OpenMemory` for fresh installs; documentation and scripts should not assume the live historical checkout must be relocated merely to match that default.

The persistent Chat release was updated there with `git pull --ff-only`, focused integration tests, a dedicated PHP-FPM restart and public health/asset/authentication checks.

### Repository-managed mailit.click Nginx

The live MCMA V1 routing for the historical `/var/www/memory` deployment is now versioned in:

~~~text
config/nginx/mcma-mailit-v1.conf
~~~

It is a **server-block fragment**, not a complete virtual host. The live parent include remains:

~~~text
/etc/nginx/mcma-mailit.conf
~~~

and the repository-managed fragment is installed at:

~~~text
/etc/nginx/mcma-mailit-v1-managed.conf
~~~

Use the repository deployer after pulling a reviewed/merged commit:

~~~bash
cd /var/www/memory
git pull --ff-only
sudo bash scripts/deploy-mailit-nginx.sh --apply
sudo bash scripts/deploy-mailit-nginx.sh --check
~~~

On first adoption the script backs up `/etc/nginx/mcma-mailit.conf`, removes only the known inline MCMA V1/static/OIDC locations, leaves historical `/mcma/v2/*` compatibility routes intact, installs one include line for the managed fragment, runs `nginx -t`, reloads only after validation, and restores the backup if validation fails.

The managed production fragment pins the dedicated runtime boundary:

~~~text
checkout: /var/www/memory
front controller: /var/www/memory/apps/web/public/index.php
PHP-FPM socket: /run/php-fpm-mcma/mcma.sock
FastCGI read/send timeout: 180s
~~~

Secrets, OIDC credentials, AWS credentials and MCMA keys never belong in this Nginx fragment.

