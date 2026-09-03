<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\ResourceModel;

use Goodahead\OrderSync\Model\Dispatch\IdempotencyKey;
use Goodahead\OrderSync\Model\Dispatch\Record;
use Goodahead\OrderSync\Model\Dispatch\Status;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Sales\Api\Data\OrderInterface;

class Dispatch
{
    public const TABLE = 'goodahead_ordersync_dispatch';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly IdempotencyKey $idempotencyKey
    ) {
    }

    public function register(OrderInterface $order, string $eventType): ?Record
    {
        $connection = $this->resourceConnection->getConnection();
        $key = $this->idempotencyKey->forOrder($order, $eventType);

        $data = [
            'order_id' => (int)$order->getEntityId(),
            'increment_id' => (string)$order->getIncrementId(),
            'event_type' => $eventType,
            'idempotency_key' => $key,
            'status' => Status::PENDING,
            'attempts' => 0,
            'next_attempt_at' => null,
        ];

        try {
            $connection->insert($this->getTable(), $data);
        } catch (DuplicateException) {
            return null;
        }

        return $this->find((int)$order->getEntityId(), $eventType);
    }

    public function find(int $orderId, string $eventType): ?Record
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('order_id = ?', $orderId)
            ->where('event_type = ?', $eventType);

        $row = $connection->fetchRow($select);

        return $row === false || $row === null ? null : Record::fromRow($row);
    }

    public function exists(int $orderId, string $eventType): bool
    {
        return $this->find($orderId, $eventType) !== null;
    }

    public function claim(int $id): bool
    {
        $connection = $this->resourceConnection->getConnection();

        return $connection->update(
            $this->getTable(),
            ['status' => Status::IN_PROGRESS],
            ['entity_id = ?' => $id, 'status = ?' => Status::PENDING]
        ) === 1;
    }

    public function markSucceeded(int $id, ?int $statusCode): void
    {
        $this->resourceConnection->getConnection()->update(
            $this->getTable(),
            [
                'status' => Status::SUCCEEDED,
                'attempts' => new \Zend_Db_Expr('attempts + 1'),
                'last_status_code' => $statusCode,
                'last_error' => null,
                'next_attempt_at' => null,
            ],
            ['entity_id = ?' => $id]
        );
    }

    /** Back to pending, to be picked up again once next_attempt_at has passed. */
    public function markRetryable(int $id, string $error, ?int $statusCode, string $nextAttemptAt): void
    {
        $this->resourceConnection->getConnection()->update(
            $this->getTable(),
            [
                'status' => Status::PENDING,
                'attempts' => new \Zend_Db_Expr('attempts + 1'),
                'last_status_code' => $statusCode,
                'last_error' => $error,
                'next_attempt_at' => $nextAttemptAt,
            ],
            ['entity_id = ?' => $id]
        );
    }

    /** Terminal. Nothing will retry this; an operator has to look at it. */
    public function markFailed(int $id, string $error, ?int $statusCode): void
    {
        $this->resourceConnection->getConnection()->update(
            $this->getTable(),
            [
                'status' => Status::FAILED,
                'attempts' => new \Zend_Db_Expr('attempts + 1'),
                'last_status_code' => $statusCode,
                'last_error' => $error,
                'next_attempt_at' => null,
            ],
            ['entity_id = ?' => $id]
        );
    }

    /**
     * Work that is due: never attempted, or waited long enough.
     *
     * @return Record[]
     */
    public function findDue(string $now, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('status = ?', Status::PENDING)
            ->where('next_attempt_at IS NULL OR next_attempt_at <= ?', $now)
            ->order('entity_id ASC')
            ->limit($limit);

        return array_map(
            static fn (array $row): Record => Record::fromRow($row),
            $connection->fetchAll($select)
        );
    }

    /**
     * Releases rows a consumer claimed and never finished — a killed worker, a fatal error.
     * Without this they would sit in_progress forever and never retry.
     */
    public function reclaimStale(string $staleBefore): int
    {
        return $this->resourceConnection->getConnection()->update(
            $this->getTable(),
            ['status' => Status::PENDING],
            ['status = ?' => Status::IN_PROGRESS, 'updated_at < ?' => $staleBefore]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRows(?string $status, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->order('entity_id DESC')
            ->limit($limit);

        if ($status !== null) {
            $select->where('status = ?', $status);
        }

        return $connection->fetchAll($select);
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable(), ['status', 'total' => new \Zend_Db_Expr('COUNT(*)')])
            ->group('status');

        return array_map('intval', $connection->fetchPairs($select));
    }

    /**
     * Puts terminally failed deliveries back in the queue of work, with a fresh budget.
     *
     * An operator retrying is stating that the cause is fixed, so keeping the exhausted
     * attempt count would fail the row again on the next tick without trying anything.
     */
    /**
     * @param string[] $incrementIds
     */
    public function requeueFailed(array $incrementIds = []): int
    {
        $where = ['status = ?' => Status::FAILED];

        if ($incrementIds !== []) {
            $where['increment_id IN (?)'] = $incrementIds;
        }

        return $this->resourceConnection->getConnection()->update(
            $this->getTable(),
            ['status' => Status::PENDING, 'attempts' => 0, 'next_attempt_at' => null],
            $where
        );
    }

    public function getById(int $id): ?Record
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->getTable())->where('entity_id = ?', $id)
        );

        return $row === false || $row === null ? null : Record::fromRow($row);
    }

    private function getTable(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
