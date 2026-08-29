# Implementation Lineage

MCMA-OpenMemory did not begin as a blank specification. The public architecture is being extracted from a sequence of working prototypes.

## Stage 1 — Hierarchical key-service prototype

The earliest prototype explored a master key held on a Linux server and per-object key derivation based on logical path + `.mcma` filename.

Reference concepts introduced:

- `MCMA_MASTER_KEY_B64` as 32 random bytes encoded in Base64;
- `MCMA_API_TOKEN` for bearer authentication;
- HKDF-SHA256 per-object key derivation;
- AES-256-GCM encryption/decryption;
- logical object identity based on path + filename;
- secrets kept outside Git and outside source files.

This prototype also established the Linux deployment pattern using a protected environment file under `/etc/mcma/`.

## Stage 2 — `mcma-v1`

The first envelope format stored authenticated encrypted content with fields including:

```text
format
cipher
key_version
key_id
logical_path
file
iv_b64
tag_b64
ciphertext_b64
```

The V1 implementation is retained in this repository only as a legacy compatibility reference.

## Stage 3 — V2 security boundary

The important V2 change was architectural rather than cosmetic: encryption/decryption moved into the trusted server-side crypto service.

Clients no longer need to receive derived encryption keys.

V2 introduced or formalized:

- envelope format `mcma-v2`;
- key version `mcma-key-v2`;
- `kdf = HKDF-SHA256` metadata;
- authenticated identity including logical path and filename in GCM AAD;
- strict GCM IV/tag validation;
- server-side bearer-authorized encrypt/decrypt endpoint;
- `created_at` timestamp;
- memory temperature metadata.

## Stage 4 — V2 fix

The fixed V2 package corrected the active CLI/reference implementation so it actually writes and reads `mcma-v2` envelopes using the V2 derivation and AAD rules while retaining the V1 implementation separately for recovery compatibility.

This fixed V2 code is the basis of the public PHP reference implementation.

## Stage 5 — Memory bridge and external persistence

The deployed prototype then added a memory UI/bridge flow:

```text
plaintext
   ↓
trusted MCMA endpoint
   ↓
server-side encryption
   ↓
.mcma envelope
   ↓
storage bridge
   ↓
external persistent storage
```

The deployed bridge used GitHub as one concrete storage backend and stored encrypted `.mcma` envelopes under a `memories/` tree.

That working implementation proved the end-to-end idea, but GitHub is intentionally **not** part of the core MCMA-OpenMemory specification. In the open architecture it becomes one storage adapter among many.

## Stage 6 — MCMA-OpenMemory

The public project generalizes the working prototype into four separable layers:

```text
Memory Engine
Crypto Engine
Index Engine
Storage Adapter
```

It also formalizes three independent memory dimensions:

```text
Cognitive meaning
Scope
Temperature
```

## Why the public project is v0.1 while the envelope says v2

Two version spaces exist:

```text
Project/spec release: 0.1.0
Envelope format:      mcma-v2
Key derivation:       mcma-key-v2
```

Renaming the existing working envelope to `mcma-v1` merely to match the public repository would create unnecessary compatibility confusion. The public repository therefore starts at `0.1.0` and preserves the actual envelope identifiers produced by the prototype.

## Source packages reviewed for this public extraction

The initial public extraction was based on the working development packages and an EC2 configuration/context snapshot:

```text
mcma-key-service-v0.1
mcma-v2
mcma-v2-fixed
mcma-context
```

Production credentials and secret values are not copied into the public repository.
