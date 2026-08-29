# Compatibility Reference

This directory preserves the exact working prototype material required to understand and read historical MCMA encrypted memories.

It is **not** the active MCMA 1.0 format.

The active specification lives in:

```text
/spec/1.0/
```

## Why this code remains

Real encrypted memories already exist outside this repository. Their cryptographic identity depends on the rules used when they were created.

For that reason compatibility code is preserved rather than renamed internally or modified to pretend those objects were created under the MCMA 1.0 identity model.

A future migration tool will:

1. read and authenticate the historical object with this compatibility code;
2. decrypt only in an authorized runtime;
3. create a new stable MCMA 1.0 object;
4. preserve provenance linking the migrated object to its source.

No production secrets are stored here.
