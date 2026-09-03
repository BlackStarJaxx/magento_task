<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Dispatch;

use Goodahead\OrderSync\Model\Config;

/**
 * How long to wait before the next attempt.
 *
 * Exponential, capped, with jitter. The jitter matters more than it looks: without it a batch
 * of orders that failed together retries together, and the endpoint that was struggling gets
 * the same burst again at every interval.
 */
class Backoff
{
    private const JITTER_RATIO = 0.2;

    public function __construct(private readonly Config $config)
    {
    }

    public function secondsUntilNextAttempt(int $attemptsSoFar, ?int $storeId = null): int
    {
        $base = $this->config->getBaseDelaySeconds($storeId);
        $max = $this->config->getMaxDelaySeconds($storeId);

        $exponent = max(0, $attemptsSoFar - 1);
        $delay = min($max, $base * (2 ** $exponent));

        $jitter = (int)round($delay * self::JITTER_RATIO);

        return max(1, $delay - $jitter + random_int(0, 2 * $jitter));
    }
}
