# MCMA 1.0 CLI

PHP 8.2+ with OpenSSL is required. Network adapters require PHP cURL.

## Locations

~~~text
/local/path
github://OWNER/REPO/prefix?branch=main
s3://BUCKET/prefix?region=us-east-1
webdav+https://HOST/existing/library/root
~~~

Provider credentials are supplied through environment variables, never storage URLs.

## Actor-aware memory commands

~~~text
mcma write LOCATION URI INPUT [--actor=owner]
mcma update LOCATION URI INPUT [--actor=owner]
mcma temperature LOCATION URI hot|warm|cold|frozen [--actor=owner]
mcma read LOCATION URI [--actor=owner]
mcma list LOCATION [--actor=owner]
mcma tree LOCATION [--actor=owner]
~~~

Before access control is initialized, actor-aware operations are owner-only.

## Initialize security

~~~bash
./apps/cli/mcma access-init /path/to/library
./apps/cli/mcma access-check /path/to/library ai read memory://topics/example
~~~

A custom policy can be supplied with --policy=FILE.json.

## Permissions

~~~text
mcma permissions-show LOCATION [--actor=owner]
mcma permissions-set LOCATION FILE.json [--actor=owner]
~~~

## Vault

Store a secret from an environment variable:

~~~bash
export MY_API_TOKEN='...'
./apps/cli/mcma vault-put /path/to/library provider-token MY_API_TOKEN --type=api-token
unset MY_API_TOKEN
~~~

Metadata only:

~~~text
mcma vault-list LOCATION [--actor=owner]
~~~

Delete:

~~~text
mcma vault-delete LOCATION NAME [--actor=owner]
~~~

There is intentionally no vault-get or vault-read command.

Trusted application/security-agent code uses the Library useVaultSecret callback and should return only the result of the authorized external operation.

## Storage migration

~~~text
mcma storage-copy SOURCE DESTINATION
~~~

Copies exact encrypted bytes; keys are not copied into the provider.
