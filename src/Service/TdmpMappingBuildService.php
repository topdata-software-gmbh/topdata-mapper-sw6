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
    public function buildProductMappings(string $language = 'de'): int
    {
        $now  = (new \DateTime())->format('Y-m-d H:i:s');
        $rows = [];

        $start = 0;
        $page  = 0;
        while (true) {
            $page++;
            $response = $this->mapperClient->getProductMappings(self::PRODUCT_TYPES, $start, self::PAGE_SIZE, $language);
            $apiRows  = $response->rows ?? [];

            if (count($apiRows) === 0) {
                break;
            }

            foreach ($apiRows as $apiRow) {
                $topdataId = (int)$apiRow->products_id;
                foreach ($this->productMatcher->matchRow($apiRow) as $product) {
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
            $start += self::PAGE_SIZE;
        }

        $this->tdmpProductService->deleteAll();
        $count = $this->tdmpProductService->insertMany($rows);
        CliLogger::info("Built tdmp_product: {$count} rows across {$page} page(s).");

        return $count;
    }

    /**
     * Rebuilds tdmp_brand from /v2/mapping/brand (matched by normalized name).
     */
    public function buildBrandMappings(string $language = 'de'): int
    {
        $now  = (new \DateTime())->format('Y-m-d H:i:s');
        $rows = [];

        $shopBrandMap = $this->_loadShopBrandMap();

        $start = 0;
        $page  = 0;
        while (true) {
            $page++;
            $response = $this->mapperClient->getBrandMappings($start, self::PAGE_SIZE, $language);
            $apiRows  = $response->rows ?? [];

            if (count($apiRows) === 0) {
                break;
            }

            foreach ($apiRows as $apiRow) {
                $brandId = $shopBrandMap[UtilIdentifierNormalizer::normalizeLabel((string)$apiRow->val)] ?? null;
                if ($brandId === null) {
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
            $start += self::PAGE_SIZE;
        }

        $this->tdmpBrandService->deleteAll();
        $count = $this->tdmpBrandService->insertMany($rows);
        CliLogger::info("Built tdmp_brand: {$count} rows across {$page} page(s).");

        return $count;
    }

    /**
     * @return array<string, string> normalized manufacturer name → brand_id hex
     */
    private function _loadShopBrandMap(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS brand_id, name FROM product_manufacturer WHERE name IS NOT NULL'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[UtilIdentifierNormalizer::normalizeLabel((string)$row['name'])] = $row['brand_id'];
        }

        return $map;
    }
}
