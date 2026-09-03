<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model\Checkout;

use Goodahead\PaymentTiers\Api\Data\CheckoutTierInterface;
use Goodahead\PaymentTiers\Model\Checkout\CheckoutTierFactory;
use Goodahead\PaymentTiers\Model\CardBrand;
use Goodahead\PaymentTiers\Model\ComparableAmount;
use Goodahead\PaymentTiers\Model\TierProvider;
use Goodahead\PaymentTiers\Model\TierResolver;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the checkout's view of the current tier.
 */
class TierSnapshot
{
    public function __construct(
        private readonly TierProvider $tierProvider,
        private readonly TierResolver $tierResolver,
        private readonly ComparableAmount $comparableAmount,
        private readonly CardBrand $cardBrand,
        private readonly StoreManagerInterface $storeManager,
        private readonly CheckoutTierFactory $checkoutTierFactory
    ) {
    }

    public function forQuote(?CartInterface $quote): CheckoutTierInterface
    {
        if ($quote === null) {
            return $this->unrestricted();
        }

        $websiteId = (int)$this->storeManager->getStore((int)$quote->getStoreId())->getWebsiteId();

        if (!$this->tierProvider->isEnabled($websiteId)) {
            return $this->unrestricted();
        }

        $amount = $this->comparableAmount->fromQuote($quote, $websiteId);

        if ($amount === null) {
            return $this->unrestricted();
        }

        $tier = $this->tierResolver->resolve($amount, $websiteId);
        $allowed = $tier->getAllowedBrands();

        return $this->build(
            $tier->getMessage(),
            $allowed,
            $allowed !== [] && count($allowed) < count($this->cardBrand->all())
        );
    }

    /**
     * Say nothing rather than guess. The message is presentation; the guard is what refuses.
     */
    private function unrestricted(): CheckoutTierInterface
    {
        return $this->build('', $this->cardBrand->all(), false);
    }

    /**
     * @param string[] $allowedBrands
     */
    private function build(string $message, array $allowedBrands, bool $brandRestricted): CheckoutTierInterface
    {
        return $this->checkoutTierFactory->create(['data' => [
            CheckoutTier::MESSAGE => $message,
            CheckoutTier::CARD_AVAILABLE => $allowedBrands !== [],
            CheckoutTier::ALLOWED_BRANDS => array_values($allowedBrands),
            CheckoutTier::BRAND_RESTRICTED => $brandRestricted,
        ]]);
    }
}
