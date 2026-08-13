<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Db;

use Doctrine\DBAL\Connection;

/**
 * Raw DBAL access to `tdmp_product` (Topdata products_id ↔ SW6 product id).
 *
 * The Mapper plugin is the single writer; TopFeed and TopFinder read only.
 *
 * 08/2026 created
 */
class TdmpProductService
{
    private const INSERT_BATCH = 500;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<int, array{product_id: string, product_version_id: string, topdata_id: int, created_at: string, updated_at: string}> $rows product_id/version_id are hex (no 0x prefix)
     */
    public function insertMany(array $rows): int
    {
        $inserted = 0;
        foreach (array_chunk($rows, self::INSERT_BATCH) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $values[] = sprintf(
                    "(0x%s, 0x%s, %d, '%s', '%s')",
                    $row['product_id'],
                    $row['product_version_id'],
                    $row['topdata_id'],
                    $row['created_at'],
                    $row['updated_at']
                );
            }
            $inserted += $this->connection->executeStatement(
                'INSERT INTO tdmp_product (product_id, product_version_id, topdata_id, created_at, updated_at) VALUES ' . implode(',', $values)
            );
        }

        return $inserted;
    }

    public function deleteAll(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE tdmp_product');
    }

    public function count(): int
    {
        return (int)$this->connection->fetchOne('SELECT COUNT(*) FROM tdmp_product');
    }

    /**
     * Map topdata_id (int) → list of SW6 product ids (hex).
     *
     * @return array<int, list<string>>
     */
    public function getProductMap(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT topdata_id, LOWER(HEX(product_id)) AS product_id FROM tdmp_product'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['topdata_id']][] = $row['product_id'];
        }

        return $map;
    }
}
