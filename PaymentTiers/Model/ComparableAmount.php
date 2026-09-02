<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model;

use Goodahead\PaymentTiers\Model\Config\Source\CurrencyMode;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * The number a tier is decided on: the order value expressed in US cents.
 *
 * AC-6 asks for this to be deliberate and documented, so both readings are implemented and
 * the choice is configuration:
 *
 * - convert_to_usd (default): the base total is converted to USD at the rate the store
 *   maintains. "$10,000" then means the same exposure on every website, which is what the
 *   finance decision behind this task is about. The cost is that a tier now depends on an
 *   admin-maintained exchange rate, and a stale rate can move an order across a boundary.
 * - base_currency: the base total is compared as-is. Stable and rate-independent, but
 *   "10,000" then means 10,000 units of whatever the store sells in, so a EUR website
 *   enforces a materially different limit than the USD one.
 *
 * The base total is used rather than the display total: the store's own books are kept in
 * base currency, and it does not shift when a customer switches display currency.
 */
class ComparableAmount
{
    private const USD = 'USD';

    public function __construct(
        private readonly TierProvider $tierProvider,
        private readonly CurrencyFactory $currencyFactory,
        private readonly MinorUnits $minorUnits,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Null means "cannot be determined" — the caller must then fail closed, never open.
     */
    public function fromQuote(CartInterface $quote, ?int $websiteId = null): ?int
    {
        return $this->convert(
            (float)$quote->getBaseGrandTotal(),
            (string)$quote->getBaseCurrencyCode(),
            $websiteId
        );
    }

    /**
     * The order is the anchor at placement time: it is built server-side from the quote, so
     * a client that replays an old intent cannot move it.
     */
    public function fromOrder(OrderInterface $order, ?int $websiteId = null): ?int
    {
        return $this->convert(
            (float)$order->getBaseGrandTotal(),
            (string)$order->getBaseCurrencyCode(),
            $websiteId
        );
    }

    private function convert(float $baseGrandTotal, string $baseCurrency, ?int $websiteId): ?int
    {
        $baseTotal = sprintf('%.8F', $baseGrandTotal);

        if ($this->tierProvider->getCurrencyMode($websiteId) === CurrencyMode::BASE_CURRENCY
            || $baseCurrency === self::USD
            || $baseCurrency === ''
        ) {
            return $this->minorUnits->fromAmount($baseTotal);
        }

        $rate = $this->getRate($baseCurrency);

        if ($rate === null) {
            $this->logger->critical(sprintf(
                'Goodahead_PaymentTiers: no %s->%s exchange rate is configured, so the order '
                . 'value cannot be compared against the thresholds. Treating the order as the '
                . 'most restricted tier.',
                $baseCurrency,
                self::USD
            ));

            return null;
        }

        return $this->minorUnits->fromAmount(bcmul($baseTotal, $rate, 8));
    }

    private function getRate(string $baseCurrency): ?string
    {
        try {
            $rate = $this->currencyFactory->create()->load($baseCurrency)->getAnyRate(self::USD);
        } catch (\Throwable $e) {
            $this->logger->critical('Goodahead_PaymentTiers: exchange rate lookup failed. ' . $e->getMessage());

            return null;
        }

        if (!$rate || (float)$rate <= 0) {
            return null;
        }

        return sprintf('%.10F', (float)$rate);
    }
}
