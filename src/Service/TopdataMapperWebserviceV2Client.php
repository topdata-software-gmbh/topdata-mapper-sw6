<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataFoundationSW6\Service\AbstractTopdataWebserviceV2Client;

class TopdataMapperWebserviceV2Client extends AbstractTopdataWebserviceV2Client
{
    public function __construct(SystemConfigService $systemConfigService)
    {
        parent::__construct($systemConfigService, 'TopdataMapperSW6.config');
    }

    /**
     * Fetch product identifier mappings (bulk, unified v2 keyset pagination).
     * Response payload: {rows: [{topdataProductId, topdataBrandIds?, ean?, mpn?, pcd?, articleNumbers?}], pagination: {cursor, limit, next_cursor, has_more}}.
     * topdataBrandIds is present iff mpn is requested; articleNumbers is an
     * object keyed by provider id → per-provider article-number list.
     *
     * @param string[] $types identifier dimensions to include (ean/mpn/pcd/articleNumbers)
     * @param int|null $cursor last topdataProductId of the previous page (null = first page)
     */
    public function getProductMappings(array $types, ?int $cursor, int $limit, string $language): mixed
    {
        $params = [
            'types' => implode(',', $types),
            'limit' => $limit,
        ];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->httpGet('/mapping/product', $params, $language);
    }

    /**
     * Fetch the Topdata brand list (bulk, unified v2 pagination).
     * Response payload: {rows: [{id, val, ...}], pagination}.
     */
    public function getBrandMappings(int $start, int $limit, string $language): mixed
    {
        return $this->httpGet('/mapping/brand', [
            'start' => $start,
            'limit' => $limit,
        ], $language);
    }

    /**
     * Fetch the user's reserved providers (bulk, unified v2 pagination).
     * Response payload: {rows: [{id, name, synonym?}], pagination}.
     */
    public function getProviders(int $start, int $limit, string $language): mixed
    {
        return $this->httpGet('/mapping/provider', [
            'start' => $start,
            'limit' => $limit,
        ], $language);
    }

    /**
     * Ping endpoint for testConnection() — the mapper key has mapping access,
     * so /v2/mapping/brand is reachable (override the default /revision which
     * is feed-only).
     */
    protected function getPingEndpoint(): string
    {
        return '/mapping/brand';
    }
}