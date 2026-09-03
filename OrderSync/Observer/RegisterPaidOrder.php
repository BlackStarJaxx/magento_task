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

        if (!$order instanceof OrderInterface || !$this->paidStateDetector->isPaid($order)) {
            return;
        }

        if ($this->registrar->register($order, EventType::ORDER_PLACED) === null) {
            return;
        }

        $this->stampPurchaseRecency($order);
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
