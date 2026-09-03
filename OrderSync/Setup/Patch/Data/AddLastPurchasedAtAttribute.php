<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Setup\CategorySetupFactory;
use Magento\Eav\Model\Entity\Attribute\Backend\Datetime;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddLastPurchasedAtAttribute implements DataPatchInterface
{
    public const string ATTRIBUTE_CODE = 'last_purchased_at';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CategorySetupFactory $categorySetupFactory
    ) {
    }

    public function apply(): self
    {
        $setup = $this->categorySetupFactory->create(['setup' => $this->moduleDataSetup]);

        $setup->addAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, [
            'type' => 'datetime',
            'backend' => Datetime::class,
            'label' => 'Last Purchased At',
            'input' => 'date',
            'required' => false,
            'sort_order' => 100,
            'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
            'user_defined' => false,
            'visible' => false,
            'visible_on_front' => false,
            'used_in_product_listing' => false,
            'is_html_allowed_on_front' => false,
            'searchable' => false,
            'filterable' => false,
            'filterable_in_search' => false,
            'comparable' => false,
            'used_for_sort_by' => false,
            'visible_in_advanced_search' => false,
            'is_used_in_grid' => false,
            'is_visible_in_grid' => false,
            'is_filterable_in_grid' => false,
        ]);

        return $this;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
