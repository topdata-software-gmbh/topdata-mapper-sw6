<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Dsl;

/**
 * Serializes a DSL AST back to the canonical DSL string (stored on save).
 * The frontend only sends/validates DSL strings — there is no JS serializer.
 * Nested `( ... )` groups are re-emitted verbatim (Phase 2).
 *
 * 08/2026 created, 08/2026 toArray() removed (visual builder removed)
 */
class DslSerializer
{
    public function toString(DslOrExpr $ast): string
    {
        $groups = [];
        foreach ($ast->groups as $group) {
            $parts = [];
            foreach ($group->items as $item) {
                $parts[] = $item instanceof DslLeaf
                    ? $this->_leafToString($item)
                    : '(' . $this->toString($item) . ')';
            }
            $groups[] = implode(' & ', $parts);
        }

        return implode(' | ', $groups);
    }

    private function _leafToString(DslLeaf $leaf): string
    {
        return $leaf->shopField . ':' . ($leaf->dimensionVariant !== null
            ? $leaf->dimension . '.' . $leaf->dimensionVariant
            : $leaf->dimension);
    }
}