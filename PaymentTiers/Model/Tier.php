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
     * @param string[] $allowedMethods governed method codes this tier permits; empty means the
     *                                 tier does not narrow methods and only the brands apply
     */
    public function __construct(
        private readonly ?int $upperBoundMinorUnits,
        private readonly array $allowedBrands,
        private readonly string $message,
        private readonly array $allowedMethods = []
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

    /**
     * An empty list means the tier says nothing about methods, not that it forbids them all.
     * Narrowing is opt-in, so a tier table that only cares about brands stays readable.
     */
    public function allowsMethod(string $methodCode): bool
    {
        return $this->allowedMethods === [] || in_array($methodCode, $this->allowedMethods, true);
    }

    /** @return string[] */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
