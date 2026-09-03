# ADR-0006 — Exactly-once delivery when the endpoint will not help

- **Status:** Accepted
- **Date:** 2026-09-03
- **Context:** Part 2, AC-10, AC-11, AC-12, AC-13

## Context

The brief describes the finance endpoint as one that "times out, returns 500s, and will
occasionally accept the same order twice without complaint". That last clause is the design
constraint: the endpoint does **not** deduplicate. Every guarantee has to come from our side.

At the same time the trigger is unreliable in the other direction. With Stripe an order can
become paid on a webhook that arrives late, twice, and out of order relative to the browser —
so the same order can plausibly be announced to us several times.

Unreliable trigger, unreliable transport, no help from the far side.

## Decisions

### The database gives the guarantee, not our code

A ledger row per logical delivery, with `UNIQUE KEY (order_id, event_type)`. Registration is a
plain `INSERT` and a duplicate-key error is the answer, not an error.

The alternative — check whether a row exists, then insert — has a window between the two
statements that two concurrent webhooks fit through comfortably. Asking the database to decide
removes the window rather than narrowing it.

The same key travels to the endpoint as `Idempotency-Key`, readable rather than hashed
(`goodahead-000000042-order_placed`), so the two systems can be compared by a human when
something goes wrong.

### Register from several triggers on purpose

Hunting for the single correct event in a payment module that may change its flow next release
is a losing game. Registration happens from `sales_order_save_after` behind a paid-state test,
because every route to a paid order — the customer's request, a webhook, an invoice raised by
hand — ends in a save.

Over-triggering costs one indexed lookup. Under-triggering loses an order in silence. With the
unique key in place the first is free, so the trade is not close.

### The queue is the fast path; cron is the one that cannot fail

Publishing happens after registration and never throws: the row is already committed, so a
broker that is down means a later delivery, not a lost one (AC-10).

The cron sweeper delivers **inline** rather than republishing. Republishing would be tidier,
but it would make the sweeper depend on the broker — and the sweeper exists for the times when
something is down. Both paths go through the same processor and the same atomic claim, so they
cannot collide.

The consumer never re-queues a failed message itself. That would spin a hot loop against an
endpoint that is already struggling; retries are paced by cron and exponential backoff with
jitter, so a batch that failed together does not return together.

### 409 is a success

| Response | Meaning | Why |
|---|---|---|
| timeout, DNS failure, 5xx, 429 | retry | transient; the network may come back |
| **409 Conflict** | **succeeded** | conventional "already have it" — an earlier attempt landed and we never saw the response |
| other 4xx | terminal, no retries | a malformed request will be malformed next time too |

Counting 409 as a failure would spend the retry budget on an order finance already holds, and
could end with a terminal-failed row for a delivery that actually succeeded.

## Consequences

- Exactly-once is demonstrable rather than asserted: the verification stub records every
  attempt with its key, so a run can show the endpoint was hit four times and holds one
  delivery.
- Terminal failures are findable three ways — the ledger, a comment on the order, and a
  dedicated log line — because operators look in different places depending on what they were
  already doing.
- An operator retry resets the attempt count. Someone pressing retry is stating that the cause
  is fixed; keeping an exhausted counter would fail the row again without trying anything.
- Cancellation only applies to an order Magento will actually cancel — one whose payment is
  authorised but not captured. A captured order is reversed with a credit memo, which the
  brief puts out of scope.
