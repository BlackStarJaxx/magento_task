<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolves the tier that governs an order.
 *
 * Shared by everything that has an order in hand: the placement guard, the intent parameters,
 * and the post-payment check. "Governed" and "which tier" are separate questions because a
 * caller that cannot price an order still needs to know whether the tiers apply to it — an
 * unknown value on a governed order is a reason to refuse, not to wave through.
 */
class TierForOrder
{
    public function __construct(
        private readonly TierProvider $tierProvider,
        private readonly TierResolver $tierResolver,
        private readonly RestrictedMethods $restrictedMethods,
        private readonly ComparableAmount $comparableAmount,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isGoverned(OrderInterface $order): bool
    {
        $payment = $order->getPayment();

        if ($payment === null) {
            return false;
        }

        $websiteId = $this->getWebsiteId($order);

        return $this->tierProvider->isEnabled($websiteId)
            && $this->restrictedMethods->isRestrictable((string)$payment->getMethod(), $websiteId);
    }

    /**
     * Null means the order cannot be priced, which callers must not read as unrestricted.
     */
    public function resolve(OrderInterface $order): ?Tier
    {
        if (!$this->isGoverned($order)) {
            return null;
        }

        $websiteId = $this->getWebsiteId($order);
        $amount = $this->comparableAmount->fromOrder($order, $websiteId);

        return $amount === null ? null : $this->tierResolver->resolve($amount, $websiteId);
    }

    private function getWebsiteId(OrderInterface $order): int
    {
        return (int)$this->storeManager->getStore((int)$order->getStoreId())->getWebsiteId();
    }
}
