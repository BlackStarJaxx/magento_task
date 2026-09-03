<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Payload\Section;

use Goodahead\OrderSync\Api\Data\PayloadSectionInterface;
use Magento\Sales\Api\Data\OrderInterface;

/** Enough to reconcile the order against a person, and no more. */
class Customer implements PayloadSectionInterface
{
    public function build(OrderInterface $order): array
    {
        return [
            'customer' => [
                'email' => (string)$order->getCustomerEmail(),
                'name' => trim((string)$order->getCustomerFirstname() . ' ' . (string)$order->getCustomerLastname()),
                'is_guest' => (bool)$order->getCustomerIsGuest(),
            ],
        ];
    }
}
