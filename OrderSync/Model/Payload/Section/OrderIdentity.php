<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Payload\Section;

use Goodahead\OrderSync\Api\Data\PayloadSectionInterface;
use Magento\Sales\Api\Data\OrderInterface;

/** Which order this is, and where it stands. */
class OrderIdentity implements PayloadSectionInterface
{
    public function build(OrderInterface $order): array
    {
        return [
            'increment_id' => (string)$order->getIncrementId(),
            'store_id' => (int)$order->getStoreId(),
            'created_at' => (string)$order->getCreatedAt(),
            'state' => (string)$order->getState(),
            'status' => (string)$order->getStatus(),
        ];
    }
}
