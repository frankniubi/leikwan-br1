# Tenant Client Install-Only Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the tenant Client page with one Linux install command, one Windows installer action, and one global concurrent-online indicator while preserving server-side client limits and runtime enforcement.

**Architecture:** Keep `/tenant/clients` and the existing `clients`/`client_device_sessions` tables. Resolve one active, ungrouped default client through `ProvisioningService`, count online devices with the indexed `ClientOnlineSessionService::activeCountForClient()` query, and render a four-field page view model. Add a separate CSRF-protected reset-token POST that rotates the default client token, deletes its device sessions, and writes a secret-free audit event. Legacy tenant CRUD endpoints and Admin pages remain available but are no longer called by the tenant GET page.

**Tech Stack:** PHP 8.x, existing PDO `Database`, hand-built controller HTML, MySQL migrations, source-contract regression tests, and the repository's standalone `php master/tests/<file>.php` test runner.

---

## File Map

| File | Responsibility |
| --- | --- |
| `master/src/Support/ProvisioningService.php` | Select/repair an active ungrouped default client; rotate its token transactionally; preserve server-side profile-limit enforcement. |
| `master/src/Support/ClientOnlineSessionService.php` | Delete all sessions for a client when its token is reset. |
| `master/src/Controllers/TenantController.php` | Render the install-only page and handle the reset-token POST. Remove page-only list, group, forward, reputation, diagnostics, and Android reads. |
| `master/src/Controllers/BaseController.php` | Allow localized copy-button success/error labels without changing existing callers. |
| `master/public/assets/operator-console.css` | Provide the approved two-column platform layout, compact command block, and responsive global online row. |
| `master/src/Bootstrap.php` | Register `POST /tenant/clients/reset-token` before the legacy tenant client routes. |
| `master/tests/tenant_client_page_simplification_regression_test.php` | Assert the new page contract and forbidden legacy surface. |
| `master/tests/focused_tenant_resource_views_regression_test.php` | Update the Client method view contract from tabs/resource rows to the compact surface. |
| `master/tests/tenant_bond_leg_reputation_visibility_test.php` | Assert reputation data is absent from the tenant Client page while leaving non-page diagnostics code intact. |
| `master/tests/tenant_client_profile_limit_regression_test.php` | Prove the page does not expose/query profile counts while `saveClient` and the provisioning cap remain enforced. |
| `master/tests/tenant_client_reset_route_regression_test.php` | Cover the route, CSRF, tenant ownership, feature gate, and browser-id rejection contract. |
| `master/tests/default_client_provisioning_test.php` | Extend default-client lifecycle coverage for grouped legacy rows, ungrouped invariant, and token rotation. |
| `master/tests/client_online_session_revoke_test.php` | Cover session deletion and the zero-online result after reset. |
| `master/tests/client_copy_button_labels_regression_test.php` | Cover localized copy-button success/error labels. |
| `master/tests/tenant_client_install_style_regression_test.php` | Lock the approved responsive platform layout classes and CSS boundary. |
| `docs/user/AI-SETUP-HANDOFF.zh-CN.md` | Point the tenant runbook at the canonical install-only page and remove obsolete Android/tab/diagnostics instructions. |
| `master/tests/ai_setup_handoff_regression_test.php` | Match the revised runbook contract. |
| `master/migrations/20260825_refresh_tenant_client_install_surface.sql` | Guarded update of the published `install-client` knowledgebase article in `en`, `zh-Hans`, and `zh-TW`. |
| `master/tests/tenant_client_install_knowledgebase_migration_test.php` | Validate the migration is idempotent in shape and contains no Android/old-tab instructions. |

## Task 1: Establish Failing Page and Route Contracts

**Files:**
- Modify: `master/tests/tenant_client_page_simplification_regression_test.php`
- Modify: `master/tests/focused_tenant_resource_views_regression_test.php`
- Modify: `master/tests/tenant_bond_leg_reputation_visibility_test.php`
- Modify: `master/tests/tenant_client_profile_limit_regression_test.php`
- Create: `master/tests/tenant_client_reset_route_regression_test.php`

- [x] **Step 1: Replace the old positive page assertions with the approved compact contract.**

Isolate the GET substring from `public function clientsPage(` to the next
`public function resetDefaultClientToken(`. Require these exact page-owned snippets;
inspect the reset POST method separately for its authorization contract:

```php
foreach ([
    'defaultClientForUser(',
    'ensureDefaultClientForUser(',
    'activeCountForClient(',
    'clientAgentInstallCommand(',
    'windowsClientAppUrl()',
    '最大在线',
    '复制命令',
    '下载 installer',
    '/tenant/clients/reset-token',
] as $required) {
    if (!str_contains($clientsPage, $required)) {
        fail('missing compact Client page contract: ' . $required);
    }
}
```

Forbid the old page-only calls and visible text:

```php
foreach ([
    'listClientsForUser(',
    'maxClientProfilesForTenantOwnedClients(',
    'availableClientGroupsForUser(',
    'availableForwardChainsForService(',
    'BondLegReputationService',
    'scopedRowsForTenant(',
    'recentEvents(',
    'assignedForwardConfigsForClient(',
    'activeSessionsForClient(',
    'viewTabs(',
    '?view=',
    'Android',
    'Client Profiles',
    'System distribution',
    'system distribution',
    '安装次数',
    '/diagnose',
    '/request-update',
    '/delete',
] as $forbidden) {
    if (str_contains($clientsPage, $forbidden)) {
        fail('legacy Client page surface remains: ' . $forbidden);
    }
}

if (substr_count($clientsPage, '最大在线') !== 1) {
    fail('expected one global online label');
}
```

- [x] **Step 2: Change the focused resource-view contract.**

Keep the existing forward/dashboard assertions. Replace the Client entry with
`'views' => []`, then isolate the Client method and require `Linux`, `Windows
App`, `最大在线`, and `/tenant/clients/reset-token`; forbid `pg-sheet`,
`pg-mobile-resource-row`, `/diagnose`, `/request-update`, `/delete`, and
`viewTabs` in that method.

- [x] **Step 3: Turn the reputation test into a negative page assertion.**

Keep the source read and method isolation, but fail if the Client method
contains `BondLegReputationService`, `scopedRowsForTenant`, `recentEvents`, or
`Leg Reputation`. Do not remove the shared BaseController diagnostics helper
in this task.

- [x] **Step 4: Preserve the server-side cap test while removing page coupling.**

Assert that the isolated Client method does not contain
`maxClientProfilesForTenantOwnedClients(`, `Current Client Profiles`, or
`This package allows up to`. Separately keep assertions over the whole
`TenantController`/`ProvisioningService` sources for
`maxClientProfilesForTenantOwnedClients(`, `tenant_client_profile_limit_reached`,
and the `saveClient` create-cap branch.

- [x] **Step 5: Add the reset route contract before implementation.**

Create `tenant_client_reset_route_regression_test.php` with these concrete
assertions:

```php
$bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$tenant = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$provisioning = (string) file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    "\$this->router->add('POST', '/tenant/clients/reset-token', [\$tenant, 'resetDefaultClientToken']);",
    'public function resetDefaultClientToken(Request $request, array $params): Response',
    'SessionAuth::validateCsrf($request);',
    'rotateDefaultClientTokenForUser(',
    "'allow_rotate_client_token'",
] as $required) {
    if (!str_contains($bootstrap . $tenant . $provisioning, $required)) {
        fail('missing reset-token contract: ' . $required);
    }
}

$resetStart = strpos($tenant, 'public function resetDefaultClientToken(');
$resetEnd = strpos($tenant, 'public function sidecarsPage(', $resetStart ?: 0);
$resetMethod = ($resetStart !== false && $resetEnd !== false)
    ? substr($tenant, $resetStart, $resetEnd - $resetStart)
    : '';
foreach (["input('client_id'", 'input("client_id"', 'agent_token']) as $forbidden) {
    if (str_contains($resetMethod, $forbidden)) {
        fail('reset route must not trust a browser client id/token: ' . $forbidden);
    }
}
```

Also require `Response::redirect('/tenant/clients')` and a fixed generic error
flash; forbid concatenating `$throwable->getMessage()` into the reset error.

- [x] **Step 6: Run the new/updated tests and confirm RED for the intended reason.**

Run:

```powershell
php master/tests/tenant_client_page_simplification_regression_test.php
php master/tests/focused_tenant_resource_views_regression_test.php
php master/tests/tenant_bond_leg_reputation_visibility_test.php
php master/tests/tenant_client_profile_limit_regression_test.php
php master/tests/tenant_client_reset_route_regression_test.php
```

Expected: the compact-contract and reset tests fail on missing new snippets;
the failure must not be a PHP parse error. Do not change production files until
these tests have demonstrated the missing behavior.

## Task 2: Implement the Default-Client Invariant and Token Rotation

**Files:**
- Modify: `master/tests/default_client_provisioning_test.php`
- Modify: `master/src/Support/ProvisioningService.php`

- [x] **Step 1: Add failing lifecycle cases to the existing provisioning test.**

Extend its seeded `clients` rows with a grouped default and an ungrouped
non-default row. Assert that repair chooses the ungrouped row, demotes the
grouped marker, and leaves the grouped row's `group_id` unchanged:

```php
$repaired = $service->ensureDefaultClientForUser(50);
if ((int) ($repaired['group_id'] ?? 0) !== 0
    || (int) ($repaired['is_default_for_owner'] ?? 0) !== 1) {
    fail('expected the default client to be active and ungrouped');
}

$legacyGrouped = $db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => 501]);
if ((int) ($legacyGrouped['group_id'] ?? 0) !== 77
    || (int) ($legacyGrouped['is_default_for_owner'] ?? 0) !== 0) {
    fail('grouped legacy client was changed incorrectly');
}
```

Add a stable-token assertion for two successive `defaultClientForUser(50)`
calls, and a missing-default case that creates exactly one `group_id = null`
row after two `ensureDefaultClientForUser()` calls.

- [x] **Step 2: Run the lifecycle test and verify RED.**

```powershell
php master/tests/default_client_provisioning_test.php
```

Expected: the grouped-default case fails because the current helper returns a
grouped marker or selects the first owned row.

- [x] **Step 3: Make default lookup return only the runtime-valid default.**

Change `defaultClientForUser()` to use the existing owner/default/status index
and require both `status = 'active'` and `group_id IS NULL`:

```php
$row = $this->db->fetchOne(
    'SELECT *
     FROM clients
     WHERE owner_user_id = :owner_user_id
       AND is_default_for_owner = 1
       AND status = :status
       AND group_id IS NULL
     ORDER BY id ASC
     LIMIT 1',
    ['owner_user_id' => $userId, 'status' => 'active']
);
```

- [x] **Step 4: Make missing-default repair transactional and ungrouped.**

Update `ensureDefaultClientForUser()` to lock the tenant row with
`SELECT * FROM users WHERE id = :id LIMIT 1 FOR UPDATE`, then:

1. return the existing active ungrouped default if present;
2. select the oldest active ungrouped owned client and pass it to
   `markClientAsDefaultForUser()`;
3. otherwise create a new default through `createDefaultClientForUser()`;
4. re-read the row after marking/creating;
5. keep the whole operation inside `$this->db->transaction(...)` so two repair
   requests cannot create duplicate defaults.

`markClientAsDefaultForUser()` must reject a target with non-null `group_id`
using `ApiException('default_client_must_be_ungrouped', 422)` before clearing
other markers. Existing grouped clients are demoted only as markers; their
group assignments and forwards are not rewritten.

- [x] **Step 5: Add the default-client token rotation method.**

Add this public method to `ProvisioningService`:

```php
/** @return array<string, mixed> */
public function rotateDefaultClientTokenForUser(int $userId): array
{
    return $this->db->transaction(function () use ($userId): array {
        $client = $this->defaultClientForUser($userId);
        if ($client === null) {
            $client = $this->ensureDefaultClientForUser($userId);
        }
        $clientId = (int) ($client['id'] ?? 0);
        if ($clientId <= 0) {
            throw new ApiException('default_client_not_found', 404);
        }

        $token = bin2hex(random_bytes(24));
        $this->db->execute(
            'UPDATE clients
             SET agent_token = :agent_token, updated_at = :updated_at
             WHERE id = :id AND owner_user_id = :owner_user_id
               AND is_default_for_owner = 1 AND group_id IS NULL',
            [
                'id' => $clientId,
                'owner_user_id' => $userId,
                'agent_token' => $token,
                'updated_at' => $this->now(),
            ]
        );
        $this->db->execute(
            'DELETE FROM client_device_sessions WHERE client_id = :client_id',
            ['client_id' => $clientId]
        );
        $this->audit('client.default_token_rotated', [
            'user_id' => $userId,
            'client_id' => $clientId,
        ]);

        return $this->db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => $clientId]) ?? [];
    });
}
```

The audit context must contain no token. Do not reuse
`regenerateClientToken()`, which rotates the legacy service-level token.

- [x] **Step 6: Run the provisioning test and the existing default-client hooks.**

```powershell
php master/tests/default_client_provisioning_test.php
php master/tests/default_client_registration_hooks_regression_test.php
```

Expected: both pass with one stable ungrouped default per tenant and no
regression in registration/admin-created tenant hooks.

## Task 3: Add Explicit Session Revocation

**Files:**
- Create: `master/tests/client_online_session_revoke_test.php`
- Modify: `master/src/Support/ClientOnlineSessionService.php`
- Modify: `master/tests/client_online_device_limit_test.php`

- [x] **Step 1: Write the failing revoke test.**

Add a fake-database case that inserts two sessions for client `1`, calls the
new method, and asserts both rows are gone and `activeCountForClient(1) === 0`:

```php
$service->revokeAllForClient(1);
if ($db->sessions !== []) {
    fail('expected token reset to remove every device session');
}
if ($service->activeCountForClient(1) !== 0) {
    fail('expected revoked client to report zero active devices');
}
```

Extend the fake `execute()` implementation only for the exact
`DELETE FROM client_device_sessions WHERE client_id = :client_id` statement.

- [x] **Step 2: Run the revoke test and verify RED.**

```powershell
php master/tests/client_online_session_revoke_test.php
```

Expected: `Call to undefined method ...::revokeAllForClient()`.

- [x] **Step 3: Implement the minimal service method.**

Add:

```php
public function revokeAllForClient(int $clientId): int
{
    if ($clientId <= 0) {
        return 0;
    }

    return $this->db->execute(
        'DELETE FROM client_device_sessions WHERE client_id = :client_id',
        ['client_id' => $clientId]
    );
}
```

The method deliberately removes session history because this product surface
tracks concurrent online state only. The foreign key already cascades sessions
when a client is deleted.

- [x] **Step 4: Run the online-session tests.**

```powershell
php master/tests/client_online_session_revoke_test.php
php master/tests/client_online_device_limit_test.php
php master/tests/client_online_limit_wiring_regression_test.php
```

Expected: all pass, including the existing 180-second stale-session boundary.

## Task 4: Replace the Tenant Controller Surface and Register Reset

**Files:**
- Modify: `master/src/Controllers/TenantController.php`
- Modify: `master/src/Controllers/BaseController.php`
- Modify: `master/public/assets/operator-console.css`
- Modify: `master/src/Bootstrap.php`

- [x] **Step 1: Add the localized copy-button parameters.**

Change `BaseController::renderCopyButton()` to accept optional labels while
preserving all existing call sites:

```php
protected function renderCopyButton(
    string $label,
    string $text,
    string $variant = 'secondary',
    string $successLabel = 'Copied',
    string $errorLabel = 'Copy failed'
): string {
    // Keep the existing class construction and escaping.
    return '<button type="button" class="' . $this->escape($class)
        . '" data-copy-text="' . $this->escape($text)
        . '" data-copy-success="' . $this->escape($successLabel)
        . '" data-copy-error="' . $this->escape($errorLabel) . '">'
        . $this->escape($label) . '</button>';
}
```

The implementation must retain the current `button`/variant classes and HTML
escaping; only the two data attributes become configurable.

- [x] **Step 2: Replace only `clientsPage()` with the compact GET.**

Keep the role check and existing page/nav helpers. Use this control flow:

```php
$user = SessionAuth::currentUser($this->db);
if ($user === null || (string) $user['role'] !== 'tenant') {
    return Response::redirect('/login');
}

$client = $this->provisioning->defaultClientForUser((int) $user['id']);
if ($client === null) {
    $client = $this->provisioning->ensureDefaultClientForUser((int) $user['id']);
}
$clientId = (int) ($client['id'] ?? 0);
if ($clientId <= 0 || trim((string) ($client['agent_token'] ?? '')) === '') {
    SessionAuth::flash('error', 'Client install is temporarily unavailable.');
    return Response::redirect('/tenant');
}

$installCommand = $this->clientAgentInstallCommand($client);
$onlineCount = (new ClientOnlineSessionService($this->db))->activeCountForClient($clientId);
$onlineLimit = max(1, (int) ($client['online_device_limit'] ?? 1));
$csrf = $this->escape(SessionAuth::csrfToken());
$resetForm = '<form class="inline" method="post" action="/tenant/clients/reset-token">'
    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
    . '<button class="button ghost" type="submit">重置</button></form>';
$body = '<div class="tenant-client-install">'
    . '<div class="tenant-client-install__shell">'
    . '<div class="tenant-client-install__header"><strong>安装</strong></div>'
    . '<div class="tenant-client-install__body"><div class="tenant-client-install__platforms">'
    . '<section class="tenant-client-install__platform"><strong>Linux</strong>'
    . '<pre class="tenant-client-install__command">' . $this->escape($installCommand) . '</pre>'
    . '<div class="actions">'
    . $this->renderCopyButton('复制命令', $installCommand, 'secondary', '已复制', '复制失败')
    . $resetForm
    . '</div></section>'
    . '<section class="tenant-client-install__platform"><strong>Windows App</strong>'
    . '<div class="actions">' . $this->renderCommandLink('下载 installer', $this->windowsClientAppUrl(), 'secondary') . '</div>'
    . '</section></div>'
    . '<div class="tenant-client-install__online"><span>最大在线</span><strong>'
    . $onlineCount . ' / ' . $onlineLimit . '</strong></div></div></div></div>';

return $this->page('Client', $body, $user, $this->tenantNav('clients'));
```

Use the existing `$this->escape()` path for the command, URL, and CSRF token.
Do not include a token label, hidden client/group JSON, tabs, or explanatory
copy. Remove the now-unused `renderTenantClientLinuxInstall()` helper and the
page-local `BondLegReputationService` import; leave `PrecisionGridUi` for the
other tenant pages.

- [x] **Step 3: Add the reset POST handler.**

Add immediately after `clientsPage()`:

```php
public function resetDefaultClientToken(Request $request, array $params): Response
{
    $user = SessionAuth::currentUser($this->db);
    if ($user === null || (string) $user['role'] !== 'tenant') {
        return Response::redirect('/login');
    }

    try {
        SessionAuth::validateCsrf($request);
        $service = $this->db->fetchOne(
            'SELECT id FROM services
             WHERE user_id = :user_id AND status = :status
             ORDER BY id ASC LIMIT 1',
            ['user_id' => $user['id'], 'status' => 'active']
        );
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }
        $features = $this->provisioning->serviceSummary((int) $service['id'])['effective_features'] ?? [];
        if (empty($features['allow_rotate_client_token'])) {
            throw new ApiException('feature_denied_rotate_client_token', 403);
        }

        $this->provisioning->rotateDefaultClientTokenForUser((int) $user['id']);
        SessionAuth::flash('success', 'Client token reset.');
    } catch (\Throwable) {
        SessionAuth::flash('error', 'Client token reset failed.');
    }

    return Response::redirect('/tenant/clients');
}
```

Do not read `client_id` or `agent_token` from the request. Do not echo an
exception message. The service method owns token generation, session deletion,
and audit logging.

- [x] **Step 4: Register the route and preserve legacy routes.**

Insert this line before the existing `POST /tenant/clients` registration:

```php
$this->router->add('POST', '/tenant/clients/reset-token', [$tenant, 'resetDefaultClientToken']);
```

Do not remove `POST /tenant/clients` or the Admin/legacy diagnostic routes in
this task; they are backend compatibility surfaces, not tenant-page markup.

- [x] **Step 5: Run PHP lint and the page/route contracts.**

```powershell
php -l master/src/Support/ProvisioningService.php
php -l master/src/Support/ClientOnlineSessionService.php
php -l master/src/Controllers/BaseController.php
php -l master/src/Controllers/TenantController.php
php -l master/src/Bootstrap.php
php master/tests/client_copy_button_labels_regression_test.php
php master/tests/tenant_client_install_style_regression_test.php
php master/tests/tenant_client_page_simplification_regression_test.php
php master/tests/focused_tenant_resource_views_regression_test.php
php master/tests/tenant_bond_leg_reputation_visibility_test.php
php master/tests/tenant_client_profile_limit_regression_test.php
php master/tests/tenant_client_reset_route_regression_test.php
```

Expected: every command exits `0`; the page contract must show one
`最大在线`, no Android/group/profile/diagnostic strings, and the reset route
must be POST-only.

## Task 5: Update the Tenant Runbook and Published Install Article

**Files:**
- Modify: `docs/user/AI-SETUP-HANDOFF.zh-CN.md`
- Modify: `master/tests/ai_setup_handoff_regression_test.php`
- Create: `master/migrations/20260825_refresh_tenant_client_install_surface.sql`
- Create: `master/tests/tenant_client_install_knowledgebase_migration_test.php`

- [x] **Step 1: Rewrite the runbook's Client step.**

Replace the old `/tenant/clients?view=install`/Android/diagnostics sequence with
the exact rules below:

```text
4. 打开 `/tenant/clients`。页面只提供 Linux 安装命令和 Windows installer。
   - Windows: 选择 `下载 installer`，用户本人在 App 中完成登录。
   - Linux: 选择 `复制命令`，只在目标 Linux 主机以相当于 root 的权限运行一次；完整命令和其中的 Bearer token 不进入 AI、工单、日志或共享历史。
5. 回到 `/tenant/clients`，只核对 `最大在线` 的当前值；不要要求用户提供安装次数、client 列表、group、line、provider 或 diagnostics 内容。
```

Keep the existing password/token redaction and real-application verification
rules. Remove the obsolete Android and `Run Diagnostics` instructions from the
document, then update the regression test's required paths/labels accordingly.

- [x] **Step 2: Add a guarded knowledgebase refresh migration.**

Create a migration that updates only the three published `install-client`
articles when `updated_at < '2026-08-25 00:00:00'`. Each locale's body must
contain only the Windows installer and Linux command flow, must state that the
command contains a secret token, and must not mention Android, `Install` tabs,
client lists, groups, lines, or diagnostics. Use this exact guarded statement
shape for each locale (with the locale/body values changed):

```sql
UPDATE knowledgebase_articles
SET title = 'Install the Client',
    excerpt = 'Install Windows or Linux and confirm concurrent online status.',
    body_html = '<h2>Install</h2><p>Open <strong>Tenant → Clients</strong>.</p><ol><li><strong>Windows:</strong> choose <strong>Download installer</strong> and sign in in the app.</li><li><strong>Linux:</strong> choose <strong>Copy command</strong> and run it once on the target host with root-equivalent permission. The command contains a secret token; never share it.</li><li>Return to the page and check <strong>Maximum online</strong>.</li></ol>',
    status = 'published',
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'install-client')
  AND locale = 'en'
  AND status = 'published'
  AND updated_at < '2026-08-25 00:00:00';
```

Use the same guarded shape for `zh-Hans` and `zh-TW`, with their approved
localized labels. Do not add a table or modify the schema.

- [x] **Step 3: Add a migration-shape regression test.**

The test must require the filename, the `install-client` topic key, all three
locale predicates, `下载 installer`/`Download installer`, `复制命令`/`Copy
command`, and `最大在线`/`concurrent online`. It must forbid `Download Android
App`, `Android App`, `?view=install`, `Run Diagnostics`, `View Diagnostics`,
and `Client Profiles` in the new migration. It must also assert that no
`CREATE TABLE`, `DROP TABLE`, or `ALTER TABLE` statement appears.

- [x] **Step 4: Run documentation tests.**

```powershell
php master/tests/ai_setup_handoff_regression_test.php
php master/tests/tenant_client_install_knowledgebase_migration_test.php
```

Expected: both pass without exposing a literal token or private network IP.

## Task 6: Full Targeted Verification and Checkpoint Commits

**Files:**
- Test: all files listed in Tasks 1-5
- Documentation: `docs/superpowers/plans/2026-08-25-tenant-client-install-only.md`

- [ ] **Step 1: Run the complete related PHP regression set.**

```powershell
$tests = @(
  'tenant_client_page_simplification_regression_test.php',
  'focused_tenant_resource_views_regression_test.php',
  'tenant_bond_leg_reputation_visibility_test.php',
  'tenant_client_profile_limit_regression_test.php',
  'tenant_client_reset_route_regression_test.php',
  'default_client_provisioning_test.php',
  'default_client_registration_hooks_regression_test.php',
  'client_online_session_revoke_test.php',
  'client_online_device_limit_test.php',
  'client_online_limit_wiring_regression_test.php',
  'client_copy_button_labels_regression_test.php',
  'tenant_client_install_style_regression_test.php',
  'tenant_client_save_route_regression_test.php',
  'client_install_simplification_regression_test.php',
  'ai_setup_handoff_regression_test.php',
  'tenant_client_install_knowledgebase_migration_test.php'
)
foreach ($test in $tests) {
    php (Join-Path 'master/tests' $test)
    if ($LASTEXITCODE -ne 0) { throw "failed: $test" }
}
```

Expected: every test prints its existing `ok`/`OK` line and the loop exits
without throwing.

- [ ] **Step 2: Run static, syntax, and secret-surface checks.**

```powershell
git diff --check
rg -n "System distribution|system distribution|后台策略|安装次数|Download Android App|Android App|Client Profiles|Run Diagnostics|View Diagnostics|provider|line_ips|temporary credential" master/src/Controllers/TenantController.php
```

The search may find legacy strings in unrelated tenant methods or shared
helpers; the isolated `clientsPage()` substring must produce no matches. The
Linux token may appear only in the escaped command generated for the approved
install action, never in logs, audit context, URLs, or hidden metadata.

- [x] **Step 3: Verify the query boundary by source and fake-database tests.**

Confirm the Client method contains exactly one `activeCountForClient(` call and
no `fetchAll(` call. Confirm the normal GET calls
`defaultClientForUser()` first and calls `ensureDefaultClientForUser()` only
inside the null branch. Run the online count test to prove stale rows are
excluded at 180 seconds.

- [ ] **Step 4: Render the page in a local canary and inspect both viewports.**

Start the existing PHP entry point with a test database/config on an unused
local port, authenticate a seeded tenant, and open `/tenant/clients` in the
approved browser preview. Verify desktop and narrow mobile screenshots contain
only the two platform blocks and the global `最大在线 0 / N` row. Click `复制命令`,
download the Windows installer link, and submit `重置`; verify the redirect,
changed command token, and zero online sessions. Do not capture or store the
full token in screenshots or logs.

- [ ] **Step 5: Commit the implementation in reviewable checkpoints.**

Use these commits, keeping unrelated worktree changes untouched:

```powershell
git add master/tests/tenant_client_page_simplification_regression_test.php master/tests/focused_tenant_resource_views_regression_test.php master/tests/tenant_bond_leg_reputation_visibility_test.php master/tests/tenant_client_profile_limit_regression_test.php master/tests/tenant_client_reset_route_regression_test.php
git commit -m "test: lock tenant client install-only contract"

git add master/src/Support/ProvisioningService.php master/src/Support/ClientOnlineSessionService.php master/tests/default_client_provisioning_test.php master/tests/client_online_session_revoke_test.php
git commit -m "feat: enforce ungrouped default client lifecycle"

git add master/src/Controllers/BaseController.php master/src/Controllers/TenantController.php master/src/Bootstrap.php master/public/assets/operator-console.css master/tests/client_copy_button_labels_regression_test.php master/tests/tenant_client_install_style_regression_test.php master/tests/leikwan_ui_asset_regression_test.php master/tests/precision_grid_design_system_regression_test.php
git commit -m "feat: simplify tenant client install page"

git add master/tests/tenant_client_page_simplification_regression_test.php
git commit -m "test: enforce tenant client page query boundary"

git add docs/user/AI-SETUP-HANDOFF.zh-CN.md master/migrations/20260825_refresh_tenant_client_install_surface.sql master/tests/ai_setup_handoff_regression_test.php master/tests/tenant_client_install_knowledgebase_migration_test.php docs/superpowers/plans/2026-08-25-tenant-client-install-only.md
git commit -m "docs: align tenant client install guidance"
```

Before each commit, run the relevant tests from that task and `git diff --check`.
Do not stage the ignored `.superpowers/brainstorm/client-page-preview` files.

## Plan Self-Review

- **Spec coverage:** Tasks 1 and 4 cover the exact UI labels, global online row,
  no internal/group text, no Android/list/diagnostics reads, and canonical
  route. Tasks 2 and 3 cover stable ungrouped default identity, concurrent
  repair, token reset, session revocation, and secret-free audit. Task 2 keeps
  profile limits server-side. Task 5 removes stale user guidance. Task 6
  covers lint, negative scans, query boundary, and browser canary evidence.
- **No placeholders:** The plan contains concrete paths, method names, SQL,
  assertions, commands, and expected outcomes.
- **Type consistency:** The reset route is named
  `resetDefaultClientToken`; the service method is named
  `rotateDefaultClientTokenForUser`; session revocation is named
  `revokeAllForClient`; all later tasks use those exact names.
- **Compatibility:** Admin pages, legacy tenant CRUD routes, artifact URLs,
  runtime heartbeats, and server-side profile caps stay in place. Only the
  tenant Client GET and its new reset action are changed.
