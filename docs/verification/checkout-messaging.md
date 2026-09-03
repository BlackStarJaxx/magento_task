# Verification — the tier message on the checkout

## What was observed

**The tier rides the totals payload.** Quotes built at three totals, read back through
`CartTotalRepositoryInterface::get()` — the same call the checkout uses both on first render
and on every refresh:

```json
$4,210.00   {"message":"","card_available":true,"allowed_brands":[…8 brands…],"brand_restricted":false}
$17,682.00  {"message":"For orders over $10,000 we can only accept American Express. Other
             cards will be declined.","card_available":true,"allowed_brands":["amex"],
             "brand_restricted":true}
$21,892.00  {"message":"Orders over $20,000 cannot be paid by card. Please use Check / Money
             Order or Bank Transfer.","card_available":false,"allowed_brands":[],
             "brand_restricted":false}
```

Note the middle tier: `card_available` stays `true`. AC-2 requires the card option to remain
on offer above $10,000 with the restriction stated, not to disappear.

**The component reaches the checkout.** The merged `checkout_index_index` layout contains the
node under `beforeMethods`, so it renders above the payment method list:

```
<item name="goodahead-payment-tier"> <item name="component">Goodahead_PaymentTiers/js/view/tier-message</item>
```

**Its assets are served.**

```
/static/frontend/Magento/luma/en_US/Goodahead_PaymentTiers/js/view/tier-message.js   HTTP 200
/static/frontend/Magento/luma/en_US/Goodahead_PaymentTiers/template/tier-message.html HTTP 200
```

## Why it reacts (AC-3)

Nothing bespoke drives the message. It is an extension attribute on
`Magento\Quote\Api\Data\TotalsInterface`, so it is recomputed by the same request that
recomputes the totals — coupon, shipping method, address or tax change. A KO computed bound
to `quote.totals()` re-renders when that payload lands.

The "cart edited in another tab" clause has no such trigger, because nothing on the open page
changes. The component listens for `visibilitychange` and `focus` and refetches totals,
throttled to once every five seconds. Returning to the tab reconciles the tier in one request
with no polling.

AC-3's second clause — an intent created at an older amount must not be honoured on its old
terms — is not a checkout concern at all. The placement guard recomputes the tier from the
order, so the intent's amount never takes part in the decision; see `placement-guard.md`.

## Not yet verified

**The rendered page.** Everything above is server-side observation plus asset availability.
That the message actually appears above the payment methods, and updates when a coupon is
applied, has not been watched in a browser. Until it is, treat AC-2 and AC-3 as implemented
but unconfirmed.

## Tests

```
Tests: 59, Assertions: 76
phpcs --standard=Magento2:  0 errors
phpstan level 8:  no errors
```
