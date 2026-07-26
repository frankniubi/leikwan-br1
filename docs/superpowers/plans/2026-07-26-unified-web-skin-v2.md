# Unified Web Skin v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the three stacked design systems in the web frontend with one dark, dimensional, achromatic skin covering the public pages and the ~40-page operator console, behind a flag whose removal is enforced by tests.

**Architecture:** One new stylesheet (`leikwan-v2.css`, zero `!important`) plus v2 branches in the two `BaseController` shell functions and a few `PrecisionGridUi` methods. The 336 KB / 176 KB controllers are not restructured — the skin reaches them through the ~30-class vocabulary they already emit. A `LEIKWAN_SKIN` flag selects shells; four test-enforced forcing functions make the legacy skin's deletion mandatory.

**Tech Stack:** PHP 8.3, server-rendered heredoc HTML, hand-authored CSS, no build step, no framework. Tests are plain PHP scripts.

**Spec:** `docs/superpowers/specs/2026-07-26-unified-web-skin-v2-design.md`

---

## Facts you need before starting

**Test idiom.** Every file in `master/tests/` is a standalone PHP script. It fails by throwing `RuntimeException` (or calling a local `fail()` that writes STDERR and `exit(1)`), and succeeds by printing `ok`. There is no PHPUnit, no runner config, no assertion library.

Run one test:
```bash
php -d date.timezone=UTC master/tests/<name>_test.php
```

Run the whole suite:
```bash
cd /c/Users/frankniubi/Downloads/leikwan-br1
pass=0; fail=0; failed=""
for f in master/tests/*_test.php; do
  if php -d date.timezone=UTC "$f" >/dev/null 2>&1; then pass=$((pass+1)); else fail=$((fail+1)); failed="$failed $f"; fi
done
echo "PASS=$pass FAIL=$fail"; for f in $failed; do echo "  $f"; done
```

**Baseline as of branch `frontend-skin-v2` @ `2537f67`: `PASS=234 FAIL=7`.** Those 7 failures are pre-existing and unrelated to this work. Do not try to fix them:

| Failing test | Cause |
| --- | --- |
| `knowledgebase_service_test.php` | `PDOException: could not find driver` — no MySQL PDO locally |
| `provisioning_mptcp_preferred_bond_slave_payload_regression_test.php` | same |
| `provisioning_mptcp_sidecar_wireguard_port_regression_test.php` | same |
| `provisioning_self_leg_wg_contract_regression_test.php` | same |
| `provisioning_sidecar_republish_test.php` | same |
| `provisioning_slave_mptcp_desired_state_regression_test.php` | same |
| `public_port_recalculation_test.php` | calls `ProvisioningService::recalculatePublicPortsForGroup()`, which does not exist — stale test |

**Success for this plan is `PASS=<234 + new tests> FAIL=7`.** If `FAIL` goes above 7, you broke something.

**Two test styles exist; use the right one.**

Static source assertion (most files) — reads source text, never renders:
```php
<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
if (!str_contains($base, 'something')) {
    throw new RuntimeException('missing something');
}
echo "ok\n";
```

Behavioral (see `master/tests/readable_sheet_design_system_regression_test.php`) — loads the class and calls it:
```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Support/PrecisionGridUi.php';
use Leikwan\Master\Support\PrecisionGridUi;
$html = PrecisionGridUi::status('Healthy', 'ok');
if (!str_contains($html, 'pg-status-dot')) {
    throw new RuntimeException('status must emit a dot');
}
echo "ok\n";
```

**Prefer behavioral.** The spec's central finding is that string-locking tests let three design layers accumulate while the suite stayed green.

**Config reads.** `master/src/Config.php` — `$this->config->get('KEY', 'default')` checks `getenv()` first, then the `.env` file, then the default. `BaseController` already holds `$this->config` (see `brandLogoUrl()` at L1067).

**Do not call `PrecisionGridUi` methods with global skin state.** It is a `final class` of pure statics with no config access. Where a helper needs the skin, pass it as an explicit argument.

**Conflict you must resolve, not work around.** `master/tests/precision_grid_responsive_accessibility_test.php:54-56` currently fails the suite on *any* negative letter-spacing:
```php
if (preg_match('/letter-spacing\s*:\s*-/i', $css) === 1) {
    fail('negative letter spacing is forbidden');
}
```
The v2 display headings require `-0.02em` to `-0.035em`. Task 15 rescopes this ban to body and table text, where it is a real legibility contract, and keeps it banned there. Until Task 15, `leikwan-v2.css` will fail this test — that is expected and is called out in the affected tasks.

---

## File structure

**Created:**

| Path | Responsibility |
| --- | --- |
| `master/public/assets/leikwan-v2.css` | The entire v2 skin: token layer, then component layer. Zero `!important`. |
| `master/public/assets/fonts/*.woff2` | Self-hosted Archivo + Spline Sans Mono. |
| `master/src/Support/UptimeTrace.php` | Pure functions: heartbeat timestamps → 12 buckets → trace HTML. No DB, no I/O. |
| `master/tests/fixtures/skin-v2-coverage.txt` | Route manifest driving the cutover, plus the runtime-class allowlist. |
| `master/tests/skin_flag_contract_test.php` | Flag resolution behaviour. |
| `master/tests/skin_v2_class_vocabulary_test.php` | Every class the controllers emit has a v2 rule. |
| `master/tests/skin_v2_legacy_freeze_test.php` | Legacy stylesheets are byte-frozen. |
| `master/tests/skin_v2_coverage_manifest_test.php` | Manifest ↔ default-skin linkage, and route classification. |
| `master/tests/skin_v2_status_encoding_test.php` | Status cells are triple-encoded. |
| `master/tests/skin_v2_uptime_trace_test.php` | Bucketing and trace accessibility. |

**Modified:**

| Path | Change |
| --- | --- |
| `master/src/Controllers/BaseController.php` | `activeSkin()`; v2 branches in `publicPage()` (~L1196) and `page()` (~L1244); `brandLogoUrl()` default (L1067). |
| `master/src/Support/PrecisionGridUi.php` | `status()` triple encoding; new `traceHeader()` / `traceCell()`. |
| `master/src/Controllers/AdminController.php` | Trace helper calls at the 5 `viewTabs()` views: L1351, L2293, L2911, L3311, L3546. |
| `master/src/Controllers/TenantController.php` | Trace helper calls at the 3 `viewTabs()` views: L1119, L1328, L1696. |
| 14 legacy design tests | Rewritten to contract level (Task 15). |

**Not touched:** the HTML string concatenation in the controllers, `ProvisioningService`, any agent or data-plane code.

---

### Task 1: Skin flag resolution

The flag must exist before anything can branch on it. It is read in exactly two shell functions; controllers never branch on it directly.

**Files:**
- Modify: `master/src/Controllers/BaseController.php` (add method near `brandLogoUrl()`, L1067)
- Test: `master/tests/skin_flag_contract_test.php`

- [ ] **Step 1: Write the failing test**

Create `master/tests/skin_flag_contract_test.php`:

```php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');

// The flag is resolved in one place.
if (substr_count($base, "config->get('LEIKWAN_SKIN'") !== 1) {
    throw new RuntimeException('LEIKWAN_SKIN must be read in exactly one method');
}
if (!str_contains($base, 'protected function activeSkin(?array $viewer = null): string')) {
    throw new RuntimeException('activeSkin() accessor is missing');
}

// Default is legacy: nothing changes for users until the cutover.
if (!str_contains($base, "config->get('LEIKWAN_SKIN', 'legacy')")) {
    throw new RuntimeException('LEIKWAN_SKIN must default to legacy');
}

// Unknown values must not silently enable v2.
if (!str_contains($base, "=== 'v2' ? 'v2' : 'legacy'")) {
    throw new RuntimeException('skin resolution must whitelist v2 rather than blacklist legacy');
}

// The ?skin= override is admin-only and session-persisted.
foreach (["\$_GET['skin']", "'admin'", 'skin_override'] as $needle) {
    if (!str_contains($base, $needle)) {
        throw new RuntimeException('admin skin override contract missing: ' . $needle);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
php -d date.timezone=UTC master/tests/skin_flag_contract_test.php
```
Expected: `PHP Fatal error: Uncaught RuntimeException: LEIKWAN_SKIN must be read in exactly one method`

- [ ] **Step 3: Implement `activeSkin()`**

In `master/src/Controllers/BaseController.php`, immediately after the `brandLogoUrl()` method (ends L1069), insert:

```php
    /**
     * Resolve the active skin.
     *
     * Read in exactly two places -- publicPage() and page(). Controllers never
     * branch on the skin; helpers that need it take it as an argument.
     *
     * Administrators may preview the new skin with ?skin=v2, which persists for
     * the session. The parameter is ignored for everyone else, so the flag stays
     * the only thing that exposes v2 to users.
     *
     * @param array<string, mixed>|null $viewer
     */
    protected function activeSkin(?array $viewer = null): string
    {
        $configured = (string) ($this->config->get('LEIKWAN_SKIN', 'legacy') ?? 'legacy');
        $skin = strtolower(trim($configured)) === 'v2' ? 'v2' : 'legacy';

        $isAdmin = $viewer !== null && (string) ($viewer['role'] ?? '') === 'admin';
        if (!$isAdmin) {
            return $skin;
        }

        $requested = strtolower(trim((string) ($_GET['skin'] ?? '')));
        if ($requested === 'v2' || $requested === 'legacy') {
            $_SESSION['skin_override'] = $requested;
            return $requested;
        }

        $stored = strtolower(trim((string) ($_SESSION['skin_override'] ?? '')));
        return $stored === 'v2' ? 'v2' : ($stored === 'legacy' ? 'legacy' : $skin);
    }
```

- [ ] **Step 4: Run it and confirm it passes**

```bash
php -d date.timezone=UTC master/tests/skin_flag_contract_test.php
```
Expected: `ok`

- [ ] **Step 5: Confirm nothing else broke**

Run the full suite (command in "Facts you need before starting").
Expected: `PASS=235 FAIL=7`

- [ ] **Step 6: Commit**

```bash
git add master/src/Controllers/BaseController.php master/tests/skin_flag_contract_test.php
git commit -m "feat: add LEIKWAN_SKIN flag resolution with admin preview override"
```

---

### Task 2: Class vocabulary inventory

This runs **before** any CSS is written, because it is what defines "the stylesheet is complete". Writing it afterwards would make it a rubber stamp.

> **Amended during execution — the extractor below is superseded. `master/tests/skin_v2_class_vocabulary_test.php` in the repo is the source of truth.**
>
> The version below deletes `$vars` from a class attribute and harvests whatever text survives. That works for heredoc interpolation (`class="badge {$tone}"`) and produces garbage for concatenation (`class="nav-link' . ($active ? ' active' : '') . '"`), whose residue tokenises into `===`, `implode(`, `((string)` and thirteen other fragments of PHP. Seventeen of the 175 "classes" it reported were unsatisfiable by any stylesheet, so **the test could never have gone green** — the only exits were editing the test or hiding the tokens in a CSS comment, since the coverage check is a raw text match. A test that must be defeated to finish the work is worse than no test.
>
> Four changes, all in the repo file:
>
> 1. **Stop at the first PHP boundary** (`strcspn($attr, '$\'{')`) instead of reading past it, and reject any surviving token that is not identifier-shaped — so a misparse fails loudly rather than becoming a fake requirement.
> 2. **Two fixture directives replace `allow-class`.** `allow-dynamic <prefix>` attributes a runtime-composed attribute, matched with `str_starts_with` (anchored, unlike the original `str_contains`, which a short entry could have used to attribute everything and silently disable the check) and required to be ≥3 characters. `require-class <name>` declares a class that only ever exists at runtime — `active`, `has-usage`, `is-today` and friends — which the narrower extractor cannot see but which still must be styled.
> 3. **`(?![\w-])` replaces `\b`** in the coverage regex. `-` is a non-word character, so `/\.card\b/` was satisfied by a rule for `.card-kicker` while `.card` itself went unstyled — 19 of the emitted classes could have been marked covered without a rule of their own.
> 4. **Four more sources**, all of which render real pages: `HealthController.php`, `UptimeTrace.php`, `public/index.php`, `public/install.php`. Between them they emit `callout`, `pg-form-section`, `pg-trace` and `pg-trace-block`, none of which the original five-file source set could see.
>
> The plan's guessed allowlist (`nav-link`, `flash`, `pg-view-tab`, `pg-status`, `button`) was about 40% right and is not worth copying: `pg-status` has no dynamic composition at all, `button` matches nothing (that attribute is `' . $this->escape($class) . '`, which does not contain the string "button"), and the largest real entry — `badge`, carrying 10 of 17 fragments — was missed entirely. Populate from real output.

**Files:**
- Create: `master/tests/skin_v2_class_vocabulary_test.php`
- Create: `master/tests/fixtures/skin-v2-coverage.txt` (allowlist section only; the route section arrives in Task 13)

- [ ] **Step 1: Write the extractor test**

Create `master/tests/skin_v2_class_vocabulary_test.php`:

```php
<?php
declare(strict_types=1);

/**
 * Every CSS class the PHP emits must have a rule in the v2 stylesheet.
 *
 * This is a floor, not a proof: classes composed at runtime (class="badge {$x}")
 * cannot be extracted statically, so they live in an explicit allowlist below and
 * the extractor fails on any dynamic composition it cannot attribute to one.
 */

$root = dirname(__DIR__);
$fixture = $root . '/tests/fixtures/skin-v2-coverage.txt';
$cssPath = $root . '/public/assets/leikwan-v2.css';

if (!is_file($cssPath)) {
    throw new RuntimeException('leikwan-v2.css does not exist yet');
}
$css = (string) file_get_contents($cssPath);

$sources = [
    '/src/Controllers/BaseController.php',
    '/src/Controllers/AdminController.php',
    '/src/Controllers/TenantController.php',
    '/src/Controllers/AuthController.php',
    '/src/Support/PrecisionGridUi.php',
];

// Runtime-composed fragments, hand-maintained.
$allowlist = [];
foreach (file($fixture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (str_starts_with($line, 'allow-class ')) {
        $allowlist[] = trim(substr($line, strlen('allow-class ')));
    }
}

$classes = [];
$dynamic = [];
foreach ($sources as $rel) {
    $src = (string) file_get_contents($root . $rel);
    preg_match_all('/class="([^"]*)"/', $src, $m);
    foreach ($m[1] as $attr) {
        if (str_contains($attr, '$') || str_contains($attr, '{')) {
            $dynamic[] = trim($attr);
            // Still harvest the static leading tokens, e.g. class="flash {$flashClass}".
            $attr = preg_replace('/\{?\$[^}\s"]*\}?/', ' ', $attr) ?? '';
        }
        foreach (preg_split('/\s+/', trim($attr)) ?: [] as $cls) {
            if ($cls !== '') {
                $classes[$cls] = true;
            }
        }
    }
}

// Every dynamic fragment must be attributable to an allowlist entry.
$unattributed = [];
foreach (array_unique($dynamic) as $frag) {
    $ok = false;
    foreach ($allowlist as $allowed) {
        if (str_contains($frag, $allowed)) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        $unattributed[] = $frag;
    }
}
if ($unattributed !== []) {
    throw new RuntimeException(
        "runtime-composed class attributes not in the allowlist:\n  "
        . implode("\n  ", array_slice($unattributed, 0, 20))
        . "\nAdd 'allow-class <fragment>' lines to tests/fixtures/skin-v2-coverage.txt"
    );
}

// Every static class must be styled by v2.
$missing = [];
foreach (array_keys($classes) as $cls) {
    if (!preg_match('/\.' . preg_quote($cls, '/') . '\b/', $css)) {
        $missing[] = $cls;
    }
}
if ($missing !== []) {
    sort($missing);
    throw new RuntimeException(
        'leikwan-v2.css has no rule for ' . count($missing) . " emitted classes:\n  "
        . implode("\n  ", $missing)
    );
}

echo "ok (" . count($classes) . " classes covered)\n";
```

- [ ] **Step 2: Create the fixture with an empty allowlist**

Create `master/tests/fixtures/skin-v2-coverage.txt`:

```
# Unified web skin v2 -- coverage manifest.
#
# Two sections:
#   allow-class <fragment>   runtime-composed class attributes (see
#                            skin_v2_class_vocabulary_test.php)
#   route <verdict> <path>   page coverage during cutover (see
#                            skin_v2_coverage_manifest_test.php, Task 13)
#
# Verdicts: verified | pending | not-a-page
```

- [ ] **Step 3: Run it to discover the real vocabulary**

```bash
php -d date.timezone=UTC master/tests/skin_v2_class_vocabulary_test.php
```
Expected: `RuntimeException: leikwan-v2.css does not exist yet`.

Now create an empty file so the extractor runs, and read the actual list:

```bash
touch master/public/assets/leikwan-v2.css
php -d date.timezone=UTC master/tests/skin_v2_class_vocabulary_test.php
```
Expected: either an "unattributed" failure listing dynamic fragments, or a "no rule for N emitted classes" failure listing every class.

- [ ] **Step 4: Populate the allowlist from that output**

For each unattributed fragment the test printed, append one `allow-class` line to `master/tests/fixtures/skin-v2-coverage.txt` using the fragment's static prefix. From the known shells these will include at least:

```
allow-class nav-link
allow-class flash
allow-class pg-view-tab
allow-class pg-status
allow-class button
```

Re-run until the unattributed error is gone and the failure is the class-coverage one. **Save that class list** — it is the work order for Task 4.

- [ ] **Step 5: Commit the test in its failing state**

The test legitimately fails until Task 4 writes the CSS. Commit it now so the CSS work is test-driven.

```bash
git add master/tests/skin_v2_class_vocabulary_test.php master/tests/fixtures/skin-v2-coverage.txt master/public/assets/leikwan-v2.css
git commit -m "test: add class vocabulary inventory to define v2 stylesheet completeness"
```

---

### Task 3: Self-hosted fonts

Google Fonts is not reliably reachable from mainland China, where the users are. Latin is self-hosted; Chinese uses a system stack because a full CJK face is 3-8 MB.

**Files:**
- Create: `master/public/assets/fonts/archivo-variable.woff2`
- Create: `master/public/assets/fonts/spline-sans-mono-400.woff2`
- Create: `master/public/assets/fonts/spline-sans-mono-600.woff2`
- Test: `master/tests/skin_v2_font_hosting_test.php`

- [ ] **Step 1: Write the failing test**

Create `master/tests/skin_v2_font_hosting_test.php`:

```php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/public/assets/leikwan-v2.css');

foreach ([
    'fonts/archivo-variable.woff2',
    'fonts/spline-sans-mono-400.woff2',
    'fonts/spline-sans-mono-600.woff2',
] as $rel) {
    if (!is_file($root . '/public/assets/' . $rel)) {
        throw new RuntimeException('font is not self-hosted: ' . $rel);
    }
    if (!str_contains($css, $rel)) {
        throw new RuntimeException('stylesheet does not reference ' . $rel);
    }
}

// No remote font CDN: unreachable behind the GFW.
foreach (['fonts.googleapis.com', 'fonts.gstatic.com', '@import url(http'] as $forbidden) {
    if (str_contains($css, $forbidden)) {
        throw new RuntimeException('remote font dependency is forbidden: ' . $forbidden);
    }
}

// Latin faces must not claim CJK coverage, or the browser stops falling through
// to the system CJK stack and Chinese renders in a fallback serif.
if (!str_contains($css, 'unicode-range')) {
    throw new RuntimeException('@font-face must declare unicode-range');
}

echo "ok\n";
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
php -d date.timezone=UTC master/tests/skin_v2_font_hosting_test.php
```
Expected: `RuntimeException: font is not self-hosted: fonts/archivo-variable.woff2`

- [ ] **Step 3: Download the fonts**

Both are SIL Open Font License, so redistribution in-repo is permitted. Fetch through the local proxy — `fonts.gstatic.com` is unreliable from this network:

```bash
mkdir -p master/public/assets/fonts
P="-x http://127.0.0.1:2080"
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36"
curl -sL $P -A "$UA" "https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..800&family=Spline+Sans+Mono:wght@400;600&display=swap" -o /tmp/gf.css
grep -o 'https://fonts.gstatic.com[^)]*\.woff2' /tmp/gf.css | sort -u
```

From that list download the **latin** subset of each face and save as the three filenames above. Verify each is a real woff2 (`wOF2` magic) and non-trivial:

```bash
for f in master/public/assets/fonts/*.woff2; do
  printf '%s ' "$f"; head -c4 "$f"; printf ' %s bytes\n' "$(stat -c%s "$f")"
done
```
Expected: each line shows `wOF2` and a size above 10000.

- [ ] **Step 4: Declare the faces**

Prepend to `master/public/assets/leikwan-v2.css`:

```css
/* ==========================================================================
   Unified web skin v2 -- font layer
   Latin is self-hosted (Google Fonts is unreliable behind the GFW).
   CJK uses a system stack: a full CJK face is 3-8 MB and cannot ship.
   ========================================================================== */

@font-face {
  font-family: 'Archivo';
  src: url('fonts/archivo-variable.woff2') format('woff2-variations');
  font-weight: 400 800;
  font-stretch: 62% 125%;
  font-display: swap;
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+2000-206F, U+2074, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215;
}

@font-face {
  font-family: 'Spline Sans Mono';
  src: url('fonts/spline-sans-mono-400.woff2') format('woff2');
  font-weight: 400;
  font-display: swap;
  unicode-range: U+0000-00FF, U+2000-206F, U+2212;
}

@font-face {
  font-family: 'Spline Sans Mono';
  src: url('fonts/spline-sans-mono-600.woff2') format('woff2');
  font-weight: 600;
  font-display: swap;
  unicode-range: U+0000-00FF, U+2000-206F, U+2212;
}
```

- [ ] **Step 5: Run it and confirm it passes**

```bash
php -d date.timezone=UTC master/tests/skin_v2_font_hosting_test.php
```
Expected: `ok`

- [ ] **Step 6: Commit**

```bash
git add master/public/assets/fonts master/public/assets/leikwan-v2.css master/tests/skin_v2_font_hosting_test.php
git commit -m "feat: self-host Archivo and Spline Sans Mono for the v2 skin"
```

---

### Task 4: Token layer

The locked design tokens. Everything downstream references these; no component may hardcode a colour.

**Files:**
- Modify: `master/public/assets/leikwan-v2.css` (append after the font layer)
- Test: `master/tests/skin_v2_token_contract_test.php`

- [ ] **Step 1: Write the failing test**

Note what this asserts: that each token **is defined**, never that it equals a particular hex. Palette tuning must not turn the suite red — that failure mode is why the legacy tests were worthless.

Create `master/tests/skin_v2_token_contract_test.php`:

```php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/public/assets/leikwan-v2.css');
$compact = preg_replace('/\s+/', '', $css) ?: '';

// Tokens must exist. Their values are a design decision, not a test fixture.
foreach ([
    '--ink-0', '--ink-1', '--ink-2', '--ink-line',
    '--paper-0', '--paper-1', '--paper-line', '--paper-rule',
    '--text-hi', '--text-lo', '--paper-hi', '--paper-lo', '--micro',
    '--font-display', '--font-body', '--font-mono', '--font-cjk',
    '--radius', '--row-h', '--cjk-heading-scale',
] as $token) {
    if (!str_contains($compact, $token . ':')) {
        throw new RuntimeException('v2 token is not defined: ' . $token);
    }
}

// One radius spec. The legacy skins had five.
preg_match_all('/border-radius:\s*([^;}]+)/i', $css, $m);
$radii = [];
foreach ($m[1] as $value) {
    $value = trim($value);
    if (!str_contains($value, 'var(--radius)') && $value !== '0' && $value !== '1px' && $value !== '50%') {
        $radii[$value] = true;
    }
}
if ($radii !== []) {
    throw new RuntimeException('radius must come from var(--radius); found: ' . implode(', ', array_keys($radii)));
}

// The whole point of the rewrite.
if (str_contains($css, '!important')) {
    throw new RuntimeException('leikwan-v2.css must contain zero !important');
}

// Glassmorphism is retired.
if (str_contains($compact, 'backdrop-filter')) {
    throw new RuntimeException('backdrop-filter is retired in v2');
}

// Hue is quarantined to status. No other component may introduce a saturated colour.
$statusOnly = ['--state-ok', '--state-warn', '--state-down'];
foreach ($statusOnly as $token) {
    if (!str_contains($compact, $token)) {
        throw new RuntimeException('status token missing: ' . $token);
    }
}
if (str_contains($compact, '--signal:#ef4938')) {
    throw new RuntimeException('the retired --signal accent collided with the danger state');
}

echo "ok\n";
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
php -d date.timezone=UTC master/tests/skin_v2_token_contract_test.php
```
Expected: `RuntimeException: v2 token is not defined: --ink-0`

- [ ] **Step 3: Append the token layer**

Append to `master/public/assets/leikwan-v2.css`:

```css
/* ==========================================================================
   Token layer
   Achromatic skeleton. Emphasis comes from luminance, not hue.
   ========================================================================== */

:root {
  /* Shell -- the dark chrome that holds everything up. */
  --ink-0: #0a0b0c;
  --ink-1: #101314;
  --ink-2: #171b1c;
  --ink-line: #1f2425;

  /* Sheet -- the light surface that carries data. */
  --paper-0: #fbfbf9;
  --paper-1: #f5f5f1;
  --paper-line: #e6e6e0;
  --paper-rule: #14171a;

  /* Text */
  --text-hi: #f2f3f0;
  --text-lo: #8b918b;
  --paper-hi: #14171a;
  --paper-lo: #7a807b;
  --micro: #9aa09a;

  /* Status. The only hues in the system, and always paired with a shape and
     a text label so they survive colourblindness and grayscale. */
  --state-ok: #3f6b4e;
  --state-warn: #8a6a21;
  --state-down: #96453a;
  --state-ok-shell: #8fa894;
  --state-warn-shell: #b9a06a;
  --state-down-shell: #c08379;

  /* Type */
  --font-display: 'Archivo', var(--font-cjk);
  --font-body: 'Archivo', var(--font-cjk);
  --font-mono: 'Spline Sans Mono', ui-monospace, Consolas, monospace;
  --font-cjk: 'PingFang SC', 'PingFang TC', 'Microsoft YaHei', 'Microsoft JhengHei', 'Noto Sans CJK SC', sans-serif;

  /* Chinese glyphs carry more weight per em than wide Latin display type, so
     CJK headings render at 75% of the Latin size for the same level. */
  --cjk-heading-scale: 0.75;

  /* Geometry -- one radius, 4px rhythm, one row height sized for the trace. */
  --radius: 3px;
  --row-h: 34px;
  --step: 4px;

  /* Depth. Two sources only; see the spec. */
  --lift-sheet: 0 18px 34px -14px rgba(0, 0, 0, .85), 0 0 0 1px rgba(255, 255, 255, .07);
}
```

- [ ] **Step 4: Run it and confirm it passes**

```bash
php -d date.timezone=UTC master/tests/skin_v2_token_contract_test.php
```
Expected: `ok`

- [ ] **Step 5: Commit**

```bash
git add master/public/assets/leikwan-v2.css master/tests/skin_v2_token_contract_test.php
git commit -m "feat: add v2 token layer with achromatic palette and quarantined status hues"
```

---

### Task 5: Component layer

Driven by the class list Task 2 produced. The inventory test is the definition of done — write rules until it is green, and do not add rules for classes the controllers never emit.

**Files:**
- Modify: `master/public/assets/leikwan-v2.css` (append after the token layer)
- Test: `master/tests/skin_v2_class_vocabulary_test.php` (already exists, currently failing)

- [ ] **Step 1: Re-read the work order**

```bash
php -d date.timezone=UTC master/tests/skin_v2_class_vocabulary_test.php
```
Expected: `RuntimeException: leikwan-v2.css has no rule for N emitted classes:` followed by the list. That list is this task's scope.

- [ ] **Step 2: Write the structural rules**

Append to `master/public/assets/leikwan-v2.css`. These are the load-bearing rules; the remaining per-class rules follow the same vocabulary.

```css
/* ==========================================================================
   Component layer
   ========================================================================== */

*, *::before, *::after { box-sizing: border-box; }

body {
  margin: 0;
  background: var(--ink-0);
  color: var(--text-hi);
  font-family: var(--font-body);
  font-size: 13.5px;
  line-height: 1.6;
  -webkit-font-smoothing: antialiased;
}

/* Display type: the wdth axis is display-only. Varying it in dense tables
   destroys column rhythm, so body and table type stay at regular width. */
h1, h2, h3, .pg-page-header h1 {
  font-family: var(--font-display);
  font-variation-settings: 'wdth' 118;
  font-weight: 800;
  letter-spacing: -0.03em;
  line-height: 1.05;
  margin: 0;
}
h2, h3 { font-weight: 700; letter-spacing: -0.02em; }

:lang(zh) h1, :lang(zh) h2, :lang(zh) h3 {
  font-size: calc(1em * var(--cjk-heading-scale));
  font-weight: 600;
  letter-spacing: 0;
}

/* All numerals are mono and tabular so values do not jitter across rows. */
.pg-metric dd, .pg-resource-secondary, td, .pg-eyebrow, .pg-nav-heading {
  font-variant-numeric: tabular-nums;
}

.pg-eyebrow, .pg-nav-heading {
  font-family: var(--font-mono);
  font-size: 7.5px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: var(--micro);
}

/* The sheet: the one light surface, lifted off the dark shell. */
.content-card, .pg-console {
  background: var(--paper-0);
  color: var(--paper-hi);
  border-radius: var(--radius);
  box-shadow: var(--lift-sheet);
  min-width: 0;
  overflow-wrap: anywhere;
}

/* The page header stays on the dark shell; the sheet carries data only. */
.pg-page-header {
  background: transparent;
  color: var(--text-hi);
  box-shadow: none;
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: calc(var(--step) * 4);
  padding: calc(var(--step) * 6) 0 calc(var(--step) * 4);
}

.table-scroll { max-width: 100%; overflow: auto; }

:focus-visible {
  outline: 2px solid var(--text-hi);
  outline-offset: 2px;
}

.pg-visually-hidden {
  position: absolute;
  width: 1px; height: 1px;
  padding: 0; margin: -1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}

/* Touch targets stay at the size the legacy accessibility test locked in. */
.pg-row-menu > summary {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  cursor: pointer;
  list-style: none;
}

@media (max-width: 900px) {
  .content-card, .pg-console { border-radius: 0; box-shadow: none; }
  .sidebar { display: none; }
  .sidebar[data-open="true"] { display: block; }
}
```

- [ ] **Step 3: Iterate until the inventory is green**

Re-run after each batch of rules:

```bash
php -d date.timezone=UTC master/tests/skin_v2_class_vocabulary_test.php
```
Expected on success: `ok (N classes covered)`

- [ ] **Step 4: Confirm the token contract still holds**

```bash
php -d date.timezone=UTC master/tests/skin_v2_token_contract_test.php
```
Expected: `ok`

- [ ] **Step 5: Confirm the suite**

Run the full suite.

Expected: `PASS=237 FAIL=8` — and the 8th failure **must** be `precision_grid_responsive_accessibility_test.php`, which bans negative letter-spacing globally. That is the known conflict from "Facts you need before starting"; Task 15 rescopes it. Verify it is that test and no other:

```bash
php -d date.timezone=UTC master/tests/precision_grid_responsive_accessibility_test.php
```
Expected: `negative letter spacing is forbidden`

- [ ] **Step 6: Commit**

```bash
git add master/public/assets/leikwan-v2.css
git commit -m "feat: add v2 component layer covering the emitted class vocabulary"
```

---

### Task 6: Public shell

**Files:**
- Modify: `master/src/Controllers/BaseController.php` — `publicPage()`, heredoc at L1196-1234
- Test: `master/tests/skin_v2_public_shell_test.php`

- [ ] **Step 1: Write the failing test**

Create `master/tests/skin_v2_public_shell_test.php`:

```php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');

// Both shells exist during migration and the v2 one is selected by the flag.
if (!str_contains($base, "\$skin = \$this->activeSkin(\$viewer);")) {
    throw new RuntimeException('publicPage must resolve the skin');
}
if (!str_contains($base, '/assets/leikwan-v2.css?v=')) {
    throw new RuntimeException('v2 shell must load the v2 stylesheet');
}

// The legacy stylesheets must not leak into the v2 shell: that is exactly how
// the three legacy layers ended up fighting each other.
$v2Block = '';
if (preg_match('/\$skin === \'v2\'.*?HTML;/s', $base, $m) === 1) {
    $v2Block = $m[0];
}
if ($v2Block === '') {
    throw new RuntimeException('could not locate the v2 shell branch');
}
foreach (['signal-chamber.css', 'operator-console.css', 'leikwan-ui.css'] as $legacy) {
    if (str_contains($v2Block, $legacy)) {
        throw new RuntimeException('v2 shell must not load the legacy stylesheet ' . $legacy);
    }
}

// Accessibility contracts carried over from the legacy shell.
foreach ([
    'name="viewport" content="width=device-width, initial-scale=1"',
    'class="brand-plate"',
] as $needle) {
    if (!str_contains($base, $needle)) {
        throw new RuntimeException('public shell contract missing: ' . $needle);
    }
}

// The public depth source is one rendered object, built in CSS from the four
// elements the spec names: a radial gradient, a rim light, an interior falloff,
// and a cast shadow. A raster or a build step is not acceptable here.
$css = (string) file_get_contents($root . '/public/assets/leikwan-v2.css');
if (preg_match('/\.signal-canvas\s*\{[^}]*\}/s', $css, $m) !== 1) {
    throw new RuntimeException('the hero object has no rule');
}
$object = $m[0];
if (!str_contains($object, 'radial-gradient')) {
    throw new RuntimeException('the hero object must be built from a gradient');
}
if (str_contains($object, 'url(')) {
    throw new RuntimeException('the hero object must not reference a raster asset');
}
if (preg_match('/box-shadow:([^;]+);/s', $object, $sm) !== 1) {
    throw new RuntimeException('the hero object needs a box-shadow stack');
}
$inset = 0;
$cast = 0;
foreach (preg_split('/,(?![^()]*\))/', $sm[1]) ?: [] as $layer) {
    if (str_contains($layer, 'inset')) {
        $inset++;
    } else {
        $cast++;
    }
}
if ($inset < 2 || $cast < 1) {
    throw new RuntimeException(
        'the hero object needs 2+ inset layers (rim light, interior falloff) and 1 cast shadow, got '
        . $inset . ' and ' . $cast
    );
}

echo "ok\n";
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
php -d date.timezone=UTC master/tests/skin_v2_public_shell_test.php
```
Expected: `RuntimeException: publicPage must resolve the skin`

- [ ] **Step 3: Add the v2 branch**

In `publicPage()`, immediately before the existing `$html = <<<HTML` at L1196, insert the skin resolution and the v2 shell. Keep the existing heredoc as the `legacy` branch, unchanged.

```php
        $skin = $this->activeSkin($viewer);
        if ($skin === 'v2') {
            $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$escapedTitle}</title>
  <link rel="stylesheet" href="/assets/leikwan-v2.css?v=20260726">
</head>
<body class="pg-public">
  <div class="public-shell">
    <header class="site-topbar">
      <a class="site-brand" href="/">
        <span class="brand-plate"><img src="{$brandLogoUrl}" alt="{$escapedBrandName} logo"></span>
        <span class="site-copy">
          <strong>{$escapedBrandName}</strong>
          <span>{$escapedAppName} control plane</span>
        </span>
      </a>
      <div class="site-actions">
        {$actionsHtml}
      </div>
    </header>
    <main class="landing-stack">
      {$flashHtml}
      {$body}
    </main>
    <footer class="page-footer">
      <div class="footer-tools">
        {$languageSwitcher}
      </div>
    </footer>
  </div>
{$this->i18nBootstrapHtml()}
  <script src="/assets/signal-chamber.js?v=20260725" defer></script>
</body>
</html>
HTML;

            return Response::html(200, $html);
        }
```

- [ ] **Step 4: Style the brand plate**

The mark is a light-ground raster: its navy wordmark measures 1.68:1 on `--ink-0`, and it carries white matting fringes. On a paper plate both problems vanish and no recolouring is needed. Append to `master/public/assets/leikwan-v2.css`:

```css
/* The mark sits on its own light surface -- same material as the data sheet.
   It was drawn for a light ground and cannot sit directly on the shell. */
.brand-plate {
  display: inline-grid;
  place-items: center;
  padding: 0 10px;
  background: var(--paper-0);
  border-radius: var(--radius);
}
.brand-plate img {
  display: block;
  height: 40px;  /* below ~32px the mark's internal lettering stops resolving */
  width: auto;
}
```

- [ ] **Step 5: Build the rendered object**

This is the public pages' entire depth source — the counterpart to the console's floating sheet. The homepage and the auth pages already emit `<canvas class="signal-canvas">` inside `.signal-hero` (see `AuthController.php`), and `signal-chamber.js` paints its motion. **You do not add markup.** The object is the canvas element's own surface; the existing animation composites on top of it.

Append to `master/public/assets/leikwan-v2.css`:

```css
/* The one rendered object on the public side. Achromatic, like everything
   else -- the form reads through luminance, not hue. Built from a gradient
   plus a shadow stack, so there is no raster to ship and no build step. */
.signal-hero {
  position: relative;
}
.signal-canvas {
  display: block;
  width: 100%;
  aspect-ratio: 16 / 10;
  border-radius: var(--radius);
  /* A matte solid lit from the upper left, falling off into the shell. */
  background: radial-gradient(
    58% 52% at 34% 28%,
    #2b3133 0%,
    #202526 38%,
    #14181a 68%,
    #0d1011 100%
  );
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, .10),        /* rim light on the top edge */
    inset 0 -28px 48px -30px rgba(0, 0, 0, .90),   /* the mass falls into shadow */
    0 30px 60px -28px rgba(0, 0, 0, .90);          /* cast onto the shell */
}
```

- [ ] **Step 6: Check the canvas is not painting over it**

The CSS background sits *behind* the canvas bitmap, so it is only visible where that bitmap is transparent. Load the homepage with `?skin=v2` and look at it.

If the object is invisible, `signal-chamber.js` is filling the bitmap opaquely. Do not edit the JS — it is frozen by its motion-guard test. Move the object to a pseudo-element behind the canvas instead:

```css
.signal-canvas { background: none; box-shadow: none; }
.signal-hero::before {
  content: "";
  position: absolute;
  inset: 0;
  z-index: 0;
  border-radius: var(--radius);
  background: radial-gradient(
    58% 52% at 34% 28%,
    #2b3133 0%,
    #202526 38%,
    #14181a 68%,
    #0d1011 100%
  );
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, .10),
    inset 0 -28px 48px -30px rgba(0, 0, 0, .90),
    0 30px 60px -28px rgba(0, 0, 0, .90);
}
.signal-canvas { position: relative; z-index: 1; }
```

Then update the test's `preg_match` selector from `\.signal-canvas` to `\.signal-hero::before` so it still checks the rule that actually draws the object.

- [ ] **Step 7: Run it and confirm it passes**

```bash
php -d date.timezone=UTC master/tests/skin_v2_public_shell_test.php
php -d date.timezone=UTC master/tests/skin_v2_class_vocabulary_test.php
```
Expected: `ok` from both.

- [ ] **Step 8: Commit**

```bash
git add master/src/Controllers/BaseController.php master/public/assets/leikwan-v2.css master/tests/skin_v2_public_shell_test.php
git commit -m "feat: add v2 public shell with brand plate and rendered object"
```

---

### Task 7: Console shell

**Files:**
- Modify: `master/src/Controllers/BaseController.php` — `page()`, heredoc at L1302-1346
- Modify: `master/public/assets/leikwan-v2.css`
- Test: `master/tests/skin_v2_console_shell_test.php`

- [ ] **Step 1: Write the failing test**

Create `master/tests/skin_v2_console_shell_test.php`:

```php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$css = (string) file_get_contents($root . '/public/assets/leikwan-v2.css');

// The mobile drawer JS is unchanged and still wired. Regressing this breaks
// navigation on every phone.
foreach ([
    'class="mobile-nav-toggle"',
    'aria-controls="workspace-navigation"',
    'id="workspace-navigation"',
    "setAttribute('aria-expanded', open ? 'true' : 'false')",
] as $needle) {
    if (!str_contains($base, $needle)) {
        throw new RuntimeException('mobile navigation contract missing: ' . $needle);
    }
}

// The sheet floats; the shell holds it up.
$compact = preg_replace('/\s+/', '', $css) ?: '';
if (!str_contains($compact, 'box-shadow:var(--lift-sheet)')) {
    throw new RuntimeException('the data sheet must be lifted off the shell');
}
if (!str_contains($compact, '.sidebar[data-open="true"]{display:block}')) {
    throw new RuntimeException('mobile drawer rule missing');
}

// The page header lives on the dark shell, not on the white sheet.
if (!preg_match('/\.pg-page-header\s*\{[^}]*background:\s*transparent/', $css)) {
    throw new RuntimeException('page header must sit on the shell, not on the sheet');
}

echo "ok\n";
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
php -d date.timezone=UTC master/tests/skin_v2_console_shell_test.php
```
Expected: passes the markup checks, then `RuntimeException: the data sheet must be lifted off the shell` (the rule exists but the compact form differs) — or `ok` if Task 5's rules already satisfy it. If it prints `ok`, still complete Step 3: the shell branch does not exist yet and the next step's test will catch it.

- [ ] **Step 3: Add the v2 branch to `page()`**

Immediately before the existing `$html = <<<HTML` at L1302, insert. The legacy heredoc below it stays untouched.

```php
        $skin = $this->activeSkin($viewer);
        if ($skin === 'v2') {
            $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$escapedTitle}</title>
  <link rel="stylesheet" href="/assets/leikwan-v2.css?v=20260726">
</head>
<body class="pg-app">
  <div class="shell">
    <div class="topbar">
      <div class="brand">
        <div class="brand-plate"><img src="{$brandLogoUrl}" alt="{$escapedBrandName} logo"></div>
        <div class="brand-copy">
          <h1>{$escapedBrandName}</h1>
          <p>{$escapedAppName} control plane</p>
        </div>
      </div>
      <div class="toolbar">
        <button class="mobile-nav-toggle" type="button" aria-controls="workspace-navigation" aria-expanded="false">&#9776;<span class="pg-visually-hidden">Navigation</span></button>
        {$viewerHtml}
      </div>
    </div>
    <div class="layout">
      <aside class="sidebar" id="workspace-navigation" data-open="false">
        {$navHtml}
      </aside>
      <main class="content-card">
        {$flashHtml}
        {$lowBalanceHtml}
        {$body}
      </main>
    </div>
    <footer class="page-footer">
      <div class="footer-tools">
        {$languageSwitcher}
      </div>
    </footer>
  </div>
{$this->i18nBootstrapHtml()}
HTML;

            $html .= $this->consoleShellScriptHtml() . "\n</body>\n</html>";

            return Response::html(200, $html);
        }
```

The legacy shell inlines its `<script>` inside the heredoc. Rather than duplicating ~90 lines of JS, extract it once so both shells share it — duplicated behaviour would drift.

- [ ] **Step 4: Extract the shared console script**

Cut the entire `<script>` … `</script>` block from the legacy `page()` heredoc (starts L1348) into a new private method, and reference it from **both** shells. Add near `activeSkin()`:

```php
    /**
     * Console shell behaviour, shared by the legacy and v2 shells.
     *
     * Extracted rather than duplicated: two copies of the drawer and clipboard
     * logic would drift, and the mobile drawer is the only way to navigate on a
     * phone.
     */
    private function consoleShellScriptHtml(): string
    {
        return <<<'HTML'
<script>
  /* PASTE THE EXISTING BLOCK FROM page() HERE, VERBATIM, INCLUDING THE
     mobile-nav-toggle LISTENER AND fallbackCopy(). Change nothing. */
</script>
HTML;
    }
```

Then in the legacy heredoc, replace the removed block with `{$this->consoleShellScriptHtml()}`.

Verify the JS survived the move byte-for-byte:

```bash
php -d date.timezone=UTC master/tests/precision_grid_responsive_accessibility_test.php
```
Expected: still only the known negative-letter-spacing failure, not a missing-navigation failure.

- [ ] **Step 5: Add the console shell rules**

Append to `master/public/assets/leikwan-v2.css`:

```css
.shell { background: var(--ink-1); min-height: 100vh; }

.topbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: calc(var(--step) * 4);
  padding: calc(var(--step) * 3) calc(var(--step) * 6);
  border-bottom: 1px solid var(--ink-line);
}

.layout {
  display: grid;
  grid-template-columns: 244px minmax(0, 1fr);
  gap: calc(var(--step) * 6);
  padding: 0 calc(var(--step) * 6) calc(var(--step) * 6);
}

.sidebar { padding-top: calc(var(--step) * 4); }

.nav-link {
  display: block;
  padding: calc(var(--step) * 2) calc(var(--step) * 3);
  color: var(--text-lo);
  text-decoration: none;
  border-left: 2px solid transparent;
}
.nav-link:hover { color: var(--text-hi); }
.nav-link.active {
  color: var(--text-hi);
  border-left-color: var(--text-hi);
}

@media (max-width: 900px) {
  .layout { grid-template-columns: minmax(0, 1fr); padding: 0; gap: 0; }
}
```

- [ ] **Step 6: Confirm**

```bash
php -d date.timezone=UTC master/tests/skin_v2_console_shell_test.php
php -d date.timezone=UTC master/tests/skin_v2_class_vocabulary_test.php
```
Expected: `ok` from both. Then run the full suite: `PASS=239 FAIL=8` (still only the letter-spacing conflict).

- [ ] **Step 7: Commit**

```bash
git add master/src/Controllers/BaseController.php master/public/assets/leikwan-v2.css master/tests/skin_v2_console_shell_test.php
git commit -m "feat: add v2 console shell and share the shell script between skins"
```

---

### Task 8: Status triple encoding

`PrecisionGridUi::status()` is the single change point for all 16 status call sites. The achromatic palette costs scannability unless status is encoded three ways at once.

**Files:**
- Modify: `master/src/Support/PrecisionGridUi.php:85-89`
- Modify: `master/public/assets/leikwan-v2.css`
- Test: `master/tests/skin_v2_status_encoding_test.php`

- [ ] **Step 1: Write the failing test**

Behavioural, not string-matching. Create `master/tests/skin_v2_status_encoding_test.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/PrecisionGridUi.php';

use Leikwan\Master\Support\PrecisionGridUi;

// Every state must carry shape + colour + text. Colour alone fails for
// colourblind users, on low-contrast displays, and in grayscale print.
foreach (['ok' => 'Healthy', 'warn' => 'Degraded', 'down' => 'Down'] as $state => $label) {
    $html = PrecisionGridUi::status($label, $state);

    if (!str_contains($html, 'data-state="' . $state . '"')) {
        throw new RuntimeException('status must expose its state: ' . $state);
    }
    if (!str_contains($html, '>' . $label . '<')) {
        throw new RuntimeException('status must carry a text label: ' . $state);
    }
    if (!preg_match('/data-shape="(circle|triangle|square)"/', $html)) {
        throw new RuntimeException('status must carry a shape encoding: ' . $state);
    }
    if (!str_contains($html, 'aria-hidden="true"')) {
        throw new RuntimeException('the decorative glyph must be hidden from screen readers: ' . $state);
    }
}

// Distinct states must get distinct shapes, or the shape encoding is decorative.
$shapes = [];
foreach (['ok', 'warn', 'down'] as $state) {
    preg_match('/data-shape="([a-z]+)"/', PrecisionGridUi::status('X', $state), $m);
    $shapes[] = $m[1] ?? '';
}
if (count(array_unique($shapes)) !== 3) {
    throw new RuntimeException('each state needs its own shape, got: ' . implode(',', $shapes));
}

// An unknown state must not silently render as healthy.
$unknown = PrecisionGridUi::status('Mystery', 'banana');
if (str_contains($unknown, 'data-shape="circle"')) {
    throw new RuntimeException('unknown states must not render as healthy');
}

echo "ok\n";
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
php -d date.timezone=UTC master/tests/skin_v2_status_encoding_test.php
```
Expected: `RuntimeException: status must carry a shape encoding: ok`

- [ ] **Step 3: Implement**

Replace `PrecisionGridUi::status()` (L85-89) with:

```php
    /**
     * Status is encoded three ways at once -- shape, colour and text.
     *
     * The v2 palette is achromatic, so colour carries less signal than it did.
     * None of the three encodings is optional: shape survives colourblindness
     * and grayscale, text survives everything.
     */
    public static function status(string $label, string $state): string
    {
        $shape = match (strtolower(trim($state))) {
            'ok', 'healthy', 'active', 'online' => 'circle',
            'warn', 'warning', 'degraded', 'pending' => 'triangle',
            'down', 'error', 'danger', 'failed', 'offline' => 'square',
            default => 'unknown',
        };

        return '<span class="pg-status" data-state="' . self::escape($state)
            . '" data-shape="' . $shape . '">'
            . '<i class="pg-status-dot" data-shape="' . $shape . '" aria-hidden="true"></i>'
            . self::escape($label) . '</span>';
    }
```

- [ ] **Step 4: Style the shapes**

Append to `master/public/assets/leikwan-v2.css`:

```css
.pg-status {
  display: inline-flex;
  align-items: center;
  gap: calc(var(--step) * 2);
  font-family: var(--font-mono);
  font-size: 11px;
}

.pg-status-dot {
  width: 8px;
  height: 8px;
  background: currentColor;
  flex: none;
}
.pg-status-dot[data-shape="circle"]   { border-radius: 50%; }
.pg-status-dot[data-shape="square"]   { border-radius: 0; }
.pg-status-dot[data-shape="triangle"] {
  background: none;
  border-left: 4px solid transparent;
  border-right: 4px solid transparent;
  border-bottom: 8px solid currentColor;
  width: 0; height: 0;
}
.pg-status-dot[data-shape="unknown"] {
  background: none;
  border: 1px solid currentColor;
  border-radius: 50%;
}

.pg-status[data-shape="circle"]   { color: var(--state-ok); }
.pg-status[data-shape="triangle"] { color: var(--state-warn); }
.pg-status[data-shape="square"]   { color: var(--state-down); }
.pg-status[data-shape="unknown"]  { color: var(--paper-lo); }

.pg-app .topbar .pg-status[data-shape="circle"]   { color: var(--state-ok-shell); }
.pg-app .topbar .pg-status[data-shape="triangle"] { color: var(--state-warn-shell); }
.pg-app .topbar .pg-status[data-shape="square"]   { color: var(--state-down-shell); }
```

- [ ] **Step 5: Confirm**

```bash
php -d date.timezone=UTC master/tests/skin_v2_status_encoding_test.php
php -d date.timezone=UTC master/tests/readable_sheet_design_system_regression_test.php
```
Expected: `ok` from both. The second one matters — it asserts the old `status()` contract (`pg-status-dot` and `>Healthy<`), and the rewrite must not break it.

- [ ] **Step 6: Commit**

```bash
git add master/src/Support/PrecisionGridUi.php master/public/assets/leikwan-v2.css master/tests/skin_v2_status_encoding_test.php
git commit -m "feat: encode status by shape, colour and text at every call site"
```

---

### Task 9: Ledger data grid

Removing row rules is what makes the grid read as typography rather than as a spreadsheet, but it introduces line-slip risk. The three mitigations are required, not optional.

**Files:**
- Modify: `master/public/assets/leikwan-v2.css`
- Test: `master/tests/skin_v2_ledger_grid_test.php`

- [ ] **Step 1: Write the failing test**

Create `master/tests/skin_v2_ledger_grid_test.php`:

```php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/public/assets/leikwan-v2.css');
$compact = preg_replace('/\s+/', '', $css) ?: '';

// No header fill, no zebra striping, no vertical rules.
if (str_contains($compact, 'tr:nth-child(even)') || str_contains($compact, 'tr:nth-child(odd)')) {
    throw new RuntimeException('zebra striping is not part of the ledger grid');
}
if (preg_match('/td\s*\{[^}]*border-(left|right)\s*:\s*(?!none)/', $css)) {
    throw new RuntimeException('vertical rules are not part of the ledger grid');
}

// Line-slip mitigations. Dropping row rules without these makes long lists
// unreadable, so all three are contracts.
foreach ([
    'tbodytr:hover',            // 1. full-row hover
    'tbodytr:focus-within',     // 3. keyboard parity with the mouse
] as $needle) {
    if (!str_contains($compact, $needle)) {
        throw new RuntimeException('line-slip mitigation missing: ' . $needle);
    }
}
if (!str_contains($compact, 'nth-child(8n)')) {
    throw new RuntimeException('line-slip mitigation missing: banding every 8 rows');
}

// Numerals right-aligned and tabular, or columns of latencies will not line up.
if (!str_contains($compact, 'font-variant-numeric:tabular-nums')) {
    throw new RuntimeException('table numerals must be tabular');
}

// Row height is a token, so the trace bar and the rows stay in proportion.
if (!str_contains($compact, 'height:var(--row-h)')) {
    throw new RuntimeException('row height must come from --row-h');
}

echo "ok\n";
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
php -d date.timezone=UTC master/tests/skin_v2_ledger_grid_test.php
```
Expected: `RuntimeException: line-slip mitigation missing: tbodytr:hover`

- [ ] **Step 3: Implement**

Append to `master/public/assets/leikwan-v2.css`:

```css
/* ==========================================================================
   Ledger grid
   Typography, not a spreadsheet: one hairline under the header, no fills,
   no zebra, no vertical rules, no row rules.
   ========================================================================== */

table {
  width: 100%;
  border-collapse: collapse;
  color: var(--paper-hi);
}

thead th {
  font-family: var(--font-mono);
  font-size: 7.5px;
  font-weight: 400;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: var(--micro);
  text-align: left;
  padding: calc(var(--step) * 2) calc(var(--step) * 3);
  background: none;
  border-bottom: 1px solid var(--paper-rule);
  white-space: nowrap;
}

tbody td {
  height: var(--row-h);
  padding: 0 calc(var(--step) * 3);
  border: none;
  font-variant-numeric: tabular-nums;
  vertical-align: middle;
}

/* Line-slip mitigations. Without row rules the eye loses the line on long
   lists, so the row itself has to respond. */
tbody tr:hover { background: var(--paper-1); }
tbody tr:focus-within { background: var(--paper-1); }
tbody tr:nth-child(8n) td { box-shadow: inset 0 -1px 0 var(--paper-line); }

.pg-resource-primary {
  font-family: var(--font-display);
  font-weight: 600;
  font-size: 12.5px;
  display: block;
}
.pg-resource-secondary {
  font-family: var(--font-mono);
  font-size: 10px;
  color: var(--paper-lo);
  display: block;
}

/* Numeric columns read down the column, so they align right. */
td.num, th.num { text-align: right; font-family: var(--font-mono); }
td.num small { font-size: 9.5px; color: var(--micro); }
```

- [ ] **Step 4: Confirm**

```bash
php -d date.timezone=UTC master/tests/skin_v2_ledger_grid_test.php
php -d date.timezone=UTC master/tests/skin_v2_token_contract_test.php
```
Expected: `ok` from both.

- [ ] **Step 5: Commit**

```bash
git add master/public/assets/leikwan-v2.css master/tests/skin_v2_ledger_grid_test.php
git commit -m "feat: add ledger data grid with line-slip mitigations"
```

---

### Task 10: Uptime trace — pure functions

Bucketing and rendering are pure functions with no DB access, so they are unit-testable. This matters: 6 of the 7 pre-existing suite failures are "no MySQL driver", and a trace test that needed a database would be untestable here.

**Why `node_heartbeats` and not `bond_leg_reputation_events`:** the reputation table is populated by the bond binary (`session.rs` → `bond-leg-health-forward-*.json` → `client_agent.py:1143` → heartbeat → `BondLegReputationService::ingestSnapshots()`). Forwards on `leigod_mode` or true-MPTCP never run that binary, so the column would be blank precisely on the mode the fleet is migrating toward. `node_heartbeats` is `backend_mode`-independent.

> **Amended during execution — two defects in the code below. `master/src/Support/UptimeTrace.php` in the repo is the source of truth.**
>
> **The bucketing anchored on the wrong end of the window.** `intdiv($ts - $start, $span)` counts forward from `now - 3600`, which puts the boundary error at the old end and needs an `if ($index >= self::BLOCKS)` clamp to stay in range. That clamp was the tell: a correct partition of a fixed window into 12 equal blocks cannot overflow. Run against this task's own test data it marks block 4 `present` — the heartbeat at exactly 40 minutes old lands on block 4's left edge — so only block 5 comes out a gap and `block 4 should be a gap` fails. The shipped version anchors on `now` and counts backwards, `$buckets[self::BLOCKS - 1 - intdiv($age, $span)]`, with a single `$age < 0 || $age >= self::WINDOW_SECONDS` exclusion and no clamp.
>
> **The test asserted the wrong interruption count.** Blocks 4 and 5 are one contiguous ten-minute outage, so the correct count is 1, not 2 — `str_contains($html, '2')` was checking for a digit that a correct implementation never emits. It was also vacuous in the other direction: any `2` anywhere in the markup would have satisfied it, including the block count. Replaced with named numbers — `1 interruption in the last 60 minutes` and `2 blocks of 5 minutes without heartbeats` — plus a two-separate-outages case asserting `2 interruptions` (the distinction that justifies counting runs rather than blocks) and a clean-hour case asserting `no interruptions`.
>
> The interruption count is therefore a count of *runs* of gap blocks, and the label states both the run count and the total silent extent, because those are different facts and one long outage should not read like several short ones.

**Files:**
- Create: `master/src/Support/UptimeTrace.php`
- Test: `master/tests/skin_v2_uptime_trace_test.php`

- [ ] **Step 1: Write the failing test**

Create `master/tests/skin_v2_uptime_trace_test.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/UptimeTrace.php';

use Leikwan\Master\Support\UptimeTrace;

$now = 1785000000; // fixed clock: the bucketing must not depend on wall time

// A heartbeat in every 5-minute slot of the last hour -> 12 present blocks.
$dense = [];
for ($i = 0; $i < 60; $i++) {
    $dense[] = $now - ($i * 60);
}
$buckets = UptimeTrace::buckets($dense, $now);
if (count($buckets) !== 12) {
    throw new RuntimeException('expected 12 blocks, got ' . count($buckets));
}
foreach ($buckets as $i => $b) {
    if ($b !== 'present') {
        throw new RuntimeException('dense heartbeats must be present at block ' . $i);
    }
}

// No heartbeats at all -> fully hatched, never silently "healthy".
$empty = UptimeTrace::buckets([], $now);
if (count(array_unique($empty)) !== 1 || $empty[0] !== 'gap') {
    throw new RuntimeException('an empty series must render as a gap, not as uptime');
}

// A real outage must land in the right blocks. Drop the window 30-40 min ago.
$holed = [];
for ($i = 0; $i < 60; $i++) {
    if ($i >= 30 && $i < 40) {
        continue;
    }
    $holed[] = $now - ($i * 60);
}
$hb = UptimeTrace::buckets($holed, $now);
// Blocks are oldest-first: block k covers [now-60min+5k, now-60min+5(k+1)).
// 30-40 minutes ago == blocks 4 and 5.
foreach ([4, 5] as $gapIndex) {
    if ($hb[$gapIndex] !== 'gap') {
        throw new RuntimeException('block ' . $gapIndex . ' should be a gap');
    }
}
foreach ([0, 3, 6, 11] as $okIndex) {
    if ($hb[$okIndex] !== 'present') {
        throw new RuntimeException('block ' . $okIndex . ' should be present');
    }
}

// Heartbeats older than the window must not leak in.
$stale = UptimeTrace::buckets([$now - 7200], $now);
if ($stale[0] !== 'gap' || $stale[11] !== 'gap') {
    throw new RuntimeException('heartbeats outside the window must be ignored');
}

// Accessibility: a screen reader must not meet an empty element.
$html = UptimeTrace::render($hb, 'ok', 'Node uptime');
if (!preg_match('/aria-label="[^"]+"/', $html)) {
    throw new RuntimeException('the trace must carry a non-empty aria-label');
}
if (!str_contains($html, '2')) {
    throw new RuntimeException('the aria-label must state the number of interruptions');
}
if (substr_count($html, 'pg-trace-block') !== 12) {
    throw new RuntimeException('the trace must render 12 blocks');
}
// The final block carries the current state.
if (!str_contains($html, 'data-current="ok"')) {
    throw new RuntimeException('the trace must mark the current state');
}

// No JS, no build step.
if (str_contains($html, '<script') || str_contains($html, 'onclick')) {
    throw new RuntimeException('the trace is server-rendered and must not ship JS');
}

echo "ok\n";
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
php -d date.timezone=UTC master/tests/skin_v2_uptime_trace_test.php
```
Expected: `PHP Fatal error: Failed opening required '.../src/Support/UptimeTrace.php'`

- [ ] **Step 3: Implement**

Create `master/src/Support/UptimeTrace.php`:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Server-rendered uptime trace.
 *
 * Backed by node_heartbeats, which is dense, indexed on (node_id, created_at),
 * retained 24h, and independent of backend_mode -- unlike
 * bond_leg_reputation_events, which only the bond binary populates and which is
 * therefore empty for leigod_mode and true-MPTCP forwards.
 *
 * Granularity is node-level, not leg-level. The UI says so rather than implying
 * more precision than the data has.
 *
 * Pure functions: no DB, no clock, no I/O. The caller supplies both.
 */
final class UptimeTrace
{
    public const BLOCKS = 12;
    public const WINDOW_SECONDS = 3600;

    /**
     * Bucket heartbeat timestamps into fixed blocks, oldest first.
     *
     * @param array<int, int> $timestamps unix seconds
     * @return array<int, string> 'present' | 'gap'
     */
    public static function buckets(array $timestamps, int $nowTs): array
    {
        $span = intdiv(self::WINDOW_SECONDS, self::BLOCKS);
        $start = $nowTs - self::WINDOW_SECONDS;

        $buckets = array_fill(0, self::BLOCKS, 'gap');
        foreach ($timestamps as $ts) {
            $ts = (int) $ts;
            if ($ts < $start || $ts > $nowTs) {
                continue;
            }
            $index = intdiv($ts - $start, $span);
            if ($index >= self::BLOCKS) {
                $index = self::BLOCKS - 1;
            }
            $buckets[$index] = 'present';
        }

        return $buckets;
    }

    /**
     * @param array<int, string> $buckets
     */
    public static function render(array $buckets, string $currentState, string $label): string
    {
        $gaps = 0;
        $inGap = false;
        foreach ($buckets as $b) {
            if ($b === 'gap' && !$inGap) {
                $gaps++;
                $inGap = true;
            } elseif ($b !== 'gap') {
                $inGap = false;
            }
        }

        $summary = $gaps === 0
            ? $label . ': no interruptions in the last 60 minutes'
            : $label . ': ' . $gaps . ' interruption' . ($gaps === 1 ? '' : 's') . ' in the last 60 minutes';

        $html = '<span class="pg-trace" role="img" aria-label="'
            . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8')
            . '" data-current="' . htmlspecialchars($currentState, ENT_QUOTES, 'UTF-8') . '">';

        $last = count($buckets) - 1;
        foreach ($buckets as $i => $b) {
            $html .= '<i class="pg-trace-block" data-fill="' . $b . '"'
                . ($i === $last ? ' data-tip="current"' : '') . '></i>';
        }

        return $html . '</span>';
    }
}
```

- [ ] **Step 4: Run it and confirm it passes**

```bash
php -d date.timezone=UTC master/tests/skin_v2_uptime_trace_test.php
```
Expected: `ok`

- [ ] **Step 5: Style the trace**

Append to `master/public/assets/leikwan-v2.css`:

```css
/* 132 x 20px: 12 blocks of 5 minutes. Sized against --row-h (34px). */
.pg-trace {
  display: inline-grid;
  grid-template-columns: repeat(12, 1fr);
  gap: 1px;
  width: 132px;
  height: 20px;
  vertical-align: middle;
}
.pg-trace-block { display: block; height: 100%; }
.pg-trace-block[data-fill="present"] { background: var(--paper-line); }
.pg-trace-block[data-fill="gap"] {
  background: repeating-linear-gradient(
    45deg, transparent, transparent 2px,
    var(--paper-line) 2px, var(--paper-line) 3px
  );
}
.pg-trace[data-current="ok"]   .pg-trace-block[data-tip="current"] { background: var(--state-ok); }
.pg-trace[data-current="warn"] .pg-trace-block[data-tip="current"] { background: var(--state-warn); }
.pg-trace[data-current="down"] .pg-trace-block[data-tip="current"] { background: var(--state-down); }
```

- [ ] **Step 6: Commit**

```bash
git add master/src/Support/UptimeTrace.php master/public/assets/leikwan-v2.css master/tests/skin_v2_uptime_trace_test.php
git commit -m "feat: add server-rendered uptime trace backed by node heartbeats"
```

---

### Task 11: Trace helpers and wiring

The trace is the only part of this design that needs new markup inside the inline tables. Under the legacy skin the helpers must emit **nothing**, or the legacy tables grow an unstyled orphan column.

**Files:**
- Modify: `master/src/Support/PrecisionGridUi.php`
- Modify: `master/src/Controllers/AdminController.php` at L1351, L2293, L2911, L3311, L3546
- Modify: `master/src/Controllers/TenantController.php` at L1119, L1328, L1696
- Test: `master/tests/skin_v2_trace_helpers_test.php`

- [ ] **Step 1: Write the failing test**

Create `master/tests/skin_v2_trace_helpers_test.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/PrecisionGridUi.php';

use Leikwan\Master\Support\PrecisionGridUi;

// Under legacy the helpers emit nothing at all -- not an empty cell, which
// would leave an orphan column in the legacy tables.
if (PrecisionGridUi::traceHeader('legacy') !== '') {
    throw new RuntimeException('traceHeader must be empty under the legacy skin');
}
if (PrecisionGridUi::traceCell('legacy', '<span>x</span>') !== '') {
    throw new RuntimeException('traceCell must be empty under the legacy skin');
}

// Under v2 they emit a complete element each, so call sites never write markup.
$th = PrecisionGridUi::traceHeader('v2');
if (!str_starts_with($th, '<th') || !str_ends_with($th, '</th>')) {
    throw new RuntimeException('traceHeader must emit a complete <th>');
}
// The label states what the data is, not a vague "trend".
if (!str_contains($th, '60')) {
    throw new RuntimeException('the trace column label must state its window');
}

$td = PrecisionGridUi::traceCell('v2', '<span class="pg-trace"></span>');
if (!str_starts_with($td, '<td') || !str_ends_with($td, '</td>')) {
    throw new RuntimeException('traceCell must emit a complete <td>');
}
if (!str_contains($td, 'pg-trace')) {
    throw new RuntimeException('traceCell must carry the rendered trace');
}

// An unknown skin must not enable the column.
if (PrecisionGridUi::traceHeader('banana') !== '') {
    throw new RuntimeException('unknown skins must not enable the trace column');
}

// Header and cell must agree, or every table below them is off by one column.
$sameSkin = ['legacy' => 0, 'v2' => 1];
foreach ($sameSkin as $skin => $expected) {
    $h = PrecisionGridUi::traceHeader($skin) === '' ? 0 : 1;
    $c = PrecisionGridUi::traceCell($skin, 'x') === '' ? 0 : 1;
    if ($h !== $expected || $c !== $expected) {
        throw new RuntimeException('header/cell disagree for skin ' . $skin);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
php -d date.timezone=UTC master/tests/skin_v2_trace_helpers_test.php
```
Expected: `Error: Call to undefined method ...::traceHeader()`

- [ ] **Step 3: Implement the helpers**

Append to `master/src/Support/PrecisionGridUi.php`, before `groupForLabel()`:

```php
    /**
     * Trace column header.
     *
     * Emits nothing under the legacy skin so the two skins can coexist without
     * the legacy tables growing an unstyled orphan column. Call sites therefore
     * call a helper instead of writing markup, and never branch on the skin.
     */
    public static function traceHeader(string $skin): string
    {
        if ($skin !== 'v2') {
            return '';
        }

        return '<th class="pg-trace-col" scope="col">Node uptime &middot; 60 min</th>';
    }

    /**
     * Trace column cell. $traceHtml comes from UptimeTrace::render().
     */
    public static function traceCell(string $skin, string $traceHtml): string
    {
        if ($skin !== 'v2') {
            return '';
        }

        return '<td class="pg-trace-col">' . $traceHtml . '</td>';
    }
```

- [ ] **Step 4: Run it and confirm it passes**

```bash
php -d date.timezone=UTC master/tests/skin_v2_trace_helpers_test.php
```
Expected: `ok`

- [ ] **Step 5: Wire the 8 list views**

At each of the 8 `viewTabs()` views, add the header to the table's `<thead>` row and the cell to each `<tbody>` row, immediately **before** the current-value column.

Locate them:
```bash
grep -n "PrecisionGridUi::viewTabs(" master/src/Controllers/AdminController.php master/src/Controllers/TenantController.php
```
Expected: `AdminController.php:1351, 2293, 2911, 3311, 3546` and `TenantController.php:1119, 1328, 1696`.

In each view, resolve the skin once and render per row:

```php
$skin = $this->activeSkin($viewer);
// ... inside the header row:
$html .= PrecisionGridUi::traceHeader($skin);
// ... inside each body row, before the current-value cell:
$html .= PrecisionGridUi::traceCell(
    $skin,
    UptimeTrace::render(
        UptimeTrace::buckets($heartbeatTimestamps, time()),
        $rowState,
        'Node uptime'
    )
);
```

Fetch `$heartbeatTimestamps` once per page, not per row — one query for every node on the page, grouped in PHP:

```php
$stmt = $this->db->pdo()->prepare(
    'SELECT node_id, UNIX_TIMESTAMP(created_at) AS ts
       FROM node_heartbeats
      WHERE created_at >= (NOW() - INTERVAL 60 MINUTE)'
);
$stmt->execute();
$byNode = [];
foreach ($stmt->fetchAll() as $row) {
    $byNode[(int) $row['node_id']][] = (int) $row['ts'];
}
```

This uses the existing covering index `idx_node_heartbeats_node_created (node_id, created_at)`. Do **not** query inside the row loop — that is N+1 on the busiest pages in the console.

- [ ] **Step 6: Confirm column counts still line up**

Under legacy the tables must be byte-identical to before. Verify by diffing rendered output is not possible without a DB, so check structurally: every `traceHeader` call has a matching `traceCell` call in the same method.

```bash
grep -c "traceHeader" master/src/Controllers/AdminController.php master/src/Controllers/TenantController.php
grep -c "traceCell"   master/src/Controllers/AdminController.php master/src/Controllers/TenantController.php
```
Expected: `5` and `5` for Admin, `3` and `3` for Tenant.

- [ ] **Step 7: Run the full suite**

Expected: `PASS=243 FAIL=8` (still only the letter-spacing conflict).

- [ ] **Step 8: Commit**

```bash
git add master/src/Support/PrecisionGridUi.php master/src/Controllers/AdminController.php master/src/Controllers/TenantController.php master/tests/skin_v2_trace_helpers_test.php
git commit -m "feat: add skin-aware trace helpers and wire them into the 8 list views"
```

---

### Task 12: Repoint the brand mark

The repaired asset is already committed at `master/public/assets/brand/leikwan-mark.png` (26 KB, de-matted, 160px tall). What remains is to stop fetching the 1.2 MB original cross-host on every page view.

**Files:**
- Modify: `master/src/Controllers/BaseController.php:1067`
- Test: `master/tests/skin_v2_brand_asset_test.php`

- [ ] **Step 1: Write the failing test**

Create `master/tests/skin_v2_brand_asset_test.php`:

```php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$asset = $root . '/public/assets/brand/leikwan-mark.png';

if (!is_file($asset)) {
    throw new RuntimeException('the repaired brand mark is missing');
}

// The original was 1.2 MB for a 40px render. Keep it small.
$bytes = (int) filesize($asset);
if ($bytes > 60000) {
    throw new RuntimeException('brand mark regressed to ' . $bytes . ' bytes; keep it under 60 KB');
}

// It must be a real PNG.
if (substr((string) file_get_contents($asset, false, null, 0, 8), 1, 3) !== 'PNG') {
    throw new RuntimeException('brand mark is not a PNG');
}

// Served from this host: a cross-host fetch is a needless dependency for users
// behind the GFW.
if (!str_contains($base, "'BRAND_LOGO_URL', '/assets/brand/leikwan-mark.png'")) {
    throw new RuntimeException('brandLogoUrl must default to the local asset');
}
if (str_contains($base, 'buy.leikwanhost.com/assets/img/logo.png')) {
    throw new RuntimeException('the remote logo default must be gone');
}

echo "ok\n";
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
php -d date.timezone=UTC master/tests/skin_v2_brand_asset_test.php
```
Expected: `RuntimeException: brandLogoUrl must default to the local asset`

- [ ] **Step 3: Repoint the default**

Replace `brandLogoUrl()` at L1067:

```php
    protected function brandLogoUrl(): string
    {
        return (string) ($this->config->get('BRAND_LOGO_URL', '/assets/brand/leikwan-mark.png') ?? '/assets/brand/leikwan-mark.png');
    }
```

`BRAND_LOGO_URL` still overrides, so any deployment pointing at its own asset is unaffected.

- [ ] **Step 4: Confirm**

```bash
php -d date.timezone=UTC master/tests/skin_v2_brand_asset_test.php
```
Expected: `ok`

- [ ] **Step 5: Commit**

```bash
git add master/src/Controllers/BaseController.php master/tests/skin_v2_brand_asset_test.php
git commit -m "feat: serve the repaired brand mark from this host"
```

---

### Task 13: Legacy freeze

Forcing function 1. A parallel-skin migration is the same mechanism that produced the three existing layers: the switch gets added and the old skin is never deleted. Freezing the legacy stylesheets makes them a read-only fossil, so all new work is forced into v2 and the skins cannot drift.

**Files:**
- Create: `master/tests/skin_v2_legacy_freeze_test.php`

- [ ] **Step 1: Confirm the hashes still match**

The three hashes below were measured on branch `frontend-skin-v2` @ `2537f67` and are already written into the test in Step 2. Verify nothing drifted before you rely on them:

```bash
cd master/public/assets && sha256sum leikwan-ui.css operator-console.css signal-chamber.css
```
Expected:
```
e4e96ec31e2f6ea73f6db9285b44934bebbb43bcf01c6c65e45cde660e2df844  leikwan-ui.css
ddf094cc5b27fc4116a1063cdfc9992dbd9496948cf41fa293b37b8165569fb3  operator-console.css
f18f1b84725a388ef9689e1415d2009bf8d59c753dcd0bdb72e85e47b3a86d0f  signal-chamber.css
```

If any differ, someone edited a legacy stylesheet after this plan was written. Find out what changed and why before substituting the new hash — that edit is exactly what the freeze exists to stop.

- [ ] **Step 2: Write the test**

Create `master/tests/skin_v2_legacy_freeze_test.php`:

```php
<?php
declare(strict_types=1);

/**
 * The legacy stylesheets are frozen.
 *
 * Three design systems accumulated because each redesign was added as a new
 * layer with higher specificity rather than replacing the last one. Freezing the
 * old files means new work cannot be added to them, so v2 is the only place a
 * change can land -- and the two skins cannot drift apart during the migration.
 *
 * These files are deleted entirely in the teardown commit (Task 16), which also
 * deletes this test.
 */

$root = dirname(__DIR__);

$frozen = [
    'leikwan-ui.css'       => 'e4e96ec31e2f6ea73f6db9285b44934bebbb43bcf01c6c65e45cde660e2df844',
    'operator-console.css' => 'ddf094cc5b27fc4116a1063cdfc9992dbd9496948cf41fa293b37b8165569fb3',
    'signal-chamber.css'   => 'f18f1b84725a388ef9689e1415d2009bf8d59c753dcd0bdb72e85e47b3a86d0f',
];

foreach ($frozen as $name => $expected) {
    $path = $root . '/public/assets/' . $name;
    if (!is_file($path)) {
        throw new RuntimeException('frozen stylesheet is missing: ' . $name
            . ' -- if this is the teardown commit, delete this test too');
    }
    $actual = hash_file('sha256', $path);
    if ($actual !== $expected) {
        throw new RuntimeException(
            $name . ' is frozen and must not be edited.' . PHP_EOL
            . '  expected ' . $expected . PHP_EOL
            . '  actual   ' . $actual . PHP_EOL
            . 'Put the change in leikwan-v2.css instead.'
        );
    }
}

echo "ok\n";
```

- [ ] **Step 3: Confirm it passes, then confirm it actually bites**

```bash
php -d date.timezone=UTC master/tests/skin_v2_legacy_freeze_test.php
echo "/* tamper */" >> master/public/assets/leikwan-ui.css
php -d date.timezone=UTC master/tests/skin_v2_legacy_freeze_test.php
```
Expected: first `ok`, then `leikwan-ui.css is frozen and must not be edited.`

Revert the tamper:
```bash
git checkout master/public/assets/leikwan-ui.css
php -d date.timezone=UTC master/tests/skin_v2_legacy_freeze_test.php
```
Expected: `ok`

A freeze test that has never been seen to fail is not a freeze test.

- [ ] **Step 4: Commit**

```bash
git add master/tests/skin_v2_legacy_freeze_test.php
git commit -m "test: freeze the legacy stylesheets so all new work lands in v2"
```

---

### Task 14: Coverage manifest and default linkage

Forcing functions 2 and 3. The cutover is compelled by a test rather than by discipline: once every route is verified, remaining on the legacy default turns the suite red.

**Files:**
- Modify: `master/tests/fixtures/skin-v2-coverage.txt`
- Create: `master/tests/skin_v2_coverage_manifest_test.php`

- [ ] **Step 1: Write the test**

Create `master/tests/skin_v2_coverage_manifest_test.php`:

```php
<?php
declare(strict_types=1);

/**
 * Two forcing functions.
 *
 * 1. When no route is still 'pending', LEIKWAN_SKIN's default MUST be 'v2'.
 *    Finishing the migration and then leaving the flag on legacy fails here.
 * 2. Every GET route registered in Bootstrap must be classified in the manifest.
 *    A new route cannot silently skip the migration -- and since the legacy
 *    stylesheets are frozen, it can only honestly be marked 'verified'.
 */

$root = dirname(__DIR__);
$fixture = $root . '/tests/fixtures/skin-v2-coverage.txt';
$bootstrap = (string) file_get_contents($root . '/src/Bootstrap.php');
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');

$verdicts = [];
foreach (file($fixture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, 'allow-class ')) {
        continue;
    }
    if (!preg_match('/^route\s+(verified|pending|not-a-page)\s+(\S+)$/', $line, $m)) {
        throw new RuntimeException('malformed manifest line: ' . $line);
    }
    $verdicts[$m[2]] = $m[1];
}

// Every registered GET route must be classified.
preg_match_all("/router->add\('GET',\s*'([^']+)'/", $bootstrap, $routes);
$unclassified = [];
foreach (array_unique($routes[1]) as $path) {
    if (!isset($verdicts[$path])) {
        $unclassified[] = $path;
    }
}
if ($unclassified !== []) {
    sort($unclassified);
    throw new RuntimeException(
        count($unclassified) . " GET route(s) are not classified in the manifest:\n  "
        . implode("\n  ", $unclassified)
        . "\nAdd 'route verified|pending|not-a-page <path>' lines."
    );
}

// Manifest entries for routes that no longer exist are stale.
$stale = array_diff(array_keys($verdicts), array_unique($routes[1]));
if ($stale !== []) {
    throw new RuntimeException('manifest lists routes that no longer exist: ' . implode(', ', $stale));
}

// The linkage: no pending routes means the cutover is due.
$pending = array_keys($verdicts, 'pending', true);
$defaultsToLegacy = str_contains($base, "config->get('LEIKWAN_SKIN', 'legacy')");

if ($pending === [] && $defaultsToLegacy) {
    throw new RuntimeException(
        'every route is verified, so LEIKWAN_SKIN must now default to v2. '
        . 'Change the default in BaseController::activeSkin().'
    );
}
if ($pending !== [] && !$defaultsToLegacy) {
    throw new RuntimeException(
        count($pending) . ' route(s) are still pending, so the default must stay legacy: '
        . implode(', ', array_slice($pending, 0, 10))
    );
}

echo 'ok (' . count($verdicts) . ' routes, ' . count($pending) . " pending)\n";
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
php -d date.timezone=UTC master/tests/skin_v2_coverage_manifest_test.php
```
Expected: `RuntimeException: 93 GET route(s) are not classified in the manifest:` followed by the list.

- [ ] **Step 3: Populate the manifest**

Append one `route` line per path from that output to `master/tests/fixtures/skin-v2-coverage.txt`. Classify honestly:

- `not-a-page` — JSON/agent/artifact endpoints that render no HTML shell.
- `pending` — every HTML page. They start unverified; Task 16 walks them.

Example shape:

```
route pending /
route pending /login
route pending /register
route pending /knowledgebase
route pending /admin
route pending /tenant
route not-a-page /artifacts/versions.json
```

- [ ] **Step 4: Confirm**

```bash
php -d date.timezone=UTC master/tests/skin_v2_coverage_manifest_test.php
```
Expected: `ok (93 routes, N pending)`

- [ ] **Step 5: Commit**

```bash
git add master/tests/fixtures/skin-v2-coverage.txt master/tests/skin_v2_coverage_manifest_test.php
git commit -m "test: link the cutover default to route coverage so it cannot be skipped"
```

---

### Task 15: Rewrite the 14 legacy design tests

These assert exact hex values, exact hrefs including `?v=20260725`, and exact HTML strings. A stylesheet rewrite fails them by construction. They are rewritten to contract level — which is what they should have asserted originally, and their current form is why three design layers could accumulate while the suite stayed green.

**Files (all Modify):**

```
master/tests/signal_chamber_homepage_regression_test.php
master/tests/precision_grid_responsive_accessibility_test.php
master/tests/readable_sheet_design_system_regression_test.php
master/tests/precision_grid_public_pages_regression_test.php
master/tests/precision_grid_page_templates_regression_test.php
master/tests/precision_grid_design_system_regression_test.php
master/tests/leikwan_ui_asset_regression_test.php
master/tests/homepage_positioning_regression_test.php
master/tests/focused_tenant_resource_views_regression_test.php
master/tests/focused_transport_endpoints_views_regression_test.php
master/tests/focused_slave_nodes_views_regression_test.php
master/tests/focused_admin_resource_views_regression_test.php
master/tests/all_tables_readable_sheet_regression_test.php
master/tests/core_mobile_resource_rows_regression_test.php
```

**Triage policy — apply to every assertion in every file:**

| Assertion kind | Before | After |
| --- | --- | --- |
| Token | `str_contains($css, '--page:#eef0ed')` | assert the token *is defined*: `str_contains($css, '--page:')` |
| Markup | full HTML string match | assert the *class is emitted*: `str_contains($html, 'pg-status-dot')` |
| Asset | `'/assets/leikwan-ui.css?v=20260725'` | assert *a versioned stylesheet* is referenced: `preg_match('#/assets/[a-z0-9-]+\.css\?v=\d+#', $base)` |
| Motion / a11y guard | keep verbatim | **keep and strengthen** — these are genuine contracts |

- [ ] **Step 1: Fix the letter-spacing conflict first**

This is the one blocking the suite. In `master/tests/precision_grid_responsive_accessibility_test.php`, replace the global ban at L54-56:

```php
if (preg_match('/letter-spacing\s*:\s*-/i', $css) === 1) {
    fail('negative letter spacing is forbidden');
}
```

with a scoped one. Tight display tracking is a deliberate part of the v2 headings; negative tracking in body and table text is the actual legibility problem:

```php
// Negative tracking is legitimate on display headings, where it is a deliberate
// part of the wide Archivo setting. It is a legibility problem in body and table
// text, so it stays banned there.
preg_match_all('/([^{}]+)\{([^}]*letter-spacing\s*:\s*-[^}]*)\}/i', $css, $tight, PREG_SET_ORDER);
$displaySelectors = ['h1', 'h2', 'h3', '.pg-page-header', '.site-brand', '.signal-hero'];
foreach ($tight as $rule) {
    $selector = trim($rule[1]);
    $isDisplay = false;
    foreach ($displaySelectors as $allowed) {
        if (str_contains($selector, $allowed)) {
            $isDisplay = true;
            break;
        }
    }
    if (!$isDisplay) {
        fail('negative letter spacing is forbidden outside display headings: ' . $selector);
    }
}
```

- [ ] **Step 2: Confirm the suite is clean**

```bash
php -d date.timezone=UTC master/tests/precision_grid_responsive_accessibility_test.php
```
Expected: `ok`

Run the full suite. Expected: `FAIL=7` — back to the pre-existing baseline. **From here on, any failure above 7 is yours.**

- [ ] **Step 3: Add the v2 stylesheet to every design test's source set**

Each of the 14 files reads some combination of the three legacy stylesheets. Add `leikwan-v2.css` to each `$css` concatenation so the contracts are asserted against the skin that will actually ship. For example, in `precision_grid_design_system_regression_test.php:12-14`:

```php
$css = (string) file_get_contents($root . '/public/assets/leikwan-ui.css')
    . (string) file_get_contents($root . '/public/assets/operator-console.css')
    . (string) file_get_contents($root . '/public/assets/signal-chamber.css')
    . (string) file_get_contents($root . '/public/assets/leikwan-v2.css');
```

- [ ] **Step 4: Apply the triage table, file by file**

Work through all 14. Concretely, in `precision_grid_design_system_regression_test.php:16-20`, replace the hex-locked token list:

```php
foreach (['--page:#eef0ed', '--surface:#fff', '--ink:#17201c', '--healthy:#139b68', '--danger:#c84538'] as $token) {
```

with a definition check that survives palette changes:

```php
// Assert the token exists, not its value. Locking hex values is what let three
// design systems accumulate while this suite stayed green.
foreach (['--page', '--surface', '--ink', '--healthy', '--danger'] as $token) {
    if (!preg_match('/' . preg_quote($token, '/') . '\s*:/', $css)) {
        throw new RuntimeException('missing token ' . $token);
    }
}
```

And in `leikwan_ui_asset_regression_test.php:15-24`, replace the two exact-href loops with:

```php
// A versioned stylesheet must be referenced. Which one, and at which version,
// is a deployment detail -- not a contract worth failing the suite over.
if (!preg_match('#/assets/[a-z0-9-]+\.css\?v=\d+#', $base)) {
    throw new RuntimeException('shell references no versioned stylesheet');
}
```

- [ ] **Step 5: Keep the motion guards verbatim**

In `signal_chamber_homepage_regression_test.php:42-49`, change nothing. `prefers-reduced-motion`, `IntersectionObserver`, `document.visibilityState` and the DPR clamp are real accessibility contracts, and the visibility-gated frame loop assertion at L47 is the only thing stopping the hero animation from burning battery in a background tab.

- [ ] **Step 6: Run the full suite**

Expected: `PASS=250 FAIL=7`.

- [ ] **Step 7: Commit**

```bash
git add master/tests
git commit -m "test: rewrite the design regression suite to assert contracts, not strings"
```

---

### Task 16: Cutover and teardown

- [ ] **Step 1: Walk the manifest**

Start the app with the override available and visit every `pending` route as an administrator with `?skin=v2`. For each one that renders correctly, flip `pending` → `verified` in `master/tests/fixtures/skin-v2-coverage.txt`.

Check on each page: the sheet floats on the dark shell; the page header sits on the shell, not the sheet; status cells show shape + colour + text; tables have no row rules but hover and 8-row banding track the line; the brand plate renders the mark at 40px; nothing overflows below 900px.

- [ ] **Step 2: Let the test force the cutover**

When the last `pending` becomes `verified`:

```bash
php -d date.timezone=UTC master/tests/skin_v2_coverage_manifest_test.php
```
Expected: `RuntimeException: every route is verified, so LEIKWAN_SKIN must now default to v2.`

That failure is the mechanism working. Change the default in `activeSkin()` — note it now runs through `normalizeSkin()`, so both the argument and the null-coalesce fallback move together:

```php
        $configured = self::normalizeSkin(
            (string) ($this->config->get('LEIKWAN_SKIN', 'v2') ?? 'v2'),
            'v2'
        );
```

Then update `skin_flag_contract_test.php`. Task 1 was reworked after a mutation check showed the original source-text version stayed green with the admin gate deleted, so this test now calls the method rather than reading it. Two assertions encode the migration-era default and must flip:

```php
is('legacy', probe(null)->skin($admin), 'an unset flag must render the legacy skin');
```
becomes
```php
is('v2', probe(null)->skin($admin), 'an unset flag must render v2 now that cutover is done');
```

and
```php
is('legacy', probe('banana')->skin($admin), 'an unrecognised flag must fall back, not render an undefined skin');
is('legacy', probe('')->skin($admin), 'an empty flag must fall back');
```
become `is('v2', ...)` with the same messages — the fallback target changed, the fallback behaviour did not.

Leave everything else alone. The admin gate, the stamped-override kill switch and the hostile-input cases are all independent of which skin is the default, and the `substr_count($source, "config->get('LEIKWAN_SKIN'") !== 1` guard still holds because the flag is still read in exactly one place.

Re-run it and confirm the total-function sweep at the bottom still passes — it asserts only that the return is one of the two known literals, so it should be untouched by the flip. If it fails, the default change leaked somewhere it shouldn't have.

- [ ] **Step 3: Commit the cutover separately from the teardown**

```bash
git add master/tests/fixtures/skin-v2-coverage.txt master/src/Controllers/BaseController.php master/tests/skin_flag_contract_test.php
git commit -m "feat: default to the v2 skin now that every route is verified"
```

Keeping this its own commit means the cutover can be reverted without also reverting the teardown.

- [ ] **Step 4: Tear the legacy skin out**

Delete, in one commit:

```bash
git rm master/public/assets/leikwan-ui.css \
       master/public/assets/operator-console.css \
       master/public/assets/signal-chamber.css \
       master/tests/skin_v2_legacy_freeze_test.php \
       master/tests/skin_v2_coverage_manifest_test.php \
       master/tests/fixtures/skin-v2-coverage.txt
```

Then in `master/src/Controllers/BaseController.php`:
- delete `activeSkin()` and both `if ($skin === 'v2')` guards, keeping the v2 heredocs as the only shells
- delete the two legacy heredocs

And in `master/src/Support/PrecisionGridUi.php`, drop the `$skin` parameter from `traceHeader()` / `traceCell()` and their early returns, updating the 8 call sites and `skin_v2_trace_helpers_test.php` (its legacy-skin assertions no longer describe anything).

Delete `skin_v2_class_vocabulary_test.php`'s allowlist dependency only if the fixture is gone — otherwise keep both.

**The three `git rm`s break 13 other files that still point at those paths. Fix them in the same commit or the suite goes red.**

Nine tests read the deleted stylesheets by path. Task 15 Step 3 *added* `leikwan-v2.css` to each of these concatenations but deliberately left the legacy reads in place, because at that point the files still existed. Now strip the legacy reads and leave only the v2 one — after which each `$css` is a single `file_get_contents`, not a concatenation:

```
master/tests/core_mobile_resource_rows_regression_test.php
master/tests/homepage_positioning_regression_test.php
master/tests/leikwan_ui_asset_regression_test.php
master/tests/precision_grid_design_system_regression_test.php
master/tests/precision_grid_page_templates_regression_test.php
master/tests/precision_grid_public_pages_regression_test.php
master/tests/precision_grid_responsive_accessibility_test.php
master/tests/readable_sheet_design_system_regression_test.php
master/tests/signal_chamber_homepage_regression_test.php
```

Do not skip a file because it currently passes. `file_get_contents` on a missing path emits a warning and returns `false`, which concatenates to `''` — so a test reading three deleted sheets does not fail, it silently asserts against less and less CSS until it is asserting against nothing. That is precisely the rot this project exists to end, and it would arrive disguised as a green suite.

Four sources `<link>` them. Each needs its `<link>` tags reduced to the single v2 stylesheet:

```
master/public/index.php:42
master/public/install.php:366-367
master/src/Controllers/BaseController.php:1235-1236,1341-1342
master/src/Controllers/HealthController.php:30-31
```

`install.php` and `HealthController.php` are the two easy ones to miss: neither goes through `page()` or `publicPage()`, so neither is reachable from the shell rewrite in Tasks 6 and 7, and the installer is not a route in `Bootstrap.php` so it will not appear in the coverage manifest either. Both render real pages. Check them by eye after the teardown — the installer especially, since it is the first thing a new deployment sees and nothing in the suite renders it.

- [ ] **Step 5: Verify the exit criteria**

```bash
grep -rn "!important" master/public/assets/*.css | wc -l   # expect 0
ls master/public/assets/*.css                              # expect only leikwan-v2.css
grep -rn "LEIKWAN_SKIN" master/src/ | wc -l                # expect 0

# No dangling reference to a deleted stylesheet anywhere -- tests, sources,
# deploy templates. This is the check that catches the silent-'' failure above.
grep -rn "leikwan-ui\.css\|operator-console\.css\|signal-chamber\.css" \
    master/ deploy/ | wc -l                                # expect 0
```

Run the full suite. Expected: `FAIL=7` — the pre-existing baseline, unchanged from start to finish.

Then confirm the count did not merely stay flat by luck: `PASS` must be within one or two of what Task 15 Step 6 reported, allowing for the freeze and manifest tests deleted in Step 4. A `PASS` that dropped by nine is nine tests that stopped running, not nine tests that passed.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor: delete the legacy skin, its flag and its migration controls"
```

**Done means:** one stylesheet, zero `!important`, one radius spec, one font system, one status encoding, and no code path that can render the legacy skin.

---

## Notes for whoever executes this

**Tasks 1-15 are safe to land incrementally.** Everything before Task 16 Step 2 ships with `LEIKWAN_SKIN` defaulting to `legacy`, so no user sees a change. Only the cutover is user-visible, and it is one revertible commit.

**The 8th failure is expected between Task 5 and Task 15 Step 1.** It is `precision_grid_responsive_accessibility_test.php` and nothing else. If a different test fails, stop and fix it rather than carrying it forward.

**Do not fix the 7 pre-existing failures.** Six need a MySQL PDO driver; one calls a method that no longer exists. Both are out of scope and neither is design-related.

**The trace query must stay out of the row loop.** One query per page grouped in PHP, not one per row — the list views it lands on are the busiest pages in the console.

**Two tasks defer their CSS, and Task 4/5 must collect it.** Tasks 8 and 10 were executed before `leikwan-v2.css` existed, so neither could append to it without taking over the stylesheet task's job. Both shipped their PHP and their tests; their CSS blocks are still sitting in this document unwritten — the status shapes in Task 8 Step 4 and the `.pg-trace` rules in Task 10 Step 5. Until they land, `data-shape` and the whole trace render unstyled. Task 2's coverage test is the backstop: `pg-trace` and `pg-trace-block` are in its class list, so the suite stays red until the rules exist. `data-shape` is an attribute rather than a class, so nothing catches that one but this note.

**One spec item has no task, deliberately.** The spec's per-leg `bond_leg_reputation_events` enhancement targets a forward detail page. There is no per-forward `GET` route in `Bootstrap.php` — forwards live in list views plus an edit form — so the enhancement has nothing to attach to. Building the page is a feature, not a re-skin. It has been moved to the spec's follow-ups; do not add it here.

### Task 8a: Stop the call sites flattening severity

**This section replaces an earlier note that deferred the problem. That deferral was wrong, and the reasoning is worth keeping because it is a trap.**

The original note said `AdminController.php:3239` collapses every non-`active` node into `'error'`, and left it unfixed on the grounds that it was "a data question, not a design one" — that choosing what `provisioning` and `deleting` should look like was a spec change. Two things were wrong with that.

**The vocabulary was never an open question.** It is enumerated in the codebase, and in one case by the admin UI itself. `AdminController.php:1366` renders a `<select>` whose options *are* the `users.status` vocabulary — `active`, `pending_verification`, `suspended`. `AdminController.php:2313` and `TenantController.php:1089` do the same for `clients.status` — `active`, `disabled`. `ProvisioningService` writes `services.status` as `suspended`, `terminated` and `traffic_suspended`, and the column defaults to `pending`. For `nodes.status`, `ProvisioningService` selects on `status = 'active' OR status = 'degraded'` in a dozen places, which settles `degraded` outright: a degraded node is *still carrying traffic*, so it cannot be an error. `provisioning` turned out not to be a real value at all — it appears only in a docblock. So there was no spec decision to make, only research nobody had done.

**The node case was the mild one.** A code review of Task 8 found the sidecar sites are worse, because they fail in the opposite and more dangerous direction. `BaseController::sidecarPointStatusInfo()` computes a three-way classification — `success`, `info`, `danger` — and four call sites reduced it with `$status['class'] === 'success' ? 'ok' : 'warning'`. That maps `danger` **and** `info` both to `warning`, so a *disabled or erroring* endpoint drew the same warning triangle as one that had merely never been installed. The classifier had already done the work; the ternary threw the answer away and understated the severity.

Both are instances of one defect, and it was in 13 places, not 3 — five entities, all reduced to two states. The most common wrong signal was `services.status = 'pending'`, the column default, rendering as a red error square on every newly created service; the second was a tenant who had not yet clicked their verification email.

**What landed.** Two mappings, each in exactly one place:

- `sidecarPointStatusInfo()` now returns the severity it computed alongside the class and label, so no caller has to re-derive it: `success` → `ok`, `info` → `warning`, `danger` → `error`.
- `BaseController::resourceStatusState()` maps a raw status string for all four entities. `active` → `ok`; `pending`, `pending_verification`, `deleting`, `degraded` → `warning`; `suspended`, `traffic_suspended`, `terminated`, `inactive`, `disabled` → `error`; anything else → `unknown`.

Every arm is a value the code demonstrably writes or reads — the Task 8 review's lesson about unreachable `match` arms applies here too, and the vocabulary is tight on purpose. An unrecognised status returns `unknown` and renders as the `unknown` shape rather than being forced into a level: guessing `ok` hides a real problem, and guessing `error` cries wolf. That is also what finally makes Task 8's `default => 'unknown'` arm reachable — before this, no call site could ever produce it.

**Test:** `master/tests/skin_v2_status_severity_test.php`. It asserts the mapping through the real input shapes rather than against hand-written class names, asserts the *distinctions* directly (so collapsing any pair fails even if every individual case were updated to match), and closes the loop through `PrecisionGridUi::status()` to prove the three levels reach three distinct shapes — a correct state string the component did not recognise would render as `unknown` and undo the whole change silently. It also carries two source-text guards, which are the only way to notice a *new* flattening ternary appearing at a call site, since behaviour cannot observe the absence of one.

**Two ternaries were deliberately left.** `AdminController.php:1317` (`$verified ? 'ok' : 'warning'`) is a boolean, and `TenantController.php:224` is a composite of a forward's `enabled` flag and its service status. Both are genuinely two-state; neither is a flattened classification.
