# MCMA 1.0 Conformance

Status: **Normative test requirements for first implementation**

## Required capabilities

A conforming first implementation must be able to:

1. validate canonical library/object identifiers;
2. validate an MCMA 1.0 envelope;
3. derive the same object key from the same synthetic inputs;
4. produce the same RFC 8785 JCS AAD;
5. authenticate/decrypt the published test vector;
6. recompute the published `storage_hash`;
7. reject a modified protected header;
8. reject a modified ciphertext;
9. reject a modified GCM tag;
10. reject a false `storage_hash`.

## Published vector

The first vector is:

```text
test-vectors/vector-001.json
```

All keys and plaintext in the vector are synthetic and intentionally public.

They MUST NOT be reused as production key material.

## Cross-language rule

PHP, Python, JavaScript, Go, Rust, Java or another implementation is conformant only if it reproduces the same normative intermediate/final values for the vector:

- salt;
- HKDF info;
- derived key;
- AAD bytes;
- ciphertext;
- tag;
- storage hash.

## Negative tests

At minimum, tests must mutate one byte/character independently in:

- `protected.library_id`;
- `protected.object_id`;
- `protected.crypto.key_version`;
- `protected.crypto.key_context`;
- `protected.crypto.iv_b64u`;
- ciphertext;
- tag.

Authentication or structural verification must fail.

## Storage independence test

A conforming test should copy the exact envelope bytes to a different physical path/backend mapping and verify that decryption still succeeds.

This proves physical path is not cryptographic identity.

## Temperature independence test

A future lifecycle test must demonstrate that changing a temperature view does not require a new `object_id`.

If the encrypted payload itself is rewritten to persist that change, `storage_hash` may change while `object_id` remains stable.
