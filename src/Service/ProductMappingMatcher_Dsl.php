<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service;

use Doctrine\DBAL\Connection;
use Topdata\TopdataMapperSW6\Helper\UtilIdentifierNormalizer;
use Topdata\TopdataMapperSW6\Service\Db\TdmpProductService;
use Topdata\TopdataMapperSW6\Service\Dsl\DslAndExpr;
use Topdata\TopdataMapperSW6\Service\Dsl\DslLeaf;
use Topdata\TopdataMapperSW6\Service\Dsl\DslOrExpr;
use Topdata\TopdataMapperSW6\Service\Dsl\DslPairingMatrix;
use Topdata\TopdataMapperSW6\Service\Dsl\DslParser;

/**
 * Configurable product matcher driven by the matching DSL (the wired default).
 *
 * Set algebra per API row: each leaf is one map lookup per row value → product
 * set; `|` = union, `&` = intersection. Scales like the old bulk map-lookup
 * matching (no per-product evaluation).
 *
 * Shop-side maps are built lazily (only the leaf kinds actually referenced by
 * the strategy) and cached for the run. The `topdataBrandIds` leaf resolves the
 * shop manufacturer via the tdmp_brand reverse map, which requires tdmp_brand
 * to be built before the product build (see TdmpMappingBuildService).
 *
 * Only live-version products are considered (see ProductMappingMatcherInterface).
 *
 * 08/2026 created
 */
class ProductMappingMatcher_Dsl implements ProductMappingMatcherInterface
{
    private ?DslOrExpr $strategy = null;

    /** @var array<string, list<array{product_id: string}>>|null */
    private ?array $eanMap = null;

    /** @var array<string, list<array{product_id: string}>>|null */
    private ?array $mpnMap = null;

    /** @var array<string, list<array{product_id: string}>>|null */
    private ?array $artnrMap = null;

    /** @var array<int, list<array{product_id: string}>>|null */
    private ?array $brandProductMap = null;

    /** @var array<string, array<string, list<array{product_id: string}>>> group|field → normalized value → products */
    private array $valueMapCache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly DslParser $dslParser,
    ) {
    }

    /**
     * Sets the compiled strategy for this run. The build service parses the
     * configured DSL once and passes the AST here.
     */
    public function setStrategy(DslOrExpr $strategy): void
    {
        $this->strategy = $strategy;
        $this->_resetMaps();
    }

    /**
     * Whether the strategy references the `topdataBrandIds` dimension (needs
     * tdmp_brand to be built first / guards --mapping=product alone). Walk is
     * recursive — the dimension may sit inside a `( ... )` group.
     */
    public static function referencesTopdataBrandIds(DslOrExpr $strategy): bool
    {
        foreach ($strategy->groups as $group) {
            foreach ($group->items as $item) {
                if ($item instanceof DslLeaf) {
                    if ($item->dimension === DslPairingMatrix::DIMENSION_BRAND_IDS) {
                        return true;
                    }
                } elseif (self::referencesTopdataBrandIds($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function matchRow(object $row): array
    {
        if ($this->strategy === null) {
            throw new \RuntimeException('No matching strategy set — call setStrategy() before matchRow().');
        }

        return $this->_evaluateExpr($this->strategy, $row);
    }

    /**
     * @return list<array{product_id: string}> deduped union over the OR groups
     */
    private function _evaluateExpr(DslOrExpr $expr, object $row): array
    {
        $matches = [];
        foreach ($expr->groups as $group) {
            $matches = $this->_union($matches, $this->_evaluateAnd($group, $row));
        }

        return $matches;
    }

    /**
     * @return list<array{product_id: string}> intersection over the AND operands
     */
    private function _evaluateAnd(DslAndExpr $group, object $row): array
    {
        $result = null;
        foreach ($group->items as $item) {
            $itemMatches = $item instanceof DslLeaf
                ? $this->_evaluateLeaf($item, $row)
                : $this->_evaluateExpr($item, $row);
            if (empty($itemMatches)) {
                return [];
            }
            $result = $result === null ? $itemMatches : $this->_intersect($result, $itemMatches);
            if (empty($result)) {
                return [];
            }
        }

        return $result ?? [];
    }

    /**
     * @param list<array{product_id: string}> $a
     * @param list<array{product_id: string}> $b
     * @return list<array{product_id: string}> deduped union
     */
    private function _union(array $a, array $b): array
    {
        $seen = [];
        foreach ($a as $product) {
            $seen[$product['product_id']] = true;
        }
        foreach ($b as $product) {
            if (!isset($seen[$product['product_id']])) {
                $a[] = $product;
            }
        }

        return $a;
    }

    /**
     * @return list<array{product_id: string}>
     */
    private function _evaluateLeaf(DslLeaf $leaf, object $row): array
    {
        return match ($leaf->shopField) {
            DslPairingMatrix::SHOP_EAN => $this->_lookupDimension($this->_getEanMap(), $this->_rowValues($row, 'ean'), UtilIdentifierNormalizer::normalizeEan(...)),
            DslPairingMatrix::SHOP_MANUFACTURER_NR => $this->_lookupDimension($this->_getMpnMap(), $this->_rowValues($row, $leaf->dimension), UtilIdentifierNormalizer::normalizeMpn(...)),
            DslPairingMatrix::SHOP_MANUFACTURER => $this->_lookupBrandIds($row),
            DslPairingMatrix::SHOP_PRODUCT_NUMBER => $this->_lookupArticleNumbers($row, $leaf->dimensionVariant),
            default => $this->_lookupValueDimension($leaf, $row),
        };
    }

    /**
     * @return list<array{product_id: string}>
     */
    private function _lookupDimension(array $map, array $values, callable $normalize): array
    {
        $matches = [];
        foreach ($values as $value) {
            $normalized = $normalize((string)$value);
            if ($normalized === '') {
                continue;
            }
            foreach ($map[$normalized] ?? [] as $product) {
                $matches[] = $product;
            }
        }

        return $matches;
    }

    /**
     * @return list<array{product_id: string}>
     */
    private function _lookupBrandIds(object $row): array
    {
        $matches = [];
        foreach ($this->_rowValues($row, 'topdataBrandIds') as $brandId) {
            foreach ($this->_getBrandProductMap()[(int)$brandId] ?? [] as $product) {
                $matches[] = $product;
            }
        }

        return $matches;
    }

    /**
     * @return list<array{product_id: string}> union over all providers (or one provider when scoped)
     */
    private function _lookupArticleNumbers(object $row, ?string $providerId): array
    {
        $matches = [];

        $articleNumbers = $row->articleNumbers ?? null;
        if (!is_object($articleNumbers)) {
            return [];
        }

        $providerLists = $providerId !== null
            ? [$articleNumbers->{$providerId} ?? null]
            : array_values(get_object_vars($articleNumbers));

        foreach ($providerLists as $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                $normalized = UtilIdentifierNormalizer::normalizeArticleNumber((string)$value);
                if ($normalized === '') {
                    continue;
                }
                foreach ($this->_getArtnrMap()[$normalized] ?? [] as $product) {
                    $matches[] = $product;
                }
            }
        }

        return $matches;
    }

    /**
     * property.<group> / customField.<name> leaves: shop values are mapped per
     * (group|field, dimension) with the dimension's normalization.
     *
     * @return list<array{product_id: string}>
     */
    private function _lookupValueDimension(DslLeaf $leaf, object $row): array
    {
        $shopMap = str_starts_with($leaf->shopField, 'property.')
            ? $this->_getPropertyMap(substr($leaf->shopField, strlen('property.')), $leaf->dimension)
            : $this->_getCustomFieldMap(substr($leaf->shopField, strlen('customField.')), $leaf->dimension);

        $normalize = $this->_dimensionNormalizer($leaf->dimension);

        $matches = [];
        foreach ($this->_rowValues($row, $leaf->dimension) as $value) {
            $normalized = $normalize((string)$value);
            if ($normalized === '') {
                continue;
            }
            foreach ($shopMap[$normalized] ?? [] as $product) {
                $matches[] = $product;
            }
        }

        return $matches;
    }

    /**
     * @return callable(string): string
     */
    private function _dimensionNormalizer(string $dimension): callable
    {
        return match ($dimension) {
            DslPairingMatrix::DIMENSION_EAN => UtilIdentifierNormalizer::normalizeEan(...),
            DslPairingMatrix::DIMENSION_PCD => UtilIdentifierNormalizer::normalizePcd(...),
            DslPairingMatrix::DIMENSION_ARTICLE_NUMBERS => UtilIdentifierNormalizer::normalizeArticleNumber(...),
            default => UtilIdentifierNormalizer::normalizeMpn(...),
        };
    }

    /**
     * @return list<string> the row's values for the dimension (articleNumbers is handled separately)
     */
    private function _rowValues(object $row, string $dimension): array
    {
        return match ($dimension) {
            DslPairingMatrix::DIMENSION_EAN => is_array($row->ean ?? null) ? $row->ean : [],
            DslPairingMatrix::DIMENSION_MPN => is_array($row->mpn ?? null) ? $row->mpn : [],
            DslPairingMatrix::DIMENSION_PCD => is_array($row->pcd ?? null) ? $row->pcd : [],
            DslPairingMatrix::DIMENSION_BRAND_IDS => is_array($row->topdataBrandIds ?? null) ? $row->topdataBrandIds : [],
            default => [],
        };
    }

    /**
     * @param list<array{product_id: string}> $a
     * @param list<array{product_id: string}> $b
     * @return list<array{product_id: string}>
     */
    private function _intersect(array $a, array $b): array
    {
        $bIds = [];
        foreach ($b as $product) {
            $bIds[$product['product_id']] = true;
        }

        $out = [];
        foreach ($a as $product) {
            if (isset($bIds[$product['product_id']])) {
                $out[] = $product;
            }
        }

        return $out;
    }

    private function _resetMaps(): void
    {
        $this->eanMap          = null;
        $this->mpnMap          = null;
        $this->artnrMap        = null;
        $this->brandProductMap = null;
        $this->valueMapCache   = [];
    }

    /**
     * @return array<string, list<array{product_id: string}>>
     */
    private function _getEanMap(): array
    {
        if ($this->eanMap === null) {
            $this->eanMap = $this->_loadIdentifierMap('ean', UtilIdentifierNormalizer::normalizeEan(...));
        }

        return $this->eanMap;
    }

    /**
     * @return array<string, list<array{product_id: string}>>
     */
    private function _getMpnMap(): array
    {
        if ($this->mpnMap === null) {
            $this->mpnMap = $this->_loadIdentifierMap('manufacturer_number', UtilIdentifierNormalizer::normalizeMpn(...));
        }

        return $this->mpnMap;
    }

    /**
     * @return array<string, list<array{product_id: string}>>
     */
    private function _getArtnrMap(): array
    {
        if ($this->artnrMap === null) {
            $this->artnrMap = $this->_loadIdentifierMap('product_number', UtilIdentifierNormalizer::normalizeArticleNumber(...));
        }

        return $this->artnrMap;
    }

    /**
     * @return array<int, list<array{product_id: string}>> topdata_brand_id → products
     */
    private function _getBrandProductMap(): array
    {
        if ($this->brandProductMap === null) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT tb.topdata_brand_id, LOWER(HEX(p.id)) AS product_id
                   FROM tdmp_brand tb
                   JOIN product p
                     ON p.manufacturer = tb.brand_id
                    AND p.version_id = 0x' . TdmpProductService::LIVE_VERSION_HEX
            );

            $map = [];
            foreach ($rows as $row) {
                $map[(int)$row['topdata_brand_id']][] = ['product_id' => $row['product_id']];
            }
            $this->brandProductMap = $map;
        }

        return $this->brandProductMap;
    }

    /**
     * @return array<string, list<array{product_id: string}>> normalized identifier → products
     */
    private function _loadIdentifierMap(string $column, callable $normalize): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT LOWER(HEX(id)) AS product_id, {$column} AS identifier
               FROM product
              WHERE version_id = 0x" . TdmpProductService::LIVE_VERSION_HEX . "
                AND {$column} IS NOT NULL AND {$column} <> ''"
        );

        $map = [];
        foreach ($rows as $row) {
            $normalized = $normalize((string)$row['identifier']);
            if ($normalized === '') {
                continue;
            }
            $map[$normalized][] = ['product_id' => $row['product_id']];
        }

        return $map;
    }

    /**
     * @return array<string, list<array{product_id: string}>> normalized property value → products
     */
    private function _getPropertyMap(string $groupName, string $dimension): array
    {
        return $this->valueMapCache['property:' . $groupName . ':' . $dimension] ??= $this->_loadValueMap(
            'SELECT LOWER(HEX(pp.product_id)) AS product_id, pgot.name AS value
               FROM product_property pp
               JOIN property_group_option pgo
                 ON pgo.id = pp.property_group_option_id
               JOIN property_group_option_translation pgot
                 ON pgot.property_group_option_id = pgo.id
                AND pgot.property_group_option_version_id = pgo.version_id
               JOIN property_group_translation pgt
                 ON pgt.property_group_id = pgo.property_group_id
                AND pgt.property_group_version_id = pgo.version_id
              WHERE pgt.name = ?
                AND pp.version_id = 0x' . TdmpProductService::LIVE_VERSION_HEX,
            [$groupName],
            $this->_dimensionNormalizer($dimension)
        );
    }

    /**
     * @return array<string, list<array{product_id: string}>> normalized custom field value → products
     */
    private function _getCustomFieldMap(string $fieldName, string $dimension): array
    {
        return $this->valueMapCache['customField:' . $fieldName . ':' . $dimension] ??= $this->_loadCustomFieldMap($fieldName, $this->_dimensionNormalizer($dimension));
    }

    /**
     * @param string[] $params
     * @return array<string, list<array{product_id: string}>>
     */
    private function _loadValueMap(string $sql, array $params, callable $normalize): array
    {
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $map = [];
        foreach ($rows as $row) {
            if ($row['value'] === null) {
                continue;
            }
            $normalized = $normalize((string)$row['value']);
            if ($normalized === '') {
                continue;
            }
            $map[$normalized][] = ['product_id' => $row['product_id']];
        }

        return $map;
    }

    /**
     * @return array<string, list<array{product_id: string}>>
     */
    private function _loadCustomFieldMap(string $fieldName, callable $normalize): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS product_id, custom_fields AS custom_fields
               FROM product
              WHERE version_id = 0x' . TdmpProductService::LIVE_VERSION_HEX . '
                AND custom_fields IS NOT NULL'
        );

        $map = [];
        foreach ($rows as $row) {
            $customFields = json_decode((string)$row['custom_fields'], true);
            if (!is_array($customFields) || !array_key_exists($fieldName, $customFields)) {
                continue;
            }
            $values = $customFields[$fieldName];
            if (!is_array($values)) {
                $values = [$values];
            }
            foreach ($values as $value) {
                if (!is_string($value) && !is_numeric($value)) {
                    continue;
                }
                $normalized = $normalize((string)$value);
                if ($normalized === '') {
                    continue;
                }
                $map[$normalized][] = ['product_id' => $row['product_id']];
            }
        }

        return $map;
    }
}