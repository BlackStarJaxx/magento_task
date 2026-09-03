<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Api\Data;

/**
 * The tier as the checkout needs to see it.
 */
interface CheckoutTierInterface
{
    /** Customer-facing reason, empty when nothing is restricted. */
    public function getMessage(): string;

    /** Whether any card brand may pay for this total. */
    public function isCardAvailable(): bool;

    /** @return string[] */
    public function getAllowedBrands(): array;

    /** True when some brands are allowed and some are not. */
    public function isBrandRestricted(): bool;
}
