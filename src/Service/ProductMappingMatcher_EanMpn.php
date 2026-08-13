<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service;

use Doctrine\DBAL\Connection;
use Topdata\TopdataMapperSW6\Helper\UtilIdentifierNormalizer;

/**
 * Default product matcher: matches the `ean` dimension against Shopware
 * product.ean and the `oem` dimension against product.manufacturer_number (MPN).
 *
 * Used by the Mapper's own CLI build; TopFeed supplies its own matcher.
 *
 * 08/2026 created
 */
class ProductMappingMatcher_EanMpn implements ProductMappingMatcherInterface
{
    /** @var array<string, list<array{product_id: string, product_version_id: string}>>|null */
    private ?array $eanMap = null;

    /** @var array<string, list<array{product_id: string, product_version_id: string}>>|null */
    private ?array $mpnMap = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function matchRow(object $row): array
    {
        $this->_loadMaps();

        $matches = [];

        foreach ($row->ean ?? [] as $value) {
            foreach ($this->eanMap[UtilIdentifierNormalizer::normalizeEan((string)$value)] ?? [] as $product) {
                $matches[] = $product;
            }
        }

        foreach (($row->oem ?? []) as $value) {
            foreach ($this->mpnMap[UtilIdentifierNormalizer::normalizeMpn((string)$value)] ?? [] as $product) {
                $matches[] = $product;
            }
        }

        return $matches;
    }

    private function _loadMaps(): void
    {
        if ($this->eanMap !== null) {
            return;
        }

        $this->eanMap = $this->_loadIdentifierMap('ean');
        $this->mpnMap = $this->_loadIdentifierMap('mpn');
    }

    /**
     * @return array<string, list<array{product_id: string, product_version_id: string}>>
     */
    private function _loadIdentifierMap(string $kind): array
    {
        $column = $kind === 'mpn' ? 'manufacturer_number' : 'ean';

        $rows = $this->connection->fetchAllAssociative(
            "SELECT LOWER(HEX(id)) AS product_id, LOWER(HEX(version_id)) AS product_version_id, {$column} AS identifier
               FROM product
              WHERE {$column} IS NOT NULL AND {$column} <> ''"
        );

        $map = [];
        foreach ($rows as $row) {
            $normalized = $kind === 'mpn'
                ? UtilIdentifierNormalizer::normalizeMpn((string)$row['identifier'])
                : UtilIdentifierNormalizer::normalizeEan((string)$row['identifier']);
            if ($normalized === '') {
                continue;
            }
            $map[$normalized][] = [
                'product_id'         => $row['product_id'],
                'product_version_id' => $row['product_version_id'],
            ];
        }

        return $map;
    }
}
