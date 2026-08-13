<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration2026081300CreateTdmpTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 2026081300;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdmp_product` (
                `product_id`          binary(16) NOT NULL,
                `product_version_id`  binary(16) NOT NULL,
                `top_id`              bigint(20) NOT NULL,
                `created_at`          DATETIME(3) NOT NULL,
                `updated_at`          DATETIME(3) NOT NULL,
                PRIMARY KEY (`product_id`, `product_version_id`, `top_id`),
                KEY `idx_tdmp_product_top_id` (`top_id`),
                CONSTRAINT `fk_tdmp_product_product` FOREIGN KEY (`product_id`, `product_version_id`)
                    REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdmp_brand` (
                `brand_id`   binary(16) NOT NULL,
                `top_id`     bigint(20) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`brand_id`, `top_id`),
                KEY `idx_tdmp_brand_top_id` (`top_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
