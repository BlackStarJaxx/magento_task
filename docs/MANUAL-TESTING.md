# Testing this by hand

Two commands prove most of it. The rest needs eyes, and two things need a Stripe account you
control.

Every command below runs where the store's PHP runs — inside the container, for a Docker
setup — with the Magento root as the working directory. These modules sit at
`app/code/Goodahead`, so the paths are written from there.

Prices below assume this store's 20% cart rule, so watch the **Order Total**, not the price.
`WJ12-XS-Blue` costs $10,000, which puts each tier one quantity apart:

| Qty | Order Total | Tier |
|---|---|---|
| 1 | $8,005 | all cards |
| 2 | **$16,010** | **Amex only** |
| 3 | **$24,015** | **no cards** |

---

## 1. Automated (5 minutes)

```bash
app/code/Goodahead/qa.sh
```

Expect `All checks passed`: phpcs with zero errors, PHPStan level 8 on both modules, 116 unit
tests, and the two Definition-of-Done greps.

```bash
php app/code/Goodahead/docs/verification/part1-selftest.php
```

Expect `All Part 1 checks passed`. It places real orders through
`CartManagementInterface::placeOrder` — no checkout page, no JavaScript — which is the crafted
request AC-1 describes.

For Part 2, start the stub first. The endpoint in the brief never resolves, so nothing can
succeed without it:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8099 \
  app/code/Goodahead/docs/verification/finance-stub.php &

php app/code/Goodahead/docs/verification/part2-selftest.php
```

`PHP_CLI_SERVER_WORKERS` matters: the built-in server is single threaded without it, so the
stub's timeout mode would block every later request instead of only its own.

Expect `All Part 2 checks passed`. It points the module at the stub and puts every setting
back afterwards, even if it fails half way.

---

## 2. Part 1 in the browser

Add `WJ12-XS-Blue` to the cart, go to `/checkout`, reach the payment step.

| Qty | Message | Card option | Wallet buttons |
|---|---|---|---|
| 1 | none | shown | shown |
| 2 | "…only accept American Express" | **shown** | shown |
| 3 | "…cannot be paid by card" | **gone** | **gone** |

The card option staying at qty 2 is the point, not a miss: AC-2 wants the restriction stated
with the option still on offer.

**The real test — pay at qty 2.** Take the numbers from Stripe's current testing docs:

| Card | Expect |
|---|---|
| Visa `4242 4242 4242 4242` | refused, with our wording, **no order created** |
| Amex `3782 822463 10005` | order placed |

After the refusal, open the intent in the Stripe dashboard: it should sit at
`requires_payment_method` with nothing received. That is the whole point — refused **before**
authorisation, not unwound after it.

**Cross-tab.** Stay on the payment step at qty 2, change the cart to 3 in a second tab, come
back. The message and the method list must both update **without a reload**.

If anything looks stale, hard-reload (Cmd+Shift+R) — static assets cache aggressively.

---

## 3. Part 1 in the admin

Stores → Configuration → Sales → **Payment Tiers**. The table should fill the row width and
show four columns: upper bound, allowed brands, allowed methods, customer message.

Try to save each of these; every one should be refused with a readable reason:

- a brand that does not exist, e.g. `bitcoin`
- two rows with an empty upper bound, or none
- a row that narrows brands but has no customer message
- the same upper bound twice
- `ten thousand` as an upper bound
- `checkmo` in the allowed methods column

Then set the middle tier's **allowed methods** to `stripe_payments` and confirm at qty 2 that
the other Stripe methods disappear while that one stays.

---

## 4. Part 2 in the console

After placing an order that was actually paid:

```bash
bin/magento goodahead:ordersync:status
```

With the shipped endpoint (`example.invalid`, which never resolves) you will watch the
**failure** path: `pending`, attempts climbing, `next attempt` moving further out, then
`failed` after six tries with a comment on the order in the admin. Cron drives this on its own,
so the waits follow the backoff — 60s, 120s, 240s.

To watch it succeed, point it at the stub and requeue:

```bash
bin/magento config:set goodahead_ordersync/endpoint/url http://127.0.0.1:8099/orders
bin/magento cache:flush
bin/magento goodahead:ordersync:retry
```

Within a minute `status` shows `succeeded`. Then look at what the endpoint holds:

```bash
curl -s localhost:8099/_state
```

The line that matters is `attempts` against `distinct_deliveries`: however many times we tried,
the endpoint holds one logical delivery per event.

Break it deliberately:

```bash
curl -s -X POST 'localhost:8099/_mode?mode=500'   # or 429, 409, timeout, flaky
```

`409` is worth trying: it is treated as **success**, because it means an earlier attempt landed
and we simply never saw the response.

Put the endpoint back when you are done:

```bash
bin/magento config:set goodahead_ordersync/endpoint/url https://example.invalid/orders
```

---

## 5. The two things that need your Stripe account

**A repeated webhook.** Requires an interactive login, so it cannot be scripted here:

```bash
stripe login
stripe listen --forward-to https://magento.test/stripe/webhooks --skip-verify
# in another shell, after placing an order:
stripe events resend <event_id>
```

Expect the ledger to still hold **one** row for that order and event, and the endpoint to still
show one delivery. That is the same guarantee the self-test exercises with duplicate queue
messages, against a real duplicate webhook.

**A wallet payment.** Apple Pay and Google Pay need a verified domain, which `magento.test`
cannot have. On a domain registered in the Stripe dashboard, pay at qty 2 with a wallet funded
by a Visa: expect a refusal and the authorisation released, with nothing captured. The
mechanism is verified against the sandbox already — a manual-capture intent confirmed with a
Visa reads back as `card / visa / 4242`, sits at `requires_capture` with `amount_received =
0.00`, and ends `canceled` after the release — but no payment has been pushed through a real
wallet sheet.

---

## What would be a real failure

Some things look wrong and are not:

- **No message at exactly $10,000.00.** Bounds are inclusive; the message starts at $10,000.01.
- **A captured order refusing to cancel.** Magento only cancels an order whose payment is
  authorised and not captured; a captured one is reversed with a credit memo, which the brief
  puts out of scope.
- **Deliveries failing out of the box.** `example.invalid` never resolves. That is the shipped
  default and the reason the retry and terminal paths are the default behaviour.

These would be real problems:

- a card refused at qty 1, or accepted at qty 3
- an order created after a refusal, or money captured on one
- the message and the method list disagreeing with each other
- two ledger rows for the same order and event
- `last_purchased_at` changing after a cancellation
