<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Test\Unit\Model\Order;

use Goodahead\OrderSync\Model\Order\PaidStateDetector;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaidStateDetectorTest extends TestCase
{
    private PaidStateDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new PaidStateDetector();
    }

    /**
     * @return array<string, array{string, float, float, bool}>
     */
    public static function orderProvider(): array
    {
        return [
            'captured card order' => [Order::STATE_PROCESSING, 842.0, 842.0, true],
            'authorised but not captured' => [Order::STATE_PROCESSING, 0.0, 842.0, true],
            'offline order awaiting payment' => [Order::STATE_NEW, 0.0, 0.0, false],
            'invoiced offline order' => [Order::STATE_PROCESSING, 842.0, 0.0, true],
            'still pending payment' => [Order::STATE_PENDING_PAYMENT, 0.0, 0.0, false],
            'cancelled order' => [Order::STATE_CANCELED, 0.0, 0.0, false],
            'complete order' => [Order::STATE_COMPLETE, 842.0, 842.0, true],
        ];
    }

    #[DataProvider('orderProvider')]
    public function testDetectsWhetherMoneyWasCommitted(
        string $state,
        float $paid,
        float $authorized,
        bool $expected
    ): void {
        self::assertSame($expected, $this->detector->isPaid($this->order($state, $paid, $authorized)));
    }

    /**
     * AC-15: a capture that failed leaves the order in a state where nothing may fire. The
     * money test is what enforces that, not the fact that an order row exists.
     */
    public function testAnOrderThatNeverTookMoneyIsNotPaid(): void
    {
        self::assertFalse($this->detector->isPaid($this->order(Order::STATE_PROCESSING, 0.0, 0.0)));
    }

    public function testAnOrderWithoutAPaymentIsNotPaid(): void
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->method('getPayment')->willReturn(null);

        self::assertFalse($this->detector->isPaid($order));
    }

    private function order(string $state, float $paid, float $authorized): OrderInterface
    {
        $payment = $this->createStub(OrderPaymentInterface::class);
        $payment->method('getBaseAmountPaid')->willReturn($paid);
        $payment->method('getBaseAmountAuthorized')->willReturn($authorized);

        $order = $this->createStub(OrderInterface::class);
        $order->method('getState')->willReturn($state);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }
}
