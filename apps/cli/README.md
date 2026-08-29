# MCMA 1.0 CLI

PHP 8.2+ with OpenSSL is required. Network adapters and Bedrock require PHP cURL.

## Knowledge

~~~text
mcma knowledge-put
mcma knowledge-check
mcma knowledge-show
mcma knowledge-validate
~~~

confidence and min-confidence are real floating-point values from 0.0 through 1.0.

## Semantic index

Build/rebuild the encrypted semantic index:

~~~text
mcma semantic-index LOCATION --actor=librarian --dimensions=256
~~~

Semantic retrieval:

~~~text
mcma semantic-check LOCATION QUESTION_FILE --actor=ai --min-similarity=0.78 --min-confidence=0.75
~~~

Options:

~~~text
--embedding-provider=bedrock-titan-v2
--dimensions=256|512|1024
--min-similarity=-1.0..1.0
--min-confidence=0.0..1.0
--current=yes|no
~~~

Exact knowledge lookup runs first. Semantic search runs only on an exact miss.

For revalidate/reject/miss results, no remembered answer is emitted.

## Bedrock authentication

Preferred API-key environment:

~~~text
AWS_BEARER_TOKEN_BEDROCK
~~~

SigV4 may use standard AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_SESSION_TOKEN or the MCMA_BEDROCK_* equivalents.

No Bedrock credential is written into MCMA objects.

## Security

The semantic index is derived internal data. AI is denied direct index reads by the default permissions profile; semantic candidate results are filtered through the requesting actor's access.
