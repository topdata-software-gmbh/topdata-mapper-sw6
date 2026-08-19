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
                `topdata_product_id`  bigint(20) NOT NULL,
                `created_at`          DATETIME(3) NOT NULL,
                `updated_at`          DATETIME(3) NOT NULL,
                PRIMARY KEY (`product_id`, `product_version_id`, `topdata_product_id`),
                KEY `idx_tdmp_product_topdata_product_id` (`topdata_product_id`),
                CONSTRAINT `fk_tdmp_product_product` FOREIGN KEY (`product_id`, `product_version_id`)
                    REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdmp_brand` (
                `product_manufacturer_id`          binary(16) NOT NULL,
                `product_manufacturer_version_id`  binary(16) NOT NULL,
                `topdata_brand_id`                 bigint(20) NOT NULL,
                `created_at`                       DATETIME(3) NOT NULL,
                `updated_at`                       DATETIME(3) NOT NULL,
                PRIMARY KEY (`product_manufacturer_id`, `product_manufacturer_version_id`, `topdata_brand_id`),
                KEY `idx_tdmp_brand_topdata_brand_id` (`topdata_brand_id`),
                CONSTRAINT `fk_tdmp_brand_product_manufacturer`
                    FOREIGN KEY (`product_manufacturer_id`, `product_manufacturer_version_id`)
                    REFERENCES `product_manufacturer` (`id`, `version_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
