<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Queue;

use Goodahead\OrderSync\Api\Data\DispatchMessageInterface;
use Goodahead\OrderSync\Model\Dispatch\DeliveryProcessor;
use Goodahead\OrderSync\Model\Dispatch\Status;
use Goodahead\OrderSync\Model\ResourceModel\Dispatch as DispatchResource;
use Psr\Log\LoggerInterface;

class DispatchConsumer
{
    public function __construct(
        private readonly DispatchResource $dispatchResource,
        private readonly DeliveryProcessor $processor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(DispatchMessageInterface $message): void
    {
        $record = $this->dispatchResource->getById($message->getDispatchId());

        if ($record === null) {
            return;
        }

        if ($record->getStatus() === Status::SUCCEEDED || $record->getStatus() === Status::FAILED) {
            return;
        }

        if (!$this->dispatchResource->claim($record->getId())) {
            return;
        }

        try {
            $this->processor->process($record);
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf(
                'Goodahead_OrderSync: delivery of %s for order %s crashed. %s',
                $record->getEventType(),
                $record->getIncrementId(),
                $e->getMessage()
            ));
        }
    }
}
