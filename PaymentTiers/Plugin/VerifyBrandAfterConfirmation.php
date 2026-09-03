<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Plugin;

use Goodahead\PaymentTiers\Model\Stripe\ConfirmedPaymentReader;
use Goodahead\PaymentTiers\Model\Tier;
use Goodahead\PaymentTiers\Model\TierForOrder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use StripeIntegration\Payments\Model\PaymentElement;

/**
 * The backstop for payments the placement guard cannot see.
 *
 * Express wallet buttons and GraphQL confirm the intent in the browser, so by the time
 * PaymentElement::confirm() runs it has nothing left to confirm and returns the finished
 * payment. The guard on getConfirmParams is never reached on that path — this is.
 *
 * Running after confirm() rather than on an order save is deliberate: it happens exactly once,
 * with the payment in hand, and still before the order row is written. A refusal therefore
 * leaves no order behind, and the money is released rather than refunded because restricted
 * tiers force manual capture (see StampIntentForTier).
 */
class VerifyBrandAfterConfirmation
{
    public function __construct(
        private readonly TierForOrder $tierForOrder,
        private readonly ConfirmedPaymentReader $reader,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param mixed $result
     * @param mixed $order
     * @return mixed
     * @throws LocalizedException
     */
    public function afterConfirm(PaymentElement $subject, $result, $order = null)
    {
        if (!$order instanceof OrderInterface || !is_object($result) || !isset($result->id)) {
            return $result;
        }

        $intentId = (string)$result->id;

        if (!str_starts_with($intentId, 'pi_') || !$this->tierForOrder->isGoverned($order)) {
            return $result;
        }

        $tier = $this->tierForOrder->resolve($order);

        if ($tier === null) {
            // Governed but unpriceable. The guard would have refused; so does this.
            $this->refuse($intentId, __('This order cannot be paid by card right now.'), $order);
        }

        $details = $this->reader->read($intentId);

        if ($details === null || !$details->isCard()) {
            return $result;
        }

        if ($details->getBrand() !== null && $tier->allowsBrand($details->getBrand())) {
            return $result;
        }

        $this->refuse($intentId, $this->messageFor($tier), $order);
    }

    /**
     * @throws LocalizedException
     */
    private function refuse(string $intentId, \Magento\Framework\Phrase $message, OrderInterface $order): never
    {
        try {
            $outcome = $this->reader->release($intentId);
            $this->logger->warning(sprintf(
                'Goodahead_PaymentTiers: refused %s for order %s after confirmation, %s.',
                $intentId,
                (string)$order->getIncrementId(),
                $outcome
            ));
        } catch (\Throwable $e) {
            // Refusing without releasing would leave the customer charged for an order that
            // will not exist, so this is loud.
            $this->logger->critical(sprintf(
                'Goodahead_PaymentTiers: could not release %s for order %s. %s',
                $intentId,
                (string)$order->getIncrementId(),
                $e->getMessage()
            ));
        }

        throw new LocalizedException($message);
    }

    private function messageFor(Tier $tier): \Magento\Framework\Phrase
    {
        $message = trim($tier->getMessage());

        return $message === ''
            ? __('This card cannot be used for an order of this value.')
            : __($message);
    }
}
