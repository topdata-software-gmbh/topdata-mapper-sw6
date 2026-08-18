<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Dsl;

/**
 * AST node of the matching DSL: disjunction of AND groups (`a | b | ...`).
 * Evaluation = union of the per-group product sets. Also the node type of
 * parenthesized sub-expressions (nested as items of DslAndExpr).
 *
 * 08/2026 created, 08/2026 docblock: paren groups reuse this node
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