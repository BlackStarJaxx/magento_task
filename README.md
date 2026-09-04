# Goodahead payment tiers and order sync

Two Magento 2 modules for Magento Open Source 2.4.9 / PHP 8.5, built against the official
Stripe module (`StripeIntegration_Payments` 4.6.5).

- **`Goodahead_PaymentTiers`** — restricts card payments by order grand total (Part 1).
- **`Goodahead_OrderSync`** — pushes paid and cancelled orders to finance, and stamps purchase
  recency on the catalogue (Part 2).

Two modules rather than one because `PaymentTiers` depends on the Stripe module and `OrderSync`
must not: an order paid by Check / Money Order has to reach finance too.

## Install

This repository is the `Goodahead` vendor folder. Copy it into a Magento 2.4.9 installation:

```bash
cp -r . <magento-root>/app/code/Goodahead/
```

or require the modules from a path repository, `goodahead/module-payment-tiers` and
`goodahead/module-order-sync`. Then, from the Magento root:

```bash
bin/magento module:enable Goodahead_PaymentTiers Goodahead_OrderSync
bin/magento setup:upgrade && bin/magento cache:flush
```

`OrderSync` needs cron running, because retries are paced by it, and its queue consumer
running — either through `consumers_runner` in `env.php` or directly:

```bash
bin/magento queue:consumers:start goodahead.ordersync.dispatch
```

`PaymentTiers` additionally needs `StripeIntegration_Payments` installed and its **test** keys
set. Core Bank Transfer ships disabled and AC-8 names it, so enable it if it is not already:
`bin/magento config:set payment/banktransfer/active 1`.

## Configuration

Stores → Configuration → Sales → **Payment Tiers** and **Order Sync**. Both sections are
declared at website scope, so a multi-site store can hold different limits per website.

`Goodahead_PaymentTiers`:

| Path | Default |
|---|---|
| `goodahead_payment_tiers/general/enabled` | `1` |
| `goodahead_payment_tiers/general/currency_mode` | `convert_to_usd` |
| `goodahead_payment_tiers/tiers/rows` | `10000.00` all brands · `20000.00` amex · unbounded, none |
| ↳ per-tier columns | upper bound · allowed brands · **allowed methods** (blank = all) · customer message |
| `goodahead_payment_tiers/methods/restricted` | the four Stripe methods that take a card |

`Goodahead_OrderSync`:

| Path | Default |
|---|---|
| `goodahead_ordersync/endpoint/url` | `https://example.invalid/orders` |
| `goodahead_ordersync/endpoint/timeout` | `10` seconds |
| `goodahead_ordersync/retry/max_attempts` | `6` attempts in total |
| `goodahead_ordersync/retry/base_delay` | `60` seconds |
| `goodahead_ordersync/retry/max_delay` | `3600` seconds |
| `goodahead_ordersync/reconcile/window_days` | `7` days the cron sweeper looks back |

The retry policy is exponential from `base_delay`, doubling each attempt with jitter and
capped at `max_delay`; after `max_attempts` the row becomes terminal rather than retrying
further. The reconciliation window is configurable so that installing on a store with history
does not dispatch everything ever placed.

Tier bounds are **inclusive**, exactly one tier must be unbounded, and a tier that narrows
brands must carry a customer message — all enforced when the configuration is saved, along
with unknown brands, unknown method codes, duplicate bounds and an offline method placed in
the methods column. No threshold appears in code (AC-7).

The methods column narrows the governed methods for one tier; blank means the tier says
nothing about methods and only the brand rule applies, which is what the defaults do.

## `Goodahead_PaymentTiers` — where enforcement sits

| Layer | Where | Does |
|---|---|---|
| Presentation | observer on `payment_method_is_active` | hides card methods when no brand is allowed |
| Presentation | plugin on Stripe's `ExpressCheckout\Config::isEnabled` | hides wallet buttons when no card is allowed |
| **Enforcement** | plugin on `Helper\PaymentIntent::getConfirmParams` | refuses the payment before Stripe confirms |
| **Backstop** | plugin on `PaymentElement::confirm` | checks the brand of a payment confirmed in the browser and releases it |

The no-cards tier goes through the `payment_method_is_active` event rather than a
`SpecificationInterface` registered into `MethodList`, because a `MethodList`-only hook does
not cover order placement: `isAvailable()` is called there directly, which is precisely the
path a hostile client takes.

The third row is the one that holds. Reading the vendor source showed the brief's premise — "the
payment is confirmed from the customer's browser … not against our server" — does not hold for
this module version: the Payment Element flow calls `paymentIntents->confirm()` **server-side,
inside the order transaction, before the order row is written**. So the brand is knowable before
authorisation and a refusal costs nothing: observed refusals leave the intent at
`requires_payment_method` with `amount_received = 0.00` and no order row.

Everything is recomputed from the **order**, never from the intent, which is what makes a
replayed intent harmless (AC-1): its amount takes no part in the decision.

Express wallets and GraphQL do not reach that guard — they confirm in the browser and arrive
already paid — so the backstop reads the brand from the charge afterwards and unwinds the
payment when it is not allowed: it cancels an authorisation that has not been captured, and
refunds one that has.

Forcing `capture_method: manual` for restricted tiers, so the unwind is always the cheaper of
the two, was tried and reverted: capture method is part of the contract between the intent and
the Stripe Elements instance the browser created, and setting it server-side alone makes Stripe
refuse the confirmation outright. A merchant who wants the cleaner unwind sets Stripe's payment
action to authorise only, which keeps both sides in agreement.

## `Goodahead_OrderSync` — how delivery is guaranteed

The endpoint does not deduplicate, and the trigger is unreliable in the other direction: a
Stripe webhook can announce the same order late, twice, out of order. So the guarantee is a
ledger row per logical delivery with `UNIQUE KEY (order_id, event_type)` — the database decides,
not a check-then-insert with a race in the middle.

Duplicate protection is layered, and the two layers are independent. The Stripe module keeps
its own `UNIQUE KEY` on the webhook event id, so a replayed webhook is answered "already
processed" and never reaches this module at all. Deleting that row to force a genuine
reprocess was tried, and the ledger still held one delivery — which is the point: the
guarantee is derived from the order, not from the trigger, so it does not care whether a
duplicate arrived by webhook, by queue redelivery, or by another save.

Registration happens on `sales_order_save_after` behind a paid-state test, because every route
to a paid order ends in a save. Over-triggering costs one indexed lookup; under-triggering loses
an order silently. Registration and publishing both swallow their own failures, and a cron
sweeper re-finds paid or cancelled orders that have no ledger row, so a failure anywhere on that
path delays a delivery rather than losing one.

Publishing never throws, so a dead broker means a later delivery rather than a lost one (AC-10).
The sweeper delivers inline rather than republishing — it exists for the times when the broker
is down. Retries are exponential with jitter, bounded by a budget, and end in a terminal state
visible in the ledger, as an order comment, and in the log.

`last_purchased_at` is written with `Product\Action::updateAttributes()`, Magento's own bulk
attribute writer: 85 ms for 200 products against ~42 s for the save-per-product mechanism AC-14
rules out. A raw batched insert was measured at 10 ms and rejected — the 75 ms is not worth
owning the EAV table layout and skipping the attribute event other extensions listen to.

## Verifying it

```bash
./qa.sh    # phpcs (Magento2), PHPStan level 8 over both modules, 116 unit tests, and greps
           # for the two Definition-of-Done bans (ObjectManager, foreign preference)
```

Enforcement was verified by bypass rather than by using the checkout as intended: orders
placed straight through `CartManagementInterface::placeOrder`, with no checkout page and no
JavaScript, and an intent created while the cart was below a threshold then replayed once it
had crossed one. A refused attempt leaves the intent at `requires_payment_method` with
`amount_received = 0.00`, and no order row is written.

The delivery path was exercised against a local stub, because the endpoint in the brief
(`https://example.invalid/orders`) is an RFC 2606 reserved name that never resolves — the
shipped default cannot succeed by construction.

What was observed rather than reasoned about:

| Claim | What was seen |
|---|---|
| Cards restricted at the boundary | $16,005: Visa refused before authorisation, Amex accepted; the order records `AE`, `0005` and the tier that allowed it |
| Below $10,000 unchanged | small carts and a $22 checkout completed with no restriction and no message |
| Offline never restricted | a **$25,025.40** order placed through Check / Money Order |
| Exactly one delivery per order | four duplication attempts produced one row; the ledger holds 42 rows against 42 distinct `(order, event)` keys |
| Retries bounded, failure visible | a persistent 500 drove a dispatch to a terminal state, discoverable by CLI, and an operator retry recovered it |
| Cancellation reaches finance | one order delivered `order_placed`, was cancelled, and delivered `order_cancelled` |
| The stamp is not rolled back | that order's product still carries the `last_purchased_at` written at placement, five seconds before the cancellation |
| The attribute stays out of the way | declared with `is_filterable`, `used_in_product_listing` and `is_searchable` all `0` |
| A repeated webhook changes nothing | a real `payment_intent.succeeded`, forwarded by `stripe listen` and then replayed twice with a valid signature, left one ledger row, one invoice and the original stamp |

This repository ships the modules; the throwaway scripts that produced those observations are
not part of it.

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
- **Wallets stay on offer in the brand-restricted tier**, because AC-5 wants an Amex-funded one
  accepted there. They are hidden only when no card is allowed at all; what makes that safe is
  the backstop, which releases a wrong-brand payment immediately after confirmation.
- **Cancellation applies only to orders Magento will cancel**, i.e. authorised but not captured.
  A captured order is reversed with a credit memo, which the brief puts out of scope.
- **`last_purchased_at` is not rolled back** on cancellation (AC-15). It records that a purchase
  happened, and one did; restoring a previous value would mean knowing it, which is not stored,
  and would be wrong whenever another order has touched the same product since.
- **Configurable and bundle products stamp both parent and child.** Listings surface the parent;
  the child is what actually sold.
- **Offline methods are an invariant in code**, not configuration. Selecting `checkmo` as
  restrictable in the admin has no effect.
- **Fail closed on money, fail open on presentation.** An unknown order value, an unreadable
  brand or unreadable tier configuration all refuse the payment; the observers catch and log,
  because neither may be why a page fails to render or an order fails to save.
- **The tier decision is recorded on the order** — brand, masked digits, the tier and the brands
  it allowed. The Stripe module stores none of it and the admin fetches the brand live, so it
  vanishes if keys are rotated; and the tier table is configuration, so a year later nothing
  else would explain why a $16,010 order was allowed on Amex.
- **`cctypes` in the Stripe module is dead config** — it ships a default listing exactly the
  brands this task cares about, and nothing reads it; `card_icons_specific` is cosmetic.
  **`payment_method_options[card][restrictions]` does not exist on PaymentIntents** — it is
  real, but only on Checkout Sessions, and reaching it would mean moving every customer to a
  redirect checkout.

## What was cut

- **A wallet payment driven through a real Apple Pay or Google Pay sheet.** The backstop and the
  release are verified against the sandbox with a confirmed manual-capture intent, and the
  decision logic is unit-tested, but no payment has been pushed through an actual wallet UI —
  those need domain verification a local host cannot have.
- **Integration tests.** Both self-tests are scripts run by hand rather than by CI.
- **A brand multiselect in the admin.** The tier table uses validated text columns; the brief
  puts admin UI beyond a `system.xml` section out of scope.
- **Non-USD verification.** The conversion path is implemented and unit-tested but never run
  against a store with a non-USD base currency.

## What another week would buy

Drive a payment through a real wallet sheet on a verified domain, and replay a Stripe webhook
with the CLI, so the two paths that are currently reasoned about and unit-tested are observed as
well. Then move both self-tests into Magento integration tests so the refusal and retry paths
are covered by CI rather than by a script, add a non-USD website to the fixtures, and put a
small admin grid over the dispatch ledger so an operator does not need SSH to see a terminal
failure.
