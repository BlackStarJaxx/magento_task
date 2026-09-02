<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model;

/**
 * One configured band of order value, and what may pay for it.
 *
 * Immutable: a tier is resolved from the current total and then read, never mutated. The
 * upper bound is INCLUSIVE — a total equal to the bound belongs to this tier (AC-9).
 */
class Tier
{
    /**
     * @param int|null $upperBoundMinorUnits null means unbounded; there is exactly one such tier
     * @param string[] $allowedBrands normalised brand codes; empty means no cards at all
     */
    public function __construct(
        private readonly ?int $upperBoundMinorUnits,
        private readonly array $allowedBrands,
        private readonly string $message
    ) {
    }

    public function getUpperBoundMinorUnits(): ?int
    {
        return $this->upperBoundMinorUnits;
    }

    public function isUnbounded(): bool
    {
        return $this->upperBoundMinorUnits === null;
    }

    public function contains(int $amountMinorUnits): bool
    {
        return $this->isUnbounded() || $amountMinorUnits <= $this->upperBoundMinorUnits;
    }

    public function allowsAnyCard(): bool
    {
        return $this->allowedBrands !== [];
    }

    public function allowsBrand(string $normalisedBrand): bool
    {
        return in_array($normalisedBrand, $this->allowedBrands, true);
    }

    /** @return string[] */
    public function getAllowedBrands(): array
    {
        return $this->allowedBrands;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
