---
filename: "_ai/backlog/active/260813_1245__IMPLEMENTATION_PLAN__mapper-plugin-core.md"
title: "TopdataMapperSW6: mapping tables (tdmp_product/tdmp_brand), webservice client, build service, import command"
createdAt: 2026-08-13 12:45
updatedAt: 2026-08-13 12:45
status: draft
priority: high
tags: [shopware, sw6-plugin, mapper, mapping, tdmp, foundation]
estimatedComplexity: complex
documentRevision: 1
documentType: IMPLEMENTATION_PLAN
---

# Problem

The shared Topdata-ID ↔ SW6-ID mapping (products and brands) needs a single owner so that **both** `topdata-topfeed-sw6-v9` and `topdata-topfinder-pro-sw6` can read it without depending on each other, and without duplicating table creation/migrations.

`TopdataMapperSW6` is that owner: a **real, installed** Shopware plugin (not build-time merged like foundation, because the release builder's tree-shaker never ships migrations). It owns the `tdmp_product` + `tdmp_brand` tables and is the **single writer**; feed and finder are pure readers.

The skeleton exists at `custom/plugins/topdata-mapper-sw6` (plugin class, empty services.xml/config.xml, example controllers/command). This plan turns it into the mapping engine.

# Executive Summary

Build the Mapper plugin core:

1. **Tables**: one migration creating `tdmp_product` + `tdmp_brand`.
2. **Credentials**: `config.xml` with its own `apiBaseUrl` + `apiKey`.
3. **Client**: `TopdataMapperWebserviceV2Client` (extends foundation client) hitting `/v2/mapping/product` + `/v2/mapping/brand`.
4. **Db helpers**: `TdmpProductService`, `TdmpBrandService` (insertMany / deleteAll / count / lookups).
5. **Build service**: `TdmpMappingBuildService` — the local matcher that turns webservice identifier data + Shopware catalog into mapping rows (the "build the mapping table" logic shared by nothing else, owned here).
6. **Command**: `topdata:mapper:import` with `--mapping=product|brand|all`.
7. Cleanup of the skeleton examples; housekeeping (README/CHANGELOG).

Feed/finder integration is **out of scope** for this plan (separate plans).

# Environment

```
- Project Name: SW6.7 Plugin (TopdataMapperSW6)
- Backend root: src
- PHP Version: 8.2 / 8.3 / 8.4
- Dependencies: shopware/core 6.7.*, topdata/topdata-foundation-sw6 ^1.4.0 (runtime)
```

# Conventions

- Private methods prefixed with `_`; class + method docblocks required (except getters/setters/constructors).
- Constructor property promotion with `private readonly`.
- Commands extend `Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand`; **all** CLI output via `CliLogger`; set `CliStyle` in the command.
- Services registered in `src/Resources/config/services.xml` (autowire).
- Table prefix `tdmp_` (Topdata Mapper).
- Migration classes extend `Shopware\Core\Framework\Migration\MigrationStep`.
- Config keys mirrored from the finder plugin (`apiBaseUrl` text, `apiKey` text `sk-...`).

---

# Phase 1 — Cleanup skeleton examples

Delete (example scaffolding from the skeleton — not used by the mapper):

- `src/Controller/StorefrontExampleController.php` → [DELETE]
- `src/Controller/AdminApiExampleController.php` → [DELETE]
- `src/Command/ExampleCommand.php` → [DELETE]
- `src/Resources/views/storefront/example.html.twig` → [DELETE]
- `src/Resources/views/storefront/` (empty dir) → [DELETE]

Update `src/Resources/config/services.xml` to drop the two controller service entries (see Phase 6 for the full new content).

Remove `routes.xml` **only if** it becomes empty/unused after the controller deletions (check its content first).

---

# Phase 2 — composer.json: depend on foundation

## 2.1 `composer.json` [MODIFY]

Add the foundation runtime requirement (dev/composer-only; the release builder strips it and merges foundation code at build time, as it does for the other plugins):

```json
"require": {
    "shopware/core": "6.7.*",
    "topdata/topdata-foundation-sw6": "^1.4.0"
},
```

# Phase 3 — Tables

## 3.1 New file: `src/Migration/Migration2608130000CreateTdmpTables.php` [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration2608130000CreateTdmpTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 2608130000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdmp_product` (
                `product_id`          binary(16) NOT NULL,
                `product_version_id`  binary(16) NOT NULL,
                `topdata_id`          bigint(20) NOT NULL,
                `created_at`          DATETIME(3) NOT NULL,
                `updated_at`          DATETIME(3) NOT NULL,
                PRIMARY KEY (`product_id`, `product_version_id`, `topdata_id`),
                KEY `idx_tdmp_product_topdata_id` (`topdata_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdmp_brand` (
                `brand_id`   binary(16) NOT NULL,
                `topdata_id` bigint(20) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`brand_id`, `topdata_id`),
                KEY `idx_tdmp_brand_topdata_id` (`topdata_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
```

> `CREATE TABLE IF NOT EXISTS` keeps installs safe if another plugin ever shipped the same schema (they won't — this is the single owner).

---

# Phase 4 — Config

## 4.1 `src/Resources/config/config.xml` [MODIFY]

Replace the placeholder `example` field with the webservice credentials (mirror the finder plugin's fields):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/platform/trunk/src/Core/System/SystemConfig/Schema/config.xsd">
    <card>
        <title>Webservice</title>
        <title lang="de-DE">Webservice</title>

        <input-field type="text">
            <name>apiBaseUrl</name>
            <label>API Base URL</label>
            <label lang="de-DE">API Basis-URL</label>
            <placeholder>https://ws.topdata.de</placeholder>
        </input-field>

        <input-field type="text">
            <name>apiKey</name>
            <label>API Key (sk-...)</label>
            <label lang="de-DE">API-Schlüssel (sk-...)</label>
            <placeholder>sk-...</placeholder>
        </input-field>
    </card>
</config>
```

---

# Phase 5 — Webservice client

## 5.1 New file: `src/Service/TopdataMapperWebserviceV2Client.php` [NEW FILE]

```php
<?php declare(strict_types=1);

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
     * Fetch product identifier mappings (bulk, unified v2 pagination).
     * Response payload: {rows: [{products_id, ean?, oem?, pcd?, distributor?}], pagination}.
     *
     * @param string[] $types identifier dimensions to include
     */
    public function getProductMappings(array $types, int $start, int $limit, string $language): mixed
    {
        return $this->httpGet('/mapping/product', [
            'types' => implode(',', $types),
            'start' => $start,
            'limit' => $limit,
        ], $language);
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
     * Ping endpoint for testConnection() — the mapper key has mapping access,
     * so /v2/mapping/brand is reachable (override the default /revision which
     * is feed-only).
     */
    protected function getPingEndpoint(): string
    {
        return '/mapping/brand';
    }
}
```

---

# Phase 6 — DB helpers + build service + command

## 6.1 New file: `src/Service/Db/TdmpProductService.php` [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Db;

use Doctrine\DBAL\Connection;

/**
 * Raw DBAL access to `tdmp_product` (Topdata products_id ↔ SW6 product id).
 */
class TdmpProductService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<int, array{product_id: string, product_version_id: string, topdata_id: int, created_at: string, updated_at: string}> $rows product_id/version_id are binary(16) bytes
     */
    public function insertMany(array $rows): int
    {
        $inserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $inserted += $this->connection->executeStatement(
                'INSERT INTO tdmp_product (product_id, product_version_id, topdata_id, created_at, updated_at) VALUES ' .
                implode(',', array_map(
                    fn (array $r) => "(0x{$r['product_id']}, 0x{$r['product_version_id']}, {$r['topdata_id']}, '{$r['created_at']}', '{$r['updated_at']}')",
                    $chunk
                ))
            );
        }

        return $inserted;
    }

    public function deleteAll(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE tdmp_product');
    }

    public function count(): int
    {
        return (int)$this->connection->fetchOne('SELECT COUNT(*) FROM tdmp_product');
    }

    /**
     * Map topdata_id (int) → list of SW6 product ids (hex).
     *
     * @return array<int, list<string>>
     */
    public function getProductMap(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT topdata_id, LOWER(HEX(product_id)) AS product_id FROM tdmp_product'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['topdata_id']][] = $row['product_id'];
        }

        return $map;
    }
}
```

## 6.2 New file: `src/Service/Db/TdmpBrandService.php` [NEW FILE]

Same pattern as `TdmpProductService` for `tdmp_brand`:

- `insertMany(array $rows)` — rows `{brand_id: bytes-hex, topdata_id: int, created_at, updated_at}` → `INSERT INTO tdmp_brand (brand_id, topdata_id, created_at, updated_at)`.
- `deleteAll()`, `count()`, `getBrandMap()` (`[topdata_id => brand_id hex]`).

## 6.3 New file: `src/Service/TdmpMappingBuildService.php` [NEW FILE]

The core matcher. It is **purely local** (no webservice calls of its own — the client data is passed in), so it stays unit-testable.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service;

use Doctrine\DBAL\Connection;
use Topdata\TopdataMapperSW6\Helper\UtilIdentifierNormalizer;
use Topdata\TopdataMapperSW6\Service\Db\TdmpProductService;
use Topdata\TopdataMapperSW6\Service\Db\TdmpBrandService;
use Topdata\TopdataMapperSW6\Service\TopdataMapperWebserviceV2Client;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Builds the tdmp_product / tdmp_brand mapping tables.
 *
 * Flow per entity: fetch the bulk data from the mapping API, normalize the
 * shop-side identifiers, match, then full-table replace (single writer).
 *
 * 08/2026 created
 */
class TdmpMappingBuildService
{
    public const int PRODUCT_PAGE_SIZE = 5000;
    public const int BRAND_PAGE_SIZE   = 5000;

    public const array PRODUCT_TYPES = ['ean', 'oem', 'pcd', 'distributor'];

    public function __construct(
        private readonly TdmpProductService       $tdmpProductService,
        private readonly TdmpBrandService         $tdmpBrandService,
        private readonly TopdataMapperWebserviceV2Client $mapperClient,
        private readonly Connection                $connection,
    ) {
    }

    public function buildProductMappings(string $language = 'de'): int
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        // 1. shop-side identifier maps (normalized value → product keys)
        $shopEanMap = $this->_loadShopIdentifierMap('ean');     // normalized ean → [product_id, version_id]
        $shopOemMap = $this->_loadShopIdentifierMap('mpn');     // manufacturer_number

        // 2. stream mapping API pages
        $insert = [];
        $start  = 0;
        while (true) {
            $page = $this->mapperClient->getProductMappings(self::PRODUCT_TYPES, $start, self::PRODUCT_PAGE_SIZE, $language);
            $rows = $page->rows ?? [];
            if (count($rows) === 0) {
                break;
            }

            foreach ($rows as $row) {
                $productId  = (int)$row->products_id;
                $matched    = $this->_matchProductRow($row, $shopEanMap, $shopOemMap);
                foreach ($matched as [$productIdBytes, $versionIdBytes]) {
                    $insert[] = [
                        'product_id'         => $productIdBytes,
                        'product_version_id' => $versionIdBytes,
                        'topdata_id'         => $productId,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ];
                }
            }

            if (!isset($page->pagination->has_more) || !$page->pagination->has_more) {
                break;
            }
            $start += self::PRODUCT_PAGE_SIZE;
        }

        $this->tdmpProductService->deleteAll();
        $count = $this->tdmpProductService->insertMany($insert);
        CliLogger::info("Built tdmp_product: {$count} rows (products_id).");

        return $count;
    }

    public function buildBrandMappings(string $language = 'de'): int
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        // shop-side brand map: normalized manufacturer name → brand_id bytes
        $shopBrandMap = $this->_loadShopBrandMap();

        $insert = [];
        $start  = 0;
        while (true) {
            $page = $this->mapperClient->getBrandMappings($start, self::BRAND_PAGE_SIZE, $language);
            $rows = $page->rows ?? [];
            if (count($rows) === 0) {
                break;
            }

            foreach ($rows as $row) {
                $brandId = $shopBrandMap[UtilIdentifierNormalizer::normalizeLabel($row->val)] ?? null;
                if ($brandId === null) {
                    continue;
                }
                $insert[] = [
                    'brand_id'   => $brandId,
                    'topdata_id' => (int)$row->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!isset($page->pagination->has_more) || !$page->pagination->has_more) {
                break;
            }
            $start += self::BRAND_PAGE_SIZE;
        }

        $this->tdmpBrandService->deleteAll();
        $count = $this->tdmpBrandService->insertMany($insert);
        CliLogger::info("Built tdmp_brand: {$count} rows.");

        return $count;
    }

    /**
     * Match one mapping row against the shop identifier maps.
     * Returns list of [product_id_bytes, version_id_bytes].
     */
    private function _matchProductRow(object $row, array $shopEanMap, array $shopOemMap): array
    {
        $matches = [];

        foreach ($row->ean ?? [] as $value) {
            $key = UtilIdentifierNormalizer::normalizeEan($value);
            if (isset($shopEanMap[$key])) {
                foreach ($shopEanMap[$key] as $product) {
                    $matches[] = $product;
                }
            }
        }
        foreach (($row->oem ?? []) as $value) {
            $key = UtilIdentifierNormalizer::normalizeMpn($value);
            if (isset($shopOemMap[$key])) {
                foreach ($shopOemMap[$key] as $product) {
                    $matches[] = $product;
                }
            }
        }

        return $matches;
    }

    private function _loadShopIdentifierMap(string $kind): array
    {
        // 'ean' → product.ean ; 'mpn' → product.manufacturer_number
        $column = $kind === 'mpn' ? 'manufacturer_number' : 'ean';

        $rows = $this->connection->fetchAllAssociative(
            "SELECT LOWER(HEX(id)) AS product_id, LOWER(HEX(version_id)) AS product_version_id, {$column} AS identifier
               FROM product
              WHERE {$column} IS NOT NULL AND {$column} <> ''"
        );

        $map = [];
        foreach ($rows as $row) {
            $normalized = $kind === 'mpn'
                ? UtilIdentifierNormalizer::normalizeMpn($row['identifier'])
                : UtilIdentifierNormalizer::normalizeEan($row['identifier']);
            if ($normalized === '') {
                continue;
            }
            $map[$normalized][] = [$row['product_id'], $row['product_version_id']];
        }

        return $map;
    }

    private function _loadShopBrandMap(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS brand_id, name FROM product_manufacturer WHERE name IS NOT NULL'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[UtilIdentifierNormalizer::normalizeLabel($row['name'])] = $row['brand_id'];
        }

        return $map;
    }
}
```

> **Strategy note (SOLID):** keep the identifier-matching strategy pluggable from the start. Extract the `_matchProductRow`/`_loadShopIdentifierMap` pair behind a small `ProductMappingMatcherInterface` if a second matching strategy (custom-field based, as TopFeed's `MappingTypeConstants::CUSTOM_FIELD`) is needed now. YAGNI default: the concrete matcher above; refactor when TopFeed's custom-field strategy is wired (TopFeed plan).

## 6.4 New file: `src/Helper/UtilIdentifierNormalizer.php` [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Helper;

/**
 * Identifier normalization for matching (mirrors TopFeed's UtilMappingHelper):
 * EAN → digits-only; MPN/OEM → lowercase, no surrounding space; labels → lowercase trimmed.
 */
final class UtilIdentifierNormalizer
{
    public static function normalizeEan(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    public static function normalizeMpn(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function normalizeLabel(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }
}
```

## 6.5 New file: `src/Command/Command_TdmpImport.php` [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Helper\CliStyle;
use Topdata\TopdataFoundationSW6\Service\CliApiCredentialPrompter;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataMapperSW6\Service\TdmpMappingBuildService;
use Topdata\TopdataMapperSW6\Service\TopdataMapperWebserviceV2Client;

#[AsCommand(name: 'topdata:mapper:import', description: 'Build the Topdata↔SW6 mapping tables (tdmp_product, tdmp_brand)')]
class Command_TdmpImport extends AbstractTopdataCommand
{
    public function __construct(
        private readonly TdmpMappingBuildService          $mappingBuildService,
        private readonly TopdataMapperWebserviceV2Client   $mapperClient,
        private readonly CliApiCredentialPrompter         $credentialPrompter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'mapping',
            'm',
            InputOption::VALUE_REQUIRED,
            'Which mapping to build: product | brand | all',
            'all'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cliStyle = new CliStyle($input, $output);
        CliLogger::setCliStyle($this->cliStyle);
        CliLogger::section('Topdata Mapper: import mapping data');

        if (!$this->credentialPrompter->ensureValidApiConfig($input, $output, $this->mapperClient, 'TopdataMapperSW6')) {
            CliLogger::error('API credentials are not configured. Please set API Base URL and API Key (sk-...) in the plugin configuration.');

            return self::FAILURE;
        }

        $mapping = strtolower($input->getOption('mapping'));

        match ($mapping) {
            'product' => $this->mappingBuildService->buildProductMappings(),
            'brand'   => $this->mappingBuildService->buildBrandMappings(),
            'all'     => $this->_buildAll(),
            default   => throw new \InvalidArgumentException("Unknown --mapping value '{$mapping}' (product|brand|all)"),
        };

        return self::SUCCESS;
    }

    private function _buildAll(): void
    {
        $this->mappingBuildService->buildProductMappings();
        $this->mappingBuildService->buildBrandMappings();
    }
}
```

---

# Phase 7 — services.xml

## 7.1 `src/Resources/config/services.xml` [MODIFY]

```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <service id="Topdata\TopdataMapperSW6\Service\TopdataMapperWebserviceV2Client" autowire="true"/>
        <service id="Topdata\TopdataMapperSW6\Service\Db\TdmpProductService" autowire="true"/>
        <service id="Topdata\TopdataMapperSW6\Service\Db\TdmpBrandService" autowire="true"/>
        <service id="Topdata\TopdataMapperSW6\Service\TdmpMappingBuildService" autowire="true"/>

        <service id="Topdata\TopdataMapperSW6\Command\Command_TdmpImport" autowire="true">
            <tag name="console.command"/>
        </service>
    </services>
</container>
```

> Remove the two example-controller service entries from the skeleton.

---

# Phase 8 — Housekeeping

## 8.1 README.md [MODIFY]

Replace skeleton placeholder text with: purpose (owns `tdmp_product`/`tdmp_brand`), the `topdata:mapper:import` command, the mapping API endpoints used, and a note that TopFeed/TopFinder consume these tables.

## 8.2 CHANGELOG.md [MODIFY]

Add `1.0.0` (or Unreleased) entries: mapping tables, mapper import command, webservice client, config keys.

## 8.3 .gitignore [VERIFY]

No new artifact types expected. Ensure `vendor/`, `var/` etc. are covered; add nothing unless introduced.

## 8.4 Verification

```bash
php -l src/Migration/Migration2608130000CreateTdmpTables.php
# …and all new files
docker exec focus-www rm -rf /www/var/cache/*
bin/console plugin:refresh && bin/console plugin:install --activate TopdataMapperSW6
bin/console topdata:mapper:import --mapping=all
```

Live check: `bin/console topdata:mapper:import` populates `tdmp_product` (>0 rows when products are mapped) and `tdmp_brand` (or warns).

# Phase 9 — Report

Write `_ai/backlog/reports/260813_1245__IMPLEMENTATION_REPORT__mapper-plugin-core.md` per the report template.

## Definition of Done

- [ ] Skeleton examples removed; no dead scaffolding
- [ ] `tdmp_product` + `tdmp_brand` created by migration
- [ ] Own `apiBaseUrl`/`apiKey` config
- [ ] `topdata:mapper:import` builds both tables from `/v2/mapping/*`
- [ ] Foundation requirement in composer.json; services.xml registers all services
- [ ] php -l clean; plugin installs + activates
