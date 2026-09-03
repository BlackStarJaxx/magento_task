# ADR-0005 — What the placement guard refuses, and what it deliberately does not

- **Status:** Accepted
- **Date:** 2026-09-02
- **Context:** Part 1, AC-1, AC-4, AC-5
- **Implements:** the seam identified in ADR-0002

## Context

`TierGuard` runs from a plugin on `StripeIntegration\Payments\Helper\PaymentIntent::getConfirmParams()`,
the last public seam before `paymentIntents->confirm()`. Everything it decides is recomputed
from the **order**, never read from the client or from the intent. That single choice is what
answers AC-1: the order is built server-side from the quote, so an intent created when the
cart was $500 cannot make a $50,000 order look small — the guard never consults the intent's
amount to decide anything.

Three cases needed a deliberate answer rather than a default.

## Decisions

### 1. A wallet is judged by the card funding it

Apple Pay, Google Pay and Link present a card. The confirmation token's
`payment_method_preview` carries `card.brand` with `card.wallet.type` set alongside it, so
the funding brand is available before confirmation and is compared exactly as a directly
entered card would be. AC-5 asks for an Amex-funded wallet to be accepted in the middle tier
and anything else refused, and that is what happens — pre-authorisation, on the same code
path, with no special case.

This holds for the Payment Element flow. Express wallet buttons confirm on the client and
arrive already successful (ADR-0002 §3); the guard cannot refuse those. That path is covered by
the backstop instead — a plugin on `PaymentElement::confirm` reads the brand from the charge and
releases the money when it is not allowed. Restricted tiers force `capture_method: manual`
(`StampIntentForTier`) so releasing is dropping a hold rather than issuing a refund, and the
whole thing still happens before the order row is written.

### 2. A card whose brand cannot be established is refused

If the payment method is a card but no brand comes back, the guard refuses. The alternative —
letting it through — would make the middle tier bypassable by anything that suppresses the
brand, which is precisely the "crafted request" AC-1 is about. Refusing costs a legitimate
customer a retry; allowing costs the restriction entirely.

### 3. Non-card payment methods are not the tiers' business

Stripe's Payment Element can offer SEPA debit, iDEAL, bank transfers and more under the same
Magento method code. The guard checks `type === 'card'` and returns otherwise.

The reason is the one in the brief: the tiers exist because *card* chargebacks cost more than
the margin on large orders. A SEPA debit carries none of that exposure, so restricting it
would impose the cost of the policy without the benefit. This is a judgement, and a merchant
who disagrees can express it — the method list is configuration, so a Stripe method can be
removed from tier governance entirely, though not at brand granularity.

## Consequences

- The middle tier is enforced **before any authorisation**, which is better than the brief's
  framing assumes is reachable. Verified: refused attempts leave the intent at
  `requires_payment_method` with `amount_received = 0.00`, and no order row is written.
- The customer sees the tier's configured message rather than a gateway decline, because the
  guard throws a `LocalizedException` whose text the Stripe module preserves (AC-2).
- The guard duplicates part of what the observer expresses, on purpose. The observer decides
  what to *offer*; the guard decides what to *accept*. A client that never renders the
  checkout is unaffected by the first and stopped by the second.
- Failing closed appears three times — unknown order value, unknown brand, unreadable tier
  configuration. In each case the module refuses cards rather than lifting the restriction,
  and the deliberate escape hatch is the `enabled` flag, not a malformed input.
