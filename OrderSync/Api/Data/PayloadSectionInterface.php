<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Api\Data;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * One part of the JSON the finance system receives.
 *
 * Sections are composed by OrderPayloadBuilder and wired in di.xml, so the wire format can be
 * extended — a store that has to send its own field adds a section rather than editing this
 * module. Each returns a fragment of the "order" node; the envelope around it belongs to the
 * builder, because it describes the delivery rather than the order.
 */
interface PayloadSectionInterface
{
    /**
     * @param OrderInterface $order
     * @return array<string, mixed>
     */
    public function build(OrderInterface $order): array;
}
