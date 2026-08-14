<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Dsl;

/**
 * Recursive-descent parser for the matching DSL (the single authoritative
 * parser — the settings page re-renders its visual builder from the AST this
 * parser produces, and the import fails loudly on invalid stored strategies).
 *
 * Grammar:
 * ```
 * strategy := orExpr
 * orExpr   := andExpr ('|' andExpr)*     // | = union of matched product sets
 * andExpr  := leaf ('&' leaf)*           // & = intersection
 * leaf     := shopField ':' dimensionRef
 * ```
 *
 * shopField: product.ean | product.manufacturer_number | product.manufacturer
 *            | product.product_number | property.<group> | customField.<name>
 * dimensionRef: ean | mpn | pcd | articleNumbers | articleNumbers.<providerId>
 *            | topdataBrandIds
 *
 * 08/2026 created
 */
class DslParser
{
    /**
     * @throws DslParseException on grammar or pairing-matrix violations
     */
    public function parse(string $dsl): DslOrExpr
    {
        $dsl = trim($dsl);
        if ($dsl === '') {
            throw new DslParseException('The DSL string is empty.');
        }

        $groups = [];
        foreach ($this->_split($dsl, '|') as $orIndex => $andPart) {
            $orOffset = $this->_findOperatorOffset($dsl, '|', $orIndex);

            $leaves = [];
            foreach ($this->_split($andPart, '&') as $andIndex => $leafPart) {
                $leafOffset = $orOffset + $this->_findOperatorOffset($andPart, '&', $andIndex);
                $leaves[]   = $this->_parseLeaf(trim($leafPart), $leafOffset);
            }
            if (count($leaves) === 0) {
                throw new DslParseException('Empty AND group (two `|` in a row).', position: $orOffset);
            }
            $groups[] = new DslAndExpr($leaves);
        }

        return new DslOrExpr($groups);
    }

    /**
     * @return string[] non-empty trimmed parts (empty parts are skipped — the
     *                  caller detects the "missing operand" case via the count)
     */
    private function _split(string $s, string $operator): array
    {
        $parts = [];
        foreach (explode($operator, $s) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * Approximates the 0-based offset of the operand at $index within $s,
     * counting the operator separators before it (used for error positions).
     */
    private function _findOperatorOffset(string $s, string $operator, int $index): int
    {
        $offset = 0;
        for ($i = 0; $i < $index; $i++) {
            $pos = strpos($s, $operator, $offset);
            if ($pos === false) {
                break;
            }
            $offset = $pos + 1;
        }

        return $offset;
    }

    /**
     * Parses one `shopField:dimensionRef` leaf.
     */
    private function _parseLeaf(string $leafText, int $position): DslLeaf
    {
        $colonPos = strpos($leafText, ':');
        if ($colonPos === false) {
            throw new DslParseException(
                "Missing ':' — a leaf must look like <shopField>:<dimension>.",
                position: $position
            );
        }

        $shopField = trim(substr($leafText, 0, $colonPos));
        $dimensionRef = trim(substr($leafText, $colonPos + 1));

        if ($shopField === '') {
            throw new DslParseException('Missing shop field before \':\'.', position: $position);
        }
        if ($dimensionRef === '') {
            throw new DslParseException('Missing dimension after \':\'.', shopField: $shopField, position: $position);
        }

        [$dimension, $variant] = $this->_parseDimensionRef($dimensionRef, $shopField, $position);

        if (!in_array($shopField, [DslPairingMatrix::SHOP_EAN, DslPairingMatrix::SHOP_MANUFACTURER_NR, DslPairingMatrix::SHOP_MANUFACTURER, DslPairingMatrix::SHOP_PRODUCT_NUMBER], true)
            && !str_starts_with($shopField, 'property.')
            && !str_starts_with($shopField, 'customField.')
        ) {
            throw new DslParseException(
                "Unknown shop field '{$shopField}' (expected product.ean, product.manufacturer_number, product.manufacturer, product.product_number, property.<group> or customField.<name>).",
                shopField: $shopField,
                dimension: $dimension,
                position: $position
            );
        }

        $kind = $this->_kindOf($shopField);
        if (!DslPairingMatrix::isAllowed($kind, $dimension)) {
            throw new DslParseException(
                "Pairing not allowed: '{$shopField}' cannot be matched against '{$dimension}'.",
                shopField: $shopField,
                dimension: $dimension,
                position: $position
            );
        }

        return new DslLeaf($shopField, $dimension, $variant);
    }

    /**
     * @return array{string, string|null} [base dimension, variant]
     */
    private function _parseDimensionRef(string $dimensionRef, string $shopField, int $position): array
    {
        if (!str_starts_with($dimensionRef, DslPairingMatrix::DIMENSION_ARTICLE_NUMBERS . '.')) {
            if (!in_array($dimensionRef, [DslPairingMatrix::DIMENSION_EAN, DslPairingMatrix::DIMENSION_MPN, DslPairingMatrix::DIMENSION_PCD, DslPairingMatrix::DIMENSION_ARTICLE_NUMBERS, DslPairingMatrix::DIMENSION_BRAND_IDS], true)) {
                throw new DslParseException(
                    "Unknown dimension '{$dimensionRef}' (expected ean, mpn, pcd, articleNumbers, articleNumbers.<providerId> or topdataBrandIds).",
                    shopField: $shopField,
                    dimension: $dimensionRef,
                    position: $position
                );
            }

            return [$dimensionRef, null];
        }

        $providerId = substr($dimensionRef, strlen(DslPairingMatrix::DIMENSION_ARTICLE_NUMBERS) + 1);
        if ($providerId === '' || !ctype_digit($providerId)) {
            throw new DslParseException(
                "Provider scope of 'articleNumbers' must be a numeric provider id (e.g. articleNumbers.4123), got '{$providerId}'.",
                shopField: $shopField,
                dimension: $dimensionRef,
                position: $position
            );
        }

        return [DslPairingMatrix::DIMENSION_ARTICLE_NUMBERS, $providerId];
    }

    /**
     * Maps a shopField to its matrix kind ('property.<x>' / 'customField.<x>'
     * share the property/customField matrix cells).
     */
    private function _kindOf(string $shopField): string
    {
        if (str_starts_with($shopField, 'property.')) {
            return DslPairingMatrix::SHOP_PROPERTY;
        }
        if (str_starts_with($shopField, 'customField.')) {
            return DslPairingMatrix::SHOP_CUSTOM_FIELD;
        }

        return $shopField;
    }
}