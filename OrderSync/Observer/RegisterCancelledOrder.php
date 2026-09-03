<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Observer;

use Goodahead\OrderSync\Model\Dispatch\DispatchRegistrar;
use Goodahead\OrderSync\Model\Dispatch\EventType;
use Goodahead\OrderSync\Model\ResourceModel\Dispatch as DispatchResource;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;

class RegisterCancelledOrder implements ObserverInterface
{
    public function __construct(
        private readonly DispatchResource $dispatchResource,
        private readonly DispatchRegistrar $registrar
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        if (!$order instanceof OrderInterface) {
            return;
        }

        if (!$this->dispatchResource->exists((int)$order->getEntityId(), EventType::ORDER_PLACED)) {
            return;
        }

        $this->registrar->register($order, EventType::ORDER_CANCELLED);
    }
}
