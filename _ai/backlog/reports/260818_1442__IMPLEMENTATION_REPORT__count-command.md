---
filename: "_ai/backlog/reports/260818_1442__IMPLEMENTATION_REPORT__count-command.md"
title: "Report: topdata:mapper:count debug command"
createdAt: 2026-08-18 14:42
updatedAt: 2026-08-18 15:32
planFile: "_ai/backlog/active/260818_1436__IMPLEMENTATION_PLAN__count-command.md"
project: "topdata-mapper-sw6"
status: completed
filesCreated: 2
filesModified: 3
filesDeleted: 0
tags: [command, cli, debugging, count, dbal]
documentType: IMPLEMENTATION_REPORT
---

# 1. Summary

New `topdata:mapper:count` debug command that answers the shop-side question
behind a low import match rate: **does this shop even have products with
EAN / MPN / article numbers, and how many?** DB-side only (raw DBAL, no API
call, no credentials). Counts live-version products (variants included by
default, `--parents-only` restricts to `parent_id IS NULL`) per identifier
dimension in one aggregate `SUM(CASE …)` query, plus an OR-combined "Any
identifier" row; `--also-customfields` adds a per-custom-field-name table
(JSON-decoded in PHP, same semantics as the matcher's `_loadCustomFieldMap`).
Output is a pretty box table in the import summary's visual style; zero-count
cells render yellow-bold (the "mapping is dead" symptom), >0 green-bold.

# 2. Files Changed

### New
- `src/Service/Db/TdmpProductCountService.php` — raw DBAL counting service
  (single aggregate query for identifiers; PHP-side custom-field decode;
  `hasCustomFieldsColumn()` schema guard).
- `src/Command/Command_TdmpCount.php` — `topdata:mapper:count` with
  `-c/--also-customfields` and `-p/--parents-only`, box tables mirroring
  `Command_TdmpImport`'s helpers (`_cell`, `_padCell`, `_renderTable`).
- `_ai/backlog/reports/260818_1442__IMPLEMENTATION_REPORT__count-command.md` —
  this report.

### Modified
- `src/Resources/config/services.xml` — registered `TdmpProductCountService`
  (autowire) and `Command_TdmpCount` (autowire + `console.command` tag).
- `AGENTS.md` — commands table row for `topdata:mapper:count`.
- `README.md` — new "Debug: count identifiers" usage subsection.
- `CHANGELOG.md` — `[Unreleased]` → `### Added` entry.

# 3. Key Changes

- **One roundtrip for all identifier counts**: `COUNT(*)` total + four
  `SUM(CASE …)` dimensions incl. the OR-combined "any identifier" sum, filtered
  by `version_id = 0x` . `TdmpProductService::LIVE_VERSION_HEX`
  (live-version products only, mirroring the matcher).
- **Custom fields decoded in PHP** (`json_decode` + `_hasNonEmptyValue`:
  non-empty strings and numerics count, empty strings ignored — same filter as
  the matcher), aggregated per field name, `arsort` by count desc. Only queried
  when the `product` table actually has a `custom_fields` column.
- **Zero-dimension styling**: count cells green-bold when > 0, yellow-bold
  when 0 — inverse of the import summary's unmatched styling, same color
  language (yellow = suspicious).
- **Total products row** after a `TableSeparator`, bold, 100.0%; empty
  custom-fields table renders a "— no custom fields set —" placeholder row.

# 4. Deviations from Plan

- **Schema guard for `custom_fields`** (`TdmpProductCountService::hasCustomFieldsColumn()`
  + `CliLogger::warning` branch in the command): the dev shop's `product`
  table has **no `custom_fields` column** (trimmed MariaDB schema; verified
  via `SHOW COLUMNS` — the matcher would fail identically on such shops).
  Without the guard, `--also-customfields` crashed with
  `SQLSTATE[42S22]: Unknown column 'custom_fields'`. The command now skips the
  custom-field table with a warning instead of crashing. Everything else per
  plan (the plan's custom-fields query text is unchanged, just guarded).

# 5. Technical Decisions

- Live-version filter via the constant `TdmpProductService::LIVE_VERSION_HEX`
  (hex literal in SQL, existing convention) — count scope equals what the
  import can match.
- Variants included by default (matcher sees all live rows), `--parents-only`
  mirrors the `parent_id IS NULL` filter.
- Custom fields via PHP JSON decode, not SQL JSON functions — consistent with
  the matcher and portable across MariaDB/MySQL.
- Schema guard via `information_schema.COLUMNS` (one cheap indexed lookup) —
  the command must never crash on a DB quirk; it is a debugging tool.

# 6. Testing Notes

Manual verification in the dev shop (`sw67-www` container, Shopware root
`/www`), `php -l` clean on both new files:

- `bin/console topdata:mapper:count` → Identifier counts table,
  19,504 products incl. variants, EAN/MPN/Article number/Any all 100.0%
  (dev shop has uniform data), Total row 19,504. Sample output:

```
┌──────────────────┬────────── Identifier counts ──────────────────────────────┐
│    Identifier    │  Products  │  % of total  │         Source column         │
├──────────────────┼────────────┼──────────────┼───────────────────────────────┤
│       EAN        │   19,504   │    100.0%    │          product.ean          │
│       MPN        │   19,504   │    100.0%    │  product.manufacturer_number  │
│  Article number  │   19,504   │    100.0%    │    product.product_number     │
│  Any identifier  │   19,504   │    100.0%    │                               │
├──────────────────┼────────────┼──────────────┼───────────────────────────────┤
│  Total products  │   19,504   │    100.0%    │                               │
└──────────────────┴────────────┴──────────────┴───────────────────────────────┘
```

- `--parents-only` → 15,107 main products (variant filter active).
- `--also-customfields` → warning
  "product.custom_fields column not present in this shop — custom-field counts
  skipped." (schema guard, see Deviations).
- `bin/console list | grep topdata` → `topdata:mapper:count` listed.
- Sanity cross-check: EAN row count equals raw
  `SELECT COUNT(*) FROM product WHERE version_id = 0x0fa91ce3e96a4bc2be4bd9ce752c3425 AND ean <> ''`.
- No API requests made, no credentials configured — DB-only confirmed.
- The green/yellow count-cell styling and the "no custom fields set"
  placeholder row could not be exercised visually (dev shop has uniform data
  and no custom-fields column); logic is trivial and reviewed statically.

# 7. Usage Examples

```bash
bin/console topdata:mapper:count                     # EAN / MPN / article number, incl. variants
bin/console topdata:mapper:count --also-customfields # + per custom-field counts
bin/console topdata:mapper:count --parents-only      # main products only
```

# 8. Documentation Updates

- `CHANGELOG.md` — `[Unreleased]` → `### Added` (debug command entry).
- `README.md` — "Debug: count identifiers" usage subsection (DB-side only note).
- `AGENTS.md` — commands table row for `topdata:mapper:count`.
- `.gitignore` — no change needed (no new artifact types).

# 9. Follow-up Fix (2026-08-18) — placeholder values over-reported

**Reported**: the first release counted 19,504 / 19,504 products with EAN and
MPN — suspicious, "do really ALL products have EAN and MPN?".

**Root cause**: `countIdentifiers()` used `<> ''` as the only filter. The dev
shop (and likely real shops) import junk placeholder values: 1,801 of 19,504
products have `-` / `n/a` in `ean` and `manufacturer_number`. Verified
directly in `sw67-mariadb` (the DB the command reads; `focus-mariadb` holds a
stale copy with 19,488 rows):

| dimension | raw non-empty | digit / usable | placeholder |
|---|---|---|---|
| ean | 19,504 | 17,703 | 1,801 |
| manufacturer_number | 19,504 | 19,504 | 1,801 |
| product_number | 19,504 | 19,504 | 0 |

The matcher's `_loadIdentifierMap()` already drops values that normalize to
empty (`UtilIdentifierNormalizer::normalizeEan('-')` = `''`), so the count
command violated its own "mirrors the matcher" contract for EAN.

**Fix**:
- `TdmpProductCountService::countIdentifiers()` now uses matcher-exact
  filters — EAN: `ean REGEXP '[0-9]'` (contains a digit, equivalent to
  `normalizeEan() !== ''`); MPN / article number: `TRIM(...) <> ''` — and
  additionally computes per-dimension placeholder counts (`-`, `n/a` trimmed
  case-insensitive; EAN additionally "non-empty but no digit").
- `Command_TdmpCount::_printIdentifierTable()` renders a yellow
  `placeholder (-, n/a)` sub-row under a dimension whenever its placeholder
  count is > 0.
- Docs updated (CHANGELOG, README, AGENTS.md).

**Revised per review (same day)** — placeholders are now excluded from the
main counts **by default** for all three dimensions (MPN / article number
additionally exclude the `-` / `n/a` tokens — stricter than the matcher,
which technically keeps a `-` MPN, and deliberate: a debug count should
report usable identifiers). The placeholder breakdown is only rendered with
the new `--show-placeholders` flag, and the sub-row label was made precise
(what is counted / that it is excluded from the row above):
"`placeholder-only (no digit) — excluded above`" (EAN) and
"`placeholder-only (-, n/a) — excluded above`" (MPN / article number).

**Verified** in the dev shop:

Default (`bin/console topdata:mapper:count`) — placeholders excluded, no
sub-rows:

```
│       EAN        │   17,703   │    90.8%     │          product.ean          │
│       MPN        │   17,703   │    90.8%     │  product.manufacturer_number  │
│  Article number  │   19,504   │    100.0%    │    product.product_number     │
│  Any identifier  │   19,504   │    100.0%    │                               │
```

With `--show-placeholders`:

```
│                       EAN                        │   17,703   │    90.8%     │          product.ean          │
│    placeholder-only (no digit) — excluded above  │   1,801    │     9.2%     │                               │
│                       MPN                        │   17,703   │    90.8%     │  product.manufacturer_number  │
│     placeholder-only (-, n/a) — excluded above   │   1,801    │     9.2%     │                               │
│                  Article number                  │   19,504   │    100.0%    │    product.product_number     │
```

Numbers cross-checked against raw SQL on `sw67-mariadb` (identical; per
dimension: usable + placeholder = total). `--parents-only` run (15,107):
EAN 13,596 usable / 1,511 placeholder, MPN 13,638 / 1,469 placeholder.

**Follow-up (2) — article number rows removed** (same day): `product_number`
is mandatory and unique in Shopware 6 (`ProductDefinition.php:163`,
`Required` flag), so "Article number" was always 100% and "Any identifier"
(the OR of all three dimensions) trivially equaled the total — no debugging
value. Both rows and their service counters (`with_article_number`,
`with_any`, `placeholder_article_number`) were removed from
`TdmpProductCountService::countIdentifiers()` and
`Command_TdmpCount::_printIdentifierTable()`; the table now shows EAN, MPN
(+ optional placeholder sub-rows) and the Total row. Docs updated. Verified
in the dev shop (EAN 17,703 / MPN 17,703 / total 19,504).