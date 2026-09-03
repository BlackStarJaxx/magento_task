<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Plugin;

use Goodahead\PaymentTiers\Model\MinorUnits;
use Goodahead\PaymentTiers\Model\TierForOrder;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use StripeIntegration\Payments\Model\Config as StripeConfig;

class StampTierOnStripeMetadata
{
    public function __construct(
        private readonly TierForOrder $tierForOrder,
        private readonly MinorUnits $minorUnits,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     * @param mixed $order
     * @return array<string, mixed>
     */
    public function afterGetMetadata(StripeConfig $subject, array $metadata, $order = null): array
    {
        if (!$order instanceof OrderInterface) {
            return $metadata;
        }

        try {
            $tier = $this->tierForOrder->resolve($order);

            if ($tier === null) {
                return $metadata;
            }

            $metadata['goodahead_tier_allowed_brands'] = implode(',', $tier->getAllowedBrands()) ?: 'none';
            $metadata['goodahead_tier_upper_bound'] = $tier->isUnbounded()
                ? 'unbounded'
                : $this->minorUnits->toAmountString((int)$tier->getUpperBoundMinorUnits());
        } catch (\Throwable $e) {
            $this->logger->error('Goodahead_PaymentTiers: could not stamp tier metadata. ' . $e->getMessage());
        }

        return $metadata;
    }
}
