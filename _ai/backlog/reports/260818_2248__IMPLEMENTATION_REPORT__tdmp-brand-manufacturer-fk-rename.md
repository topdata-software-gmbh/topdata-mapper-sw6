---
filename: "_ai/backlog/reports/260818_2248__IMPLEMENTATION_REPORT__tdmp-brand-manufacturer-fk-rename.md"
title: "Report: tdmp_brand — rename brand_id to product_manufacturer_id, pin version, add FK"
createdAt: 2026-08-18 23:58
updatedAt: 2026-08-18 23:58
planFile: "_ai/backlog/active/260818_2248__IMPLEMENTATION_PLAN__tdmp-brand-manufacturer-fk-rename.md"
project: "topdata-mapper-sw6"
status: completed
filesCreated: 2
filesModified: 7
filesDeleted: 0
tags: [migration, schema, fk, tdmp_brand, refactor]
documentType: IMPLEMENTATION_REPORT
---

# 1. Summary

Aligned `tdmp_brand` with the `tdmp_product` pattern: `brand_id` renamed to
`product_manufacturer_id`, new `product_manufacturer_version_id` column always
pinned to the live version, PK extended to
`(product_manufacturer_id, product_manufacturer_version_id, topdata_brand_id)`,
and a composite FK `fk_tdmp_brand_product_manufacturer` →
`product_manufacturer(id, version_id) ON DELETE CASCADE`. Fresh installs get
the final schema from migration 1300 (edited in place); existing installs are
repaired by the new idempotent migration 1804 (guarded on the legacy
`brand_id` column). All four code locations touching `brand_id` were renamed
mechanically; consumers (TopFeed / TopFinder) never read this table, so there
is no consumer impact. Verified end-to-end in the dev shop: migration ran,
schema final, 64 brand rows survived, brand + full imports rebuilt
successfully, admin browse query and the DSL matcher's reverse brand→product
query both proven against the DB.

# 2. Files Changed

### New
- `src/Migration/Migration2026081804TdmpBrandManufacturerFk.php` — repair
  migration (guarded on `brand_id` column existing): prunes rows without a
  live manufacturer row, renames the column in place via `CHANGE COLUMN`,
  adds the version column (nullable → backfill live version → NOT NULL),
  dedupes rows duplicated by the version pin, rebuilds the PK and adds the FK
  (guarded with `_foreignKeyExists`).
- `_ai/backlog/reports/260818_2248__IMPLEMENTATION_REPORT__tdmp-brand-manufacturer-fk-rename.md` —
  this report.

### Modified
- `src/Migration/Migration2026081300CreateTdmpTables.php` — `tdmp_brand`
  CREATE statement now the final schema (fresh installs; inline FK mirrors
  `tdmp_product`).
- `src/Service/Db/TdmpBrandService.php` — new public
  `LIVE_VERSION_HEX` constant; `insertMany()` pins the version and inserts
  `product_manufacturer_id` / `product_manufacturer_version_id`;
  `getBrandMap()` aliases the renamed column; class docblock documents the
  version pin + FK rationale.
- `src/Service/TdmpMappingBuildService.php` — `buildBrandMappings()` row key
  `brand_id` → `product_manufacturer_id`; `_loadShopBrandMap()` renamed the
  alias **and** added the live-version filter (required — without it a
  draft-only manufacturer would pass the map but violate the new FK on insert).
- `src/Service/Db/TdmpMappingBrowseService.php` — `listBrandMappings()`:
  COUNT + page queries join on `(product_manufacturer_id,
  product_manufacturer_version_id)` and the translation sub-query groups by
  both; API key `brandId` → `manufacturerId` (admin JS never read it —
  verified).
- `src/Service/ProductMappingMatcher_Dsl.php` — `_getBrandProductMap()`
  joins `p.manufacturer = tb.product_manufacturer_id` (the `p.manufacturer`
  join column stays — real populated column in the 6.7 schema).
- `AGENTS.md` — `tdmp_brand` schema contract bullet (columns, live-version
  pin, composite FK, draft-merge rationale, plugin-only readers).
- `README.md` — table row note "(pinned to the live version)".
- `CHANGELOG.md` — `[Unreleased]` → `### Changed` entry.

# 3. Key Changes

- **Version pin in one place**: callers never pass the version —
  `TdmpBrandService::insertMany()` hardcodes `LIVE_VERSION_HEX`, mirroring
  `TdmpProductService`.
- **Composite FK is versioning-safe**: draft `product_manufacturer` rows
  (random version ids) never match the pinned live version, so the FK fires
  only on real manufacturer deletion, never on draft drops during version
  merges.
- **Migration order matters**: the repair prunes orphan rows (missing or
  draft-only manufacturers) *before* adding the FK — otherwise the constraint
  creation would fail.
- **`_loadShopBrandMap()` live filter is a correctness fix, not cosmetics**:
  without `pm.version_id = 0x...LIVE_VERSION`, a manufacturer existing only
  as a draft row would yield an INSERT violating the FK.

# 4. Deviations from Plan

- **Command syntax**: the plan's `database:migrate --all` is a *timestamp
  cap*, not an identifier selector — plugin migrations run as
  `bin/console database:migrate TopdataMapperSW6 --all`. Ran that instead.
- Everything else exactly per plan (verified: no stray `brand_id`
  references in `src/` besides `topdata_brand_id` and the 1804 legacy-guard).

# 5. Technical Decisions

- Column renamed in place via `CHANGE COLUMN` (PK follows the leading
  column automatically), then the PK is re-declared explicitly to include the
  version column — same approach as migration 1301.
- Legacy migrations 1301/1303 left untouched: their chain converges to
  `brand_id`, which 1804 then migrates (guarded, idempotent).
- `manufacturerId` API key rename in the browse service is safe: the admin
  grid only reads `manufacturerName` / `topdataBrandId` / timestamps
  (verified by grep over `src/Resources`).

# 6. Testing Notes

Manual verification in the dev shop (`sw67-www` / `sw67-mariadb` containers,
Shopware root `/www`), `php -l` clean on all six touched PHP files:

1. `bin/console database:migrate TopdataMapperSW6 --all` → **1 out of 1**
   migrations executed (1804), cache cleared automatically.
2. `SHOW CREATE TABLE tdmp_brand` → `product_manufacturer_id` +
   `product_manufacturer_version_id` binary(16) NOT NULL, PK on both +
   `topdata_brand_id`, `CONSTRAINT fk_tdmp_brand_product_manufacturer ...
   REFERENCES product_manufacturer (id, version_id) ON DELETE CASCADE` —
   exactly the plan's expected output. No legacy `brand_id` column.
3. Row count 64 — data survived the migration (rows for deleted/draft
   manufacturers would have been pruned; none were).
4. `topdata:mapper:import --mapping=brand` → **Built tdmp_brand: 64 rows
   across 3 page(s)** — insert path incl. FK pin proven.
5. `topdata:mapper:import` (full) → product build 50 rows / 5 conflicts +
   brand build 64 rows; no FK or SQL errors. (Configured strategy does not
   reference `topdataBrandIds`, so the matcher's brand query was not
   exercised by the import itself.)
6. Admin smoke: cache cleared; `listBrandMappings` COUNT + page queries
   replayed against the DB → total 64, manufacturer names resolved (3M,
   Aurora, Avery Zweckform …).
7. DSL matcher's `_getBrandProductMap()` query replayed → joins resolve
   (e.g. topdata_brand_id 108215 → 440 products).

# 7. Documentation Updates

- `CHANGELOG.md` — `[Unreleased]` → `### Changed` (schema contract rename
  entry, consumer-impact note).
- `README.md` — `tdmp_brand` table row "(pinned to the live version)".
- `AGENTS.md` — `tdmp_brand` schema contract bullet (mirrors the
  `tdmp_product` bullet: columns, version pin, composite FK + draft-merge
  rationale, plugin-only readers).
- `.gitignore` — no change needed.

# 8. Follow-ups / Out of scope

- Renaming `tdmp_product.product_id` remains out of scope (breaking for
  TopFeed / TopFinder).
- The draft DAL-facade plan (`260818_0943`) stays compatible — its
  `FkField` will reference the new column names.
- Report written; `git status` sanity-checked; committing left to the user.