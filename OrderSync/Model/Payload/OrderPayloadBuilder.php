<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Payload;

use Goodahead\OrderSync\Api\Data\PayloadSectionInterface;
use Goodahead\OrderSync\Model\Dispatch\IdempotencyKey;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;

class OrderPayloadBuilder
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param PayloadSectionInterface[] $sections
     */
    public function __construct(
        private readonly IdempotencyKey $idempotencyKey,
        private readonly DateTime $dateTime,
        private readonly array $sections = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(OrderInterface $order, string $eventType): array
    {
        $orderNode = [];

        foreach ($this->sections as $section) {
            if ($section instanceof PayloadSectionInterface) {
                $orderNode += $section->build($order);
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event' => $eventType,
            'idempotency_key' => $this->idempotencyKey->forOrder($order, $eventType),
            'occurred_at' => $this->dateTime->gmtDate('c'),
            'order' => $orderNode,
        ];
    }
}
