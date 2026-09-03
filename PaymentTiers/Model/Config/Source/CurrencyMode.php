<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CurrencyMode implements OptionSourceInterface
{
    public const CONVERT_TO_USD = 'convert_to_usd';
    public const BASE_CURRENCY = 'base_currency';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => self::CONVERT_TO_USD,
                'label' => __('Convert the total to USD, then compare (thresholds mean US dollars everywhere)'),
            ],
            [
                'value' => self::BASE_CURRENCY,
                'label' => __('Compare the base currency total directly (thresholds mean units of the store currency)'),
            ],
        ];
    }
}
