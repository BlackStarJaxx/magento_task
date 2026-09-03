<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model\Order;

use Goodahead\PaymentTiers\Model\CardBrand;
use Goodahead\PaymentTiers\Model\MinorUnits;
use Goodahead\PaymentTiers\Model\Stripe\PaymentMethodDetails;
use Goodahead\PaymentTiers\Model\Tier;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Writes onto the order what was allowed to pay for it, and why.
 *
 * The Stripe module stores neither the brand nor the last four digits; the admin fetches them
 * from the Stripe API every time an order is opened. That is fine until the keys are rotated
 * or the account is unreachable, and it leaves nothing behind about which tier applied — the
 * one thing worth having when a chargeback arrives months later and the tier configuration
 * has since changed.
 *
 * Only the masked digits and the brand are recorded. The full number never reaches this
 * server: the browser tokenises directly with Stripe and we see nothing but a token, so there
 * is no cardholder data here to protect. This mirrors what Magento's own Braintree module
 * writes in Gateway\Response\CardDetailsHandler.
 */
class TierDecisionRecorder
{
    public const string TIER_UPPER_BOUND = 'goodahead_tier_upper_bound';
    public const string TIER_ALLOWED_BRANDS = 'goodahead_tier_allowed_brands';
    public const string ACCEPTED_BRAND = 'goodahead_accepted_brand';
    public const string PAYMENT_METHOD_TYPE = 'goodahead_payment_method_type';
    public const string WALLET = 'goodahead_wallet';

    public function __construct(
        private readonly CardBrand $cardBrand,
        private readonly MinorUnits $minorUnits
    ) {
    }

    public function record(OrderInterface $order, Tier $tier, PaymentMethodDetails $details): void
    {
        $payment = $order->getPayment();

        if ($payment === null) {
            return;
        }

        if ($details->getBrand() !== null) {
            $payment->setCcType($this->cardBrand->toMagentoCode($details->getBrand()));
        }

        if ($details->getLast4() !== null) {
            $payment->setCcLast4($details->getLast4());
        }

        if (!$payment instanceof InfoInterface) {
            return;
        }

        $bound = $tier->getUpperBoundMinorUnits();

        $payment->setAdditionalInformation(
            self::TIER_UPPER_BOUND,
            $bound === null ? '' : $this->minorUnits->toAmountString($bound)
        );
        $payment->setAdditionalInformation(self::TIER_ALLOWED_BRANDS, implode(',', $tier->getAllowedBrands()));
        $payment->setAdditionalInformation(self::ACCEPTED_BRAND, (string)$details->getBrand());
        $payment->setAdditionalInformation(self::PAYMENT_METHOD_TYPE, (string)$details->getType());

        if ($details->getWallet() !== null) {
            $payment->setAdditionalInformation(self::WALLET, $details->getWallet());
        }
    }
}
