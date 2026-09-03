# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Working agreement

**Never run `git commit`, `git push`, `git rebase`, or anything that rewrites history.** When a
piece of work is finished and verified, hand over **one sentence** suitable as a commit
message and stop. The developer stages, commits and pushes.

Report what was actually observed. "The tests pass" means they were run and the output is in
the transcript. Reasoning from a call chain is a hypothesis; say so and mark it for
verification rather than presenting it as a result.

## Comments

Keep them short. A docblock is one line saying what the method does in a few words, and only
when the signature does not already say it — `getMessage(): string` needs nothing.

Do not explain reasoning, alternatives or trade-offs in code. That belongs in `docs/adr/`,
where a reviewer looks for it and where it will not go stale next to a method that changed.
A comment restating the code is worse than no comment.

The exception, worth a line or two inline: something that looks wrong but is not — a
non-obvious API contract, a deliberate fail-closed branch, an order of operations that
matters. Say *why*, never *what*.

## What this is

A Magento 2 backend task: restrict card payment options by order grand total (Part 1), and
push placed orders to an external finance system with delivery guarantees (Part 2). The
source brief is `Goodahead-Backend-Task.pdf`, and it is graded on where enforcement is
placed, whether it survives a crafted request, and the quality of the reasoning in the
README — not on volume of code.

- Magento Open Source **2.4.9**, PHP **8.5.6**, MariaDB 11.8, OpenSearch, Redis, RabbitMQ
- Official Stripe module `StripeIntegration_Payments` **4.6.5** from the vendor's raw package
  channel, with `stripe/stripe-php` 20.3.1

Write to the standard the task is graded on: think about where a control belongs before
writing it, prefer an extension point that survives an upgrade, and state the trade-off you
took. A working implementation in the wrong layer scores worse than a smaller one in the
right layer.

## Repository layout

**This repository is the `Goodahead` vendor folder.** Its root is exactly what gets copied
into `app/code/Goodahead/` of a Magento installation — nothing above that is tracked.

```
PaymentTiers/        Part 1
OrderSync/           Part 2
phpstan.neon         one config for both, above them: a module ships code, not tooling
qa.sh                the pre-push gate, likewise
docs/adr/            decisions, numbered, shipped
docs/verification/   observed evidence for the Definition of Done
docs/ANALYSIS.md, docs/PLAN.md     working notes, deliberately NOT tracked
```

`docs/adr/` and `docs/verification/` are deliverables. An ADR is written only when a
competent reviewer would ask "why?", the answer is not visible in the code, and a real
alternative was rejected for a real cost. Repository plumbing decisions are README material,
not ADRs.

Two modules because `PaymentTiers` depends on the Stripe module and `OrderSync` must not.

## The environment around it

The development environment is `markshust/docker-magento` and **lives outside this
repository** — four levels up, where this folder sits at `src/app/code/Goodahead`. Its
helper scripts are not tracked here and must never be referenced from anything that ships.

```
<env>/bin/magento, bin/cli, bin/composer     from the environment root, not this one
<env>/src/                                   the Magento root, mapped to /var/www/html
<env>/env/magento.env                        admin credentials
```

Site: `https://magento.test` (self-signed cert — `curl -k`). Deploy mode is `developer`, so
static assets are generated on request.

Core Bank Transfer is enabled in this installation (`payment/banktransfer/active = 1`); it
ships disabled and AC-8 names it, so that is deliberate and the README documents it. Nothing
else in `core_config_data` is a leftover from testing — the tier and OrderSync sections all
run on the defaults declared in `config.xml`.

## Commands

Everything runs inside the `phpfpm` container, whose working directory is `/var/www/html`.
The helper scripts are at the environment root, so from this folder that is `../../../..`:

```bash
cd ../../../..               # the environment root; the rest of this section assumes it
bin/magento <cmd>            # Magento CLI
bin/cli <cmd>                # any command in the container
bin/composer <cmd>           # composer inside the container
bin/cli php app/code/foo.php # run a throwaway script (see the mount caveat below)
```

### The pre-push gate

```bash
bin/cli sh -c "cd app/code/Goodahead && ./qa.sh"
```

It lives beside the modules, not at the repository root, and finds the Magento root by walking
up — no Docker knowledge inside it, because it ships with the modules. On the host `vendor/`
is invisible, so it must be run in the container.

Run it before handing over a commit message, and treat a non-zero exit as "not finished".
It runs, in this order:

1. `phpcs --standard=Magento2 --warning-severity=0`
2. `phpstan analyse` at **level 8**, including Magento's own config for the bootstrap and the
   DataObject reflection extension
3. both modules' unit tests
4. two Definition-of-Done greps: no `ObjectManager` in module code, no `<preference` in XML

Steps 1 and 4 name `PaymentTiers` and `OrderSync` rather than scanning this whole folder:
`docs/verification/` ships throwaway CLI scripts that legitimately `echo`, `exit` and reach
for the ObjectManager, and they would fail a gate meant for module code.

PHPStan is the check that earns its place. It caught a `beforeSave()` whose entire body —
validation included — was unreachable, two calls to `CartInterface` methods the interface
does not declare, and a call to `OrderPaymentInterface::getAdditionalInformation()` with an
argument the interface does not accept. None of those are visible to phpcs or to the unit
tests. If a finding looks like noise, fix the type rather than lowering the level.

Unit tests — note the suite name, `Magento Unit Tests` does not exist here:

```bash
bin/cli sh -c "vendor/bin/phpunit --no-extensions -c dev/tests/unit/phpunit.xml.dist \
  --testsuite 'Magento_Unit_Tests_App_Code' --filter 'Goodahead'"

# one class
... --filter 'Goodahead\\\\PaymentTiers\\\\Test\\\\Unit\\\\Model\\\\TierResolverTest'
```

`--no-extensions` matters: this installation ships Allure without `allure/allure.config.php`,
so the extension fails to bootstrap and PHPUnit exits **1 even when every test passes**.
Disabling extensions drops reporting nobody here uses; it skips no tests. Without the flag a
green run looks red, which is worse than useless in a gate.

Coding standards — stderr carries pages of PHP_CodeSniffer deprecation notices, so filter it:

```bash
bin/cli sh -c "vendor/bin/phpcs --standard=Magento2 --severity=1 \
  --report=summary app/code/Goodahead/PaymentTiers 2>/dev/null"
```

**Zero errors is the bar, warnings are not.** The Magento2 standard's docblock sniffs fire on
typed accessors; `vendor/magento/module-payment/Model` carries 4.7 warnings per file and the
Stripe module 27.3, measured against the same standard. Adding empty `/** @return bool */`
blocks to satisfy a sniff makes the code worse. See
`docs/verification/tests-and-coding-standards.md`.

## Environment caveats that will cost you time

**Only four paths are bind-mounted from the host** (`compose.dev.yaml`): `src/app/code`,
`src/app/design`, `src/app/etc`, and the two composer files. `vendor/`, `var/`, `generated/`
and `pub/` live inside the container volume and are invisible from the host. A throwaway
verification script must therefore be written to `src/app/code/` and bootstrapped with an
absolute path (`require '/var/www/html/app/bootstrap.php';`). Delete it afterwards.

The DB client in the `db` container is `mariadb`, not `mysql`.

The host has no `pdftotext`; `gs -sDEVICE=txtwrite` reads the brief.

## Architecture

### Part 1 — `Goodahead_PaymentTiers`

The single most important fact, established by reading the vendor source and recorded in
ADR-0002: **the Stripe module confirms the PaymentIntent server-side**, inside the order
transaction, before the order row is written.

```
QuoteManagement::submitQuote()                          transaction opens
  PaymentMethod::authorize() → pay()
    PaymentElement::updateFromOrder()
      PaymentIntent::getParamsFrom($order)              seam: intent creation params
    PaymentElement::confirm()
      Helper\PaymentIntent::getConfirmParams(...)       seam: the guard runs here
      $stripeClient->paymentIntents->confirm(...)
  orderRepository->save($order)                         order persisted only now
```

So a card brand is knowable *before* authorisation, and throwing at `getConfirmParams()`
means no auth, no charge, no order. The brief assumes this is impossible; it is not, for this
module version, and the README must say so with the call chain as evidence.

Three layers, and they are not redundant:

| Layer | Where | What it does |
|---|---|---|
| Presentation | `Observer\RestrictCardMethodsByTier` on `payment_method_is_active` | hides card methods when the tier allows **no** cards. Leaves the method visible when only brands are narrowed — AC-2 requires that |
| Enforcement | `Model\TierGuard` via a plugin on `Helper\PaymentIntent::getConfirmParams` | the control that actually holds; recomputes everything from the **order** |
| Backstop | `Plugin\VerifyBrandAfterConfirmation` on `PaymentElement::confirm` | express wallets and GraphQL confirm on the client and arrive already successful; this reads the brand off the charge and releases the payment — cancel if uncaptured, refund if captured |

Domain pieces: `Tier` (immutable, inclusive upper bound), `TierProvider` (config → tiers,
fails closed), `TierResolver` (narrowest containing tier, order-independent), `TierForOrder`
(the shared resolver — `isGoverned()` and `resolve()` are separate so "governed but
unpriceable" means refuse), `MinorUnits` (bcmath, USD only), `CardBrand` (one vocabulary),
`ComparableAmount` (quote/order → USD cents), `RestrictedMethods` + `OfflineMethods`
(policy), `Order\TierDecisionRecorder` (writes the decision onto the order).

`Model\Stripe\` is the only place that knows how the Stripe module carries a payment method:
`BrandReader` before confirmation, `ConfirmedPaymentReader` after it. A plugin on
`Config::getMetadata` stamps the tier — not on the intent creation params, because the module
replaces metadata wholesale in three places downstream of those.

### Part 2 — `Goodahead_OrderSync`

A ledger table with `UNIQUE KEY (order_id, event_type)` is the exactly-once guarantee: the
database decides, not a check-then-insert with a race in the middle (ADR-0006).

```
sales_order_save_after / order_cancel_after
  Observer\Register*Order            registers a ledger row, swallowing its own failures
    ResourceModel\Dispatch::register()   duplicate key = already registered, not an error
  AMQP publish after commit          never throws; a dead broker just leaves the row pending
Cron\DispatchSweeper                 reconciles rows nobody registered, then delivers due ones
```

The observers are wrapped whole, because anything escaping them rolls back the order save.
That makes the sweeper load-bearing rather than decorative: it re-finds paid and cancelled
orders that have no ledger row within a configurable window, so a failure anywhere on the
fast path delays a delivery instead of losing one.

`ResponseClassifier` decides retryable from terminal; **409 counts as success**, because it
means an earlier attempt landed. `Payload\OrderPayloadBuilder` is a composite over
`PayloadSectionInterface` implementations wired in `di.xml`, one per section of the document.
`Catalog\PurchaseRecencyStamper` writes `last_purchased_at` through
`Product\Action::updateAttributes()`, chunked, at default store scope.

## Rules this codebase holds itself to

Four come from the task's Definition of Done and are non-negotiable:

- **No `ObjectManager`** in module code. Throwaway verification scripts may use it.
- **No `preference`** overriding a core or Stripe class where a plugin or documented
  extension point exists. The ban is conditional in the brief's wording, but a plugin on the
  narrowest public method is always preferred.
- **No hardcoded thresholds.** Tiers are configuration at website scope.
- Code passes Magento coding standards.

Four are decisions this module took and should stay consistent with:

- **Money never touches a float.** Comparisons happen on integer minor units via bcmath.
  `(int)(20000.01 * 100)` is `2000000`, which would put an AC-9 boundary value in the wrong
  tier. `MinorUnitsTest` keeps a guard assertion on that expression.
- **Invariants live in code, not configuration.** Offline methods are filtered out of the
  restrictable set even if an administrator selects them: a configurable invariant is not an
  invariant.
- **Fail closed on the money path, fail open on the presentation path.** Unknown order value,
  unreadable tier config or an unreadable card brand all refuse the payment. The observer, by
  contrast, catches and logs — it must never be the reason a checkout page fails to render.
- **Recompute from the order, never trust the intent.** That is what makes a replayed intent
  harmless.

## Known traps

- **PHPUnit 12 ignores annotation metadata.** Use `#[DataProvider('name')]`, not
  `@dataProvider`. Use `createStub()` when only return values are needed; `createMock()`
  without configured expectations emits a notice.
- **Do not name a test helper `run()`** — `TestCase::run()` is final and the file fatals.
- **PhpStorm may rewrite promoted `private readonly` parameters into a `readonly class`.**
  That is a phpcs *error* under the Magento2 standard. Keep `readonly` on properties.
- **`acl.xml` must nest the full path** (`Magento_Backend::admin` → `stores` →
  `stores_settings` → `Magento_Config::config`) with no `title` on re-declared parents.
  Getting it wrong breaks every admin page with "Could not create an acl object", and the
  error names an unrelated core module as the file it failed on.
- **Frontend asset changes need the static version bumped**, or the browser keeps serving the
  old file and a "fix" appears not to work. Static URLs carry `/static/version<n>/`, read from
  `pub/static/deployed_version.txt`. Write it with `printf`, never `date +%s >` — a trailing
  newline lands inside every static URL and takes the whole frontend down.
- **Navigating to the same URL with the same `#fragment` does not reload the page.** A test
  that "reloads" that way is testing the old code.
- **`Api/Data` interfaces need `@return` annotations**, even with PHP return types declared.
  Magento's service-contract reflection reads the docblock; without it any REST call that
  serialises the type dies with "Method's return type must be specified using @return
  annotation". This is the one place the comment policy above yields to a hard requirement.
- **The admin configuration form is table markup**, not `.admin__field` divs: each field is a
  `<tr id="row_{section}_{group}_{field}">` with `<td class="label">` and `<td class="value">`.
