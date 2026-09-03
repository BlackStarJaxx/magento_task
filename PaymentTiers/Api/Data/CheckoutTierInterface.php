<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Api\Data;

/**
 * The tier as the checkout needs to see it.
 *
 * Every method carries an @return annotation because Magento's service-contract reflection
 * reads the docblock, not the PHP return type, and refuses the interface without it.
 */
interface CheckoutTierInterface
{
    /**
     * Customer-facing reason, empty when nothing is restricted.
     *
     * @return string
     */
    public function getMessage(): string;

    /**
     * Whether any card brand may pay for this total.
     *
     * @return bool
     */
    public function isCardAvailable(): bool;

    /**
     * @return string[]
     */
    public function getAllowedBrands(): array;

    /**
     * True when some brands are allowed and some are not.
     *
     * @return bool
     */
    public function isBrandRestricted(): bool;
}
