<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Payload\Section;

use Goodahead\OrderSync\Api\Data\PayloadSectionInterface;
use Goodahead\OrderSync\Model\Payload\Money;
use Magento\Sales\Api\Data\OrderInterface;

/** Base-currency totals, which is what the store's own books are kept in. */
class Totals implements PayloadSectionInterface
{
    public function __construct(private readonly Money $money)
    {
    }

    public function build(OrderInterface $order): array
    {
        return [
            'totals' => [
                'subtotal' => $this->money->format($order->getBaseSubtotal()),
                'discount' => $this->money->format($order->getBaseDiscountAmount()),
                'shipping' => $this->money->format($order->getBaseShippingAmount()),
                'tax' => $this->money->format($order->getBaseTaxAmount()),
                'grand_total' => $this->money->format($order->getBaseGrandTotal()),
                'total_paid' => $this->money->format($order->getBaseTotalPaid()),
            ],
        ];
    }
}
