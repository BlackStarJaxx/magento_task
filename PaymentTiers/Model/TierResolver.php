<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model;

/**
 * Maps an order value to the tier that governs it.
 *
 * Bounds are inclusive (AC-9), so a total equal to a bound belongs to that tier. The tier
 * chosen is the narrowest one that still contains the amount, which makes the result
 * independent of the order the tiers arrive in — the resolver does not silently depend on
 * the provider having sorted them.
 *
 * Everything is in integer minor units; see MinorUnits for why that matters here.
 */
class TierResolver
{
    public function __construct(private readonly TierProvider $tierProvider)
    {
    }

    public function resolve(int $amountMinorUnits, ?int $websiteId = null): Tier
    {
        $match = null;

        foreach ($this->tierProvider->getTiers($websiteId) as $tier) {
            if ($tier->contains($amountMinorUnits) && $this->isNarrower($tier, $match)) {
                $match = $tier;
            }
        }

        // Only reachable if every configured tier is bounded and the amount exceeds them all,
        // which the backend model rejects at save time. Fail closed rather than unrestricted.
        return $match ?? new Tier(null, [], (string)__('Card payments are not available for this order total.'));
    }

    private function isNarrower(Tier $candidate, ?Tier $current): bool
    {
        if ($current === null) {
            return true;
        }

        if ($candidate->isUnbounded()) {
            return false;
        }

        if ($current->isUnbounded()) {
            return true;
        }

        return $candidate->getUpperBoundMinorUnits() < $current->getUpperBoundMinorUnits();
    }
}
