<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Catalog;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

class PurchasedProductIds
{
    /**
     * @return int[]
     */
    public function fromOrder(OrderInterface $order): array
    {
        $ids = [];

        foreach ((array)$order->getItems() as $item) {
            if (!$item instanceof OrderItemInterface) {
                continue;
            }

            $productId = (int)$item->getProductId();

            if ($productId > 0) {
                $ids[$productId] = $productId;
            }
        }

        return array_values($ids);
    }
}
