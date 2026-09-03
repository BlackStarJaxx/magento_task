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

## Observed in the browser

Luma checkout, guest, Ukraine address, product at $10,000 with a 20% cart rule and flat-rate
shipping.

| Cart | Grand total | Tier | Message | Methods offered |
|---|---|---|---|---|
| qty 1 | $8,005.00 | all cards | none, element hidden | checkmo, banktransfer, stripe_payments |
| qty 2 | $16,010.00 | Amex only | "For orders over $10,000 we can only accept American Express." | checkmo, banktransfer, **stripe_payments** |
| qty 3 | $24,015.00 | no cards | "Orders over $20,000 cannot be paid by card." | checkmo, banktransfer |

The card option staying on offer at $16,010 is AC-2 being met, not a miss. The message renders
above the payment list; the element is present but `display: none` when nothing is restricted.

**Cart edited in another tab.** With the checkout open on the payment step, the cart was
changed in a second tab. On returning to the checkout the message and the method list both
matched the new tier, with no page reload, in both directions — card removed when crossing up
into the no-cards tier, and offered again when crossing back down.

### Two defects this found, which server-side testing had not

1. **Refreshing totals is not enough.** The first implementation called
   `Magento_Checkout/js/action/get-totals`, which updates the message but leaves the payment
   method list as it was. The checkout went on offering a card in the no-cards tier — the
   guard still refused it at placement, but the interface was lying. Fixed by calling
   `get-payment-information`, which returns methods and totals together.
2. **A single refresh on focus can lose a race.** It fired before the other tab's cart update
   had committed, and the throttle then blocked the retry, leaving stale state until the next
   focus. Fixed by subscribing to the `cart` customer-data section — which Magento already
   syncs across tabs, so the trigger is the edit itself rather than the customer happening to
   look back — and by shortening the throttle so a raced refresh is retried rather than
   suppressed.

3. **Reacting to events misses most of the routes.** The first two fixes hooked focus and the
   cart section, but a total can cross a threshold from a coupon, a shipping change, or any
   other refresh Magento performs on its own — and Magento reloads the method list only when
   shipping information is submitted. Observed directly: calling `get-totals` alone moved the
   order from $16,010 to $24,015 and updated the message, while the card stayed on offer.
   Fixed by subscribing to the **tier itself** rather than to the events that might have
   changed it: when `card_available` or the allowed brands change, the component refreshes
   payment information once, guarded against re-entry.

Confirmed in both directions with `get-totals` alone, no page reload:

```
$16,010 -> $24,015   [checkmo, banktransfer, stripe_payments] -> [checkmo, banktransfer]
$24,015 ->  $8,005   [checkmo, banktransfer] -> [checkmo, banktransfer, stripe_payments]
```

All three were invisible to the unit tests and to server-side probing, because all three are
about what the already-rendered page does next.

## Tax counts towards the threshold

The brief defines the tier by "order grand total (goods + tax + shipping)", and
`ComparableAmount` reads `base_grand_total`, which contains all three. Asserted for a long
time, now observed — the same cart, differing only in destination:

| Destination | Tax | Grand total | Tier |
|---|---|---|---|
| Michigan (8.25%) | $751.41 | **$10,434.41** | Amex only |
| Texas (no rate) | $0.00 | $9,683.00 | all brands |

Tax alone moved the order across the threshold. Note that this installation's sample data
taxes Michigan only — its single rule is bound to the US-MI rate, not to California — so a
test written against a Californian address measures nothing.

This is also AC-3's "tax recalculation on address change" clause, and it was broken until the
tier subscription above: a customer choosing a card at $9,683 and then changing the address to
Michigan would have gone on being offered every brand. The method list now follows the tier
whatever moved the total.

## Tests

```
Tests: 59, Assertions: 76
phpcs --standard=Magento2:  0 errors
phpstan level 8:  no errors
```
