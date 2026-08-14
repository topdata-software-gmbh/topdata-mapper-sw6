# [2026-08-14] - Vue 3 template breaks on `_`-prefixed methods (DSL builder fix)

## Context

The admin settings module (`topdata.mapper.settings`, strategy editor) crashed on render with
`ReferenceError: _dimensionOptions is not defined` in the compiled Vue render function. The
graphical DSL builder (preset chips, visual builder, debounced live validation) was being fixed
after a discussion about whether it was worth keeping at all.

## Challenge

The error trace pointed into `compileToFunction` output — a compiled render function referencing
`_dimensionOptions` as a free (module-level) variable, even though the method existed on the
component. Both `_dimensionOptions(kind)` and `_leafComplete(leaf)` were defined as regular methods
and used from the template.

## Discovery/Solution

Root cause: **Vue 3's template compiler treats identifiers starting with `_` as globally-available
helpers** (`isGloballyWhitelisted` in `@vue/shared` returns true for any key starting with `_`).
It therefore does NOT emit `_ctx._dimensionOptions` — the compiled function references the bare
name, which is undefined at render time → `ReferenceError`.

The PHP convention "private methods prefixed with `_`" (from `CONVENTIONS-PHP.md`) does **not**
apply to Vue components. Fix: renamed to `dimensionOptions` / `leafComplete` in
`index.js` (definition + internal `this.` calls) and `topdata-mapper-settings.html.twig:101`
(template usage).

Deployment (dev shop, SW 6.7, no `node_modules` in project root):
1. `bin/build-administration.sh` (copies bundle files — this is the admin build entry, NOT npm)
2. `bin/console assets:install`
3. `bin/console cache:clear`
4. Verify by grepping the compiled bundle:
   `grep -c 'dimensionOptions' /www/public/bundles/topdatamappersw6/administration/assets/*.js` → 1,
   `grep -c '_dimensionOptions' ...` → 0 (new content hash `e6me0mib` proves rebuild).

## Key Takeaways

- **Never name Vue (template-visible) methods/fields with a leading underscore** — Vue 3 compiles
  `_foo` as a global, not `_ctx._foo`. The PHP `_`-prefix convention is PHP-only; in JS admin
  components use normal names (or a non-underscore prefix).
- If a "method exists but render says undefined" error appears in `compileToFunction`, check for
  `_`-prefixed identifiers used in the template first.
- SW 6.7 dev-shop admin build: no npm needed — run `bin/build-administration.sh`, then
  `assets:install` + `cache:clear`; confirm the rebuild via the content hash in the bundle filename
  and by grepping the new bundle for old/new identifiers.
- The DSL grammar is intentionally flat: no braces/parens, `&` binds tighter than `|`
  (groups = OR-joined `&`-chains), so precedence is unambiguous. The visual builder enforces this
  shape by construction — nested logic would require extending the PHP DSL parser
  (`src/Service/Dsl/`), not just the UI.