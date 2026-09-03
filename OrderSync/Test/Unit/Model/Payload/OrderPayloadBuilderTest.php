<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Test\Unit\Model\Payload;

use Goodahead\OrderSync\Model\Dispatch\EventType;
use Goodahead\OrderSync\Model\Dispatch\IdempotencyKey;
use Goodahead\OrderSync\Api\Data\PayloadSectionInterface;
use Goodahead\OrderSync\Model\Payload\Money;
use Goodahead\OrderSync\Model\Payload\OrderPayloadBuilder;
use Goodahead\OrderSync\Model\Payload\Section\Currency;
use Goodahead\OrderSync\Model\Payload\Section\Customer;
use Goodahead\OrderSync\Model\Payload\Section\Items;
use Goodahead\OrderSync\Model\Payload\Section\OrderIdentity;
use Goodahead\OrderSync\Model\Payload\Section\Payment;
use Goodahead\OrderSync\Model\Payload\Section\Totals;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use PHPUnit\Framework\TestCase;

class OrderPayloadBuilderTest extends TestCase
{
    private OrderPayloadBuilder $builder;

    protected function setUp(): void
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-09-03T08:00:00+00:00');

        $money = new Money();

        $this->builder = new OrderPayloadBuilder(new IdempotencyKey(), $dateTime, [
            new OrderIdentity(),
            new Currency($money),
            new Totals($money),
            new Customer(),
            new Payment($money),
            new Items($money),
        ]);
    }

    public function testCarriesASchemaVersionSoTheContractCanChangeLater(): void
    {
        $payload = $this->builder->build($this->order(), EventType::ORDER_PLACED);

        self::assertSame(OrderPayloadBuilder::SCHEMA_VERSION, $payload['schema_version']);
        self::assertSame('order_placed', $payload['event']);
        self::assertSame('goodahead-000000042-order_placed', $payload['idempotency_key']);
    }

    /**
     * Money must survive a JSON round trip through any parser, so it goes out as decimal
     * strings rather than as JSON numbers.
     */
    public function testSendsMoneyAsFixedPrecisionStrings(): void
    {
        $totals = $this->builder->build($this->order(), EventType::ORDER_PLACED)['order']['totals'];

        foreach ($totals as $field => $value) {
            self::assertIsString($value, $field . ' must be a string');
            self::assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $value, $field);
        }

        self::assertSame('16010.00', $totals['grand_total']);
        self::assertSame('-4000.00', $totals['discount']);
    }

    public function testSendsOnlyMaskedCardDetails(): void
    {
        $payment = $this->builder->build($this->order(), EventType::ORDER_PLACED)['order']['payment'];

        self::assertSame('AE', $payment['card_type']);
        self::assertSame('8431', $payment['card_last_4']);
        self::assertArrayNotHasKey('card_number', $payment);
    }

    public function testMapsItemsExplicitly(): void
    {
        $items = $this->builder->build($this->order(), EventType::ORDER_PLACED)['order']['items'];

        self::assertCount(1, $items);
        self::assertSame('MJ08-L-Green', $items[0]['sku']);
        self::assertSame('2.0000', $items[0]['qty_ordered']);
        self::assertSame('16000.00', $items[0]['row_total']);
        self::assertNull($items[0]['parent_item_id']);
    }

    public function testCancellationIsADistinctEvent(): void
    {
        $payload = $this->builder->build($this->order(), EventType::ORDER_CANCELLED);

        self::assertSame('order_cancelled', $payload['event']);
        self::assertSame('goodahead-000000042-order_cancelled', $payload['idempotency_key']);
    }

    /**
     * The contract is extended by adding a section, not by editing the builder. A store that
     * has to send its own field should not need to fork this module.
     */
    public function testASectionAddedByAnotherModuleReachesThePayload(): void
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-09-03T08:00:00+00:00');

        $extra = new class implements PayloadSectionInterface {
            public function build(OrderInterface $order): array
            {
                return ['warehouse' => 'east'];
            }
        };

        $builder = new OrderPayloadBuilder(new IdempotencyKey(), $dateTime, [new OrderIdentity(), $extra]);
        $payload = $builder->build($this->order(), EventType::ORDER_PLACED);

        self::assertSame('east', $payload['order']['warehouse']);
        self::assertSame('000000042', $payload['order']['increment_id'], 'existing sections still contribute');
    }

    public function testTheEnvelopeIsBuiltEvenWithNoSections(): void
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-09-03T08:00:00+00:00');

        $payload = (new OrderPayloadBuilder(new IdempotencyKey(), $dateTime, []))
            ->build($this->order(), EventType::ORDER_PLACED);

        self::assertSame([], $payload['order']);
        self::assertSame('goodahead-000000042-order_placed', $payload['idempotency_key']);
    }

    private function order(): OrderInterface
    {
        $item = $this->createStub(OrderItemInterface::class);
        $item->method('getSku')->willReturn('MJ08-L-Green');
        $item->method('getName')->willReturn('Lando Gym Jacket');
        $item->method('getProductId')->willReturn(330);
        $item->method('getProductType')->willReturn('simple');
        $item->method('getParentItemId')->willReturn(null);
        $item->method('getQtyOrdered')->willReturn(2.0);
        $item->method('getBasePrice')->willReturn(10000.0);
        $item->method('getBaseRowTotal')->willReturn(16000.0);
        $item->method('getBaseTaxAmount')->willReturn(0.0);

        $payment = $this->createStub(OrderPaymentInterface::class);
        $payment->method('getMethod')->willReturn('stripe_payments');
        $payment->method('getLastTransId')->willReturn('pi_123');
        $payment->method('getCcType')->willReturn('AE');
        $payment->method('getCcLast4')->willReturn('8431');
        $payment->method('getBaseAmountPaid')->willReturn(16010.0);

        $order = $this->createStub(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCreatedAt')->willReturn('2026-09-03 08:00:00');
        $order->method('getState')->willReturn('processing');
        $order->method('getStatus')->willReturn('processing');
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getBaseCurrencyCode')->willReturn('USD');
        $order->method('getBaseToOrderRate')->willReturn(1.0);
        $order->method('getBaseSubtotal')->willReturn(20000.0);
        $order->method('getBaseDiscountAmount')->willReturn(-4000.0);
        $order->method('getBaseShippingAmount')->willReturn(10.0);
        $order->method('getBaseTaxAmount')->willReturn(0.0);
        $order->method('getBaseGrandTotal')->willReturn(16010.0);
        $order->method('getBaseTotalPaid')->willReturn(16010.0);
        $order->method('getCustomerEmail')->willReturn('buyer@example.test');
        $order->method('getCustomerFirstname')->willReturn('Test');
        $order->method('getCustomerLastname')->willReturn('Buyer');
        $order->method('getCustomerIsGuest')->willReturn(true);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getItems')->willReturn([$item]);

        return $order;
    }
}
