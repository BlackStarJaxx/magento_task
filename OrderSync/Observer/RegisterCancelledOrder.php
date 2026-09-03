<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Observer;

use Goodahead\OrderSync\Model\Dispatch\DispatchRegistrar;
use Goodahead\OrderSync\Model\Dispatch\EventType;
use Goodahead\OrderSync\Model\ResourceModel\Dispatch as DispatchResource;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

class RegisterCancelledOrder implements ObserverInterface
{
    public function __construct(
        private readonly DispatchResource $dispatchResource,
        private readonly DispatchRegistrar $registrar,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        if (!$order instanceof OrderInterface) {
            return;
        }

        // exists() is a database read inside Order::cancel(); letting it escape would fail
        // the cancellation itself.
        try {
            if (!$this->dispatchResource->exists((int)$order->getEntityId(), EventType::ORDER_PLACED)) {
                return;
            }

            $this->registrar->register($order, EventType::ORDER_CANCELLED);
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf(
                'Goodahead_OrderSync: registering the cancellation of order %s failed. %s',
                (string)$order->getIncrementId(),
                $e->getMessage()
            ));
        }
    }
}
