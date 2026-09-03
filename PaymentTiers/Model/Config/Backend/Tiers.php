<?php

declare(strict_types=1);

namespace Goodahead\PaymentTiers\Model\Config\Backend;

use Goodahead\PaymentTiers\Model\CardBrand;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Validates the tier table, then stores it as JSON.
 *
 * Validation is where the invariants live. A tier table that cannot express a total, or that
 * restricts brands without telling the customer why, is rejected at save time rather than
 * discovered at checkout.
 */
class Tiers extends Value
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly Json $serializer,
        private readonly CardBrand $cardBrand,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave(): self
    {
        /**
         * Magento's docblock narrows this to string, but a field array posts an array of
         * rows keyed by row id. Anything that is already a string has been serialized
         * before (a config import, for instance) and is passed through untouched.
         *
         * @var mixed $value
         */
        $value = $this->getValue();

        if (is_array($value)) {
            $rows = [];

            foreach ($value as $key => $row) {
                if ($key === '__empty' || !is_array($row)) {
                    continue;
                }

                $rows[] = [
                    'upper_bound' => trim((string)($row['upper_bound'] ?? '')),
                    'brands' => trim((string)($row['brands'] ?? '')),
                    'message' => trim((string)($row['message'] ?? '')),
                ];
            }

            $this->validate($rows);
            $this->setValue((string)$this->serializer->serialize($rows));
        }

        return parent::beforeSave();
    }

    /**
     * @return $this
     */
    protected function _afterLoad()
    {
        /** @var mixed $value */
        $value = $this->getValue();

        if (is_string($value) && $value !== '') {
            $decoded = $this->serializer->unserialize($value);
            $this->setData('value', is_array($decoded) ? $decoded : []);
        }

        return parent::_afterLoad();
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @throws LocalizedException
     */
    private function validate(array $rows): void
    {
        if ($rows === []) {
            throw new LocalizedException(
                __('Configure at least one payment tier, or disable the module.')
            );
        }

        $unbounded = 0;
        $bounds = [];

        foreach ($rows as $index => $row) {
            $position = $index + 1;
            $bound = $row['upper_bound'];

            if ($bound === '') {
                $unbounded++;
            } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $bound)) {
                throw new LocalizedException(
                    __('Tier %1: "%2" is not a valid amount. Use digits and at most two decimals, for example 10000.00.', $position, $bound)
                );
            } else {
                if (in_array($bound, $bounds, true)) {
                    throw new LocalizedException(__('Tier %1: the upper bound %2 is used twice.', $position, $bound));
                }
                $bounds[] = $bound;
            }

            foreach (array_filter(array_map('trim', explode(',', $row['brands']))) as $brand) {
                if (!$this->cardBrand->isKnown($brand)) {
                    throw new LocalizedException(
                        __('Tier %1: "%2" is not a known card brand. Allowed: %3.', $position, $brand, implode(', ', $this->cardBrand->all()))
                    );
                }
            }

            $restricted = count(array_filter(array_map('trim', explode(',', $row['brands']))))
                < count($this->cardBrand->all());

            if ($restricted && $row['message'] === '') {
                throw new LocalizedException(
                    __('Tier %1 restricts card brands, so it needs a customer message explaining why.', $position)
                );
            }
        }

        if ($unbounded !== 1) {
            throw new LocalizedException(
                __('Exactly one tier must have an empty upper bound, so that every order total falls into a tier. Found %1.', $unbounded)
            );
        }
    }
}
