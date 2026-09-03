<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Http;

class ResponseClassifier
{
    private const RETRYABLE_STATUSES = [408, 425, 429];

    public function classify(int $statusCode): Outcome
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return Outcome::Succeeded;
        }

        if ($statusCode === 409) {
            return Outcome::Succeeded;
        }

        if ($statusCode >= 500 || in_array($statusCode, self::RETRYABLE_STATUSES, true)) {
            return Outcome::Retryable;
        }

        return Outcome::Terminal;
    }
}
