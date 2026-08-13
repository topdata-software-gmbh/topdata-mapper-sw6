<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Migrates the pre-1301 tdmp_product / tdmp_brand schema to the new one:
 *
 * - tdmp_product: `product_version_id` is pinned to the live version
 *   (0x0fa91ce3e96a4bc2be4bd9ce752c3425) and an FK
 *   (product_id, product_version_id) → product(id, version_id) ON DELETE CASCADE
 *   is added; `topdata_id` is renamed to `top_id`.
 * - tdmp_brand: `topdata_id` is renamed to `top_id`.
 *
 * The FK is composite because Shopware products are versioned: the live product
 * row is (id, LIVE_VERSION), draft rows carry random version ids. Pinning the
 * version makes the FK fire only when a product is really deleted, never when
 * draft rows are dropped during a version merge.
 *
 * Idempotent via information_schema checks; a no-op on fresh installs where
 * Migration2026081300 already created the new schema.
 *
 * 08/2026 created
 */
class Migration2026081301TdmpTopIdAndProductFk extends MigrationStep
{
    private const string LIVE_VERSION_HEX = '0fa91ce3e96a4bc2be4bd9ce752c3425';

    public function getCreationTimestamp(): int
    {
        return 2026081301;
    }

    public function update(Connection $connection): void
    {
        $this->_migrateTdmpProduct($connection);
        $this->_migrateTdmpBrand($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function _migrateTdmpProduct(Connection $connection): void
    {
        if (!$this->_columnExists($connection, 'tdmp_product', 'topdata_id')) {
            return; // already on the new schema
        }

        // Keep only mappings whose live product row exists and pin the version:
        // rows referencing draft versions could not satisfy the FK.
        $connection->executeStatement(
            'DELETE t FROM tdmp_product t LEFT JOIN product p
               ON p.id = t.product_id AND p.version_id = 0x' . self::LIVE_VERSION_HEX . '
              WHERE p.id IS NULL'
        );
        $connection->executeStatement(
            'UPDATE tdmp_product SET product_version_id = 0x' . self::LIVE_VERSION_HEX
        );

        // Drop rows duplicated by the version pin (same product + top_id, different version)
        $connection->executeStatement('
            DELETE t1 FROM tdmp_product t1 INNER JOIN tdmp_product t2
               ON t1.product_id = t2.product_id
              AND t1.product_version_id = t2.product_version_id
              AND t1.topdata_id = t2.topdata_id
             WHERE t1.created_at > t2.created_at
        ');

        $connection->executeStatement('ALTER TABLE tdmp_product DROP PRIMARY KEY');
        if ($this->_indexExists($connection, 'tdmp_product', 'idx_tdmp_product_topdata_id')) {
            $connection->executeStatement('ALTER TABLE tdmp_product DROP INDEX idx_tdmp_product_topdata_id');
        }
        $connection->executeStatement('ALTER TABLE tdmp_product CHANGE COLUMN `topdata_id` `top_id` bigint(20) NOT NULL');
        $connection->executeStatement('
            ALTER TABLE tdmp_product
                ADD PRIMARY KEY (`product_id`, `product_version_id`, `top_id`),
                ADD KEY `idx_tdmp_product_top_id` (`top_id`)
        ');

        if (!$this->_foreignKeyExists($connection, 'tdmp_product', 'fk_tdmp_product_product')) {
            $connection->executeStatement('
                ALTER TABLE tdmp_product
                    ADD CONSTRAINT `fk_tdmp_product_product`
                    FOREIGN KEY (`product_id`, `product_version_id`)
                    REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE
            ');
        }
    }

    private function _migrateTdmpBrand(Connection $connection): void
    {
        if (!$this->_columnExists($connection, 'tdmp_brand', 'topdata_id')) {
            return; // already on the new schema
        }

        $connection->executeStatement('ALTER TABLE tdmp_brand DROP PRIMARY KEY');
        if ($this->_indexExists($connection, 'tdmp_brand', 'idx_tdmp_brand_topdata_id')) {
            $connection->executeStatement('ALTER TABLE tdmp_brand DROP INDEX idx_tdmp_brand_topdata_id');
        }
        $connection->executeStatement('ALTER TABLE tdmp_brand CHANGE COLUMN `topdata_id` `top_id` bigint(20) NOT NULL');
        $connection->executeStatement('
            ALTER TABLE tdmp_brand
                ADD PRIMARY KEY (`brand_id`, `top_id`),
                ADD KEY `idx_tdmp_brand_top_id` (`top_id`)
        ');
    }

    private function _columnExists(Connection $connection, string $table, string $column): bool
    {
        return (bool)$connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    private function _indexExists(Connection $connection, string $table, string $index): bool
    {
        return (bool)$connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        );
    }

    private function _foreignKeyExists(Connection $connection, string $table, string $fk): bool
    {
        return (bool)$connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $fk]
        );
    }
}
