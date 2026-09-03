<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Payload\Section;

use Goodahead\OrderSync\Api\Data\PayloadSectionInterface;
use Goodahead\OrderSync\Model\Payload\Money;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * How it was paid. Brand and masked digits only — the full number never reaches this
 * application, so there is nothing else here to send.
 */
class Payment implements PayloadSectionInterface
{
    public function __construct(private readonly Money $money)
    {
    }

    public function build(OrderInterface $order): array
    {
        $payment = $order->getPayment();

        if ($payment === null) {
            return ['payment' => []];
        }

        return [
            'payment' => [
                'method' => (string)$payment->getMethod(),
                'transaction_id' => (string)$payment->getLastTransId(),
                'card_type' => (string)$payment->getCcType(),
                'card_last_4' => (string)$payment->getCcLast4(),
                'amount_paid' => $this->money->format($payment->getBaseAmountPaid()),
            ],
        ];
    }
}
