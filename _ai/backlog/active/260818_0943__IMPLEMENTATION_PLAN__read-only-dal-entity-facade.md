---
filename: "_ai/backlog/active/260818_0943__IMPLEMENTATION_PLAN__read-only-dal-entity-facade.md"
title: "DAL entity definitions as a read-only facade for the mapping/conflicts tables + standard sw-entity-listing admin pages"
createdAt: 2026-08-18 09:43
updatedAt: 2026-08-18 09:43
status: draft
priority: medium
tags: [dal, entity-definition, admin, listing, migration, adr, refactor]
estimatedComplexity: complex
documentRevision: 1
documentType: IMPLEMENTATION_PLAN
---

# DAL entity definitions as a read-only facade + standard admin listings

## 1. Problem

The plugin owns the shared Topdata-ID ↔ SW6-ID mapping
(`tdmp_product`, `tdmp_brand`, `tdmp_product_conflict_resolutions`) as the
**single writer**. Today there are **no DAL entities at all** — `src/Service/Db/`
is raw DBAL and the admin modules (`topdata-mapper-conflicts`,
`topdata-mapper-mappings`) render hand-rolled `sw-data-grid` pages driven by
custom action routes (`GET /api/_action/topdata-mapper/{conflicts,mappings,brands}`)
with manual pagination/search plumbing.

We want **standard Shopware admin entity listings** (`sw-entity-listing`:
search, sort, pagination, empty states for free), which requires registered
`EntityDefinition`s — but the write path must stay exactly as it is:

- the import build does a **full-table replace** (`TRUNCATE` + batch raw
  `INSERT`s of 500) — routing that through the DAL write pipeline would be an
  order of magnitude slower and would fight the single-writer contract,
- conflict resolution (`resolve-conflict`) writes via the action controller on
  raw DBAL,
- consumers (TopFeed, TopFinder) read the tables with raw SQL and must stay
  unaffected — the tables keep their shape.

So: **add entity definitions as a read-only DAL facade** (reads/listings only,
never a write), and make the read-only admin grids standard `sw-entity-listing`
pages over the repositories. The read-only decision must be recorded in an ADR.

Open questions this plan decides:

1. **Composite PK vs surrogate `id`** — `sw-entity-listing` and the DAL admin
   tooling assume a single `id` property per row. Keeping composite PKs in the
   definitions would leave `entity.id` null (row identification, selection and
   context actions break). **Decision:** add a surrogate `binary(16) id` column
   to all three tables (backfilled in a migration); the natural composite keys
   stay in the DB — they carry the FK cascade and the full-table-replace
   contract.
2. **Do we convert the admin pages?** Yes — that is the point. Conflicts page
   and mappings browser move to `sw-entity-listing` over the repositories; the
   old list action routes + `TdmpMappingBrowseService` + `listConflicts()`/
   `getStats()` become dead code and are deleted. The strategy routes and the
   `resolve-conflict` write route stay.
3. **Product enrichment columns** — the current grids show product
   number/name/thumbnail. With DAL, number/name come from the auto-resolved
   `product` association (`product.productNumber`, `product.name`); the
   **thumbnail is dropped** (documented UX trade-off, see Phase 5).
4. **Search** — `sw-entity-listing`'s built-in term search only searches the
   entity's own fields (and would need `SearchRanking` flags), so the pages
   keep their toolbar search field and build a **criteria filter** on the
   association paths (`product.productNumber LIKE …`) instead.

## 2. Executive summary

- **Phase 0 — ADR**: `_ai/technical_decisions/ADR__260818-1__read-only-dal-entity-facade.md`
  records: DAL entities are a read-only facade; all writes stay raw DBAL.
- **Phase 1 — Schema + write paths**: idempotent migration
  `Migration2026081800AddSurrogateIds` adds `id binary(16) NOT NULL UNIQUE`
  to the three tables and backfills existing rows with `UNHEX(REPLACE(UUID(),'-',''))`
  (no truncation, no PHP loop). The raw write services learn to generate the
  surrogate id (`Uuid::randomHex()`) — `TdmpProductService::insertMany`,
  `TdmpBrandService::insertMany`, `TdmpConflictResolutionService::syncFromBuild`
  (upsert column list) and `applyUserResolution` (re-insert of the tdmp_product
  row). The composite PKs and the FK cascade stay untouched.
- **Phase 2 — Entity definitions + entities**: three classic
  `EntityDefinition`s (`tdmp_product`, `tdmp_brand`,
  `tdmp_product_conflict_resolutions`) with `FkField` → `ProductDefinition`
  (+`ReferenceVersionField`) / `ProductManufacturerDefinition`, explicit
  `ManyToOneAssociationField`s (`product`, `manufacturer`) and minimal getter
  entities. Auto-discovered, no service wiring needed.
- **Phase 3 — Conflicts page**: `topdata-mapper-conflicts` becomes an
  `sw-entity-listing` page over the `tdmp_product_conflict_resolutions`
  repository (proven pattern: `listing` mixin + manual `getList()` +
  `:dataSource`), keeping the stats banner (via repository searches +
  aggregations), status tabs, search filter and the radio-resolve column
  (still POSTing to the existing `resolve-conflict` route — never a DAL write).
- **Phase 4 — Mappings browser**: both tabs become `sw-entity-listing` pages
  over `tdmp_product` / `tdmp_brand`; the old list action routes,
  `TdmpMappingBrowseService` and the `listConflicts`/`getStats` methods are
  deleted; `TopdataMapperApiService` keeps only strategy + resolve methods.
- **Phase 5 — Housekeeping**: `AGENTS.md`, `README.md`, `CHANGELOG.md`
  updated; `.gitignore` checked (no new artifact types); implementation
  report written.

No DAL writes anywhere: the plan contains an explicit verification guard
(`rg` for repository `save`/`create`/`delete` in the plugin).

## 3. Project environment

- Project Name: SW6.7 Plugin (`topdata-mapper-sw6`)
- Backend root: `src`
- PHP Version: 8.2+
- Shopware: `6.7.*`, Doctrine DBAL 4.x, Symfony 7.4
- Admin: Vue 3 / Vite (SW 6.7)
- No tests, no CI in this repo — verification is the dev shop
  (`sw67-www` container, shop root bind-mounted at `/www`)

## 4. Conventions & reference material

- Repo conventions: `AGENTS.md` (raw DBAL service contracts, hex-UUID
  convention, `_` private method prefix, docblocks, property promotion,
  idempotent migrations in this plugin only).
- ADR format: `adr-writer` skill (TradeGuard-style, `_ai/technical_decisions/`,
  `ADR__YYMMDD-N__kebab.md`, next-id helper script, README index).
- Admin listing pattern: `sw67-admin-entity-listing` skill +
  `references/component-patterns.md` — `sw-entity-listing` with the `listing`
  mixin, manual `getList()` (`repository.search(criteria)`), `:dataSource`,
  `@page-change`/`@column-sort`, `sw-page` as root element, `$t()` for
  interpolation.
- SW 6.7 auto-adds `CreatedAtField`/`UpdatedAtField` to every definition —
  all three tables already have the `created_at`/`updated_at` columns.

---

## Phase 0 — ADR: read-only DAL entity facade

[NEW FILE] `_ai/technical_decisions/ADR__260818-1__read-only-dal-entity-facade.md`

Use the `adr-writer` skill workflow: id via
`python3 /home/marc/.opencode/skills/adr-writer/scripts/next_adr_id.py _ai/technical_decisions 20260818`,
fill the template (frontmatter order/keys preserved, ~30–50 lines), create
[NEW FILE] `_ai/technical_decisions/README.md` with a `## Index` (year list)
if the directory has none yet. Content:

```markdown
---
title: Read-only DAL entity facade for the mapping tables
status: Accepted
date: 2026-08-18
deciders: Topdata team
tags: [shopware, dal, architecture, admin, mapping]
---

# Read-only DAL entity facade for the mapping tables

## Context
The plugin owns the shared Topdata-ID ↔ SW6-ID mapping (`tdmp_product`,
`tdmp_brand`, `tdmp_product_conflict_resolutions`) and is the single writer;
TopFeed and TopFinder consume the tables with raw SQL. All access was raw
DBAL (`src/Service/Db/`) and the admin built hand-rolled `sw-data-grid` pages
over custom action routes. We wanted standard Shopware admin entity listings
(search/sort/pagination for free), which require registered entity
definitions — while the write paths are deliberately not DAL-shaped.

## Decision
Add `EntityDefinition`s + entities as a **read-only DAL facade**:
- Three definitions (`tdmp_product`, `tdmp_brand`,
  `tdmp_product_conflict_resolutions`) with a surrogate `id` column added by
  migration (the composite keys stay; they carry the FK cascade and the
  full-table-replace contract). `FkField`s resolve `product`/`manufacturer`
  associations for the admin listings.
- **All writes stay raw DBAL**: the import build (`TRUNCATE` + batch `INSERT`)
  and conflict resolution keep using `src/Service/Db/` + the action
  controller. No code path may call a repository `save`/`create`/`delete`.
- The admin conflicts and mappings pages use `sw-entity-listing` over the
  repositories; the old list action routes and browse service are removed.

## Consequences
+ Standard listings (search/sort/pagination/empty states) with much less
  custom JS and no pagination/search SQL in the backend.
+ Definitions are reusable by future consumers (e.g. TopFeed/TopFinder)
  without table changes.
+ Read model and write model are cleanly separated (SOLID: single
  responsibility per service).
- Schema change: surrogate `id` columns + backfill migration; raw write
  services must generate ids.
- The read routes for these tables are no longer gated by the
  `topdata_mapper:read` action-route check (custom-entity API exposure is
  admin-wide); the write path keeps its `topdata_mapper:update` gate.
- Product thumbnails are dropped from the listings (association depth vs.
  value); product number/name remain.
- `BigIntField` values hydrate as strings — admin code must compare/display
  accordingly.

## Alternatives Considered
- **Writes through DAL too** — rejected: the DAL write pipeline cannot
  handle full-table replaces of large catalogs; would force a merge/upsert
  model and break the TRUNCATE + batch-insert contract.
- **Keep composite PKs in the definitions (no schema change)** — rejected:
  `sw-entity-listing` and DAL admin tooling assume a single `id` property;
  rows would have `id == null`, breaking identification/selection/actions.
- **Definitions only, keep the custom admin pages** — rejected: dead code;
  the standard listing was the whole motivation.
- **Attribute-based `#[Entity]` definitions** — rejected for now: classic
  `defineFields()` keeps composite/versioned/JSON field semantics explicit
  and unambiguous; the attribute API can be adopted later.

## Related Decisions
(none yet)
```

---

## Phase 1 — Schema + raw write paths

### 1.1 Migration

[NEW FILE] `src/Migration/Migration2026081800AddSurrogateIds.php`

Idempotent (column-existence guard, consistent with the plugin convention).
Backfill via `UNHEX(REPLACE(UUID(), '-', ''))` — one statement, works on
MySQL/MariaDB. Existing rows are preserved everywhere (no truncation; the
conflict resolutions table holds user resolutions).

```php
<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds a surrogate binary(16) `id` column to the three mapping tables.
 *
 * The read-only DAL facade and the admin sw-entity-listing pages need a
 * single `id` property per row. The natural composite keys stay in place —
 * they carry the FK cascade (product deletions) and the full-table-replace
 * contract. Existing rows are backfilled with random UUIDs (single UPDATE,
 * no PHP loop); nothing is truncated.
 *
 * 08/2026 created
 */
class Migration2026081800AddSurrogateIds extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 2026081800;
    }

    public function update(Connection $connection): void
    {
        $tables = [
            ['table' => 'tdmp_product',                      'after' => 'topdata_product_id', 'uniq' => 'uniq_tdmp_product_id'],
            ['table' => 'tdmp_brand',                        'after' => 'topdata_brand_id',   'uniq' => 'uniq_tdmp_brand_id'],
            ['table' => 'tdmp_product_conflict_resolutions', 'after' => 'status',             'uniq' => 'uniq_tdmp_conflict_id'],
        ];

        foreach ($tables as $spec) {
            if ($this->_columnExists($connection, $spec['table'], 'id')) {
                continue;
            }

            $connection->executeStatement(
                "ALTER TABLE `{$spec['table']}` ADD COLUMN `id` binary(16) NULL AFTER `{$spec['after']}`"
            );
            $connection->executeStatement(
                "UPDATE `{$spec['table']}` SET `id` = UNHEX(REPLACE(UUID(), '-', '')) WHERE `id` IS NULL"
            );
            $connection->executeStatement(
                "ALTER TABLE `{$spec['table']}` MODIFY `id` binary(16) NOT NULL, ADD UNIQUE KEY `{$spec['uniq']}` (`id`)"
            );
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
}
```

### 1.2 Raw write services generate the surrogate id

[MODIFY] `src/Service/Db/TdmpProductService.php`

`insertMany()` gains `id` (first column of the INSERT list, `Uuid::randomHex()`
per row); docblock row contract gains `'id'`.

```php
use Shopware\Core\Framework\Uuid\Uuid;

    /**
     * @param array<int, array{product_id: string, topdata_product_id: int, created_at: string, updated_at: string}> $rows product_id is hex (no 0x prefix)
     */
    public function insertMany(array $rows): int
    {
        $inserted = 0;
        foreach (array_chunk($rows, self::INSERT_BATCH) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $values[] = sprintf(
                    "(0x%s, 0x%s, 0x%s, %d, '%s', '%s')",
                    Uuid::randomHex(),
                    $row['product_id'],
                    self::LIVE_VERSION_HEX,
                    $row['topdata_product_id'],
                    $row['created_at'],
                    $row['updated_at']
                );
            }
            $inserted += $this->connection->executeStatement(
                'INSERT INTO tdmp_product (id, product_id, product_version_id, topdata_product_id, created_at, updated_at) VALUES ' . implode(',', $values)
            );
        }

        return $inserted;
    }
```

[MODIFY] `src/Service/Db/TdmpBrandService.php` — same change:
`INSERT INTO tdmp_brand (id, brand_id, topdata_brand_id, created_at, updated_at)`.

[MODIFY] `src/Service/Db/TdmpConflictResolutionService.php` — two spots:

1. `syncFromBuild()` — the upsert value literal gains a leading `(0x%s,` for a
   fresh `Uuid::randomHex()` per row; the INSERT column list in `_flushUpserts()`
   gains `id` **first**. The `ON DUPLICATE KEY UPDATE` clause stays untouched —
   it still keys off the composite PK, and existing rows keep their id.

```php
$upserts[] = sprintf(
    "(0x%s, 0x%s, 0x%s, %d, %s, '%s', '%s', '%s')",
    Uuid::randomHex(),
    $productId,
    TdmpProductService::LIVE_VERSION_HEX,
    $chosen,
    $this->connection->quote($candidatesJson),
    $status,
    $now,
    $now
);
```

```php
    private function _flushUpserts(array $upserts): void
    {
        foreach (array_chunk($upserts, self::INSERT_BATCH) as $chunk) {
            $this->connection->executeStatement(
                'INSERT INTO tdmp_product_conflict_resolutions
                    (id, product_id, product_version_id, chosen_topdata_product_id, topdata_product_ids, status, created_at, updated_at)
                 VALUES ' . implode(', ', $chunk) . '
                 ON DUPLICATE KEY UPDATE
                    chosen_topdata_product_id = VALUES(chosen_topdata_product_id),
                    topdata_product_ids = VALUES(topdata_product_ids),
                    status = VALUES(status),
                    updated_at = VALUES(updated_at)'
            );
        }
    }
```

2. `applyUserResolution()` — the `tdmp_product` re-insert gains `id`:

```php
$this->connection->executeStatement(
    sprintf(
        "INSERT INTO tdmp_product (id, product_id, product_version_id, topdata_product_id, created_at, updated_at)
         VALUES (0x%s, 0x%s, 0x%s, %d, %s, %s)",
        Uuid::randomHex(),
        $productId,
        TdmpProductService::LIVE_VERSION_HEX,
        $chosenTopdataProductId,
        $this->connection->quote($now),
        $this->connection->quote($now)
    )
);
```

> DBAL 4.x note: `executeStatement` + raw literals are already the pattern
> here — keep it. No DAL writes are introduced anywhere.

---

## Phase 2 — Entity definitions + entities

Three definitions in `src/Entity/` (auto-discovered by the plugin kernel —
**no** `services.xml` wiring). Classic `defineFields()` (explicit composite/
versioned/JSON semantics; attribute-based API left for later, see ADR
alternatives). Entity name = table name. `id` is an `IdField` with
`PrimaryKey` flag (DAL/admin single-id contract) — the DB keeps the composite
PK; the definition's PK only matters for writes, which never happen.

Associations are declared **explicitly** (`ManyToOneAssociationField`) so the
admin columns do not depend on FkField auto-resolution behavior. For the
versioned `product` references the join becomes version-aware through the
`ReferenceVersionField`.

[NEW FILE] `src/Entity/TdmpProductDefinition.php`

```php
<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Entity;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BigIntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Read-only DAL facade over `tdmp_product`.
 *
 * All writes stay in the raw DBAL services (src/Service/Db) — see
 * _ai/technical_decisions/ADR__260818-1__read-only-dal-entity-facade.md.
 * The `product` association (version-aware via the ReferenceVersionField)
 * powers the admin listings.
 *
 * 08/2026 created
 */
class TdmpProductDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'tdmp_product';
    }

    public function getEntityClass(): string
    {
        return TdmpProductEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('product_id', 'productId', ProductDefinition::class))->addFlags(new Required()),
            (new ReferenceVersionField(ProductDefinition::class))->addFlags(new Required()),
            (new BigIntField('topdata_product_id', 'topdataProductId'))->addFlags(new Required()),
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class, 'id', false),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
```

[NEW FILE] `src/Entity/TdmpProductEntity.php`

```php
<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Entity;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Read-only row of `tdmp_product` — getter-only by contract; never write via
 * the DAL repository (see ADR__260818-1__read-only-dal-entity-facade.md).
 *
 * Note: BigIntField hydrates as string.
 *
 * 08/2026 created
 */
class TdmpProductEntity extends Entity
{
    use EntityIdTrait;

    public function getProductId(): ?string
    {
        return $this->get('productId');
    }

    public function getProductVersionId(): ?string
    {
        return $this->get('productVersionId');
    }

    public function getTopdataProductId(): ?string
    {
        return $this->get('topdataProductId');
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->get('product');
    }
}
```

[NEW FILE] `src/Entity/TdmpBrandDefinition.php`

```php
<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Entity;

use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BigIntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Read-only DAL facade over `tdmp_brand`.
 *
 * All writes stay in the raw DBAL services — see
 * _ai/technical_decisions/ADR__260818-1__read-only-dal-entity-facade.md.
 *
 * 08/2026 created
 */
class TdmpBrandDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'tdmp_brand';
    }

    public function getEntityClass(): string
    {
        return TdmpBrandEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('brand_id', 'brandId', ProductManufacturerDefinition::class))->addFlags(new Required()),
            (new BigIntField('topdata_brand_id', 'topdataBrandId'))->addFlags(new Required()),
            new ManyToOneAssociationField('manufacturer', 'brand_id', ProductManufacturerDefinition::class, 'id', false),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
```

[NEW FILE] `src/Entity/TdmpBrandEntity.php` — mirror of `TdmpProductEntity`
with `getBrandId(): ?string`, `getTopdataBrandId(): ?string`,
`getManufacturer(): ?ProductManufacturerEntity` (import from
`Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity`).

[NEW FILE] `src/Entity/TdmpProductConflictResolutionDefinition.php`

```php
<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Entity;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BigIntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Read-only DAL facade over `tdmp_product_conflict_resolutions`.
 *
 * `topdataProductIds` hydrates as the decoded candidate array
 * ({id, pcd[], ean[], mpn[]} per candidate) — the conflicts page renders the
 * radio list straight from it. All writes stay raw DBAL — see
 * _ai/technical_decisions/ADR__260818-1__read-only-dal-entity-facade.md.
 *
 * 08/2026 created
 */
class TdmpProductConflictResolutionDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'tdmp_product_conflict_resolutions';
    }

    public function getEntityClass(): string
    {
        return TdmpProductConflictResolutionEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('product_id', 'productId', ProductDefinition::class))->addFlags(new Required()),
            (new ReferenceVersionField(ProductDefinition::class))->addFlags(new Required()),
            (new BigIntField('chosen_topdata_product_id', 'chosenTopdataProductId'))->addFlags(new Required()),
            (new JsonField('topdata_product_ids', 'topdataProductIds'))->addFlags(new Required()),
            (new StringField('status', 'status', 16))->addFlags(new Required()),
            new ManyToOneAssociationField('product', 'product_id', ProductDefinition::class, 'id', false),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
```

[NEW FILE] `src/Entity/TdmpProductConflictResolutionEntity.php` — getters:
`getProductId()`, `getProductVersionId()`, `getChosenTopdataProductId(): ?string`,
`getTopdataProductIds(): ?array`, `getStatus(): ?string`, `getProduct()`.

---

## Phase 3 — Conflicts page: `sw-entity-listing`

Goal: keep the UX (stats banner, status tabs, search, radio resolve) but move
the grid to the standard listing over the repository. Pattern from
`sw67-admin-entity-listing` (proven in this team): `listing` mixin + manual
`getList()` + `:dataSource` + `@page-change`/`@column-sort`.

Gotchas handled:
- `BigIntField` hydrates as **string** → radio `:checked` compares
  `Number(item.chosenTopdataProductId) === candidate.id`; no `===` between
  int and string.
- Column slot names come from `column.property`; the product column uses a
  friendly `property: 'productNumber'` with `dataIndex: 'product.productNumber'`
  for sorting (sorting via association works because `getList()` always calls
  `addAssociation('product')`).
- Search = custom **criteria filter** (associations are NOT searched by the
  DAL term search), triggered by the existing toolbar field (debounced).
- Stats: three repository searches — two `total`-counts with a status filter,
  one `MaxAggregation` over `tdmp_product.updatedAt` for `lastImportAt`.
- Resolve stays a POST to `api.action.topdata-mapper.conflicts.resolve`
  (raw DBAL write — the ADR's rule); after success the entity row is mutated
  in place and stats are reloaded. **Never** `repository.save()`.

[MODIFY] `src/Resources/app/administration/src/module/topdata-mapper-conflicts/page/topdata-mapper-conflicts/index.js`

```js
import template from './topdata-mapper-conflicts.html.twig';
import './topdata-mapper-conflicts.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Conflict resolution (Catalog navigation).
 *
 * Standard sw-entity-listing over the read-only DAL facade — the grid is
 * repository-driven (search/sort/pagination), the radio pick still POSTs to
 * the resolve-conflict action route (raw DBAL write, never a repository
 * write — see ADR__260818-1__read-only-dal-entity-facade.md).
 *
 * 08/2026 created
 */
Component.register('topdata-mapper-conflicts', {
    template,

    inject: ['repositoryFactory', 'TopdataMapperApiService'],
    mixins: [Mixin.getByName('listing'), Mixin.getByName('notification')],

    data: () => ({
        items: null,
        total: 0,
        page: 1,
        limit: 25,
        sortBy: 'updatedAt',
        sortDirection: 'DESC',
        status: 'all',
        search: '',
        stats: { pending: 0, resolved: 0, lastImportAt: null },
        isLoading: true,
        resolvingKey: null,
    }),

    computed: {
        repository() {
            return this.repositoryFactory.create('tdmp_product_conflict_resolutions');
        },

        productRepository() {
            return this.repositoryFactory.create('tdmp_product');
        },

        columns() {
            return [
                { property: 'productNumber', dataIndex: 'product.productNumber', label: this.$tc('TopdataMapperSW6.conflicts.columns.product'), sortable: true, primary: true },
                { property: 'candidates', label: this.$tc('TopdataMapperSW6.conflicts.columns.candidates'), sortable: false },
                { property: 'status', label: this.$tc('TopdataMapperSW6.conflicts.columns.status'), sortable: true },
                { property: 'updatedAt', label: this.$tc('TopdataMapperSW6.conflicts.columns.updatedAt'), sortable: true },
            ];
        },
    },

    created() {
        this.debouncedSearch = Shopware.Utils.debounce(this.getList, 400);
        this.getList();
        this.loadStats();
    },

    methods: {
        getList() {
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.addAssociation('product');
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));
            if (this.status !== 'all') {
                criteria.addFilter(Criteria.equals('status', this.status));
            }
            if (this.search !== '') {
                const filters = [
                    Criteria.contains('product.productNumber', this.search),
                    Criteria.contains('product.name', this.search),
                ];
                const numeric = parseInt(this.search, 10);
                if (!Number.isNaN(numeric)) {
                    filters.push(Criteria.equals('chosenTopdataProductId', numeric));
                }
                criteria.addFilter(Criteria.multi('OR', filters));
            }

            return this.repository.search(criteria)
                .then((result) => {
                    this.items = result;
                    this.total = result.total;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        loadStats() {
            const pending = new Criteria(1, 1);
            pending.addFilter(Criteria.equals('status', 'auto'));
            this.repository.search(pending).then((result) => { this.stats.pending = result.total; });

            const resolved = new Criteria(1, 1);
            resolved.addFilter(Criteria.equals('status', 'user'));
            this.repository.search(resolved).then((result) => { this.stats.resolved = result.total; });

            const lastImport = new Criteria(1, 1);
            lastImport.addAggregation(Criteria.max('lastImport', 'updatedAt'));
            this.productRepository.search(lastImport).then((result) => {
                const agg = result.aggregations?.lastImport;
                this.stats.lastImportAt = agg?.max ?? null;
            });
        },

        onPageChange({ page, limit }) {
            this.page = page;
            this.limit = limit;
            this.getList();
        },

        onSortColumn(column) {
            this.sortBy = column.dataIndex ?? column.property;
            this.sortDirection = column.sortDirection ?? 'ASC';
            this.getList();
        },

        onStatusChange(statusOrItem) {
            this.status = typeof statusOrItem === 'string' ? statusOrItem : statusOrItem.name;
            this.page = 1;
            this.getList();
        },

        onSearchChange() {
            this.page = 1;
            this.debouncedSearch();
        },

        /**
         * Immediate POST on radio pick; the row is updated in place (entity
         * mutation only — no repository write).
         */
        resolve(item, candidate) {
            if (candidate.id === Number(item.chosenTopdataProductId)) {
                return;
            }
            this.resolvingKey = item.productId + ':' + candidate.id;
            this.TopdataMapperApiService.resolveConflict(item.productId, candidate.id)
                .then(() => {
                    item.chosenTopdataProductId = String(candidate.id);
                    item.status = 'user';
                    this.createNotificationSuccess({
                        title: this.$tc('TopdataMapperSW6.conflicts.resolve.successTitle'),
                        message: this.$tc('TopdataMapperSW6.conflicts.resolve.successMessage', { number: candidate.id }),
                    });
                    this.loadStats();
                })
                .catch(() => {
                    this.createNotificationError({
                        title: this.$tc('TopdataMapperSW6.conflicts.resolve.failedTitle'),
                        message: this.$tc('TopdataMapperSW6.conflicts.resolve.failedMessage'),
                    });
                })
                .finally(() => {
                    this.resolvingKey = null;
                });
        },

        statusLabel(status) {
            return status === 'user'
                ? this.$tc('TopdataMapperSW6.conflicts.status.resolved')
                : this.$tc('TopdataMapperSW6.conflicts.status.pending');
        },

        statusVariant(status) {
            return status === 'user' ? 'success' : 'warning';
        },

        candidateHint(candidate) {
            const previews = [];
            if (candidate.pcd && candidate.pcd.length > 0) {
                previews.push(candidate.pcd.join(', '));
            }
            if (candidate.ean && candidate.ean.length > 0) {
                previews.push(candidate.ean.join(', '));
            }
            if (candidate.mpn && candidate.mpn.length > 0) {
                previews.push(candidate.mpn.join(', '));
            }
            return previews.join(' · ');
        },
    },
});
```

[MODIFY] `src/Resources/app/administration/src/module/topdata-mapper-conflicts/page/topdata-mapper-conflicts/topdata-mapper-conflicts.html.twig`

Keep the summary card and the toolbar (tabs + search field) as-is; replace the
`sw-data-grid` block with:

```twig
<sw-entity-listing
    v-if="items"
    :dataSource="items"
    :columns="columns"
    :repository="repository"
    identifier="topdata-mapper-conflicts"
    :show-settings="false"
    :show-selection="false"
    :allow-view="false"
    :allow-edit="false"
    :allow-delete="false"
    :allow-inline-edit="false"
    :sort-by="sortBy"
    :sort-direction="sortDirection"
    :is-loading="isLoading"
    :full-page="true"
    @page-change="onPageChange"
    @column-sort="onSortColumn"
>
    {% block topdata_mapper_conflicts_grid_product %}
        <template #column-productNumber="{ item }">
            <div class="topdata-mapper-conflicts__product">
                <div class="topdata-mapper-conflicts__product-info">
                    <strong>{{ item.product ? item.product.productNumber : item.productId }}</strong>
                    <span v-if="item.product">{{ item.product.name }}</span>
                </div>
            </div>
        </template>
    {% endblock %}

    {% block topdata_mapper_conflicts_grid_candidates %}
        <template #column-candidates="{ item }">
            <div class="topdata-mapper-conflicts__candidates">
                <label
                    v-for="candidate in item.topdataProductIds"
                    :key="candidate.id"
                    class="topdata-mapper-conflicts__candidate"
                >
                    <input
                        type="radio"
                        :name="'conflict-' + item.productId"
                        :value="candidate.id"
                        :checked="candidate.id === Number(item.chosenTopdataProductId)"
                        :disabled="resolvingKey !== null"
                        @change="resolve(item, candidate)"
                    >
                    <span>
                        Topdata #{{ candidate.id }}
                        <em v-if="candidateHint(candidate)">({{ candidateHint(candidate) }})</em>
                    </span>
                </label>
            </div>
        </template>
    {% endblock %}

    {% block topdata_mapper_conflicts_grid_status %}
        <template #column-status="{ item }">
            <sw-label :variant="statusVariant(item.status)" appearance="pill">
                {{ statusLabel(item.status) }}
            </sw-label>
        </template>
    {% endblock %}

    {% block topdata_mapper_conflicts_grid_updated_at %}
        <template #column-updatedAt="{ item }">
            <sw-time-ago :date="item.updatedAt" />
        </template>
    {% endblock %}
</sw-entity-listing>

<sw-empty-state
    v-if="!isLoading && items && items.length === 0"
    :title="$tc('TopdataMapperSW6.conflicts.empty.title')"
    :subline="$tc('TopdataMapperSW6.conflicts.empty.message')"
    icon="regular-plug"
/>
```

> If `sw-entity-listing` renders its own built-in search field next to the
> toolbar search, hide it (verify in the dev shop; candidate prop
> `show-search="false"`). The toolbar search builds the association filter.

[MODIFY] `src/Resources/app/administration/src/service/topdata-mapper-api-service.js` — remove `fetchConflicts` (keep `fetchStrategy`, `validateStrategy`, `resolveConflict`).

---

## Phase 4 — Mappings browser: `sw-entity-listing` + dead code removal

### 4.1 Mappings page

[MODIFY] `src/Resources/app/administration/src/module/topdata-mapper-mappings/page/topdata-mapper-mappings/index.js`

Same pattern as Phase 3, with two repositories switched by `activeTab`:

- products: `repositoryFactory.create('tdmp_product')`, columns
  `productNumber` (`dataIndex: 'product.productNumber'`), `productName`
  (`dataIndex: 'product.name'`), `topdataProductId` (pill slot), `createdAt`,
  `updatedAt` (`sw-time-ago` slots). `getList()` always
  `addAssociation('product')`. Search filter OR: `product.productNumber`
  contains, `product.name` contains, `topdataProductId` equals (numeric).
- brands: `repositoryFactory.create('tdmp_brand')`, columns
  `manufacturerName` (`dataIndex: 'manufacturer.name'`), `topdataBrandId`
  (pill slot), `createdAt`, `updatedAt`. Search filter OR:
  `manufacturer.name` contains, `topdataBrandId` equals (numeric).

The thumbnails are gone (association depth — documented trade-off). Keep the
tabs + toolbar search + `onTabChange` (switches repository + resets page/search).

[MODIFY] `topdata-mapper-mappings.html.twig` — swap the `sw-data-grid` for an
`sw-entity-listing` (same props/events as Phase 3), with slots:
`#column-topdataProductId` / `#column-topdataBrandId` (info pill),
`#column-createdAt` / `#column-updatedAt` (`sw-time-ago`), plus
`#column-productNumber`/`#column-productName` fallback rendering
(`item.product?.productNumber`, `item.product?.name` — same `v-if="item.product"`
guard pattern).

[MODIFY] `src/Resources/app/administration/src/service/topdata-mapper-api-service.js` — remove `fetchMappings` and `fetchBrands`.

### 4.2 Dead code removal

[DELETE] `src/Service/Db/TdmpMappingBrowseService.php`

[MODIFY] `src/Controller/Api/TopdataMapperActionController.php` — remove
`listMappingsAction`, `listBrandMappingsAction`, `listConflictsAction`, the
`TdmpMappingBrowseService` constructor dependency (and its import).

[MODIFY] `src/Service/Db/TdmpConflictResolutionService.php` — remove
`listConflicts()` and `getStats()` (listings are now DAL-repository-driven;
stats come from aggregations). Keep `loadResolutions()`, `syncFromBuild()`,
`applyUserResolution()`.

[MODIFY] `src/Resources/config/services.xml` — remove the
`TdmpMappingBrowseService` service entry (definitions are auto-discovered, no
entries needed).

---

## Phase 5 — Housekeeping, docs, report

### 5.1 Docs

[MODIFY] `AGENTS.md`:
- Architecture notes: replace the "**No DAL entities**" bullet with:
  "**Read-only DAL facade** — `src/Entity/` defines `tdmp_product`,
  `tdmp_brand`, `tdmp_product_conflict_resolutions` (surrogate `id` columns,
  `product`/`manufacturer` associations) for admin listings only. All writes
  stay raw DBAL in `src/Service/Db/`; never call repository writes — see
  `_ai/technical_decisions/ADR__260818-1__read-only-dal-entity-facade.md`."
- Schema contract bullet: mention the surrogate `id` column (natural keys +
  FK cascade unchanged).
- Admin section: conflicts/mappings pages are `sw-entity-listing` over the
  repositories; the list action routes are gone (strategy + resolve remain).

[MODIFY] `README.md`:
- Admin section: mappings/conflicts listings are standard `sw-entity-listing`
  pages (search/sort/pagination; thumbnails no longer shown); note the DAL
  facade is read-only.
- Tables section: mention the surrogate `id` column.

[MODIFY] `CHANGELOG.md` (Unreleased → Added):
- "Read-only DAL entity definitions for `tdmp_product`, `tdmp_brand`,
  `tdmp_product_conflict_resolutions` (surrogate `id` columns, migration
  `Migration2026081800AddSurrogateIds`) — admin listings use standard
  `sw-entity-listing` over the repositories (conflicts + mappings pages,
  search/sort/pagination); all writes remain raw DBAL. Decision recorded in
  `_ai/technical_decisions/ADR__260818-1__read-only-dal-entity-facade.md`."
- Changed: "Mapping/conflicts list action routes removed
  (`GET /api/_action/topdata-mapper/{conflicts,mappings,brands}`); grids no
  longer show product thumbnails."

`.gitignore` — no new artifact types introduced; nothing to change (state this
explicitly in the report).

### 5.2 Implementation report

[NEW FILE] `_ai/backlog/reports/260818_HHmm__IMPLEMENTATION_REPORT__read-only-dal-entity-facade.md`
with the frontmatter per the report template (status, files created/modified/
deleted counts, planFile reference) and sections: Summary, Files Changed, Key
Changes, Deviations from Plan, Technical Decisions, Testing Notes, Usage
Examples, Documentation Updates, Next Steps.

---

## 6. Verification

Run from the Shopware root (`/topdata/clones/sw67/vol/www`), in the
`sw67-www` container where needed:

1. **Migration**:
   `docker exec sw67-www php /www/bin/console database:migrate --identifier=Topdata\\TopdataMapperSW6`
   (fall back to plain `database:migrate` if the identifier option is not
   available in this shop).
2. **Schema spot check**:
   `SELECT COUNT(*) AS rows, COUNT(id) AS with_id, COUNT(DISTINCT id) AS unique_ids FROM tdmp_product;`
   (same for `tdmp_brand`, `tdmp_product_conflict_resolutions`) — counts
   equal, ids unique, no nulls.
3. **Import still works** (write path incl. new ids):
   `bin/console topdata:mapper:import` → succeeds, summary counts sane.
4. **Resolve-conflict path still works** (if a conflict exists):
   `bin/console` nothing — verify via admin; or call the DB service directly.
5. **Admin build**: `docker exec sw67-www bash -c 'cd /www && VITE_MODE=production npx ts-node -T build/plugins.vite.ts'`,
   then `docker exec sw67-www rm -rf /www/var/cache/*`.
6. **Manual admin checks**: conflicts page (tabs, search by product
   number/name/Topdata id, sort, pagination, radio resolve → success
   notification, stats update), mappings browser (both tabs, search, sort,
   pills, `sw-time-ago` dates), settings page unaffected.
7. **Read-only guard**:
   `rg -n "repositoryFactory|repository\.(save|create|delete|upsert)" src/Resources/app/administration/src`
   → only `repositoryFactory.create(...)` for searches; no write calls.
   `rg -n "EntityRepository|repository" src/` → definitions/services only read.
8. **ACL**: if a non-admin user with only `topdata_mapper:read` gets API 403s
   on `/api/tdmp-product*` (unexpected for custom entities), extend
   `Resources/config/acl.xml` accordingly and document in the report.

## 7. Out of scope / follow-ups

- Global admin search bar (`sw-search-bar`) integration for these entities
  (needs `SearchRanking` flags + term search semantics).
- Restoring thumbnails in the listings via deeper product associations
  (`product.coverMedia` → first thumbnail) — deliberately not done.
- Attribute-based (`#[Entity]`) definitions — revisit when the classic
  facade is proven.