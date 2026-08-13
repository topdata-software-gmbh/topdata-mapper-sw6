# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed
- `tdmp_product` schema: `topdata_id` renamed to `top_id`; `product_version_id` is
  now always the live version (constant, see `TdmpProductService::LIVE_VERSION_HEX`)
  so the new FK `fk_tdmp_product_product (product_id, product_version_id) →
  product(id, version_id) ON DELETE CASCADE` is safe against Shopware's versioning
  (draft rows deleted on version merge never cascade). `tdmp_brand.topdata_id`
  renamed to `top_id` as well.
- `ProductMappingMatcherInterface::matchRow()` now returns
  `list<array{product_id: string}>` — matchers must only return live-version
  products; consumers (TopFeed, TopFinder) updated to read `top_id`.
- Added idempotent migration `Migration2026081301TdmpTopIdAndProductFk` that
  migrates existing installs (normalizes versions, renames columns, adds FK).
- Replaced the skeleton example controllers/command with the mapping engine:
  mapping tables (`tdmp_product`, `tdmp_brand`), the mapping-API webservice
  client, the local identifier matcher, and the `topdata:mapper:import` command.
- The import command now prints a summary table (pages, API rows, matched,
  unmatched, duration) at the end of the run.

## [1.0.0] - 2026-08-13

### Added
- Initial release
