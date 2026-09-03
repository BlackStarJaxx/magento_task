<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Queue;

use Goodahead\OrderSync\Api\Data\DispatchMessageInterface;

class DispatchMessage implements DispatchMessageInterface
{
    private int $dispatchId = 0;
    private string $incrementId = '';
    private string $eventType = '';

    public function getDispatchId(): int
    {
        return $this->dispatchId;
    }

    public function getIncrementId(): string
    {
        return $this->incrementId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setDispatchId(int $dispatchId): void
    {
        $this->dispatchId = $dispatchId;
    }

    public function setIncrementId(string $incrementId): void
    {
        $this->incrementId = $incrementId;
    }

    public function setEventType(string $eventType): void
    {
        $this->eventType = $eventType;
    }
}
