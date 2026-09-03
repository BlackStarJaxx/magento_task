# Verification — `last_purchased_at` at 200 line items

AC-14 asks that stamping a 200-item order trigger no full reindex, flush no per-product cache,
and not do a save-per-product where something cheaper exists. Measured rather than argued.

## Cost of the three mechanisms

Statement counts come from `SHOW SESSION STATUS LIKE 'Questions'` around each call, on the same
200 simple products. The slower two were measured on a smaller sample and scaled, because
running `ProductRepository::save()` 200 times takes the better part of a minute.

| Mechanism | Statements | Time |
|---|---|---|
| **Batched `insertOnDuplicate`** (shipped) | **1** | **8.2 ms** |
| `Product\Action::updateAttributes()` | ~280 (28 per 20) | ~183 ms |
| `ProductRepository::save()` per product | ~51,200 (1,280 per 5) | ~42 s |

The mechanism the acceptance criterion rules out is four orders of magnitude more expensive
than the one shipped. `Product\Action::updateAttributes()` is the documented middle option and
is named in the README as the right answer if the attribute ever becomes visible.

## What it did not do

```
indexers invalidated : none
rows written         : 200
stored values        : 200
```

The whole run, including resolving the attribute metadata on a cold cache, was 7 statements;
warm, the write itself is a single `INSERT ... ON DUPLICATE KEY UPDATE`.

Nothing was invalidated because nothing indexes this attribute. Verified on the attribute row
itself:

```
is_filterable = 0   is_searchable = 0   used_in_product_listing = 0
is_used_in_grid = 0 used_for_sort_by = 0 is_global = 1
```

That declaration is what makes the cheap write legitimate rather than merely fast: there is no
cache to invalidate and no index to rebuild. If the attribute is ever made visible or
sortable, the write has to change with it.

## Idempotent by value

```
first run  : 200 stored
repeat run : 200 stored  -> idempotent
```

The value written is the order's own `created_at`, not `now()`. A redelivered message, a
second webhook or a manual replay therefore writes an identical row rather than a different
one, which is what makes AC-13's "exactly once" hold without a lock.

## Configurable and bundle products

Both the parent and the child are stamped, and the unit tests pin that down. Stamping only the
child would leave merchandising's "recently purchased" empty for every configurable, because
listings surface the parent; stamping only the parent would lose which variant actually sold.

## Not rolled back on cancellation

AC-15 requires the stamp to survive a cancellation, and it does — nothing in the cancellation
path touches the attribute. `last_purchased_at` records that a purchase happened, and one did.
Restoring a previous value would mean knowing it, which is not stored, and would be wrong
whenever another order has touched the same product since.
