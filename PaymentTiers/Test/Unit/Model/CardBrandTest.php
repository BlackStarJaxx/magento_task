<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Test\Unit\Model;

use Goodahead\PaymentTiers\Model\CardBrand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CardBrandTest extends TestCase
{
    private CardBrand $cardBrand;

    protected function setUp(): void
    {
        $this->cardBrand = new CardBrand();
    }

    #[DataProvider('spellingProvider')]
    public function testNormalisesTheSpellingsStripeAndAdministratorsProduce(string $input, string $expected): void
    {
        self::assertSame($expected, $this->cardBrand->normalise($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function spellingProvider(): array
    {
        return [
            'already normal' => ['amex', 'amex'],
            'stripe long form' => ['american_express', 'amex'],
            'typed with a space' => ['American Express', 'amex'],
            'typed with a hyphen' => ['american-express', 'amex'],
            'upper case' => ['VISA', 'visa'],
            'padded' => ['  mastercard  ', 'mastercard'],
            'diners long form' => ['diners_club', 'diners'],
            'cartes bancaires' => ['Cartes Bancaires', 'cartes_bancaires'],
        ];
    }

    public function testRejectsBrandsItDoesNotKnow(): void
    {
        self::assertFalse($this->cardBrand->isKnown('bitcoin'));
        self::assertFalse($this->cardBrand->isKnown(''));
        self::assertTrue($this->cardBrand->isKnown('American Express'));
    }
}
