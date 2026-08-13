# DESIGN — Mapping API v2 contract, matching DSL, conflict handling

**Date:** 2026-08-13
**Status:** validated via brainstorm; settings page (Card B, §7) + conflict-resolution UI (Card C, §8) fully designed; all open items decided 2026-08-13
**Repos in scope:** `t2-webservice` (API), `topdata-mapper-sw6` (this plugin), `topdata-topfeed-sw6-v9` (TopFeed match will be REMOVED — this plugin replaces it), `topdata-topfinder-pro-sw6` (read-only consumer)

---

## 1. Problem statement

The current mapping API v2 contract leaks Topdata-internal vocabulary that is confusing for shop owners:

| Current name | Problem | New name |
|---|---|---|
| `products_id` | DB column name leaking into the API | `topdataProductId` |
| `oem` | automotive jargon; "MPN" is the modern standard | `mpn` |
| `pcd` | cryptic acronym | kept (`pcd`), docs spell out "Topdata product code" |
| `distributor` | the shop owner sees HIS own SKU, not "distributor" | `articleNumbers` (per provider) |
| — | MPN alone is ambiguous (different manufacturers share MPNs) | row-level `topdataBrandIds` for pair matching |

Key facts established during the brainstorm:

- **API v2 is not live yet** (commit `264629f` "feat: add v2 mapping API", today) — the wire contract can be renamed freely.
- The domain concept "distributor" was renamed to "provider" / "product data provider" long ago (`DistributorsController` docblock: *aka ProviderController aka ProductDataProviderController*); legacy identifiers (`distributors` tables etc.) were kept — but v2 is the chance to fix the API layer.
- Multi-provider article-number export **is already possible**: `ProductModel::get_article_artnrs()` returns `[products_id => [distributor_id => ['id','name','synonym','artnrs' => [...]]]]`; `MappingModel::extractDistributorArtnrs()` currently flattens it (source info thrown away).
- MPN pairing is only constructible at **product level**: the webservice `oem` table stores `products_id + oem` only (no per-value manufacturer); the manufacturer exists via `products_manufacturers` (product → attribute_id, same id space as `/v2/mapping/brand` and `tdmp_brand`). Per-value `{brandId, number}` would be fake precision.

---

## 2. API v2 contract (final)

### `GET /v2/mapping/product` — identifier dimensions per reserved product

Params: `types=ean,mpn,pcd,articleNumbers` (default: all; unknown entries dropped, existing `filterValidTypes` behavior), `start`, `limit`, `language`. Auth: valid v2 api_key (unchanged). Pagination: unified `rows`/`pagination` envelope (unchanged).

Response row:

```json
{
  "topdataProductId": 123456,
  "topdataBrandIds": [7],
  "ean": ["4001234567890"],
  "mpn": ["F00B1"],
  "pcd": ["A-123"],
  "articleNumbers": {
    "4123": ["4120", "X-77"],
    "5199": ["H-42"],
    "7":    ["A-123"]
  }
}
```

Rules:
- `topdataProductId`: always present (the row key; was `products_id`, then `topId`).
- `topdataBrandIds`: present **iff `mpn` requested**; same id space as `/v2/mapping/brand` (and `tdmp_brand.topdata_brand_id`); product-level manufacturer ids (from `products_manufacturers` join).
  - **Why an array (not a single brand id):** a Topdata product CAN have multiple brands. Verified in t2-app (`products_attributes` is M:N; spec `BRAND (29)` has `only_one_value = 0`) and in live t2 data — 4,067 of ~615k branded products (~0.7%) carry ≥2 brands (e.g. Siemens/Nixdorf/Wincor rebranding history, up to 6). The legacy export `products.brand` (varchar) keeps only the FIRST brand and is lossy. `topdataBrandIds` is therefore the full set, in no particular order — no "primary brand" concept (matching only needs set membership: shop side has exactly ONE manufacturer, so the DSL leaf `product.manufacturer:topdataBrandIds` matches when the shop manufacturer is contained in the set).
- `ean` / `mpn` / `pcd`: arrays of strings (deduplicated). `pcd` documented as "Topdata product code".
- `articleNumbers`: **object** keyed by **provider id** → array of article-number strings (per-provider from `get_article_artnrs`, deduplicated per provider). Only the user's reserved providers appear (existing behavior).
- **No `articleNumbersFlat` in the response** — the flat union is derived engine-side at strategy-compile time (keeps the streamed payload ~half size; source table `distributor_artnr_products` is ~200MB).
- Values across dimensions may overlap (a number can be both a PCD and a provider article number) — harmless, separate dimensions.

### `GET /v2/mapping/provider` — NEW

`{rows: [{id, name, synonym?}], pagination}` — mirrors `/v2/mapping/brand`, same reservation filter. Feeds the settings page provider dropdown.

### `GET /v2/mapping/brand` — unchanged

### Naming rule

The `topdata*` prefix marks the **Topdata-internal id space** — used wherever a Topdata id could be confused with a Shopware id (API fields, DSL dimensions, DB columns). Identifier dimensions (`ean`, `mpn`, `pcd`, `articleNumbers`) are values, never prefixed.

| Context | Name |
|---|---|
| API row key (was `products_id`, `topId`) | `topdataProductId` |
| API manufacturer set (was `brandIds`) | `topdataBrandIds` |
| `tdmp_product` column (was `topdata_id`, `top_id`) | `topdata_product_id` |
| `tdmp_brand` column (was `topdata_id`, `top_id`) | `topdata_brand_id` |
| Conflict table chosen id (was `chosen_topdata_id`) | `chosen_topdata_product_id` |
| Conflict table candidates (was `topdata_ids`) | `topdata_product_ids` |

### Rename surface (API side, t2-webservice)

- `MappingModel::VALID_TYPES` → `['ean', 'mpn', 'pcd', 'articleNumbers']`; `$type === 'distributor'` branch → `articleNumbers`; `extractDistributorArtnrs()` → keep flattening logic only as engine-side helper or remove (structured pass-through instead); docblocks.
- `MappingController` docblock (`types=...`), `ROUTING.md`, `openApi.json` (regenerate), `MappingModelTest`.
- New provider endpoint + model method (reservation-filtered provider list).
- TopFinder: reads `products_id` from the **finder** endpoint (`/v2/finder/ink-toner/device-products`) — decision: rename finder API too for consistency (out of scope of this doc; flag only).

---

## 3. Matching DSL (this plugin)

### Grammar

```
strategy  := orExpr
orExpr    := andExpr ('|' andExpr)*     // | = union of matched product sets
andExpr   := leaf ('&' leaf)*           // & = intersection
leaf      := shopField ':' dimensionRef
```

### Leaves

| DSL | Meaning |
|---|---|
| `product.ean:ean` | shop `product.ean` vs API `ean` |
| `product.manufacturer_number:mpn` | shop MPN vs API `mpn` |
| `product.manufacturer:topdataBrandIds` | shop manufacturer vs API `topdataBrandIds` (via `tdmp_brand` reverse map) |
| `product.product_number:articleNumbers` | shop SKU vs API `articleNumbers` (any provider = union) |
| `product.product_number:articleNumbers.4123` | shop SKU vs one provider only |
| `property.<group>:articleNumbers` | property option values vs API dimension |
| `customField.<name>:mpn` | custom field values vs API dimension |

### Semantics

- Set algebra: each leaf = one map lookup per row value → product set; `|` = union, `&` = intersection. Evaluated **per API row**, scales like today's bulk map-lookup matching (no per-product evaluation).
- Normalization per dimension: `ean` → digits; `mpn`/`pcd` → lowercase trim; `articleNumbers` → string exact (matching TopFeed's `UtilMappingHelper` behavior; `UtilIdentifierNormalizer` must stay in sync).
- `topdataBrandIds` leaf resolves shop manufacturer → Topdata brand id via `tdmp_brand` (reverse map built at strategy-compile time). **Import-order dependency:** the reverse map requires `tdmp_brand` to be freshly built **before** the product build starts (brand build runs first, see §3 Engine). Only strategies referencing `topdataBrandIds` (Brand-scoped MPN preset) depend on this — the default strategy does not.
- Default (no config): `product.ean:ean | product.manufacturer_number:mpn | product.manufacturer_number:pcd | product.product_number:articleNumbers`
- Brand-scoped MPN preset: `product.ean:ean | (product.manufacturer:topdataBrandIds & product.manufacturer_number:mpn) | product.product_number:articleNumbers`
- Presets: Default / Brand-scoped MPN / Article numbers only / EAN only / Custom (free-form DSL).

### Engine (this plugin)

- Configurable matcher implementing `ProductMappingMatcherInterface` (interface stays stable — TopFeed's own matcher gets REMOVED; the configurable matcher becomes the wired default; TopFeed reads mapping from this plugin).
- Components: DSL tokenizer + recursive-descent parser → AST; leaf evaluators with per-dimension normalizers + shop map builders; set ops; validation error messages for bad DSL (import fails loudly).
- Config storage: `matchingStrategy` config field (`TopdataMapperSW6.config`), default = the default DSL string.
- CLI `topdata:mapper:import` uses the configured strategy.
- **Build order:** when the strategy references `topdataBrandIds`, brand must be built before product. Currently `_buildAll()` runs product first (Command_TdmpImport.php:76-77) — must be swapped. Guard for `--mapping=product` alone: if the strategy references `topdataBrandIds` and `tdmp_brand` is empty, warn or fail loudly instead of silently matching nothing.

---

## 4. Conflict handling

### Detection

- During build: dedupe rows per `(product_id, topdata_product_id)` **before insert** (raw batch INSERT would crash on duplicate PK tuple — same product matched via multiple dimensions).
- Group accumulated rows per product; `distinct topdata_product_ids > 1` → conflict. (Reverse case — one Topdata article matched by many shop products, e.g. variants — is normal, NOT a conflict.)

### Table `tdmp_product_conflict_resolutions` (NEW, migration `Migration2026081302...`)

```sql
CREATE TABLE IF NOT EXISTS `tdmp_product_conflict_resolutions` (
  `product_id`         binary(16)  NOT NULL,
  `product_version_id` binary(16)  NOT NULL,
  `chosen_topdata_product_id` bigint(20)  NOT NULL,
  `topdata_product_ids`     json        NOT NULL,
  `status`             varchar(16) NOT NULL DEFAULT 'auto',  -- 'auto' | 'user'
  `created_at`         DATETIME(3) NOT NULL,
  `updated_at`         DATETIME(3) NOT NULL,
  PRIMARY KEY (`product_id`, `product_version_id`),
  CONSTRAINT `fk_tdmp_conflict_resolution_product` FOREIGN KEY (`product_id`, `product_version_id`)
    REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `product_version_id` pinned to `LIVE_VERSION_HEX` (same pattern as `tdmp_product`; row contract carries only `product_id`).
- `topdata_product_ids` json = candidate list for the settings-page radio list, **including per-candidate preview data (pcd/ean/mpn) captured at conflict-detection time** — the radio rows render from this json, no API lookups (decided 2026-08-13, §8).

### Import flow (per full-table-replace run)

1. Dedupe rows; group per product; find conflicts.
2. Load existing resolutions (product → chosen, status).
3. Per conflicted product:
   - no row → insert `chosen = min(topdata_product_id)`, `status = 'auto'`
   - row `status = 'user'` and chosen ∈ candidates → keep, refresh `topdata_product_ids`
   - row `status = 'user'` and chosen ∉ candidates → **delete row**, re-insert as `status = 'auto'` with `chosen = min(topdata_product_id)` (demotion — the settings-page queue re-flags it)
   - row `status = 'auto'` → always recompute `chosen = min(topdata_product_id)`, refresh candidates
4. Delete rows for products no longer conflicted (product now matches ≤1 article) — table strictly mirrors live conflicts.
5. `tdmp_product`: only the chosen row per conflicted product; TRUNCATE + insert as today.

### Settings page (this plugin — SECTION STILL OPEN, see §6)

- Rows with `status = 'auto'` = pending queue; radio buttons from `topdata_product_ids`; picking one → `status = 'user'`.
- Changing a `user` row keeps `status = 'user'`.
- CLI prints conflict count (+ optionally stale/demotion count).

---

## 5. Notes / constraints

- `ProductMappingMatcherInterface::matchRow()` signature stays stable (`list<array{product_id: string}>`, live version only).
- `UtilIdentifierNormalizer` mirrors TopFeed's `UtilMappingHelper` — keep both in sync.
- Foundation code is tree-shaken into consumer builds — shared engine logic lives in THIS plugin.
- `tdmp_brand` remains the (brand id ↔ SW6 manufacturer) bridge used by the `topdataBrandIds` leaf. The brand build only inserts brands that matched a shop manufacturer — exactly the set the reverse map needs (the shop side of the `topdataBrandIds` comparison is always a shop manufacturer). `--mapping=brand` stays independent; `tdmp_brand` persists across runs and is only replaced by the brand build, so a populated table from a previous run still works for the reverse map.
- **Migration strategy:** existing migrations `Migration2026081300`/`Migration2026081301` are edited **in place** to create the final column names (`topdata_product_id`, `topdata_brand_id`) — no rename migration. The dev shop resets the plugin via uninstall/reinstall (drops plugin migration rows, re-runs from 1300).
- TopFeed's `MAPPING_TYPE` config + `ProductMappingMatcher_TopFeed` will be removed — migration path for existing configs to the new DSL is a follow-up task.

---

## 6. Open items

- **Conflict-resolution UI (Card C)** — separate admin module under the Products main navigation (`sw-product` parent, route `topdata.mapper.conflicts`); design settled 2026-08-13 — see §8. Settings-page sections were split during the Card B brainstorm: credentials stay as native config card, matching engine = settings module, conflicts = Products module.
- Finder API `products_id` rename — **included in this effort** (decided 2026-08-13): rename the finder endpoint's field to `topdataProductId` and update TopFinder's parser in the same release (lockstep, nothing breaks).
- TopFeed config migration to DSL — **not needed now** (decided 2026-08-13): on upgrade TopFeed v8 → v9 + mapper plugin, the shop owner configures the mapper strategy manually; an automated migration snippet could be a later iteration.
- Whether a "manual mapping" feature (pin a mapping without a conflict) is wanted later — out of scope now.

---

## 7. Settings page — matching strategy editor (Card B, designed 2026-08-13)

Admin module `topdata-mapper-settings` (`settingsItem` group `plugins`, ACL `topdata_mapper:read`/`update`, route `topdata.mapper.settings`).

- **Card A — Webservice credentials (confirmed 2026-08-13):** keep the native config card (config.xml `apiBaseUrl`/`apiKey`, CLI prompt logic already reads config storage — no duplicated write paths); the module shows a read-only configured/not-configured status with a link to the native settings.
- **Card B — Matching strategy:** designed below.
- **Card C — Conflict resolution:** separate module under Products nav (design open, §6).

### Card B decisions

- **DSL string is the single source of truth.** Preset chips + visual builder are views over the current DSL string; presets are label→DSL-string constants, no separate state.
- **Preset chips** (Default / Brand-scoped MPN / Article numbers only / EAN only / Custom): a chip is highlighted iff the current DSL equals its canonical string; typing flips the selector to "Custom" automatically (live detection — no read-only mode, no edit-button mode); re-clicking a chip restores the canonical preset string (one-click recovery from botched edits).
- **Stacked, live-synced editors:** visual builder on top, DSL textarea below (labeled "DSL", not "Custom DSL"), both always editable; "last-edited side wins", each edit fully replaces the other side (no merge states).
- **Sync architecture — single parser, single serializer:**
  - Builder edit → small JS serializer → DSL string (grammar is tiny: 3-select leaf rows, AND groups, OR joiners).
  - Textarea edit → debounced `POST /api/_action/topdata-mapper/validate-strategy` → response `{valid, ast, error}` → builder re-renders from the PHP-parsed AST.
  - Parser lives once in PHP (authoritative — import fails loudly on bad DSL, §3), serializer once in JS — no drift.
- **Leaf row selects:** shop field (`product.ean`, `product.manufacturer_number`, `product.manufacturer`, `product.product_number`, `property.<group>`, `customField.<name>`) → dimension (`ean`, `mpn`, `pcd`, `articleNumbers`, `topdataBrandIds`) → contextual variant select (provider dropdown for `articleNumbers.<provider>` fed by `/v2/mapping/provider`; property-group via `property_group` repository; custom field via custom field set repository).
- **Pairing matrix (decided 2026-08-13):** single source of truth is a PHP class `src/Service/Dsl/DslPairingMatrix.php` (const map + `isAllowed()` / `allowedDimensions()`; provider-scoped `articleNumbers.<provider>` = prefix check on the `articleNumbers` cell). The frontend never hardcodes it — the matrix is served as JSON data via the strategy GET route (see Backend routes) and renders the dimension dropdowns from it. Matrix-violating pairs are **hard-blocked** (validation error, not warn) — a silently-never-matching leaf is worse than a loud error, same philosophy as the import failing loudly (§3).
- **Backend routes (decided 2026-08-13):** controller `src/Controller/Api/TopdataMapperActionController.php`, `_routeScope: api`, called from admin via `Shopware.Service('httpClient')`:
  - `GET /api/_action/topdata-mapper/strategy` → `{dsl, presets: [{key, label, dsl}], allowedPairs}` — module init, one round trip.
  - `POST /api/_action/topdata-mapper/strategy` → body `{dsl}` → full validation (grammar + pairing matrix + provider-id existence) **before** any `SystemConfigService` write; violation → HTTP 400 with structured error (shop field, dimension, position) → nothing persisted; the settings page never writes config storage directly (authoritative write gate).
  - `POST /api/_action/topdata-mapper/validate-strategy` → `{valid, ast, error}` — debounced live check while typing; AST re-renders the builder (single PHP parser).
  - Backstop: import re-validates the stored strategy per run and fails loudly (§3) — catches configs written around the gate (CLI `config:set`, direct DB edit, TopFeed-migrated configs).
- **Validation:** pairing constraints client-side; backend re-validates authoritatively on save; inline error banner on save failure, success toast; debounced validation feedback while typing.
- **Micro-UX:** copy icon on the DSL textarea (support emails DSL strings to shop owners).

### Card B open items

(none — dirty-check warning **included in v1**, decided 2026-08-13: confirm dialog when clicking a preset chip while the current DSL differs from the last-loaded value; ~15 lines of JS.)

---

## 8. Conflict-resolution UI (Card C, designed 2026-08-13)

Admin module `topdata-mapper-conflicts`, parent `sw-product` (top-level Products nav in 6.7), position directly after the product list, route `topdata.mapper.conflicts`, ACL `topdata_mapper:read` (view) / `topdata_mapper:update` (resolve).

### Card C decisions

- **Data access: no-DAL, action routes + `sw-data-grid`.** Enrichment (product number/name/thumbnail, candidate previews) is custom PHP either way — a DAL entity would only ride `sw-entity-listing` features (selection, bulk actions, inline edit) that radio-based resolution doesn't need. If a *mapping overview* list (browse/search `tdmp_product`) is ever wanted, a DAL entity can be introduced for that feature then.
- **Server-side pagination/filtering via route params** — `sw-data-grid` `pagination` prop + `page-change`/`limit-change` events re-fetching `GET /api/_action/topdata-mapper/conflicts?page=&limit=&status=&search=`; conflicts can be numerous (lazy strategy across a big catalog).
- **Resolve without re-import:** `POST /api/_action/topdata-mapper/resolve-conflict` `{productId, chosenTopdataProductId}` → status `user` + single UPDATE of that product's `tdmp_product` row, effective immediately. No full re-import needed to see the change.
- **No menu count badge** on the nav entry — keep it simple.
- Page layout: summary banner (last import time + pending/resolved counts), status tabs (All / Pending `auto` / Resolved `user`), search by product number/name, grid columns product (number + name + thumb) / candidates as radio list / status badge / updated-at, immediate POST on radio pick, empty-state hint that conflicts appear after `topdata:mapper:import`.

### Card C open item

(none — candidate previews **stored json only**, decided 2026-08-13: conflict-detection persists candidate ids + pcd/ean/mpn into `topdata_product_ids`; the radio list renders from it, zero API traffic.)
