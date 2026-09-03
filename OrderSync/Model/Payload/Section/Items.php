<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Payload\Section;

use Goodahead\OrderSync\Api\Data\PayloadSectionInterface;
use Goodahead\OrderSync\Model\Payload\Money;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * What was bought.
 *
 * A configurable order carries a line for the parent and one for each child. Both are sent:
 * the parent holds the money, the child says which variant actually sold, and finance takes
 * the row totals — which are zero on the child, so nothing is double counted.
 */
class Items implements PayloadSectionInterface
{
    public function __construct(private readonly Money $money)
    {
    }

    public function build(OrderInterface $order): array
    {
        $items = [];

        foreach ((array)$order->getItems() as $item) {
            if (!$item instanceof OrderItemInterface) {
                continue;
            }

            $items[] = [
                'sku' => (string)$item->getSku(),
                'name' => (string)$item->getName(),
                'product_id' => (int)$item->getProductId(),
                'product_type' => (string)$item->getProductType(),
                'parent_item_id' => $item->getParentItemId() === null ? null : (int)$item->getParentItemId(),
                'qty_ordered' => $this->money->format($item->getQtyOrdered(), 4),
                'price' => $this->money->format($item->getBasePrice()),
                'row_total' => $this->money->format($item->getBaseRowTotal()),
                'tax_amount' => $this->money->format($item->getBaseTaxAmount()),
            ];
        }

        return ['items' => $items];
    }
}
