# MCMA Metering, Credits, Billing and SuperAdmin

MCMA can meter AI usage and administer credits without SQL.

## User storage

Billing records live inside each user's encrypted MCMA library as independent logical objects.

~~~text
memory://billing/account
memory://billing/ledger/YYYY/MM/DD
~~~

The daily ledger is authoritative. It contains:

- opening and closing credit state;
- active reservations;
- credit adjustments;
- payments;
- AI usage events;
- input/output/cached/embedding tokens;
- provider/model identity;
- token-count method;
- duration;
- pricing snapshot;
- cost in integer currency micros;
- credits charged;
- web/API origin;
- API key id when applicable.

No money values use floating point.

## Request charging

~~~text
request
  ↓
active service + plan check
  ↓
exact memory lookup
  ↓
first real AI/embedding call
  ↓
lazy credit reservation
  ↓
provider calls
  ↓
usage collector
  ↓
settlement
  ↓
encrypted daily ledger
~~~

Exact reusable memory never triggers an AI reservation.

A suspended/cancelled account is blocked before any answer is served.

## Token counts

Generation providers already expose usage when available.

Embedding metering uses provider-reported counts when available:

- Bedrock Titan: inputTextTokenCount
- Ollama: prompt_eval_count
- llama.cpp: usage.prompt_tokens

If a provider does not expose usage, MCMA stores a conservative estimate and explicitly records:

~~~text
token_count_method = estimated-bytes-upper-bound
~~~

It never labels an estimate as an exact provider count.

## Pricing

Pricing is stored encrypted under the system billing library.

Each exact provider id can define integer rates per one million tokens:

~~~text
input_cost_micros_per_1m
output_cost_micros_per_1m
cached_cost_micros_per_1m
embedding_cost_micros_per_1m

input_credit_units_per_1m
output_credit_units_per_1m
cached_credit_units_per_1m
embedding_credit_units_per_1m
~~~

Every usage record stores the pricing version and rate snapshot used at settlement time.

Billing fails closed when a model is called while its pricing is missing.

Zero-cost local models are supported by configuring explicit zero rates.

## Credits

Credit units are integer application credits.

Ledger event types include:

~~~text
credit
adjustment
reservation
release
usage
payment
~~~

A request reserves credits before the first paid AI call and settles against actual usage afterward.

## Plans

Built-in initial plan definitions:

~~~text
free
starter
pro
business
~~~

Plans control:

- API enabled;
- embeddings enabled metadata;
- requests per minute;
- daily AI requests;
- concurrent requests;
- max credit units per request;
- optional monthly credit allowance;
- optional monthly AI token limit;
- allowed providers.

The built-in Free plan defaults to 20 AI-backed requests per day, 100,000 AI tokens per UTC month and a 100,000-credit monthly target balance. The allowance is a top-up, not an additive grant: unused Free credits do not stack month after month. A plan value of 0 for a monthly allowance/limit means that specific monthly rule is disabled.

Quota accounting is lazy and database-free. The first billing summary or AI-backed request in a UTC month records an idempotent encrypted allowance event in that user's daily billing ledger. Exact reusable memory that makes no provider call creates no billing reservation, so it does not consume the daily AI-request quota or monthly AI-token quota.

The encrypted plan catalog can be changed by SuperAdmin.

## API keys

External applications can authenticate with:

~~~text
Authorization: Bearer mcma_api_...
~~~

The plaintext key is returned once on creation.

MCMA stores only an HMAC of the token in the encrypted API-key registry.

Routes:

~~~text
GET    /mcma/v1/api-keys
POST   /mcma/v1/api-keys
DELETE /mcma/v1/api-keys/{key_id}
~~~

An API request resolves to the same user library and billing account as the web session.

## User billing routes

~~~text
GET /mcma/v1/billing
GET /mcma/v1/billing/usage?date=YYYY-MM-DD
~~~

The browser dashboard displays:

- plan;
- service state;
- available credits;
- total AI tokens;
- credits consumed;
- API keys.

## SuperAdmin

The initial SuperAdmin is configured server-side:

~~~text
MCMA_SUPERADMIN_ISSUER
MCMA_SUPERADMIN_SUBJECT
~~~

Only its HMAC identity fingerprint is persisted.

SuperAdmin routes do not expose memory content or Vault secrets.

~~~text
GET  /mcma/v1/admin/users
GET  /mcma/v1/admin/users/{user_id}/billing

POST /mcma/v1/admin/users/{user_id}/credits
POST /mcma/v1/admin/users/{user_id}/plan
POST /mcma/v1/admin/users/{user_id}/service
POST /mcma/v1/admin/users/{user_id}/access
POST /mcma/v1/admin/users/{user_id}/payments

POST /mcma/v1/admin/pricing
POST /mcma/v1/admin/plans/{plan_id}
~~~

The web panel is:

~~~text
/admin.html
~~~

Administrative actions are written to encrypted daily audit objects:

~~~text
memory://admin/audit/YYYY/MM/DD
~~~

## Payments

The billing ledger can record already verified payments from:

~~~text
stripe
paypal
mercadopago
bank-transfer
manual
~~~

A provider payment reference is idempotent: the same provider/payment id cannot be credited twice.

MCMA does not store card or bank credentials.

Stripe Checkout is implemented for both one-time and recurring subscription packages.

Routes:

~~~text
GET  /mcma/v1/billing/stripe/packages
POST /mcma/v1/billing/stripe/checkout
POST /mcma/v1/billing/stripe/webhook
~~~

A Stripe package is configured server-side with `billing_mode=payment|subscription`, a Stripe Price id, plan id, credit units, currency, amount in the currency minor unit, and minor-unit exponent.

For `payment`, `credit_units` are granted once when the Checkout Session is confirmed paid.

For `subscription`, the Stripe Price must be recurring and `credit_units` are granted on every successfully paid subscription invoice, including the initial invoice and future renewal invoices.

Checkout Sessions are created server-side. MCMA binds the authenticated user and package to Stripe using `client_reference_id` and metadata. Subscription packages also copy the binding into `subscription_data.metadata` so renewal events can be resolved without a database. A package fingerprint is embedded so a changed package configuration is rejected instead of silently applying new terms.

The webhook uses the raw request body and Stripe-Signature header. It checks timestamp tolerance and HMAC-SHA256 signature before parsing the event.

The webhook handles:

~~~text
checkout.session.completed
checkout.session.async_payment_succeeded
invoice.paid
invoice.payment_failed
customer.subscription.created
customer.subscription.updated
customer.subscription.deleted
customer.subscription.paused
customer.subscription.resumed
~~~

For one-time packages, paid Checkout Sessions are fulfilled idempotently by Checkout Session id.

For subscriptions, Checkout never grants recurring credits directly. MCMA retrieves the Stripe Subscription, verifies its metadata and configured recurring Price, stores its lifecycle state, and waits for `invoice.paid`. Every paid invoice is fulfilled idempotently by invoice id, so webhook retries cannot duplicate renewal credits.

Subscription behavior:

- `active` / `trialing`: paid plan may remain active;
- `past_due`: state is recorded while Stripe performs configured retries; MCMA does not immediately revoke the plan;
- `canceled`, `unpaid`, `paused`, `incomplete_expired`: the subscription's paid-plan benefit is removed and the user returns to Free while keeping memory and previously acquired credits;
- stale events from an older subscription do not override a newer current subscription.

Paid invoices additionally verify the configured Stripe Price, package fingerprint, currency and expected package amount before granting credits.

PayPal and Mercado Pago remain recorded-payment types until their own live checkout/webhook connectors are implemented.

## Important configuration

~~~text
MCMA_BILLING_ENABLED=true
MCMA_API_KEY_PEPPER=32+ random bytes
MCMA_SUPERADMIN_ISSUER=...
MCMA_SUPERADMIN_SUBJECT=...
MCMA_WEB_BILLING_MAX_OUTPUT_TOKENS=1024
MCMA_STRIPE_SECRET_KEY=...
MCMA_STRIPE_WEBHOOK_SECRET=...
MCMA_STRIPE_PACKAGES_JSON=...

# Example package modes:
# {"credits-once":{"billing_mode":"payment",...},
#  "pro-monthly":{"billing_mode":"subscription",...}}
~~~

Do not enable paid model billing until pricing entries are configured.

Multi-user deployments must continue to leave MCMA_MASTER_KEY_B64 unset and use MCMA_KEY_DIR.
