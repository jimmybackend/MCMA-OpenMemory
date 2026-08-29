# MCMA 1.0 Local CLI

This is the first executable MCMA 1.0 core. It requires PHP 8.2+ with OpenSSL.

It intentionally has **no AI and no database dependency**.

## Commands

```text
mcma init
mcma open
mcma info
mcma write
mcma read
mcma verify
mcma list
mcma tree
```

Example:

```bash
export MCMA_KEY_DIR="$HOME/.config/mcma/keys"

./apps/cli/mcma init ~/memory.mcma-library --mode=private
printf 'My first MCMA 1.0 memory\n' > /tmp/memory.txt
./apps/cli/mcma write ~/memory.mcma-library memory://topics/first-memory /tmp/memory.txt
./apps/cli/mcma read ~/memory.mcma-library memory://topics/first-memory
./apps/cli/mcma verify ~/memory.mcma-library
./apps/cli/mcma tree ~/memory.mcma-library
```

## Key boundary

By default `init` creates a random 32-byte master key outside the portable library at:

```text
~/.config/mcma/keys/<library_id>.key
```

Set `MCMA_KEY_DIR` to choose another protected local key directory.

For controlled deployments, `MCMA_MASTER_KEY_B64` may supply the key instead.

The key is never embedded in `manifest.mcma` or an object envelope.

## Current limitation

The first PHP canonical JSON writer accepts integers but rejects floating-point JSON values. This is deliberate: MCMA 1.0 requires RFC 8785 JCS and the implementation will not silently emit non-conformant floating-point encodings. Text, Markdown, XML and binary content are unaffected.
