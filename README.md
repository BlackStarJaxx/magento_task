# Goodahead backend task

Magento 2.4.9 / PHP 8.5 with the official Stripe module (`StripeIntegration_Payments` 4.6.5).

- **`Goodahead_PaymentTiers`** — restricts card payments by order grand total (Part 1).
- **`Goodahead_OrderSync`** — pushes paid and cancelled orders to finance, and stamps purchase
  recency on the catalogue (Part 2).

Two modules because `PaymentTiers` depends on the Stripe module and `OrderSync` must not: an
order paid by Check / Money Order has to reach finance too.

## Install

The repository root is a `markshust/docker-magento` environment; only `src/app/code/Goodahead`
is ours.

```bash
bin/start
bin/composer require stripe/stripe-php:~20.1
# Stripe module from the vendor's raw-package channel:
#   github.com/stripe/stripe-magento2-releases -> stripe-magento2-4.6.5.tgz, extracted into src/
bin/magento module:enable StripeIntegration_Tax StripeIntegration_Payments \
    Goodahead_PaymentTiers Goodahead_OrderSync
bin/magento setup:upgrade && bin/magento cache:flush
bin/magento config:set payment/banktransfer/active 1   # core Bank Transfer ships disabled; AC-8 needs it
make cron                                              # retries are paced by cron
```

Stripe **test** keys go in Stores → Configuration → Sales → Payment Methods → Stripe.
Consumers run either from `consumers_runner` in `env.php` under cron, or directly:
`bin/magento queue:consumers:start goodahead.ordersync.dispatch`.

To install a module into an existing 2.4.9, copy it into `app/code/` or `composer require` it
from a path repository. Each carries its own `composer.json`.

## Verifying it

```bash
./qa.sh    # phpcs (Magento2), PHPStan level 8 on both modules, 106 unit tests,
           # and greps for the two Definition-of-Done bans (ObjectManager, foreign preference)
```

Two self-tests place real orders and assert what both sides ended up holding. The second needs
the stub, because the endpoint in the brief (`https://example.invalid/orders`) is an RFC 2606
reserved name that never resolves — the shipped default cannot succeed by construction.

```bash
cp docs/verification/part1-selftest.php src/app/code/ \
  && bin/cli php app/code/part1-selftest.php; rm -f src/app/code/part1-selftest.php

cp docs/verification/finance-stub.php src/app/code/
docker compose exec -d phpfpm php -S 0.0.0.0:8099 /var/www/html/app/code/finance-stub.php
bin/magento config:set goodahead_ordersync/endpoint/url http://127.0.0.1:8099/orders
cp docs/verification/part2-selftest.php src/app/code/ \
  && bin/cli php app/code/part2-selftest.php; rm -f src/app/code/part2-selftest.php
```

Part 1's self-test calls `CartManagementInterface::placeOrder` directly — no checkout page, no
JavaScript — which is what AC-1 means by a crafted request. Part 2's drives the real retry path
to a terminal failure and back. Evidence is in [`docs/verification/`](docs/verification); the
decisions are in [`docs/adr/`](docs/adr).

Three things need eyes: between $10,000 and $20,000 the checkout shows the restriction with the
card option still offered and the wallet buttons gone; above $20,000 the card option goes too;
and editing the cart in a second tab updates the message and the method list without a reload.

## Configuration

Stores → Configuration → Sales → **Payment Tiers** (website scope) and **Order Sync**.

| Path | Default |
|---|---|
| `goodahead_payment_tiers/general/enabled` | `1` |
| `goodahead_payment_tiers/general/currency_mode` | `convert_to_usd` |
| `goodahead_payment_tiers/tiers/rows` | `10000.00` all brands · `20000.00` amex · unbounded, none |
| `goodahead_payment_tiers/methods/restricted` | the four Stripe methods that take a card |
| `goodahead_ordersync/endpoint/url` | `https://example.invalid/orders` |
| `goodahead_ordersync/endpoint/timeout` | `10` seconds |
| `goodahead_ordersync/retry/max_attempts` | `6` attempts in total |
| `goodahead_ordersync/retry/base_delay` · `max_delay` | `60` · `3600` seconds, doubling with jitter |

Tier bounds are **inclusive**, exactly one tier must be unbounded, and a tier that narrows
brands must carry a customer message — all enforced when the configuration is saved.

## Part 1 — where enforcement sits

| Layer | Where | Does |
|---|---|---|
| Presentation | observer on `payment_method_is_active` | hides card methods when no brand is allowed |
| Presentation | plugin on Stripe's `ExpressCheckout\Config::isEnabled` | hides wallet buttons in any restricted tier |
| **Enforcement** | plugin on `Helper\PaymentIntent::getConfirmParams` | refuses the payment before Stripe confirms |

The third is the one that holds. Reading the vendor source showed the brief's premise — "the
payment is confirmed from the customer's browser … not against our server" — does not hold for
this module version: the Payment Element flow calls `paymentIntents->confirm()` **server-side,
inside the order transaction, before the order row is written**. So the brand is knowable
before authorisation and a refusal costs nothing: observed refusals leave the intent at
`requires_payment_method` with `amount_received = 0.00` and no order row.

Everything is recomputed from the **order**, never from the intent, which is what makes a
replayed intent harmless: its amount takes no part in the decision.

## Part 2 — how delivery is guaranteed

The endpoint does not deduplicate, and the trigger is unreliable in the other direction: a
Stripe webhook can announce the same order late, twice, out of order. So the guarantee is a
ledger row per logical delivery with `UNIQUE KEY (order_id, event_type)` — the database
decides, not a check-then-insert with a race in the middle (ADR-0006).

Registration happens on `sales_order_save_after` behind a paid-state test, because every route
to a paid order ends in a save. Over-triggering costs one indexed lookup; under-triggering
loses an order silently.

Publishing never throws, so a dead broker means a later delivery rather than a lost one
(AC-10). The cron sweeper delivers inline rather than republishing — it exists for the times
when the broker is down. Retries are exponential with jitter, bounded by a budget, and end in a
terminal state visible in the ledger, as an order comment, and in the log.

`last_purchased_at` is written in **one statement** for the whole order: 8.2 ms for 200
products, against ~42 s for the save-per-product mechanism AC-14 rules out
([evidence](docs/verification/purchase-recency.md)).

## Decisions on what the task left open

- **Currency (AC-6).** The base total is converted to USD by default, so "$10,000" means the
  same exposure on every website; the cost is a dependency on an admin-maintained rate. The
  alternative (`base_currency`) is rate-independent but makes the limit mean 10,000 of whatever
  the store sells in. Switchable.
- **Money never touches a float.** Comparisons are integer minor units via bcmath, because
  `(int)(20000.01 * 100)` is `2000000` — one of AC-9's four values, in the wrong tier. On the
  wire, money is sent as decimal strings for the same reason.
- **Tax and shipping count**, because the tier reads `base_grand_total`. The same cart is
  Amex-only in Michigan ($10,434.41 with tax) and unrestricted in Texas ($9,683.00).
- **"Paid" means authorised or captured.** An authorisation is a committed claim finance will
  reconcile. AC-15 excludes only a *failed* capture, and an unpaid offline order fires nothing.
- **409 from the endpoint is a success**, not a failure — it means an earlier attempt landed.
- **Cancellation applies only to orders Magento will cancel**, i.e. authorised but not
  captured. A captured order is reversed with a credit memo, which the brief puts out of scope.
- **`last_purchased_at` is not rolled back** on cancellation. It records that a purchase
  happened, and one did; restoring a previous value would mean knowing it, which is not stored,
  and would be wrong whenever another order has touched the same product since.
- **Configurable and bundle products stamp both parent and child.** Listings surface the
  parent; the child is what actually sold.
- **Offline methods are an invariant in code**, not configuration. Selecting `checkmo` as
  restrictable in the admin has no effect.
- **Fail closed on money, fail open on presentation.** An unknown order value, an unreadable
  brand or unreadable tier configuration all refuse the payment; the observers catch and log,
  because neither may be why a page fails to render or an order fails to save.
- **The tier decision is recorded on the order** — brand, masked digits, the tier and the
  brands it allowed. The Stripe module stores none of it and the admin fetches the brand live,
  so it vanishes if keys are rotated; and the tier table is configuration, so a year later
  nothing else would explain why a $16,010 order was allowed on Amex.
- **`cctypes` in the Stripe module is dead config** and `card_icons_specific` is cosmetic
  (ADR-0001). **`payment_method_options[card][restrictions]` does not exist on PaymentIntents**
  — it does on Checkout Sessions, but only by moving every customer to a redirect checkout
  (ADR-0003).

## What was cut

- **The post-confirmation backstop.** Express wallet buttons and GraphQL confirm in the browser
  and arrive already paid, so the placement guard never runs for them. Rather than leave a hole,
  wallet buttons are hidden in *every* restricted tier — stricter than AC-5 asks, and it also
  refuses the Amex-funded wallet AC-5 wants accepted. This is the one acceptance criterion not
  fully met, and the first thing to finish.
- **`capture_method: manual` in restricted tiers**, which only matters once that backstop
  exists, so an unwind is an authorisation released rather than a refund issued.
- **Integration tests.** Both self-tests are scripts run by hand rather than by CI.
- **A brand multiselect in the admin.** The tier table uses validated text columns; the brief
  puts admin UI beyond a `system.xml` section out of scope.
- **Non-USD verification.** The conversion path is implemented and unit-tested but never run
  against a store with a non-USD base currency.

## What another week would buy

The backstop first: verify the brand on the order's paid transition, void the uncaptured
authorisation and cancel the order when it does not match, then re-enable wallets in the middle
tier and close AC-5 properly. Then move both self-tests into Magento integration tests so the
refusal and retry paths are covered by CI rather than by a script, add a non-USD website to the
fixtures, and put a small admin grid over the dispatch ledger so an operator does not need SSH
to see a terminal failure.
