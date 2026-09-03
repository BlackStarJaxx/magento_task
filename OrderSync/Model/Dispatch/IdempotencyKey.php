<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Dispatch;

use Magento\Sales\Api\Data\OrderInterface;

class IdempotencyKey
{
    private const PREFIX = 'goodahead';

    public function forOrder(OrderInterface $order, string $eventType): string
    {
        return $this->forIncrementId((string)$order->getIncrementId(), $eventType);
    }

    public function forIncrementId(string $incrementId, string $eventType): string
    {
        return self::PREFIX . '-' . $incrementId . '-' . $eventType;
    }
}
