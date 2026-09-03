<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Dispatch;

use Goodahead\OrderSync\Model\Config;
use Goodahead\OrderSync\Model\Queue\DispatchPublisher;
use Goodahead\OrderSync\Model\ResourceModel\Dispatch as DispatchResource;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

class DispatchRegistrar
{
    public function __construct(
        private readonly DispatchResource $dispatchResource,
        private readonly DispatchPublisher $publisher,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function register(OrderInterface $order, string $eventType): ?Record
    {
        try {
            if (!$this->config->isEnabled((int)$order->getStoreId())) {
                return null;
            }

            $orderId = (int)$order->getEntityId();

            if ($orderId === 0 || $this->dispatchResource->exists($orderId, $eventType)) {
                return null;
            }

            $record = $this->dispatchResource->register($order, $eventType);

            if ($record === null) {
                return null;
            }

            $this->publisher->publish($record);

            return $record;
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf(
                'Goodahead_OrderSync: could not register %s for order %s. %s',
                $eventType,
                (string)$order->getIncrementId(),
                $e->getMessage()
            ));

            return null;
        }
    }
}
