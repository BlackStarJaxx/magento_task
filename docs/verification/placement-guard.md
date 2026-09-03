# Verification — the tier holds against a placement that bypasses the checkout UI

Orders were placed by calling `CartManagementInterface::placeOrder()` directly with Stripe's
shared test payment methods. Nothing rendered a checkout page, so nothing the observer does
is involved: this exercises only the placement-time guard, which is what AC-1 means by a
crafted request.

## Result

| Tier | Card | Grand total | Outcome | `sales_order` |
|---|---|---|---|---|
| all cards | visa | $4,210.00 | placed `#000000007` | 4 → 5 |
| Amex only | visa | $17,682.00 | **refused** | 5 → 5 |
| Amex only | amex | $17,682.00 | placed `#000000009` | 5 → 6 |
| no cards | amex | $21,892.00 | **refused** | 6 → 6 |

The refusal messages are the ones configured on the tier, verbatim:

```
For orders over $10,000 we can only accept American Express. Other cards will be declined.
Orders over $20,000 cannot be paid by card. Please use Check / Money Order or Bank Transfer.
```

## What Stripe saw

```
intent                       status                    amount   received  order
pi_..._1tWarJLZ              requires_payment_method  21892.00      0.00  000000010   refused
pi_..._0V1XBsib              succeeded                17682.00  17682.00  000000009   placed
pi_..._0qKlkw59              requires_payment_method  17682.00      0.00  000000008   refused
pi_..._1eZ2SR9k              succeeded                 4210.00   4210.00  000000007   placed
```

Both refusals left the intent at `requires_payment_method` with `amount_received = 0.00`.
No authorisation was taken, no charge was created, and no order row exists — the restriction
is enforced before the money moves, not unwound afterwards.

## Tests

```
Tests: 53, Assertions: 63          (the one PHPUnit warning is Magento's Allure extension)
phpcs --standard=Magento2:  0 ERRORS, 90 WARNINGS in 22 files
```
