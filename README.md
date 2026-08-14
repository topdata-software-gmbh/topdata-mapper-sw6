# Topdata Mapper SW6

Owns the shared **Topdata-ID ↔ SW6-ID mapping** for Topdata Shopware plugins.
It is the **single writer** of the mapping tables; `TopdataTopFeedSW6` and
`TopdataTopFinderProSW6` depend on this plugin and read from them.

## Tables

| Table | Purpose |
|---|---|
| `tdmp_product` | Topdata `topdata_product_id` ↔ Shopware `product.id` (one row per SW6 variant) |
| `tdmp_brand` | Topdata `topdata_brand_id` ↔ Shopware `product_manufacturer.id` |
| `tdmp_product_conflict_resolutions` | Conflicts (product matched >1 Topdata article): candidate ids + identifier previews, auto/user status |

## Data source

Fetched from the webservice **mapping API** (third v2 API group, shared
feed+finder infrastructure):

- `GET /v2/mapping/product` — identifier dimensions (`ean`/`mpn`/`pcd`/`articleNumbers` per provider) per product
- `GET /v2/mapping/brand` — Topdata brand list
- `GET /v2/mapping/provider` — reserved providers of the API user

## Matching strategy (DSL)

`TopdataMapperSW6.config.matchingStrategy` holds a set algebra over identifier
dimensions — which Shopware field matches which Topdata identifier. The
**settings page** (Settings → Plugins → Topdata Mapper) is the preferred
editor: preset chips, visual builder, live validation. The import fails
loudly on an invalid stored strategy.

Default: `product.ean:ean | product.manufacturer_number:mpn | product.manufacturer_number:pcd | product.product_number:articleNumbers`

## Usage

```bash
bin/console topdata:mapper:import                 # build product + brand mappings
bin/console topdata:mapper:import --mapping=product
bin/console topdata:mapper:import --mapping=brand
```

When the strategy references `topdataBrandIds`, the brand build runs first
(brand-scoped leaves need fresh `tdmp_brand` rows).

Credentials (API base URL + API key `sk-...`) are configured in the plugin
settings or prompted on the CLI.

## Conflicts

Products matching >1 Topdata article are conflicts: `tdmp_product` keeps only
the chosen row (auto = lowest id; user choices survive re-imports), the admin
module **Products → Topdata mapping conflicts** shows candidates and resolves
immediately without a re-import.

## Consumers

- **TopFinder** reads `tdmp_product` to resolve `topdata_product_id` → SW6 product ids
  when importing `tdfi_device_to_product`.
- **TopFeed** reads/writes `tdmp_product` via its own matcher (DSL/strategy-based).

## Installation

1. Download the plugin
2. Upload to your Shopware 6 installation
3. Install and activate the plugin

## Requirements

- Shopware 6.7.*
- `topdata/topdata-foundation-sw6` ^1.4.0 (runtime dependency)

## License

MIT
