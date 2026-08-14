<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Repairs shops that ran the pre-v1.1 migration set: renames the legacy
 * `top_id` columns to the final `topdata_product_id` / `topdata_brand_id`.
 *
 * Background: migrations 1300/1301 originally created `top_id`. The DSL /
 * conflict refactor renamed the columns and edited those migration files in
 * place — so any shop that had already executed them (e.g. the dev shop) is
 * stuck with `top_id` while the code reads `topdata_product_id`. Migration
 * 1301's guard only checks for `topdata_id`, making it a no-op there.
 *
 * Idempotent via information_schema checks; a no-op on fresh installs where
 * migration 1300 already created the final schema.
 *
 * 08/2026 created
 */
class Migration2026081303TdmpTopIdRename extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 2026081303;
    }

    public function update(Connection $connection): void
    {
        $this->_renameTopIdColumn(
            $connection,
            'tdmp_product',
            'topdata_product_id',
            'idx_tdmp_product_top_id',
            'idx_tdmp_product_topdata_product_id'
        );
        $this->_renameTopIdColumn(
            $connection,
            'tdmp_brand',
            'topdata_brand_id',
            'idx_tdmp_brand_top_id',
            'idx_tdmp_brand_topdata_brand_id'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function _renameTopIdColumn(Connection $connection, string $table, string $newColumn, string $oldIndex, string $newIndex): void
    {
        if (!$this->_columnExists($connection, $table, 'top_id')) {
            return; // already on the final schema
        }

        $connection->executeStatement("ALTER TABLE `$table` CHANGE COLUMN `top_id` `$newColumn` bigint(20) NOT NULL");
        if ($this->_indexExists($connection, $table, $oldIndex)) {
            $connection->executeStatement("ALTER TABLE `$table` DROP INDEX `$oldIndex`");
        }
        $connection->executeStatement("ALTER TABLE `$table` ADD KEY `$newIndex` (`$newColumn`)");
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
}
