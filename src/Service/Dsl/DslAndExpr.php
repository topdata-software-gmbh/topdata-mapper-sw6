<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Dsl;

/**
 * AST node of the matching DSL: conjunction of operands (`a & b & ...`).
 * Evaluation = intersection of the per-operand product sets.
 *
 * Operands are leaves and/or parenthesized sub-expressions (DslOrExpr).
 *
 * 08/2026 created, 08/2026 leaves → items (parentheses support)
 */
class DslAndExpr
{
    /**
     * @param array<DslLeaf|DslOrExpr> $items at least one operand (enforced by the parser)
     */
    public function __construct(public readonly array $items)
    {
    }
}