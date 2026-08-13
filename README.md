# Topdata Mapper SW6

Owns the shared **Topdata-ID ↔ SW6-ID mapping** for Topdata Shopware plugins.
It is the **single writer** of the mapping tables; `TopdataTopFeedSW6` and
`TopdataTopFinderProSW6` depend on this plugin and read from them.

## Tables

| Table | Purpose |
|---|---|
| `tdmp_product` | Topdata `products_id` ↔ Shopware `product.id` (one row per SW6 variant) |
| `tdmp_brand` | Topdata brand id ↔ Shopware `product_manufacturer.id` |

## Data source

Fetched from the webservice **mapping API** (third v2 API group, shared
feed+finder infrastructure):

- `GET /v2/mapping/product` — identifier dimensions (`ean`/`oem`/`pcd`/`distributor`) per product
- `GET /v2/mapping/brand` — Topdata brand list

## Usage

```bash
bin/console topdata:mapper:import                 # build product + brand mappings
bin/console topdata:mapper:import --mapping=product
bin/console topdata:mapper:import --mapping=brand
```

Credentials (API base URL + API key `sk-...`) are configured in the plugin
settings or prompted on the CLI.

## Consumers

- **TopFinder** reads `tdmp_product` to resolve `products_id` → SW6 product ids
  when importing `tdfi_device_to_product`.
- **TopFeed** reads/writes `tdmp_product` via its own matcher (EAN/OEM/PCD /
  distributor / custom-field strategies).

## Installation

1. Download the plugin
2. Upload to your Shopware 6 installation
3. Install and activate the plugin

## Requirements

- Shopware 6.7.*
- `topdata/topdata-foundation-sw6` ^1.4.0 (runtime dependency)

## License

MIT
