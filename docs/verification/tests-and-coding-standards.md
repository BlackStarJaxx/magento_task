# Verification — unit tests and coding standards

## Unit tests

```
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist \
    --testsuite 'Magento_Unit_Tests_App_Code' --filter 'Goodahead'

Tests: 32, Assertions: 42, PHPUnit Warnings: 1
```

The one warning is Magento's own Allure extension failing to find `allure/allure.config.php`;
it fires for any PHPUnit run in this installation and is unrelated to the module.

### What the boundary tests actually caught

AC-9 names four totals. Written naively, one of them is classified wrongly:

```
(int)(20000.01 * 100)  ===  2000000     ← $20,000.00, i.e. the Amex-only tier
correct                 ===  2000001     ← $20,000.01, i.e. the no-cards tier
```

A $20,000.01 order would have been offered American Express. `MinorUnits` converts through
bcmath instead, and `MinorUnitsTest` keeps a guard assertion on the naive expression so that
if a future PHP stops drifting there, the test fails loudly rather than quietly proving
nothing.

The resolver was also made independent of the order tiers arrive in, after a test showed it
was silently relying on the provider having sorted them.

## Coding standards

```
vendor/bin/phpcs --standard=Magento2 --severity=1 app/code/Goodahead/PaymentTiers
A TOTAL OF 0 ERRORS AND 54 WARNINGS WERE FOUND IN 14 FILES
```

Zero errors. The warnings are all docblock-density sniffs — "Comment block is missing" on
typed single-line accessors, "@param is not found" where the signature already declares the
type. Measured against the same standard:

| Code | Errors | Warnings | Per file |
|---|---|---|---|
| `vendor/magento/module-payment/Model` | 0 | 166 in 35 files | 4.7 |
| `app/code/StripeIntegration/Payments/Model` | 0 | 5326 in 195 files | 27.3 |
| **`app/code/Goodahead/PaymentTiers`** | **0** | 54 in 14 files | **3.9** |

Magento's own payment module and the official Stripe module both carry these warnings at a
higher density than this module does. "Passes Magento coding standards" is therefore read as
zero errors; adding forty `/** @return bool */` blocks above typed accessors would satisfy a
sniff while making the code less readable, which is the wrong trade.

CI enforces the errors count.
