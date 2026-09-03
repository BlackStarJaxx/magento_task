<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Plugin;

use Goodahead\PaymentTiers\Model\CardBrand;
use Goodahead\PaymentTiers\Model\Tier;
use Goodahead\PaymentTiers\Model\TierForOrder;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use StripeIntegration\Payments\Model\PaymentIntent;

/**
 * Forces manual capture on a restricted tier, and records the tier on the intent.
 *
 * `getParamsFrom` is reached from five places in the Stripe module, two of them webhook paths,
 * so one plugin covers every route by which an intent is created.
 *
 * Manual capture matters for the wallet path. Express buttons confirm in the browser and reach
 * us already paid, so the only control left is to check afterwards and unwind — and unwinding
 * an authorisation is releasing a hold, while unwinding a capture is taking money and giving
 * it back. The cost is that a restricted-tier order settles when it is invoiced rather than
 * immediately, which for orders above $10,000 is arguably the right default anyway.
 *
 * Metadata is written for the dispute that arrives months later: it puts the tier and the
 * brands it allowed on Stripe's copy of the payment, not only on ours.
 */
class StampIntentForTier
{
    public function __construct(
        private readonly TierForOrder $tierForOrder,
        private readonly CardBrand $cardBrand,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @param mixed $order
     * @return array<string, mixed>
     */
    public function afterGetParamsFrom(PaymentIntent $subject, array $params, $order = null): array
    {
        if (!$order instanceof OrderInterface) {
            return $params;
        }

        try {
            $tier = $this->tierForOrder->resolve($order);

            if ($tier === null || !$tier->allowsAnyCard()) {
                return $params;
            }

            $params['metadata'] = ($params['metadata'] ?? []) + [
                'goodahead_tier_allowed_brands' => implode(',', $tier->getAllowedBrands()),
                'goodahead_tier_upper_bound' => $tier->isUnbounded() ? '' : (string)$tier->getUpperBoundMinorUnits(),
            ];

            if ($this->isRestricted($tier)) {
                $params['capture_method'] = 'manual';
            }
        } catch (\Throwable $e) {
            // Never stop a payment being created over this; the guard still refuses.
            $this->logger->error('Goodahead_PaymentTiers: could not stamp the intent. ' . $e->getMessage());
        }

        return $params;
    }

    /** Some brands allowed, but not all of them. */
    private function isRestricted(Tier $tier): bool
    {
        return count($tier->getAllowedBrands()) < count($this->cardBrand->all());
    }
}
