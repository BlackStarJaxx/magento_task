<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Catalog;

use Goodahead\OrderSync\Setup\Patch\Data\AddLastPurchasedAtAttribute;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Store\Model\Store;

class PurchaseRecencyStamper
{
    private const CHUNK_SIZE = 500;

    public function __construct(private readonly ProductAction $productAction)
    {
    }

    /**
     * @param int[] $productIds
     * @return int products stamped
     */
    public function stamp(array $productIds, string $purchasedAt): int
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if ($productIds === []) {
            return 0;
        }

        foreach (array_chunk($productIds, self::CHUNK_SIZE) as $chunk) {
            $this->productAction->updateAttributes(
                $chunk,
                [AddLastPurchasedAtAttribute::ATTRIBUTE_CODE => $purchasedAt],
                Store::DEFAULT_STORE_ID
            );
        }

        return count($productIds);
    }
}
