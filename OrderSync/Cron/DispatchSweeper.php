<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Cron;

use Goodahead\OrderSync\Model\Config;
use Goodahead\OrderSync\Model\Dispatch\DeliveryProcessor;
use Goodahead\OrderSync\Model\Dispatch\DispatchRegistrar;
use Goodahead\OrderSync\Model\Dispatch\EventType;
use Goodahead\OrderSync\Model\Order\PaidStateDetector;
use Goodahead\OrderSync\Model\ResourceModel\Dispatch as DispatchResource;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class DispatchSweeper
{
    /** A claim older than this belonged to a worker that is not coming back. */
    private const int STALE_CLAIM_SECONDS = 900;

    private const int BATCH_SIZE = 50;

    public function __construct(
        private readonly DispatchResource $dispatchResource,
        private readonly DeliveryProcessor $processor,
        private readonly Config $config,
        private readonly DispatchRegistrar $registrar,
        private readonly PaidStateDetector $paidStateDetector,
        private readonly OrderRepositoryInterface $orderRepository,
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

        $this->registerOrdersTheObserverMissed($now);

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

    /**
     * The observer swallows its own failures so that it can never roll an order back, which
     * means a failed registration leaves no ledger row and nothing to retry. This finds those
     * orders and registers them, so "the order was paid but finance never heard" is a state
     * that heals itself rather than one somebody has to notice.
     */
    public function registerOrdersTheObserverMissed(int $now, ?int $windowDays = null): int
    {
        $days = $windowDays ?? $this->config->getReconcileWindowDays();
        $since = $this->dateTime->gmtDate('Y-m-d H:i:s', $now - ($days * 86400));
        $registered = 0;

        foreach ($this->dispatchResource->findPaidOrdersWithoutDispatch($since, self::BATCH_SIZE) as $orderId) {
            try {
                $order = $this->orderRepository->get($orderId);

                if (!$this->paidStateDetector->isPaid($order)) {
                    continue;
                }

                if ($this->registrar->register($order, EventType::ORDER_PLACED) !== null) {
                    $registered++;
                    $this->logger->warning(sprintf(
                        'Goodahead_OrderSync: order %s was paid but unregistered; the sweep registered it.',
                        (string)$order->getIncrementId()
                    ));
                }
            } catch (\Throwable $e) {
                $this->logger->error('Goodahead_OrderSync: reconciling order ' . $orderId . ' failed. ' . $e->getMessage());
            }
        }

        foreach ($this->dispatchResource->findCancelledOrdersWithoutDispatch($since, self::BATCH_SIZE) as $orderId) {
            try {
                $order = $this->orderRepository->get($orderId);

                if ($this->registrar->register($order, EventType::ORDER_CANCELLED) !== null) {
                    $registered++;
                    $this->logger->warning(sprintf(
                        'Goodahead_OrderSync: order %s was cancelled but finance was never told; the sweep registered it.',
                        (string)$order->getIncrementId()
                    ));
                }
            } catch (\Throwable $e) {
                $this->logger->error('Goodahead_OrderSync: reconciling the cancellation of order ' . $orderId . ' failed. ' . $e->getMessage());
            }
        }

        return $registered;
    }
}
