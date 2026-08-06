# Tenant User Guide Screenshots Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the tenant knowledgebase into screenshot-led, executable user guides and ship a safe AI setup handoff without exposing credentials or overwriting administrator-edited articles.

**Architecture:** Keep the existing eight topic keys and multilingual resource layout. Add a local, non-production screenshot fixture that uses the current panel CSS and user-facing labels, capture deterministic PNG assets from it at desktop and mobile widths, embed them through sanitized `<figure>` blocks, and refresh existing database articles only when their body matches the prior built-in seed fingerprint.

**Tech Stack:** PHP 8+, existing `KnowledgebaseService`, `KnowledgebaseHtmlSanitizer`, MySQL/SQLite migrations, HTML/CSS resource files, gstack `browse.exe` with Chromium screenshots, focused PHP regression tests.

---

### Task 1: Add a local tenant screenshot fixture

**Files:**
- Create: `master/tests/fixtures/tenant_user_guide_fixture.php`
- Test: `master/tests/tenant_user_guide_fixture_test.php`

- [ ] **Step 1: Write the fixture contract test**

Create a CLI test that loads the fixture source as UTF-8 and asserts all six views and their current panel labels are present:

```php
<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/fixtures/tenant_user_guide_fixture.php');
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "tenant screenshot fixture missing\n");
    exit(1);
}

foreach ([
    'overview', 'forward-form', 'client-install', 'forward-result', 'diagnostics', 'target-endpoint',
    'Forward Route', 'Target Host', 'Target Port', 'Transport', 'Run Diagnostics',
    'View Diagnostics', 'Transport Listen Port', 'Alternate Listen Port', 'CF Listen Port',
] as $required) {
    if (!str_contains($source, $required)) {
        fwrite(STDERR, "fixture missing: {$required}\n");
        exit(1);
    }
}

foreach (['password', 'bearer', 'private key', 'real_ip', '198.51.100.10'] as $forbidden) {
    if (str_contains(strtolower($source), strtolower($forbidden))) {
        fwrite(STDERR, "fixture contains forbidden secret marker: {$forbidden}\n");
        exit(1);
    }
}

echo "tenant_user_guide_fixture_test: OK\n";
```

- [ ] **Step 2: Run the contract test and confirm it fails**

Run `php master/tests/tenant_user_guide_fixture_test.php`.
Expected: FAIL with `tenant screenshot fixture missing`.

- [ ] **Step 3: Implement the fixture views**

Implement `tenant_user_guide_fixture.php` as a query-driven PHP page. It must:

1. Load `/master/public/assets/leikwan-ui.css`, `/master/public/assets/signal-chamber.css`, and `/master/public/assets/bridge-modern.css` using paths relative to the fixture URL.
2. Accept `view` from this allowlist only: `overview`, `forward-form`, `client-install`, `forward-result`, `diagnostics`, `target-endpoint`.
3. Render the same tenant navigation labels and form field labels currently emitted by `TenantController`, with only demo values such as `demo.example.invalid`, `8443`, and `123`.
4. Render a visible `DEMO DATA` notice and never render secrets, real addresses, install commands, or real service names.
5. Keep all controls inert, because this page exists only to capture documentation evidence. Add `data-guide-view` to `<main>` so the capture command can assert the selected view.

The six views must contain these observable states:

```text
overview: active package, Step 2 current, Create a forward
forward-form: Forward Route, Target Host, Target Port, Local Listen Port, Transport, Create Forward
client-install: Install tab, Windows/Android/Linux cards, Copy Install Command label without the command value
forward-result: Local Listen 123, Target demo.example.invalid:8443, Running
diagnostics: Run Diagnostics button, View Diagnostics dialog, healthy link summary
target-endpoint: Name, Sidecar Host, Transport Listen Port, Alternate Listen Port, CF Listen Port, Save Transport Endpoint
```

- [ ] **Step 4: Run the contract test and PHP lint**

Run:

```text
php master/tests/tenant_user_guide_fixture_test.php
php -l master/tests/fixtures/tenant_user_guide_fixture.php
```

Expected: both commands exit 0 and print the fixture test success line.

- [ ] **Step 5: Commit the fixture**

```text
git add master/tests/fixtures/tenant_user_guide_fixture.php master/tests/tenant_user_guide_fixture_test.php
git commit -m "test: add tenant guide screenshot fixture"
```

### Task 2: Capture and validate real UI screenshots

**Files:**
- Create: `master/public/assets/knowledgebase/screenshots/tenant/01-overview.png`
- Create: `master/public/assets/knowledgebase/screenshots/tenant/02-forward-form.png`
- Create: `master/public/assets/knowledgebase/screenshots/tenant/03-client-install.png`
- Create: `master/public/assets/knowledgebase/screenshots/tenant/04-forward-result.png`
- Create: `master/public/assets/knowledgebase/screenshots/tenant/05-diagnostics.png`
- Create: `master/public/assets/knowledgebase/screenshots/tenant/06-target-endpoint.png`
- Test: `master/tests/knowledgebase_screenshot_assets_test.php`

- [ ] **Step 1: Add asset metadata assertions before capturing**

The test must enumerate the six fixed PNG paths, require a non-zero file size, `getimagesize()` width/height between 640 and 2400 pixels, and reject any image path outside `public/assets/knowledgebase/screenshots/tenant/`.

- [ ] **Step 2: Start the fixture server**

From the worktree root run the PHP built-in server with the worktree as document root:

```powershell
$server = Start-Process -FilePath php -ArgumentList '-S','127.0.0.1:8129','-t','.' -WorkingDirectory (Get-Location) -PassThru
$server.Id | Set-Content .qa-tenant-guide-server.pid
```

The fixture URL is `http://127.0.0.1:8129/master/tests/fixtures/tenant_user_guide_fixture.php?view=<view>`.

- [ ] **Step 3: Capture desktop screenshots with gstack browse**

Use `C:\Users\frankniubi\.codex\skills\gstack\browse\dist\browse.exe`:

```text
goto http://127.0.0.1:8129/master/tests/fixtures/tenant_user_guide_fixture.php?view=overview
viewport 1440x960
screenshot master/public/assets/knowledgebase/screenshots/tenant/01-overview.png
```

Repeat for the five other `view` values and the matching filenames. Use `text` or `is visible "[data-guide-view]"` before each capture to prove the requested view loaded.

- [ ] **Step 4: Capture mobile screenshots without replacing the desktop evidence**

Use a second filename suffix under `.qa-screenshots/mobile/` for the mobile QA evidence, not as published assets:

```text
viewport 390x844
screenshot .qa-screenshots/mobile/01-overview.png
```

Repeat for all six views. Published images stay at the stable desktop dimensions; mobile captures are used to inspect overflow and text wrapping.

- [ ] **Step 5: Inspect every image and run asset tests**

Use `view_image` for all twelve PNGs. Confirm no text is clipped, no dynamic secret is visible, and the active panel state is obvious. Then run:

```text
php master/tests/knowledgebase_screenshot_assets_test.php
```

Expected: six published assets pass size, PNG, and path checks.

- [ ] **Step 6: Stop the local server and commit assets**

```powershell
$pid = Get-Content .qa-tenant-guide-server.pid
Stop-Process -Id ([int]$pid) -Force
Remove-Item .qa-tenant-guide-server.pid
```

```text
git add master/public/assets/knowledgebase/screenshots/tenant master/tests/knowledgebase_screenshot_assets_test.php
git commit -m "docs: add tenant guide screenshots"
```

### Task 3: Rewrite multilingual user-facing articles

**Files:**
- Modify: `master/resources/knowledgebase/topics.php`
- Modify: `master/resources/knowledgebase/en/*.html`
- Modify: `master/resources/knowledgebase/zh-Hans/*.html`
- Modify: `master/resources/knowledgebase/zh-TW/*.html`
- Modify: `master/src/Controllers/AuthController.php:796-1030`
- Test: `master/tests/knowledgebase_content_catalog_test.php`

- [ ] **Step 1: Extend the content catalog test for screenshots and static tenant links**

Keep the existing 24-article, heading-role, Mermaid topology, safe-stop, and locale terminology checks. Add these exact assertions for every non-glossary core article:

```php
$images = catalogElementsByTag($body, 'img');
assertCatalog($images !== [], $topicKey . ': must include an operation screenshot');
foreach ($images as $image) {
    $src = $image->getAttribute('src');
    assertCatalog((bool) preg_match('~^/assets/knowledgebase/screenshots/tenant/[0-9]{2}-[a-z-]+\.png$~', $src), $topicKey . ': invalid screenshot path');
    assertCatalog(trim($image->getAttribute('alt')) !== '', $topicKey . ': screenshot alt is required');
    assertCatalog($image->hasAttribute('width') && $image->hasAttribute('height'), $topicKey . ': screenshot dimensions are required');
}
```

Allow only these static user entry links in addition to existing knowledgebase links: `/tenant`, `/tenant/getting-started`, `/tenant/forwards`, `/tenant/clients?view=install`, and `/tenant-transport-endpoints`. Reject external tracking URLs and dynamic service IDs.

- [ ] **Step 2: Run the catalog test to record the expected failure**

Run `php master/tests/knowledgebase_content_catalog_test.php`.
Expected: FAIL because the current articles contain no operation screenshots.

- [ ] **Step 3: Rewrite the five core articles in English, zh-Hans, and zh-TW**

For each locale, keep one Mermaid overview and replace the prose with the seven-part step block from the approved spec. Embed screenshots using this exact shape:

```html
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/02-forward-form.png" width="1440" height="960" alt="Forward 表单：选择 Forward Route，填写 Target Host、Target Port 和 Transport">
  <figcaption>演示数据：截图中的域名、端口和 ID 仅用于说明位置。</figcaption>
</figure>
```

Use the actual UI labels from `TenantController`, show sample values as code, and put a visible safe-stop paragraph after every failure branch. Do not claim that `Connected`, `Running`, or a successful port probe alone proves the target application handled the request.

- [ ] **Step 4: Rewrite the three advanced articles and glossary**

Update `connection-modes-single-race-bond`, `bond-sidecar-setup`, and `glossary` to explain what a tenant sees and when to ask an administrator. The sidecar article must mention shared endpoint read-only behavior and all three listen ports. The glossary must not imply that the tenant can directly connect to a Sidecar.

- [ ] **Step 5: Update topic metadata and reader image styling**

Update titles and excerpts in `topics.php` to match the rewritten article headings. Add to `knowledgebaseStyles()` in `AuthController.php`:

```css
.kb-screenshot-figure { margin: 8px 0; display: grid; gap: 8px; }
.kb-screenshot-figure img { display: block; width: 100%; height: auto; max-width: 100%; border: 1px solid #dfe5e1; border-radius: 12px; }
.kb-screenshot-figure figcaption { color: var(--muted); font-size: .875rem; }
```

Keep the existing Mermaid responsive rules and add a mobile rule that preserves readable captions and prevents image overflow.

- [ ] **Step 6: Regenerate the multilingual seed and run the catalog test**

Run:

```text
php master/scripts/build_knowledgebase_seed.php
php master/tests/knowledgebase_content_catalog_test.php
```

Expected: the generator exits 0, the seed is byte-stable on a second run, and the catalog reports 24 localized articles with valid screenshots and links.

- [ ] **Step 7: Commit the content and reader styling**

```text
git add master/resources/knowledgebase master/migrations/20260729_seed_multilingual_knowledgebase.sql master/src/Controllers/AuthController.php master/tests/knowledgebase_content_catalog_test.php
git commit -m "docs: rewrite tenant knowledgebase as screenshot guide"
```

### Task 4: Add a guarded article refresh migration

**Files:**
- Create: `master/migrations/20260806_refresh_tenant_user_guides.sql`
- Modify: `master/tests/knowledgebase_migration_behavior_test.php`
- Test: `master/tests/knowledgebase_refresh_migration_test.php`

- [ ] **Step 1: Write the migration behavior test fixtures**

Create an SQLite fixture with the multilingual tables and two English articles for the same topic: one containing the exact old seed body and one containing `Administrator edited body`. Assert that applying the migration changes only the old seed article and leaves the administrator-edited article byte-for-byte unchanged.

- [ ] **Step 2: Run the new migration test to confirm it fails**

Run `php master/tests/knowledgebase_refresh_migration_test.php`.
Expected: FAIL because `20260806_refresh_tenant_user_guides.sql` does not exist.

- [ ] **Step 3: Generate the guarded update statements**

For every updated topic and locale, generate an `UPDATE knowledgebase_articles AS article INNER JOIN knowledgebase_topics AS topic ...` statement that sets title, excerpt, body, status, published timestamp, and updated timestamp only when:

```sql
topic.topic_key = '<topic>'
AND article.locale = '<locale>'
AND SHA2(article.body_html, 256) = '<old-resource-sha256>'
```

Use the old resource hash from `git show refs/heads/master:<path>` and the new body from the versioned resource file. Do not use `UPDATE` without the topic, locale, and hash predicates. Keep the SQL valid for the project MySQL version and avoid `ON DUPLICATE KEY` or destructive deletes.

- [ ] **Step 4: Run the migration behavior test**

Run:

```text
php master/tests/knowledgebase_refresh_migration_test.php
php master/tests/knowledgebase_migration_behavior_test.php
```

Expected: the guarded update test passes, the existing 25-article migration behavior remains passing, and the edited fixture remains unchanged.

- [ ] **Step 5: Commit the refresh migration and tests**

```text
git add master/migrations/20260806_refresh_tenant_user_guides.sql master/tests/knowledgebase_refresh_migration_test.php master/tests/knowledgebase_migration_behavior_test.php
git commit -m "db: refresh tenant guides without overwriting edits"
```

### Task 5: Publish the AI setup handoff

**Files:**
- Create: `docs/user/AI-SETUP-HANDOFF.zh-CN.md`
- Test: `master/tests/ai_setup_handoff_regression_test.php`

- [ ] **Step 1: Write the handoff regression test**

Require the handoff to include the exact tenant paths, success markers, redaction rules, and stop phrases:

```php
foreach ([
    '/tenant/getting-started', '/tenant/clients?view=install', '/tenant/forwards',
    'Run Diagnostics', 'View Diagnostics', '127.0.0.1', 'Target Host', 'Target Port',
    '不要保存', '不要回显', '停止', '密码', 'Bearer token',
] as $required) {
    if (!str_contains($handoff, $required)) {
        fail('AI handoff missing: ' . $required);
    }
}
```

Reject literal secrets, IPv4 addresses other than documentation ranges, shell commands containing a token value, and language that instructs the AI to guess hidden route settings.

- [ ] **Step 2: Run the handoff test to confirm it fails**

Run `php master/tests/ai_setup_handoff_regression_test.php`.
Expected: FAIL because the handoff file does not exist.

- [ ] **Step 3: Write the user-facing AI handoff**

Structure the document as a copy/paste prompt with these sections: role, target outcome, information the AI may ask for, exact step loop, success evidence table, read-only troubleshooting, secret handling, and final report format. Use placeholders such as `<MASTER_URL>` and `<LOCAL_PORT>`, never real credentials. Require the AI to pause before any destructive action, advanced route change, firewall opening, NAT change, or rerun of a secret-bearing command.

- [ ] **Step 4: Run the handoff test and commit**

Run `php master/tests/ai_setup_handoff_regression_test.php`, then:

```text
git add docs/user/AI-SETUP-HANDOFF.zh-CN.md master/tests/ai_setup_handoff_regression_test.php
git commit -m "docs: add tenant AI setup handoff"
```

### Task 6: Full focused verification and review

**Files:**
- No new source files. Review the complete diff from `refs/heads/master`.

- [ ] **Step 1: Run PHP lint for changed PHP files**

Run:

```powershell
git diff --name-only refs/heads/master...HEAD -- '*.php' | ForEach-Object { php -l $_ }
```

Expected: every changed PHP file reports no syntax errors.

- [ ] **Step 2: Run the focused knowledgebase suite**

Run:

```text
php master/tests/knowledgebase_content_catalog_test.php
php master/tests/knowledgebase_screenshot_assets_test.php
php master/tests/knowledgebase_refresh_migration_test.php
php master/tests/knowledgebase_migration_behavior_test.php
php master/tests/knowledgebase_html_sanitizer_test.php
php master/tests/knowledgebase_reader_regression_test.php
php master/tests/tenant_user_guide_fixture_test.php
php master/tests/ai_setup_handoff_regression_test.php
```

Expected: all commands exit 0. Record any pre-existing unrelated failures separately.

- [ ] **Step 3: Check generated seed stability and diff hygiene**

Run `php master/scripts/build_knowledgebase_seed.php` twice and `git diff --check`. The second generation must produce no diff, and `git status --short` must contain only intended release files or explicitly retained QA captures.

- [ ] **Step 4: Review screenshots at both viewports**

Use `view_image` on all six published PNGs and the six mobile QA captures. Confirm every image shows a real task state, the captions match the UI, no text or controls overlap, and no secrets are visible.

- [ ] **Step 5: Commit the verification record**

```text
git add docs/superpowers/plans/2026-08-06-tenant-user-guide-screenshots.md
git commit -m "docs: add tenant guide implementation plan"
```

## Plan Self-Review

- **Spec coverage:** screenshots, three locales, reader CSS, guarded refresh, AI handoff, privacy, and browser/static validation each have a dedicated task.
- **Placeholder scan:** no `TBD`, `TODO`, or unspecified implementation step remains; all paths and commands are concrete.
- **Type consistency:** the plan uses `knowledgebase_topics.topic_key`, `knowledgebase_articles.locale`, `body_html`, and existing route labels consistently.
- **Scope:** no API, protocol, billing, or permission code is included; all changes stay inside documentation, rendering CSS, fixture, migration, and tests.
