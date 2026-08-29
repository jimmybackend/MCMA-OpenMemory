# Multi-cloud Storage Providers

MCMA stores the same encrypted `.mcma` bytes regardless of provider.

The storage provider does not change:

- `library_id`
- `object_id`
- plaintext/ciphertext format
- knowledge semantics
- semantic-index rules

Only the physical StorageAdapter changes.

## Implemented locations

~~~text
/local/path
github://OWNER/REPO/optional/prefix?branch=main
s3://BUCKET/optional/prefix?region=us-east-1
gcs://BUCKET/optional/prefix
gdrive://ROOT_FOLDER_ID
azure://ACCOUNT/CONTAINER/optional/prefix
oss://BUCKET/optional/prefix?region=cn-hangzhou
webdav+https://HOST/existing/library/root
~~~

## Google Cloud Storage

Authentication:

~~~text
MCMA_GCS_ACCESS_TOKEN
~~~

The adapter uses the Google Cloud Storage JSON API and stores the object `generation` as the provider version.

Conditional writes use `ifGenerationMatch`. A create-only write uses generation match `0`.

Capabilities:

~~~text
compare_and_swap = true
conditional_create = true
conditional_delete = true
version = generation
~~~

## Google Drive

Authentication:

~~~text
MCMA_GDRIVE_ACCESS_TOKEN
~~~

The LOCATION is the Drive folder used as the MCMA storage root.

MCMA stores encrypted objects as ordinary binary Drive files. The original MCMA storage locator is recorded in Drive `appProperties`; file names are deterministic hashes of the locator.

Drive exposes a monotonically increasing file `version`, which MCMA checks before updates and deletes.

However Drive does not provide the same atomic object CAS primitive used by object stores for this workflow. Therefore the adapter deliberately reports:

~~~text
compare_and_swap = false
writer_model = single-writer
~~~

Use one active MCMA writer for a Google Drive-backed library.

## Microsoft Azure Blob Storage

LOCATION:

~~~text
azure://ACCOUNT/CONTAINER/optional/prefix
~~~

Authentication can be supplied externally with:

~~~text
MCMA_AZURE_SAS_TOKEN
MCMA_AZURE_BEARER_TOKEN
~~~

An optional custom endpoint can use:

~~~text
MCMA_AZURE_BLOB_ENDPOINT
~~~

The adapter uses Blob ETags for provider versions and `If-Match` / `If-None-Match` for conditional writes.

## Alibaba Cloud OSS

LOCATION:

~~~text
oss://BUCKET/optional/prefix?region=cn-hangzhou
~~~

MCMA uses Alibaba OSS's S3-compatible interface through the existing S3 adapter engine.

Credentials:

~~~text
MCMA_OSS_ACCESS_KEY_ID
MCMA_OSS_ACCESS_KEY_SECRET
MCMA_OSS_SECURITY_TOKEN
~~~

or the standard Alibaba Cloud variables:

~~~text
ALIBABA_CLOUD_ACCESS_KEY_ID
ALIBABA_CLOUD_ACCESS_KEY_SECRET
ALIBABA_CLOUD_SECURITY_TOKEN
~~~

OSS requires virtual-hosted request style. The default S3-compatible endpoint is:

~~~text
https://s3.oss-REGION.aliyuncs.com
~~~

A custom endpoint can be configured with `MCMA_OSS_ENDPOINT`.

## Other clouds

MCMA already has two generic portability routes:

- S3-compatible providers can use the S3 adapter.
- services/NAS products exposing WebDAV can use the WebDAV adapter.

A new native provider only needs to implement the `StorageAdapter` interface; no change to MCMA encryption, memory identity or AI orchestration is required.

## Provider migration

`mcma storage-copy` copies encrypted bytes without decrypting/re-encrypting them and publishes the manifest last.

The destination provider therefore never receives plaintext from MCMA's migration process.
