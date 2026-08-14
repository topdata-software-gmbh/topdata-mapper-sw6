<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Db;

use Doctrine\DBAL\Connection;

/**
 * Raw DBAL access to `tdmp_brand` (Topdata topdata_brand_id ↔ SW6 manufacturer id).
 *
 * The Mapper plugin is the single writer; TopFeed and TopFinder read only.
 *
 * 08/2026 created
 */
class TdmpBrandService
{
    private const INSERT_BATCH = 500;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<int, array{brand_id: string, topdata_brand_id: int, created_at: string, updated_at: string}> $rows brand_id is hex (no 0x prefix)
     */
    public function insertMany(array $rows): int
    {
        $inserted = 0;
        foreach (array_chunk($rows, self::INSERT_BATCH) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $values[] = sprintf(
                    "(0x%s, %d, '%s', '%s')",
                    $row['brand_id'],
                    $row['topdata_brand_id'],
                    $row['created_at'],
                    $row['updated_at']
                );
            }
            $inserted += $this->connection->executeStatement(
                'INSERT INTO tdmp_brand (brand_id, topdata_brand_id, created_at, updated_at) VALUES ' . implode(',', $values)
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
     * Map topdata_brand_id (int) → SW6 manufacturer id (hex).
     *
     * @return array<int, string>
     */
    public function getBrandMap(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT topdata_brand_id, LOWER(HEX(brand_id)) AS brand_id FROM tdmp_brand'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['topdata_brand_id']] = $row['brand_id'];
        }

        return $map;
    }
}
