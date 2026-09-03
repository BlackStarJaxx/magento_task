<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Order;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

class PaidStateDetector
{
    private const UNPAID_STATES = [
        Order::STATE_NEW,
        Order::STATE_PENDING_PAYMENT,
        Order::STATE_CANCELED,
    ];

    public function isPaid(OrderInterface $order): bool
    {
        if (in_array((string)$order->getState(), self::UNPAID_STATES, true)) {
            return false;
        }

        $payment = $order->getPayment();

        if ($payment === null) {
            return false;
        }

        return (float)$payment->getBaseAmountPaid() > 0.0
            || (float)$payment->getBaseAmountAuthorized() > 0.0;
    }
}
