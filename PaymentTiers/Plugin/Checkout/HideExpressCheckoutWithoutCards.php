<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Plugin\Checkout;

use Goodahead\PaymentTiers\Model\Checkout\TierSnapshot;
use Goodahead\PaymentTiers\Model\RestrictedMethods;
use Magento\Checkout\Model\Session;
use Psr\Log\LoggerInterface;
use StripeIntegration\Payments\Model\ExpressCheckout\Config;

/**
 * Hides the express wallet buttons in any tier that restricts cards.
 *
 * AC-5 puts Apple Pay, Google Pay and Link on the same footing as cards. They are not Magento
 * payment methods, so the method-availability observer never sees them: the Stripe module
 * renders them from its own configuration, and this is where that is decided.
 *
 * They are hidden in the brand-restricted tier as well as the no-cards one, which is stricter
 * than AC-5 asks. The reason is that express buttons confirm the intent in the browser and
 * reach the server already paid, so the placement guard never runs for them and a
 * Visa-funded wallet could not be refused. Until the post-confirmation backstop exists,
 * closing the path is the only honest option; the cost is that an Amex-funded wallet is
 * refused too. Recorded in the README as a known cut.
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

            $tier = $this->tierSnapshot->forQuote($quote);

            return $tier->isCardAvailable() && !$tier->isBrandRestricted();
        } catch (\Throwable $e) {
            // Never break the page over presentation.
            $this->logger->error('Goodahead_PaymentTiers: express checkout tier check failed. ' . $e->getMessage());

            return $result;
        }
    }
}
