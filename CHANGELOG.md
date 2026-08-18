# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Removed
- **Help tooltip on the DSL textarea** — the question-mark help icon next to
  the DSL input is gone; the DSL-Hilfe modal remains the single source of
  syntax help (the truncated tooltip text was confusing).
- **Visual DSL builder** from the settings strategy editor — the DSL string
  (with debounced live validation) and the preset chips are now the only
  editor. `GET /api/_action/topdata-mapper/strategy` no longer returns
  `providers`; `POST .../validate-strategy` no longer returns `ast`;
  `DslSerializer::toArray()` removed.

### Changed
- **DSL grammar: `( )` groups for explicit precedence** — parenthesized
  sub-expressions (`(a | b) & c`) now parse and evaluate; `&` still binds
  tighter than `|` without parens. Recursive-descent parser
  (`DslAndExpr::items`), canonical serializer (parens preserved), matcher
  evaluation and the provider-existence check are recursive now. Existing
  flat strategies (default + presets) are unaffected.

### Added
- **Admin: "Katalog > Topdata Mapper" navigation group** — new group under
  the catalog containing the existing conflicts page (moved out of Products)
  and a new read-only **Mappings** browser (tabs for product and brand
  mappings, server-side pagination + search, `topdata_mapper:read`). New
  API routes `GET /api/_action/topdata-mapper/mappings` and
  `GET /api/_action/topdata-mapper/brands`.
- **Matching DSL engine** (`src/Service/Dsl/*`, `ProductMappingMatcher_Dsl`):
  set algebra over identifier dimensions — shop field (`product.ean`,
  `product.manufacturer_number`, `product.manufacturer`,
  `product.product_number`, `property.<group>`, `customField.<name>`) paired
  with an API dimension (`ean`, `mpn`, `pcd`, `articleNumbers[.<provider>]`,
  `topdataBrandIds`). Single PHP parser/serializer; invalid strategies make the
  import fail loudly. Replaces `ProductMappingMatcher_EanMpn` (removed).
- **Conflict handling** in the product build: a product matching >1 Topdata
  article is a conflict — candidate ids + identifier previews are persisted in
  `tdmp_product_conflict_resolutions` (new migration
  `Migration2026081302CreateConflictResolutionsTable`), `tdmp_product` keeps
  only the chosen row, auto-chosen resolutions are refreshed per import.
- **Admin modules**: settings page (strategy editor with preset chips, visual
  builder, live validation, authoritative save via
  `TopdataMapperActionController`) and conflicts page (Products navigation,
  paginated grid, immediate radio resolve without re-import). New privileges
  `topdata_mapper:read` / `topdata_mapper:update` (new `acl.xml`).
- `topdata:mapper:import` summary now also reports the conflict count; the
  brand build runs before the product build when the strategy references
  `topdataBrandIds`.
- New `matchingStrategy` config field (raw DSL textarea; the settings page is
  the preferred editor).
- **DSL syntax help modal** in the settings page: a help button next to the
  DSL textarea opens a modal explaining the grammar, shop fields, identifiers,
  allowed pairings (rendered from the pairing matrix) and examples.
- Settings page preset chip labels are now translated via snippets
  (de-DE/en-GB), keyed by preset key with the backend label as fallback.

### Fixed
- **Mappings browser + conflicts grid returned no rows** — the grid queries
  referenced non-existent SW 6.7 columns (`media_thumbnail.url`,
  `product.cover_id`), so every list request failed with a SQL error. Queries
  now join the versioned `product_media` cover chain and `media_thumbnail.path`
  (URL `/media/<path>`), and pick exactly one translation per
  product/manufacturer (multi-language shops duplicated rows).

### Changed
- **Mapping API v2 contract renames** (lockstep with t2-webservice):
  `products_id` → `topdata_product_id`, `oem` → `mpn`, `distributor` →
  `articleNumbers` (per-provider object), new `topdataBrandIds` dimension.
- `tdmp_product.top_id` renamed to `topdata_product_id`,
  `tdmp_brand.top_id` renamed to `topdata_brand_id` (migrations 1300/1301
  updated in place); `product_version_id` is now always the live version
  (constant, see `TdmpProductService::LIVE_VERSION_HEX`) so the FK
  `fk_tdmp_product_product (product_id, product_version_id) → product(id,
  version_id) ON DELETE CASCADE` is safe against Shopware's versioning.
- The product build requests only the identifier types the configured DSL
  strategy references (e.g. only `ean` for `product.ean:ean`) instead of
  always fetching `['ean', 'mpn', 'pcd', 'articleNumbers']`, and matches via
  the configured DSL strategy instead of the hardcoded EAN/OEM logic. `mpn`
  is still requested when the strategy references `topdataBrandIds` (API
  contract: topdataBrandIds is only returned when mpn is requested).
- `topdata:mapper:import` now prints the configured matching strategy as a
  flat table (shop field × API dimension) at the start of the product
  import — parens, `&` and `|` are implied by the grammar and not rendered.
- The mapper import now uses keyset pagination (`cursor` / `next_cursor`)
  instead of `start` offsets when streaming `/v2/mapping/product` (brand
  import stays offset-based). The client throws when the webservice reports
  `has_more` without a `next_cursor`.
- `ProductMappingMatcherInterface::matchRow()` returns
  `list<array{product_id: string}>` — matchers must only return live-version
  products; consumers (TopFeed, TopFinder) read `topdata_product_id`.

## [1.0.0] - 2026-08-13

### Added
- Initial release
