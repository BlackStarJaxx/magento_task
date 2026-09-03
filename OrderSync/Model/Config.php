<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ENABLED = 'goodahead_ordersync/general/enabled';
    public const XML_PATH_ENDPOINT_URL = 'goodahead_ordersync/endpoint/url';
    public const XML_PATH_TIMEOUT = 'goodahead_ordersync/endpoint/timeout';
    public const XML_PATH_MAX_ATTEMPTS = 'goodahead_ordersync/retry/max_attempts';
    public const XML_PATH_BASE_DELAY = 'goodahead_ordersync/retry/base_delay';
    public const XML_PATH_MAX_DELAY = 'goodahead_ordersync/retry/max_delay';

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

    public function getMaxDelaySeconds(?int $storeId = null): int
    {
        return max(
            $this->getBaseDelaySeconds($storeId),
            (int)$this->scopeConfig->getValue(self::XML_PATH_MAX_DELAY, ScopeInterface::SCOPE_STORE, $storeId)
        );
    }
}
