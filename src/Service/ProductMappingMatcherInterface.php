<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service;

use Topdata\TopdataMapperSW6\Service\Dsl\DslOrExpr;

/**
 * Strategy for turning one mapping-API row into tdmp_product rows.
 *
 * Implementations decide which identifier dimensions (ean/mpn/pcd/
 * articleNumbers/topdataBrandIds) and which Shopware fields are compared, so
 * the build service stays generic and consumer plugins can supply their own
 * matching behavior. The Mapper's default is the DSL-driven
 * ProductMappingMatcher_Dsl.
 *
 * Matchers MUST only return live-version products (version_id = live version):
 * the build service pins every mapping to the live version on insert, enforced
 * by the fk_tdmp_product_product FK on (product_id, product_version_id).
 *
 * 08/2026 created
 */
interface ProductMappingMatcherInterface
{
    /**
     * Sets the parsed matching strategy the matcher must evaluate. The build
     * service calls this once per run — implementations without a DSL concept
     * may validate or ignore the strategy, but MUST not fail on it.
     */
    public function setStrategy(DslOrExpr $strategy): void;

    /**
     * @param object $row one mapping-API row ({topdataProductId, topdataBrandIds?, ean?, mpn?, pcd?, articleNumbers?})
     *
     * @return list<array{product_id: string}> hex product ids of live-version products (no 0x prefix)
     */
    public function matchRow(object $row): array;
}
