<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Plugin\Checkout;

use Goodahead\PaymentTiers\Model\Checkout\TierSnapshot;
use Goodahead\PaymentTiers\Model\RestrictedMethods;
use Magento\Checkout\Model\Session;
use Psr\Log\LoggerInterface;
use StripeIntegration\Payments\Model\ExpressCheckout\Config;

/**
 * Hides the express wallet buttons when the tier allows no cards at all.
 *
 * AC-5 puts Apple Pay, Google Pay and Link on the same footing as cards. They are not Magento
 * payment methods, so the method-availability observer never sees them: the Stripe module
 * renders them from its own configuration, and this is where that is decided.
 *
 * In the brand-restricted tier they stay on offer, because AC-5 wants an Amex-funded wallet
 * accepted there. What makes that safe is VerifyBrandAfterConfirmation: express buttons
 * confirm in the browser and arrive already paid, so a wallet funded by the wrong brand is
 * caught immediately afterwards and the authorisation released.
 */
class HideExpressCheckoutWithoutCards
{
    public function __construct(
        private readonly TierSnapshot $tierSnapshot,
        private readonly RestrictedMethods $restrictedMethods,
        private readonly Session $checkoutSession,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $location
     */
    public function afterIsEnabled(Config $subject, bool $result, $location = null): bool
    {
        if (!$result) {
            return false;
        }

        try {
            $quote = $this->checkoutSession->getQuote();
            $websiteId = (int)$quote->getStore()->getWebsiteId();

            if (!$this->restrictedMethods->isRestrictable('stripe_payments_express', $websiteId)) {
                return true;
            }

            return $this->tierSnapshot->forQuote($quote)->isCardAvailable();
        } catch (\Throwable $e) {
            // Never break the page over presentation.
            $this->logger->error('Goodahead_PaymentTiers: express checkout tier check failed. ' . $e->getMessage());

            return $result;
        }
    }
}
