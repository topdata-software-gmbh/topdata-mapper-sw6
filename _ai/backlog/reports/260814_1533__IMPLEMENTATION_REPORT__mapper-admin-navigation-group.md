---
filename: "_ai/backlog/reports/260814_1533__IMPLEMENTATION_REPORT__mapper-admin-navigation-group.md"
title: "Report: Katalog > Topdata Mapper navigation group with conflicts page + mappings browser"
createdAt: 2026-08-14 17:30
updatedAt: 2026-08-14 17:30
planFile: "_ai/backlog/active/260814_1533__IMPLEMENTATION_PLAN__mapper-admin-navigation-group.md"
project: "topdata-mapper-sw6"
status: completed
filesCreated: 10
filesModified: 6
filesDeleted: 0
tags: [shopware, sw6-plugin, mapper, admin, navigation, mapping-browser]
documentType: IMPLEMENTATION_REPORT
---

# 1. Summary

New admin navigation group **Katalog > Topdata Mapper** (`parent: sw-catalogue`): the conflicts page moved out of *Katalog > Produkte* into the group, and a new read-only **Mappings** browser page (product + brand tabs) sits next to it. Backend gained a raw-DBAL `TdmpMappingBrowseService` (server-side pagination + search, live-version pinned) and two read-only `_action` routes guarded by `topdata_mapper:read`. Settings module stays under *Einstellungen > Plugins*.

# 2. Files Changed

### New
- `src/Service/Db/TdmpMappingBrowseService.php` — read-only DBAL: `listProductMappings()` (JOIN product + product_translation + media_thumbnail), `listBrandMappings()` (JOIN product_manufacturer + translation), shared `_buildPageSql()` LIMIT/OFFSET helper (`MAX_LIMIT = 500`).
- `src/Resources/app/administration/src/module/topdata-mapper/index.js` — group module; `id: 'topdata-mapper'` (required for FlatTree child attachment), route `index` rendering the mappings component, nav `parent: 'sw-catalogue'`, `privilege: 'topdata_mapper:read'` on all three nav entries.
- `src/Resources/app/administration/src/module/topdata-mapper/snippet/{en-GB,de-DE}.json` — `TopdataMapperSW6.navigation.*` keys (group label, once).
- `src/Resources/app/administration/src/module/topdata-mapper-mappings/index.js` — child module, nav label `TopdataMapperSW6.mappings.title`, route `topdata.mapper.mappings.index`.
- `src/Resources/app/administration/src/module/topdata-mapper-mappings/page/topdata-mapper-mappings/{index.js,topdata-mapper-mappings.html.twig,topdata-mapper-mappings.scss}` — tabbed grid (products/brands) with per-tab state machine, shared `load()` dispatching by `activeTab`, debounced search, `sw-time-ago` date columns, pill Topdata id labels, empty state.
- `src/Resources/app/administration/src/module/topdata-mapper-mappings/snippet/{en-GB,de-DE}.json` — `TopdataMapperSW6.mappings.*` keys.

### Modified
- `src/Resources/config/services.xml` — registered `TdmpMappingBrowseService` (autowire).
- `src/Controller/Api/TopdataMapperActionController.php` — constructor param + two GET routes (`/api/_action/topdata-mapper/mappings`, `/api/_action/topdata-mapper/brands`), both `_assertPrivilege('topdata_mapper:read')`.
- `src/Resources/app/administration/src/service/topdata-mapper-api-service.js` — `fetchMappings()` / `fetchBrands()`.
- `src/Resources/app/administration/src/main.js` — imports group + mappings modules.
- `src/Resources/app/administration/src/module/topdata-mapper-conflicts/index.js` — nav `parent` `sw-product` → `topdata-mapper`, label → `TopdataMapperSW6.conflicts.navLabel`, `parentPath: 'sw.product.index'` removed, `privilege: 'topdata_mapper:read'` added. Module/route ids + path unchanged (links intact).
- `src/Resources/app/administration/src/module/topdata-mapper-conflicts/snippet/{en-GB,de-DE}.json` — added `navLabel` ("Conflicts"/"Konflikte").
- `README.md`, `CHANGELOG.md` — admin section + Unreleased entry.

# 3. Key Changes

- **Read-only by design**: no write paths from the admin browser; mapping tables stay owned by the import.
- **Route names verified against the 6.7 generated-name rule** (`<moduleId dots>.<routeKey>`): group `topdata.mapper.index`, mappings `topdata.mapper.mappings.index`, conflicts `topdata.mapper.conflicts.index` — all resolve (no `Cannot read properties of undefined (reading 'href')` risk).
- **Numeric search guard** `(int)$search === 0 ? 0 : (int)$search` kept — a non-numeric query never matches `topdata_product_id = 0`.
- **Live-version pinning** for the products grid via `TdmpProductService::LIVE_VERSION_HEX` (draft rows never surface).

# 4. Deviations from Plan

- None material. Manual update skipped (no `manual/` folder exists — plan allowed skipping).

# 5. Technical Decisions

- **No `meta.parentPath`** on child routes — breadcrumb shows the page title only; group landing route is not a meaningful ancestor (per plan).
- **Group id `topdata-mapper`** required for FlatTree child attachment (matching `parent`), plus `privilege` on every nav entry so unprivileged users see nothing.
- **`sw-time-ago`** for date columns (SW 6.7 pattern; `| date()` filter is gone in Vue 3).
- Admin build ran inside `sw67-www` container; host-side `node copyFileSync` on this btrfs mount throws EPERM (known `copy_file_range` quirk), and root-owned `.vite` dirs in `vendor/shopware/*/Resources/public/administration/` needed cleanup before the container build could run.

# 6. Testing Notes

- `php -l` clean on both PHP files.
- `bin/console debug:router` (in container) shows both new routes registered; unauthenticated curl returns 401 (route resolves, auth required).
- `bin/build-administration.sh` in `sw67-www` succeeded; compiled `src/Resources/public/administration/assets/*.js` contains `topdata-mapper-mappings` + `TopdataMapperSW6.mappings.*` keys. Cache cleared.
- Not yet clicked through in the admin UI — needs a logged-in session (manual step: Katalog → Topdata Mapper shows Mappings + Konflikte; grid/pagination/search/empty-state).

# 7. Usage Examples

- Admin: *Katalog → Topdata Mapper → Mappings* (Products/Brands tabs, search, pagination); *Katalog → Topdata Mapper → Konflikte* (unchanged behavior).
- API: `GET /api/_action/topdata-mapper/mappings?page=1&limit=25&search=...`, `GET /api/_action/topdata-mapper/brands?...` (both require `topdata_mapper:read`).

# 8. Documentation Updates

- `README.md` — new "Admin" section (navigation group, Mappings browser, Konflikte moved, settings unchanged); conflicts section path updated.
- `CHANGELOG.md` — Unreleased → Added entry for the navigation group + new API routes.

# 9. Next Steps

- Manual UI pass in the dev shop (navigation, tabs, search, permissions) — covered by the plan's validation list.