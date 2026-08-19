<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Renames tdmp_brand.brand_id → product_manufacturer_id and adds the
 * product_manufacturer_version_id column + composite FK, mirroring the
 * tdmp_product pattern (migration 1301):
 *
 * - product_manufacturer is versioned (PK (id, version_id)), so the FK must be
 *   composite; the version is pinned to the live version so the FK fires only
 *   when a manufacturer is really deleted, never when draft rows are dropped
 *   during a version merge.
 * - Rows referencing manufacturers that no longer exist (or exist only as
 *   drafts) are deleted before the FK is added — otherwise the constraint
 *   cannot be created.
 *
 * Migration 1300 now creates the final schema directly (fresh installs), so
 * this is a no-op there: the guard checks for the legacy `brand_id` column.
 *
 * 08/2026 created
 */
class Migration2026081804TdmpBrandManufacturerFk extends MigrationStep
{
    private const string LIVE_VERSION_HEX = '0fa91ce3e96a4bc2be4bd9ce752c3425';

    public function getCreationTimestamp(): int
    {
        return 2026081804;
    }

    public function update(Connection $connection): void
    {
        if (!$this->_columnExists($connection, 'tdmp_brand', 'brand_id')) {
            return; // already on the final schema
        }

        // Keep only rows whose live manufacturer row exists (FK requirement)
        $connection->executeStatement(
            'DELETE t FROM tdmp_brand t LEFT JOIN product_manufacturer pm
               ON pm.id = t.brand_id AND pm.version_id = 0x' . self::LIVE_VERSION_HEX . '
              WHERE pm.id IS NULL'
        );

        // Rename the id column in place (PK follows automatically)
        $connection->executeStatement('ALTER TABLE tdmp_brand CHANGE COLUMN `brand_id` `product_manufacturer_id` binary(16) NOT NULL');

        // Add the version column nullable, backfill with the live version, pin NOT NULL
        $connection->executeStatement('ALTER TABLE tdmp_brand ADD COLUMN `product_manufacturer_version_id` binary(16) NULL AFTER `product_manufacturer_id`');
        $connection->executeStatement('UPDATE tdmp_brand SET product_manufacturer_version_id = 0x' . self::LIVE_VERSION_HEX);
        $connection->executeStatement('ALTER TABLE tdmp_brand MODIFY COLUMN `product_manufacturer_version_id` binary(16) NOT NULL');

        // Drop rows duplicated by the version pin (same manufacturer + topdata brand id)
        $connection->executeStatement('
            DELETE t1 FROM tdmp_brand t1 INNER JOIN tdmp_brand t2
               ON t1.product_manufacturer_id = t2.product_manufacturer_id
              AND t1.product_manufacturer_version_id = t2.product_manufacturer_version_id
              AND t1.topdata_brand_id = t2.topdata_brand_id
             WHERE t1.created_at > t2.created_at
        ');

        // Rebuild PK with the version (mirrors tdmp_product) and add the FK
        $connection->executeStatement('ALTER TABLE tdmp_brand DROP PRIMARY KEY');
        $connection->executeStatement('
            ALTER TABLE tdmp_brand
                ADD PRIMARY KEY (`product_manufacturer_id`, `product_manufacturer_version_id`, `topdata_brand_id`)
        ');
        if (!$this->_foreignKeyExists($connection, 'tdmp_brand', 'fk_tdmp_brand_product_manufacturer')) {
            $connection->executeStatement('
                ALTER TABLE tdmp_brand
                    ADD CONSTRAINT `fk_tdmp_brand_product_manufacturer`
                    FOREIGN KEY (`product_manufacturer_id`, `product_manufacturer_version_id`)
                    REFERENCES `product_manufacturer` (`id`, `version_id`) ON DELETE CASCADE
            ');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function _columnExists(Connection $connection, string $table, string $column): bool
    {
        return (bool)$connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
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