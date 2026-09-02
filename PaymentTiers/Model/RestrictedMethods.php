<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Which payment methods a tier is allowed to take away.
 *
 * Configurable rather than hardcoded, for two reasons. Stripe registers six method codes,
 * not one, and the set has changed between module versions; and a merchant running a second
 * card gateway would need it restricted too, which no amount of Stripe-specific code could
 * anticipate.
 *
 * The offline invariant is applied here rather than trusted to configuration: even if an
 * administrator selects Check / Money Order in this field, it is filtered back out. AC-8 is
 * not negotiable through the admin panel.
 */
class RestrictedMethods
{
    public const XML_PATH_RESTRICTED = 'goodahead_payment_tiers/methods/restricted';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly OfflineMethods $offlineMethods
    ) {
    }

    public function isRestrictable(string $methodCode, ?int $websiteId = null): bool
    {
        if ($this->offlineMethods->isAlwaysAvailable($methodCode)) {
            return false;
        }

        return in_array($methodCode, $this->getRestrictableCodes($websiteId), true);
    }

    /** @return string[] */
    public function getRestrictableCodes(?int $websiteId = null): array
    {
        $configured = (string)$this->scopeConfig->getValue(
            self::XML_PATH_RESTRICTED,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );

        $codes = array_filter(array_map('trim', explode(',', $configured)));

        return array_values(array_filter(
            $codes,
            fn (string $code): bool => !$this->offlineMethods->isAlwaysAvailable($code)
        ));
    }
}
