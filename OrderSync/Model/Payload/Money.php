<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Payload;

/**
 * Money on the wire.
 *
 * Decimal strings, never JSON numbers: "16005.00" survives a round trip through any parser,
 * a float does not necessarily. The same reason the tiers compare integer minor units.
 */
class Money
{
    public function format(mixed $value, int $precision = 2): string
    {
        return number_format((float)$value, $precision, '.', '');
    }
}
