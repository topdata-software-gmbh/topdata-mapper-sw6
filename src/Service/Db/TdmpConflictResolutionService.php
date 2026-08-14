<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Db;

use Doctrine\DBAL\Connection;

/**
 * Raw DBAL access to `tdmp_product_conflict_resolutions` (one row per
 * conflicted Shopware product: chosen Topdata article + candidate list with
 * preview data). The table strictly mirrors live conflicts — rows for products
 * that no longer match >1 article are deleted.
 *
 * `product_version_id` is always `LIVE_VERSION_HEX` (same pattern as
 * tdmp_product); the row contract carries only `product_id` (hex, no 0x).
 *
 * 08/2026 created
 */
class TdmpConflictResolutionService
{
    public const string STATUS_AUTO = 'auto';
    public const string STATUS_USER = 'user';

    private const INSERT_BATCH = 500;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, array{chosen: int, status: string, candidates: list<array{id: int, pcd: list<string>, ean: list<string>, mpn: list<string>}>}> keyed by product_id hex
     */
    public function loadResolutions(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(product_id)) AS product_id, chosen_topdata_product_id, topdata_product_ids, status
               FROM tdmp_product_conflict_resolutions'
        );

        $resolutions = [];
        foreach ($rows as $row) {
            $resolutions[$row['product_id']] = [
                'chosen'    => (int)$row['chosen_topdata_product_id'],
                'status'    => (string)$row['status'],
                'candidates'=> json_decode((string)$row['topdata_product_ids'], true) ?? [],
            ];
        }

        return $resolutions;
    }

    /**
     * Syncs the resolutions table after a product build (full-table replace).
     *
     * - products still conflicted keep / recompute their resolution
     * - 'user' resolutions whose chosen id left the candidate set are demoted
     *   to 'auto' (min candidate) — the settings-page queue re-flags them
     * - rows for products that are no longer conflicted are deleted
     *
     * @param array<string, list<int>> $conflicts product_id hex → candidate topdata ids (each list has ≥2 entries)
     * @param array<string, array<int, array{pcd: list<string>, ean: list<string>, mpn: list<string}>> $previews product_id hex → candidate id → preview data captured at detection time
     * @return array{stats: array{auto: int, user: int, demoted: int, removed: int}, chosen: array<string, int>} chosen = product_id hex → final chosen topdata id (user-kept or recomputed auto)
     */
    public function syncFromBuild(array $conflicts, array $previews): array
    {
        $existing = $this->loadResolutions();
        $now      = date('Y-m-d H:i:s');

        $stats   = ['auto' => 0, 'user' => 0, 'demoted' => 0, 'removed' => 0];
        $chosen  = [];

        $upserts = [];
        foreach ($conflicts as $productId => $candidateIds) {
            $candidateIds = array_values(array_unique($candidateIds));
            sort($candidateIds);
            $candidatesJson = json_encode($this->_buildCandidatesPayload($candidateIds, $previews[$productId] ?? []), JSON_UNESCAPED_UNICODE);

            $previous = $existing[$productId] ?? null;
            $status   = self::STATUS_AUTO;
            $chosenId = min($candidateIds);

            if ($previous !== null) {
                if ($previous['status'] === self::STATUS_USER && in_array($previous['chosen'], $candidateIds, true)) {
                    // ---- keep the user's resolution, refresh the candidate previews
                    $status   = self::STATUS_USER;
                    $chosenId = $previous['chosen'];
                    $stats['user']++;
                } elseif ($previous['status'] === self::STATUS_USER) {
                    // ---- demotion: chosen left the candidate set
                    $stats['demoted']++;
                } elseif ($previous['status'] === self::STATUS_AUTO) {
                    // ---- always recompute auto resolutions
                    $stats['auto']++;
                }
            } else {
                $stats['auto']++;
            }
            $chosen[$productId] = $chosenId;

            $upserts[] = sprintf(
                "(0x%s, 0x%s, %d, %s, '%s', '%s', '%s')",
                $productId,
                TdmpProductService::LIVE_VERSION_HEX,
                $chosen,
                $this->connection->quote($candidatesJson),
                $status,
                $now,
                $now
            );
        }

        $this->_flushUpserts($upserts);

        // ---- delete rows for products that are no longer conflicted
        $productIds = array_keys($conflicts);
        $deleted = 0;
        if (empty($productIds)) {
            $deleted += (int)$this->connection->executeStatement('DELETE FROM tdmp_product_conflict_resolutions');
        } else {
            $deleted += (int)$this->connection->executeStatement(
                'DELETE FROM tdmp_product_conflict_resolutions
                  WHERE product_version_id = 0x' . TdmpProductService::LIVE_VERSION_HEX . '
                    AND LOWER(HEX(product_id)) NOT IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')',
                $productIds
            );
        }
        $stats['removed'] = $deleted;

        return ['stats' => $stats, 'chosen' => $chosen];
    }

    /**
     * Applies a user's resolution immediately (no re-import needed): marks the
     * resolution row as 'user' and points the product's tdmp_product row at
     * the chosen article.
     */
    public function applyUserResolution(string $productId, int $chosenTopdataProductId): void
    {
        $now = date('Y-m-d H:i:s');

        $this->connection->executeStatement(
            sprintf(
                "UPDATE tdmp_product_conflict_resolutions
                    SET chosen_topdata_product_id = %d,
                        status = '%s',
                        updated_at = %s
                  WHERE product_id = 0x%s
                    AND product_version_id = 0x%s",
                $chosenTopdataProductId,
                self::STATUS_USER,
                $this->connection->quote($now),
                $productId,
                TdmpProductService::LIVE_VERSION_HEX
            )
        );

        $this->connection->executeStatement(
            sprintf(
                "DELETE FROM tdmp_product
                  WHERE product_id = 0x%s
                    AND product_version_id = 0x%s",
                $productId,
                TdmpProductService::LIVE_VERSION_HEX
            )
        );
        $this->connection->executeStatement(
            sprintf(
                "INSERT INTO tdmp_product (product_id, product_version_id, topdata_product_id, created_at, updated_at)
                 VALUES (0x%s, 0x%s, %d, %s, %s)",
                $productId,
                TdmpProductService::LIVE_VERSION_HEX,
                $chosenTopdataProductId,
                $this->connection->quote($now),
                $this->connection->quote($now)
            )
        );
    }

    /**
     * Lists conflicts (enriched with product number/name/thumbnail), server-side
     * paginated + filtered for the settings-page grid.
     *
     * @return array{rows: list<array{productId: string, productNumber: string, productName: string, thumbnail: ?string, chosenTopdataProductId: int, candidates: list<array{id: int, pcd: list<string>, ean: list<string>, mpn: list<string>}>, status: string, updatedAt: string}>, total: int}
     */
    public function listConflicts(int $page, int $limit, ?string $status, ?string $search): array
    {
        $where    = ['cr.product_version_id = 0x' . TdmpProductService::LIVE_VERSION_HEX];
        $params   = [];
        $orderBy  = 'cr.updated_at DESC';

        if ($status !== null && in_array($status, [self::STATUS_AUTO, self::STATUS_USER], true)) {
            $where[]  = 'cr.status = ?';
            $params[] = $status;
        }
        if ($search !== null && $search !== '') {
            $where[]  = '(p.product_number LIKE ? OR pt.name LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(*)
               FROM tdmp_product_conflict_resolutions cr
               JOIN product p ON p.id = cr.product_id AND p.version_id = cr.product_version_id
               LEFT JOIN product_translation pt
                 ON pt.product_id = cr.product_id AND pt.product_version_id = cr.product_version_id
              WHERE {$whereSql}",
            $params
        );

        $offset = max(0, ($page - 1) * $limit);
        $rows   = $this->connection->fetchAllAssociative(
            "SELECT LOWER(HEX(cr.product_id)) AS product_id,
                    p.product_number AS product_number,
                    pt.name AS product_name,
                    mt.url AS thumbnail,
                    cr.chosen_topdata_product_id,
                    cr.topdata_product_ids,
                    cr.status,
                    cr.updated_at
               FROM tdmp_product_conflict_resolutions cr
               JOIN product p ON p.id = cr.product_id AND p.version_id = cr.product_version_id
               LEFT JOIN product_translation pt
                 ON pt.product_id = cr.product_id AND pt.product_version_id = cr.product_version_id
               LEFT JOIN media_thumbnail mt
                 ON mt.media_id = p.cover_id
              WHERE {$whereSql}
              ORDER BY {$orderBy}
              LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'productId'              => $row['product_id'],
                'productNumber'          => (string)$row['product_number'],
                'productName'            => (string)($row['product_name'] ?? ''),
                'thumbnailUrl'           => $row['thumbnail'] !== null ? (string)$row['thumbnail'] : null,
                'chosenTopdataProductId' => (int)$row['chosen_topdata_product_id'],
                'candidates'             => json_decode((string)$row['topdata_product_ids'], true) ?? [],
                'status'                 => (string)$row['status'],
                'updatedAt'              => (string)$row['updated_at'],
            ];
        }

        return ['rows' => $result, 'total' => $total];
    }

    /**
     * Summary counts + last import time for the conflicts page banner.
     *
     * @return array{pending: int, resolved: int, total: int, lastImportAt: ?string}
     */
    public function getStats(): array
    {
        $pending = (int)$this->connection->fetchOne(
            "SELECT COUNT(*) FROM tdmp_product_conflict_resolutions WHERE status = ?",
            [self::STATUS_AUTO]
        );
        $resolved = (int)$this->connection->fetchOne(
            "SELECT COUNT(*) FROM tdmp_product_conflict_resolutions WHERE status = ?",
            [self::STATUS_USER]
        );
        $lastImportAt = $this->connection->fetchOne(
            'SELECT MAX(updated_at) FROM tdmp_product'
        );

        return [
            'pending'      => $pending,
            'resolved'     => $resolved,
            'total'        => $pending + $resolved,
            'lastImportAt' => $lastImportAt !== false && $lastImportAt !== null ? (string)$lastImportAt : null,
        ];
    }

    /**
     * @param list<int> $candidateIds
     * @param array<int, array{pcd: list<string>, ean: list<string>, mpn: list<string>}> $previews
     * @return list<array{id: int, pcd: list<string>, ean: list<string>, mpn: list<string>}>
     */
    private function _buildCandidatesPayload(array $candidateIds, array $previews): array
    {
        $payload = [];
        foreach ($candidateIds as $candidateId) {
            $preview = $previews[$candidateId] ?? ['pcd' => [], 'ean' => [], 'mpn' => []];
            $payload[] = [
                'id'  => $candidateId,
                'pcd' => array_values($preview['pcd'] ?? []),
                'ean' => array_values($preview['ean'] ?? []),
                'mpn' => array_values($preview['mpn'] ?? []),
            ];
        }

        return $payload;
    }

    /**
     * @param list<string> $upserts
     */
    private function _flushUpserts(array $upserts): void
    {
        foreach (array_chunk($upserts, self::INSERT_BATCH) as $chunk) {
            $this->connection->executeStatement(
                'INSERT INTO tdmp_product_conflict_resolutions
                    (product_id, product_version_id, chosen_topdata_product_id, topdata_product_ids, status, created_at, updated_at)
                 VALUES ' . implode(', ', $chunk) . '
                 ON DUPLICATE KEY UPDATE
                    chosen_topdata_product_id = VALUES(chosen_topdata_product_id),
                    topdata_product_ids = VALUES(topdata_product_ids),
                    status = VALUES(status),
                    updated_at = VALUES(updated_at)'
            );
        }
    }
}