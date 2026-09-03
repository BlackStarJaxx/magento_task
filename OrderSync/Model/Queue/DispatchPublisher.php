<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Queue;

use Goodahead\OrderSync\Model\Dispatch\Record;
use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;

class DispatchPublisher
{
    public const string TOPIC = 'goodahead.ordersync.dispatch';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly DispatchMessageFactory $messageFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function publish(Record $record): bool
    {
        $message = $this->messageFactory->create();
        $message->setDispatchId($record->getId());
        $message->setIncrementId($record->getIncrementId());
        $message->setEventType($record->getEventType());

        try {
            $this->publisher->publish(self::TOPIC, $message);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Goodahead_OrderSync: could not queue %s for order %s, leaving it for the sweeper. %s',
                $record->getEventType(),
                $record->getIncrementId(),
                $e->getMessage()
            ));

            return false;
        }
    }
}
