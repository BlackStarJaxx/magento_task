<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Test\Unit\Model;

use Goodahead\PaymentTiers\Model\CardBrand;
use Goodahead\PaymentTiers\Model\Tier;
use Goodahead\PaymentTiers\Model\TierProvider;
use Goodahead\PaymentTiers\Model\TierResolver;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TierResolverTest extends TestCase
{
    private const ALL_BRANDS = ['visa', 'mastercard', 'amex', 'discover', 'diners', 'jcb', 'unionpay', 'cartes_bancaires'];

    private TierProvider&Stub $tierProvider;
    private TierResolver $resolver;

    protected function setUp(): void
    {
        $this->tierProvider = $this->createStub(TierProvider::class);
        $this->resolver = new TierResolver($this->tierProvider);

        $this->tierProvider->method('getTiers')->willReturn([
            new Tier(1000000, self::ALL_BRANDS, ''),
            new Tier(2000000, [CardBrand::AMEX], 'American Express only above $10,000.'),
            new Tier(null, [], 'No cards above $20,000.'),
        ]);
    }

    /**
     * AC-9, verbatim: the four values the acceptance criteria name.
     *
     * @param string[] $expectedBrands
     */
    #[DataProvider('boundaryProvider')]
    public function testBoundariesAreExact(int $amountMinorUnits, array $expectedBrands): void
    {
        self::assertSame($expectedBrands, $this->resolver->resolve($amountMinorUnits)->getAllowedBrands());
    }

    /**
     * @return array<string, array{int, string[]}>
     */
    public static function boundaryProvider(): array
    {
        return [
            '$10,000.00 permits all card brands' => [1000000, self::ALL_BRANDS],
            '$10,000.01 permits Amex only' => [1000001, [CardBrand::AMEX]],
            '$20,000.00 permits Amex only' => [2000000, [CardBrand::AMEX]],
            '$20,000.01 permits no cards' => [2000001, []],
        ];
    }

    public function testTheLowestTierCoversEverythingBelowIt(): void
    {
        self::assertTrue($this->resolver->resolve(0)->allowsBrand('visa'));
        self::assertTrue($this->resolver->resolve(1)->allowsBrand('visa'));
        self::assertTrue($this->resolver->resolve(999999)->allowsBrand('visa'));
    }

    public function testTheTopTierIsUnbounded(): void
    {
        $tier = $this->resolver->resolve(PHP_INT_MAX);

        self::assertTrue($tier->isUnbounded());
        self::assertFalse($tier->allowsAnyCard());
    }

    /**
     * AC-7 asks for the allowed methods per tier to be editable, so a tier can narrow the
     * governed methods further. An empty list means the tier says nothing about methods,
     * which is what keeps the default table readable.
     */
    public function testATierCanNarrowTheMethodsItPermits(): void
    {
        $unrestricted = new Tier(1000000, self::ALL_BRANDS, '');
        $narrowed = new Tier(2000000, [CardBrand::AMEX], 'Amex only.', ['stripe_payments']);

        self::assertTrue($unrestricted->allowsMethod('stripe_payments'));
        self::assertTrue($unrestricted->allowsMethod('stripe_payments_checkout'), 'an empty list forbids nothing');

        self::assertTrue($narrowed->allowsMethod('stripe_payments'));
        self::assertFalse($narrowed->allowsMethod('stripe_payments_checkout'));
    }

    public function testARestrictedTierCarriesAMessageForTheCustomer(): void
    {
        self::assertNotSame('', $this->resolver->resolve(1500000)->getMessage());
        self::assertNotSame('', $this->resolver->resolve(2500000)->getMessage());
    }

    /**
     * The provider sorts, but the resolver must not depend on it having done so: a future
     * change there should not silently widen what a customer is allowed to pay with.
     */
    public function testResolutionDoesNotDependOnTheOrderTiersArriveIn(): void
    {
        $provider = $this->createStub(TierProvider::class);
        $provider->method('getTiers')->willReturn([
            new Tier(2000000, [CardBrand::AMEX], 'Amex only.'),
            new Tier(null, [], 'No cards.'),
            new Tier(1000000, self::ALL_BRANDS, ''),
        ]);

        $resolver = new TierResolver($provider);

        self::assertSame(self::ALL_BRANDS, $resolver->resolve(500000)->getAllowedBrands());
        self::assertSame([CardBrand::AMEX], $resolver->resolve(1500000)->getAllowedBrands());
        self::assertSame([], $resolver->resolve(2500000)->getAllowedBrands());
    }
}
