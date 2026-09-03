<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Dispatch;

class EventType
{
    public const ORDER_PLACED = 'order_placed';
    public const ORDER_CANCELLED = 'order_cancelled';

    public const ALL = [self::ORDER_PLACED, self::ORDER_CANCELLED];

    public static function isValid(string $eventType): bool
    {
        return in_array($eventType, self::ALL, true);
    }
}
