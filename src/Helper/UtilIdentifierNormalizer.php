<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Helper;

/**
 * Identifier normalization for matching — the single normalization source for
 * the mapping DSL (TopFeed's own helpers were removed with its matcher, so
 * there is no sync constraint left):
 * - EAN → digits-only
 * - MPN/PCD → lowercase trim
 * - article numbers → exact (whitespace-trimmed)
 * - labels → lowercase, whitespace-collapsed
 *
 * 08/2026 created
 */
final class UtilIdentifierNormalizer
{
    public static function normalizeEan(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    public static function normalizeMpn(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function normalizePcd(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function normalizeArticleNumber(string $value): string
    {
        return trim($value);
    }

    public static function normalizeLabel(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }
}