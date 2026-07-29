# Readable Web Panel Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Align the `master/` Web frontend with the approved readable-workbench direction and remove the encoding, raw-status, and locale-patching failures that make the UI difficult to understand.

**Architecture:** Keep the existing PHP server-rendered routes and `PrecisionGridUi` class vocabulary. Improve the shared v2 stylesheet and shared rendering helpers first, then harden the DOM locale patcher and curate high-impact locale entries. Add source/behavior guards before implementation so the presentation contract is independently testable.

**Tech Stack:** PHP 8.3, hand-authored CSS, vanilla browser JavaScript, JSON locale dictionaries, existing one-file PHP regression tests, local PHP built-in server for smoke checks.

---

## File Map

- Modify `master/public/assets/leikwan-v2.css`: shared visual tokens, typography, sheet/table rows, navigation, controls, and responsive structured rows.
- Modify `master/src/Controllers/BaseController.php`: human status labels/explanations, offline severity mapping, dead mojibake removal, and shared rendering helpers.
- Modify `master/src/Support/PrecisionGridUi.php`: accessible status output with optional explanation and stable row-menu/control markup.
- Modify `master/src/Controllers/AdminController.php` and `master/src/Controllers/TenantController.php`: replace raw status presentation and endpoint health badges with shared human-readable output.
- Modify `master/public/i18n/patch.js`: stable source-text tracking, safe attribute patching, locale language updates, and pattern-based dynamic text replacement.
- Modify `master/public/i18n/zh-Hans.json` and `master/public/i18n/zh-TW.json`: high-impact operational glossary, pattern translations, mojibake-key cleanup, and zero-width-space cleanup.
- Modify `master/public/index.php` and `master/public/install.php`: load the existing v2 stylesheet on bootstrap/install failure and installer pages.
- Create `master/tests/readable_web_panel_repair_test.php`: focused regression guard for the new readability and locale contracts.

### Task 1: Add the failing readability contract test

**Files:**
- Create: `master/tests/readable_web_panel_repair_test.php`

- [ ] **Step 1: Write the failing test.** The test must parse both JSON files and assert all of the following exact contracts:

```php
$css = file_get_contents($root . '/public/assets/leikwan-v2.css');
$patch = file_get_contents($root . '/public/i18n/patch.js');
$base = file_get_contents($root . '/src/Controllers/BaseController.php');
$index = file_get_contents($root . '/public/index.php');
$install = file_get_contents($root . '/public/install.php');

assert_contains($css, '--body-size: 14px');
assert_contains($css, '--row-h: 54px');
assert_not_contains($css, 'font-size: 7.5px');
assert_not_contains($css, 'letter-spacing: -');
assert_contains($base, "'offline' => 'error'");
assert_not_contains($base, '鏈湴姹囪仛');
assert_contains($index, '/assets/leikwan-v2.css');
assert_contains($install, '/assets/leikwan-v2.css');
assert_not_contains($index, 'leikwan-ui.css');
assert_not_contains($install, 'operator-console.css');
assert_contains($patch, 'document.documentElement.lang');
assert_contains($patch, 'preserve');

foreach (['zh-Hans.json', 'zh-TW.json'] as $localeFile) {
    $dictionary = json_decode(file_get_contents($root . '/public/i18n/' . $localeFile), true, 512, JSON_THROW_ON_ERROR);
    assert_no_zero_width_spaces($dictionary, $localeFile);
    assert_no_mojibake_keys($dictionary, $localeFile);
}
```

Use local `assert_contains`, `assert_not_contains`, and recursive dictionary checks so a failure identifies the file and contract. Do not assert byte hashes or unrelated existing translations.

- [ ] **Step 2: Run the new test to prove the baseline fails.**

Run: `php master/tests/readable_web_panel_repair_test.php`

Expected: FAIL on the current 7.5px/34px v2 tokens or the deleted legacy stylesheet references.

### Task 2: Implement the readable-workbench visual system

**Files:**
- Modify: `master/public/assets/leikwan-v2.css`

- [ ] **Step 1: Replace the compressed typography and geometry tokens.** Define `--body-size: 14px`, `--meta-size: 12px`, `--micro-size: 11px`, `--row-h: 54px`, and warm-neutral page/sheet tokens. Keep the existing font files and CJK fallback stack, but remove negative letter spacing and the 75% CJK heading scale.

- [ ] **Step 2: Update shared shell rules.** Keep `.sidebar` dark, set navigation links to 13-14px with readable group labels, and make `.pg-console`/`.content-card` the white working surfaces against the neutral page background. Retain existing route-generated class names and the 900px mobile navigation behavior.

- [ ] **Step 3: Update sheets and controls.** Set primary table rows to `var(--row-h)`, headers to at least 11px, data to 14px, supporting values to 12px, inputs/buttons to a stable 38-40px height, and row menus to a 34px target. Preserve horizontal scrolling only inside `.table-wrap`/`.table-scroll`.

- [ ] **Step 4: Update responsive list rules.** At the existing mobile breakpoint, hide desktop table rows for elements that already emit `.pg-mobile-resource-row` and show their labelled `dt/dd` structure. Ensure the content container has `min-width: 0`, long commands wrap inside bounded code regions, and `overflow-x` does not apply to `body`.

- [ ] **Step 5: Run the focused CSS contract test.**

Run: `php master/tests/readable_web_panel_repair_test.php`

Expected: CSS and failure-page assertions pass; status and locale assertions still fail.

### Task 3: Make shared server status output human-readable

**Files:**
- Modify: `master/src/Controllers/BaseController.php`
- Modify: `master/src/Support/PrecisionGridUi.php`
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Controllers/TenantController.php`

- [ ] **Step 1: Add explicit status label/explanation helpers.** In `BaseController`, map known values to labels such as `Active`, `Pending`, `Degraded`, `Offline`, `Suspended`, `Disabled`, and `Unknown`; fall back from `snake_case` to title case instead of exposing raw enum spelling. Map `offline` to `error` in `resourceStatusState`.

- [ ] **Step 2: Extend `PrecisionGridUi::status`.** Add an optional `$description = ''` parameter and emit an escaped `title`/`aria-label` plus a `.pg-status-description` element when supplied. Keep the three encodings: text, shape, and state color. The default call signature must remain compatible with current callers.

```php
public static function status(string $label, string $state, string $description = ''): string
{
    // Existing shape mapping remains unchanged.
    $accessibleLabel = $description === '' ? $label : $label . ': ' . $description;
    return '<span class="pg-status" data-state="' . self::escape($state)
        . '" data-shape="' . self::escape($shape)
        . '" aria-label="' . self::escape($accessibleLabel) . '">'
        . '<i class="pg-status-dot" data-shape="' . self::escape($shape) . '" aria-hidden="true"></i>'
        . '<span class="pg-status-label">' . self::escape($label) . '</span>'
        . ($description !== '' ? '<small class="pg-status-description">' . self::escape($description) . '</small>' : '')
        . '</span>';
}
```

- [ ] **Step 3: Replace visible `ucfirst($status)` and raw endpoint health output.** Use the shared label/explanation helpers in Admin and Tenant list/mobile rows. In the Tenant forward endpoint summary, map `health_status` through the same state vocabulary and render the status label plus explanation rather than a raw token.

- [ ] **Step 4: Remove the unreachable mojibake client-mode branch.** Delete the second unreachable return and the disabled option containing the mojibake string from `BaseController`; keep the canonical `Single`, `Race`, `Bond`, and `MPTCP` options.

- [ ] **Step 5: Run status-focused regression tests.**

Run: `php master/tests/readable_web_panel_repair_test.php`

Expected: CSS, failure-page, status, and shared markup assertions pass; only locale dictionary/patcher assertions may remain.

### Task 4: Harden locale patching without clobbering forms

**Files:**
- Modify: `master/public/i18n/patch.js`

- [ ] **Step 1: Preserve source text per text node.** Store the first English text in a `WeakMap` and always look up the dictionary using that source, so the MutationObserver can reapply translations without treating translated text as a new key.

- [ ] **Step 2: Add safe dynamic pattern replacement.** Read optional `dictionary.patterns` entries shaped as `{ "source": "...", "replace": "..." }`, compile only anchored local regexes, and apply them to text-node source strings after exact matches. Use replacement captures for counts; never evaluate dictionary content as JavaScript.

- [ ] **Step 3: Protect user-entered form values.** Skip `value` patching for `input`, `textarea`, and `select` controls unless the element has an explicit `data-i18n-static="true"` marker. Continue patching placeholders, titles, and ARIA labels from the whitelist.

- [ ] **Step 4: Keep the locale and document language synchronized.** Validate the locale against `config.supported`, set `document.documentElement.lang` before the English early return, and update the switcher without registering duplicate listeners.

- [ ] **Step 5: Run the JavaScript/source guard.**

Run: `php master/tests/readable_web_panel_repair_test.php`

Expected: patcher assertions pass; locale content assertions remain until Task 5.

### Task 5: Repair high-impact locale content and dictionary hygiene

**Files:**
- Modify: `master/public/i18n/zh-Hans.json`
- Modify: `master/public/i18n/zh-TW.json`

- [ ] **Step 1: Remove dead malformed keys and zero-width spaces.** Parse both files before and after editing; delete keys containing mojibake punctuation or replacement garbage and strip U+200B from every key/value. Keep key parity between the two dictionaries.

- [ ] **Step 2: Curate the operational glossary in both locales.** Replace the meaning-destroying translations for `Port`, `Slave`, `Bond`, `Bond leg`, `Forward`, `Forward Chain`, `Endpoint`, `Session`, `Traffic`, `Included Traffic`, `Weight`, `Runtime`, `Ticket`, and install/update actions with consistent technical terms. Keep LeiKwan, LeiGod, MPTCP, WireGuard, SNI, Sidecar, Bond, and Forward stable.

- [ ] **Step 3: Add pattern entries for dynamic count/status text.** Add a `patterns` object to each dictionary for the count strings emitted by the homepage/node and service summaries, using `$1`, `$2` captures and locale-appropriate wording.

- [ ] **Step 4: Validate JSON and dictionary invariants.**

Run: `node -e "for (const f of ['zh-Hans.json','zh-TW.json']) { const d=JSON.parse(require('fs').readFileSync('master/public/i18n/'+f)); console.log(f, Object.keys(d.text||{}).length, Object.keys(d.attributes||{}).length); }"`

Expected: both files parse, retain the expected text/attribute sections, and have no zero-width or malformed keys.

### Task 6: Repair failure/install page asset references and add focused coverage

**Files:**
- Modify: `master/public/index.php`
- Modify: `master/public/install.php`
- Modify: `master/tests/readable_web_panel_repair_test.php`

- [ ] **Step 1: Point all standalone failure/install HTML at `/assets/leikwan-v2.css`.** Use one version query value shared with the current asset cutover. Do not reference deleted `leikwan-ui.css`, `operator-console.css`, `signal-chamber.css`, or `signal-chamber.js`.

- [ ] **Step 2: Add assertions for the exact asset set and fallback shell.** The focused test must fail if a deleted legacy asset name returns to either file or if the v2 stylesheet is absent from the error page.

- [ ] **Step 3: Run PHP syntax checks.**

Run: `php -l master/public/index.php; php -l master/public/install.php; php -l master/src/Controllers/BaseController.php; php -l master/src/Support/PrecisionGridUi.php; php -l master/src/Controllers/AdminController.php; php -l master/src/Controllers/TenantController.php`

Expected: six `No syntax errors detected` lines.

### Task 7: Run focused regression tests and browser verification

**Files:**
- Modify: none unless a test exposes a regression.

- [ ] **Step 1: Run the focused test and relevant existing skin tests.**

Run:

```powershell
php master/tests/readable_web_panel_repair_test.php
php master/tests/precision_grid_design_system_regression_test.php
php master/tests/precision_grid_responsive_accessibility_test.php
php master/tests/readable_sheet_design_system_regression_test.php
php master/tests/skin_v2_token_contract_test.php
php master/tests/skin_v2_status_encoding_test.php
php master/tests/skin_v2_status_severity_test.php
```

Expected: each command exits 0. Existing unrelated baseline failures are reported separately and are not silently changed.

- [ ] **Step 2: Start the PHP panel in a local fixture-safe mode.** Use the repository's existing `.env.example`/test bootstrap and PHP built-in server only if the required database/config is available; otherwise use the standalone error and installer pages as the deterministic browser targets.

- [ ] **Step 3: Verify target viewports.** Capture the public homepage, login, one Admin list, one Tenant list, and the installer/error shell at 1440px and 390px. Check computed body/table text sizes, visible focus, status explanations, mobile structured rows, no page-level horizontal overflow, and no console errors.

- [ ] **Step 4: Review the final diff.** Run `git diff --check`, confirm only `master/` Web files plus the focused test changed after the already committed design/plan documents, and leave existing unrelated dirty files untouched.

## Commits

Commit each coherent unit after its focused verification:

1. `test: add readable web panel contract`
2. `style: make web panel sheets readable`
3. `fix: explain web resource status states`
4. `fix: harden web locale patching`
5. `fix: repair web locale glossary and fallback assets`
