<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Helper;

/**
 * Identifier normalization for matching (mirrors TopFeed's UtilMappingHelper):
 * EAN → digits-only; MPN/OEM → lowercase, no surrounding space; labels → lowercase, whitespace-collapsed.
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

    public static function normalizeLabel(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }
}
