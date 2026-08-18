---
filename: "_ai/backlog/active/260816_1230__IMPLEMENTATION_PLAN__remove-visual-dsl-builder.md"
title: "Remove the visual DSL builder from the settings page — DSL string + preset chips are the only editor; DSL grammar gains parentheses for explicit precedence"
createdAt: 2026-08-16 12:30
updatedAt: 2026-08-18
status: completed
completedAt: 2026-08-18 10:12
priority: low
tags: [admin, dsl, settings, refactor, ui, grammar]
estimatedComplexity: medium
documentRevision: 2
documentType: IMPLEMENTATION_PLAN
---

# Remove the visual DSL builder from the settings page

## 1. Problem

The settings page (`topdata.mapper.settings`) currently offers **two editors** for
the matching strategy DSL:

1. a **visual builder** (group/leaf selects: shop field ↔ dimension ↔ variant,
   add/remove leaves and OR-groups), and
2. the raw **DSL textarea** plus **preset chips**.

The visual builder was already questioned once ("a discussion about whether it
was worth keeping at all", see `_ai/lessons_learned/2026-08-14-vue3-underscore-methods.md`)
but no decision was recorded and the code remained. It is the only consumer of a
whole slice of the codebase — a JS DSL serializer (`dslFromBuilder`), the
builder model (`groups`/`leaves`), two repository fetches (`property_group`,
`custom_field_set`), the `providers` init payload, and the `ast` field in the
`validate-strategy` response. It is complex to maintain (Vue 3 rendering
pitfalls, drift risk between the JS serializer and the PHP parser) while adding
little value: the DSL grammar is gaining `( )` groups for explicit precedence
(Phase 2) — a visual builder would have to model nested expressions, which is
exactly the kind of drift-prone complexity being removed here.

**Decision:** remove the visual builder. The DSL textarea (with debounced live
validation), the preset chips, the dirty-confirm modal and the syntax help
modal remain — they are the entire editor. The DSL grammar gains `( )` groups
for explicit precedence; `&` still binds tighter than `|` when no parens are
used (see Phase 2).

## 2. Executive summary

Strip every builder-specific code path while keeping the settings page fully
functional:

- **PHP**: `getStrategyAction()` no longer fetches/returns the reserved
  providers (builder-only); `validateStrategyAction()` no longer returns the
  `ast`; `DslSerializer::toArray()` (builder-only AST export) is deleted;
  docblocks that reference the visual builder are corrected (`DslParser.php`,
  `DslSerializer.php`, `TopdataMapperActionController.php`). The provider-id
  existence check on save (`_assertProvidersExist` → `_fetchProviders`) is
  **kept** — it is a backend validation gate, not UI.
- **JS**: the builder model/methods/computed in `index.js` are removed
  (`groups`, `propertyGroups`, `customFieldNames`, `loadPropertyGroups`,
  `loadCustomFields`, `builderFromAst`, `dslFromBuilder`, `leafComplete`,
  `dimensionOptions`, all `onLeaf*`/`add*`/`remove*`/`applyBuilder`, the
  `shopFieldKinds` computed and `shopFieldLabel`); `validateDsl()` only
  consumes `valid`/`error` now.
- **Template/SCSS**: the whole `{% block topdata_mapper_settings_builder %}`
  and its SCSS rules are removed; preset chips, textarea, validation alerts,
  dirty-confirm modal and help modal stay.
- **Snippets**: `settings.builder.*` and `settings.shopField.*` (builder-only)
  keys are removed from `en-GB.json`/`de-DE.json`; `dslHelp.intro` no longer
  mentions the builder.
- **Docs**: `AGENTS.md`, `README.md`, `CHANGELOG.md` updated; final phase
  writes the implementation report.
- **Grammar**: the DSL gains `( )` groups for explicit precedence (`&` still
  binds tighter than `|`). The recursive-descent parser, the nested AST
  (`DslAndExpr::items`), the recursive canonical serializer, the matcher
  evaluation and the provider-existence check are updated. All existing flat
  strategies (default + presets) stay valid and their canonical form is
  unchanged.

No migration. No routing/privilege changes. The import backstop and the
conflict/mappings modules are untouched (the matcher interface is unchanged).

## 3. Project environment

- Project Name: SW6.7 Plugin (topdata-mapper-sw6)
- Backend root: `src`
- PHP Version: 8.2 / 8.3 / 8.4
- Frontend: Shopware 6.7 admin (Vue 3), Vite-based build via `bin/build-administration.sh`
- Commands run from the Shopware root: `bin/console` at `/topdata/clones/sw67/vol/www/bin/console`

## 4. Conventions applied

- **PHP** (topdata-foundation `CONVENTIONS-PHP.md`): private methods prefixed
  with `_`, class + method docblocks, constructor property promotion,
  `match` over `switch`.
- **JS (Vue 3)**: **never** `_`-prefixed template-visible identifiers (Vue 3
  compiles `_foo` as a global — the exact bug documented in
  `_ai/lessons_learned/2026-08-14-vue3-underscore-methods.md`).
- **Snippets**: namespace root `TopdataMapperSW6`, flat JSON, keep en-GB and
  de-DE in sync.
- **Docs**: Keep a Changelog format; `_ai/backlog/{active,reports}` workflow
  artifacts.

## 5. Phases

### Phase 1 — Backend: slim the strategy endpoints (PHP)

**Files:** `src/Controller/Api/TopdataMapperActionController.php`,
`src/Service/Dsl/DslSerializer.php`, `src/Service/Dsl/DslParser.php`

**1.1 `getStrategyAction()`** — drop `providers` from the init payload
(provider dropdown was the only consumer). `allowedPairs` stays (help modal
renders the pairing matrix from it), `credentialsConfigured` stays.

[MODIFY] `src/Controller/Api/TopdataMapperActionController.php`

```php
    /**
     * Module init for the settings page: current DSL + presets + pairing
     * matrix + credential status (one round trip).
     */
    #[Route(path: '/api/_action/topdata-mapper/strategy', name: 'api.action.topdata-mapper.strategy.get', methods: ['GET'])]
    public function getStrategyAction(Context $context): JsonResponse
    {
        $this->_assertPrivilege('topdata_mapper:read', $context);

        return new JsonResponse([
            'dsl'                   => $this->strategyService->getConfiguredDsl(),
            'presets'               => $this->strategyService->getPresets(),
            'allowedPairs'          => DslPairingMatrix::toArray(),
            'credentialsConfigured' => $this->mapperClient->hasValidConfig(),
        ]);
    }
```

**1.2 `validateStrategyAction()`** — the `ast` field existed only to re-render
the builder. The frontend now needs just `{valid, error}`. Grammar + pairing
matrix validation itself is unchanged (still the debounced live-validation
gate for the textarea).

[MODIFY] `src/Controller/Api/TopdataMapperActionController.php`

```php
    /**
     * Debounced live validation while typing: {valid, error}. Grammar +
     * pairing matrix only (no provider check — the provider scope is validated
     * on save and re-validated by the import backstop).
     */
    #[Route(path: '/api/_action/topdata-mapper/validate-strategy', name: 'api.action.topdata-mapper.strategy.validate', methods: ['POST'])]
    public function validateStrategyAction(Request $request, Context $context): JsonResponse
    {
        $this->_assertPrivilege('topdata_mapper:read', $context);

        $dsl = (string)($request->request->all()['dsl'] ?? '');
        try {
            $this->dslParser->parse($dsl);

            return new JsonResponse(['valid' => true, 'error' => null]);
        } catch (DslParseException $e) {
            return new JsonResponse(['valid' => false, 'error' => $e->toArray()]);
        }
    }
```

**1.3 `_fetchProviders()`** — keep the method (still needed by
`_assertProvidersExist()` on save) but update its docblock: it no longer feeds
a UI dropdown, it feeds the provider-id existence check.

[MODIFY] `src/Controller/Api/TopdataMapperActionController.php`

```php
    /**
     * Reserved providers of the webservice user (provider-id existence check
     * for articleNumbers.<provider> leaves on save). Best effort:
     * unreachable/not configured → [] so the check degrades to a no-op.
     *
     * @return list<array{id: int, name: string}>
     */
    private function _fetchProviders(): array
```

**1.4 `DslSerializer::toArray()`** — delete (its only caller was the
`validate-strategy` `ast` response). `toString()` stays (canonical form on
save) — its body is replaced by the recursive version in Phase 2 (parentheses
preservation). Update the class docblock (no more JS-side serializer mirror).

[MODIFY] `src/Service/Dsl/DslSerializer.php`

```php
/**
 * Serializes a DSL AST back to the canonical DSL string (stored on save).
 * The frontend only sends/validates DSL strings — there is no JS serializer.
 * Nested `( ... )` groups are re-emitted verbatim (Phase 2).
 *
 * 08/2026 created, 08/2026 toArray() removed (visual builder removed)
 */
class DslSerializer
{
    public function toString(DslOrExpr $ast): string
    {
        // body replaced in Phase 2: recursive, parens preserved
    }
}
```

(Remove the `toArray()` method and its docblock entirely.)

**1.5 `DslParser` class docblock** — it claims the settings page "re-renders
its visual builder from the AST". Correct it. (The grammar block in the same
docblock is replaced in Phase 2 with the parentheses grammar.)

[MODIFY] `src/Service/Dsl/DslParser.php`

```php
/**
 * Recursive-descent parser for the matching DSL (the single authoritative
 * parser — the settings page validates its DSL textarea against it, and the
 * import fails loudly on invalid stored strategies).
 *
 * Grammar (see Phase 2 for `( )` groups):
 * ...
```

**Verification Phase 1:** `php -l` on all three files; `grep -rn "toArray" src/Service/Dsl/` shows only `DslParseException::toArray()` and `DslPairingMatrix::toArray()` remaining.

### Phase 2 — Grammar: parentheses for explicit precedence (PHP)

The DSL gains `( )` groups. With the visual builder gone, the DSL string is
the only editor — explicit precedence makes the textarea expressive enough for
`(a | b) & c` strategies. This is a **backend-only grammar change**; the
settings page needs no new UI (validation is server-side, error position is
already rendered).

New grammar (operator precedence: `( )` > `&` > `|`):

```
strategy := orExpr
orExpr   := andExpr ('|' andExpr)*     // | = union of matched product sets
andExpr  := primary ('&' primary)*     // & = intersection
primary  := leaf | '(' orExpr ')'      // parens override precedence
leaf     := shopField ':' dimensionRef
```

**Files:** `src/Service/Dsl/DslParser.php`, `src/Service/Dsl/DslOrExpr.php`,
`src/Service/Dsl/DslAndExpr.php`, `src/Service/Dsl/DslSerializer.php`,
`src/Service/ProductMappingMatcher_Dsl.php`,
`src/Controller/Api/TopdataMapperActionController.php`,
`src/Service/DslStrategyService.php` (comment only)

**2.1 AST — `DslAndExpr::leaves` → `DslAndExpr::items`**

Parenthesized sub-expressions are plain `DslOrExpr` nodes (same class as the
root), nested inside an AND group. `DslOrExpr` keeps its shape
(`groups: DslAndExpr[]`), so the root contract of `parse()`,
`setStrategy()`, `DslStrategyService::getConfiguredStrategy()` is unchanged.

[MODIFY] `src/Service/Dsl/DslAndExpr.php`

```php
/**
 * AST node of the matching DSL: conjunction of operands (`a & b & ...`).
 * Evaluation = intersection of the per-operand product sets.
 *
 * Operands are leaves and/or parenthesized sub-expressions (DslOrExpr).
 *
 * 08/2026 created, 08/2026 leaves → items (parentheses support)
 */
class DslAndExpr
{
    /**
     * @param array<DslLeaf|DslOrExpr> $items at least one operand (enforced by the parser)
     */
    public function __construct(public readonly array $items)
    {
    }
}
```

[MODIFY] `src/Service/Dsl/DslOrExpr.php` — docblock only:

```php
/**
 * AST node of the matching DSL: disjunction of AND groups (`a | b | ...`).
 * Evaluation = union of the per-group product sets. Also the node type of
 * parenthesized sub-expressions (nested as items of DslAndExpr).
 *
 * 08/2026 created, 08/2026 docblock: paren groups reuse this node
 */
```

**2.2 `DslParser` — recursive descent with a cursor scanner**

`parse()` is rewritten from `explode`-based splitting to a cursor scanner.
The `_split()` / `_findOperatorOffset()` helpers are deleted. Leaf parsing
(`_parseLeaf`, `_parseDimensionRef`, `_kindOf`, pairing-matrix checks) is
unchanged. Byte-cursor access is safe: the operator characters `( ) | & :`
are all ASCII, UTF-8 multi-byte property-group names never collide with them.

[MODIFY] `src/Service/Dsl/DslParser.php`

```php
public function parse(string $dsl): DslOrExpr
{
    $dsl = trim($dsl);
    if ($dsl === '') {
        throw new DslParseException('The DSL string is empty.');
    }

    [$ast, $end] = $this->_parseOrExpr($dsl, 0);
    $rest = trim(substr($dsl, $end));
    if ($rest !== '') {
        throw new DslParseException("Unexpected trailing input '{$rest}'.", position: $end);
    }

    return $ast;
}

/**
 * @return array{DslOrExpr, int} [expr, end-cursor]
 */
private function _parseOrExpr(string $dsl, int $offset): array
{
    $groups = [];
    do {
        [$group, $offset] = $this->_parseAndExpr($dsl, $offset);
        $groups[] = $group;
        [$offset] = $this->_skipWhitespace($dsl, $offset);
        if (($dsl[$offset] ?? '') !== '|') {
            break;
        }
        $offset++; // consume '|'
    } while (true);

    return [new DslOrExpr($groups), $offset];
}

/**
 * @return array{DslAndExpr, int} [expr, end-cursor]
 */
private function _parseAndExpr(string $dsl, int $offset): array
{
    $items = [];
    do {
        [$item, $offset] = $this->_parsePrimary($dsl, $offset);
        $items[] = $item;
        [$offset] = $this->_skipWhitespace($dsl, $offset);
        if (($dsl[$offset] ?? '') !== '&') {
            break;
        }
        $offset++; // consume '&'
    } while (true);

    return [new DslAndExpr($items), $offset];
}

/**
 * @return array{DslLeaf|DslOrExpr, int}
 */
private function _parsePrimary(string $dsl, int $offset): array
{
    [$offset] = $this->_skipWhitespace($dsl, $offset);
    $char = $dsl[$offset] ?? '';

    if ($char === '(') {
        [$inner, $offset] = $this->_parseOrExpr($dsl, $offset + 1);
        [$offset] = $this->_skipWhitespace($dsl, $offset);
        if (($dsl[$offset] ?? '') !== ')') {
            throw new DslParseException("Missing ')' — unclosed group.", position: $offset);
        }

        return [$inner, $offset + 1];
    }
    if ($char === ')') {
        throw new DslParseException("Unexpected ')' — no matching '(' before it.", position: $offset);
    }

    [$leafTextRaw, $offset] = $this->_scanLeafText($dsl, $offset);
    $leafText = trim($leafTextRaw);
    if ($leafText === '') {
        throw new DslParseException("Expected a leaf or '(' group, found nothing.", position: $offset - strlen($leafTextRaw));
    }

    return [$this->_parseLeaf($leafText, $offset - strlen($leafTextRaw)), $offset];
}

/**
 * @return array{string, int} [raw leaf text (untrimmed), end-cursor]
 */
private function _scanLeafText(string $dsl, int $offset): array
{
    $end = $offset;
    while (isset($dsl[$end]) && !in_array($dsl[$end], ['|', '&', '(', ')'], true)) {
        $end++;
    }

    return [substr($dsl, $offset, $end - $offset), $end];
}

/**
 * @return array{int} [offset after whitespace]
 */
private function _skipWhitespace(string $dsl, int $offset): array
{
    while (isset($dsl[$offset]) && ctype_space($dsl[$offset])) {
        $offset++;
    }

    return [$offset];
}
```

New error cases (all `DslParseException` with `position`):
- `(product.ean:ean | product.product_number:articleNumbers) & product.manufacturer:topdataBrandIds` — valid.
- `(product.ean:ean` — "Missing ')' — unclosed group." (position where `)` was expected, i.e. end of input).
- `product.ean:ean) & product.manufacturer_number:mpn` — "Unexpected ')' — no matching '(' before it."
- `product.ean:ean & ( )` — the inner `_parsePrimary` hits `)` → "Unexpected ')'..." (empty group is covered by the same path).
- `product.ean:ean |` — trailing `|` → "Expected a leaf or '(' group, found nothing."

**2.3 `DslSerializer::toString()` — recursive, parens preserved**

The canonical form keeps the structure: every nested `DslOrExpr` item is
re-emitted as `( ... )`. Root-level groups stay parens-free. Round trip:
`(a | b) & c` parses → serializes → identical string.

[MODIFY] `src/Service/Dsl/DslSerializer.php`

```php
public function toString(DslOrExpr $ast): string
{
    $groups = [];
    foreach ($ast->groups as $group) {
        $parts = [];
        foreach ($group->items as $item) {
            $parts[] = $item instanceof DslLeaf
                ? $this->_leafToString($item)
                : '(' . $this->toString($item) . ')';
        }
        $groups[] = implode(' & ', $parts);
    }

    return implode(' | ', $groups);
}

private function _leafToString(DslLeaf $leaf): string
{
    return $leaf->shopField . ':' . ($leaf->dimensionVariant !== null
        ? $leaf->dimension . '.' . $leaf->dimensionVariant
        : $leaf->dimension);
}
```

(The leaf rendering moves from the inline body into `_leafToString`.)

**2.4 `ProductMappingMatcher_Dsl` — recursive evaluation**

`matchRow()` delegates to a recursive evaluator; a nested `DslOrExpr` item is
evaluated by recursion and intersected with the rest of its AND group. The
new `_union()` dedupes by `product_id` — a product matched by two OR groups
now appears once (the old loop appended per-group matches without dedupe; no
consumer relies on duplicates).

[MODIFY] `src/Service/ProductMappingMatcher_Dsl.php`

```php
public function matchRow(object $row): array
{
    if ($this->strategy === null) {
        throw new \RuntimeException('No matching strategy set — call setStrategy() before matchRow().');
    }

    return $this->_evaluateExpr($this->strategy, $row);
}

/**
 * @return list<array{product_id: string}> deduped union over the OR groups
 */
private function _evaluateExpr(DslOrExpr $expr, object $row): array
{
    $matches = [];
    foreach ($expr->groups as $group) {
        $matches = $this->_union($matches, $this->_evaluateAnd($group, $row));
    }

    return $matches;
}

/**
 * @return list<array{product_id: string}> intersection over the AND operands
 */
private function _evaluateAnd(DslAndExpr $group, object $row): array
{
    $result = null;
    foreach ($group->items as $item) {
        $itemMatches = $item instanceof DslLeaf
            ? $this->_evaluateLeaf($item, $row)
            : $this->_evaluateExpr($item, $row);
        if (empty($itemMatches)) {
            return [];
        }
        $result = $result === null ? $itemMatches : $this->_intersect($result, $itemMatches);
        if (empty($result)) {
            return [];
        }
    }

    return $result ?? [];
}

/**
 * @param list<array{product_id: string}> $a
 * @param list<array{product_id: string}> $b
 * @return list<array{product_id: string}> deduped union
 */
private function _union(array $a, array $b): array
{
    $seen = [];
    foreach ($a as $product) {
        $seen[$product['product_id']] = true;
    }
    foreach ($b as $product) {
        if (!isset($seen[$product['product_id']])) {
            $a[] = $product;
        }
    }

    return $a;
}
```

`referencesTopdataBrandIds()` becomes recursive too (same static-style walk):

```php
public static function referencesTopdataBrandIds(DslOrExpr $strategy): bool
{
    foreach ($strategy->groups as $group) {
        foreach ($group->items as $item) {
            if ($item instanceof DslLeaf) {
                if ($item->dimension === DslPairingMatrix::DIMENSION_BRAND_IDS) {
                    return true;
                }
            } elseif (self::referencesTopdataBrandIds($item)) {
                return true;
            }
        }
    }

    return false;
}
```

`_evaluateLeaf`, `_intersect`, map loading and all other private methods are
unchanged.

**2.5 Controller `_assertProvidersExist()` — recursion**

[MODIFY] `src/Controller/Api/TopdataMapperActionController.php`

```php
private function _assertProvidersExist(DslOrExpr $ast, string $dsl): void
{
    $providerIds = [];
    foreach ($this->_fetchProviders() as $provider) {
        $providerIds[$provider['id']] = true;
    }
    if (empty($providerIds)) {
        return;
    }

    foreach ($ast->groups as $group) {
        foreach ($group->items as $item) {
            if ($item instanceof DslOrExpr) {
                $this->_assertProvidersExist($item, $dsl);
                continue;
            }

            $leaf = $item;
            if ($leaf->dimensionVariant === null) {
                continue;
            }
            $providerId = (int)$leaf->dimensionVariant;
            if (!isset($providerIds[$providerId])) {
                throw new DslParseException(
                    "Unknown provider id '{$leaf->dimensionVariant}' in articleNumbers.<provider> — not among your reserved providers.",
                    shopField: $leaf->shopField,
                    dimension: 'articleNumbers.' . $leaf->dimensionVariant,
                    position: strpos($dsl, $leaf->shopField . ':' . $leaf->dimension . '.' . $leaf->dimensionVariant)
                );
            }
        }
    }
}
```

(Needs `use Topdata\TopdataMapperSW6\Service\Dsl\DslOrExpr;` — already
imported at the top of the controller.)

**2.6 `DslStrategyService` — stale comment**

[MODIFY] `src/Service/DslStrategyService.php` — line 29 comment:

```php
/** Brand-scoped MPN: `&` binds tighter than `|`; `( )` groups are allowed but not needed here. */
```

Presets stay flat — their canonical form is unchanged and they double as
paren-free examples in the help modal.

**Note (documented, not fixed):** `(` and `)` become reserved characters —
a property group name or custom field name containing them cannot be
expressed in the DSL (e.g. `property.ABC (old)`). Extremely rare; noted in
README (Phase 6).

**Verification Phase 2:** `php -l` on all touched PHP files; `grep -rn "->leaves" src/` → 0;
`grep -rn "_split\|_findOperatorOffset" src/` → 0. Round-trip spot checks via
`bin/console` debug (or ad-hoc `/tmp/debug.log`):
- `product.ean:ean | product.product_number:articleNumbers` → identical after save.
- `(product.ean:ean | product.product_number:articleNumbers) & product.manufacturer:topdataBrandIds` → identical after save.
- `(product.ean:ean | product.manufacturer_number:mpn) & product.manufacturer:topdataBrandIds` parses; `product.manufacturer:topdataBrandIds` leaf inside a paren group still triggers the provider-id/`topdataBrandIds` build-order logic (`referencesTopdataBrandIds` → brand build runs first).

### Phase 3 — Frontend: strip the builder from the settings component (JS)

**File:** `src/Resources/app/administration/src/module/topdata-mapper-settings/page/topdata-mapper-settings/index.js`

Remove everything builder-specific:

- **data**: `groups`, `propertyGroups`, `customFieldNames`, `providers`
- **computed**: `shopFieldKinds`
- **methods**: `loadPropertyGroups`, `loadCustomFields`, `builderFromAst`,
  `dslFromBuilder`, `leafComplete`, `dimensionOptions`, `onLeafShopFieldChange`,
  `onLeafDimensionChange`, `onLeafVariantChange`, `addLeaf`, `removeLeaf`,
  `addGroup`, `removeGroup`, `applyBuilder`, `shopFieldLabel`
- **kept**: `isDirty`, `activePresetKey` (preset chip highlight),
  `dimensionLabel` (used by the help modal pairing list), `openDslHelp`/
  `closeDslHelp`, presets flow (`applyPreset`, `onPresetClick`,
  `confirmPreset`, `cancelPreset`), `validateDsl` (now without AST handling),
  `save`, `copyDsl` (still uses `this.$refs.dslTextarea`)

[MODIFY] `.../topdata-mapper-settings/page/topdata-mapper-settings/index.js`

```js
import template from './topdata-mapper-settings.html.twig';
import './topdata-mapper-settings.scss';

const { Component, Mixin } = Shopware;

/**
 * Card B — matching strategy editor.
 *
 * The DSL string is the single source of truth; the textarea is the only
 * editor (preset chips fill it, the help modal documents the grammar). The
 * PHP parser is authoritative — textarea edits are re-validated debounced via
 * validate-strategy.
 *
 * 08/2026 created, 08/2026 visual builder removed
 */
Component.register('topdata-mapper-settings', {
    template,

    inject: ['TopdataMapperApiService'],
    mixins: [Mixin.getByName('notification')],

    data: () => ({
        isLoading: true,
        isSaving: false,
        isCopying: false,

        // ---- DSL string (source of truth) ----
        dsl: '',
        lastLoadedDsl: '',

        // ---- module init data ----
        presets: [],
        allowedPairs: {},
        credentialsConfigured: false,

        // ---- validation state ----
        validationValid: true,
        validationError: null,

        // ---- dirty-check confirm (preset chip) ----
        showDirtyConfirm: false,
        pendingPreset: null,

        // ---- DSL syntax help modal ----
        showDslHelp: false,

        saveError: null,
    }),

    computed: {
        isDirty() {
            return this.dsl !== this.lastLoadedDsl;
        },

        /**
         * Preset chip highlight: a chip is active iff the current DSL equals
         * its canonical string; "Custom" is active iff no preset matches.
         */
        activePresetKey() {
            const matching = this.presets.find((preset) => preset.dsl !== null && preset.dsl === this.dsl);

            return matching ? matching.key : 'custom';
        },
    },

    created() {
        this.debouncedValidate = Shopware.Utils.debounce(this.validateDsl, 400);
        this.loadStrategy();
    },

    watch: {
        dsl() {
            this.debouncedValidate();
        },
    },

    methods: {
        // ---------------------------------------------------------------- init
        loadStrategy() {
            this.isLoading = true;
            this.TopdataMapperApiService.getStrategy()
                .then((response) => {
                    this.dsl = response.data.dsl;
                    this.lastLoadedDsl = response.data.dsl;
                    this.presets = response.data.presets;
                    this.allowedPairs = response.data.allowedPairs;
                    this.credentialsConfigured = response.data.credentialsConfigured;
                    return this.validateDsl();
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        // ------------------------------------------------------------- labels
        openDslHelp() {
            this.showDslHelp = true;
        },

        closeDslHelp() {
            this.showDslHelp = false;
        },

        dimensionLabel(dimension) {
            const labels = {
                'ean': this.$tc('TopdataMapperSW6.settings.dimension.ean'),
                'mpn': this.$tc('TopdataMapperSW6.settings.dimension.mpn'),
                'pcd': this.$tc('TopdataMapperSW6.settings.dimension.pcd'),
                'articleNumbers': this.$tc('TopdataMapperSW6.settings.dimension.articleNumbers'),
                'topdataBrandIds': this.$tc('TopdataMapperSW6.settings.dimension.topdataBrandIds'),
            };

            return labels[dimension] || dimension;
        },

        // ------------------------------------------------------------ presets
        applyPreset(preset) {
            this.dsl = preset.dsl;
            this.lastLoadedDsl = preset.dsl;
            this.saveError = null;
            this.validateDsl();
        },

        onPresetClick(preset) {
            if (preset.dsl === null) {
                return;
            }
            if (this.isDirty) {
                this.pendingPreset = preset;
                this.showDirtyConfirm = true;

                return;
            }
            this.applyPreset(preset);
        },

        confirmPreset() {
            this.showDirtyConfirm = false;
            if (this.pendingPreset) {
                this.applyPreset(this.pendingPreset);
            }
            this.pendingPreset = null;
        },

        cancelPreset() {
            this.showDirtyConfirm = false;
            this.pendingPreset = null;
        },

        // ---------------------------------------------------------- validation
        validateDsl() {
            return this.TopdataMapperApiService.validateStrategy(this.dsl)
                .then((response) => {
                    this.validationValid = response.data.valid;
                    this.validationError = response.data.error;
                })
                .catch(() => {
                    this.validationValid = false;
                    this.validationError = { message: this.$tc('TopdataMapperSW6.settings.validation.unreachable') };
                });
        },

        // --------------------------------------------------------------- save
        save() { /* unchanged */ },

        // --------------------------------------------------------------- copy
        copyDsl() { /* unchanged */ },
    },
});
```

**Verification Phase 3:** `grep -n "groups\|providers\|dimensionOptions\|shopFieldKinds" index.js` returns nothing.

### Phase 4 — Template + SCSS cleanup

**Files:** `.../topdata-mapper-settings/topdata-mapper-settings.html.twig`,
`.../topdata-mapper-settings/topdata-mapper-settings.scss`

**4.1** Delete the whole `{% block topdata_mapper_settings_builder %}` section
(currently lines 75–184: the `v-for` group/leaf containers, contextual
variant selects, add/remove buttons and the "— or —" separator). The preset
chips block, the DSL textarea block, the validation alerts, the dirty-confirm
modal and the help modal remain unchanged.

[MODIFY] `topdata-mapper-settings.html.twig` — remove lines 75–184:

```twig
{% block topdata_mapper_settings_builder %}
    ... entire builder markup (group/leaf containers, sw-select-fields,
        add-leaf/add-group buttons, or-separator) ...
{% endblock %}
```

**4.2** Remove the builder-only SCSS rules: `__group`, `__leaf`, `__remove`,
`__add-leaf`/`__add-group`, `__or-separator`. Keep `__loader`, `__presets`,
`__dsl`, `__dsl-actions`, `__dsl-help*`, `__credentials-text`.

**4.3** Help modal: add one `<li>` to the examples list (after the
`examples.three` item; anchors are text-based — `dslHelp.examples.three` /
`dslHelp.grammar.or` — since line numbers shift after 4.1 removes the
builder block):

[MODIFY] `topdata-mapper-settings.html.twig`

```twig
<li>
    <p class="topdata-mapper-settings__dsl-help-code">
        <code>(product.ean:ean | product.product_number:articleNumbers.4123) &amp; product.manufacturer:topdataBrandIds</code>
    </p>
    <p>{{ $tc('TopdataMapperSW6.settings.dslHelp.examples.four') }}</p>
</li>
```

And one `<li>` under the grammar list (after the `|` row):

```twig
<li>
    <code>( )</code> — {{ $tc('TopdataMapperSW6.settings.dslHelp.grammar.parens') }}
</li>
```

(No CSS needed — reuses `__dsl-help-code` / `__dsl-help-examples`.)

[MODIFY] `topdata-mapper-settings.scss`

```scss
.topdata-mapper-settings {
    &__loader {
        margin: 24px;
    }

    &__presets {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 24px;
    }

    &__dsl {
        margin-top: 24px;

        .sw-button {
            margin-top: 8px;
        }
    }

    &__dsl-actions {
        display: flex;
        gap: 8px;
    }

    &__dsl-help {
        /* unchanged: h3, ul, li, code, &-code, &-examples */
    }

    &__credentials-text {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0;
    }
}
```

### Phase 5 — Snippet cleanup (en-GB + de-DE)

**Files:** `.../topdata-mapper-settings/snippet/en-GB.json`,
`.../topdata-mapper-settings/snippet/de-DE.json`

- **Remove** the whole `settings.builder` key (shopField/dimension/provider/
  allProviders/propertyGroup/customField/addLeaf/addGroup/orSeparator).
- **Remove** `settings.shopField.*` (builder-only; `shopFieldLabel` is gone).
  Keep `settings.dimension.*` (help modal uses `dimensionLabel`).
- **Update** `dslHelp.intro` — no longer references the visual builder;
  mentions the new `( )` precedence groups.
- **Add** `dslHelp.grammar.parens` and `dslHelp.examples.four` (consumed by
  the new help-modal rows from Phase 4.3).

[MODIFY] `.../snippet/en-GB.json`

```json
"dslHelp": {
    "title": "DSL syntax reference",
    "intro": "The matching strategy is a set algebra over identifier dimensions: it defines which Shopware field is matched against which Topdata identifier. The DSL string is the single source of truth — start from a preset chip or type the DSL directly.",
    "grammar": {
        "title": "Grammar",
        "leaf": "Each condition (leaf) pairs a Shopware field with a Topdata identifier:",
        "and": "AND — all operands in a group must match",
        "or": "OR — at least one group must match",
        "parens": "( ) — group a sub-expression to override precedence (& binds tighter than |)"
    },
    "examples": {
        "title": "Examples",
        "one": "Match by EAN, manufacturer number or product number.",
        "two": "AND: match by EAN and manufacturer brand.",
        "three": "Product number against one provider's article numbers.",
        "four": "Parentheses: match by EAN or article number, restricted to one manufacturer brand."
    },
    ...
}
```

[MODIFY] `.../snippet/de-DE.json`

```json
"dslHelp": {
    "title": "DSL-Syntax-Referenz",
    "intro": "Die Matching-Strategie ist eine Mengenalgebra über Identifikator-Dimensionen: Sie definiert, welches Shopware-Feld gegen welchen Topdata-Identifikator gematcht wird. Der DSL-String ist die einzige Quelle der Wahrheit — starten Sie mit einem Preset oder geben Sie den DSL-String direkt ein.",
    "grammar": {
        "title": "Grammatik",
        "leaf": "Jede Bedingung (Leaf) paart ein Shopware-Feld mit einem Topdata-Identifikator:",
        "and": "UND — alle Operanden in einer Gruppe müssen matchen",
        "or": "ODER — mindestens eine Gruppe muss matchen",
        "parens": "( ) — Teilausdruck gruppieren, um die Präzedenz zu überschreiben (& bindet stärker als |)"
    },
    "examples": {
        "title": "Beispiele",
        "one": "Match per EAN, Herstellernummer oder Produktnummer.",
        "two": "UND: Match per EAN und Herstellermarke.",
        "three": "Produktnummer gegen die Artikelnummern eines Providers.",
        "four": "Klammern: Match per EAN oder Artikelnummer, eingeschränkt auf eine Herstellermarke."
    },
    ...
}
```

**Verification Phase 5:** both JSON files parse (`jq empty` or `python -m json.tool`); grep for `builder`/`shopField` keys returns nothing.

### Phase 6 — Documentation updates

**6.1 [MODIFY] `AGENTS.md`** — two spots:

- Admin line: `strategy editor: preset chips, visual builder, debounced live validation`
  → `strategy editor: preset chips, DSL textarea with debounced live validation and a syntax help modal`
- DSL line: `The DSL parser lives once in PHP (src/Service/Dsl/), the
  serializer once in JS — no drift.`
  → `The DSL parser and serializer both live once in PHP (src/Service/Dsl/) — the settings page only sends/validates DSL strings, so there is no JS mirror to drift. The grammar supports ( ) groups for explicit precedence (& binds tighter than |).`

**6.2 [MODIFY] `README.md`** — matching strategy section:

```markdown
The **settings page** (Settings → Plugins → Topdata Mapper) is the preferred
editor: preset chips and the DSL string with live validation (a syntax help
modal documents the grammar). The DSL supports `( )` groups to override the
default precedence (`&` binds tighter than `|`); `(` and `)` are reserved
characters and cannot appear in property/custom-field names. The import fails
loudly on an invalid stored strategy.
```

**6.3 [MODIFY] `CHANGELOG.md`** — under `[Unreleased]` add:

```markdown
### Removed
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
```

**6.4 [MODIFY]** `_ai/lessons_learned/2026-08-14-vue3-underscore-methods.md` —
append one line to the Takeaways or leave untouched (historical record).
Recommended: leave as-is; the new report documents the removal.

### Phase 7 — Housekeeping, verification and report

1. **Admin build + deploy** (dev shop workflow, see AGENTS.md):
   `bin/build-administration.sh` → `bin/console assets:install` →
   `bin/console cache:clear` (or `docker exec sw67-www rm -rf /www/var/cache/*`).
   Confirm the rebuild via the bundle content hash and `grep -c` the compiled
   bundle for removed identifiers (`addLeaf`, `builderFromAst`, `orSeparator`)
   → 0.
2. **Smoke test the settings page** (admin, Settings → Plugins → Topdata
   Mapper): page loads without console errors; preset chips apply; DSL textarea
   edits trigger debounced validation; the help modal renders the pairing list
   from `allowedPairs` and shows the new `( )` grammar row + paren example;
   save works; dirty-confirm modal still shows when a preset is clicked after
   an unsaved edit.
3. **Grammar smoke tests** (textarea): `(product.ean:ean | product.product_number:articleNumbers) & product.manufacturer:topdataBrandIds` validates and saves with parens intact; `(product.ean:ean` shows the unclosed-group error with position; `product.ean:ean) & product.manufacturer_number:mpn` shows the unexpected-`)` error.
4. **Regression:** `bin/console topdata:mapper:import` still runs (default
   flat strategy parses unchanged; a paren strategy imports and the
   `topdataBrandIds` build-order check still fires inside paren groups);
   conflict and mappings modules unaffected.
5. **Syntax checks:** `php -l` on the modified PHP files; JSON lint on the
   snippet files.
6. **`.gitignore` / `README.md`**: no new file types or build artifacts are
   introduced → no `.gitignore` change needed (README already handled in
   Phase 6).
7. **Write the implementation report** to
   `_ai/backlog/active` → move this plan to `_ai/backlog/archive/` and create:

[NEW FILE] `_ai/backlog/reports/260816_HHmm__IMPLEMENTATION_REPORT__remove-visual-dsl-builder.md`

```yaml
---
filename: "_ai/backlog/reports/260816_HHmm__IMPLEMENTATION_REPORT__remove-visual-dsl-builder.md"
title: "Report: Remove the visual DSL builder from the settings page — DSL string + preset chips are the only editor; DSL grammar gains parentheses for explicit precedence"
createdAt: 2026-08-16 HH:mm
updatedAt: 2026-08-16 HH:mm
planFile: "_ai/backlog/active/260816_1230__IMPLEMENTATION_PLAN__remove-visual-dsl-builder.md"
project: "topdata-mapper-sw6"
status: completed
completedAt: 2026-08-18 10:12
filesCreated: 1
filesModified: 15
filesDeleted: 0
tags: [admin, dsl, settings, refactor, ui, grammar]
documentType: IMPLEMENTATION_REPORT
---
```

Report sections: Summary, Files Changed, Key Changes, Deviations from Plan,
Technical Decisions, Testing Notes (the Phase 7 verification steps), Usage
Examples (none — admin UI only; mention the import command as regression
check, and a parens strategy example), Documentation Updates, Next Steps
(optional).

## 6. Files touched (summary)

| File | Change |
|---|---|
| `src/Controller/Api/TopdataMapperActionController.php` | MODIFY — drop `providers` from init, drop `ast` from validate, docblocks, recursive `_assertProvidersExist` |
| `src/Service/Dsl/DslSerializer.php` | MODIFY — remove `toArray()`, recursive `toString()` (parens preserved), docblock |
| `src/Service/Dsl/DslParser.php` | MODIFY — recursive-descent parser with `( )` groups, docblock |
| `src/Service/Dsl/DslAndExpr.php` | MODIFY — `leaves` → `items` (nested `DslOrExpr` operands) |
| `src/Service/Dsl/DslOrExpr.php` | MODIFY — docblock only (also the paren-group node type) |
| `src/Service/ProductMappingMatcher_Dsl.php` | MODIFY — recursive `_evaluateExpr`/`_evaluateAnd`, `_union`, recursive `referencesTopdataBrandIds` |
| `src/Service/DslStrategyService.php` | MODIFY — comment only (presets unchanged) |
| `.../settings/page/topdata-mapper-settings/index.js` | MODIFY — remove builder model/methods/computed |
| `.../settings/page/topdata-mapper-settings/topdata-mapper-settings.html.twig` | MODIFY — remove builder block, add grammar + examples help-modal rows |
| `.../settings/page/topdata-mapper-settings/topdata-mapper-settings.scss` | MODIFY — remove builder rules |
| `.../settings/snippet/en-GB.json`, `de-DE.json` | MODIFY — remove builder/shopField keys, `dslHelp` intro + `grammar.parens` + `examples.four` |
| `AGENTS.md`, `README.md`, `CHANGELOG.md` | MODIFY — docs (builder removal + parens grammar) |
| `_ai/backlog/reports/260816_HHmm__IMPLEMENTATION_REPORT__remove-visual-dsl-builder.md` | NEW — report |
