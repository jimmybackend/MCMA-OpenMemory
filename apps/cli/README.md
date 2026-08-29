# MCMA 1.0 CLI

PHP 8.2+ with OpenSSL is required. Network adapters require PHP cURL.

## Locations

~~~text
/local/path
github://OWNER/REPO/prefix?branch=main
s3://BUCKET/prefix?region=us-east-1
webdav+https://HOST/existing/library/root
~~~

## Knowledge commands

Capture:

~~~text
mcma knowledge-put LOCATION QUESTION_FILE ANSWER_FILE [options]
~~~

Check direct reuse:

~~~text
mcma knowledge-check LOCATION QUESTION_FILE [options]
~~~

Inspect:

~~~text
mcma knowledge-show LOCATION QUESTION_FILE [--actor=owner]
~~~

Validate/reclassify:

~~~text
mcma knowledge-validate LOCATION QUESTION_FILE STATE CONFIDENCE REASON_FILE [options]
~~~

Important options:

~~~text
--actor=owner|ai|librarian
--answer-format=text|markdown|json
--confidence=0.0..1.0
--validation=unverified|plausible|supported|verified|disputed|retracted
--freshness=immutable|stable|dynamic|volatile
--max-age=SECONDS
--reuse=always|reuse-unless-stale|revalidate-if-stale|never-direct
--provenance=FILE.json
--current=yes|no
--min-confidence=0.0..1.0
~~~

A provenance JSON file is an array of source objects.

knowledge-check returns the remembered answer only when decision=reuse.

For revalidate/reject/miss, no direct answer is emitted.

## Security

Permissions/Vault commands remain actor-aware. There is no vault-get or vault-read command.

## Storage migration

mcma storage-copy preserves exact encrypted bytes between supported providers.
