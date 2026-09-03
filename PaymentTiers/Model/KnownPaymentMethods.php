<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model;

use Magento\Payment\Model\Config as PaymentConfig;

/**
 * The payment method codes this store actually has.
 *
 * Used to reject a typo in the tier table at save time rather than let it silently narrow
 * nothing at checkout.
 */
class KnownPaymentMethods
{
    public function __construct(private readonly PaymentConfig $paymentConfig)
    {
    }

    public function isKnown(string $methodCode): bool
    {
        return array_key_exists($methodCode, $this->paymentConfig->getActiveMethods());
    }
}
