<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model\Stripe;

/**
 * What is known about the payment method a customer is about to pay with, read before the
 * payment is confirmed. All fields may be null: Stripe's Payment Element offers non-card
 * methods too, and a tier governs cards.
 */
class PaymentMethodDetails
{
    public function __construct(
        private readonly ?string $type,
        private readonly ?string $brand,
        private readonly ?string $wallet,
        private readonly ?string $last4 = null
    ) {
    }

    public function isCard(): bool
    {
        return $this->type === 'card';
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function getWallet(): ?string
    {
        return $this->wallet;
    }

    public function getLast4(): ?string
    {
        return $this->last4;
    }
}
