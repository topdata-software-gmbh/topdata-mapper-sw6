<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Dsl;

/**
 * AST leaf node of the matching DSL: one `shopField:dimensionRef` comparison.
 *
 * shopField variants:
 * - product.ean / product.manufacturer_number / product.manufacturer / product.product_number
 * - property.<group>  (property group name, resolved against the shop)
 * - customField.<name> (custom field name, resolved against the shop)
 *
 * dimension variants:
 * - ean / mpn / pcd / articleNumbers / topdataBrandIds
 * - articleNumbers.<providerId> (provider-scoped article numbers)
 *
 * 08/2026 created
 */
class DslLeaf
{
    public function __construct(
        public readonly string $shopField,
        public readonly string $dimension,
        public readonly ?string $dimensionVariant = null,
    ) {
    }
}