<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Test\Unit\Model;

use Goodahead\PaymentTiers\Model\OfflineMethods;
use Goodahead\PaymentTiers\Model\RestrictedMethods;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class RestrictedMethodsTest extends TestCase
{
    private function build(string $configured): RestrictedMethods
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($configured);

        return new RestrictedMethods($scopeConfig, new OfflineMethods());
    }

    public function testRestrictsAConfiguredCardMethod(): void
    {
        self::assertTrue($this->build('stripe_payments,stripe_payments_express')->isRestrictable('stripe_payments'));
    }

    public function testLeavesMethodsThatWereNotConfigured(): void
    {
        self::assertFalse($this->build('stripe_payments')->isRestrictable('braintree'));
    }

    /**
     * AC-8 is an invariant, not a setting. Selecting an offline method in the admin field
     * must not make it restrictable.
     */
    public function testOfflineMethodsCannotBeRestrictedEvenWhenAnAdministratorSelectsThem(): void
    {
        $methods = $this->build('stripe_payments,checkmo,banktransfer');

        self::assertFalse($methods->isRestrictable('checkmo'));
        self::assertFalse($methods->isRestrictable('banktransfer'));
        self::assertSame(['stripe_payments'], $methods->getRestrictableCodes());
    }

    public function testHandlesEmptyConfiguration(): void
    {
        self::assertSame([], $this->build('')->getRestrictableCodes());
        self::assertFalse($this->build('')->isRestrictable('stripe_payments'));
    }
}
