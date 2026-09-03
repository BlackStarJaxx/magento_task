<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model;

/**
 * Payment methods no tier may ever restrict (AC-8).
 *
 * This is deliberately code and not configuration. The whole point of the top tier is to
 * push large orders towards settlement that carries no chargeback risk; if an administrator
 * could remove those methods from a tier, the highest-value orders would have no way to pay
 * at all. A configurable invariant is not an invariant.
 */
class OfflineMethods
{
    private const ALWAYS_AVAILABLE = [
        'checkmo',
        'banktransfer',
    ];

    public function isAlwaysAvailable(string $methodCode): bool
    {
        return in_array($methodCode, self::ALWAYS_AVAILABLE, true);
    }

    /** @return string[] */
    public function all(): array
    {
        return self::ALWAYS_AVAILABLE;
    }
}
