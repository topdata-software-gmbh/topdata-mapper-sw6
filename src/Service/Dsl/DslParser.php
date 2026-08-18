<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Dsl;

/**
 * Recursive-descent parser for the matching DSL (the single authoritative
 * parser — the settings page validates its DSL textarea against it, and the
 * import fails loudly on invalid stored strategies).
 *
 * Grammar (operator precedence: `( )` > `&` > `|`):
 * ```
 * strategy := orExpr
 * orExpr   := andExpr ('|' andExpr)*     // | = union of matched product sets
 * andExpr  := primary ('&' primary)*     // & = intersection
 * primary  := leaf | '(' orExpr ')'      // parens override precedence
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

        [$ast, $end] = $this->_parseOrExpr($dsl, 0);
        $rest = trim(substr($dsl, $end));
        if ($rest !== '') {
            if (str_starts_with($rest, ')')) {
                throw new DslParseException("Unexpected ')' — no matching '(' before it.", position: $end);
            }

            throw new DslParseException("Unexpected trailing input '{$rest}'.", position: $end);
        }

        return $ast;
    }

    /**
     * @return array{DslOrExpr, int} [expr, end-cursor]
     */
    private function _parseOrExpr(string $dsl, int $offset): array
    {
        $groups = [];
        do {
            [$group, $offset] = $this->_parseAndExpr($dsl, $offset);
            $groups[] = $group;
            [$offset] = $this->_skipWhitespace($dsl, $offset);
            if (($dsl[$offset] ?? '') !== '|') {
                break;
            }
            $offset++; // consume '|'
        } while (true);

        return [new DslOrExpr($groups), $offset];
    }

    /**
     * @return array{DslAndExpr, int} [expr, end-cursor]
     */
    private function _parseAndExpr(string $dsl, int $offset): array
    {
        $items = [];
        do {
            [$item, $offset] = $this->_parsePrimary($dsl, $offset);
            $items[] = $item;
            [$offset] = $this->_skipWhitespace($dsl, $offset);
            if (($dsl[$offset] ?? '') !== '&') {
                break;
            }
            $offset++; // consume '&'
        } while (true);

        return [new DslAndExpr($items), $offset];
    }

    /**
     * @return array{DslLeaf|DslOrExpr, int}
     */
    private function _parsePrimary(string $dsl, int $offset): array
    {
        [$offset] = $this->_skipWhitespace($dsl, $offset);
        $char = $dsl[$offset] ?? '';

        if ($char === '(') {
            [$inner, $offset] = $this->_parseOrExpr($dsl, $offset + 1);
            [$offset] = $this->_skipWhitespace($dsl, $offset);
            if (($dsl[$offset] ?? '') !== ')') {
                throw new DslParseException("Missing ')' — unclosed group.", position: $offset);
            }

            return [$inner, $offset + 1];
        }
        if ($char === ')') {
            throw new DslParseException("Unexpected ')' — no matching '(' before it.", position: $offset);
        }

        [$leafTextRaw, $offset] = $this->_scanLeafText($dsl, $offset);
        $leafText = trim($leafTextRaw);
        if ($leafText === '') {
            throw new DslParseException("Expected a leaf or '(' group, found nothing.", position: $offset - strlen($leafTextRaw));
        }

        return [$this->_parseLeaf($leafText, $offset - strlen($leafTextRaw)), $offset];
    }

    /**
     * @return array{string, int} [raw leaf text (untrimmed), end-cursor]
     */
    private function _scanLeafText(string $dsl, int $offset): array
    {
        $end = $offset;
        while (isset($dsl[$end]) && !in_array($dsl[$end], ['|', '&', '(', ')'], true)) {
            $end++;
        }

        return [substr($dsl, $offset, $end - $offset), $end];
    }

    /**
     * @return array{int} [offset after whitespace]
     */
    private function _skipWhitespace(string $dsl, int $offset): array
    {
        while (isset($dsl[$offset]) && ctype_space($dsl[$offset])) {
            $offset++;
        }

        return [$offset];
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