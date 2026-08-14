---
filename: "_ai/backlog/active/260814_1533__IMPLEMENTATION_PLAN__mapper-admin-navigation-group.md"
title: "TopdataMapperSW6: new \"Katalog > Topdata Mapper\" navigation group with conflicts page + mappings browser"
createdAt: 2026-08-14 15:33
updatedAt: 2026-08-14 15:33
status: draft
priority: medium
tags: [shopware, sw6-plugin, mapper, admin, navigation, mapping-browser]
estimatedComplexity: moderate
documentRevision: 1
documentType: IMPLEMENTATION_PLAN
---

# Problem

The mapper's admin UI currently lives in two disconnected places:

- **Settings** (`topdata.mapper.settings`, under *Einstellungen > Plugins*) — strategy editor, stays where it is.
- **Conflicts** (`topdata.mapper.conflicts`, under *Katalog > Produkte*, `parent: 'sw-product'`) — the conflict resolution grid.

The conflicts page is buried under the Products navigation and there is **no way to browse the actual mapping data** (`tdmp_product` / `tdmp_brand`) in the admin. A shop operator cannot answer "which Topdata article is this SW6 product mapped to?" without SQL.

The goal: a new navigation group **"Katalog > Topdata Mapper"** (German admin: *Katalog > Topdata Mapper*) that contains the existing conflicts page (moved) and a new **mappings browser** page listing all product mappings (SW6 product ↔ Topdata article) and — per confirmed scope — a second tab for brand mappings (SW6 manufacturer ↔ Topdata brand).

# Executive Summary

1. **New parent module** `topdata-mapper` — navigation-only group entry under `sw-catalog` ("Topdata Mapper", position 10), its click path points at the new mappings page so the group label is always navigable.
2. **New module** `topdata-mapper-mappings` — a read-only browser page with two tabs:
   - *Products*: `sw-data-grid` over `tdmp_product` joined with `product`/`product_translation`/`media_thumbnail` (product number, name, thumbnail, Topdata article id, created/updated), server-side paginated + search (product number/name/Topdata id).
   - *Brands*: `sw-data-grid` over `tdmp_brand` joined with `product_manufacturer`/`product_manufacturer_translation` (manufacturer name, Topdata brand id, created/updated), server-side paginated + search.
3. **New backend** `TdmpMappingBrowseService` (raw DBAL, follows the existing `Tdmp*Service` pattern) + two `_action` routes on `TopdataMapperActionController` (`GET /api/_action/topdata-mapper/mappings`, `GET /api/_action/topdata-mapper/brands`), both guarded by `topdata_mapper:read`.
4. **Relocate conflicts**: navigation `parent` changes `sw-product` → `topdata-mapper`, label becomes "Konflikte"/"Conflicts"; route/module ids stay unchanged (no link breakage).
5. **Housekeeping**: rebuild admin assets, CHANGELOG entry. No new composer deps, no migrations, no config changes.

SOLID notes: the browse read-model lives in its own service (`TdmpMappingBrowseService` — single responsibility); the controller only orchestrates (delegates pagination/filtering); JS modules stay per-page with shared snippets under `TopdataMapperSW6.*`.

# Environment

```
- Project Name: SW6.7 Plugin (TopdataMapperSW6)
- Backend root: src
- PHP Version: 8.2 / 8.3 / 8.4
- Dependencies: shopware/core 6.7.*, topdata/topdata-foundation-sw6 ^1.4.0 (runtime, unchanged)
- Admin build: bin/build-administration.sh from the Shopware root (compiled assets are gitignored)
```

# Conventions

- Private methods prefixed with `_`; class + method docblocks required; constructor property promotion with `private readonly`.
- Raw DBAL services in `src/Service/Db/`, UUIDs as lowercase hex without `0x`; read lookups use `LOWER(HEX(id))`; live-version pinning via `TdmpProductService::LIVE_VERSION_HEX`.
- Controller extends `Symfony\Bundle\FrameworkBundle\Controller\AbstractController`, routes via `#[Route]` with `_routeScope: api`, privilege check via `_assertPrivilege` (existing private helper).
- Admin modules follow the existing `topdata-mapper-conflicts` layout (module `index.js`, `page/…/`, `snippet/{en-GB,de-DE}.json`).
- Snippet keys namespaced under `TopdataMapperSW6` (PascalCase root).
- Tables prefix `tdmp_`; the plugin abbreviation `tdmp` is used for CSS classes (`tdmp-mappings__*`, BEM).

---

# Phase 1 — Backend: mappings/brands read services

## 1.1 `src/Service/Db/TdmpMappingBrowseService.php` [NEW FILE]

Read-only DBAL service for the admin mappings browser. Mirrors the style of `TdmpConflictResolutionService::listConflicts()` (WHERE building, COUNT + page query, LIMIT/OFFSET). Both list methods share the `_paginate()` helper (DRY, open/closed principle — adding a third list later only needs a query builder).

```php
<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Db;

use Doctrine\DBAL\Connection;

/**
 * Read-only DBAL access for the admin mappings browser
 * (tdmp_product / tdmp_brand grids on the "Topdata Mapper" navigation group).
 *
 * Both grids are server-side paginated + searchable — the mapping tables can
 * hold tens of thousands of rows, so no client-side loading ever happens.
 *
 * 08/2026 created
 */
class TdmpMappingBrowseService
{
    private const MAX_LIMIT = 500;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Paginated product mappings (tdmp_product) enriched with SW6 product
     * number/name/thumbnail. Search matches product number, product name or
     * the Topdata article id.
     *
     * @return array{rows: list<array{productId: string, productNumber: string, productName: string, thumbnailUrl: ?string, topdataProductId: int, createdAt: string, updatedAt: string}>, total: int}
     */
    public function listProductMappings(int $page, int $limit, ?string $search): array
    {
        $where   = ['p.version_id = 0x' . TdmpProductService::LIVE_VERSION_HEX];
        $params  = [];

        if ($search !== null && $search !== '') {
            $where[]  = '(p.product_number LIKE ? OR pt.name LIKE ? OR mp.topdata_product_id = ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = (int)$search === 0 ? 0 : (int)$search; // numeric search matches the article id
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(*)
               FROM tdmp_product mp
               JOIN product p ON p.id = mp.product_id AND p.version_id = mp.product_version_id
               LEFT JOIN product_translation pt
                 ON pt.product_id = mp.product_id AND pt.product_version_id = mp.product_version_id
              WHERE {$whereSql}",
            $params
        );

        [$sql, $sqlParams] = $this->_buildPageSql(
            "SELECT LOWER(HEX(mp.product_id)) AS product_id,
                    p.product_number AS product_number,
                    pt.name AS product_name,
                    mt.url AS thumbnail_url,
                    mp.topdata_product_id,
                    mp.created_at,
                    mp.updated_at
               FROM tdmp_product mp
               JOIN product p ON p.id = mp.product_id AND p.version_id = mp.product_version_id
               LEFT JOIN product_translation pt
                 ON pt.product_id = mp.product_id AND pt.product_version_id = mp.product_version_id
               LEFT JOIN media_thumbnail mt ON mt.media_id = p.cover_id
              WHERE {$whereSql}
              ORDER BY p.product_number ASC",
            $params,
            $page,
            $limit
        );

        $rows = $this->connection->fetchAllAssociative($sql, $sqlParams);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'productId'        => $row['product_id'],
                'productNumber'    => (string)$row['product_number'],
                'productName'      => (string)($row['product_name'] ?? ''),
                'thumbnailUrl'     => $row['thumbnail_url'] !== null ? (string)$row['thumbnail_url'] : null,
                'topdataProductId' => (int)$row['topdata_product_id'],
                'createdAt'        => (string)$row['created_at'],
                'updatedAt'        => (string)$row['updated_at'],
            ];
        }

        return ['rows' => $result, 'total' => $total];
    }

    /**
     * Paginated brand mappings (tdmp_brand) enriched with the SW6 manufacturer
     * name. Search matches the manufacturer name or the Topdata brand id.
     *
     * @return array{rows: list<array{brandId: string, manufacturerName: string, topdataBrandId: int, createdAt: string, updatedAt: string}>, total: int}
     */
    public function listBrandMappings(int $page, int $limit, ?string $search): array
    {
        $where   = [];
        $params  = [];

        if ($search !== null && $search !== '') {
            $where[]  = '(pmt.name LIKE ? OR mb.topdata_brand_id = ?)';
            $params[] = '%' . $search . '%';
            $params[] = (int)$search === 0 ? 0 : (int)$search;
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(*)
               FROM tdmp_brand mb
               JOIN product_manufacturer pm ON pm.id = mb.brand_id
               LEFT JOIN product_manufacturer_translation pmt
                 ON pmt.product_manufacturer_id = mb.brand_id
              {$whereSql}",
            $params
        );

        [$sql, $sqlParams] = $this->_buildPageSql(
            "SELECT LOWER(HEX(mb.brand_id)) AS brand_id,
                    pmt.name AS manufacturer_name,
                    mb.topdata_brand_id,
                    mb.created_at,
                    mb.updated_at
               FROM tdmp_brand mb
               JOIN product_manufacturer pm ON pm.id = mb.brand_id
               LEFT JOIN product_manufacturer_translation pmt
                 ON pmt.product_manufacturer_id = mb.brand_id
              {$whereSql}
              ORDER BY manufacturer_name ASC",
            $params,
            $page,
            $limit
        );

        $rows = $this->connection->fetchAllAssociative($sql, $sqlParams);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'brandId'        => $row['brand_id'],
                'manufacturerName' => (string)($row['manufacturer_name'] ?? ''),
                'topdataBrandId' => (int)$row['topdata_brand_id'],
                'createdAt'      => (string)$row['created_at'],
                'updatedAt'      => (string)$row['updated_at'],
            ];
        }

        return ['rows' => $result, 'total' => $total];
    }

    /**
     * Applies page/limit to a SELECT and returns (sql, params) — the paging
     * contract shared by both grids.
     *
     * @param list<mixed> $params
     * @return array{0: string, 1: list<mixed>}
     */
    private function _buildPageSql(string $sql, array $params, int $page, int $limit): array
    {
        $limit  = min(max(1, $limit), self::MAX_LIMIT);
        $offset = max(0, ($page - 1) * $limit);

        return [$sql . " LIMIT {$limit} OFFSET {$offset}", $params];
    }
}
```

## 1.2 `src/Resources/config/services.xml` [MODIFY]

Register the new service (autowire; the constructor only needs the `Connection`, which autowiring resolves):

```xml
<service id="Topdata\TopdataMapperSW6\Service\Db\TdmpMappingBrowseService" autowire="true"/>
```

---

# Phase 2 — Backend: API action routes

## 2.1 `src/Controller/Api/TopdataMapperActionController.php` [MODIFY]

- Add the import `use Topdata\TopdataMapperSW6\Service\Db\TdmpMappingBrowseService;`.
- Add the constructor parameter `private readonly TdmpMappingBrowseService $mappingBrowseService` (autowired).
- Add two GET routes. Both reuse the existing `_assertPrivilege('topdata_mapper:read', $context)` helper — no new privileges needed (`acl.xml` unchanged).

```php
/**
 * Product mappings grid for the mappings browser (read-only). Server-side
 * paginated + searchable (product number / name / Topdata article id).
 */
#[Route(path: '/api/_action/topdata-mapper/mappings', name: 'api.action.topdata-mapper.mappings.list', methods: ['GET'])]
public function listMappingsAction(Request $request, Context $context): JsonResponse
{
    $this->_assertPrivilege('topdata_mapper:read', $context);

    $page   = max(1, (int)$request->get('page', 1));
    $limit  = (int)$request->get('limit', 25);
    $search = $request->get('search');

    $result = $this->mappingBrowseService->listProductMappings(
        $page,
        $limit,
        is_string($search) ? $search : null
    );

    return new JsonResponse([
        'rows'  => $result['rows'],
        'total' => $result['total'],
        'page'  => $page,
        'limit' => $limit,
    ]);
}

/**
 * Brand mappings grid for the mappings browser (read-only). Server-side
 * paginated + searchable (manufacturer name / Topdata brand id).
 */
#[Route(path: '/api/_action/topdata-mapper/brands', name: 'api.action.topdata-mapper.brands.list', methods: ['GET'])]
public function listBrandMappingsAction(Request $request, Context $context): JsonResponse
{
    $this->_assertPrivilege('topdata_mapper:read', $context);

    $page   = max(1, (int)$request->get('page', 1));
    $limit  = (int)$request->get('limit', 25);
    $search = $request->get('search');

    $result = $this->mappingBrowseService->listBrandMappings(
        $page,
        $limit,
        is_string($search) ? $search : null
    );

    return new JsonResponse([
        'rows'  => $result['rows'],
        'total' => $result['total'],
        'page'  => $page,
        'limit' => $limit,
    ]);
}
```

> Note: keep the numeric-search coercion `(int)$search === 0 ? 0 : (int)$search` as written in the service — a non-numeric search string must not accidentally match `topdata_product_id = 0`.

---

# Phase 3 — Admin: new navigation group module

## 3.1 `src/Resources/app/administration/src/module/topdata-mapper/index.js` [NEW FILE]

Navigation-only parent module. It has no own page — its navigation `path` points at the first child (the mappings browser) so the group label is clickable. The child modules attach via `parent: 'topdata-mapper'`.

```js
// ---- snippets (shared nav labels for children) ----
import './snippet/en-GB.json';
import './snippet/de-DE.json';

// ---- register module (navigation group only) ----
Shopware.Module.register('topdata-mapper', {
    type: 'plugin',
    name: 'TopdataMapperSW6',
    title: 'TopdataMapperSW6.navigation.title',
    description: 'TopdataMapperSW6.navigation.description',
    color: '#ff3d58',
    icon: 'regular-plug',

    routes: {},

    navigation: [
        {
            label: 'TopdataMapperSW6.navigation.title',
            color: '#ff3d58',
            path: 'topdata.mapper.mappings.index',
            icon: 'regular-plug',
            position: 10,
            parent: 'sw-catalog',
        },
    ],
});
```

## 3.2 `src/Resources/app/administration/src/module/topdata-mapper/snippet/en-GB.json` [NEW FILE]

```json
{
    "TopdataMapperSW6": {
        "navigation": {
            "title": "Topdata Mapper",
            "description": "Browse mappings and resolve conflicts"
        }
    }
}
```

## 3.3 `src/Resources/app/administration/src/module/topdata-mapper/snippet/de-DE.json` [NEW FILE]

```json
{
    "TopdataMapperSW6": {
        "navigation": {
            "title": "Topdata Mapper",
            "description": "Mappings durchsuchen und Konflikte auflösen"
        }
    }
}
```

> The child modules use their own short labels ("Mappings", "Konflikte"/"Conflicts"); the group label is provided once by the parent module's `TopdataMapperSW6.navigation.*` keys, keeping the group name consistent.

---

# Phase 4 — Admin: mappings browser module

## 4.1 `src/Resources/app/administration/src/module/topdata-mapper-mappings/index.js` [NEW FILE]

```js
// ---- page ----
import './page/topdata-mapper-mappings';

// ---- snippets ----
import './snippet/en-GB.json';
import './snippet/de-DE.json';

// ---- register module ----
Shopware.Module.register('topdata-mapper-mappings', {
    type: 'plugin',
    name: 'TopdataMapperSW6',
    title: 'TopdataMapperSW6.mappings.title',
    description: 'TopdataMapperSW6.mappings.description',
    color: '#ff3d58',
    icon: 'regular-plug',

    routes: {
        index: {
            component: 'topdata-mapper-mappings',
            path: 'mappings',
            meta: {
                privilege: 'topdata_mapper:read',
            },
        },
    },

    navigation: [
        {
            label: 'TopdataMapperSW6.mappings.title',
            color: '#ff3d58',
            path: 'topdata.mapper.mappings.index',
            icon: 'regular-list',
            position: 10,
            parent: 'topdata-mapper',
        },
    ],
});
```

> Note: **no** `meta.parentPath` on either child route — the parent module is route-less (`topdata.mapper.index` does not exist) and a dangling `parentPath` would break breadcrumb/browser-title resolution. Without it the breadcrumb simply shows the page title; the navigation tree still renders the group correctly.

## 4.2 `src/Resources/app/administration/src/module/topdata-mapper-mappings/page/topdata-mapper-mappings/index.js` [NEW FILE]

Component with a tabbed grid (Products / Brands). Each tab is its own small state machine (page/limit/search/total/rows) sharing one `load()` that dispatches by `activeTab` — read-only, so no resolve/notification logic needed.

```js
import template from './topdata-mapper-mappings.html.twig';
import './topdata-mapper-mappings.scss';

const { Component } = Shopware;

/**
 * Mappings browser (Topdata Mapper navigation group).
 *
 * Read-only grid over tdmp_product / tdmp_brand — server-side paginated and
 * searchable via the mapper API service. Two tabs: product mappings and brand
 * mappings.
 *
 * 08/2026 created
 */
Component.register('topdata-mapper-mappings', {
    template,

    inject: ['TopdataMapperApiService'],

    data: () => ({
        activeTab: 'products',
        rows: [],
        total: 0,
        page: 1,
        limit: 25,
        search: '',
        isLoading: true,
    }),

    computed: {
        columns() {
            if (this.activeTab === 'products') {
                return [
                    { property: 'productNumber', label: this.$tc('TopdataMapperSW6.mappings.columns.product'), sortable: false },
                    { property: 'topdataProductId', label: this.$tc('TopdataMapperSW6.mappings.columns.topdataId'), sortable: false },
                    { property: 'createdAt', label: this.$tc('TopdataMapperSW6.mappings.columns.createdAt'), sortable: false },
                    { property: 'updatedAt', label: this.$tc('TopdataMapperSW6.mappings.columns.updatedAt'), sortable: false },
                ];
            }

            return [
                { property: 'manufacturerName', label: this.$tc('TopdataMapperSW6.mappings.columns.manufacturer'), sortable: false },
                { property: 'topdataBrandId', label: this.$tc('TopdataMapperSW6.mappings.columns.topdataId'), sortable: false },
                { property: 'createdAt', label: this.$tc('TopdataMapperSW6.mappings.columns.createdAt'), sortable: false },
                { property: 'updatedAt', label: this.$tc('TopdataMapperSW6.mappings.columns.updatedAt'), sortable: false },
            ];
        },
    },

    created() {
        this.debouncedSearch = Shopware.Utils.debounce(this.load, 400);
        this.load();
    },

    methods: {
        load() {
            this.isLoading = true;

            const params = {
                page: this.page,
                limit: this.limit,
                search: this.search,
            };

            const request = this.activeTab === 'products'
                ? this.TopdataMapperApiService.fetchMappings(params)
                : this.TopdataMapperApiService.fetchBrands(params);

            return request
                .then((response) => {
                    this.rows = response.data.rows;
                    this.total = response.data.total;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        onTabChange(tab) {
            this.activeTab = tab.name || tab;
            this.page = 1;
            this.search = '';
            this.load();
        },

        onPageChange({ page, limit }) {
            this.page = page;
            this.limit = limit;
            this.load();
        },

        onLimitChange(limit) {
            this.limit = limit;
            this.page = 1;
            this.load();
        },

        onSearchChange() {
            this.page = 1;
            this.debouncedSearch();
        },
    },
});
```

## 4.3 `src/Resources/app/administration/src/module/topdata-mapper-mappings/page/topdata-mapper-mappings/topdata-mapper-mappings.html.twig` [NEW FILE]

Follows the conflicts page structure (`sw-page` → toolbar card with tabs + search → `sw-data-grid` → `sw-empty-state`), with tab-conditional columns. `sw-time-ago` for the date columns (SW 6.7 pattern — see conventions).

```twig
{% block topdata_mapper_mappings %}
    <sw-page class="topdata-mapper-mappings">
        <template #smart-bar-header>
            <h2>{{ $tc('TopdataMapperSW6.mappings.title') }}</h2>
        </template>

        <template #content>
            {% block topdata_mapper_mappings_list %}
                <sw-card :title="$tc('TopdataMapperSW6.mappings.list.title')">
                    <template #toolbar>
                        <sw-container columns="1fr 240px" align="center" gap="0 16px">
                            <sw-tabs
                                default-item="products"
                                @new-item-active="onTabChange"
                            >
                                <sw-tabs-item name="products">
                                    {{ $tc('TopdataMapperSW6.mappings.tabs.products') }}
                                </sw-tabs-item>
                                <sw-tabs-item name="brands">
                                    {{ $tc('TopdataMapperSW6.mappings.tabs.brands') }}
                                </sw-tabs-item>
                            </sw-tabs>

                            <sw-text-field
                                v-model="search"
                                class="topdata-mapper-mappings__search"
                                :placeholder="$tc('TopdataMapperSW6.mappings.searchPlaceholder')"
                                @update:value="onSearchChange"
                            />
                        </sw-container>
                    </template>

                    <sw-data-grid
                        :dataSource="rows"
                        :columns="columns"
                        :isLoading="isLoading"
                        :showSelection="false"
                        :showSettings="false"
                        :allowColumnEdit="false"
                        :compactMode="false"
                        :pagination="{ page, limit, total }"
                        @page-change="onPageChange"
                        @limit-change="onLimitChange"
                    >
                        {% block topdata_mapper_mappings_grid_product %}
                            <template #column-productNumber="{ item }">
                                <div class="topdata-mapper-mappings__product">
                                    <img
                                        v-if="item.thumbnailUrl"
                                        :src="item.thumbnailUrl"
                                        class="topdata-mapper-mappings__thumb"
                                        alt=""
                                    >
                                    <div class="topdata-mapper-mappings__product-info">
                                        <strong>{{ item.productNumber }}</strong>
                                        <span>{{ item.productName }}</span>
                                    </div>
                                </div>
                            </template>
                        {% endblock %}

                        {% block topdata_mapper_mappings_grid_topdata_id %}
                            <template #column-topdataProductId="{ item }">
                                <sw-label variant="info" appearance="pill">
                                    #{{ item.topdataProductId }}
                                </sw-label>
                            </template>
                            <template #column-topdataBrandId="{ item }">
                                <sw-label variant="info" appearance="pill">
                                    #{{ item.topdataBrandId }}
                                </sw-label>
                            </template>
                        {% endblock %}

                        {% block topdata_mapper_mappings_grid_dates %}
                            <template #column-createdAt="{ item }">
                                <sw-time-ago :date="item.createdAt" />
                            </template>
                            <template #column-updatedAt="{ item }">
                                <sw-time-ago :date="item.updatedAt" />
                            </template>
                        {% endblock %}

                        <template #pagination>
                            <sw-pagination
                                :page="page"
                                :limit="limit"
                                :total="total"
                                :total-visible="7"
                                @page-change="onPageChange"
                                @limit-change="onLimitChange"
                            />
                        </template>
                    </sw-data-grid>

                    <sw-empty-state
                        v-if="!isLoading && rows.length === 0"
                        :title="$tc('TopdataMapperSW6.mappings.empty.title')"
                        :subline="$tc('TopdataMapperSW6.mappings.empty.message')"
                        icon="regular-list"
                    />
                </sw-card>
            {% endblock %}
        </template>
    </sw-page>
{% endblock %}
```

## 4.4 `src/Resources/app/administration/src/module/topdata-mapper-mappings/page/topdata-mapper-mappings/topdata-mapper-mappings.scss` [NEW FILE]

```scss
.topdata-mapper-mappings {
    &__search {
        margin-bottom: 0;
    }

    &__product {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    &__thumb {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border-radius: 4px;
    }

    &__product-info {
        display: flex;
        flex-direction: column;

        span {
            color: #52667a;
            font-size: 12px;
        }
    }
}
```

## 4.5 `src/Resources/app/administration/src/module/topdata-mapper-mappings/snippet/en-GB.json` [NEW FILE]

```json
{
    "TopdataMapperSW6": {
        "mappings": {
            "title": "Mappings",
            "description": "Browse product and brand mappings",
            "list": {
                "title": "Current mappings"
            },
            "tabs": {
                "products": "Products",
                "brands": "Brands"
            },
            "searchPlaceholder": "Search by number, name or Topdata id ...",
            "columns": {
                "product": "Product",
                "manufacturer": "Manufacturer",
                "topdataId": "Topdata ID",
                "createdAt": "Created",
                "updatedAt": "Updated"
            },
            "empty": {
                "title": "No mappings",
                "message": "Mappings appear after an import (topdata:mapper:import)."
            }
        }
    }
}
```

## 4.6 `src/Resources/app/administration/src/module/topdata-mapper-mappings/snippet/de-DE.json` [NEW FILE]

```json
{
    "TopdataMapperSW6": {
        "mappings": {
            "title": "Mappings",
            "description": "Produkt- und Marken-Mappings durchsuchen",
            "list": {
                "title": "Aktuelle Mappings"
            },
            "tabs": {
                "products": "Produkte",
                "brands": "Marken"
            },
            "searchPlaceholder": "Suche nach Nummer, Name oder Topdata-ID ...",
            "columns": {
                "product": "Produkt",
                "manufacturer": "Hersteller",
                "topdataId": "Topdata-ID",
                "createdAt": "Erstellt",
                "updatedAt": "Aktualisiert"
            },
            "empty": {
                "title": "Keine Mappings",
                "message": "Mappings erscheinen nach einem Import (topdata:mapper:import)."
            }
        }
    }
}
```

## 4.7 `src/Resources/app/administration/src/service/topdata-mapper-api-service.js` [MODIFY]

Add the two read methods after `fetchConflicts()` (same pagination contract):

```js
/**
 * Server-side paginated/filtered product mappings.
 */
fetchMappings(params) {
    return this.httpClient.get(this.getApiBasePath() + '/_action/topdata-mapper/mappings', {
        params,
        headers: this.getBasicHeaders(),
    });
}

/**
 * Server-side paginated/filtered brand mappings.
 */
fetchBrands(params) {
    return this.httpClient.get(this.getApiBasePath() + '/_action/topdata-mapper/brands', {
        params,
        headers: this.getBasicHeaders(),
    });
}
```

## 4.8 `src/Resources/app/administration/src/main.js` [MODIFY]

Import the two new modules (group first, then children):

```js
// ---- modules ----
import './module/topdata-mapper';
import './module/topdata-mapper-mappings';
import './module/topdata-mapper-settings';
import './module/topdata-mapper-conflicts';
```

---

# Phase 5 — Admin: relocate the conflicts module

## 5.1 `src/Resources/app/administration/src/module/topdata-mapper-conflicts/index.js` [MODIFY]

Two changes: navigation `parent` `'sw-product'` → `'topdata-mapper'`, and the nav label becomes the short "Konflikte"/"Conflicts" (the group now carries the "Topdata Mapper" name). Position 20 (after Mappings at 10). The module id, route id and route path stay unchanged — bookmarks/links (`topdata.mapper.conflicts.index`) keep working.

```js
    routes: {
        index: {
            component: 'topdata-mapper-conflicts',
            path: 'conflicts',
            meta: {
                privilege: 'topdata_mapper:read',
            },
        },
    },

    navigation: [
        {
            label: 'TopdataMapperSW6.conflicts.title',
            color: '#ff3d58',
            path: 'topdata.mapper.conflicts.index',
            icon: 'regular-plug',
            position: 20,
            parent: 'topdata-mapper',
        },
    ],
```

And add a short nav label snippet (the current `conflicts.title` is "Topdata mapping conflicts"/"Topdata-Mapping-Konflikte" — fine for the page header, but the nav item should read "Konflikte"/"Conflicts"):

- `en-GB.json`: `"conflicts": { ... "navLabel": "Conflicts" ... }`
- `de-DE.json`: `"conflicts": { ... "navLabel": "Konflikte" ... }`

…and use `label: 'TopdataMapperSW6.conflicts.navLabel'` in the navigation entry above.

> The conflicts page previously used `meta.parentPath: 'sw.product.index'`; this is **removed** together with the move (the new parent module has no route to point to, see 4.1).

---

# Phase 6 — User documentation

## 6.1 `README.md` [MODIFY]

In the admin section, describe the new navigation group: *Katalog > Topdata Mapper* with the **Mappings** page (product/brand mapping browser, tabs, search, read-only) and **Konflikte** (conflict resolution), plus the unchanged settings module. Update any screenshots/navigation references if present.

## 6.2 Manual (`manual/` folder) [MODIFY — only if present]

Check for an existing manual (`manual/*.md`). If present, add/adjust the navigation description; if the manual doesn't exist yet, skip (out of scope — manual creation is a separate task).

---

# Phase 7 — Housekeeping

## 7.1 `CHANGELOG.md` [MODIFY]

Add under `[Unreleased] → Added` (Keep a Changelog format):

```markdown
### Added
- **Admin: "Katalog > Topdata Mapper" navigation group** — new group under
  the catalog containing the existing conflicts page (moved out of Products)
  and a new read-only **Mappings** browser (tabs for product and brand
  mappings, server-side pagination + search, `topdata_mapper:read`). New
  API routes `GET /api/_action/topdata-mapper/mappings` and
  `GET /api/_action/topdata-mapper/brands`.
```

## 7.2 `.gitignore` [NO CHANGE]

No new file types or build artifacts are introduced (admin build output is already ignored via `src/Resources/public/administration/`).

## 7.3 Admin asset rebuild

Run from the Shopware root (compiled assets are gitignored, so they never enter the repo, but the dev shop needs them):

```
bin/build-administration.sh
```

Then clear the admin cache if needed (`rm -rf var/cache/*` inside the `sw67-www` container per the debug workflow).

---

# Phase 8 — Implementation report

Write `_ai/backlog/reports/260814_1533__IMPLEMENTATION_REPORT__mapper-admin-navigation-group.md` per the report frontmatter template (summary, files changed, key changes, deviations, technical decisions, testing notes, documentation updates).

---

# Validation / Test Plan

1. **Syntax**: `php -l` on the two modified/new PHP files.
2. **Admin build**: run `bin/build-administration.sh` from the Shopware root; no build errors.
3. **Navigation**: log into the admin → *Katalog* now shows the **Topdata Mapper** group with two children:
   - *Mappings* (position 10) — clicking the group label lands here.
   - *Konflikte* (position 20) — the existing conflicts page still fully works (grid, tabs, radio resolve, notifications).
4. **Mappings page**:
   - Products tab shows SW6 product number/name/thumbnail + Topdata article id; pagination works with >25 rows.
   - Brands tab shows manufacturer name + Topdata brand id.
   - Search by product number, product name, and numeric Topdata id.
   - Empty state when the tables are empty (fresh install without import).
5. **Permissions**: an admin user without `topdata_mapper:read` sees neither page nor the group.
6. **Regression**: settings page (Einstellungen > Plugins > Topdata Mapper) unaffected; `topdata:mapper:import` unaffected (backend changes are read-only additions).
7. **Console check**: no `Cannot read properties of undefined (reading 'href')` / breadcrumb errors in the admin devtools after navigating the new group.

# Out of Scope

- Write access to mappings from the admin (read-only browser by design; the mapping tables are owned by the import).
- Moving the settings module into the new group (stays under Settings > Plugins).
- Manual creation (only updated if already present).