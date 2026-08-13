# DESIGN — Mapping API v2 contract, matching DSL, conflict handling

**Date:** 2026-08-13
**Status:** validated via brainstorm; settings page section still open
**Repos in scope:** `t2-webservice` (API), `topdata-mapper-sw6` (this plugin), `topdata-topfeed-sw6-v9` (TopFeed match will be REMOVED — this plugin replaces it), `topdata-topfinder-pro-sw6` (read-only consumer)

---

## 1. Problem statement

The current mapping API v2 contract leaks Topdata-internal vocabulary that is confusing for shop owners:

| Current name | Problem | New name |
|---|---|---|
| `products_id` | DB column name leaking into the API | `topId` |
| `oem` | automotive jargon; "MPN" is the modern standard | `mpn` |
| `pcd` | cryptic acronym | kept (`pcd`), docs spell out "Topdata product code" |
| `distributor` | the shop owner sees HIS own SKU, not "distributor" | `articleNumbers` (per provider) |
| — | MPN alone is ambiguous (different manufacturers share MPNs) | row-level `brandIds` for pair matching |

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
  "topId": 123456,
  "brandIds": [7],
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
- `topId`: always present (the row key; was `products_id`).
- `brandIds`: present **iff `mpn` requested**; same id space as `/v2/mapping/brand` (and `tdmp_brand.top_id`); product-level manufacturer ids (from `products_manufacturers` join).
  - **Why an array (not a single brand id):** a Topdata product CAN have multiple brands. Verified in t2-app (`products_attributes` is M:N; spec `BRAND (29)` has `only_one_value = 0`) and in live t2 data — 4,067 of ~615k branded products (~0.7%) carry ≥2 brands (e.g. Siemens/Nixdorf/Wincor rebranding history, up to 6). The legacy export `products.brand` (varchar) keeps only the FIRST brand and is lossy. `brandIds` is therefore the full set, in no particular order — no "primary brand" concept (matching only needs set membership: shop side has exactly ONE manufacturer, so the DSL leaf `product.manufacturer:brandIds` matches when the shop manufacturer is contained in the set).
- `ean` / `mpn` / `pcd`: arrays of strings (deduplicated). `pcd` documented as "Topdata product code".
- `articleNumbers`: **object** keyed by **provider id** → array of article-number strings (per-provider from `get_article_artnrs`, deduplicated per provider). Only the user's reserved providers appear (existing behavior).
- **No `articleNumbersFlat` in the response** — the flat union is derived engine-side at strategy-compile time (keeps the streamed payload ~half size; source table `distributor_artnr_products` is ~200MB).
- Values across dimensions may overlap (a number can be both a PCD and a provider article number) — harmless, separate dimensions.

### `GET /v2/mapping/provider` — NEW

`{rows: [{id, name, synonym?}], pagination}` — mirrors `/v2/mapping/brand`, same reservation filter. Feeds the settings page provider dropdown.

### `GET /v2/mapping/brand` — unchanged

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
| `product.manufacturer:brandIds` | shop manufacturer vs API `brandIds` (via `tdmp_brand` reverse map) |
| `product.product_number:articleNumbers` | shop SKU vs API `articleNumbers` (any provider = union) |
| `product.product_number:articleNumbers.4123` | shop SKU vs one provider only |
| `property.<group>:articleNumbers` | property option values vs API dimension |
| `customField.<name>:mpn` | custom field values vs API dimension |

### Semantics

- Set algebra: each leaf = one map lookup per row value → product set; `|` = union, `&` = intersection. Evaluated **per API row**, scales like today's bulk map-lookup matching (no per-product evaluation).
- Normalization per dimension: `ean` → digits; `mpn`/`pcd` → lowercase trim; `articleNumbers` → string exact (matching TopFeed's `UtilMappingHelper` behavior; `UtilIdentifierNormalizer` must stay in sync).
- `brandIds` leaf resolves shop manufacturer → Topdata brand id via `tdmp_brand` (reverse map built at strategy-compile time). **Import-order dependency:** the reverse map requires `tdmp_brand` to be freshly built **before** the product build starts (brand build runs first, see §3 Engine). Only strategies referencing `brandIds` (Brand-scoped MPN preset) depend on this — the default strategy does not.
- Default (no config): `product.ean:ean | product.manufacturer_number:mpn | product.manufacturer_number:pcd | product.product_number:articleNumbers`
- Brand-scoped MPN preset: `product.ean:ean | (product.manufacturer:brandIds & product.manufacturer_number:mpn) | product.product_number:articleNumbers`
- Presets: Default / Brand-scoped MPN / Article numbers only / EAN only / Custom (free-form DSL).

### Engine (this plugin)

- Configurable matcher implementing `ProductMappingMatcherInterface` (interface stays stable — TopFeed's own matcher gets REMOVED; the configurable matcher becomes the wired default; TopFeed reads mapping from this plugin).
- Components: DSL tokenizer + recursive-descent parser → AST; leaf evaluators with per-dimension normalizers + shop map builders; set ops; validation error messages for bad DSL (import fails loudly).
- Config storage: `matchingStrategy` config field (`TopdataMapperSW6.config`), default = the default DSL string.
- CLI `topdata:mapper:import` uses the configured strategy.
- **Build order:** when the strategy references `brandIds`, brand must be built before product. Currently `_buildAll()` runs product first (Command_TdmpImport.php:76-77) — must be swapped. Guard for `--mapping=product` alone: if the strategy references `brandIds` and `tdmp_brand` is empty, warn or fail loudly instead of silently matching nothing.

---

## 4. Conflict handling

### Detection

- During build: dedupe rows per `(product_id, top_id)` **before insert** (raw batch INSERT would crash on duplicate PK tuple — same product matched via multiple dimensions).
- Group accumulated rows per product; `distinct top_ids > 1` → conflict. (Reverse case — one Topdata article matched by many shop products, e.g. variants — is normal, NOT a conflict.)

### Table `tdmp_product_conflict_resolutions` (NEW, migration `Migration2026081302...`)

```sql
CREATE TABLE IF NOT EXISTS `tdmp_product_conflict_resolutions` (
  `product_id`         binary(16)  NOT NULL,
  `product_version_id` binary(16)  NOT NULL,
  `chosen_topdata_id`  bigint(20)  NOT NULL,
  `topdata_ids`        json        NOT NULL,
  `status`             varchar(16) NOT NULL DEFAULT 'auto',  -- 'auto' | 'user'
  `created_at`         DATETIME(3) NOT NULL,
  `updated_at`         DATETIME(3) NOT NULL,
  PRIMARY KEY (`product_id`, `product_version_id`),
  CONSTRAINT `fk_tdmp_conflict_resolution_product` FOREIGN KEY (`product_id`, `product_version_id`)
    REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `product_version_id` pinned to `LIVE_VERSION_HEX` (same pattern as `tdmp_product`; row contract carries only `product_id`).
- `topdata_ids` json = candidate list for the settings-page radio list.

### Import flow (per full-table-replace run)

1. Dedupe rows; group per product; find conflicts.
2. Load existing resolutions (product → chosen, status).
3. Per conflicted product:
   - no row → insert `chosen = min(top_id)`, `status = 'auto'`
   - row `status = 'user'` and chosen ∈ candidates → keep, refresh `topdata_ids`
   - row `status = 'user'` and chosen ∉ candidates → **delete row**, re-insert as `status = 'auto'` with `chosen = min(top_id)` (demotion — the settings-page queue re-flags it)
   - row `status = 'auto'` → always recompute `chosen = min(top_id)`, refresh candidates
4. Delete rows for products no longer conflicted (product now matches ≤1 article) — table strictly mirrors live conflicts.
5. `tdmp_product`: only the chosen row per conflicted product; TRUNCATE + insert as today.

### Settings page (this plugin — SECTION STILL OPEN, see §6)

- Rows with `status = 'auto'` = pending queue; radio buttons from `topdata_ids`; picking one → `status = 'user'`.
- Changing a `user` row keeps `status = 'user'`.
- CLI prints conflict count (+ optionally stale/demotion count).

---

## 5. Notes / constraints

- `ProductMappingMatcherInterface::matchRow()` signature stays stable (`list<array{product_id: string}>`, live version only).
- `UtilIdentifierNormalizer` mirrors TopFeed's `UtilMappingHelper` — keep both in sync.
- Foundation code is tree-shaken into consumer builds — shared engine logic lives in THIS plugin.
- `tdmp_brand` remains the (brand id ↔ SW6 manufacturer) bridge used by the `brandIds` leaf. The brand build only inserts brands that matched a shop manufacturer — exactly the set the reverse map needs (the shop side of the `brandIds` comparison is always a shop manufacturer). `--mapping=brand` stays independent; `tdmp_brand` persists across runs and is only replaced by the brand build, so a populated table from a previous run still works for the reverse map.
- TopFeed's `MAPPING_TYPE` config + `ProductMappingMatcher_TopFeed` will be removed — migration path for existing configs to the new DSL is a follow-up task.

---

## 6. Open items

- **Settings page design** (postfinance pattern: admin module `topdata-mapper-settings`, `settingsItem` group plugins, ACL, snippets; sections: webservice credentials + matching strategy editor with presets, structured AND/OR builder, free-form DSL validation; provider/custom-field/property dropdowns; backend routes `/api/_action/topdata-mapper/validate-strategy` + `/providers`; conflict-resolution list with radio buttons).
- Finder API `products_id` rename (consistency) — flag, decide separately.
- TopFeed config migration to DSL.
- Whether a "manual mapping" feature (pin a mapping without a conflict) is wanted later — out of scope now.
