<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Test\Unit\Model\Catalog;

use Goodahead\OrderSync\Model\Catalog\PurchasedProductIds;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use PHPUnit\Framework\TestCase;

class PurchasedProductIdsTest extends TestCase
{
    private PurchasedProductIds $productIds;

    protected function setUp(): void
    {
        $this->productIds = new PurchasedProductIds();
    }

    /**
     * AC-14 asks for the configurable and bundle case to be deliberate. Both the parent and
     * the child are stamped: listings surface the parent, and the child is what actually sold.
     */
    public function testStampsBothTheConfigurableParentAndItsChild(): void
    {
        $order = $this->order([
            $this->item(100, null),
            $this->item(101, 1),
        ]);

        self::assertSame([100, 101], $this->productIds->fromOrder($order));
    }

    public function testTheSameProductTwiceIsStampedOnce(): void
    {
        $order = $this->order([
            $this->item(100, null),
            $this->item(100, null),
            $this->item(101, null),
        ]);

        self::assertSame([100, 101], $this->productIds->fromOrder($order));
    }

    public function testIgnoresItemsWithoutAProduct(): void
    {
        $order = $this->order([
            $this->item(0, null),
            $this->item(100, null),
        ]);

        self::assertSame([100], $this->productIds->fromOrder($order));
    }

    public function testAnEmptyOrderStampsNothing(): void
    {
        self::assertSame([], $this->productIds->fromOrder($this->order([])));
    }

    /**
     * @param OrderItemInterface[] $items
     */
    private function order(array $items): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getItems')->willReturn($items);

        return $order;
    }

    private function item(int $productId, ?int $parentItemId): OrderItemInterface
    {
        $item = $this->createStub(OrderItemInterface::class);
        $item->method('getProductId')->willReturn($productId);
        $item->method('getParentItemId')->willReturn($parentItemId);

        return $item;
    }
}
