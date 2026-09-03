<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Test\Unit\Observer;

use Goodahead\PaymentTiers\Model\CardBrand;
use Goodahead\PaymentTiers\Model\ComparableAmount;
use Goodahead\PaymentTiers\Model\RestrictedMethods;
use Goodahead\PaymentTiers\Model\Tier;
use Goodahead\PaymentTiers\Model\TierProvider;
use Goodahead\PaymentTiers\Model\TierResolver;
use Goodahead\PaymentTiers\Observer\RestrictCardMethodsByTier;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RestrictCardMethodsByTierTest extends TestCase
{
    private const NO_CARDS = 2500000;
    private const AMEX_ONLY = 1500000;

    public function testHidesACardMethodWhenTheTierAllowsNoCards(): void
    {
        $result = $this->dispatch('stripe_payments', self::NO_CARDS, new Tier(null, [], 'No cards.'));

        self::assertFalse($result->getData('is_available'));
    }

    /**
     * AC-2: above $10,000 the card option is still shown, with the restriction stated.
     * Hiding it here would make the Amex-only tier unreachable.
     */
    public function testLeavesTheCardMethodVisibleWhenOnlyBrandsAreNarrowed(): void
    {
        $result = $this->dispatch('stripe_payments', self::AMEX_ONLY, new Tier(2000000, [CardBrand::AMEX], 'Amex only.'));

        self::assertTrue($result->getData('is_available'));
    }

    /**
     * A tier that permits some governed methods and not others (AC-7) hides the rest, even
     * when cards as such are allowed.
     */
    public function testHidesAGovernedMethodTheTierDoesNotPermit(): void
    {
        $tier = new Tier(2000000, [CardBrand::AMEX], 'Amex only.', ['stripe_payments']);

        self::assertTrue($this->dispatch('stripe_payments', self::AMEX_ONLY, $tier)->getData('is_available'));
        self::assertFalse($this->dispatch('stripe_payments_checkout', self::AMEX_ONLY, $tier)->getData('is_available'));
    }

    public function testNeverTouchesAnOfflineMethod(): void
    {
        $result = $this->dispatch('checkmo', self::NO_CARDS, new Tier(null, [], 'No cards.'), restrictable: false);

        self::assertTrue($result->getData('is_available'));
    }

    public function testDoesNothingWhenTheModuleIsDisabled(): void
    {
        $result = $this->dispatch('stripe_payments', self::NO_CARDS, new Tier(null, [], ''), enabled: false);

        self::assertTrue($result->getData('is_available'));
    }

    /**
     * A total that cannot be expressed in USD is not evidence that the order is small.
     */
    public function testFailsClosedWhenTheAmountCannotBeDetermined(): void
    {
        $result = $this->dispatch('stripe_payments', null, new Tier(null, [], ''));

        self::assertFalse($result->getData('is_available'));
    }

    public function testDoesNotReviveAMethodThatWasAlreadyUnavailable(): void
    {
        $result = $this->dispatch('stripe_payments', 100, new Tier(null, ['visa'], ''), available: false);

        self::assertFalse($result->getData('is_available'));
    }

    public function testIgnoresEventsThatCarryNoQuote(): void
    {
        $observer = new Observer(['event' => new DataObject([
            'result' => $result = new DataObject(['is_available' => true]),
            'method_instance' => $this->method('stripe_payments'),
            'quote' => null,
        ])]);

        $this->build(self::NO_CARDS, new Tier(null, [], ''))->execute($observer);

        self::assertTrue($result->getData('is_available'));
    }

    private function dispatch(
        string $code,
        ?int $amount,
        Tier $tier,
        bool $restrictable = true,
        bool $enabled = true,
        bool $available = true
    ): DataObject {
        $observer = new Observer(['event' => new DataObject([
            'result' => $result = new DataObject(['is_available' => $available]),
            'method_instance' => $this->method($code),
            'quote' => $this->quote(),
        ])]);

        $this->build($amount, $tier, $restrictable, $enabled)->execute($observer);

        return $result;
    }

    private function build(
        ?int $amount,
        Tier $tier,
        bool $restrictable = true,
        bool $enabled = true
    ): RestrictCardMethodsByTier {
        $provider = $this->createStub(TierProvider::class);
        $provider->method('isEnabled')->willReturn($enabled);

        $resolver = $this->createStub(TierResolver::class);
        $resolver->method('resolve')->willReturn($tier);

        $methods = $this->createStub(RestrictedMethods::class);
        $methods->method('isRestrictable')->willReturn($restrictable);

        $comparableAmount = $this->createStub(ComparableAmount::class);
        $comparableAmount->method('fromQuote')->willReturn($amount);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new RestrictCardMethodsByTier($provider, $resolver, $methods, $comparableAmount, $storeManager, new NullLogger());
    }

    private function method(string $code): MethodInterface
    {
        $method = $this->createStub(MethodInterface::class);
        $method->method('getCode')->willReturn($code);

        return $method;
    }

    private function quote(): CartInterface
    {
        $quote = $this->createStub(CartInterface::class);
        $quote->method('getStoreId')->willReturn(1);

        return $quote;
    }
}
