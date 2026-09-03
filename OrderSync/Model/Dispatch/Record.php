<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Dispatch;

/**
 * One row of the dispatch ledger.
 */
class Record
{
    public function __construct(
        private readonly int $id,
        private readonly int $orderId,
        private readonly string $incrementId,
        private readonly string $eventType,
        private readonly string $idempotencyKey,
        private readonly string $status,
        private readonly int $attempts
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)($row['entity_id'] ?? 0),
            (int)($row['order_id'] ?? 0),
            (string)($row['increment_id'] ?? ''),
            (string)($row['event_type'] ?? ''),
            (string)($row['idempotency_key'] ?? ''),
            (string)($row['status'] ?? Status::PENDING),
            (int)($row['attempts'] ?? 0)
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getIncrementId(): string
    {
        return $this->incrementId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }
}
