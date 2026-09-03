<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model\Checkout;

use Goodahead\PaymentTiers\Api\Data\CheckoutTierInterface;
use Magento\Framework\Api\AbstractSimpleObject;

/**
 * Extends AbstractSimpleObject so that __toArray() exists: the checkout config serialises
 * extension attributes through it, and a plain object would reach the browser empty.
 */
class CheckoutTier extends AbstractSimpleObject implements CheckoutTierInterface
{
    public const string MESSAGE = 'message';
    public const string CARD_AVAILABLE = 'card_available';
    public const string ALLOWED_BRANDS = 'allowed_brands';
    public const string BRAND_RESTRICTED = 'brand_restricted';

    public function getMessage(): string
    {
        return (string)$this->_get(self::MESSAGE);
    }

    public function isCardAvailable(): bool
    {
        return (bool)$this->_get(self::CARD_AVAILABLE);
    }

    public function getAllowedBrands(): array
    {
        $brands = $this->_get(self::ALLOWED_BRANDS);

        return is_array($brands) ? $brands : [];
    }

    public function isBrandRestricted(): bool
    {
        return (bool)$this->_get(self::BRAND_RESTRICTED);
    }
}
