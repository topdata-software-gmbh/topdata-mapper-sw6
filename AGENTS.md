# AGENTS.md — Topdata Mapper SW6

## Project

Shopware 6.7 plugin that **owns the shared Topdata-ID ↔ SW6-ID mapping** (`tdmp_product`, `tdmp_brand` tables). It is the **single writer**; `topdata-topfeed-sw6-v9` and `topdata-topfinder-pro-sw6` depend on it and only read. TopFeed can override the product matcher.

- **Plugin class**: `Topdata\TopdataMapperSW6\TopdataMapperSW6` (namespace → `src/`)
- **Requires**: `php ^8.2`, `shopware/core 6.7.*`, `topdata/topdata-foundation-sw6 ^1.4.0`
- **Consumers**: TopFeed (reads/writes via own matcher), TopFinder (reads `tdmp_product`)

## Commands

Run from the Shopware root, **not** the plugin dir (`bin/console` lives at `/topdata/clones/sw67/vol/www/bin/console`):

| Command | Action |
|---|---|
| `bin/console topdata:mapper:import` | Rebuild product + brand mappings (full table replace) |
| `bin/console topdata:mapper:import --mapping=product` | Only `tdmp_product` |
| `bin/console topdata:mapper:import --mapping=brand` | Only `tdmp_brand` |

Credentials come from plugin config (`apiBaseUrl`, `apiKey` `sk-...`, key `TopdataMapperSW6.config`) or are prompted on the CLI.

## Architecture notes

- **No DAL entities** — `src/Service/Db/` services are raw DBAL. UUIDs are passed around as **lowercase hex strings without `0x` prefix**; `insertMany()` builds raw SQL with `0x%s` literals (batch 500). Read lookups use `LOWER(HEX(id))`.
- **Full-table replace**: build flow streams the mapping API in pages of 5000, matches locally, then `TRUNCATE` + batch insert. No merge/upsert logic.
- **Pluggable matcher**: `TdmpMappingBuildService` depends on `ProductMappingMatcherInterface`; the default `ProductMappingMatcher_EanMpn` (ean↔`product.ean`, oem↔`product.manufacturer_number`) is wired in `services.xml`. Keep this interface stable — TopFeed supplies its own matcher.
- **Webservice client** extends foundation's `AbstractTopdataWebserviceV2Client` (unified v2 pagination: `rows` + `pagination.has_more`). Ping endpoint is overridden to `/mapping/brand` — the default `/revision` is feed-only and fails with mapper API keys.
- **Migration** is idempotent (`CREATE TABLE IF NOT EXISTS`) in this plugin only. Foundation code is tree-shaken into consumer release builds — only classes `use`d are copied, so keep shared logic in this plugin and don't rely on foundation migrations.
- Identifier normalization in `src/Helper/UtilIdentifierNormalizer.php` mirrors TopFeed's `UtilMappingHelper` — keep both in sync.

## Conventions (PHP)

From `topdata-foundation-sw6/ai_docs/CONVENTIONS-PHP.md`:
- Private methods prefixed with `_` (e.g. `_myPrivateMethod()`)
- Class + method docblocks required (no redundant `@return void` / `@param` without extra info)
- Constructor property promotion with `private readonly`; type hints on all params/returns
- `match` over `switch` when possible
- Command classes named `Command_Tdmp*` with `#[AsCommand]` attribute
- No php-cs-fixer config in this repo; the phar lives in `topdata-topfeed-sw6-v9/` (`php php-cs-fixer.phar fix`)

## Testing / CI

No tests, no phpunit config, no CI in this repo (`tests/` is empty). Verify via the import command or by checking the plugin builds in the dev shop.

## Debug workflow

Dev shop is bind-mounted at `/www` in the `sw67-www` docker container:
- Cache clear: `docker exec sw67-www rm -rf /www/var/cache/*`
- Ad-hoc debug logging: `file_put_contents('/tmp/debug.log', $msg, FILE_APPEND)` (inside container)

## Workflow artifacts

- `_ai/backlog/active/` — implementation plans, `_ai/backlog/reports/` — implementation reports (documented in the report frontmatter format)
- Keep `CHANGELOG.md` updated (Keep a Changelog format)