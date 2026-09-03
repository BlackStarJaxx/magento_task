<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Http;

class DeliveryResult
{
    public function __construct(
        private readonly Outcome $outcome,
        private readonly ?int $statusCode,
        private readonly string $detail
    ) {
    }

    public function getOutcome(): Outcome
    {
        return $this->outcome;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    public function isSucceeded(): bool
    {
        return $this->outcome === Outcome::Succeeded;
    }
}
