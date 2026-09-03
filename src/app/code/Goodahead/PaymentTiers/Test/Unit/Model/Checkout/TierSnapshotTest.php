<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Test\Unit\Model\Checkout;

use Goodahead\PaymentTiers\Api\Data\CheckoutTierInterface;
use Goodahead\PaymentTiers\Model\CardBrand;
use Goodahead\PaymentTiers\Model\Checkout\CheckoutTier;
use Goodahead\PaymentTiers\Model\Checkout\CheckoutTierFactory;
use Goodahead\PaymentTiers\Model\Checkout\TierSnapshot;
use Goodahead\PaymentTiers\Model\ComparableAmount;
use Goodahead\PaymentTiers\Model\Tier;
use Goodahead\PaymentTiers\Model\TierProvider;
use Goodahead\PaymentTiers\Model\TierResolver;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class TierSnapshotTest extends TestCase
{
    private const ALL_BRANDS = ['visa', 'mastercard', 'amex', 'discover', 'diners', 'jcb', 'unionpay', 'cartes_bancaires'];

    public function testReportsNothingWhenEveryBrandIsAllowed(): void
    {
        $tier = $this->snapshot(new Tier(1000000, self::ALL_BRANDS, ''), 500000)->forQuote($this->quote());

        self::assertSame('', $tier->getMessage());
        self::assertTrue($tier->isCardAvailable());
        self::assertFalse($tier->isBrandRestricted());
    }

    public function testReportsTheRestrictionInTheMiddleTier(): void
    {
        $tier = $this->snapshot(new Tier(2000000, ['amex'], 'Amex only.'), 1500000)->forQuote($this->quote());

        self::assertSame('Amex only.', $tier->getMessage());
        self::assertTrue($tier->isCardAvailable(), 'the card option must stay on offer');
        self::assertTrue($tier->isBrandRestricted());
        self::assertSame(['amex'], $tier->getAllowedBrands());
    }

    public function testReportsThatNoCardIsAvailableInTheTopTier(): void
    {
        $tier = $this->snapshot(new Tier(null, [], 'No cards.'), 2500000)->forQuote($this->quote());

        self::assertFalse($tier->isCardAvailable());
        self::assertFalse($tier->isBrandRestricted(), 'nothing is narrowed when nothing is allowed');
        self::assertSame('No cards.', $tier->getMessage());
    }

    public function testSaysNothingWithoutAQuote(): void
    {
        $tier = $this->snapshot(new Tier(null, [], 'No cards.'), 2500000)->forQuote(null);

        self::assertSame('', $tier->getMessage());
    }

    public function testSaysNothingWhenTheModuleIsDisabled(): void
    {
        $tier = $this->snapshot(new Tier(null, [], 'No cards.'), 2500000, enabled: false)->forQuote($this->quote());

        self::assertSame('', $tier->getMessage());
    }

    /**
     * The message is presentation. When the value cannot be established the guard still
     * refuses at placement, so staying quiet here beats inventing a warning.
     */
    public function testSaysNothingWhenTheOrderValueCannotBeDetermined(): void
    {
        $tier = $this->snapshot(new Tier(null, [], 'No cards.'), null)->forQuote($this->quote());

        self::assertSame('', $tier->getMessage());
    }

    private function snapshot(Tier $tier, ?int $amount, bool $enabled = true): TierSnapshot
    {
        $provider = $this->createStub(TierProvider::class);
        $provider->method('isEnabled')->willReturn($enabled);

        $resolver = $this->createStub(TierResolver::class);
        $resolver->method('resolve')->willReturn($tier);

        $amounts = $this->createStub(ComparableAmount::class);
        $amounts->method('fromQuote')->willReturn($amount);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $factory = $this->createStub(CheckoutTierFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $arguments): CheckoutTierInterface => new CheckoutTier($arguments['data'] ?? [])
        );

        return new TierSnapshot($provider, $resolver, $amounts, new CardBrand(), $storeManager, $factory);
    }

    private function quote(): CartInterface
    {
        $quote = $this->createStub(CartInterface::class);
        $quote->method('getStoreId')->willReturn(1);

        return $quote;
    }
}
