<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Dsl;

/**
 * AST root node of the matching DSL: disjunction of AND groups (`a | b | ...`).
 * Evaluation = union of the per-group product sets.
 *
 * 08/2026 created
 */
class DslOrExpr
{
    /**
     * @param DslAndExpr[] $groups at least one group (enforced by the parser)
     */
    public function __construct(public readonly array $groups)
    {
    }
}