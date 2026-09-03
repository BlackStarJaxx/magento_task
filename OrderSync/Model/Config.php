<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const string XML_PATH_ENABLED = 'goodahead_ordersync/general/enabled';
    public const string XML_PATH_ENDPOINT_URL = 'goodahead_ordersync/endpoint/url';
    public const string XML_PATH_TIMEOUT = 'goodahead_ordersync/endpoint/timeout';
    public const string XML_PATH_MAX_ATTEMPTS = 'goodahead_ordersync/retry/max_attempts';
    public const string XML_PATH_BASE_DELAY = 'goodahead_ordersync/retry/base_delay';
    public const string XML_PATH_MAX_DELAY = 'goodahead_ordersync/retry/max_delay';
    public const string XML_PATH_RECONCILE_DAYS = 'goodahead_ordersync/retry/reconcile_window_days';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getEndpointUrl(?int $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_ENDPOINT_URL, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getTimeout(?int $storeId = null): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_TIMEOUT, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /** Attempts, not retries: 1 means deliver once and give up. */
    public function getMaxAttempts(?int $storeId = null): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_MAX_ATTEMPTS, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getBaseDelaySeconds(?int $storeId = null): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_BASE_DELAY, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * How far back the reconciliation sweep looks for paid orders that never reached the
     * ledger. Configurable because the right answer depends on the store: long enough to
     * cover any outage it might have, short enough that installing the module on a store
     * with history does not announce that history to finance.
     */
    public function getReconcileWindowDays(?int $storeId = null): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_RECONCILE_DAYS, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getMaxDelaySeconds(?int $storeId = null): int
    {
        return max(
            $this->getBaseDelaySeconds($storeId),
            (int)$this->scopeConfig->getValue(self::XML_PATH_MAX_DELAY, ScopeInterface::SCOPE_STORE, $storeId)
        );
    }
}
