<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Db;

use Doctrine\DBAL\Connection;

/**
 * Raw DBAL access to `tdmp_brand` (Topdata topdata_brand_id ↔ SW6 manufacturer id).
 *
 * The Mapper plugin is the single writer; TopFeed and TopFinder read only.
 *
 * `product_manufacturer_version_id` is always `LIVE_VERSION_HEX`: mappings only
 * ever refer to live manufacturers, and the constant version is what makes the
 * FK `fk_tdmp_brand_product_manufacturer` (product_manufacturer_id,
 * product_manufacturer_version_id) → product_manufacturer(id, version_id)
 * safe against Shopware's versioning (draft rows are deleted on version merge).
 *
 * 08/2026 created
 */
class TdmpBrandService
{
    /** Live (default) Shopware version id, see Shopware\Core\Defaults::LIVE_VERSION */
    public const string LIVE_VERSION_HEX = '0fa91ce3e96a4bc2be4bd9ce752c3425';

    private const INSERT_BATCH = 500;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<int, array{product_manufacturer_id: string, topdata_brand_id: int, created_at: string, updated_at: string}> $rows product_manufacturer_id is hex (no 0x prefix); product_manufacturer_version_id is always pinned to the live version
     */
    public function insertMany(array $rows): int
    {
        $inserted = 0;
        foreach (array_chunk($rows, self::INSERT_BATCH) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $values[] = sprintf(
                    "(0x%s, 0x%s, %d, '%s', '%s')",
                    $row['product_manufacturer_id'],
                    self::LIVE_VERSION_HEX,
                    $row['topdata_brand_id'],
                    $row['created_at'],
                    $row['updated_at']
                );
            }
            $inserted += $this->connection->executeStatement(
                'INSERT INTO tdmp_brand (product_manufacturer_id, product_manufacturer_version_id, topdata_brand_id, created_at, updated_at) VALUES ' . implode(',', $values)
            );
        }

        return $inserted;
    }

    public function deleteAll(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE tdmp_brand');
    }

    public function count(): int
    {
        return (int)$this->connection->fetchOne('SELECT COUNT(*) FROM tdmp_brand');
    }

    /**
     * Map topdata_brand_id (int) → SW6 product_manufacturer id (hex).
     *
     * @return array<int, string>
     */
    public function getBrandMap(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT topdata_brand_id, LOWER(HEX(product_manufacturer_id)) AS product_manufacturer_id FROM tdmp_brand'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['topdata_brand_id']] = $row['product_manufacturer_id'];
        }

        return $map;
    }
}
