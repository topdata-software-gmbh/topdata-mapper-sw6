<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Db;

use Doctrine\DBAL\Connection;

/**
 * Counts Shopware products by identifier dimension (ean / mpn / article
 * number / any) and per custom-field name — the DB-side debugging companion
 * of the mapping import (e.g. explaining an import run with zero matches:
 * are the identifiers even present in the shop?).
 *
 * Mirrors the matcher's semantics: only live-version products count
 * (TdmpProductService::LIVE_VERSION_HEX), variants are included unless
 * `$parentsOnly` is set, and custom-field values that are empty strings are
 * ignored.
 *
 * 08/2026 created
 */
class TdmpProductCountService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Counts products per identifier dimension in one aggregate query.
     *
     * The "usable" counts exclude junk placeholder values by default: EAN
     * counts only values containing at least one digit (`-` / `n/a` normalize
     * to '' via UtilIdentifierNormalizer and can never match), MPN and article
     * number count non-blank values that are not placeholder tokens (`-`,
     * `n/a`, trimmed, case-insensitive — stricter than the matcher, which
     * technically keeps a `-` MPN). The `placeholder*` counters flag the
     * excluded products (EAN: non-empty values without any digit; MPN /
     * article number: the placeholder tokens) — the command renders them only
     * with `--show-placeholders`.
     *
     * @return array{total: int, ean: int, mpn: int, articleNumber: int, any: int, placeholderEan: int, placeholderMpn: int, placeholderArticleNumber: int}
     */
    public function countIdentifiers(bool $parentsOnly = false): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN ean REGEXP '[0-9]' THEN 1 ELSE 0 END) AS with_ean,
                SUM(CASE
                    WHEN TRIM(manufacturer_number) <> ''
                      AND LOWER(TRIM(manufacturer_number)) NOT IN ('-', 'n/a')
                    THEN 1 ELSE 0 END) AS with_mpn,
                SUM(CASE
                    WHEN TRIM(product_number) <> ''
                      AND LOWER(TRIM(product_number)) NOT IN ('-', 'n/a')
                    THEN 1 ELSE 0 END) AS with_article_number,
                SUM(CASE
                    WHEN ean REGEXP '[0-9]'
                      OR (TRIM(manufacturer_number) <> '' AND LOWER(TRIM(manufacturer_number)) NOT IN ('-', 'n/a'))
                      OR (TRIM(product_number) <> '' AND LOWER(TRIM(product_number)) NOT IN ('-', 'n/a'))
                    THEN 1 ELSE 0 END) AS with_any,
                SUM(CASE WHEN ean IS NOT NULL AND ean <> '' AND ean NOT REGEXP '[0-9]' THEN 1 ELSE 0 END) AS placeholder_ean,
                SUM(CASE WHEN LOWER(TRIM(manufacturer_number)) IN ('-', 'n/a') THEN 1 ELSE 0 END) AS placeholder_mpn,
                SUM(CASE WHEN LOWER(TRIM(product_number)) IN ('-', 'n/a') THEN 1 ELSE 0 END) AS placeholder_article_number
               FROM product
              WHERE version_id = 0x" . TdmpProductService::LIVE_VERSION_HEX
                . ($parentsOnly ? ' AND parent_id IS NULL' : '')
        ) ?: [];

        return [
            'total'                    => (int)($row['total'] ?? 0),
            'ean'                      => (int)($row['with_ean'] ?? 0),
            'mpn'                      => (int)($row['with_mpn'] ?? 0),
            'articleNumber'            => (int)($row['with_article_number'] ?? 0),
            'any'                      => (int)($row['with_any'] ?? 0),
            'placeholderEan'           => (int)($row['placeholder_ean'] ?? 0),
            'placeholderMpn'           => (int)($row['placeholder_mpn'] ?? 0),
            'placeholderArticleNumber' => (int)($row['placeholder_article_number'] ?? 0),
        ];
    }

    /**
     * Whether the `product` table has a `custom_fields` column — some shops /
     * fixtures drop it, and both this service and the matcher would fail on
     * them.
     */
    public function hasCustomFieldsColumn(): bool
    {
        return (bool)$this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'custom_fields'"
        );
    }

    /**
     * Counts products per custom-field name (products having at least one
     * non-empty scalar value), sorted by count descending.
     *
     * @return array<string, int> custom field name → product count
     */
    public function countCustomFields(bool $parentsOnly = false): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT custom_fields
               FROM product
              WHERE version_id = 0x' . TdmpProductService::LIVE_VERSION_HEX
                . ' AND custom_fields IS NOT NULL'
                . ($parentsOnly ? ' AND parent_id IS NULL' : '')
        );

        $counts = [];
        foreach ($rows as $row) {
            $customFields = json_decode((string)$row['custom_fields'], true);
            if (!is_array($customFields)) {
                continue;
            }
            foreach ($customFields as $name => $value) {
                if (!$this->_hasNonEmptyValue($value)) {
                    continue;
                }
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Whether a custom-field value contains at least one non-empty scalar
     * (scalars and lists of scalars, mirroring the matcher).
     */
    private function _hasNonEmptyValue(mixed $value): bool
    {
        if (!is_array($value)) {
            $value = [$value];
        }
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                return true;
            }
            if (is_numeric($item)) {
                return true;
            }
        }

        return false;
    }
}