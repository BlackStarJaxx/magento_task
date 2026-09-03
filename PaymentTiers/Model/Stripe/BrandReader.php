<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model\Stripe;

use Goodahead\PaymentTiers\Model\CardBrand;
use Magento\Sales\Api\Data\OrderInterface;
use StripeIntegration\Payments\Model\Stripe\ConfirmationTokenFactory;
use StripeIntegration\Payments\Model\Stripe\PaymentMethodFactory;

/**
 * Reads what the customer is about to pay with, before the payment is confirmed.
 *
 * This is the only class that knows how the Stripe module carries a payment method, and it
 * mirrors the vendor's own precedence in Helper\PaymentIntent::getConfirmParams(): a
 * confirmation token when present, the legacy payment method token otherwise.
 *
 * A confirmation token exposes payment_method_preview, which carries the brand of the card
 * funding a wallet as well as a card entered directly. That is what lets AC-5 be met for
 * Apple Pay and Google Pay on this path rather than only after the money has moved.
 */
class BrandReader
{
    public function __construct(
        private readonly ConfirmationTokenFactory $confirmationTokenFactory,
        private readonly PaymentMethodFactory $paymentMethodFactory,
        private readonly CardBrand $cardBrand
    ) {
    }

    public function read(OrderInterface $order): ?PaymentMethodDetails
    {
        $payment = $order->getPayment();

        if ($payment === null) {
            return null;
        }

        $confirmationTokenId = (string)$payment->getAdditionalInformation('confirmation_token');

        if ($confirmationTokenId !== '') {
            $preview = $this->confirmationTokenFactory->create()
                ->fromId($confirmationTokenId)
                ->getPaymentMethodPreview();

            return $this->toDetails($preview);
        }

        $paymentMethodId = (string)$payment->getAdditionalInformation('token');

        if ($paymentMethodId === '') {
            return null;
        }

        return $this->toDetails(
            $this->paymentMethodFactory->create()->fromPaymentMethodId($paymentMethodId)->getStripeObject()
        );
    }

    private function toDetails(mixed $stripeObject): ?PaymentMethodDetails
    {
        if (!is_object($stripeObject)) {
            return null;
        }

        $brand = $stripeObject->card->brand ?? null;

        return new PaymentMethodDetails(
            isset($stripeObject->type) ? (string)$stripeObject->type : null,
            is_string($brand) && trim($brand) !== '' ? $this->cardBrand->normalise($brand) : null,
            isset($stripeObject->card->wallet->type) ? (string)$stripeObject->card->wallet->type : null
        );
    }
}
