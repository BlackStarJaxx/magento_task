# Goodahead backend task — Part 1

Restricts card payments by order grand total on Magento 2.4.9 / PHP 8.5 with the official
Stripe module (`StripeIntegration_Payments` 4.6.5).

**Part 2 (finance push, purchase recency) is not implemented yet.** Everything below is Part 1.

## Install

The repository root is a `markshust/docker-magento` environment; only `src/app/code/Goodahead`
is ours. To run the whole thing:

```bash
bin/start                                   # or: docker compose up -d
bin/composer require stripe/stripe-php:~20.1
# Stripe module, from the vendor's own raw-package channel:
#   github.com/stripe/stripe-magento2-releases -> stripe-magento2-4.6.5.tgz, extracted into src/
bin/magento module:enable StripeIntegration_Tax StripeIntegration_Payments Goodahead_PaymentTiers
bin/magento setup:upgrade && bin/magento cache:flush
bin/magento config:set payment/banktransfer/active 1     # core Bank Transfer ships disabled; AC-8 needs it
```

Then set Stripe **test** keys in Stores → Configuration → Sales → Payment Methods → Stripe.

To install only the module into an existing 2.4.9: copy `src/app/code/Goodahead/PaymentTiers`
into `app/code/`, or `composer require goodahead/module-payment-tiers` from a path repository.

## Verifying it

```bash
./qa.sh          # phpcs (Magento2), PHPStan level 8, unit tests, Definition-of-Done greps

cp docs/verification/part1-selftest.php src/app/code/ \
  && bin/cli php app/code/part1-selftest.php; rm -f src/app/code/part1-selftest.php
```

The self-test places real orders in the Stripe sandbox with Stripe's shared test payment
methods, calling `CartManagementInterface::placeOrder` directly — no checkout page, no
JavaScript — which is what AC-1 means by a crafted request. It asserts the AC-9 boundaries,
that offline methods survive every tier, that a restricted tier carries a message, that a
non-Amex card is refused above $10,000 with no order row written, and that refused attempts
leave the Stripe intent unconfirmed with nothing captured.

Three things need eyes rather than a script: at a total between $10,000 and $20,000 the
message appears with the card option still offered and the express wallet buttons gone; above
$20,000 the card option goes too; and editing the cart in a second tab updates both the
message and the method list without reloading the checkout.

## Configuration

Stores → Configuration → Sales → **Payment Tiers**, editable at **website** scope.

| Path | Default |
|---|---|
| `goodahead_payment_tiers/general/enabled` | `1` |
| `goodahead_payment_tiers/general/currency_mode` | `convert_to_usd` |
| `goodahead_payment_tiers/tiers/rows` | three tiers: `10000.00` all brands · `20000.00` amex · unbounded, none |
| `goodahead_payment_tiers/methods/restricted` | the four Stripe methods that take a card |

Bounds are **inclusive**, exactly one tier must be unbounded, and a tier that narrows brands
must carry a customer message — all enforced when the configuration is saved, not at checkout.

## Where enforcement sits

Three layers, deliberately not redundant:

| Layer | Where | Does |
|---|---|---|
| Presentation | observer on `payment_method_is_active` | hides card methods when no brand is allowed |
| Presentation | plugin on Stripe's `ExpressCheckout\Config::isEnabled` | hides wallet buttons in any restricted tier |
| **Enforcement** | plugin on `Helper\PaymentIntent::getConfirmParams` | refuses the payment before Stripe confirms |

The load-bearing one is the third. Reading the vendor source showed the brief's premise —
"the payment is confirmed from the customer's browser … not against our server" — does not
hold for this module version: the Payment Element flow calls `paymentIntents->confirm()`
**server-side, inside the order transaction, before the order row is written**. So the card
brand is knowable before authorisation, and a refusal costs nothing: observed refusals leave
the intent at `requires_payment_method` with `amount_received = 0.00` and no order row.

Everything is recomputed from the **order**, never from the intent, which is what makes a
replayed intent harmless: its amount takes no part in the decision.

## Decisions on what the task left open

Full reasoning in [`docs/adr/`](docs/adr); evidence in [`docs/verification/`](docs/verification).

- **Currency (AC-6).** Default converts the base total to USD, so "$10,000" means the same
  exposure on every website. The cost is a dependency on an admin-maintained rate; the
  alternative (`base_currency`) is rate-independent but makes the limit mean 10,000 of
  whatever the store sells in. Switchable in config.
- **Money never touches a float.** Comparisons are integer minor units via bcmath, because
  `(int)(20000.01 * 100)` is `2000000` — one of AC-9's four values, in the wrong tier.
- **Tax and shipping count**, because the tier reads `base_grand_total`. Verified: the same
  cart is Amex-only in Michigan ($10,434.41 with 8.25% tax) and unrestricted in Texas
  ($9,683.00).
- **Offline methods are an invariant in code**, not configuration. Selecting `checkmo` as
  restrictable in the admin has no effect; a configurable invariant is not one.
- **Fail closed on money, fail open on presentation.** An unknown order value, an unreadable
  brand or unreadable tier configuration all refuse the payment. The observer, by contrast,
  catches and logs — it must never be why a checkout page fails to render.
- **Non-card methods are left alone.** Stripe's Payment Element also offers SEPA, iDEAL and
  others under the same Magento method code. The tiers exist to cap *card* chargeback
  exposure, so restricting those would impose the cost of the policy without its benefit.
- **`cctypes` in the Stripe module is dead config** and `card_icons_specific` is cosmetic —
  both checked against the source before designing around them (ADR-0001).
- **`payment_method_options[card][restrictions]` does not exist on PaymentIntents.** It does
  on Checkout Sessions, which the module supports — but only by moving every customer to a
  redirect checkout, which is a bad trade for a control that matters above $10,000 (ADR-0003).

## What was cut

- **The post-confirmation backstop.** Express wallet buttons and GraphQL confirm in the
  browser and arrive already paid, so the placement guard never runs for them. Rather than
  leave a hole, express buttons are hidden in *every* restricted tier — stricter than AC-5
  asks, and it also refuses the Amex-funded wallet that AC-5 wants accepted. This is the one
  acceptance criterion not fully met, and it is the first thing to finish.
- **`capture_method: manual` in restricted tiers.** Only needed once the backstop exists, so
  that an unwind is an authorisation released rather than a refund issued.
- **Tier metadata on the intent** (`getParamsFrom` plugin) — useful for auditing a dispute,
  not needed for enforcement.
- **A brand multiselect in the admin.** The tier table uses validated text columns; the brief
  puts admin UI beyond a `system.xml` section out of scope.
- **Non-USD verification.** The conversion path is implemented and unit-tested but has not
  been exercised against a store with a non-USD base currency.

## What another week would buy

The backstop first: verify the brand on the order's paid transition, void the uncaptured
authorisation and cancel the order when it does not match, then re-enable wallets in the
middle tier and close AC-5 properly. Then integration tests over the placement guard so the
refusal path is covered by CI rather than by a script, tier metadata on the intent for
dispute auditing, and a non-USD website in the fixture set.

Then Part 2, which has a design but no code: a ledger table with `UNIQUE KEY (order_id,
event_type)` registered inside the order transaction as the exactly-once guarantee, an AMQP
publish after commit so checkout never waits on the finance system, bounded retries with a
terminal state an operator can find, and a batched `insertOnDuplicate` for `last_purchased_at`
so 200 line items cost one query rather than 200 saves.
