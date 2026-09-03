<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Test\Unit\Model\Http;

use Goodahead\OrderSync\Model\Http\Outcome;
use Goodahead\OrderSync\Model\Http\ResponseClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResponseClassifierTest extends TestCase
{
    private ResponseClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ResponseClassifier();
    }

    /**
     * @return array<string, array{int, Outcome}>
     */
    public static function statusProvider(): array
    {
        return [
            '200 delivered' => [200, Outcome::Succeeded],
            '201 delivered' => [201, Outcome::Succeeded],
            '204 delivered' => [204, Outcome::Succeeded],
            '409 already recorded, so delivered' => [409, Outcome::Succeeded],
            '408 request timeout' => [408, Outcome::Retryable],
            '429 rate limited' => [429, Outcome::Retryable],
            '500 server error' => [500, Outcome::Retryable],
            '502 bad gateway' => [502, Outcome::Retryable],
            '503 unavailable' => [503, Outcome::Retryable],
            '400 malformed' => [400, Outcome::Terminal],
            '401 unauthorised' => [401, Outcome::Terminal],
            '404 wrong endpoint' => [404, Outcome::Terminal],
            '422 rejected payload' => [422, Outcome::Terminal],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testClassifiesStatuses(int $status, Outcome $expected): void
    {
        self::assertSame($expected, $this->classifier->classify($status));
    }

    /**
     * The decision worth stating out loud: a conflict means an earlier attempt landed, so
     * counting it as a failure would spend the retry budget on an order finance already has,
     * and could end with a terminal-failed row for a delivery that actually succeeded.
     */
    public function testAConflictIsNotAFailure(): void
    {
        self::assertNotSame(Outcome::Terminal, $this->classifier->classify(409));
        self::assertNotSame(Outcome::Retryable, $this->classifier->classify(409));
    }

    /**
     * A malformed payload will be just as malformed on the tenth attempt.
     */
    public function testClientErrorsAreNotRetried(): void
    {
        foreach ([400, 401, 403, 404, 422] as $status) {
            self::assertSame(Outcome::Terminal, $this->classifier->classify($status), (string)$status);
        }
    }
}
