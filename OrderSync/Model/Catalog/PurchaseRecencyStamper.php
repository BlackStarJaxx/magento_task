<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Catalog;

use Goodahead\OrderSync\Setup\Patch\Data\AddLastPurchasedAtAttribute;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Psr\Log\LoggerInterface;

class PurchaseRecencyStamper
{
    private const CHUNK_SIZE = 500;
    private const VALUE_TABLE = 'catalog_product_entity_datetime';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly MetadataPool $metadataPool,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int[] $productIds
     * @return int rows written
     */
    public function stamp(array $productIds, string $purchasedAt): int
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if ($productIds === []) {
            return 0;
        }

        $attributeId = $this->getAttributeId();

        if ($attributeId === null) {
            $this->logger->error('Goodahead_OrderSync: last_purchased_at is missing; run setup:upgrade.');

            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $table = $this->resourceConnection->getTableName(self::VALUE_TABLE);
        $written = 0;

        foreach (array_chunk($this->resolveLinkValues($productIds, $linkField), self::CHUNK_SIZE) as $chunk) {
            $rows = [];

            foreach ($chunk as $linkValue) {
                $rows[] = [
                    'attribute_id' => $attributeId,
                    'store_id' => 0,
                    $linkField => $linkValue,
                    'value' => $purchasedAt,
                ];
            }

            $written += $connection->insertOnDuplicate($table, $rows, ['value']);
        }

        return $written;
    }

    private function getAttributeId(): ?int
    {
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, AddLastPurchasedAtAttribute::ATTRIBUTE_CODE);
            $id = (int)$attribute->getAttributeId();

            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveLinkValues(array $productIds, string $linkField): array
    {
        if ($linkField === 'entity_id') {
            return $productIds;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('catalog_product_entity'), [$linkField])
            ->where('entity_id IN (?)', $productIds);

        return array_map('intval', $connection->fetchCol($select));
    }
}
