<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service;

/**
 * Strategy for turning one mapping-API row into tdmp_product rows.
 *
 * Implementations decide which identifier dimensions (ean/oem/pcd/distributor)
 * and which Shopware fields are compared, so the build service stays generic
 * and consumer plugins (e.g. TopFeed) can supply their own matching behavior.
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
     * @param object $row one mapping-API row ({products_id, ean?, oem?, pcd?, distributor?})
     *
     * @return list<array{product_id: string}> hex product ids of live-version products (no 0x prefix)
     */
    public function matchRow(object $row): array;
}
