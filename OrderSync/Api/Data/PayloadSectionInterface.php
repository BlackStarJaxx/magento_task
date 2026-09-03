<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Api\Data;

use Magento\Sales\Api\Data\OrderInterface;

interface PayloadSectionInterface
{
    /**
     * @param OrderInterface $order
     * @return array<string, mixed>
     */
    public function build(OrderInterface $order): array;
}
