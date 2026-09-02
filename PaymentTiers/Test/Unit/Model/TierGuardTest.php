<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Test\Unit\Model;

use Goodahead\PaymentTiers\Model\CardBrand;
use Goodahead\PaymentTiers\Model\ComparableAmount;
use Goodahead\PaymentTiers\Model\RestrictedMethods;
use Goodahead\PaymentTiers\Model\Stripe\BrandReader;
use Goodahead\PaymentTiers\Model\Stripe\PaymentMethodDetails;
use Goodahead\PaymentTiers\Model\Tier;
use Goodahead\PaymentTiers\Model\TierGuard;
use Goodahead\PaymentTiers\Model\TierProvider;
use Goodahead\PaymentTiers\Model\TierResolver;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class TierGuardTest extends TestCase
{
    private const AMEX_ONLY = 1500000;
    private const NO_CARDS = 2500000;

    private const ALL_CARDS = ['visa', 'mastercard', 'amex'];

    public function testAllowsAnAllowedBrand(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard(
            new Tier(2000000, [CardBrand::AMEX], 'Amex only.'),
            self::AMEX_ONLY,
            new PaymentMethodDetails('card', CardBrand::AMEX, null)
        )->assertMayBeConfirmed($this->order());
    }

    public function testRefusesABlockedBrandWithTheTierMessage(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Amex only.');

        $this->guard(
            new Tier(2000000, [CardBrand::AMEX], 'Amex only.'),
            self::AMEX_ONLY,
            new PaymentMethodDetails('card', 'visa', null)
        )->assertMayBeConfirmed($this->order());
    }

    /**
     * AC-5: a wallet is a card underneath, and the funding brand is what counts.
     */
    public function testRefusesAWalletFundedByABlockedBrand(): void
    {
        $this->expectException(LocalizedException::class);

        $this->guard(
            new Tier(2000000, [CardBrand::AMEX], 'Amex only.'),
            self::AMEX_ONLY,
            new PaymentMethodDetails('card', 'visa', 'apple_pay')
        )->assertMayBeConfirmed($this->order());
    }

    public function testAcceptsAWalletFundedByAnAllowedBrand(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard(
            new Tier(2000000, [CardBrand::AMEX], 'Amex only.'),
            self::AMEX_ONLY,
            new PaymentMethodDetails('card', CardBrand::AMEX, 'google_pay')
        )->assertMayBeConfirmed($this->order());
    }

    public function testRefusesEveryCardInTheNoCardsTier(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No cards above $20,000.');

        $this->guard(
            new Tier(null, [], 'No cards above $20,000.'),
            self::NO_CARDS,
            new PaymentMethodDetails('card', CardBrand::AMEX, null)
        )->assertMayBeConfirmed($this->order());
    }

    /**
     * A card that will not say what it is cannot be shown to satisfy an Amex-only tier.
     */
    public function testRefusesACardWhoseBrandCannotBeEstablished(): void
    {
        $this->expectException(LocalizedException::class);

        $this->guard(
            new Tier(2000000, [CardBrand::AMEX], 'Amex only.'),
            self::AMEX_ONLY,
            new PaymentMethodDetails('card', null, null)
        )->assertMayBeConfirmed($this->order());
    }

    /**
     * The tiers exist to cap chargeback exposure, which is a card problem. A SEPA debit or
     * an iDEAL payment offered by the same Stripe method is none of this module's business.
     */
    public function testIgnoresNonCardPaymentMethods(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard(
            new Tier(2000000, [CardBrand::AMEX], 'Amex only.'),
            self::AMEX_ONLY,
            new PaymentMethodDetails('sepa_debit', null, null)
        )->assertMayBeConfirmed($this->order());
    }

    public function testFailsClosedWhenTheOrderValueCannotBeExpressedInUsd(): void
    {
        $this->expectException(LocalizedException::class);

        $this->guard(
            new Tier(2000000, [CardBrand::AMEX], 'Amex only.'),
            null,
            new PaymentMethodDetails('card', CardBrand::AMEX, null)
        )->assertMayBeConfirmed($this->order());
    }

    public function testDoesNothingWhenTheModuleIsDisabled(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard(
            new Tier(null, [], 'No cards.'),
            self::NO_CARDS,
            new PaymentMethodDetails('card', 'visa', null),
            enabled: false
        )->assertMayBeConfirmed($this->order());
    }

    public function testDoesNothingForAMethodTiersDoNotGovern(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard(
            new Tier(null, [], 'No cards.'),
            self::NO_CARDS,
            new PaymentMethodDetails('card', 'visa', null),
            restrictable: false
        )->assertMayBeConfirmed($this->order());
    }

    private function guard(
        Tier $tier,
        ?int $amount,
        PaymentMethodDetails $details,
        bool $enabled = true,
        bool $restrictable = true
    ): TierGuard {
        $provider = $this->createStub(TierProvider::class);
        $provider->method('isEnabled')->willReturn($enabled);

        $resolver = $this->createStub(TierResolver::class);
        $resolver->method('resolve')->willReturn($tier);

        $methods = $this->createStub(RestrictedMethods::class);
        $methods->method('isRestrictable')->willReturn($restrictable);

        $amounts = $this->createStub(ComparableAmount::class);
        $amounts->method('fromOrder')->willReturn($amount);

        $brands = $this->createStub(BrandReader::class);
        $brands->method('read')->willReturn($details);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new TierGuard($provider, $resolver, $methods, $amounts, $brands, $storeManager);
    }

    private function order(): OrderInterface
    {
        $payment = $this->createStub(OrderPaymentInterface::class);
        $payment->method('getMethod')->willReturn('stripe_payments');

        $order = $this->createStub(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);

        return $order;
    }
}
