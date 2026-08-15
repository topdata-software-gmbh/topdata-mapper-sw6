<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Db;

use Doctrine\DBAL\Connection;

/**
 * Read-only DBAL access for the admin mappings browser
 * (tdmp_product / tdmp_brand grids on the "Topdata Mapper" navigation group).
 *
 * Both grids are server-side paginated + searchable — the mapping tables can
 * hold tens of thousands of rows, so no client-side loading ever happens.
 *
 * 08/2026 created
 */
class TdmpMappingBrowseService
{
    private const MAX_LIMIT = 500;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Paginated product mappings (tdmp_product) enriched with SW6 product
     * number/name/thumbnail. Search matches product number, product name or
     * the Topdata article id.
     *
     * @return array{rows: list<array{productId: string, productNumber: string, productName: string, thumbnailUrl: ?string, topdataProductId: int, createdAt: string, updatedAt: string}>, total: int}
     */
    public function listProductMappings(int $page, int $limit, ?string $search): array
    {
        $where   = ['p.version_id = 0x' . TdmpProductService::LIVE_VERSION_HEX];
        $params  = [];

        if ($search !== null && $search !== '') {
            $where[]  = '(p.product_number LIKE ? OR pt.name LIKE ? OR mp.topdata_product_id = ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = (int)$search === 0 ? 0 : (int)$search; // numeric search matches the article id
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(*)
               FROM tdmp_product mp
               JOIN product p ON p.id = mp.product_id AND p.version_id = mp.product_version_id
               LEFT JOIN (
                   SELECT product_id, product_version_id, MAX(name) AS name
                     FROM product_translation
                    GROUP BY product_id, product_version_id
               ) pt
                 ON pt.product_id = mp.product_id AND pt.product_version_id = mp.product_version_id
              WHERE {$whereSql}",
            $params
        );

        [$sql, $sqlParams] = $this->_buildPageSql(
            "SELECT LOWER(HEX(mp.product_id)) AS product_id,
                    p.product_number AS product_number,
                    pt.name AS product_name,
                    mt.path AS thumbnail_path,
                    mp.topdata_product_id,
                    mp.created_at,
                    mp.updated_at
               FROM tdmp_product mp
               JOIN product p ON p.id = mp.product_id AND p.version_id = mp.product_version_id
               LEFT JOIN (
                   SELECT product_id, product_version_id, MAX(name) AS name
                     FROM product_translation
                    GROUP BY product_id, product_version_id
               ) pt
                 ON pt.product_id = mp.product_id AND pt.product_version_id = mp.product_version_id
               LEFT JOIN product_media pm
                 ON pm.id = p.product_media_id AND pm.version_id = p.product_media_version_id
               LEFT JOIN (
                   SELECT media_id, MIN(path) AS path
                     FROM media_thumbnail
                    GROUP BY media_id
               ) mt
                 ON mt.media_id = pm.media_id
              WHERE {$whereSql}
              ORDER BY p.product_number ASC",
            $params,
            $page,
            $limit
        );

        $rows = $this->connection->fetchAllAssociative($sql, $sqlParams);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'productId'        => $row['product_id'],
                'productNumber'    => (string)$row['product_number'],
                'productName'      => (string)($row['product_name'] ?? ''),
                'thumbnailUrl'     => $row['thumbnail_path'] !== null ? '/media/' . $row['thumbnail_path'] : null,
                'topdataProductId' => (int)$row['topdata_product_id'],
                'createdAt'        => (string)$row['created_at'],
                'updatedAt'        => (string)$row['updated_at'],
            ];
        }

        return ['rows' => $result, 'total' => $total];
    }

    /**
     * Paginated brand mappings (tdmp_brand) enriched with the SW6 manufacturer
     * name. Search matches the manufacturer name or the Topdata brand id.
     *
     * @return array{rows: list<array{brandId: string, manufacturerName: string, topdataBrandId: int, createdAt: string, updatedAt: string}>, total: int}
     */
    public function listBrandMappings(int $page, int $limit, ?string $search): array
    {
        $where   = [];
        $params  = [];

        if ($search !== null && $search !== '') {
            $where[]  = '(pmt.name LIKE ? OR mb.topdata_brand_id = ?)';
            $params[] = '%' . $search . '%';
            $params[] = (int)$search === 0 ? 0 : (int)$search;
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(*)
               FROM tdmp_brand mb
               JOIN product_manufacturer pm ON pm.id = mb.brand_id
               LEFT JOIN (
                   SELECT product_manufacturer_id, MAX(name) AS name
                     FROM product_manufacturer_translation
                    GROUP BY product_manufacturer_id
               ) pmt
                 ON pmt.product_manufacturer_id = mb.brand_id
              {$whereSql}",
            $params
        );

        [$sql, $sqlParams] = $this->_buildPageSql(
            "SELECT LOWER(HEX(mb.brand_id)) AS brand_id,
                    pmt.name AS manufacturer_name,
                    mb.topdata_brand_id,
                    mb.created_at,
                    mb.updated_at
               FROM tdmp_brand mb
               JOIN product_manufacturer pm ON pm.id = mb.brand_id
               LEFT JOIN (
                   SELECT product_manufacturer_id, MAX(name) AS name
                     FROM product_manufacturer_translation
                    GROUP BY product_manufacturer_id
               ) pmt
                 ON pmt.product_manufacturer_id = mb.brand_id
              {$whereSql}
              ORDER BY manufacturer_name ASC",
            $params,
            $page,
            $limit
        );

        $rows = $this->connection->fetchAllAssociative($sql, $sqlParams);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'brandId'          => $row['brand_id'],
                'manufacturerName' => (string)($row['manufacturer_name'] ?? ''),
                'topdataBrandId'   => (int)$row['topdata_brand_id'],
                'createdAt'        => (string)$row['created_at'],
                'updatedAt'        => (string)$row['updated_at'],
            ];
        }

        return ['rows' => $result, 'total' => $total];
    }

    /**
     * Applies page/limit to a SELECT and returns (sql, params) — the paging
     * contract shared by both grids.
     *
     * @param list<mixed> $params
     * @return array{0: string, 1: list<mixed>}
     */
    private function _buildPageSql(string $sql, array $params, int $page, int $limit): array
    {
        $limit  = min(max(1, $limit), self::MAX_LIMIT);
        $offset = max(0, ($page - 1) * $limit);

        return [$sql . " LIMIT {$limit} OFFSET {$offset}", $params];
    }
}