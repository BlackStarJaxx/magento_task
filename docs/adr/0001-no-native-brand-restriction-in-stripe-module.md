# ADR-0001 — The Stripe module exposes no native card-brand restriction

- **Status:** Accepted
- **Date:** 2026-09-02
- **Context:** Part 1, AC-4 (Amex-only tier), AC-5 (wallets follow the same rule)
- **Applies to:** `StripeIntegration_Payments` 4.6.5, Magento 2.4.9, PHP 8.5.6

## Context

The middle tier requires that, above $10,000 and at or below $20,000, only American
Express is accepted. The brief states that card brands sit inside a single Stripe payment
method behind the Payment Element, so Magento has no object called "Visa" to switch off.

Before designing around that, one thing had to be ruled out: the module ships a
`cctypes` default listing exactly the brands we care about —

```xml
<!-- etc/config.xml:30 -->
<cctypes>visa,mastercard,amex,jcb,discover,diners,cartes_bancaires</cctypes>
```

If that were live, the Amex-only tier would be a supported configuration value and the
whole of §3.4 of the analysis would be unnecessary. It is the single cheapest possible
solution to the hardest requirement, so it had to be checked first.

## Investigation

Against the installed 4.6.5 source tree:

| Question | Finding |
|---|---|
| Where does `cctypes` appear? | `etc/config.xml:30` only — one occurrence in the entire module |
| Is there an admin field? | No. Absent from every `etc/adminhtml/system/*.xml` include, so unreachable through the UI |
| Does any PHP read it? | No. `Model/Config.php:555` reads `card_icons_specific`; nothing reads `cctypes` |
| Is the matching source model wired? | No. `Model/Adminhtml/Source/CcType.php` exists and is referenced by nothing — not `system.xml`, not `di.xml`, not any class |

`cctypes` is a leftover from the pre-Payment-Element era, when the module rendered
Magento's own credit-card form and had a `cc_type` to constrain. In 4.6.5 both the config
node and its source model are dead code.

The only live brand list is `card_icons_specific`, and it is cosmetic:

```
etc/adminhtml/system/payments.xml:108   multiselect, depends on card_icons=1 and payment_flow=1
Model/Config.php:555 → Model/Ui/ConfigProvider.php:209 → checkout JS
```

It reaches the checkout config provider and selects which **icons** are drawn. Setting it
to Amex alone changes the pictures; a Visa card still pays. That is precisely the control
the brief dismisses in advance: *"a client-side filter is not a control at all."*

## Decision

Treat brand restriction as **not supported by the module**. Specifically:

1. `cctypes` is not used, and is not resurrected by writing to it from our code — nothing
   reads it, so a value there would be a lie in the config table.
2. `card_icons_specific` is not used as a control. It may be adjusted for coherent
   presentation, but never counted as enforcement.
3. Enforcement is built where it can actually hold — on the Stripe object itself and on
   the server before order creation. See ADR-0002 for the seams, and the gate G1 ADR for
   `payment_method_options[card][restrictions]` if it turns out to exist.

## Consequences

- The middle tier costs real work; no configuration shortcut exists. This is the expected
  outcome, and it confirms the brief's framing rather than contradicting it.
- We inherit a hazard worth writing down: a future maintainer who finds `cctypes` in
  `config.xml` will reasonably assume it works. The README notes it as dead config so the
  next person does not repeat this investigation, or worse, ship it as a control.
- The negative result is itself deliverable: the obvious answer was tested against the
  source and rejected with evidence, not skipped.

## Evidence

Reproducible against the installed module:

```bash
cd src/app/code/StripeIntegration/Payments
grep -rn "cctypes" --exclude-dir=resources .          # 1 hit: etc/config.xml:30
grep -rn "Source\\\\CcType\|Source/CcType" .           # 0 hits — the source model is orphaned
grep -rn "card_icons_specific" Model/                  # Config.php:555, Ui/ConfigProvider.php
```
