<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model\Stripe;

use Goodahead\PaymentTiers\Model\CardBrand;
use StripeIntegration\Payments\Model\Config as StripeConfig;

/**
 * Reads what a payment turned out to be, after Stripe confirmed it.
 *
 * The pre-confirmation reader works from the confirmation token, which express wallet buttons
 * never give us — they confirm in the browser and the payment reaches us finished. Here the
 * charge is the source of truth, and it carries the brand of the card that actually funded the
 * wallet.
 */
class ConfirmedPaymentReader
{
    public function __construct(
        private readonly StripeConfig $stripeConfig,
        private readonly CardBrand $cardBrand
    ) {
    }

    public function read(string $paymentIntentId): ?PaymentMethodDetails
    {
        if (!str_starts_with($paymentIntentId, 'pi_')) {
            return null;
        }

        $intent = $this->stripeConfig->getStripeClient()->paymentIntents->retrieve(
            $paymentIntentId,
            ['expand' => ['latest_charge', 'payment_method']]
        );

        $card = $intent->latest_charge->payment_method_details->card
            ?? $intent->payment_method->card
            ?? null;

        if ($card === null) {
            $type = $intent->payment_method->type ?? null;

            return new PaymentMethodDetails(is_string($type) ? $type : null, null, null);
        }

        $brand = $card->brand ?? null;

        return new PaymentMethodDetails(
            'card',
            is_string($brand) && trim($brand) !== '' ? $this->cardBrand->normalise($brand) : null,
            isset($card->wallet->type) ? (string)$card->wallet->type : null,
            isset($card->last4) ? (string)$card->last4 : null
        );
    }

    /** Whether the payment can still be released without money changing hands. */
    public function isUncaptured(string $paymentIntentId): bool
    {
        $intent = $this->stripeConfig->getStripeClient()->paymentIntents->retrieve($paymentIntentId);

        return ($intent->status ?? '') === 'requires_capture';
    }

    public function release(string $paymentIntentId): string
    {
        $client = $this->stripeConfig->getStripeClient();

        if ($this->isUncaptured($paymentIntentId)) {
            $client->paymentIntents->cancel($paymentIntentId);

            return 'authorisation released';
        }

        $client->refunds->create(['payment_intent' => $paymentIntentId]);

        return 'payment refunded';
    }
}
