<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the configured tiers for a website scope and hands back value objects.
 *
 * No threshold is hardcoded here (AC-7); the defaults live in etc/config.xml and an
 * administrator edits them at website scope.
 */
class TierProvider
{
    public const XML_PATH_ENABLED = 'goodahead_payment_tiers/general/enabled';
    public const XML_PATH_CURRENCY_MODE = 'goodahead_payment_tiers/general/currency_mode';
    public const XML_PATH_ROWS = 'goodahead_payment_tiers/tiers/rows';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $serializer,
        private readonly CardBrand $cardBrand,
        private readonly MinorUnits $minorUnits,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(?int $websiteId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
    }

    public function getCurrencyMode(?int $websiteId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_CURRENCY_MODE,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
    }

    /**
     * Tiers ordered by upper bound, with the unbounded tier last.
     *
     * @return Tier[]
     */
    public function getTiers(?int $websiteId = null): array
    {
        $raw = (string)$this->scopeConfig->getValue(
            self::XML_PATH_ROWS,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );

        try {
            $rows = $raw === '' ? [] : $this->serializer->unserialize($raw);
            $tiers = $this->buildTiers(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            $this->logger->critical(
                'Goodahead_PaymentTiers: tier configuration could not be read, falling back to '
                . 'the most restrictive tier. ' . $e->getMessage()
            );

            return [$this->fallbackTier()];
        }

        return $tiers === [] ? [$this->fallbackTier()] : $tiers;
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return Tier[]
     */
    private function buildTiers(array $rows): array
    {
        $tiers = [];

        foreach ($rows as $row) {
            $bound = trim((string)($row['upper_bound'] ?? ''));

            $tiers[] = new Tier(
                $bound === '' ? null : $this->minorUnits->fromAmount($bound),
                $this->parseBrands((string)($row['brands'] ?? '')),
                trim((string)($row['message'] ?? '')),
                $this->parseList((string)($row['methods'] ?? ''))
            );
        }

        usort($tiers, static function (Tier $a, Tier $b): int {
            if ($a->isUnbounded() || $b->isUnbounded()) {
                return $a->isUnbounded() <=> $b->isUnbounded();
            }

            return $a->getUpperBoundMinorUnits() <=> $b->getUpperBoundMinorUnits();
        });

        return $tiers;
    }

    /** @return string[] */
    private function parseList(string $csv): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)))));
    }

    /** @return string[] */
    private function parseBrands(string $csv): array
    {
        $brands = [];

        foreach (explode(',', $csv) as $candidate) {
            if (trim($candidate) === '') {
                continue;
            }

            $brand = $this->cardBrand->normalise($candidate);

            if (!in_array($brand, $brands, true)) {
                $brands[] = $brand;
            }
        }

        return $brands;
    }

    /**
     * Fail closed, not open.
     *
     * The module exists to cap financial exposure. If its configuration is unreadable we
     * cannot know which tier applies, so we refuse cards outright rather than silently
     * lifting the restriction. An administrator who wants cards back turns the module off
     * deliberately via the enabled flag; a corrupted row is never treated as consent.
     */
    private function fallbackTier(): Tier
    {
        return new Tier(
            null,
            [],
            (string)__('Card payments are temporarily unavailable. Please use Check / Money Order or Bank Transfer.')
        );
    }
}
