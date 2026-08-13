<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service;

/**
 * Statistics of a single mapping build run (tdmp_product / tdmp_brand).
 *
 * 08/2026 created
 */
class MappingBuildStats
{
    public function __construct(
        public readonly string $entity,
        public readonly int    $pages,
        public readonly int    $apiRows,
        public readonly int    $matched,
        public readonly int    $unmatched,
        public readonly float  $duration,
    ) {
    }
}
