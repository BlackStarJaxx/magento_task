<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Api\Data;

interface DispatchMessageInterface
{
    /**
     * @return int
     */
    public function getDispatchId(): int;

    /**
     * @return string
     */
    public function getIncrementId(): string;

    /**
     * @return string
     */
    public function getEventType(): string;

    /**
     * @param int $dispatchId
     * @return void
     */
    public function setDispatchId(int $dispatchId): void;

    /**
     * @param string $incrementId
     * @return void
     */
    public function setIncrementId(string $incrementId): void;

    /**
     * @param string $eventType
     * @return void
     */
    public function setEventType(string $eventType): void;
}
