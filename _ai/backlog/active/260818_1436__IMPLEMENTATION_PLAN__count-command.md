---
filename: "_ai/backlog/active/260818_1436__IMPLEMENTATION_PLAN__count-command.md"
title: "topdata:mapper:count — debug command counting shop products with EAN / MPN / article number / custom fields"
createdAt: 2026-08-18 14:36
updatedAt: 2026-08-18 14:36
status: draft
priority: medium
tags: [command, cli, debugging, count, dbal]
estimatedComplexity: simple
documentRevision: 1
documentType: IMPLEMENTATION_PLAN
---

# `topdata:mapper:count` — shop-side identifier/custom-field count command

## 1. Problem

When a mapping import produces an unexpectedly low match rate (e.g. 50 matched
out of 99,822 API rows), the first debugging question is shop-side: **does this
Shopware shop even have products with EANs / MPNs / article numbers, and how
many?** Today there is no way to answer that from the CLI — the only commands
are `topdata:mapper:import` (writes the mapping tables) and the brand build.
Answering it requires hand-written SQL against the `product` table.

We need a **debug command** that counts live Shopware products having values in
the identifier dimensions the mapping strategy works with:

- EAN → `product.ean`
- MPN → `product.manufacturer_number`
- Article number → `product.product_number`
- (derived) any of the above

plus an opt-in **per-custom-field count** (`--also-customfields`): for every
distinct custom-field name in `product.custom_fields`, how many products carry
a non-empty value. All results shown in a pretty table, in the same visual
style as the import summary.

Counts are **shop-side (DB only)** — no API call, no credentials needed. This
is a deliberate contrast to the API-side debug output the import already logs.

## 2. Executive summary

- **Phase 1 — `TdmpProductCountService`** (`src/Service/Db/`): raw DBAL
  counts, mirroring the matcher's semantics:
  - live-version products only (`TdmpProductService::LIVE_VERSION_HEX`),
  - variants included by default, `$parentsOnly` restricts to
    `parent_id IS NULL` (matcher sees all live rows, so the default matches
    what the import can match),
  - identifier counts in **one aggregate query** (total + `SUM(CASE …)` per
    dimension + an OR-combined "any identifier" sum),
  - custom fields via PHP `json_decode` over `custom_fields` rows (same
    approach as `ProductMappingMatcher_Dsl::_loadCustomFieldMap`); counts only
    non-empty scalar values (scalars and lists of scalars), returns
    `array<string, int>` sorted by count desc.
- **Phase 2 — `Command_TdmpCount`** (`src/Command/`): `topdata:mapper:count`
  with `--also-customfields` (`-c`) and `--parents-only` (`-p`). Renders two
  box-style tables (same styling helpers as `Command_TdmpImport`):
  - *Identifier counts*: rows `EAN | MPN | Article number | Any identifier`,
    separator, `Total products` row; columns `Identifier | Products | % of total`,
  - *Custom field counts*: one row per field; columns `Field | Products | % of total`,
  - count cells green-bold when > 0, yellow-bold when 0 (a zero dimension is
    exactly the "mapping is dead" symptom this command is built to surface).
- **Phase 3 — wiring**: register service + command in `services.xml`
  (autowire + `console.command` tag, same as `Command_TdmpImport`).
- **Phase 4 — verify** in the dev shop (`bin/console topdata:mapper:count`).
- **Phase 5 — housekeeping**: AGENTS.md commands table, README.md usage
  section, CHANGELOG.md entry. `.gitignore` needs no change (no new artifact
  types).
- **Phase 6 — implementation report** in `_ai/backlog/reports/`.

No schema, migration, API, admin or dependency changes. No tests exist in this
repo (empty `tests/`, no phpunit config) — verification is manual via the
command in the dev shop.

## 3. Project environment

- Project Name: SW6.7 Plugin (`Topdata\TopdataMapperSW6`)
- Backend root: `src`
- PHP Version: 8.2+ (constructor promotion, `readonly`, typed params/returns)
- Shopware: 6.7.*, Doctrine DBAL 4.x
- Conventions: `AGENTS.md` (Topdata Mapper SW6) + foundation
  `CONVENTIONS-PHP.md` — private methods prefixed `_`, class+method docblocks,
  `CliLogger` for all CLI output, `AbstractTopdataCommand` base, commands named
  `Command_Tdmp*` with `#[AsCommand]`.

## 4. Phase 1 — `TdmpProductCountService`

[NEW FILE] `src/Service/Db/TdmpProductCountService.php`

Raw DBAL service in the existing `src/Service/Db/` family (no DAL entity).
DBAL 4.x notes baked in: `fetchAssociative()`/`fetchAllAssociative()` return
values as **strings** (explicit `(int)` casts), no `PDO::PARAM_*` constants.

```php
<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service\Db;

use Doctrine\DBAL\Connection;

/**
 * Counts Shopware products by identifier dimension (ean / mpn / article
 * number / any) and per custom-field name — the DB-side debugging companion
 * of the mapping import (e.g. explaining an import run with zero matches:
 * are the identifiers even present in the shop?).
 *
 * Mirrors the matcher's semantics: only live-version products count
 * (TdmpProductService::LIVE_VERSION_HEX), variants are included unless
 * `$parentsOnly` is set, and custom-field values that are empty strings are
 * ignored.
 *
 * 08/2026 created
 */
class TdmpProductCountService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Counts products per identifier dimension in one aggregate query.
     *
     * @return array{total: int, ean: int, mpn: int, articleNumber: int, any: int}
     */
    public function countIdentifiers(bool $parentsOnly = false): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN ean IS NOT NULL AND ean <> '' THEN 1 ELSE 0 END) AS with_ean,
                SUM(CASE WHEN manufacturer_number IS NOT NULL AND manufacturer_number <> '' THEN 1 ELSE 0 END) AS with_mpn,
                SUM(CASE WHEN product_number IS NOT NULL AND product_number <> '' THEN 1 ELSE 0 END) AS with_article_number,
                SUM(CASE
                    WHEN (ean IS NOT NULL AND ean <> '')
                      OR (manufacturer_number IS NOT NULL AND manufacturer_number <> '')
                      OR (product_number IS NOT NULL AND product_number <> '')
                    THEN 1 ELSE 0 END) AS with_any
               FROM product
              WHERE version_id = 0x" . TdmpProductService::LIVE_VERSION_HEX
                . ($parentsOnly ? ' AND parent_id IS NULL' : '')
        ) ?: [];

        return [
            'total'         => (int)($row['total'] ?? 0),
            'ean'           => (int)($row['with_ean'] ?? 0),
            'mpn'           => (int)($row['with_mpn'] ?? 0),
            'articleNumber' => (int)($row['with_article_number'] ?? 0),
            'any'           => (int)($row['with_any'] ?? 0),
        ];
    }

    /**
     * Counts products per custom-field name (products having at least one
     * non-empty scalar value), sorted by count descending.
     *
     * @return array<string, int> custom field name → product count
     */
    public function countCustomFields(bool $parentsOnly = false): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT custom_fields
               FROM product
              WHERE version_id = 0x' . TdmpProductService::LIVE_VERSION_HEX
                . ' AND custom_fields IS NOT NULL'
                . ($parentsOnly ? ' AND parent_id IS NULL' : '')
        );

        $counts = [];
        foreach ($rows as $row) {
            $customFields = json_decode((string)$row['custom_fields'], true);
            if (!is_array($customFields)) {
                continue;
            }
            foreach ($customFields as $name => $value) {
                if (!$this->_hasNonEmptyValue($value)) {
                    continue;
                }
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Whether a custom-field value contains at least one non-empty scalar
     * (scalars and lists of scalars, mirroring the matcher).
     */
    private function _hasNonEmptyValue(mixed $value): bool
    {
        if (!is_array($value)) {
            $value = [$value];
        }
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                return true;
            }
            if (is_numeric($item)) {
                return true;
            }
        }

        return false;
    }
}
```

Design notes:

- Single `SUM(CASE …)` query → one roundtrip for all identifier counts; the
  "any" dimension is the OR of the three non-empty conditions, not a separate
  query.
- `fetchAssociative()` may return `false` → `?: []` guard before indexing.
- Custom fields are decoded in PHP (like the matcher), not via SQL JSON
  functions — consistent with the existing codebase and portable.
- Empty-string / null values are not counted; numeric values (including
  `"0"`-style strings) are. This mirrors `_loadCustomFieldMap`'s value filter.

## 5. Phase 2 — `Command_TdmpCount`

[NEW FILE] `src/Command/Command_TdmpCount.php`

`topdata:mapper:count`. Extends `AbstractTopdataCommand` (its `initialize()`
already sets `$this->cliStyle` + `CliLogger`). No credentials, no API client —
pure DB. Table rendering helpers (`_cell`, `_padCell`) mirror the private
helpers of `Command_TdmpImport` (box style, `STR_PAD_BOTH`, cyan header
title/headers, centered cells).

```php
<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataMapperSW6\Service\Db\TdmpProductCountService;

/**
 * Counts Shopware products by identifier dimension (ean / mpn / article
 * number) and optionally per custom-field name — the debugging companion of
 * `topdata:mapper:import` (e.g. to explain a run with zero matches: are the
 * identifiers even present in the shop?).
 *
 * DB-side only, no API call. Scope: live-version products; variants included
 * unless --parents-only is given.
 *
 * 08/2026 created
 */
#[AsCommand(
    name: 'topdata:mapper:count',
    description: 'Count shop products with EAN / MPN / article number (and optionally per custom field)'
)]
class Command_TdmpCount extends AbstractTopdataCommand
{
    public function __construct(
        private readonly TdmpProductCountService $countService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'also-customfields',
            'c',
            InputOption::VALUE_NONE,
            'Also count products per custom-field name'
        );
        $this->addOption(
            'parents-only',
            'p',
            InputOption::VALUE_NONE,
            'Count only main products (parent_id IS NULL), excluding variants'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $parentsOnly = (bool)$input->getOption('parents-only');
        $counts      = $this->countService->countIdentifiers($parentsOnly);

        CliLogger::info(sprintf(
            'Counting %s (live version, %s).',
            $parentsOnly ? 'main products only' : 'all products incl. variants',
            number_format($counts['total'])
        ));

        $this->_printIdentifierTable($counts);

        if ($input->getOption('also-customfields')) {
            $this->_printCustomFieldTable($this->countService->countCustomFields($parentsOnly), $counts['total']);
        }

        return self::SUCCESS;
    }

    /**
     * @param array{total: int, ean: int, mpn: int, articleNumber: int, any: int} $counts
     */
    private function _printIdentifierTable(array $counts): void
    {
        $rows = [
            ['EAN', $counts['ean'], 'product.ean'],
            ['MPN', $counts['mpn'], 'product.manufacturer_number'],
            ['Article number', $counts['articleNumber'], 'product.product_number'],
            ['Any identifier', $counts['any'], ''],
        ];

        $tableRows = [];
        foreach ($rows as [$label, $count, $source]) {
            $tableRows[] = [
                $this->_cell($label),
                $this->_cell(number_format($count), $this->_countStyle($count)),
                $this->_cell($this->_percent($count, $counts['total'])),
                $this->_cell($source),
            ];
        }
        $tableRows[] = new TableSeparator();
        $tableRows[] = [
            $this->_cell('Total products', ['fg' => 'white', 'options' => 'bold']),
            $this->_cell(number_format($counts['total']), ['options' => 'bold']),
            $this->_cell('100.0%', ['options' => 'bold']),
            $this->_cell(''),
        ];

        $this->_renderTable('Identifier counts', ['Identifier', 'Products', '% of total', 'Source column'], $tableRows);
    }

    /**
     * @param array<string, int> $fieldCounts custom field name → product count
     */
    private function _printCustomFieldTable(array $fieldCounts, int $total): void
    {
        $rows = [];
        foreach ($fieldCounts as $name => $count) {
            $rows[] = [
                $this->_cell($name),
                $this->_cell(number_format($count), $this->_countStyle($count)),
                $this->_cell($this->_percent($count, $total)),
            ];
        }
        if ($rows === []) {
            $rows[] = [
                $this->_cell('— no custom fields set —', ['fg' => 'yellow']),
                $this->_cell(''),
                $this->_cell(''),
            ];
        }

        $this->_renderTable('Custom field counts', ['Field', 'Products', '% of total'], $rows);
    }

    /**
     * @param array<int, array{0: TableCell, 1?: TableCell, 2?: TableCell, 3?: TableCell}|TableSeparator> $rows
     * @param string[] $headers
     */
    private function _renderTable(string $title, array $headers, array $rows): void
    {
        $headerCells = [];
        foreach ($headers as $header) {
            $headerCells[] = $this->_cell($header, ['fg' => 'cyan', 'options' => 'bold']);
        }

        $tbl = $this->cliStyle->createTable();
        $tbl->setStyle('box');
        $tbl->getStyle()
            ->setPadType(STR_PAD_BOTH)
            ->setHeaderTitleFormat('<fg=black;bg=cyan;options=bold> %s </>');
        $tbl->setHeaders([$headerCells]);
        $tbl->setRows($rows);
        $tbl->setHeaderTitle($title);
        $tbl->render();

        $this->cliStyle->newLine();
    }

    /**
     * @return array{fg?: string, options?: string}
     */
    private function _countStyle(int $count): array
    {
        return $count > 0 ? ['fg' => 'green', 'options' => 'bold'] : ['fg' => 'yellow', 'options' => 'bold'];
    }

    private function _percent(int $count, int $total): string
    {
        return $total > 0 ? sprintf('%.1f%%', $count / $total * 100) : '—';
    }

    /**
     * Creates a centered, padded table cell with optional styling.
     *
     * @param array{fg?: string, bg?: string, options?: string} $styleOptions
     */
    private function _cell(string $value, ?array $styleOptions = null): TableCell
    {
        $style = new TableCellStyle(array_merge(['align' => 'center'], $styleOptions ?? []));

        return new TableCell($this->_padCell($value), ['style' => $style]);
    }

    /**
     * Adds extra horizontal padding around a table cell.
     */
    private function _padCell(string $cell): string
    {
        return ' ' . $cell . ' ';
    }
}
```

Design notes:

- **Semantics**: count cells green when > 0, yellow when 0 — the inverse of the
  import summary (where "unmatched > 0" is yellow) but the same color language:
  yellow = suspicious. A 0-dimension is exactly the "mapping is dead" symptom.
- **Total products row** after a `TableSeparator`, bold — mirrors the import
  summary's Total row.
- **Empty custom-fields table** shows a placeholder row instead of an empty box.
- The optional `-c` / `-p` shortcuts follow the import command's `-m` pattern.

## 6. Phase 3 — register service + command

[MODIFY] `src/Resources/config/services.xml`

Add two entries next to the existing `TdmpProductService` and
`Command_TdmpImport` entries:

```xml
<service id="Topdata\TopdataMapperSW6\Service\Db\TdmpProductCountService" autowire="true"/>
...
<service id="Topdata\TopdataMapperSW6\Command\Command_TdmpCount" autowire="true">
    <tag name="console.command"/>
</service>
```

## 7. Phase 4 — verify

Run from the Shopware root (`/topdata/clones/sw67/vol/www`):

```bash
bin/console topdata:mapper:count
bin/console topdata:mapper:count --also-customfields
bin/console topdata:mapper:count --parents-only --also-customfields
```

Expected:

- `Identifier counts` table with the four dimension rows + Total products row;
  percentages sum sensibly and `Any identifier` ≥ max(ean, mpn, articleNumber),
  ≤ total.
- With `--also-customfields`: `Custom field counts` table, rows sorted by
  count desc, counts ≤ total.
- Sanity-check a dimension against raw SQL, e.g.
  `SELECT COUNT(*) FROM product WHERE version_id = 0x0fa91ce3e96a4bc2be4bd9ce752c3425 AND ean <> ''`
  equals the EAN row count.
- No API requests logged (DB-only), command works without configured
  credentials.
- `bin/console list | grep topdata` shows the new command.

## 8. Phase 5 — housekeeping

[MODIFY] `AGENTS.md` — add to the Commands table:

```markdown
| `bin/console topdata:mapper:count` | Count shop products with EAN/MPN/article number (pretty table, DB-only debug helper; `--also-customfields` per custom field, `--parents-only` excludes variants) |
```

[MODIFY] `README.md` — extend the Usage section:

```markdown
### Debug: count identifiers

```bash
bin/console topdata:mapper:count                    # products with EAN / MPN / article number (incl. variants)
bin/console topdata:mapper:count --also-customfields # + per custom-field counts
bin/console topdata:mapper:count --parents-only      # main products only
```

DB-side only (no API call) — the first stop when an import matches nothing.
```

[MODIFY] `CHANGELOG.md` — under `[Unreleased]` → `### Added`:

```markdown
- **`topdata:mapper:count` debug command** — counts live Shopware products
  with EAN / MPN / article-number values (plus "any identifier" and totals)
  and, with `--also-customfields`, one row per custom-field name;
  `--parents-only` excludes variants. DB-side only, no API call.
```

`.gitignore`: no change — no new file types, directories or build artifacts.

## 9. Phase 6 — implementation report

[NEW FILE] `_ai/backlog/reports/{YYMMDD_HHmm}__IMPLEMENTATION_REPORT__count-command.md`
(timestamp = implementation date/time):

```yaml
---
filename: "_ai/backlog/reports/{YYMMDD_HHmm}__IMPLEMENTATION_REPORT__count-command.md"
title: "Report: topdata:mapper:count debug command"
createdAt: YYYY-MM-DD HH:mm
updatedAt: YYYY-MM-DD HH:mm
planFile: "_ai/backlog/active/260818_1436__IMPLEMENTATION_PLAN__count-command.md"
project: "topdata-mapper-sw6"
status: completed
filesCreated: 2
filesModified: 3
filesDeleted: 0
tags: [command, cli, debugging, count, dbal]
documentType: IMPLEMENTATION_REPORT
---
```

Report sections per the standard structure: Summary · Files Changed · Key
Changes · Deviations from Plan · Technical Decisions · Testing Notes
(commands + sample output) · Usage Examples · Documentation Updates.
