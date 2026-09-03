<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model;

use Goodahead\PaymentTiers\Model\Order\TierDecisionRecorder;
use Goodahead\PaymentTiers\Model\Stripe\BrandReader;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The control that actually holds.
 *
 * Runs immediately before the payment intent is confirmed, which on the Payment Element
 * flow happens server-side inside the order transaction — so throwing here means no
 * authorisation is taken and no order row is written: a refused attempt leaves the intent at
 * requires_payment_method with amount_received = 0.
 *
 * Everything is recomputed from the order, never trusted from the client or from the intent:
 * the order is built server-side from the quote, so an intent created when the cart was $500
 * cannot make a $50,000 order look small (AC-1).
 */
class TierGuard
{
    public function __construct(
        private readonly TierProvider $tierProvider,
        private readonly TierResolver $tierResolver,
        private readonly RestrictedMethods $restrictedMethods,
        private readonly ComparableAmount $comparableAmount,
        private readonly BrandReader $brandReader,
        private readonly TierDecisionRecorder $recorder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @throws LocalizedException when the payment must not be confirmed
     */
    public function assertMayBeConfirmed(OrderInterface $order): void
    {
        $websiteId = (int)$this->storeManager->getStore((int)$order->getStoreId())->getWebsiteId();
        $payment = $order->getPayment();

        if ($payment === null || !$this->tierProvider->isEnabled($websiteId)) {
            return;
        }

        if (!$this->restrictedMethods->isRestrictable((string)$payment->getMethod(), $websiteId)) {
            return;
        }

        $amount = $this->comparableAmount->fromOrder($order, $websiteId);

        if ($amount === null) {
            // Not knowing the order value is not evidence that it is small.
            throw new LocalizedException(
                __('This order cannot be paid by card right now. Please use Check / Money Order or Bank Transfer.')
            );
        }

        $tier = $this->tierResolver->resolve($amount, $websiteId);

        if (!$tier->allowsAnyCard() || !$tier->allowsMethod((string)$payment->getMethod())) {
            throw new LocalizedException($this->messageFor($tier));
        }

        $details = $this->brandReader->read($order);

        if ($details === null || !$details->isCard()) {
            // A non-card payment method carries none of the chargeback exposure the tiers
            // exist to cap, so it is not this module's business. A null reading on a
            // restricted method is treated the same way only because the method list has
            // already established the method is available for this tier.
            return;
        }

        if ($details->getBrand() === null) {
            // A card whose brand cannot be established cannot be shown to satisfy the tier.
            throw new LocalizedException($this->messageFor($tier));
        }

        if (!$tier->allowsBrand($details->getBrand())) {
            throw new LocalizedException($this->messageFor($tier));
        }

        $this->recorder->record($order, $tier, $details);
    }

    private function messageFor(Tier $tier): \Magento\Framework\Phrase
    {
        $message = trim($tier->getMessage());

        return $message === ''
            ? __('This card cannot be used for an order of this value.')
            : __($message);
    }
}
