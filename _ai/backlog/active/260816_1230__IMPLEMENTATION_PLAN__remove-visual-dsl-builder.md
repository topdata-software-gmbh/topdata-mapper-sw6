---
filename: "_ai/backlog/active/260816_1230__IMPLEMENTATION_PLAN__remove-visual-dsl-builder.md"
title: "Remove the visual DSL builder from the settings page — DSL string + preset chips are the only editor"
createdAt: 2026-08-16 12:30
updatedAt: 2026-08-16 12:30
status: draft
priority: low
tags: [admin, dsl, settings, refactor, ui]
estimatedComplexity: simple
documentRevision: 1
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
little value: the grammar is intentionally flat (no braces/parens, `&` binds
tighter than `|`), so the DSL string is trivially readable.

**Decision:** remove the visual builder. The DSL textarea (with debounced live
validation), the preset chips, the dirty-confirm modal and the syntax help
modal remain — they are the entire editor. This aligns the shipped product with
the already-decided flat grammar (no braces will ever be added).

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

No DSL grammar changes. No migration. No routing/privilege changes. The import
backstop and the conflict/mappings modules are untouched.

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
save). Update the class docblock (no more JS-side serializer mirror).

[MODIFY] `src/Service/Dsl/DslSerializer.php`

```php
/**
 * Serializes a DSL AST back to the canonical DSL string (stored on save).
 * The frontend only sends/validates DSL strings — there is no JS serializer.
 *
 * 08/2026 created, 08/2026 toArray() removed (visual builder removed)
 */
class DslSerializer
{
    public function toString(DslOrExpr $ast): string
    {
        $groups = [];
        foreach ($ast->groups as $group) {
            $leaves = [];
            foreach ($group->leaves as $leaf) {
                $dimensionRef = $leaf->dimensionVariant !== null
                    ? $leaf->dimension . '.' . $leaf->dimensionVariant
                    : $leaf->dimension;
                $leaves[] = $leaf->shopField . ':' . $dimensionRef;
            }
            $groups[] = implode(' & ', $leaves);
        }

        return implode(' | ', $groups);
    }
}
```

(Remove the `toArray()` method and its docblock entirely.)

**1.5 `DslParser` class docblock** — it claims the settings page "re-renders
its visual builder from the AST". Correct it.

[MODIFY] `src/Service/Dsl/DslParser.php`

```php
/**
 * Recursive-descent parser for the matching DSL (the single authoritative
 * parser — the settings page validates its DSL textarea against it, and the
 * import fails loudly on invalid stored strategies).
 *
 * Grammar:
 * ```
 * strategy := orExpr
 * orExpr   := andExpr ('|' andExpr)*     // | = union of matched product sets
 * andExpr  := leaf ('&' leaf)*           // & = intersection
 * leaf     := shopField ':' dimensionRef
 * ```
 * ...
```

**Verification Phase 1:** `php -l` on all three files; `grep -rn "toArray" src/Service/Dsl/` shows only `DslParseException::toArray()` and `DslPairingMatrix::toArray()` remaining.

### Phase 2 — Frontend: strip the builder from the settings component (JS)

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

**Verification Phase 2:** `grep -n "groups\|providers\|dimensionOptions\|shopFieldKinds" index.js` returns nothing.

### Phase 3 — Template + SCSS cleanup

**Files:** `.../topdata-mapper-settings/topdata-mapper-settings.html.twig`,
`.../topdata-mapper-settings/topdata-mapper-settings.scss`

**3.1** Delete the whole `{% block topdata_mapper_settings_builder %}` section
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

**3.2** Remove the builder-only SCSS rules: `__group`, `__leaf`, `__remove`,
`__add-leaf`/`__add-group`, `__or-separator`. Keep `__loader`, `__presets`,
`__dsl`, `__dsl-actions`, `__dsl-help*`, `__credentials-text`.

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

### Phase 4 — Snippet cleanup (en-GB + de-DE)

**Files:** `.../topdata-mapper-settings/snippet/en-GB.json`,
`.../topdata-mapper-settings/snippet/de-DE.json`

- **Remove** the whole `settings.builder` key (shopField/dimension/provider/
  allProviders/propertyGroup/customField/addLeaf/addGroup/orSeparator).
- **Remove** `settings.shopField.*` (builder-only; `shopFieldLabel` is gone).
  Keep `settings.dimension.*` (help modal uses `dimensionLabel`).
- **Update** `dslHelp.intro` — no longer references the visual builder.

[MODIFY] `.../snippet/en-GB.json`

```json
"dslHelp": {
    "title": "DSL syntax reference",
    "intro": "The matching strategy is a set algebra over identifier dimensions: it defines which Shopware field is matched against which Topdata identifier. The DSL string is the single source of truth — start from a preset chip or type the DSL directly.",
    ...
}
```

[MODIFY] `.../snippet/de-DE.json`

```json
"dslHelp": {
    "title": "DSL-Syntax-Referenz",
    "intro": "Die Matching-Strategie ist eine Mengenalgebra über Identifikator-Dimensionen: Sie definiert, welches Shopware-Feld gegen welchen Topdata-Identifikator gematcht wird. Der DSL-String ist die einzige Quelle der Wahrheit — starten Sie mit einem Preset oder geben Sie den DSL-String direkt ein.",
    ...
}
```

**Verification Phase 4:** both JSON files parse (`jq empty` or `python -m json.tool`); grep for `builder`/`shopField` keys returns nothing.

### Phase 5 — Documentation updates

**5.1 [MODIFY] `AGENTS.md`** — two spots:

- Admin line: `strategy editor: preset chips, visual builder, debounced live validation`
  → `strategy editor: preset chips, DSL textarea with debounced live validation and a syntax help modal`
- DSL line: `The DSL parser lives once in PHP (src/Service/Dsl/), the
  serializer once in JS — no drift.`
  → `The DSL parser and serializer both live once in PHP (src/Service/Dsl/) — the settings page only sends/validates DSL strings, so there is no JS mirror to drift.`

**5.2 [MODIFY] `README.md`** — matching strategy section:

```markdown
The **settings page** (Settings → Plugins → Topdata Mapper) is the preferred
editor: preset chips and the DSL string with live validation (a syntax help
modal documents the grammar). The import fails loudly on an invalid stored
strategy.
```

**5.3 [MODIFY] `CHANGELOG.md`** — under `[Unreleased]` add:

```markdown
### Removed
- **Visual DSL builder** from the settings strategy editor — the DSL string
  (with debounced live validation) and the preset chips are now the only
  editor. `GET /api/_action/topdata-mapper/strategy` no longer returns
  `providers`; `POST .../validate-strategy` no longer returns `ast`;
  `DslSerializer::toArray()` removed.
```

**5.4 [MODIFY]** `_ai/lessons_learned/2026-08-14-vue3-underscore-methods.md` —
append one line to the Takeaways or leave untouched (historical record).
Recommended: leave as-is; the new report documents the removal.

### Phase 6 — Housekeeping, verification and report

1. **Admin build + deploy** (dev shop workflow, see AGENTS.md):
   `bin/build-administration.sh` → `bin/console assets:install` →
   `bin/console cache:clear` (or `docker exec sw67-www rm -rf /www/var/cache/*`).
   Confirm the rebuild via the bundle content hash and `grep -c` the compiled
   bundle for removed identifiers (`addLeaf`, `builderFromAst`, `orSeparator`)
   → 0.
2. **Smoke test the settings page** (admin, Settings → Plugins → Topdata
   Mapper): page loads without console errors; preset chips apply; DSL textarea
   edits trigger debounced validation; the help modal renders the pairing list
   from `allowedPairs`; save works; dirty-confirm modal still shows when a
   preset is clicked after an unsaved edit.
3. **Regression:** `bin/console topdata:mapper:import` still runs (strategy
   validation path unchanged); conflict and mappings modules unaffected.
4. **Syntax checks:** `php -l` on the modified PHP files; JSON lint on the
   snippet files.
5. **`.gitignore` / `README.md`**: no new file types or build artifacts are
   introduced → no `.gitignore` change needed (README already handled in
   Phase 5).
6. **Write the implementation report** to
   `_ai/backlog/active` → move this plan to `_ai/backlog/archive/` and create:

[NEW FILE] `_ai/backlog/reports/260816_HHmm__IMPLEMENTATION_REPORT__remove-visual-dsl-builder.md`

```yaml
---
filename: "_ai/backlog/reports/260816_HHmm__IMPLEMENTATION_REPORT__remove-visual-dsl-builder.md"
title: "Report: Remove the visual DSL builder from the settings page — DSL string + preset chips are the only editor"
createdAt: 2026-08-16 HH:mm
updatedAt: 2026-08-16 HH:mm
planFile: "_ai/backlog/active/260816_1230__IMPLEMENTATION_PLAN__remove-visual-dsl-builder.md"
project: "topdata-mapper-sw6"
status: completed
filesCreated: 1
filesModified: 8
filesDeleted: 0
tags: [admin, dsl, settings, refactor, ui]
documentType: IMPLEMENTATION_REPORT
---
```

Report sections: Summary, Files Changed, Key Changes, Deviations from Plan,
Technical Decisions, Testing Notes (the Phase 6 verification steps), Usage
Examples (none — admin UI only; mention the import command as regression
check), Documentation Updates, Next Steps (optional).

## 6. Files touched (summary)

| File | Change |
|---|---|
| `src/Controller/Api/TopdataMapperActionController.php` | MODIFY — drop `providers` from init, drop `ast` from validate, docblocks |
| `src/Service/Dsl/DslSerializer.php` | MODIFY — remove `toArray()`, docblock |
| `src/Service/Dsl/DslParser.php` | MODIFY — docblock only |
| `.../settings/page/topdata-mapper-settings/index.js` | MODIFY — remove builder model/methods/computed |
| `.../settings/page/topdata-mapper-settings/topdata-mapper-settings.html.twig` | MODIFY — remove builder block |
| `.../settings/page/topdata-mapper-settings/topdata-mapper-settings.scss` | MODIFY — remove builder rules |
| `.../settings/snippet/en-GB.json`, `de-DE.json` | MODIFY — remove builder/shopField keys, intro text |
| `AGENTS.md`, `README.md`, `CHANGELOG.md` | MODIFY — docs |
| `_ai/backlog/reports/260816_HHmm__IMPLEMENTATION_REPORT__remove-visual-dsl-builder.md` | NEW — report |
