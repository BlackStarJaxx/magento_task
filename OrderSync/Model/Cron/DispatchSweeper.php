<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Cron;

use Goodahead\OrderSync\Model\Config;
use Goodahead\OrderSync\Model\Dispatch\DeliveryProcessor;
use Goodahead\OrderSync\Model\ResourceModel\Dispatch as DispatchResource;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

class DispatchSweeper
{
    /** A claim older than this belonged to a worker that is not coming back. */
    private const STALE_CLAIM_SECONDS = 900;

    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly DispatchResource $dispatchResource,
        private readonly DeliveryProcessor $processor,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $now = $this->dateTime->gmtTimestamp();

        $reclaimed = $this->dispatchResource->reclaimStale(
            $this->dateTime->gmtDate('Y-m-d H:i:s', $now - self::STALE_CLAIM_SECONDS)
        );

        if ($reclaimed > 0) {
            $this->logger->warning(
                'Goodahead_OrderSync: reclaimed ' . $reclaimed . ' abandoned deliveries.'
            );
        }

        foreach ($this->dispatchResource->findDue($this->dateTime->gmtDate('Y-m-d H:i:s', $now), self::BATCH_SIZE) as $record) {
            if (!$this->dispatchResource->claim($record->getId())) {
                continue;
            }

            try {
                $this->processor->process($record);
            } catch (\Throwable $e) {
                $this->logger->critical(sprintf(
                    'Goodahead_OrderSync: sweeping %s for order %s crashed. %s',
                    $record->getEventType(),
                    $record->getIncrementId(),
                    $e->getMessage()
                ));
            }
        }
    }
}
