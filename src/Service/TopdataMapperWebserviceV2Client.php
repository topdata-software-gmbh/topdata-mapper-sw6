<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataFoundationSW6\Helper\CurlHttpClient;
use Topdata\TopdataFoundationSW6\Helper\WebserviceV2Response;
use Topdata\TopdataFoundationSW6\Service\AbstractTopdataWebserviceV2Client;

class TopdataMapperWebserviceV2Client extends AbstractTopdataWebserviceV2Client
{
    private const string CONFIG_KEY = 'TopdataMapperSW6.config';

    public function __construct(SystemConfigService $systemConfigService)
    {
        parent::__construct($systemConfigService, self::CONFIG_KEY);
        $this->systemConfigService = $systemConfigService;
    }

    private readonly SystemConfigService $systemConfigService;

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
     * Fail-fast variant of getProviders() for the settings page init: the
     * default client retries errors 8x with exponential backoff (up to ~4min),
     * which would brick the module load when the provider endpoint errors.
     * Short timeout, no retries — callers treat failures as "no providers".
     */
    public function getProvidersFast(int $start, int $limit, string $language): mixed
    {
        $config = $this->systemConfigService->get(self::CONFIG_KEY);
        $url = rtrim((string)($config['apiBaseUrl'] ?? ''), '/')
            . '/v2/mapping/provider?'
            . http_build_query([
                'start'    => $start,
                'limit'    => $limit,
                'api_key'  => (string)($config['apiKey'] ?? ''),
                'language' => $language,
            ]);

        return WebserviceV2Response::unwrap((new CurlHttpClient(5, 0, 1))->get($url));
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