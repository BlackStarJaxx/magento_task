<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Plugin\Checkout;

use Goodahead\PaymentTiers\Model\Checkout\TierSnapshot;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\TotalsExtensionFactory;
use Magento\Quote\Api\Data\TotalsInterface;

/**
 * Puts the tier on the totals payload.
 *
 * The checkout already refetches totals whenever a coupon, shipping method or address
 * changes, so riding that payload is what makes the message react without a mechanism of
 * its own (AC-3).
 */
class AddTierToTotals
{
    public function __construct(
        private readonly TierSnapshot $tierSnapshot,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly TotalsExtensionFactory $extensionFactory
    ) {
    }

    /**
     * @param int|string $cartId
     */
    public function afterGet(
        CartTotalRepositoryInterface $subject,
        TotalsInterface $totals,
        $cartId
    ): TotalsInterface {
        $extension = $totals->getExtensionAttributes() ?: $this->extensionFactory->create();

        try {
            $quote = $this->cartRepository->get((int)$cartId);
        } catch (\Throwable $e) {
            $quote = null;
        }

        $extension->setGoodaheadPaymentTier($this->tierSnapshot->forQuote($quote));
        $totals->setExtensionAttributes($extension);

        return $totals;
    }
}
