# ADR-0003 — Gateway-level brand restrictions exist, but only in a flow we are not using

- **Status:** Accepted
- **Date:** 2026-09-02
- **Context:** Gate G1. Part 1, AC-4
- **Verified against:** Stripe test mode, `stripe/stripe-php` 20.3.1, default API version

## Question

Can Stripe itself refuse a non-Amex card, so that the middle tier is enforced at the gateway
with no authorisation and no order? The candidate parameter is
`payment_method_options[card][restrictions][brands_blocked]`, documented for Payment Links
and Checkout Sessions but absent from the PaymentIntent parameter reference.

This mattered because a gateway-side refusal is the cleanest possible answer to AC-4.

## Experiment

Run against the sandbox using the Stripe client the module itself builds, so the same key
and API version as production code. Three creation attempts at $15,000 (the middle tier):

| # | Request | Result |
|---|---|---|
| 1 | PaymentIntent, no restrictions | **OK** — `pi_3UBJJRG9o1xOpgpH1jqpPYOp`, `payment_method_options.card` returned with `installments`, `mandate_options`, `network`, `request_three_d_secure` — and no `restrictions` key |
| 2 | PaymentIntent + `restrictions.brands_blocked = [visa, mastercard]` | **Rejected** — `InvalidRequestException: Received unknown parameter: payment_method_options[card][restrictions]` |
| 3 | PaymentIntent + `restrictions.brands_allowed = [american_express]` | **Rejected** — same error |

A negative result is only worth as much as its control, so the same parameter was sent to a
Checkout Session:

| # | Request | Result |
|---|---|---|
| 4 | Checkout Session + `restrictions.brands_blocked = [visa, mastercard]` | **OK** — `cs_test_a1qlho…`, and Stripe echoed `{"brands_blocked":["visa","mastercard"]}` back on the object |

So the parameter name is correct and the feature is real. It is simply **not implemented on
PaymentIntents**.

## The trade-off this exposes

The module supports both flows (`payment/stripe_payments/payment_flow`,
`Model/Adminhtml/Source/PaymentFlow.php`):

| Value | Flow | Stripe object | `restrictions` |
|---|---|---|---|
| `0` (default) | "Embed payment form into the native flow" — Payment Element | PaymentIntent | **unavailable** |
| `1` | "Redirect customers to Stripe Checkout" | Checkout Session | **available** |

Gateway-enforced brand restriction is therefore reachable — at the price of moving the whole
store from an embedded checkout to a redirect.

## Decision

**Do not switch flows. Do not depend on `brands_blocked`.**

1. The cost is paid by every order, not by the restricted tier. Redirecting all customers off
   the Magento checkout to gain a control that matters above $10,000 is a bad trade, and the
   brief scopes Part 1 to "the frontend checkout page only".
2. It is not needed. ADR-0002 established that the Payment Element flow confirms
   **server-side**, so the brand can be read from the confirmation token and refused before
   any authorisation. That is the same outcome — no auth, no order — without changing the
   checkout.
3. It would not be sufficient anyway. Express wallet buttons and Link confirm on the client
   and arrive already authorised, so the post-confirmation backstop is required regardless of
   which flow is configured.

## Consequences

- Gate G1 is closed negative, and nothing depends on it — ADR-0002 had already moved the
  primary control off this parameter.
- The README names this as a considered and rejected alternative, with the flow trade-off
  stated. It is a real fork in the road, not a missing feature.
- If a future requirement ever forces Stripe Checkout for other reasons, `brands_blocked`
  becomes available and is worth adding as defence in depth. Recorded here so that is not
  rediscovered from scratch.

## Reproducing

The two throwaway probe scripts are not part of the deliverable and were deleted after the
run. The requests are three lines each against `paymentIntents->create` and
`checkout->sessions->create`; the exact payloads and the verbatim error are in the table
above.
