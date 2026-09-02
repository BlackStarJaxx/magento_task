# Verification — refusing at the confirm seam creates no order and takes no money

Claim under test (ADR-0002): an exception raised in
`StripeIntegration\Payments\Helper\PaymentIntent::getConfirmParams()` aborts order creation
before the order row is written, and before any authorisation is taken.

This was reasoned from the call chain. The Definition of Done requires it observed.

## Method

Orders were placed programmatically against the Stripe sandbox using Stripe's shared test
payment methods (`pm_card_visa`, `pm_card_amex`) on the legacy `token` path, which the
module accepts at `Model/PaymentMethod.php:328`. `sales_order` was counted before and after
each attempt. A temporary plugin threw a `LocalizedException` at the seam when
`GOODAHEAD_PROBE_REFUSE=1`.

## Baseline — placement works

```
token=pm_card_visa qty=50   grand total 4,210.00   PLACED #000000003 state=processing
token=pm_card_amex qty=50   grand total 4,210.00   PLACED #000000004 state=processing
sales_order rows: 2 -> 3 -> 4
```

## Refusal

```
token=pm_card_visa   REFUSED  StripeIntegration\Payments\Exception\GenericException
                              "For orders over $10,000 we can only accept American Express."
token=pm_card_amex   REFUSED  (same)
sales_order rows: 4 -> 4      ← unchanged
```

## What Stripe saw

```
intent                        status                    amount   received  order
pi_..._04xd0II3               requires_payment_method   4210.00      0.00   000000006
pi_..._0LOH5GpS               requires_payment_method   4210.00      0.00   000000005
pi_..._0dNTRnD1               succeeded                 4210.00   4210.00   000000004
pi_..._18O9idZZ               succeeded                 4210.00   4210.00   000000003

charges: only the two successful orders — brand=amex, brand=visa, both captured
```

The refused attempts left intents at `requires_payment_method` with `amount_received = 0.00`:
created, never confirmed. **No authorisation, no charge, no order.**

## Three findings worth carrying forward

1. **Our message survives.** The module wraps the `LocalizedException` in its own
   `GenericException` but preserves the text verbatim, so AC-2's "comprehensible message
   rather than a raw gateway decline" is satisfied by throwing here.
2. **Default capture is immediate.** `payment_action` defaults to `authorize_capture`, and
   the successful charges came back `captured=yes`. Restricted tiers must force
   `capture_method: manual` so that anything unwound on the wallet path is an authorisation
   released, not a refund issued.
3. **Increment IDs are consumed by refused attempts.** The two refusals reserved
   `000000005` and `000000006`, which exist in Stripe metadata but as no order row. Gaps in
   the order sequence are expected Magento behaviour on failed placement; noted so finance
   does not read a gap as a lost order.

## Reproducing

Environment note: `cataloginventory/item_options/manage_stock` was set to `0` so sample-data
stock limits would not cap large quantities. Restore it before delivery.
