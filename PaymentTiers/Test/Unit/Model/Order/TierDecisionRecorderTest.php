<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Test\Unit\Model\Order;

use Goodahead\PaymentTiers\Model\CardBrand;
use Goodahead\PaymentTiers\Model\MinorUnits;
use Goodahead\PaymentTiers\Model\Order\TierDecisionRecorder;
use Goodahead\PaymentTiers\Model\Stripe\PaymentMethodDetails;
use Goodahead\PaymentTiers\Model\Tier;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

// Magento's unit-test ObjectManager mocks the constructor dependencies of the real Payment
// it builds; none of them are asserted on, and that is the point.
#[AllowMockObjectsWithoutExpectations]
class TierDecisionRecorderTest extends TestCase
{
    private TierDecisionRecorder $recorder;

    protected function setUp(): void
    {
        $this->recorder = new TierDecisionRecorder(new CardBrand(), new MinorUnits());
    }

    public function testRecordsTheBrandAsAMagentoCardTypeCode(): void
    {
        $payment = $this->record(
            new Tier(2000000, ['amex'], 'Amex only.'),
            new PaymentMethodDetails('card', 'amex', null, '0005')
        );

        self::assertSame('AE', $payment->getCcType());
        self::assertSame('0005', $payment->getCcLast4());
    }

    public function testRecordsWhichTierApplied(): void
    {
        $payment = $this->record(
            new Tier(2000000, ['amex'], 'Amex only.'),
            new PaymentMethodDetails('card', 'amex', null, '0005')
        );

        self::assertSame('20000.00', $payment->getAdditionalInformation(TierDecisionRecorder::TIER_UPPER_BOUND));
        self::assertSame('amex', $payment->getAdditionalInformation(TierDecisionRecorder::TIER_ALLOWED_BRANDS));
        self::assertSame('amex', $payment->getAdditionalInformation(TierDecisionRecorder::ACCEPTED_BRAND));
        self::assertSame('card', $payment->getAdditionalInformation(TierDecisionRecorder::PAYMENT_METHOD_TYPE));
    }

    public function testRecordsTheWalletWhenOneWasUsed(): void
    {
        $payment = $this->record(
            new Tier(2000000, ['amex'], 'Amex only.'),
            new PaymentMethodDetails('card', 'amex', 'apple_pay', '0005')
        );

        self::assertSame('apple_pay', $payment->getAdditionalInformation(TierDecisionRecorder::WALLET));
    }

    public function testLeavesTheWalletKeyOutWhenThereWasNoWallet(): void
    {
        $payment = $this->record(
            new Tier(null, ['visa'], ''),
            new PaymentMethodDetails('card', 'visa', null, '4242')
        );

        self::assertArrayNotHasKey(TierDecisionRecorder::WALLET, $payment->getAdditionalInformation());
        self::assertSame('', $payment->getAdditionalInformation(TierDecisionRecorder::TIER_UPPER_BOUND));
    }

    /**
     * A brand Magento has no code for is recorded as "Other" rather than invented.
     */
    public function testFallsBackToOtherForABrandMagentoDoesNotKnow(): void
    {
        $payment = $this->record(
            new Tier(null, ['cartes_bancaires'], ''),
            new PaymentMethodDetails('card', 'cartes_bancaires', null, '1234')
        );

        self::assertSame('OT', $payment->getCcType());
    }

    /**
     * A real Payment, not a stub: the point of these tests is what it ends up holding.
     */
    private function record(Tier $tier, PaymentMethodDetails $details): Payment
    {
        /** @var Payment $payment */
        $payment = (new ObjectManager($this))->getObject(Payment::class);

        $order = $this->createStub(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);

        $this->recorder->record($order, $tier, $details);

        return $payment;
    }
}
