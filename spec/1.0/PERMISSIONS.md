# MCMA 1.0 Permissions

Status: **Experimental implemented profile**

MCMA permissions are independent from HOT/WARM/COLD/FROZEN temperature.

## Protected resource

The canonical permission policy is stored as encrypted MCMA content at:

```text
memory://access/permissions
```

The object uses the normal MCMA `object` container. Its policy content is encrypted and indexed like other protected system resources.

## Model

A decision evaluates:

```text
subject + action + memory:// resource
```

Initial subjects:

```text
owner
ai
librarian
security-agent
application
tool
device
```

Initial actions include:

```text
read
write
update
delete
temperature
classify
summarize
share
decrypt
manage_permissions
manage_vault
vault_metadata
use_secret
```

Policies have a default effect, role rules and resource-specific rules.

The shipped default policy is **deny-by-default** and gives the owner recovery/control authority.

## Rule precedence

The first implementation evaluates from general to specific:

1. policy default;
2. subject role;
3. matching resource rule.

More-specific resource/subject rules override less-specific rules. Deny wins a tie.

Exact resources and prefix patterns ending in `/*` are supported.

## Owner recovery invariant

A policy update is rejected unless `owner` still has:

```text
manage_permissions on memory://access/permissions
manage_vault       on memory://access/vault
```

This prevents accidental lockout of the person who owns the library.

## Bootstrap

Access control is initialized explicitly:

```text
mcma access-init LOCATION
```

Before a permission object exists, actor-aware operations are owner-only.

Initialization creates both encrypted access resources:

```text
memory://access/permissions
memory://access/vault
```

## Trusted primitive vs actor-aware API

Low-level `Library` methods without an actor remain trusted owner/runtime primitives for compatibility and maintenance.

User/tool/AI-facing code SHOULD use actor-aware methods:

```text
readAs
writeAs
updateAs
setTemperatureAs
listAs
treeAs
```

Mutating actor authorization is checked after acquiring the storage write lock / current manifest snapshot so a stale pre-lock decision is not used for the write.

Reserved access resources cannot be modified through ordinary memory write/update/temperature APIs.

## Schema

See:

```text
schema/permissions.schema.json
```
