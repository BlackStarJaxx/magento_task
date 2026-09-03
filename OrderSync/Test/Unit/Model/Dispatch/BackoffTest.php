<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Test\Unit\Model\Dispatch;

use Goodahead\OrderSync\Model\Config;
use Goodahead\OrderSync\Model\Dispatch\Backoff;
use PHPUnit\Framework\TestCase;

class BackoffTest extends TestCase
{
    private const BASE = 60;
    private const MAX = 3600;

    private Backoff $backoff;

    protected function setUp(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getBaseDelaySeconds')->willReturn(self::BASE);
        $config->method('getMaxDelaySeconds')->willReturn(self::MAX);

        $this->backoff = new Backoff($config);
    }

    public function testDelayGrowsWithEachAttempt(): void
    {
        // Jitter is +/-20%, so compare the bands rather than exact values.
        self::assertLessThan(
            $this->lowerBound(60 * 4),
            $this->backoff->secondsUntilNextAttempt(1),
            'the first retry must be far shorter than the third'
        );
    }

    public function testStaysWithinTheJitterBandAtEachStep(): void
    {
        foreach ([1 => 60, 2 => 120, 3 => 240, 4 => 480] as $attempts => $expected) {
            $delay = $this->backoff->secondsUntilNextAttempt($attempts);

            self::assertGreaterThanOrEqual($this->lowerBound($expected), $delay, 'attempt ' . $attempts);
            self::assertLessThanOrEqual($this->upperBound($expected), $delay, 'attempt ' . $attempts);
        }
    }

    public function testNeverExceedsTheConfiguredCap(): void
    {
        foreach ([10, 20, 40] as $attempts) {
            self::assertLessThanOrEqual($this->upperBound(self::MAX), $this->backoff->secondsUntilNextAttempt($attempts));
        }
    }

    public function testAlwaysWaitsAtLeastASecond(): void
    {
        self::assertGreaterThanOrEqual(1, $this->backoff->secondsUntilNextAttempt(0));
    }

    /**
     * Without jitter a batch that failed together retries together, and an endpoint that was
     * already struggling gets the same burst again at every interval.
     */
    public function testTwoCallsDoNotLineUpExactly(): void
    {
        $delays = [];

        for ($i = 0; $i < 40; $i++) {
            $delays[] = $this->backoff->secondsUntilNextAttempt(3);
        }

        self::assertGreaterThan(1, count(array_unique($delays)), 'delays must be spread, not identical');
    }

    private function lowerBound(int $expected): int
    {
        return (int)floor($expected * 0.8);
    }

    private function upperBound(int $expected): int
    {
        return (int)ceil($expected * 1.2);
    }
}
