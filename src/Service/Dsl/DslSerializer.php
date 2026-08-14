<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Dsl;

/**
 * Serializes a DSL AST back to the canonical DSL string. The frontend's visual
 * builder writes DSL via this serializer on the JS side (small mirror of the
 * grammar); PHP keeps the canonical form for validation.
 *
 * 08/2026 created
 */
class DslSerializer
{
    public function toString(DslOrExpr $ast): string
    {
        $groups = [];
        foreach ($ast->groups as $group) {
            $leaves = [];
            foreach ($group->leaves as $leaf) {
                $dimensionRef = $leaf->dimensionVariant !== null
                    ? $leaf->dimension . '.' . $leaf->dimensionVariant
                    : $leaf->dimension;
                $leaves[] = $leaf->shopField . ':' . $dimensionRef;
            }
            $groups[] = implode(' & ', $leaves);
        }

        return implode(' | ', $groups);
    }

    /**
     * AST as plain data for the settings page (builder re-render + validation
     * response): {groups: [{leaves: [{shopField, dimension, dimensionVariant}]}]}.
     */
    public function toArray(DslOrExpr $ast): array
    {
        $groups = [];
        foreach ($ast->groups as $group) {
            $leaves = [];
            foreach ($group->leaves as $leaf) {
                $leaves[] = [
                    'shopField'        => $leaf->shopField,
                    'dimension'        => $leaf->dimension,
                    'dimensionVariant' => $leaf->dimensionVariant,
                ];
            }
            $groups[] = ['leaves' => $leaves];
        }

        return ['groups' => $groups];
    }
}