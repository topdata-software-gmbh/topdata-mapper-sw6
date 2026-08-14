<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Dsl;

/**
 * Single source of truth for which shop field may be paired with which API
 * dimension in the matching DSL. Served as JSON to the settings page (the
 * frontend never hardcodes it) and enforced authoritatively by the parser.
 *
 * The `articleNumbers` cell is provider-scopeable (`articleNumbers.<providerId>`
 * = prefix check on the cell). Matrix-violating pairs are hard-blocked
 * (validation error, not warn) — a silently-never-matching leaf is worse than
 * a loud error.
 *
 * 08/2026 created
 */
class DslPairingMatrix
{
    public const string DIMENSION_EAN             = 'ean';
    public const string DIMENSION_MPN             = 'mpn';
    public const string DIMENSION_PCD             = 'pcd';
    public const string DIMENSION_ARTICLE_NUMBERS = 'articleNumbers';
    public const string DIMENSION_BRAND_IDS       = 'topdataBrandIds';

    public const string SHOP_EAN             = 'product.ean';
    public const string SHOP_MANUFACTURER_NR = 'product.manufacturer_number';
    public const string SHOP_MANUFACTURER    = 'product.manufacturer';
    public const string SHOP_PRODUCT_NUMBER  = 'product.product_number';
    public const string SHOP_PROPERTY        = 'property';
    public const string SHOP_CUSTOM_FIELD    = 'customField';

    /**
     * shop field kind → allowed dimensions (articleNumbers is provider-scopeable).
     */
    private const array MATRIX = [
        self::SHOP_EAN             => [self::DIMENSION_EAN],
        self::SHOP_MANUFACTURER_NR => [self::DIMENSION_MPN, self::DIMENSION_PCD],
        self::SHOP_MANUFACTURER    => [self::DIMENSION_BRAND_IDS],
        self::SHOP_PRODUCT_NUMBER  => [self::DIMENSION_ARTICLE_NUMBERS],
        self::SHOP_PROPERTY        => [self::DIMENSION_EAN, self::DIMENSION_MPN, self::DIMENSION_PCD, self::DIMENSION_ARTICLE_NUMBERS],
        self::SHOP_CUSTOM_FIELD    => [self::DIMENSION_EAN, self::DIMENSION_MPN, self::DIMENSION_PCD, self::DIMENSION_ARTICLE_NUMBERS],
    ];

    /**
     * All dimensionRefs of a leaf are a base dimension plus an optional
     * provider scope; the matrix is checked against the base dimension.
     */
    public static function splitDimensionRef(string $dimensionRef): array
    {
        $parts = explode('.', $dimensionRef, 2);

        return [
            'dimension' => $parts[0],
            'variant'   => $parts[1] ?? null,
        ];
    }

    public static function isAllowed(string $shopFieldKind, string $dimension): bool
    {
        return in_array($dimension, self::allowedDimensions($shopFieldKind), true);
    }

    /**
     * @return string[] allowed base dimensions for the shop field kind
     */
    public static function allowedDimensions(string $shopFieldKind): array
    {
        return self::MATRIX[$shopFieldKind] ?? [];
    }

    /**
     * Matrix as served to the settings page: {shopFieldKind: [dimensions]}.
     * The frontend renders the dimension dropdowns from it.
     */
    public static function toArray(): array
    {
        return self::MATRIX;
    }
}