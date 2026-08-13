<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration2608130000CreateTdmpTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 2608130000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdmp_product` (
                `product_id`          binary(16) NOT NULL,
                `product_version_id`  binary(16) NOT NULL,
                `topdata_id`          bigint(20) NOT NULL,
                `created_at`          DATETIME(3) NOT NULL,
                `updated_at`          DATETIME(3) NOT NULL,
                PRIMARY KEY (`product_id`, `product_version_id`, `topdata_id`),
                KEY `idx_tdmp_product_topdata_id` (`topdata_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdmp_brand` (
                `brand_id`   binary(16) NOT NULL,
                `topdata_id` bigint(20) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`brand_id`, `topdata_id`),
                KEY `idx_tdmp_brand_topdata_id` (`topdata_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
