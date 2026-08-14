<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Creates `tdmp_product_conflict_resolutions`: per-product resolutions for the
 * case where one Shopware product matches more than one Topdata article.
 *
 * - `chosen_topdata_product_id` — the currently chosen Topdata article
 * - `topdata_product_ids` — JSON candidate list, including per-candidate
 *   preview data (pcd/ean/mpn) captured at conflict-detection time; the
 *   settings-page radio list renders from this, no API lookups
 * - `status` — 'auto' (pending queue) | 'user' (explicitly resolved)
 *
 * `product_version_id` is pinned to the live version (same pattern as
 * tdmp_product) so the composite FK only fires on real product deletions.
 *
 * 08/2026 created
 */
class Migration2026081302CreateConflictResolutionsTable extends MigrationStep
{
    private const string LIVE_VERSION_HEX = '0fa91ce3e96a4bc2be4bd9ce752c3425';

    public function getCreationTimestamp(): int
    {
        return 2026081302;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement("
            CREATE TABLE IF NOT EXISTS `tdmp_product_conflict_resolutions` (
                `product_id`                 binary(16) NOT NULL,
                `product_version_id`         binary(16) NOT NULL,
                `chosen_topdata_product_id`  bigint(20) NOT NULL,
                `topdata_product_ids`        json       NOT NULL,
                `status`                     varchar(16) NOT NULL DEFAULT 'auto',
                `created_at`                 DATETIME(3) NOT NULL,
                `updated_at`                 DATETIME(3) NOT NULL,
                PRIMARY KEY (`product_id`, `product_version_id`),
                CONSTRAINT `fk_tdmp_conflict_resolution_product` FOREIGN KEY (`product_id`, `product_version_id`)
                    REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}