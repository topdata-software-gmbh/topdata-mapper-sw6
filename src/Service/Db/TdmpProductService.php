<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Db;

use Doctrine\DBAL\Connection;

/**
 * Raw DBAL access to `tdmp_product` (Topdata products_id ↔ SW6 product id).
 *
 * The Mapper plugin is the single writer; TopFeed and TopFinder read only.
 *
 * `product_version_id` is always `LIVE_VERSION_HEX`: mappings only ever refer
 * to live products, and the constant version is what makes the FK
 * `fk_tdmp_product_product` (product_id, product_version_id) → product(id, version_id)
 * safe against Shopware's versioning (draft rows are deleted on version merge).
 *
 * 08/2026 created
 */
class TdmpProductService
{
    /** Live (default) Shopware version id, see Shopware\Core\Defaults::LIVE_VERSION */
    public const string LIVE_VERSION_HEX = '0fa91ce3e96a4bc2be4bd9ce752c3425';

    private const INSERT_BATCH = 500;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<int, array{product_id: string, top_id: int, created_at: string, updated_at: string}> $rows product_id is hex (no 0x prefix)
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
                    self::LIVE_VERSION_HEX,
                    $row['top_id'],
                    $row['created_at'],
                    $row['updated_at']
                );
            }
            $inserted += $this->connection->executeStatement(
                'INSERT INTO tdmp_product (product_id, product_version_id, top_id, created_at, updated_at) VALUES ' . implode(',', $values)
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
     * Map top_id (int) → list of SW6 product ids (hex).
     *
     * @return array<int, list<string>>
     */
    public function getProductMap(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT top_id, LOWER(HEX(product_id)) AS product_id FROM tdmp_product'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['top_id']][] = $row['product_id'];
        }

        return $map;
    }
}
