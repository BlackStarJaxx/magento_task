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
    public const string AMEX = 'amex';

    private const array KNOWN = [
        'visa',
        'mastercard',
        self::AMEX,
        'discover',
        'diners',
        'jcb',
        'unionpay',
        'cartes_bancaires',
    ];

    private const array ALIASES = [
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

    /**
     * Magento's own card-type codes, as used by sales_order_payment.cc_type. Anything Magento
     * has no code for is recorded as "Other" rather than invented.
     */
    private const MAGENTO_CODES = [
        'visa' => 'VI',
        'mastercard' => 'MC',
        self::AMEX => 'AE',
        'discover' => 'DI',
        'diners' => 'DN',
        'jcb' => 'JCB',
        'unionpay' => 'UN',
    ];

    public function toMagentoCode(string $brand): string
    {
        return self::MAGENTO_CODES[$this->normalise($brand)] ?? 'OT';
    }

    /** @return string[] */
    public function all(): array
    {
        return self::KNOWN;
    }
}
