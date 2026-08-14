<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Dsl;

/**
 * AST node of the matching DSL: conjunction of leaves (`a & b & ...`).
 * Evaluation = intersection of the per-leaf product sets.
 *
 * 08/2026 created
 */
class DslAndExpr
{
    /**
     * @param DslLeaf[] $leaves at least one leaf (enforced by the parser)
     */
    public function __construct(public readonly array $leaves)
    {
    }
}