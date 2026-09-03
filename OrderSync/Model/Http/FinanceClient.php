<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Http;

use Goodahead\OrderSync\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

class FinanceClient
{
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Json $serializer,
        private readonly ResponseClassifier $classifier,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function deliver(array $payload, string $idempotencyKey, ?int $storeId = null): DeliveryResult
    {
        $url = $this->config->getEndpointUrl($storeId);

        if ($url === '') {
            return new DeliveryResult(Outcome::Terminal, null, 'No finance endpoint is configured.');
        }

        $curl = $this->curlFactory->create();
        $curl->setTimeout($this->config->getTimeout($storeId));
        $curl->setHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Idempotency-Key' => $idempotencyKey,
        ]);

        try {
            $curl->post($url, (string)$this->serializer->serialize($payload));
        } catch (\Throwable $e) {
            return new DeliveryResult(Outcome::Retryable, null, 'Transport failure: ' . $e->getMessage());
        }

        $status = (int)$curl->getStatus();

        return new DeliveryResult(
            $this->classifier->classify($status),
            $status,
            'HTTP ' . $status . ' ' . $this->firstLine($curl->getBody())
        );
    }

    private function firstLine(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');

        return mb_substr($body, 0, 200);
    }
}
