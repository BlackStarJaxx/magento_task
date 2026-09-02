# ADR-0004 — The no-cards tier is enforced on `payment_method_is_active`

- **Status:** Accepted
- **Date:** 2026-09-02
- **Context:** Gate G3. Part 1, AC-8, and the "> $20,000" tier
- **Verified against:** Magento 2.4.9, `StripeIntegration_Payments` 4.6.5, PHP 8.5.6

## Question

The top tier hides cards entirely. Magento offers two upgrade-safe places to express
"this method is not available for this quote":

- **A.** an observer on the `payment_method_is_active` event
- **B.** a `Magento\Payment\Model\Checks\SpecificationInterface` registered into
  `SpecificationFactory::$mapping` and added to `MethodList::$methodSpecificationAttributes`

Both avoid touching a Stripe class. The choice turns on which one actually runs on every
path a hostile client can reach — a `MethodList`-only hook is not enough, because
`isAvailable()` is also called directly during order placement.

The risk with A was specific: `StripeIntegration\Payments\Model\PaymentMethod` **overrides**
`isAvailable()`, so the event might never be dispatched.

## Evidence

A throwaway observer logged every dispatch while quotes were built at three totals.
The event fires for the Stripe adapter, with the quote attached:

```
[G3PROBE] code=stripe_payments available=yes total=9262
  callers=Event\Manager::dispatch
       <- Payment\Model\Method\Adapter::isAvailable
       <- StripeIntegration\Payments\Model\PaymentMethod::isAvailable
```

Reading the override confirms why (`Model/PaymentMethod.php:561`): it ends with

```php
return $hasNonBillableSubscriptionItems || ... || parent::isAvailable($quote);
```

`Adapter::isAvailable()` is what dispatches the event, and the override reaches it on the
ordinary checkout path.

### The exact limitation, stated rather than glossed

Three early returns bypass `parent::` and therefore the event:

| Guard | When it is true |
|---|---|
| `$this->checkoutFlow->isPaymentMethodAvailable()` | only `isRecurringSubscriptionOrderBeingPlaced \|\| isSwitchingSubscriptionPlan` (`Model/Checkout/Flow.php:40`) |
| `$this->checkoutSessionHelper->isSubscriptionUpdate()` | subscription plan changes |
| `$this->config->isRedirectPaymentFlow() && !express && !multishipping && !admin` | returns **false** — method hidden anyway, harmless |

All three are subscription paths. This task has no subscriptions, so the event covers every
reachable case here — but if the store later sells Stripe subscriptions, an observer-only
control would be bypassed when a recurring order is placed. That is why the observer is
**not** the only control: the placement-time guard (ADR-0002) closes it regardless.

### Stripe registers six payment methods, not one

The probe also showed `stripe_payments_invoice` dispatching alongside `stripe_payments`.
`etc/config.xml` defines six method codes: `stripe_payments`, `stripe_payments_express`,
`stripe_payments_checkout`, `stripe_payments_invoice`, `stripe_payments_bank_transfers`,
`stripe_payments_subscriptions`. The top tier must therefore hide a **set**, resolved from
configuration, not a single hardcoded code.

## Decision

Use **A**, an observer on `payment_method_is_active`, because it fires on both the
`MethodList` path and the direct `isAvailable()` path, and because the event carries the
quote, so the tier is computed from the same object the totals come from.

`SpecificationInterface` (B) is recorded as the rejected alternative: equally upgrade-safe,
arguably more idiomatic, but it only runs through `MethodList`.

The observer is a **presentation-layer control**. It hides what must not be offered; it is
not what makes the restriction hold. Enforcement is the placement-time guard.

## Consequences

- Offline methods are never touched by the observer. `checkmo` and `banktransfer` sit on an
  allow-list that no tier configuration can remove (AC-8).
- Two environment facts surfaced and must be handled before delivery: core `banktransfer`
  is **not enabled** in this install, and `stripe_payments_bank_transfers` is a *Stripe*
  method, not the core offline one the brief names. The README states which methods count as
  "offline" and why.
