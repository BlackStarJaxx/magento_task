<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Payload;

use Goodahead\OrderSync\Model\Dispatch\IdempotencyKey;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

class OrderPayloadBuilder
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly IdempotencyKey $idempotencyKey,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(OrderInterface $order, string $eventType): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event' => $eventType,
            'idempotency_key' => $this->idempotencyKey->forOrder($order, $eventType),
            'occurred_at' => $this->dateTime->gmtDate('c'),
            'order' => [
                'increment_id' => (string)$order->getIncrementId(),
                'store_id' => (int)$order->getStoreId(),
                'created_at' => (string)$order->getCreatedAt(),
                'state' => (string)$order->getState(),
                'status' => (string)$order->getStatus(),
                'currency' => [
                    'order' => (string)$order->getOrderCurrencyCode(),
                    'base' => (string)$order->getBaseCurrencyCode(),
                    'base_to_order_rate' => $this->amount($order->getBaseToOrderRate(), 4),
                ],
                'totals' => [
                    'subtotal' => $this->amount($order->getBaseSubtotal()),
                    'discount' => $this->amount($order->getBaseDiscountAmount()),
                    'shipping' => $this->amount($order->getBaseShippingAmount()),
                    'tax' => $this->amount($order->getBaseTaxAmount()),
                    'grand_total' => $this->amount($order->getBaseGrandTotal()),
                    'total_paid' => $this->amount($order->getBaseTotalPaid()),
                ],
                'customer' => [
                    'email' => (string)$order->getCustomerEmail(),
                    'name' => trim((string)$order->getCustomerFirstname() . ' ' . (string)$order->getCustomerLastname()),
                    'is_guest' => (bool)$order->getCustomerIsGuest(),
                ],
                'payment' => $this->payment($order),
                'items' => $this->items($order),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(OrderInterface $order): array
    {
        $payment = $order->getPayment();

        if ($payment === null) {
            return [];
        }

        return [
            'method' => (string)$payment->getMethod(),
            'transaction_id' => (string)$payment->getLastTransId(),
            'card_type' => (string)$payment->getCcType(),
            'card_last_4' => (string)$payment->getCcLast4(),
            'amount_paid' => $this->amount($payment->getBaseAmountPaid()),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(OrderInterface $order): array
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
                'qty_ordered' => $this->amount($item->getQtyOrdered(), 4),
                'price' => $this->amount($item->getBasePrice()),
                'row_total' => $this->amount($item->getBaseRowTotal()),
                'tax_amount' => $this->amount($item->getBaseTaxAmount()),
            ];
        }

        return $items;
    }

    private function amount(mixed $value, int $precision = 2): string
    {
        return number_format((float)$value, $precision, '.', '');
    }
}
