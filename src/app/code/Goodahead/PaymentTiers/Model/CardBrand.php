<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model;

/**
 * The card brand vocabulary, normalized to Stripe's `card.brand` values.
 *
 * Stripe reports a brand in more than one spelling depending on where it is read from
 * (`american_express` on some objects, `amex` on others), and admins type by hand. Every
 * brand entering this module is normalized here so comparisons are made on one vocabulary.
 */
class CardBrand
{
    public const AMEX = 'amex';

    private const KNOWN = [
        'visa',
        'mastercard',
        self::AMEX,
        'discover',
        'diners',
        'jcb',
        'unionpay',
        'cartes_bancaires',
    ];

    private const ALIASES = [
        'american_express' => self::AMEX,
        'americanexpress' => self::AMEX,
        'master_card' => 'mastercard',
        'diners_club' => 'diners',
        'union_pay' => 'unionpay',
        'cartesbancaires' => 'cartes_bancaires',
    ];

    public function normalise(string $brand): string
    {
        $key = strtolower(trim($brand));
        $key = (string)preg_replace('/[\s\-]+/', '_', $key);

        return self::ALIASES[$key] ?? $key;
    }

    public function isKnown(string $brand): bool
    {
        return in_array($this->normalise($brand), self::KNOWN, true);
    }

    /** @return string[] */
    public function all(): array
    {
        return self::KNOWN;
    }
}
