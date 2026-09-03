<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Test\Unit\Model;

use Goodahead\PaymentTiers\Model\MinorUnits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MinorUnitsTest extends TestCase
{
    private MinorUnits $minorUnits;

    protected function setUp(): void
    {
        $this->minorUnits = new MinorUnits();
    }

    #[DataProvider('amountProvider')]
    public function testConvertsToMinorUnits(float|int|string $amount, int $expected): void
    {
        self::assertSame($expected, $this->minorUnits->fromAmount($amount));
    }

    /**
     * @return array<string, array{float|int|string, int}>
     */
    public static function amountProvider(): array
    {
        return [
            'zero' => [0, 0],
            'whole dollars' => ['10000.00', 1000000],
            'one cent over' => ['10000.01', 1000001],
            'upper threshold' => ['20000.00', 2000000],
            'one cent over upper' => ['20000.01', 2000001],
            'float that cannot be represented exactly' => [10000.01, 1000001],
            'float ending in 0.29' => [1234.29, 123429],
            'float ending in 0.57' => [8.57, 857],
            'string with spaces' => ['  4210.00 ', 421000],
            'integer amount' => [15000, 1500000],
            'empty string is zero' => ['', 0],
            'half cent rounds up' => ['0.005', 1],
            'below half cent rounds down' => ['0.004', 0],
        ];
    }

    public function testRoundTripsBackToAnAmountString(): void
    {
        self::assertSame('10000.01', $this->minorUnits->toAmountString(1000001));
        self::assertSame('0.00', $this->minorUnits->toAmountString(0));
    }

    /**
     * The reason this class exists.
     *
     * $20,000.01 is one of the four totals AC-9 names, and it is a value the naive
     * conversion gets wrong: (int)(20000.01 * 100) is 2000000, which would place the order
     * in the Amex-only tier instead of the no-cards tier. The first assertion is a guard —
     * if a future PHP stops drifting here, it should fail loudly rather than leave a test
     * that silently proves nothing.
     */
    public function testAvoidsTheFloatDriftThatWouldMisclassifyAnAcceptanceCriterionValue(): void
    {
        $amount = 20000.01;

        self::assertSame(2000000, (int)($amount * 100), 'guard: the naive cast really does drift here');
        self::assertSame(2000001, $this->minorUnits->fromAmount($amount));
    }
}
