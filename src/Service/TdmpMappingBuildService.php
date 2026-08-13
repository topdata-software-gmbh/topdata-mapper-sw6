<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service;

use Doctrine\DBAL\Connection;
use Topdata\TopdataMapperSW6\Helper\UtilIdentifierNormalizer;
use Topdata\TopdataMapperSW6\Service\Db\TdmpBrandService;
use Topdata\TopdataMapperSW6\Service\Db\TdmpProductService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Builds the mapping tables (tdmp_product / tdmp_brand) from the mapping API.
 *
 * Flow per entity: stream the bulk data from the mapping API (unified v2
 * pagination), resolve the Shopware side via a matcher / local lookup, then
 * full-table replace (the Mapper is the single writer).
 *
 * 08/2026 created
 */
class TdmpMappingBuildService
{
    public const int PAGE_SIZE = 5000;

    public const array PRODUCT_TYPES = ['ean', 'oem', 'pcd', 'distributor'];

    public function __construct(
        private readonly TdmpProductService               $tdmpProductService,
        private readonly TdmpBrandService                 $tdmpBrandService,
        private readonly TopdataMapperWebserviceV2Client   $mapperClient,
        private readonly ProductMappingMatcherInterface    $productMatcher,
        private readonly Connection                        $connection,
    ) {
    }

    /**
     * Rebuilds tdmp_product from /v2/mapping/product.
     */
    public function buildProductMappings(string $language = 'de'): MappingBuildStats
    {
        $t0        = microtime(true);
        $now       = (new \DateTime())->format('Y-m-d H:i:s');
        $rows      = [];
        $unmatched = 0;
        $apiRowsCount = 0;

        $offset = 0;
        $page   = 0;
        while (true) {
            $page++;
            $response = $this->mapperClient->getProductMappings(self::PRODUCT_TYPES, $offset, self::PAGE_SIZE, $language);
            $apiRows  = $response->rows ?? [];

            if (count($apiRows) === 0) {
                break;
            }
            $apiRowsCount += count($apiRows);

            foreach ($apiRows as $apiRow) {
                $topdataId = (int)$apiRow->products_id;
                $matches   = $this->productMatcher->matchRow($apiRow);
                if (count($matches) === 0) {
                    $unmatched++;
                }
                foreach ($matches as $product) {
                    $rows[] = [
                        'product_id'         => $product['product_id'],
                        'product_version_id' => $product['product_version_id'],
                        'topdata_id'         => $topdataId,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ];
                }
            }

            if (!isset($response->pagination->has_more) || !$response->pagination->has_more) {
                break;
            }
            $offset += self::PAGE_SIZE;
        }

        $this->tdmpProductService->deleteAll();
        $matched = $this->tdmpProductService->insertMany($rows);
        CliLogger::info("Built tdmp_product: {$matched} rows across {$page} page(s), {$unmatched} unmatched.");

        return new MappingBuildStats('product', $page, $apiRowsCount, $matched, $unmatched, microtime(true) - $t0);
    }

    /**
     * Rebuilds tdmp_brand from /v2/mapping/brand (matched by normalized name).
     */
    public function buildBrandMappings(string $language = 'de'): MappingBuildStats
    {
        $t0        = microtime(true);
        $now       = (new \DateTime())->format('Y-m-d H:i:s');
        $rows      = [];
        $unmatched = 0;
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
                $brandId = $shopBrandMap[UtilIdentifierNormalizer::normalizeLabel((string)$apiRow->val)] ?? null;
                if ($brandId === null) {
                    $unmatched++;
                    continue;
                }
                $rows[] = [
                    'brand_id'   => $brandId,
                    'topdata_id' => (int)$apiRow->id,
                    'created_at' => $now,
                    'updated_at' => $now,
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

        return new MappingBuildStats('brand', $page, $apiRowsCount, $matched, $unmatched, microtime(true) - $t0);
    }

    /**
     * @return array<string, string> normalized manufacturer name → brand_id hex
     */
    private function _loadShopBrandMap(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(pm.id)) AS brand_id, MIN(pmt.name) AS name
             FROM product_manufacturer pm
             JOIN product_manufacturer_translation pmt
               ON pmt.product_manufacturer_id = pm.id
              AND pmt.product_manufacturer_version_id = pm.version_id
             WHERE pmt.name IS NOT NULL
             GROUP BY pm.id, pm.version_id'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[UtilIdentifierNormalizer::normalizeLabel((string)$row['name'])] = $row['brand_id'];
        }

        return $map;
    }
}
