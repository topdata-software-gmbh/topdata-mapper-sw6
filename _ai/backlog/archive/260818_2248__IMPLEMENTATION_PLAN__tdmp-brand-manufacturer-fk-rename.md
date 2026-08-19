---
filename: "_ai/backlog/active/260818_2248__IMPLEMENTATION_PLAN__tdmp-brand-manufacturer-fk-rename.md"
title: "tdmp_brand: rename brand_id to product_manufacturer_id, pin version, add FK to product_manufacturer"
createdAt: 2026-08-18 22:48
updatedAt: 2026-08-18 22:48
status: completed
completedAt: 2026-08-18 23:59
priority: medium
tags: [migration, schema, fk, tdmp_brand, refactor]
estimatedComplexity: simple
documentRevision: 1
documentType: IMPLEMENTATION_PLAN
---

# tdmp_brand: rename brand_id to product_manufacturer_id, pin version, add FK

## 1. Problem

`tdmp_brand` (Topdata brand id ↔ SW6 manufacturer mapping) currently has:

```sql
brand_id binary(16) NOT NULL,            -- Shopware product_manufacturer.id
topdata_brand_id bigint(20) NOT NULL,    -- Topdata brand id
PRIMARY KEY (brand_id, topdata_brand_id)
```

Three issues:

1. **Naming**: `brand_id` is ambiguous — the Shopware entity is `product_manufacturer`.
   SW6 core names FK columns after the target table (`product_manufacturer_id`,
   see `product.product_manufacturer_id` / `product.product_manufacturer_version_id`).
2. **No version column**: `product_manufacturer` is a **versioned** table (PK
   `(id, version_id)`, `VersionField` in `ProductManufacturerDefinition`). A proper
   FK must reference both columns — the same problem `tdmp_product` already solved
   with `product_version_id` pinned to the live version.
3. **No FK**: mappings survive manufacturer deletion (stale rows) and nothing
   guarantees referential integrity.

`tdmp_product` keeps its current columns: TopFeed SELECTs and joins on
`product_id` / `product_version_id`, TopFinder SELECTs `product_id` — renaming
there would break both consumers. `tdmp_brand` on the other hand is **only read
inside this plugin** (verified: no `tdmp_brand` references in
`topdata-topfeed-sw6-v9` or `topdata-topfinder-pro-sw6`), so the rename is safe.

## 2. Executive summary

Align `tdmp_brand` with the `tdmp_product` pattern:

- `brand_id` → **`product_manufacturer_id`** (binary(16))
- new **`product_manufacturer_version_id`** (binary(16)), always pinned to the
  live version (`0fa91ce3e96a4bc2be4bd9ce752c3425`), mirrors
  `tdmp_product.product_version_id`
- PK becomes `(product_manufacturer_id, product_manufacturer_version_id, topdata_brand_id)`
- new composite FK **`fk_tdmp_brand_product_manufacturer`** →
  `product_manufacturer(id, version_id) ON DELETE CASCADE` — fires only on real
  manufacturer deletion, never on draft-row drops during version merges
- new idempotent migration `Migration2026081804...` repairs existing installs;
  `Migration2026081300CreateTdmpTables` is edited in place to the final schema
  (repo convention: fresh installs get the final schema directly, the new
  migration guards on `_columnExists('tdmp_brand', 'brand_id')`)
- mechanical renames in the four code locations that touch `brand_id`
  (`TdmpBrandService`, `TdmpMappingBuildService`, `TdmpMappingBrowseService`,
  `ProductMappingMatcher_Dsl`)

Consumers are unaffected (they never read `tdmp_brand`). No DAL, no admin JS
changes (the mappings grid already shows `manufacturerName` / `topdataBrandId`).

## 3. Project environment

- Project Name: SW6.7 Plugin (`topdata-mapper-sw6`)
- Backend root: `src`
- PHP Version: 8.2+
- Shopware: `6.7.*`, Doctrine DBAL 4.x, Symfony 7.4
- Admin: Vue 3 / Vite (SW 6.7)
- No tests, no CI in this repo — verification is the dev shop
  (`sw67-www` container, shop root bind-mounted at `/www`, DB `sw6`)

## 4. Conventions & reference material

- Repo conventions: `AGENTS.md` — raw DBAL services, UUIDs as lowercase hex
  without `0x` prefix, `0x%s` literals in `insertMany()`, `_` private method
  prefix, docblocks, constructor property promotion, idempotent migrations
  (information_schema guards) in this plugin only, migration 1300 is the
  "final schema" that repair migrations converge on (see 1301/1303 docblocks).
- Schema contract precedent: `tdmp_product` (`TdmpProductService::LIVE_VERSION_HEX`,
  composite FK `fk_tdmp_product_product`, migration 1301) — this plan mirrors it.
- DBAL 4.x: `fetchOne`/`fetchAllAssociative`/`executeStatement` only.
- Verified shop facts (dev DB): `product_manufacturer` PK `(id, version_id)`;
  `tdmp_brand` currently has no version column and no FK; `product` has both
  `product_manufacturer_id` and a legacy `manufacturer` column (both populated —
  matcher keeps using `p.manufacturer`).

## 5. Implementation phases

### Phase 1 — Schema: edit Migration1300 + new repair migration

**1a. [MODIFY] `src/Migration/Migration2026081300CreateTdmpTables.php`**

Edit the `tdmp_brand` CREATE statement in place to the final schema (fresh
installs; the FK inline matches how `tdmp_product`'s FK already lives there):

```php
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdmp_brand` (
                `product_manufacturer_id`          binary(16) NOT NULL,
                `product_manufacturer_version_id`  binary(16) NOT NULL,
                `topdata_brand_id`                 bigint(20) NOT NULL,
                `created_at`                       DATETIME(3) NOT NULL,
                `updated_at`                       DATETIME(3) NOT NULL,
                PRIMARY KEY (`product_manufacturer_id`, `product_manufacturer_version_id`, `topdata_brand_id`),
                KEY `idx_tdmp_brand_topdata_brand_id` (`topdata_brand_id`),
                CONSTRAINT `fk_tdmp_brand_product_manufacturer`
                    FOREIGN KEY (`product_manufacturer_id`, `product_manufacturer_version_id`)
                    REFERENCES `product_manufacturer` (`id`, `version_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
```

**1b. [NEW FILE] `src/Migration/Migration2026081804TdmpBrandManufacturerFk.php`**

Repairs shops that executed the old 1300. Idempotent; a no-op on fresh
installs (guard: `brand_id` column no longer exists). Mirrors migration 1301's
style. The FK needs every surviving row to reference an existing **live**
manufacturer row, so rows pointing at missing/draft manufacturers are deleted
first; the version is backfilled with the live version, then pinned NOT NULL.
The `brand_id` column cannot be `DROP`ped — it is renamed in place via
`CHANGE COLUMN` (the PK follows automatically, it is re-declared explicitly
afterwards to include the new version column):

```php
<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Renames tdmp_brand.brand_id → product_manufacturer_id and adds the
 * product_manufacturer_version_id column + composite FK, mirroring the
 * tdmp_product pattern (migration 1301):
 *
 * - product_manufacturer is versioned (PK (id, version_id)), so the FK must be
 *   composite; the version is pinned to the live version so the FK fires only
 *   when a manufacturer is really deleted, never when draft rows are dropped
 *   during a version merge.
 * - Rows referencing manufacturers that no longer exist (or exist only as
 *   drafts) are deleted before the FK is added — otherwise the constraint
 *   cannot be created.
 *
 * Migration 1300 now creates the final schema directly (fresh installs), so
 * this is a no-op there: the guard checks for the legacy `brand_id` column.
 *
 * 08/2026 created
 */
class Migration2026081804TdmpBrandManufacturerFk extends MigrationStep
{
    private const string LIVE_VERSION_HEX = '0fa91ce3e96a4bc2be4bd9ce752c3425';

    public function getCreationTimestamp(): int
    {
        return 2026081804;
    }

    public function update(Connection $connection): void
    {
        if (!$this->_columnExists($connection, 'tdmp_brand', 'brand_id')) {
            return; // already on the final schema
        }

        // Keep only rows whose live manufacturer row exists (FK requirement)
        $connection->executeStatement(
            'DELETE t FROM tdmp_brand t LEFT JOIN product_manufacturer pm
               ON pm.id = t.brand_id AND pm.version_id = 0x' . self::LIVE_VERSION_HEX . '
              WHERE pm.id IS NULL'
        );

        // Rename the id column in place (PK follows automatically)
        $connection->executeStatement('ALTER TABLE tdmp_brand CHANGE COLUMN `brand_id` `product_manufacturer_id` binary(16) NOT NULL');

        // Add the version column nullable, backfill with the live version, pin NOT NULL
        $connection->executeStatement('ALTER TABLE tdmp_brand ADD COLUMN `product_manufacturer_version_id` binary(16) NULL AFTER `product_manufacturer_id`');
        $connection->executeStatement('UPDATE tdmp_brand SET product_manufacturer_version_id = 0x' . self::LIVE_VERSION_HEX);
        $connection->executeStatement('ALTER TABLE tdmp_brand MODIFY COLUMN `product_manufacturer_version_id` binary(16) NOT NULL');

        // Drop rows duplicated by the version pin (same manufacturer + topdata brand id)
        $connection->executeStatement('
            DELETE t1 FROM tdmp_brand t1 INNER JOIN tdmp_brand t2
               ON t1.product_manufacturer_id = t2.product_manufacturer_id
              AND t1.product_manufacturer_version_id = t2.product_manufacturer_version_id
              AND t1.topdata_brand_id = t2.topdata_brand_id
             WHERE t1.created_at > t2.created_at
        ');

        // Rebuild PK with the version (mirrors tdmp_product) and add the FK
        $connection->executeStatement('ALTER TABLE tdmp_brand DROP PRIMARY KEY');
        $connection->executeStatement('
            ALTER TABLE tdmp_brand
                ADD PRIMARY KEY (`product_manufacturer_id`, `product_manufacturer_version_id`, `topdata_brand_id`)
        ');
        if (!$this->_foreignKeyExists($connection, 'tdmp_brand', 'fk_tdmp_brand_product_manufacturer')) {
            $connection->executeStatement('
                ALTER TABLE tdmp_brand
                    ADD CONSTRAINT `fk_tdmp_brand_product_manufacturer`
                    FOREIGN KEY (`product_manufacturer_id`, `product_manufacturer_version_id`)
                    REFERENCES `product_manufacturer` (`id`, `version_id`) ON DELETE CASCADE
            ');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function _columnExists(Connection $connection, string $table, string $column): bool
    {
        return (bool)$connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    private function _foreignKeyExists(Connection $connection, string $table, string $fk): bool
    {
        return (bool)$connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $fk]
        );
    }
}
```

Note: the PK (leading columns) doubles as the index the FK needs; the existing
`idx_tdmp_brand_topdata_brand_id` key stays untouched. Migrations 1301/1303
stay as they are — their legacy chain converges to `brand_id`, which 1804 then
migrates.

### Phase 2 — Code: rename brand_id references

**2a. [MODIFY] `src/Service/Db/TdmpBrandService.php`**

- Make the live version constant public (mirrors `TdmpProductService::LIVE_VERSION_HEX`)
  and pin it inside `insertMany()` — callers never pass the version:

```php
    public const string LIVE_VERSION_HEX = '0fa91ce3e96a4bc2be4bd9ce752c3425';
```

- `insertMany()` — column list, values and docblock:

```php
    /**
     * @param array<int, array{product_manufacturer_id: string, topdata_brand_id: int, created_at: string, updated_at: string}> $rows product_manufacturer_id is hex (no 0x prefix); product_manufacturer_version_id is always pinned to the live version
     */
    public function insertMany(array $rows): int
    {
        $inserted = 0;
        foreach (array_chunk($rows, self::INSERT_BATCH) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $values[] = sprintf(
                    "(0x%s, 0x%s, %d, '%s', '%s')",
                    $row['product_manufacturer_id'],
                    self::LIVE_VERSION_HEX,
                    $row['topdata_brand_id'],
                    $row['created_at'],
                    $row['updated_at']
                );
            }
            $inserted += $this->connection->executeStatement(
                'INSERT INTO tdmp_brand (product_manufacturer_id, product_manufacturer_version_id, topdata_brand_id, created_at, updated_at) VALUES ' . implode(',', $values)
            );
        }

        return $inserted;
    }
```

- `getBrandMap()` — column + alias rename:

```php
    /**
     * Map topdata_brand_id (int) → SW6 product_manufacturer id (hex).
     *
     * @return array<int, string>
     */
    public function getBrandMap(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT topdata_brand_id, LOWER(HEX(product_manufacturer_id)) AS product_manufacturer_id FROM tdmp_brand'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['topdata_brand_id']] = $row['product_manufacturer_id'];
        }

        return $map;
    }
```

Also update the class docblock ("Topdata topdata_brand_id ↔ SW6 manufacturer id",
mention the live-version pin + FK).

**2b. [MODIFY] `src/Service/TdmpMappingBuildService.php`**

- `buildBrandMappings()` — row key `'brand_id'` → `'product_manufacturer_id'`
  (version is pinned inside `insertMany()`, no version key needed):

```php
                $rows[] = [
                    'product_manufacturer_id' => $brandId,
                    'topdata_brand_id'        => (int)$apiRow->id,
                    'created_at'              => $now,
                    'updated_at'              => $now,
                ];
```

- `_loadShopBrandMap()` — alias rename **and** a live-version filter. The
  filter is required, not cosmetic: without it, a manufacturer existing only as
  a draft row would pass the map but the INSERT would violate the new FK (no
  live row to reference):

```php
    /**
     * @return array<string, string> normalized manufacturer name → product_manufacturer_id hex
     */
    private function _loadShopBrandMap(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(pm.id)) AS product_manufacturer_id, MIN(pmt.name) AS name
             FROM product_manufacturer pm
             JOIN product_manufacturer_translation pmt
               ON pmt.product_manufacturer_id = pm.id
              AND pmt.product_manufacturer_version_id = pm.version_id
             WHERE pmt.name IS NOT NULL
               AND pm.version_id = 0x' . TdmpBrandService::LIVE_VERSION_HEX . '
             GROUP BY pm.id, pm.version_id'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[UtilIdentifierNormalizer::normalizeLabel((string)$row['name'])] = $row['product_manufacturer_id'];
        }

        return $map;
    }
```

**2c. [MODIFY] `src/Service/Db/TdmpMappingBrowseService.php`**

`listBrandMappings()` — column renames in the COUNT + page queries and the
version joins (matches the product grid's `p.version_id = mp.product_version_id`
pattern):

```php
        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(*)
               FROM tdmp_brand mb
               JOIN product_manufacturer pm
                 ON pm.id = mb.product_manufacturer_id
                AND pm.version_id = mb.product_manufacturer_version_id
               LEFT JOIN (
                   SELECT product_manufacturer_id, product_manufacturer_version_id, MAX(name) AS name
                     FROM product_manufacturer_translation
                    GROUP BY product_manufacturer_id, product_manufacturer_version_id
               ) pmt
                 ON pmt.product_manufacturer_id = mb.product_manufacturer_id
                AND pmt.product_manufacturer_version_id = mb.product_manufacturer_version_id
              {$whereSql}",
            $params
        );

        [$sql, $sqlParams] = $this->_buildPageSql(
            "SELECT LOWER(HEX(mb.product_manufacturer_id)) AS product_manufacturer_id,
                    pmt.name AS manufacturer_name,
                    mb.topdata_brand_id,
                    mb.created_at,
                    mb.updated_at
               FROM tdmp_brand mb
               JOIN product_manufacturer pm
                 ON pm.id = mb.product_manufacturer_id
                AND pm.version_id = mb.product_manufacturer_version_id
               LEFT JOIN (
                   SELECT product_manufacturer_id, product_manufacturer_version_id, MAX(name) AS name
                     FROM product_manufacturer_translation
                    GROUP BY product_manufacturer_id, product_manufacturer_version_id
               ) pmt
                 ON pmt.product_manufacturer_id = mb.product_manufacturer_id
                AND pmt.product_manufacturer_version_id = mb.product_manufacturer_version_id
              {$whereSql}
              ORDER BY manufacturer_name ASC",
            $params,
            $page,
            $limit
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'manufacturerId'     => $row['product_manufacturer_id'],
                'manufacturerName'   => (string)($row['manufacturer_name'] ?? ''),
                'topdataBrandId'     => (int)$row['topdata_brand_id'],
                'createdAt'          => (string)$row['created_at'],
                'updatedAt'          => (string)$row['updated_at'],
            ];
        }
```

Update the docblock return type accordingly. The admin JS never reads the
`brandId` key (grid shows only `manufacturerName`, `topdataBrandId`,
`createdAt`, `updatedAt`) — verified, so no JS change and the API key rename
to `manufacturerId` is safe.

**2d. [MODIFY] `src/Service/ProductMappingMatcher_Dsl.php`**

`_getBrandProductMap()` — rename the tdmp_brand column reference (the `p.manufacturer`
join column stays — it is a real, populated column in the 6.7 schema and the
query is proven):

```php
                'SELECT tb.topdata_brand_id, LOWER(HEX(p.id)) AS product_id
                   FROM tdmp_brand tb
                   JOIN product p
                     ON p.manufacturer = tb.product_manufacturer_id
                    AND p.version_id = 0x' . TdmpProductService::LIVE_VERSION_HEX
```

### Phase 3 — Documentation

**3a. [MODIFY] `AGENTS.md`** — extend the `tdmp_brand` description in the
architecture notes: new columns (`product_manufacturer_id`,
`product_manufacturer_version_id` always live version), composite FK
`fk_tdmp_brand_product_manufacturer (product_manufacturer_id, product_manufacturer_version_id)
→ product_manufacturer(id, version_id) ON DELETE CASCADE` with the same
draft-merge rationale as the product FK; note that `tdmp_brand` is only read
by this plugin (DSL matcher), consumers never touch it.

**3b. [MODIFY] `README.md`** — update the table row for `tdmp_brand`
(Topdata `topdata_brand_id` ↔ Shopware `product_manufacturer.id`, pinned to
the live version).

**3c. [MODIFY] `CHANGELOG.md`** — add an Unreleased entry:

```markdown
### Changed

- `tdmp_brand`: `brand_id` renamed to `product_manufacturer_id`, new
  `product_manufacturer_version_id` column (pinned to the live version) and a
  composite FK to `product_manufacturer(id, version_id)` with `ON DELETE
  CASCADE` — internal rename, no consumer impact (TopFeed / TopFinder never
  read this table).
```

**3d. `.gitignore`** — no new artifact types; no change needed.

### Phase 4 — Verification

Run from the Shopware root (`/topdata/clones/sw67/vol/www`):

```bash
# 1. run the pending migration
docker exec sw67-www php /www/bin/console database:migrate --all

# 2. schema is final: FK present, no legacy column
docker exec sw67-mariadb mariadb -u root -p11111 sw6 -e "SHOW CREATE TABLE tdmp_brand\G"
#    expect: product_manufacturer_id + product_manufacturer_version_id, PK on both + topdata_brand_id,
#    CONSTRAINT fk_tdmp_brand_product_manufacturer ... REFERENCES product_manufacturer (id, version_id) ON DELETE CASCADE

# 3. data survived (row count roughly unchanged; rows for deleted/draft manufacturers were pruned)
docker exec sw67-mariadb mariadb -u root -p11111 sw6 -e "SELECT COUNT(*) FROM tdmp_brand"

# 4. rebuild brand mappings (insert path incl. FK pin)
docker exec sw67-www php /www/bin/console topdata:mapper:import --mapping=brand

# 5. full import (product build consumes the brand map when the strategy uses topdataBrandIds)
docker exec sw67-www php /www/bin/console topdata:mapper:import

# 6. admin smoke test: "Topdata Mapper" → Mappings → Brands tab still lists manufacturers
docker exec sw67-www rm -rf /www/var/cache/*
```

Ad-hoc logging if a step fails: `file_put_contents('/tmp/debug.log', $msg, FILE_APPEND)`.

### Phase 5 — Housekeeping & report

- Re-read the final diff (no stray `brand_id` references):
  `rg -n "brand_id" src/` must only match `topdata_brand_id` and legacy
  migration 1301/1303 guards.
- Write `_ai/backlog/reports/260818_2248__IMPLEMENTATION_REPORT__tdmp-brand-manufacturer-fk-rename.md`
  (frontmatter per repo convention, summary, files changed, key changes,
  deviations, testing notes).
- `git status` sanity check; leave committing to the user.

## 6. Out of scope

- Renaming `tdmp_product.product_id` → `sw6_product_id`: **breaking** — TopFeed
  (`TopdataToProductService.php:52-58`) and TopFinder
  (`TdfiDeviceToProductImportService.php:146`) SELECT/join on the current names.
- The draft DAL-facade plan (`260818_0943`) adds surrogate `id` columns later —
  this rename is independent and compatible (its `FkField` → `ProductManufacturerDefinition`
  will reference the new column names).
- No changes to the conflict-resolution table, DSL grammar, or admin JS.