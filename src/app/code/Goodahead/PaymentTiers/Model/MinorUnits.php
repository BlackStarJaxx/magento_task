<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model;

/**
 * Converts money to integer minor units (US cents).
 *
 * Tier boundaries are exact: $10,000.00 belongs to the lowest tier and $10,000.01 does not.
 * Comparing floats cannot express that reliably, so every comparison in this module happens
 * on integers produced here. The one place a float is unavoidable — Magento hands totals
 * over as float — is isolated in toDecimalString(), which prints far more precision than any
 * currency carries before arithmetic starts.
 */
class MinorUnits
{
    /** USD. The thresholds are stated in USD and amounts are converted before they reach here. */
    private const EXPONENT = 2;

    private const PRINT_PRECISION = 8;

    public function fromAmount(float|int|string $amount): int
    {
        $scaled = bcmul($this->toDecimalString($amount), bcpow('10', (string)self::EXPONENT), self::PRINT_PRECISION);

        // bcadd at scale 0 truncates, so adding a half first gives round-half-away-from-zero.
        $rounded = bccomp($scaled, '0', self::PRINT_PRECISION) >= 0
            ? bcadd($scaled, '0.5', 0)
            : bcsub($scaled, '0.5', 0);

        return (int)$rounded;
    }

    public function toAmountString(int $minorUnits): string
    {
        return bcdiv((string)$minorUnits, bcpow('10', (string)self::EXPONENT), self::EXPONENT);
    }

    private function toDecimalString(float|int|string $amount): string
    {
        if (is_string($amount)) {
            $trimmed = trim($amount);

            return $trimmed === '' ? '0' : $trimmed;
        }

        if (is_int($amount)) {
            return (string)$amount;
        }

        return sprintf('%.' . self::PRINT_PRECISION . 'F', $amount);
    }
}
