<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Plugin;

use Goodahead\PaymentTiers\Model\TierGuard;
use StripeIntegration\Payments\Helper\PaymentIntent;

/**
 * Refuses a payment that the current tier does not allow, before Stripe is asked to confirm.
 *
 * getConfirmParams() is the last public seam the vendor calls before
 * $stripeClient->paymentIntents->confirm(), and it is reached on every Payment Element
 * placement. A plugin here needs no preference and overrides no Stripe class, which the
 * Definition of Done requires.
 *
 * The exception is deliberately allowed to propagate: PaymentMethod::pay() catches it,
 * sends the vendor's payment-failed notification and rethrows, Magento abandons the order,
 * and the intent is left unconfirmed with nothing authorised.
 */
class GuardPaymentIntentConfirmation
{
    public function __construct(private readonly TierGuard $tierGuard)
    {
    }

    /**
     * @param mixed $order
     * @param mixed $paymentIntent
     * @return array{0: mixed, 1: mixed}
     */
    public function beforeGetConfirmParams(PaymentIntent $subject, $order, $paymentIntent): array
    {
        if ($order instanceof \Magento\Sales\Api\Data\OrderInterface) {
            $this->tierGuard->assertMayBeConfirmed($order);
        }

        return [$order, $paymentIntent];
    }
}
