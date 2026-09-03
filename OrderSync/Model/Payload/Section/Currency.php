<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Payload\Section;

use Goodahead\OrderSync\Api\Data\PayloadSectionInterface;
use Goodahead\OrderSync\Model\Payload\Money;
use Magento\Sales\Api\Data\OrderInterface;

/** What the amounts below are denominated in, and the rate they were converted at. */
class Currency implements PayloadSectionInterface
{
    public function __construct(private readonly Money $money)
    {
    }

    public function build(OrderInterface $order): array
    {
        return [
            'currency' => [
                'order' => (string)$order->getOrderCurrencyCode(),
                'base' => (string)$order->getBaseCurrencyCode(),
                'base_to_order_rate' => $this->money->format($order->getBaseToOrderRate(), 4),
            ],
        ];
    }
}
