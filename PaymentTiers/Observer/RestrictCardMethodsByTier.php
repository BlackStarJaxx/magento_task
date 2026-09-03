<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Observer;

use Goodahead\PaymentTiers\Model\ComparableAmount;
use Goodahead\PaymentTiers\Model\RestrictedMethods;
use Goodahead\PaymentTiers\Model\TierProvider;
use Goodahead\PaymentTiers\Model\TierResolver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Hides card payment methods when the tier allows no cards at all.
 *
 * `payment_method_is_active` is dispatched from Magento\Payment\Model\Method\Adapter and
 * from AbstractMethod, so it covers both the MethodList path that renders the checkout and
 * the direct isAvailable() call made while an order is placed. That is why it is preferred
 * over a Checks\SpecificationInterface, which only runs through MethodList.
 *
 * This is a presentation control: it stops an unusable option being offered. It is NOT what
 * makes the restriction hold — a client that never asks Magento what is available is
 * unaffected by anything decided here. Enforcement lives at placement time.
 *
 * Only the "no cards" tier is acted on. A tier that narrows brands leaves the method
 * visible on purpose: AC-2 requires the card option to still be shown above $10,000, with
 * the Amex-only restriction stated before the customer starts typing.
 */
class RestrictCardMethodsByTier implements ObserverInterface
{
    public function __construct(
        private readonly TierProvider $tierProvider,
        private readonly TierResolver $tierResolver,
        private readonly RestrictedMethods $restrictedMethods,
        private readonly ComparableAmount $comparableAmount,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $result = $observer->getEvent()->getData('result');

        if ($result === null || !$result->getData('is_available')) {
            return;
        }

        $method = $observer->getEvent()->getData('method_instance');
        $quote = $observer->getEvent()->getData('quote');

        if ($method === null || !$quote instanceof CartInterface) {
            // No quote means no total, and a guess is worse than leaving the decision to the
            // enforcement layer. Admin orders arrive here without one.
            return;
        }

        try {
            // CartInterface exposes only the store id, so the website is resolved through
            // the store manager rather than reaching for the concrete Quote model.
            $websiteId = (int)$this->storeManager->getStore((int)$quote->getStoreId())->getWebsiteId();

            if (!$this->tierProvider->isEnabled($websiteId)
                || !$this->restrictedMethods->isRestrictable((string)$method->getCode(), $websiteId)
            ) {
                return;
            }

            $amount = $this->comparableAmount->fromQuote($quote, $websiteId);

            if ($amount === null) {
                $result->setData('is_available', false);

                return;
            }

            $tier = $this->tierResolver->resolve($amount, $websiteId);

            // Two separate reasons to hide: the tier allows no card at all, or it permits
            // some governed methods and not this one.
            if (!$tier->allowsAnyCard() || !$tier->allowsMethod((string)$method->getCode())) {
                $result->setData('is_available', false);
            }
        } catch (\Throwable $e) {
            // An observer must never be the reason a checkout page fails to render. The
            // placement-time guard still refuses the order, so failing open here costs
            // presentation, not enforcement.
            $this->logger->error(
                'Goodahead_PaymentTiers: could not evaluate the tier for payment method '
                . (string)$method->getCode() . '. ' . $e->getMessage()
            );
        }
    }
}
