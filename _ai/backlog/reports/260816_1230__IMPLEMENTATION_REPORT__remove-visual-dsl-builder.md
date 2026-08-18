---
filename: "_ai/backlog/reports/260816_1230__IMPLEMENTATION_REPORT__remove-visual-dsl-builder.md"
title: "Report: Remove the visual DSL builder from the settings page — DSL string + preset chips are the only editor; DSL grammar gains parentheses for explicit precedence"
createdAt: 2026-08-18 10:15
updatedAt: 2026-08-18 10:15
planFile: "_ai/backlog/active/260816_1230__IMPLEMENTATION_PLAN__remove-visual-dsl-builder.md"
project: "topdata-mapper-sw6"
status: completed
filesCreated: 1
filesModified: 16
filesDeleted: 0
tags: [admin, dsl, settings, refactor, ui, grammar]
documentType: IMPLEMENTATION_REPORT
---

# 1. Summary

The visual DSL builder is gone from the settings strategy editor. The DSL
textarea (debounced live validation), preset chips, dirty-confirm modal and
syntax help modal are now the entire editor. In the same pass the DSL grammar
gained `( )` groups for explicit precedence (`&` still binds tighter than `|`
without parens): the parser was rewritten as a recursive-descent cursor
scanner (`DslAndExpr::leaves` → `DslAndExpr::items`, nested `DslOrExpr` nodes),
the canonical serializer re-emits parentheses verbatim, the matcher evaluates
recursively (with a new deduping `_union`) and the provider-existence check
walks paren groups. All flat strategies (default + presets) keep their
canonical form unchanged.

# 2. Files Changed

### Modified
- `src/Controller/Api/TopdataMapperActionController.php` — `getStrategyAction()` no longer returns `providers`; `validateStrategyAction()` no longer returns `ast` (only `{valid, error}`); `_fetchProviders()` docblock now describes the provider-id existence check; `_assertProvidersExist()` recurses into paren groups.
- `src/Service/Dsl/DslSerializer.php` — `toArray()` removed; `toString()` recursive with parens preserved (leaf rendering moved to `_leafToString()`); class docblock updated (no JS serializer mirror).
- `src/Service/Dsl/DslParser.php` — class docblock corrected (no more "re-renders its visual builder"); grammar block now documents `primary := leaf | '(' orExpr ')'`; `parse()` rewritten as recursive descent (`_parseOrExpr` / `_parseAndExpr` / `_parsePrimary` / `_scanLeafText` / `_skipWhitespace`); `_split()` / `_findOperatorOffset()` deleted; new error cases: unclosed group, unexpected `)`, empty primary, trailing input.
- `src/Service/Dsl/DslAndExpr.php` — `leaves` → `items` (`array<DslLeaf|DslOrExpr>`), docblock.
- `src/Service/Dsl/DslOrExpr.php` — docblock: also the paren-group node type.
- `src/Service/ProductMappingMatcher_Dsl.php` — `matchRow()` delegates to recursive `_evaluateExpr()`/`_evaluateAnd()`; new `_union()` dedupes by `product_id`; `referencesTopdataBrandIds()` walks paren groups recursively; **fix: brand map JOIN now uses `product.manufacturer` instead of `product.manufacturer_id`** (SW 6.7 column rename — the query was latent-broken and only fires for `topdataBrandIds` strategies, see Deviations).
- `src/Service/DslStrategyService.php` — comment on `BRAND_SCOPED_MPN_DSL` (parens now allowed).
- `src/Resources/app/administration/src/module/topdata-mapper-settings/page/topdata-mapper-settings/index.js` — builder model removed (data `groups`/`propertyGroups`/`customFieldNames`/`providers`, computed `shopFieldKinds`, methods `loadPropertyGroups`/`loadCustomFields`/`builderFromAst`/`dslFromBuilder`/`leafComplete`/`dimensionOptions`/`onLeaf*`/`add*`/`remove*`/`applyBuilder`/`shopFieldLabel`); `validateDsl()` consumes only `valid`/`error`; presets, dirty-confirm, help modal, save/copy kept.
- `.../topdata-mapper-settings/topdata-mapper-settings.html.twig` — whole `{% block topdata_mapper_settings_builder %}` deleted; help modal gains the `( )` grammar row and the paren example (`examples.four`).
- `.../topdata-mapper-settings/topdata-mapper-settings.scss` — builder rules removed (`__group`, `__leaf`, `__remove`, `__add-leaf`, `__add-group`, `__or-separator`).
- `.../settings/snippet/en-GB.json`, `de-DE.json` — `settings.builder.*` and `settings.shopField.*` removed; `dslHelp.intro` rewritten; `dslHelp.grammar.parens` + `dslHelp.examples.four` added.
- `src/Resources/app/administration/src/service/topdata-mapper-api-service.js` — docblocks updated (`providers`/`ast` gone) — small addition beyond the plan table.
- `AGENTS.md`, `README.md`, `CHANGELOG.md` — builder removal + parens grammar documented; README notes `(`/`)` are reserved characters.

### New
- `_ai/backlog/reports/260816_1230__IMPLEMENTATION_REPORT__remove-visual-dsl-builder.md` — this report.

# 3. Key Changes

- **Single-editor settings page**: the textarea is the only editor; the PHP
  parser/serializer are the only DSL logic (no JS mirror to drift).
- **Recursive-descent grammar**: `( )` > `&` > `|`. Byte-cursor scanning is
  UTF-8-safe (operators are ASCII; property-group names may contain
  multi-byte chars). New, precise error messages with `position`:
  "Missing ')' — unclosed group.", "Unexpected ')' — no matching '(' before
  it.", "Expected a leaf or '(' group, found nothing.", "Unexpected trailing
  input '...'".
- **Round trip preserved**: `(a | b) & c` parses → serializes → identical
  string; flat strategies serialize without parens.
- **Matcher set algebra**: deduped union across OR groups; intersection
  short-circuits on empty operand sets; `referencesTopdataBrandIds` (and
  therefore the brand-build-first ordering + `--mapping=product` guard) works
  for leaves inside paren groups.
- **API contract slimmed**: `GET /strategy` drops `providers`;
  `POST /validate-strategy` drops `ast`. Save gate unchanged (grammar +
  pairing matrix + provider-id existence, still recursing into parens).

# 4. Deviations from Plan

- **Matcher fix beyond the plan**: `ProductMappingMatcher_Dsl::_getBrandProductMap()`
  joined `product.manufacturer_id` — on SW 6.7 the column is `manufacturer`
  (verified against the dev shop schema; `SHOW COLUMNS` shows
  `manufacturer binary(16)`). Pre-existing latent bug: the query only runs
  for strategies referencing `topdataBrandIds`, which the default never did,
  so it was never exercised in this shop. The plan's own regression step
  ("a paren strategy imports and the topdataBrandIds build-order check still
  fires") could not pass without the fix. One-line change:
  `ON p.manufacturer = tb.brand_id`.
- **`parse()` trailing-`)` refinement**: the plan's error list and Phase 7
  smoke test require `product.ean:ean) & ...` → "Unexpected ')' — no matching
  '(' before it.", but the plan's code snippet would emit the generic
  "Unexpected trailing input". Added a `str_starts_with($rest, ')')` branch in
  `parse()` so the documented error message is produced.
- **api-service docblocks** updated (`providers`/`ast` references) — not in the
  plan's file table but stale after the endpoint change.
- **Environment note**: the worktree started in a pre-feature state while
  `HEAD` (commit `1a197e3`) already contained this exact feature; the
  re-implementation converged byte-identical to the committed code for all 15
  plan files, leaving the matcher fix as the only real delta.

# 5. Technical Decisions

- Decision (from the plan, kept): remove the builder — the DSL string is the
  only editor; the grammar gains parens instead of a builder that would have
  to model nested expressions.
- `(` and `)` are reserved characters: a property group / custom field name
  containing them cannot be expressed (documented in README; extremely rare,
  no fix).
- New error positions are exact leaf starts (cursor-scan based), slightly
  more precise than the old exploded-offset approximation.

# 6. Testing Notes

- `php -l` clean on all modified PHP files; `python3 -m json.tool` clean on
  both snippet files.
- Standalone round-trip harness (parser + serializer, 18 cases): flat
  strategies canonical-form identical, paren strategies round-trip verbatim,
  nested groups, provider-scoped leaf inside parens; all invalid cases throw
  the documented messages with correct positions.
- `referencesTopdataBrandIds` harness (5 cases): true/false through paren
  groups, root groups, AND chains — all correct.
- Admin build (`bin/build-administration.sh` inside `sw67-www`, Vite):
  compiled; bundle contains `grammar.parens`/`examples.four` and zero
  occurrences of removed identifiers (`addLeaf`, `builderFromAst`,
  `orSeparator`, `shopFieldKinds`, `dslFromBuilder`); bundle served (HTTP 200);
  cache cleared.
- Template/JS contract cross-check: every `$tc()` reference and every
  template binding resolves against the component (snippets and methods).
- Import regression (`bin/console topdata:mapper:import`):
  - Default flat strategy: 50 matched / 5 conflicts — identical to the
    pre-change baseline run.
  - Paren strategy `(product.ean:ean | product.product_number:articleNumbers) & product.manufacturer:topdataBrandIds`:
    import succeeds, brand build runs first (recursive
    `referencesTopdataBrandIds` fires inside the paren group), 18 matched;
    conflicts resolution flow unaffected. Strategy restored to default
    afterwards.
- Browser smoke test of the settings page was not run (no admin credentials
  available in the dev shop; password not reset to avoid breaking the
  developer's login). Covered statically + via the compiled bundle.

# 7. Usage Examples

Admin UI only. Regression check:

```bash
bin/console topdata:mapper:import
```

Parens strategy (textarea):

```
(product.ean:ean | product.product_number:articleNumbers) & product.manufacturer:topdataBrandIds
```

Saves with parens intact; `&` still binds tighter than `|` without parens.

# 8. Documentation Updates

- `CHANGELOG.md` — `[Unreleased]` gained `### Removed` (visual DSL builder,
  endpoint fields, `toArray()`) and `### Changed` (parens grammar).
- `README.md` — matching strategy section: single-editor description, parens
  + reserved characters note.
- `AGENTS.md` — admin line (strategy editor description), DSL parser/serializer
  line (single PHP implementation, no JS mirror, parens grammar).
- Lessons-learned `2026-08-14-vue3-underscore-methods.md` left untouched
  (historical record, per plan).

# 9. Next Steps

- None required. The settings-page browser smoke test can be completed
  manually in the dev shop (see Testing Notes).
