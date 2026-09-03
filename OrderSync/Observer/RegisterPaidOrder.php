<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Observer;

use Goodahead\OrderSync\Model\Catalog\PurchasedProductIds;
use Goodahead\OrderSync\Model\Catalog\PurchaseRecencyStamper;
use Goodahead\OrderSync\Model\Dispatch\DispatchRegistrar;
use Goodahead\OrderSync\Model\Dispatch\EventType;
use Goodahead\OrderSync\Model\Order\PaidStateDetector;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

class RegisterPaidOrder implements ObserverInterface
{
    public function __construct(
        private readonly PaidStateDetector $paidStateDetector,
        private readonly DispatchRegistrar $registrar,
        private readonly PurchasedProductIds $purchasedProductIds,
        private readonly PurchaseRecencyStamper $stamper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        if (!$order instanceof OrderInterface) {
            return;
        }

        /*
         * Nothing may escape. This runs on sales_order_save_after, inside the order's own
         * transaction, so an exception here would roll the order back — the customer would
         * lose a paid order because the finance push had a bad moment. The reconciliation
         * sweep in DispatchSweeper picks up anything missed here, so failing quietly loses
         * nothing but time.
         */
        try {
            if (!$this->paidStateDetector->isPaid($order)) {
                return;
            }

            if ($this->registrar->register($order, EventType::ORDER_PLACED) === null) {
                return;
            }

            $this->stampPurchaseRecency($order);
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf(
                'Goodahead_OrderSync: registering order %s failed; the sweep will retry. %s',
                (string)$order->getIncrementId(),
                $e->getMessage()
            ));
        }
    }

    private function stampPurchaseRecency(OrderInterface $order): void
    {
        try {
            $this->stamper->stamp(
                $this->purchasedProductIds->fromOrder($order),
                (string)$order->getCreatedAt()
            );
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf(
                'Goodahead_OrderSync: could not stamp purchase recency for order %s. %s',
                (string)$order->getIncrementId(),
                $e->getMessage()
            ));
        }
    }
}
