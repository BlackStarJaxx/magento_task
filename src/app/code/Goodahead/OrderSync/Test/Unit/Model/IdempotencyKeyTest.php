<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Test\Unit\Model;

use Goodahead\OrderSync\Model\Dispatch\EventType;
use Goodahead\OrderSync\Model\Dispatch\IdempotencyKey;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;

class IdempotencyKeyTest extends TestCase
{
    private IdempotencyKey $key;

    protected function setUp(): void
    {
        $this->key = new IdempotencyKey();
    }

    /**
     * The property AC-11 rests on: the same order and event always produce the same key, so a
     * retry cannot look like a new delivery.
     */
    public function testTheSameOrderAndEventAlwaysProduceTheSameKey(): void
    {
        $first = $this->key->forOrder($this->order('000000042'), EventType::ORDER_PLACED);
        $second = $this->key->forOrder($this->order('000000042'), EventType::ORDER_PLACED);

        self::assertSame($first, $second);
    }

    public function testPlacementAndCancellationAreDistinctDeliveries(): void
    {
        self::assertNotSame(
            $this->key->forOrder($this->order('000000042'), EventType::ORDER_PLACED),
            $this->key->forOrder($this->order('000000042'), EventType::ORDER_CANCELLED)
        );
    }

    public function testDifferentOrdersDoNotCollide(): void
    {
        self::assertNotSame(
            $this->key->forOrder($this->order('000000042'), EventType::ORDER_PLACED),
            $this->key->forOrder($this->order('000000043'), EventType::ORDER_PLACED)
        );
    }

    /**
     * Readable on purpose: an operator comparing the two systems greps for the order number.
     */
    public function testTheKeyCarriesTheOrderNumber(): void
    {
        $key = $this->key->forOrder($this->order('000000042'), EventType::ORDER_PLACED);

        self::assertSame('goodahead-000000042-order_placed', $key);
        self::assertLessThanOrEqual(128, strlen($key), 'must fit the ledger column');
    }

    private function order(string $incrementId): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getIncrementId')->willReturn($incrementId);

        return $order;
    }
}
