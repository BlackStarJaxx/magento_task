# Verification — the no-cards tier hides card methods, offline survives

Observed on Magento 2.4.9 / PHP 8.5.6 with `StripeIntegration_Payments` 4.6.5 and
`Goodahead_PaymentTiers` enabled. Quotes were built at four totals and passed through
`Magento\Payment\Model\MethodList::getAvailableMethods()`, the same call the checkout uses.

| Grand total | Tier | Methods offered |
|---|---|---|
| $4,210.00 | all cards | `checkmo`, `banktransfer`, `stripe_payments` |
| $9,262.00 | all cards | `checkmo`, `banktransfer`, `stripe_payments` |
| $17,682.00 | Amex only | `checkmo`, `banktransfer`, `stripe_payments` |
| $21,892.00 | no cards | `checkmo`, `banktransfer` |

Two things this shows deliberately:

- **The card option stays visible in the Amex-only tier.** AC-2 requires it: the customer
  must see the card option with the restriction stated before typing. Hiding the method
  there would make the middle tier unreachable rather than restricted.
- **Offline methods are untouched at every total.** `checkmo` and `banktransfer` are on a
  code-level allow-list (`Model/OfflineMethods`) that the admin field cannot override — a
  unit test asserts that selecting them as "restricted" in configuration has no effect.

## What this is not

The observer is a presentation control. It removes an option that cannot be honoured; it
does not stop a client that never asks Magento what is available. Enforcement is the
placement-time guard, verified separately in `refusal-at-confirm.md`.

## Environment changes made for this run

- `payment/banktransfer/active` set to `1` — core Bank Transfer ships disabled, and AC-8
  names it explicitly. The README lists it as an install step.
- `cataloginventory/item_options/manage_stock` set to `0`, so sample-data stock limits do
  not cap the quantities needed to cross $20,000. Restore before delivery.

## Tests

```
Tests: 43, Assertions: 56, PHPUnit Warnings: 1   (the warning is Magento's Allure extension)
phpcs --standard=Magento2: 0 ERRORS, 68 WARNINGS in 18 files
```
