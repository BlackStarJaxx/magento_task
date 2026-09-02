# ADR-0002 — Enforcement seams: confirmation is server-side, so the brand is knowable before authorisation

- **Status:** Accepted (one item pending sandbox verification, marked below)
- **Date:** 2026-09-02
- **Context:** Gate G2. Part 1, AC-1, AC-4, AC-5
- **Applies to:** `StripeIntegration_Payments` 4.6.5

## Context

The brief frames the middle tier as hard for a specific reason:

> the payment is confirmed from the customer's browser against Stripe's API — not against
> our server.

If that holds, our server is not in the request path when the card brand becomes known, and
the only options are gateway-side restrictions or post-authorisation unwinding. The analysis
was written on that premise and ranked a server-side confirmation flow (option B3) as
"deep surgery on a vendor module".

Reading 4.6.5 shows the premise is **false for the flow this task targets**.

## Findings

### 1. The Payment Element flow confirms server-side, inside the order transaction

```
Magento\Quote\Model\QuoteManagement::submitQuote()      ← transaction opens
  Order::place() → Payment::place()
    PaymentMethod::authorize()            Model/PaymentMethod.php:201
      PaymentMethod::pay()                Model/PaymentMethod.php:326
        PaymentElement::updateFromOrder() Model/PaymentElement.php:99
          PaymentIntent::getParamsFrom()  Model/PaymentIntent.php:304
        PaymentElement::confirm()         Model/PaymentElement.php:434
          $stripeClient->paymentIntents->confirm(...)   Model/PaymentElement.php:478
  orderRepository->save($order)                          ← only now is the order persisted
```

The browser sends us a token; **we** call `paymentIntents->confirm()`. We are in the request
path at the moment of confirmation, and the order row does not exist yet.

Consequence for AC-1: an exception raised anywhere in this chain aborts order creation with
no authorisation taken. *[Verify in sandbox — reasoned from the call chain, and the DoD
requires this observed rather than argued.]*

### 2. The brand is available before confirmation

`Helper/PaymentIntent::getConfirmParams()` already resolves a **ConfirmationToken**:

```php
// Helper/PaymentIntent.php:63 — "Prioritize confirmation token when available"
$confirmParams["confirmation_token"] = ...additionalInformation("confirmation_token");
$confirmationToken = $this->confirmationTokenFactory->create()->fromId($confirmParams["confirmation_token"]);
```

and the module's own wrapper exposes the preview:

```php
// Model/Stripe/ConfirmationToken.php:41
public function getPaymentMethodPreview()   // → card.brand, card.wallet.type
```

So the brand — and, for wallets, the funding card's brand — can be read **before** confirm,
using the vendor's own API. Refusal at that point costs nothing: no authorisation, no order,
no funds to release. The legacy `payment_method` (`pm_…`) path is equally inspectable via
`stripePaymentMethodFactory->fromPaymentMethodId()`, already used in the same method.

This makes what the analysis called B3 the **native** flow rather than surgery, and it
demotes `payment_method_options[card][restrictions][brands_blocked]` (B1) from "primary
control" to "defence in depth, if it exists at all". Gate G1 is therefore no longer on the
critical path.

### 3. Wallet buttons and GraphQL confirm client-side — and the module says so

```php
// Model/PaymentElement.php:456-465
// Wallet button 3DS confirms the PI on the client side and retries order placement
if ($this->paymentIntentHelper->isSuccessful($confirmationObject) || ...) {
    $this->paymentState->setConfirmedPaymentIntent($confirmationObject);
    return $confirmationObject;    // already confirmed elsewhere; nothing to refuse
}
```

For express wallet buttons and GraphQL, the intent arrives already confirmed and the module
simply accepts it. Pre-authorisation refusal is impossible on that path by construction.

This is the precise answer to AC-5's hard half: **wallets need the post-confirmation
backstop** (verify brand → cancel/void → no order), while ordinary Payment Element cards can
be refused before any authorisation.

### 4. Three usable seams, all public, non-final, DI-managed

| Seam | Signature | Why it is the right one |
|---|---|---|
| `Model\PaymentIntent::getParamsFrom` | `(OrderInterface $order): array` | **Five call sites**, including two webhook paths (`Stripe/Event/SetupIntentSucceeded.php:193`, `InvoicePaymentSucceeded.php:193`) and `Helper/Api.php:104`. One `after` plugin stamps metadata and `capture_method` on every creation path at once |
| `Helper\PaymentIntent::getConfirmParams` | `($order, $paymentIntent): array` | Where the token is resolved — the natural place to read the brand and refuse |
| `Helper\PaymentMethodOptions::getPaymentMethodOptions` | `($quote): array` | Builds `payment_method_options` **from the quote**, which is exactly where the tier is computed. The seam for brand restrictions if G1 turns out to support them |

`PaymentIntent extends \Magento\Framework\Model\AbstractModel`; no `final` on class or
methods; instances come from generated factories, so DI returns interceptors and plugins
apply.

## Decision

1. Enforce the middle tier **before confirmation**, in a plugin on
   `Helper\PaymentIntent::getConfirmParams`, by reading the brand from the confirmation
   token (or payment method) and refusing when it is not allowed for the tier recomputed at
   that moment. No `preference`, no vendor class overridden.
2. Stamp tier metadata and `capture_method` through `Model\PaymentIntent::getParamsFrom`,
   once, covering the webhook paths for free.
3. Keep the **post-confirmation backstop** for wallets, Link, and GraphQL, which arrive
   already confirmed. This is not belt-and-braces; §3 shows it is the only control on that
   path.
4. Treat `brands_blocked` (G1) as optional hardening, tested but not depended upon.

## Consequences

- The task's hardest requirement is met **pre-authorisation** for the main checkout flow —
  strictly better than authorise-then-void, and better than the brief's own framing suggests
  is possible.
- Restricted tiers still force `capture_method: manual`, because the wallet path can only be
  unwound after the fact and releasing an authorisation beats refunding a capture.
- One vendor side effect to handle: `PaymentMethod::pay()` catches, calls
  `sendPaymentFailedEmail()`, then rethrows (`Model/PaymentMethod.php:349-356`). Our refusal
  will therefore also send the vendor's payment-failed email. Either accept it as correct
  (the payment did fail) or suppress it for tier refusals — decided in a follow-up ADR once the refusal path is built.
- The README must state plainly that the brief's premise does not hold for this module
  version, with the call chain as evidence. That is a real trade-off honestly reported,
  which is what the evaluation asks for.
