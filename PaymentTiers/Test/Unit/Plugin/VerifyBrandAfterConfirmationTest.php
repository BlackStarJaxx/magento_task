<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Test\Unit\Plugin;

use Goodahead\PaymentTiers\Model\CardBrand;
use Goodahead\PaymentTiers\Model\Stripe\ConfirmedPaymentReader;
use Goodahead\PaymentTiers\Model\Stripe\PaymentMethodDetails;
use Goodahead\PaymentTiers\Model\Tier;
use Goodahead\PaymentTiers\Model\TierForOrder;
use Goodahead\PaymentTiers\Plugin\VerifyBrandAfterConfirmation;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use StripeIntegration\Payments\Model\PaymentElement;

class VerifyBrandAfterConfirmationTest extends TestCase
{
    private const INTENT_ID = 'pi_3ABC';

    private ConfirmedPaymentReader $reader;

    /**
     * AC-5: an Amex-funded wallet must go through in the Amex-only tier. This is the case the
     * backstop exists to allow, not only the one it exists to stop.
     */
    public function testLetsAnAllowedBrandThrough(): void
    {
        $this->reader = $this->createMock(ConfirmedPaymentReader::class);
        $this->reader->expects(self::never())->method('release');
        $this->reader->method('read')->willReturn(new PaymentMethodDetails('card', CardBrand::AMEX, 'apple_pay', '0005'));

        $result = $this->intent();

        self::assertSame($result, $this->plugin($this->amexOnly())->afterConfirm($this->subject(), $result, $this->order()));
    }

    public function testReleasesTheMoneyAndRefusesABlockedBrand(): void
    {
        $this->reader = $this->createMock(ConfirmedPaymentReader::class);
        $this->reader->expects(self::once())->method('release')->with(self::INTENT_ID)->willReturn('authorisation released');
        $this->reader->method('read')->willReturn(new PaymentMethodDetails('card', 'visa', 'google_pay', '4242'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Amex only.');

        $this->plugin($this->amexOnly())->afterConfirm($this->subject(), $this->intent(), $this->order());
    }

    /**
     * The refusal must stand even when the release fails, or a customer could end up holding
     * a valid order we meant to reject. The failure is logged as critical instead.
     */
    public function testStillRefusesWhenTheMoneyCannotBeReleased(): void
    {
        // A stub, not a mock: nothing here is asserted about how it was called.
        $reader = $this->createStub(ConfirmedPaymentReader::class);
        $reader->method('read')->willReturn(new PaymentMethodDetails('card', 'visa', null, '4242'));
        $reader->method('release')->willThrowException(new \RuntimeException('Stripe is down'));
        $this->reader = $reader;

        $this->expectException(LocalizedException::class);

        $this->plugin($this->amexOnly())->afterConfirm($this->subject(), $this->intent(), $this->order());
    }

    public function testIgnoresNonCardPayments(): void
    {
        $this->reader = $this->createMock(ConfirmedPaymentReader::class);
        $this->reader->expects(self::never())->method('release');
        $this->reader->method('read')->willReturn(new PaymentMethodDetails('sepa_debit', null, null, null));

        $result = $this->intent();

        self::assertSame($result, $this->plugin($this->amexOnly())->afterConfirm($this->subject(), $result, $this->order()));
    }

    public function testIgnoresOrdersTheTiersDoNotGovern(): void
    {
        $this->reader = $this->createMock(ConfirmedPaymentReader::class);
        $this->reader->expects(self::never())->method('read');

        $tierForOrder = $this->createStub(TierForOrder::class);
        $tierForOrder->method('isGoverned')->willReturn(false);

        $result = $this->intent();
        $plugin = new VerifyBrandAfterConfirmation($tierForOrder, $this->reader, new NullLogger());

        self::assertSame($result, $plugin->afterConfirm($this->subject(), $result, $this->order()));
    }

    /**
     * Governed but unpriceable is a reason to refuse, never to wave through.
     */
    public function testRefusesWhenTheOrderCannotBePriced(): void
    {
        $this->reader = $this->createMock(ConfirmedPaymentReader::class);
        $this->reader->expects(self::once())->method('release');

        $tierForOrder = $this->createStub(TierForOrder::class);
        $tierForOrder->method('isGoverned')->willReturn(true);
        $tierForOrder->method('resolve')->willReturn(null);

        $this->expectException(LocalizedException::class);

        (new VerifyBrandAfterConfirmation($tierForOrder, $this->reader, new NullLogger()))
            ->afterConfirm($this->subject(), $this->intent(), $this->order());
    }

    private function plugin(Tier $tier): VerifyBrandAfterConfirmation
    {
        $tierForOrder = $this->createStub(TierForOrder::class);
        $tierForOrder->method('isGoverned')->willReturn(true);
        $tierForOrder->method('resolve')->willReturn($tier);

        return new VerifyBrandAfterConfirmation($tierForOrder, $this->reader, new NullLogger());
    }

    private function amexOnly(): Tier
    {
        return new Tier(2000000, [CardBrand::AMEX], 'Amex only.');
    }

    private function intent(): \stdClass
    {
        $intent = new \stdClass();
        $intent->id = self::INTENT_ID;

        return $intent;
    }

    private function subject(): PaymentElement
    {
        return $this->createStub(PaymentElement::class);
    }

    private function order(): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000042');

        return $order;
    }
}
