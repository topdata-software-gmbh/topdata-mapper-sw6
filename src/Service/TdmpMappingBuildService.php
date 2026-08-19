<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service;

use Doctrine\DBAL\Connection;
use Topdata\TopdataMapperSW6\Helper\UtilIdentifierNormalizer;
use Topdata\TopdataMapperSW6\Service\Db\TdmpBrandService;
use Topdata\TopdataMapperSW6\Service\Db\TdmpConflictResolutionService;
use Topdata\TopdataMapperSW6\Service\Db\TdmpProductService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Builds the mapping tables (tdmp_product / tdmp_brand) from the mapping API.
 *
 * Flow per entity: stream the bulk data from the mapping API (unified v2
 * keyset pagination), resolve the Shopware side via the DSL-driven matcher,
 * then full-table replace (the Mapper is the single writer).
 *
 * Conflict handling (product build): rows are deduped per (product_id,
 * topdata_product_id) before insert — a raw batch INSERT would crash on a
 * duplicate PK tuple when the same product matches via multiple dimensions.
 * Products matching >1 Topdata article are conflicts: the resolution table is
 * synced (see TdmpConflictResolutionService) and tdmp_product keeps only the
 * chosen row per conflicted product. The reverse case (one Topdata article
 * matched by many shop products, e.g. variants) is normal, NOT a conflict.
 *
 * 08/2026 created
 */
class TdmpMappingBuildService
{
    public const int PAGE_SIZE = 1000;

    public function __construct(
        private readonly TdmpProductService $tdmpProductService,
        private readonly TdmpBrandService $tdmpBrandService,
        private readonly TdmpConflictResolutionService $tdmpConflictResolutionService,
        private readonly TopdataMapperWebserviceV2Client $mapperClient,
        private readonly ProductMappingMatcherInterface $productMatcher,
        private readonly DslStrategyService $strategyService,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Rebuilds tdmp_product from /v2/mapping/product, using the configured
     * matching strategy (fails loudly on an invalid stored strategy).
     */
    public function buildProductMappings(string $language = 'de'): MappingBuildStats
    {
        $t0           = microtime(true);
        $now          = (new \DateTime())->format('Y-m-d H:i:s');
        $apiRowsCount = 0;
        $unmatched    = 0;

        $strategy = $this->strategyService->getConfiguredStrategy();
        $this->productMatcher->setStrategy($strategy);

        $types = ProductMappingMatcher_Dsl::neededApiTypes($strategy);
        CliLogger::info('Requesting mapping API types: ' . implode(', ', $types));

        if (ProductMappingMatcher_Dsl::referencesTopdataBrandIds($strategy) && $this->tdmpBrandService->count() === 0) {
            CliLogger::warning('The matching strategy references topdataBrandIds but tdmp_brand is empty — the brand-scoped leaves will match nothing. Run the brand build first (topdata:mapper:import --mapping=brand).');
        }

        // ---- per product: candidate topdata ids (deduped) + preview data for the conflict radios
        $candidates   = [];
        $previews     = [];
        $synonymIndex = [];

        $cursor = null;
        $page   = 0;
        while (true) {
            $page++;
            $response = $this->mapperClient->getProductMappings($types, $cursor, self::PAGE_SIZE, $language);
            $apiRows  = $response->rows ?? [];

            if (count($apiRows) === 0) {
                break;
            }
            $apiRowsCount += count($apiRows);

            foreach ($apiRows as $apiRow) {
                $topdataId = (int) $apiRow->topdataProductId;
                $matches   = $this->productMatcher->matchRow($apiRow);
                if (count($matches) === 0) {
                    $unmatched++;
                }
                foreach ($matches as $product) {
                    $productId                          = $product['product_id'];
                    $candidates[$productId][$topdataId] = true;
                    if (!isset($previews[$productId][$topdataId])) {
                        $previews[$productId][$topdataId] = [
                            'pcd' => array_map('strval', $apiRow->pcd ?? []),
                            'ean' => array_map('strval', $apiRow->ean ?? []),
                            'mpn' => array_map('strval', $apiRow->mpn ?? []),
                        ];
                    }
                }
                foreach ($apiRow->synonymIds ?? [] as $synonymId) {
                    $synonymIndex[$topdataId][(int) $synonymId] = true;
                }
            }

            if (!isset($response->pagination->has_more) || !$response->pagination->has_more) {
                break;
            }
            $cursor = $response->pagination->next_cursor ?? null;
            if ($cursor === null) {
                throw new \RuntimeException('Webservice reported has_more without next_cursor');
            }
        }

        // ---- collapse synonym-equivalent candidates: the webservice emits a
        //      row for every reserved product that has synonyms (bidirectional
        //      links), so a shop product that matches both partners of a
        //      synonym pair (e.g. a toner that fits 51034 and its synonym 8100)
        //      would otherwise register a false conflict. Merge connected
        //      candidates into one representative per group; the API does not
        //      expose which partner is the canonical device product, so the
        //      representative is the lowest topdataProductId (deterministic
        //      and stable across imports; the deferred structured
        //      version_products_id columns will allow true canonical selection).
        $mergedCandidates = 0;
        foreach ($candidates as $productId => $topdataIds) {
            $collapsed = $this->_collapseSynonymCandidates($topdataIds, $synonymIndex);
            if (count($collapsed) < count($topdataIds)) {
                $mergedCandidates += count($topdataIds) - count($collapsed);
                CliLogger::debug(sprintf(
                    'product %s: merged %d synonym-equivalent candidate(s): %s → %s',
                    $productId,
                    count($topdataIds) - count($collapsed),
                    implode(',', array_keys($topdataIds)),
                    implode(',', array_keys($collapsed))
                ));
            }
            $candidates[$productId] = $collapsed;
            $previews[$productId]   = array_intersect_key($previews[$productId], $collapsed);
        }
        if ($mergedCandidates > 0) {
            CliLogger::info("Merged {$mergedCandidates} synonym-equivalent candidate(s) across " . count($candidates) . ' product(s).');
        }

        // ---- split into conflicts (≥2 candidates) and plain mappings, sync
        // resolutions first (user resolutions survive re-imports; user-kept
        // choices may differ from the recomputed auto choice), then insert
        // only the chosen row per conflicted product
        $conflicts = [];
        foreach ($candidates as $productId => $topdataIds) {
            $ids = array_keys($topdataIds);
            if (count($ids) > 1) {
                $conflicts[$productId] = $ids;
            }
        }

        $resolutionResult = $this->tdmpConflictResolutionService->syncFromBuild($conflicts, $previews);
        $chosenMap        = $resolutionResult['chosen'];
        $resolutionStats  = $resolutionResult['stats'];
        CliLogger::info(sprintf(
            'Conflicts: %d product(s) matched >1 Topdata article (%d auto, %d user-kept, %d demoted, %d resolved).',
            count($conflicts),
            $resolutionStats['auto'],
            $resolutionStats['user'],
            $resolutionStats['demoted'],
            $resolutionStats['removed']
        ));

        $insertRows = [];
        foreach ($candidates as $productId => $topdataIds) {
            $ids          = array_keys($topdataIds);
            $chosen       = $chosenMap[$productId] ?? min($ids);
            $insertRows[] = [
                'product_id'         => $productId,
                'topdata_product_id' => $chosen,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        $this->tdmpProductService->deleteAll();
        $matched = $this->tdmpProductService->insertMany($insertRows);
        CliLogger::info("Built tdmp_product: {$matched} rows across {$page} page(s), {$unmatched} unmatched.");

        return new MappingBuildStats(
            'product',
            $page,
            $apiRowsCount,
            $matched,
            $unmatched,
            $this->_countShopProducts(),
            microtime(true) - $t0,
            count($conflicts)
        );
    }

    /**
     * Rebuilds tdmp_brand from /v2/mapping/brand (matched by normalized name).
     */
    public function buildBrandMappings(string $language = 'de'): MappingBuildStats
    {
        $t0           = microtime(true);
        $now          = (new \DateTime())->format('Y-m-d H:i:s');
        $rows         = [];
        $unmatched    = 0;
        $apiRowsCount = 0;

        $shopBrandMap = $this->_loadShopBrandMap();

        $offset = 0;
        $page   = 0;
        while (true) {
            $page++;
            $response = $this->mapperClient->getBrandMappings($offset, self::PAGE_SIZE, $language);
            $apiRows  = $response->rows ?? [];

            if (count($apiRows) === 0) {
                break;
            }
            $apiRowsCount += count($apiRows);

            foreach ($apiRows as $apiRow) {
                $brandId = $shopBrandMap[UtilIdentifierNormalizer::normalizeLabel((string) $apiRow->val)] ?? null;
                if ($brandId === null) {
                    $unmatched++;
                    continue;
                }
                $rows[] = [
                    'product_manufacturer_id' => $brandId,
                    'topdata_brand_id'        => (int) $apiRow->id,
                    'created_at'              => $now,
                    'updated_at'              => $now,
                ];
            }

            if (!isset($response->pagination->has_more) || !$response->pagination->has_more) {
                break;
            }
            $offset += self::PAGE_SIZE;
        }

        $this->tdmpBrandService->deleteAll();
        $matched = $this->tdmpBrandService->insertMany($rows);
        CliLogger::info("Built tdmp_brand: {$matched} rows across {$page} page(s), {$unmatched} unmatched.");

        return new MappingBuildStats('brand', $page, $apiRowsCount, $matched, $unmatched, $this->_countShopBrands(), microtime(true) - $t0);
    }

    /**
     * Counts live-version shop products (variants included, mirroring the matcher).
     */
    private function _countShopProducts(): int
    {
        return (int)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM product WHERE version_id = 0x' . TdmpProductService::LIVE_VERSION_HEX
        );
    }

    /**
     * Counts live-version shop manufacturers.
     */
    private function _countShopBrands(): int
    {
        return (int)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM product_manufacturer WHERE version_id = 0x' . TdmpBrandService::LIVE_VERSION_HEX
        );
    }

    /**
     * Merges candidates that are connected via the synonym index into a single
     * representative per group (union-find over the candidate ids). A group's
     * representative is its lowest topdataProductId.
     *
     * @param array<int, true> $candidateIds topdata product ids of one shop product (keys)
     * @param array<int, array<int, true>> $synonymIndex topdata product id → synonym ids (keys)
     * @return array<int, true> collapsed candidate ids (keys)
     */
    private function _collapseSynonymCandidates(array $candidateIds, array $synonymIndex): array
    {
        $parent = [];
        $find   = function (int $x) use (&$parent, &$find): int {
            $parent[$x] ??= $x;
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x          = $parent[$x];
            }

            return $x;
        };
        $union = function (int $a, int $b) use (&$parent, &$find): void {
            $rootA = $find($a);
            $rootB = $find($b);
            if ($rootA !== $rootB) {
                $parent[$rootA] = $rootB;
            }
        };

        foreach ($candidateIds as $x => $_) {
            foreach ($synonymIndex[$x] ?? [] as $y => $_) {
                if (isset($candidateIds[$y])) {
                    $union($x, $y);
                }
            }
        }

        $representatives = [];
        foreach ($candidateIds as $x => $_) {
            $root                   = $find($x);
            $representatives[$root] = $representatives[$root] ?? $x;
            $representatives[$root] = min($representatives[$root], $x);
        }

        $collapsed = [];
        foreach ($representatives as $representative) {
            $collapsed[$representative] = true;
        }

        return $collapsed;
    }

    /**
     * @return array<string, string> normalized manufacturer name → product_manufacturer_id hex
     */
    private function _loadShopBrandMap(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(pm.id)) AS product_manufacturer_id, MIN(pmt.name) AS name
             FROM product_manufacturer pm
             JOIN product_manufacturer_translation pmt
               ON pmt.product_manufacturer_id = pm.id
              AND pmt.product_manufacturer_version_id = pm.version_id
             WHERE pmt.name IS NOT NULL
               AND pm.version_id = 0x' . TdmpBrandService::LIVE_VERSION_HEX . '
             GROUP BY pm.id, pm.version_id'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[UtilIdentifierNormalizer::normalizeLabel((string) $row['name'])] = $row['product_manufacturer_id'];
        }

        return $map;
    }
}
