<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Dispatch;

use Goodahead\OrderSync\Model\Config;
use Goodahead\OrderSync\Model\Http\FinanceClient;
use Goodahead\OrderSync\Model\Http\Outcome;
use Goodahead\OrderSync\Model\Payload\OrderPayloadBuilder;
use Goodahead\OrderSync\Model\ResourceModel\Dispatch as DispatchResource;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterfaceFactory;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Performs one delivery attempt and records what it meant.
 */
class DeliveryProcessor
{
    public function __construct(
        private readonly DispatchResource $dispatchResource,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly OrderStatusHistoryInterfaceFactory $historyFactory,
        private readonly OrderPayloadBuilder $payloadBuilder,
        private readonly FinanceClient $client,
        private readonly Backoff $backoff,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(Record $record): void
    {
        try {
            $order = $this->orderRepository->get($record->getOrderId());
        } catch (\Throwable $e) {
            $this->dispatchResource->markFailed($record->getId(), 'Order could not be loaded: ' . $e->getMessage(), null);

            return;
        }

        $storeId = (int)$order->getStoreId();
        $payload = $this->payloadBuilder->build($order, $record->getEventType());
        $result = $this->client->deliver($payload, $record->getIdempotencyKey(), $storeId);
        $attempts = $record->getAttempts() + 1;

        switch ($result->getOutcome()) {
            case Outcome::Succeeded:
                $this->dispatchResource->markSucceeded($record->getId(), $result->getStatusCode());

                return;

            case Outcome::Terminal:
                $this->fail($record, $result->getDetail(), $result->getStatusCode(), $order);

                return;

            case Outcome::Retryable:
                if ($attempts >= $this->config->getMaxAttempts($storeId)) {
                    $this->fail(
                        $record,
                        'Retry budget exhausted after ' . $attempts . ' attempts. Last: ' . $result->getDetail(),
                        $result->getStatusCode(),
                        $order
                    );

                    return;
                }

                $this->dispatchResource->markRetryable(
                    $record->getId(),
                    $result->getDetail(),
                    $result->getStatusCode(),
                    $this->dateTime->gmtDate(
                        'Y-m-d H:i:s',
                        $this->dateTime->gmtTimestamp() + $this->backoff->secondsUntilNextAttempt($attempts, $storeId)
                    )
                );
        }
    }

    private function fail(Record $record, string $error, ?int $statusCode, OrderInterface $order): void
    {
        $this->dispatchResource->markFailed($record->getId(), $error, $statusCode);

        $this->logger->critical(sprintf(
            'Goodahead_OrderSync: %s for order %s failed permanently. %s',
            $record->getEventType(),
            $record->getIncrementId(),
            $error
        ));

        try {
            $history = $this->historyFactory->create();
            $history->setParentId((int)$order->getEntityId());
            $history->setEntityName('order');
            $history->setStatus((string)$order->getStatus());
            $history->setIsCustomerNotified(0);
            $history->setIsVisibleOnFront(0);
            $history->setComment(
                (string)__('Finance push (%1) failed permanently: %2', $record->getEventType(), $error)
            );

            $this->orderManagement->addComment((int)$order->getEntityId(), $history);
        } catch (\Throwable $e) {
            $this->logger->error('Goodahead_OrderSync: could not add the failure comment. ' . $e->getMessage());
        }
    }
}
