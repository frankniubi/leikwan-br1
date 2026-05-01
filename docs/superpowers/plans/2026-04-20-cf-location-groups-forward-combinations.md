# CF Location Groups + Forward Combinations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build tenant-hidden forward combinations and independent Cloudflare location groups so phase-one `single` and `bond` forwards can keep the existing `client -> slave -> sidecar` topology while each slave shares one best Cloudflare IP selection per assigned location group.

**Architecture:** Add panel asset tables for Cloudflare location groups and forward combinations, resolve combinations into forward snapshots inside `ProvisioningService`, add a sidecar WSS path-mux runtime for `cf_mode`, and teach each slave to bridge its current local backend port into a WSS client connection that dials the selected Cloudflare IP with the original `sni + port + ws path` identity. Keep slave ingress in `7000-9000`, keep tenant UI backend-blind, and reject `race` and `mptcp`.

**Tech Stack:** PHP controllers + provisioning + source-string regression tests, Python slave/sidecar agents + unit tests, existing `wstunnel`, existing mux route JSON files, MySQL schema migrations, systemd-managed runtimes

---

## Scope Guardrails

- Do not change topology away from `client -> slave -> sidecar`.
- Do not surface slave groups, sidecar points, backend mode, or Cloudflare groups to tenants.
- Do not add `race` or `mptcp` to phase one.
- Keep one concrete forward bound to exactly one Cloudflare location group.
- Keep one slave sharing one best-IP result per location group.
- In `cf_mode`, support UDP and WireGuard-compatible traffic only through an **independent UoT shadow channel** over TCP/WSS, not native UDP underlay.
- Prefer lower latency, lower jitter, and higher real transfer speed over minimizing implementation changes.
- Reuse existing panel plan filters (`allowed_node_groups`, `allowed_sidecar_points`) instead of creating new plan-to-combination tables in phase one.

## What Already Exists

- `master/src/Support/ProvisioningService.php` already owns the forward snapshot, publish-task payload generation, sidecar-point resolution, and service summary APIs.
- `master/src/Controllers/AdminController.php` already has management pages for slave groups and sidecar points, so the new assets fit the current control-plane shape.
- `master/src/Controllers/TenantController.php` already funnels tenant forward creation through `normalizeTenantForwardPayload(...)` and `createForward(...)`, so combination-only entry can reuse that seam instead of adding a second path.
- `slave-agent/slave_agent.py` already has:
  - per-service metadata files
  - listener route files
  - systemd unit rendering
  - plain TCP mux lifecycle
- `slave-agent/slave_agent.py::WssPathMux` is an existing path-based TLS/WSS routing reference that can be extracted for sidecar `cf_mode`.
- `sidecar-agent/sidecar_agent.py` already applies management payloads and renders per-mode runtime files, so `cf_runtime` can slot into the same loop instead of creating a separate daemon.
- Existing plan filtering already happens through allowed slave groups and allowed sidecar points, which is enough for phase one even without a dedicated plan-to-combination table.

## NOT in scope

- `race` as a first-class client mode, user already decided current behavior is enough.
- `mptcp` inside the new Cloudflare-backed path, intentionally deferred because it would explode transport complexity.
- exposing backend topology to tenants, explicitly rejected by product direction.
- direct client-to-sidecar connectivity, forbidden by topology rules.
- a new plan-to-forward-combination authorization model, deferred because existing group and sidecar filters are sufficient for phase one.

## Architecture Diagram

```text
Tenant UI
  -> chooses Forward Combination only
  -> createForward()

Forward Combination
  -> node_group_key
  -> backend_mode
  -> sidecar_point_id
  -> optional cf_location_group_id
  -> client_mode(single|bond)

ProvisioningService
  -> snapshots combination into forward
  -> publishes service_endpoints to slaves
  -> emits cf_mode payload when backend_mode=cf_mode

cf_mode runtime path
  client
    -> slave public ingress
    -> slave main TCP bridge and optional UoT shadow bridge
    -> selected Cloudflare IP:443
    -> sidecar WSS mux and optional UoT shadow route
    -> sidecar local backend ws://127.0.0.1:<port>
    -> target backend
```

## Transport Contract

Phase-one `cf_mode` transport behavior:

- `tcp` -> main TCP channel only
- `tcp+udp` -> main TCP channel plus independent UoT shadow channel
- `udp` -> independent UoT shadow channel only

The main TCP channel and the UoT shadow channel are separate runtime/process channels, but both reuse the same slave-local best-IP selection for the assigned location group.

## Inline Diagram Comment Targets

When implemented, add small ASCII diagrams in:

- `master/src/Support/ProvisioningService.php` near combination resolution and `buildCfModePayload(...)`
- `slave-agent/slave_agent.py` near:
  - location-group cache selection
  - `cf_mode` bridge rendering
- `sidecar-agent/sidecar_agent.py` near `cf_runtime` application and mux/backend orchestration

## File Map

- Create: `master/migrations/20260420_add_cf_location_groups_and_forward_combinations.sql`
- Create: `master/tests/provisioning_forward_combination_regression_test.php`
- Create: `master/tests/admin_forward_combination_page_test.php`
- Create: `master/tests/tenant_forward_combination_regression_test.php`
- Create: `master/tests/admin_cf_location_observability_test.php`
- Create: `sidecar-agent/leikwan_wss_mux.py`
- Create: `sidecar-agent/tests/test_sidecar_cf_mode_runtime.py`
- Create: `slave-agent/tests/test_slave_cf_location_group_selection.py`
- Modify: `master/migrations/schema.sql`
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Controllers/TenantController.php`
- Modify: `master/src/Controllers/NodeController.php`
- Modify: `master/src/Controllers/SidecarAgentController.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `sidecar-agent/sidecar_agent.py`
- Modify: `slave-agent/slave_agent.py`
- Modify: `install/install-bond-sidecar.sh`
- Modify: `install/install-slave.sh`

## Task 1: Panel Schema And Provisioning Foundation

**Files:**
- Create: `master/migrations/20260420_add_cf_location_groups_and_forward_combinations.sql`
- Create: `master/tests/provisioning_forward_combination_regression_test.php`
- Modify: `master/migrations/schema.sql`
- Modify: `master/src/Support/ProvisioningService.php`

- [ ] **Step 1: Write the failing panel regression test**

Create `master/tests/provisioning_forward_combination_regression_test.php` with:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
$migration = file_get_contents(__DIR__ . '/../migrations/20260420_add_cf_location_groups_and_forward_combinations.sql');
$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    'CREATE TABLE IF NOT EXISTS cf_location_groups',
    'CREATE TABLE IF NOT EXISTS cf_location_group_cidrs',
    'CREATE TABLE IF NOT EXISTS forward_combinations',
    'ADD COLUMN cf_listen_port',
    'ADD COLUMN forward_combination_id',
    'ADD COLUMN backend_mode',
    'ADD COLUMN cf_location_group_id',
] as $snippet) {
    if (!str_contains($schema, $snippet) && !str_contains($migration, $snippet)) {
        fail('missing schema snippet: ' . $snippet);
    }
}

foreach ([
    'public function saveCfLocationGroup(array $payload): array',
    'public function saveForwardCombination(array $payload): array',
    'public function availableForwardCombinationsForService(int $serviceId): array',
    '$forwardCombinationId = (int) ($payload[\'forward_combination_id\'] ?? 0);',
    '$backendMode = trim((string) ($payload[\'backend_mode\'] ?? \'current_mode\'));',
    'if (!in_array($clientMode, [\'single\', \'bond\'], true)) {',
    "'forward_combination_id' =>",
    "'backend_mode' =>",
    "'cf_mode' =>",
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing provisioning snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the test and verify it fails**

Run:

```bash
php master/tests/provisioning_forward_combination_regression_test.php
```

Expected: FAIL because the schema, save helpers, and forward snapshot logic do not exist yet.

- [ ] **Step 3: Add the migration and schema changes**

In `master/migrations/20260420_add_cf_location_groups_and_forward_combinations.sql`, add:

```sql
CREATE TABLE cf_location_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    region_code VARCHAR(32) NOT NULL,
    probe_mode VARCHAR(16) NOT NULL DEFAULT 'auto',
    cache_ttl_seconds INT NOT NULL DEFAULT 120,
    switch_improvement_ms INT NOT NULL DEFAULT 15,
    max_candidates INT NOT NULL DEFAULT 32,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE cf_location_group_cidrs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    cidr VARCHAR(64) NOT NULL,
    priority INT NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_cf_location_group_cidrs_group FOREIGN KEY (group_id) REFERENCES cf_location_groups(id),
    UNIQUE KEY uniq_cf_location_group_cidr (group_id, cidr)
);

CREATE TABLE forward_combinations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    tenant_label VARCHAR(191) NOT NULL,
    tenant_description VARCHAR(255) NULL,
    tenant_badge VARCHAR(64) NULL,
    node_group_key VARCHAR(191) NOT NULL,
    backend_mode VARCHAR(32) NOT NULL DEFAULT 'current_mode',
    sidecar_point_id BIGINT UNSIGNED NOT NULL,
    cf_location_group_id BIGINT UNSIGNED NULL,
    client_mode VARCHAR(32) NOT NULL DEFAULT 'single',
    bond_ingress_mode VARCHAR(32) NOT NULL DEFAULT 'plain_tcp_mux',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_forward_combinations_sidecar FOREIGN KEY (sidecar_point_id) REFERENCES sidecar_points(id),
    CONSTRAINT fk_forward_combinations_cf_group FOREIGN KEY (cf_location_group_id) REFERENCES cf_location_groups(id)
);

ALTER TABLE sidecar_points ADD COLUMN cf_listen_port INT NULL AFTER mptcp_listen_port;
ALTER TABLE forwards ADD COLUMN forward_combination_id BIGINT UNSIGNED NULL AFTER bond_sidecar_point_id;
ALTER TABLE forwards ADD COLUMN backend_mode VARCHAR(32) NOT NULL DEFAULT 'current_mode' AFTER forward_combination_id;
ALTER TABLE forwards ADD COLUMN cf_location_group_id BIGINT UNSIGNED NULL AFTER backend_mode;
```

Mirror the same structures in `master/migrations/schema.sql`, including the new `cf_listen_port` column on `sidecar_points` and the new forward columns.

- [ ] **Step 4: Add provisioning helpers and combination resolution**

In `master/src/Support/ProvisioningService.php`, add the new persistence helpers:

```php
public function saveCfLocationGroup(array $payload): array
{
    $id = (int) ($payload['id'] ?? 0);
    $name = trim((string) ($payload['name'] ?? ''));
    $regionCode = trim((string) ($payload['region_code'] ?? ''));
    $probeMode = trim((string) ($payload['probe_mode'] ?? 'auto'));
    $cacheTtlSeconds = max(30, (int) ($payload['cache_ttl_seconds'] ?? 120));
    $switchImprovementMs = max(1, (int) ($payload['switch_improvement_ms'] ?? 15));
    $maxCandidates = max(1, min(256, (int) ($payload['max_candidates'] ?? 32)));
    $cidrLines = preg_split('/\r\n|\r|\n/', (string) ($payload['cidr_lines'] ?? '')) ?: [];
    $cidrs = array_values(array_unique(array_filter(array_map('trim', $cidrLines), static fn(string $line): bool => $line !== '')));

    if ($name === '' || $regionCode === '' || $cidrs === []) {
        throw new ApiException('invalid_cf_location_group_payload', 422, ['payload' => $payload]);
    }

    // upsert group, then replace child CIDR rows in one transaction
}

public function saveForwardCombination(array $payload): array
{
    $id = (int) ($payload['id'] ?? 0);
    $backendMode = trim((string) ($payload['backend_mode'] ?? 'current_mode'));
    $clientMode = $this->normalizeClientMode((string) ($payload['client_mode'] ?? 'single'));
    if (!in_array($backendMode, ['current_mode', 'cf_mode'], true)) {
        throw new ApiException('invalid_forward_combination_backend_mode', 422);
    }
    if (!in_array($clientMode, ['single', 'bond'], true)) {
        throw new ApiException('invalid_forward_combination_client_mode', 422);
    }
    if ($backendMode === 'cf_mode' && (int) ($payload['cf_location_group_id'] ?? 0) <= 0) {
        throw new ApiException('cf_location_group_required', 422);
    }

    // insert or update combination row
}
```

Then update `saveForward(...)` so combinations resolve before the legacy direct fields:

```php
$forwardCombinationId = (int) ($payload['forward_combination_id'] ?? 0);
$backendMode = trim((string) ($payload['backend_mode'] ?? 'current_mode'));
$cfLocationGroupId = (int) ($payload['cf_location_group_id'] ?? 0);

if ($forwardCombinationId > 0) {
    $combination = $this->visibleForwardCombinationForService($forwardCombinationId, $serviceId);
    $backendMode = (string) $combination['backend_mode'];
    $clientMode = $this->normalizeClientMode((string) $combination['client_mode']);
    if (!in_array($clientMode, ['single', 'bond'], true)) {
        throw new ApiException('invalid_forward_combination_client_mode', 422, ['client_mode' => $clientMode]);
    }
    $nodeGroup = (string) $combination['node_group_key'];
    $bondSidecarPointId = (int) $combination['sidecar_point_id'];
    $bondIngressMode = $this->normalizeBondIngressMode((string) $combination['bond_ingress_mode']);
    $cfLocationGroupId = $backendMode === 'cf_mode' ? (int) ($combination['cf_location_group_id'] ?? 0) : 0;
}
```

Persist the snapshot columns on insert and update:

```php
'forward_combination_id' => $forwardCombinationId > 0 ? $forwardCombinationId : null,
'backend_mode' => $backendMode,
'cf_location_group_id' => $cfLocationGroupId > 0 ? $cfLocationGroupId : null,
```

Add `availableForwardCombinationsForService(...)` by filtering active combinations against the service plan’s existing `allowed_node_groups` and `allowed_sidecar_points`. Update `serviceSummary(...)` and `buildClientConfig(...)` so they return:

```php
'forward_combination_id' => $forward['forward_combination_id'] !== null ? (int) $forward['forward_combination_id'] : null,
'forward_combination_name' => (string) ($forward['forward_combination_name'] ?? ''),
'backend_mode' => (string) ($forward['backend_mode'] ?? 'current_mode'),
```

Finally, extend `publishTaskPayloadForForward(...)` with a `cf_mode` block that the slave will consume later:

```php
'backend_mode' => (string) ($forward['backend_mode'] ?? 'current_mode'),
'cf_mode' => (string) ($forward['backend_mode'] ?? 'current_mode') === 'cf_mode'
    ? $this->buildCfModePayload($forward, $node)
    : null,
```

- [ ] **Step 5: Run the regression test and syntax checks**

Run:

```bash
php master/tests/provisioning_forward_combination_regression_test.php
php -l master/src/Support/ProvisioningService.php
```

Expected:

- regression test prints `ok`
- PHP lint reports `No syntax errors detected`

- [ ] **Step 6: Commit the foundation**

```bash
git add master/migrations/20260420_add_cf_location_groups_and_forward_combinations.sql master/migrations/schema.sql master/src/Support/ProvisioningService.php master/tests/provisioning_forward_combination_regression_test.php
git commit -m "feat: add CF location group and forward combination foundation"
```

## Task 2: Admin UI, Routes, And Sidecar Point Inputs

**Files:**
- Create: `master/tests/admin_forward_combination_page_test.php`
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Support/ProvisioningService.php`

- [ ] **Step 1: Write the failing admin page regression test**

Create `master/tests/admin_forward_combination_page_test.php` with:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');

foreach ([
    "/admin/cf-location-groups",
    "/admin/forward-combinations",
    "cfLocationGroupsPage",
    "saveCfLocationGroup",
    "forwardCombinationsPage",
    "saveForwardCombination",
] as $snippet) {
    if (!str_contains($bootstrap . $admin, $snippet)) {
        fail('missing admin routing snippet: ' . $snippet);
    }
}

foreach ([
    'Cloudflare Location Groups',
    'Forward Combinations',
    'name="cf_listen_port"',
    'name="backend_mode"',
    'name="cf_location_group_id"',
    'value="current_mode"',
    'value="cf_mode"',
    'value="single"',
    'value="bond"',
] as $snippet) {
    if (!str_contains($admin, $snippet)) {
        fail('missing admin page snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the test and verify it fails**

Run:

```bash
php master/tests/admin_forward_combination_page_test.php
```

Expected: FAIL because the new routes, pages, and `cf_listen_port` input are missing.

- [ ] **Step 3: Add routes and admin pages**

In `master/src/Bootstrap.php`, add:

```php
$this->router->add('GET', '/admin/cf-location-groups', [$admin, 'cfLocationGroupsPage']);
$this->router->add('POST', '/admin/cf-location-groups', [$admin, 'saveCfLocationGroup']);
$this->router->add('GET', '/admin/forward-combinations', [$admin, 'forwardCombinationsPage']);
$this->router->add('POST', '/admin/forward-combinations', [$admin, 'saveForwardCombination']);
```

In `master/src/Controllers/AdminController.php`, add a Cloudflare location-group page that uses a textarea for `cidr_lines` so child rows can be rewritten safely:

```php
public function cfLocationGroupsPage(Request $request, array $params): Response
{
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::redirect('/login');
    }

    $groups = $this->provisioning?->listCfLocationGroups() ?? [];
    $csrf = $this->escape(SessionAuth::csrfToken());

    $body = '
        <div class="hero"><div><h2>Cloudflare Location Groups</h2><p class="muted">Reusable CIDR sets for slave-side best-IP selection.</p></div></div>
        <section class="card">
          <h3>Create Location Group</h3>
          <form method="post" action="/admin/cf-location-groups">
            <input type="hidden" name="csrf_token" value="' . $csrf . '">
            <div class="form-grid">
              <div class="field"><label>Name</label><input type="text" name="name" required></div>
              <div class="field"><label>Region Code</label><input type="text" name="region_code" placeholder="JP" required></div>
              <div class="field"><label>Probe Mode</label><select name="probe_mode"><option value="auto">auto</option><option value="tcp">tcp</option><option value="icmp">icmp</option></select></div>
              <div class="field"><label>Cache TTL Seconds</label><input type="number" name="cache_ttl_seconds" value="120"></div>
              <div class="field"><label>Switch Improvement Ms</label><input type="number" name="switch_improvement_ms" value="15"></div>
              <div class="field"><label>Max Candidates</label><input type="number" name="max_candidates" value="32"></div>
            </div>
            <div class="field" style="margin-top:14px;"><label>CIDRs</label><textarea name="cidr_lines" placeholder="104.16.0.0/13&#10;172.64.0.0/13" required></textarea></div>
            <div class="actions"><button class="button" type="submit">Save Group</button></div>
          </form>
        </section>';

    return $this->adminPage('Cloudflare Location Groups', $body, $viewer, 'node-groups');
}
```

Add a forward-combination page that renders active sidecars, location groups, and hard-coded client-mode choices `single` and `bond`. Also extend both admin and tenant sidecar-point forms with `CF Listen Port`, then feed it into `saveSidecarPoint(...)` as `cf_listen_port`.

The forward-combination form should collect tenant-facing copy separately from backend wiring:

- `tenant_label`
- `tenant_description`
- optional `tenant_badge`

and show a small preview block like:

```text
Tenant sees:
SHA -> NRT
Best for Japan
[Recommended]
```

- [ ] **Step 4: Update sidecar point save payloads**

In `master/src/Controllers/AdminController.php` and `master/src/Controllers/TenantController.php`, pass through:

```php
'cf_listen_port' => $request->input('cf_listen_port'),
'cf_tls_cert_path' => $request->input('cf_tls_cert_path'),
'cf_tls_key_path' => $request->input('cf_tls_key_path'),
```

Then in `ProvisioningService::saveSidecarPoint(...)`, persist it:

```php
$cfListenPort = max(0, (int) ($payload['cf_listen_port'] ?? ($existing['cf_listen_port'] ?? 0)));
if ($cfListenPort <= 0) {
    $cfListenPort = 443;
}

$pointConfig = $this->decodeJsonAssoc($agentConfigJson);
$pointConfig['cf_tls_cert_path'] = trim((string) ($payload['cf_tls_cert_path'] ?? ($pointConfig['cf_tls_cert_path'] ?? '')));
$pointConfig['cf_tls_key_path'] = trim((string) ($payload['cf_tls_key_path'] ?? ($pointConfig['cf_tls_key_path'] ?? '')));
$agentConfigJson = $this->normalizeJsonField($pointConfig);

'cf_listen_port' => $cfListenPort,
```

Update all sidecar point list/select queries so `cf_listen_port` is included wherever `bond_listen_port` and `mptcp_listen_port` are already selected.

- [ ] **Step 5: Run the admin regression tests**

Run:

```bash
php master/tests/admin_forward_combination_page_test.php
php master/tests/provisioning_forward_combination_regression_test.php
```

Expected: both tests print `ok`.

- [ ] **Step 6: Commit the admin control plane**

```bash
git add master/src/Bootstrap.php master/src/Controllers/AdminController.php master/src/Controllers/TenantController.php master/src/Support/ProvisioningService.php master/tests/admin_forward_combination_page_test.php
git commit -m "feat: add admin CF groups and forward combinations UI"
```

## Task 3: Tenant Forward Creation Becomes Combination-Only

**Files:**
- Create: `master/tests/tenant_forward_combination_regression_test.php`
- Modify: `master/src/Controllers/TenantController.php`
- Modify: `master/src/Support/ProvisioningService.php`

- [ ] **Step 1: Write the failing tenant regression test**

Create `master/tests/tenant_forward_combination_regression_test.php` with:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$serviceSummarySource = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    "available_forward_combinations",
    "forward_combination_id",
    "Forward Combination",
    "public function createForward(Request $request, array $params): Response",
    "return [\n        'forward_combination_id' => max(0, (int) (\$payload['forward_combination_id'] ?? 0)),",
] as $snippet) {
    if (!str_contains($source . $serviceSummarySource, $snippet)) {
        fail('missing tenant combination snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the test and verify it fails**

Run:

```bash
php master/tests/tenant_forward_combination_regression_test.php
```

Expected: FAIL because tenant pages still expose raw backend selectors.

- [ ] **Step 3: Add service summary support and simplify the payload**

In `master/src/Support/ProvisioningService.php`, add:

```php
$service['available_forward_combinations'] = $this->availableForwardCombinationsForService($serviceId);
```

In `master/src/Controllers/TenantController.php`, reduce `normalizeTenantForwardPayload(...)` to only the fields tenants should control:

```php
private function normalizeTenantForwardPayload(array $payload): array
{
    return [
        'forward_combination_id' => max(0, (int) ($payload['forward_combination_id'] ?? 0)),
        'listen_port' => max(0, (int) ($payload['listen_port'] ?? 0)),
        'target_host' => trim((string) ($payload['target_host'] ?? '')),
        'target_port' => max(0, (int) ($payload['target_port'] ?? 0)),
        'transport_mode' => match (strtolower(trim((string) ($payload['transport_mode'] ?? 'tcp')))) {
            'udp' => 'udp',
            'tcp+udp', 'udp+tcp', 'both' => 'tcp+udp',
            default => 'tcp',
        },
    ];
}
```

- [ ] **Step 4: Replace the create-forward form with a combination select**

In both the service detail page and `quickCreateForward(...)`, render only:

```php
$combinationOptions = '<option value="">Select a forward combination</option>';
foreach (($summary['available_forward_combinations'] ?? []) as $combination) {
    $combinationOptions .= '<option value="' . (int) $combination['id'] . '">' . $this->escape((string) $combination['tenant_label']) . '</option>';
}
```

If the combination list is empty, render:

```php
<div class="flash danger">
  No route package is available for this service yet. Ask admin to publish a forward combination first.
</div>
```

And use a minimal form body:

```php
<div class="form-grid">
  <div class="field"><label>Forward Combination</label><select name="forward_combination_id" required>' . $combinationOptions . '</select></div>
  <div class="field"><label>Target Host</label><input type="text" name="target_host" required></div>
  <div class="field"><label>Target Port</label><input type="number" name="target_port" required></div>
  <div class="field"><label>Local Listen Port</label><input type="number" name="listen_port" placeholder="auto from line range"></div>
  <div class="field"><label>Transport</label><select name="transport_mode"><option value="tcp">TCP</option><option value="tcp+udp">TCP + UDP</option><option value="udp">UDP</option></select></div>
</div>
<div class="field-note" id="forward-combination-help">Show the selected combination's short description and badge here without exposing backend details.</div>
```

Keep the wizard routes but leave them as redirects to `/tenant/services/{id}/create-forward` so no parallel backend-aware form survives.

- [ ] **Step 5: Run the tenant regression tests**

Run:

```bash
php master/tests/tenant_forward_combination_regression_test.php
php master/tests/provisioning_forward_combination_regression_test.php
```

Expected: both tests print `ok`.

- [ ] **Step 6: Commit the tenant-facing simplification**

```bash
git add master/src/Controllers/TenantController.php master/src/Support/ProvisioningService.php master/tests/tenant_forward_combination_regression_test.php
git commit -m "feat: make tenant forward creation combination-only"
```

## Task 4: Sidecar `cf_mode` Runtime And WSS Mux

**Files:**
- Create: `sidecar-agent/leikwan_wss_mux.py`
- Create: `sidecar-agent/tests/test_sidecar_cf_mode_runtime.py`
- Modify: `master/src/Controllers/SidecarAgentController.php`
- Modify: `sidecar-agent/sidecar_agent.py`
- Modify: `install/install-bond-sidecar.sh`

- [ ] **Step 1: Write the failing sidecar runtime test**

Create `sidecar-agent/tests/test_sidecar_cf_mode_runtime.py` with:

```python
import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module

sidecar_agent = load_module("sidecar_agent_cf_mode_under_test", "sidecar-agent/sidecar_agent.py")


class SidecarCfModeRuntimeTests(unittest.TestCase):
    def make_agent(self, tmp_dir: str):
        config_path = pathlib.Path(tmp_dir) / "config.json"
        config_path.write_text(json.dumps({
            "master_url": "https://panel.example.com",
            "agent_token": "token-123",
            "state_dir": str(pathlib.Path(tmp_dir) / "state"),
            "runtime_config_path": str(pathlib.Path(tmp_dir) / "bond-runtime.json"),
            "service_name": "leikwan-bond-sidecar-12",
        }), encoding="utf-8")
        return sidecar_agent.SidecarAgent(config_path)

    def test_apply_management_writes_cf_runtime_route_file(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            management = {
                "cf_runtime": {
                    "listen_host": "0.0.0.0",
                    "listen_port": 443,
                    "tls_cert_path": "/etc/ssl/cf/fullchain.pem",
                    "tls_key_path": "/etc/ssl/cf/privkey.pem",
                    "routes": [
                        {
                            "forward_id": 26,
                            "service_name": "cf-forward-26",
                            "path_prefix": "a43547b6350f72cd",
                            "target": "127.0.0.1:51820",
                        }
                    ],
                }
            }
            agent.apply_management(management)
            routes_path = agent.state_dir / "cf-routes-443.json"
            payload = json.loads(routes_path.read_text(encoding="utf-8"))
            self.assertEqual(payload["routes"][0]["path_prefix"], "a43547b6350f72cd")
            unit_text = (agent.state_dir / "cf-forward-26.service.preview").read_text(encoding="utf-8")
            self.assertIn("wstunnel server ws://127.0.0.1:", unit_text)
            self.assertIn("--restrict-http-upgrade-path-prefix a43547b6350f72cd", unit_text)
```

- [ ] **Step 2: Run the test and verify it fails**

Run:

```bash
python -m unittest sidecar-agent.tests.test_sidecar_cf_mode_runtime
```

Expected: FAIL because there is no `cf_runtime` support and no WSS mux helper.

- [ ] **Step 3: Build the sidecar management payload and runtime files**

In `master/src/Controllers/SidecarAgentController.php`, build a new `cf_runtime` block from active `cf_mode` forwards on the point:

```php
$cfRows = $this->db->fetchAll(
    'SELECT f.id, f.path_prefix, f.target_host, f.target_port, f.transport_mode,
            sp.cf_listen_port, sp.sidecar_host, sp.agent_config_json
     FROM forwards f
     INNER JOIN services s ON s.id = f.service_id
     INNER JOIN sidecar_points sp ON sp.id = f.bond_sidecar_point_id
     WHERE f.bond_sidecar_point_id = :point_id
       AND f.enabled = 1
       AND f.backend_mode = :backend_mode
       AND s.status = :service_status
     ORDER BY f.id ASC',
    ['point_id' => $pointId, 'backend_mode' => 'cf_mode', 'service_status' => 'active']
);

$cfRuntime = $cfRows === [] ? null : [
    'listen_host' => (string) ($point['bind_host'] ?? '0.0.0.0'),
    'listen_port' => max(1, (int) ($point['cf_listen_port'] ?? 443)),
    'tls_cert_path' => (string) ($runtimeState['cf_tls_cert_path'] ?? '/etc/ssl/cf/fullchain.pem'),
    'tls_key_path' => (string) ($runtimeState['cf_tls_key_path'] ?? '/etc/ssl/cf/privkey.pem'),
    'routes' => array_map(static fn(array $row): array => [
        'forward_id' => (int) $row['id'],
        'service_name' => 'cf-forward-' . (int) $row['id'],
        'path_prefix' => (string) $row['path_prefix'],
        'target' => (string) $row['target_host'] . ':' . (int) $row['target_port'],
        'transport_mode' => (string) $row['transport_mode'],
        'shadow_route_key' => str_contains((string) $row['transport_mode'], 'udp') ? ((string) $row['path_prefix'] . '.uot') : '',
    ], $cfRows),
];
```

In `sidecar-agent/sidecar_agent.py`, add a new apply path:

```python
def load_managed_cf_metadata(self) -> list[dict[str, Any]]:
    path = self.state_dir / "cf-managed.json"
    if not path.exists():
        return []
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return []
    return payload if isinstance(payload, list) else []

def cf_unit_path(self, listen_port: int) -> pathlib.Path:
    return pathlib.Path(f"/etc/systemd/system/{self.service_name()}-cf-{listen_port}.service")

def render_cf_backend_unit(self, service_name: str, backend_port: int, target: str, path_prefix: str) -> str:
    return f"""[Unit]
Description=Leikwan CF Backend {service_name}
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/wstunnel server ws://127.0.0.1:{backend_port} \
  --restrict-to {target} \
  --restrict-http-upgrade-path-prefix {path_prefix} \
  --websocket-ping-frequency-sec 5
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""

def render_cf_mux_unit(self, listen_port: int, tls_cert_path: str, tls_key_path: str, routes_path: pathlib.Path) -> str:
    return f"""[Unit]
Description=Leikwan CF WSS Mux {listen_port}
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 /usr/local/bin/leikwan-wss-mux.py --listen-port {listen_port} --tls-cert {tls_cert_path} --tls-key {tls_key_path} --routes-file {routes_path}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""

def allocate_backend_port(self, service_name: str) -> int:
    used = {
        int(item["backend_port"])
        for item in self.load_managed_cf_metadata()
        if int(item.get("backend_port", 0) or 0) > 0 and str(item.get("service_name", "")) != service_name
    }
    for port in range(26000, 26100):
        if port not in used:
            return port
    raise RuntimeError("no free sidecar cf backend port available")

def apply_cf_runtime(self, runtime: dict[str, object]) -> None:
    listen_port = int(runtime.get("listen_port", 0) or 0)
    routes = runtime.get("routes", [])
    if listen_port <= 0 or not isinstance(routes, list) or routes == []:
        self.disable_runtime(f"{self.service_name()}-cf-{listen_port}", self.cf_unit_path(listen_port))
        stale_routes = self.state_dir / f"cf-routes-{listen_port}.json"
        if stale_routes.exists():
            stale_routes.unlink()
        return

    routes_path = self.state_dir / f"cf-routes-{listen_port}.json"
    routes_path.write_text(json.dumps({"routes": routes}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    mux_unit = self.render_cf_mux_unit(
        listen_port,
        str(runtime.get("tls_cert_path", "")),
        str(runtime.get("tls_key_path", "")),
        routes_path,
    )
    write_text_if_changed(self.cf_unit_path(listen_port), mux_unit)

    for route in routes:
        backend_port = self.allocate_backend_port(str(route["service_name"]))
        preview_path = self.state_dir / f"{route['service_name']}.service.preview"
        preview_path.write_text(
            self.render_cf_backend_unit(
                str(route["service_name"]),
                backend_port,
                str(route["target"]),
                str(route["path_prefix"]),
            ),
            encoding="utf-8",
        )
```

Create `sidecar-agent/leikwan_wss_mux.py` by copying the minimal `WssPathMux` implementation from `slave-agent/slave_agent.py` into its own module so the sidecar agent can run a TLS WSS listener with path-based backend routing without refactoring the slave file in phase one.

Also define the phase-one UDP-compatible branch here:

- when `transport_mode` contains `udp`, the sidecar should create an independent shadow route keyed by `path_prefix + ".uot"`
- that shadow route should terminate into the existing UDP compatibility runtime shape, not the main TCP bridge
- keep the shadow route lifecycle independent so UDP/WireGuard-compatible traffic can fail or restart without tearing down the main TCP route

- [ ] **Step 4: Download the required runtime binary on sidecars**

In `install/install-bond-sidecar.sh`, add:

```bash
download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/wstunnel_linux_amd64" "$BIN_DIR/wstunnel"
download_atomic "$ARTIFACT_BASE/artifacts/sidecar-agent/leikwan_wss_mux.py" "$BIN_DIR/leikwan-wss-mux.py"
```

If you keep `leikwan_wss_mux.py` outside `/artifacts/sidecar-agent/`, adjust the URL and artifact mapping consistently.

- [ ] **Step 5: Run the sidecar runtime tests**

Run:

```bash
python -m unittest sidecar-agent.tests.test_sidecar_cf_mode_runtime
php master/tests/provisioning_forward_combination_regression_test.php
```

Expected: both tests pass.

- [ ] **Step 6: Commit the sidecar runtime**

```bash
git add master/src/Controllers/SidecarAgentController.php sidecar-agent/leikwan_wss_mux.py sidecar-agent/sidecar_agent.py sidecar-agent/tests/test_sidecar_cf_mode_runtime.py install/install-bond-sidecar.sh
git commit -m "feat: add sidecar cf mode WSS runtime"
```

## Task 5: Slave Shared Best-IP Selection And WSS Bridge

**Files:**
- Create: `slave-agent/tests/test_slave_cf_location_group_selection.py`
- Modify: `slave-agent/slave_agent.py`
- Modify: `install/install-slave.sh`

- [ ] **Step 1: Write the failing slave selection test**

Create `slave-agent/tests/test_slave_cf_location_group_selection.py` with:

```python
import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
from unittest import mock

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module

slave_agent = load_module("slave_agent_cf_mode_under_test", "slave-agent/slave_agent.py")


class SlaveCfLocationSelectionTests(unittest.TestCase):
    def make_agent(self, tmp_dir: str):
        config_path = pathlib.Path(tmp_dir) / "config.json"
        config_path.write_text(json.dumps({
            "master_url": "https://panel.example.com",
            "node_token": "token-123",
            "state_dir": str(pathlib.Path(tmp_dir) / "state"),
            "systemd_dir": str(pathlib.Path(tmp_dir) / "systemd"),
            "wstunnel_bin": "/usr/local/bin/wstunnel",
            "backend_port_range_start": 24000,
            "backend_port_range_end": 24010,
        }), encoding="utf-8")
        return slave_agent.SlaveAgent(config_path)

    def test_same_group_reuses_selected_ip_across_forwards(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(agent, 'probe_cf_candidates', return_value=[{'ip': '104.16.10.10', 'latency_ms': 18.2, 'jitter_ms': 1.4, 'failures': 0}]):
                first = agent.select_cf_location_ip({
                    'location_group_id': 7,
                    'cidrs': ['104.16.0.0/13'],
                    'probe_mode': 'tcp',
                    'dial_port': 443,
                })
                second = agent.select_cf_location_ip({
                    'location_group_id': 7,
                    'cidrs': ['172.64.0.0/13'],
                    'probe_mode': 'tcp',
                    'dial_port': 443,
                })
            self.assertEqual(first['selected_ip'], '104.16.10.10')
            self.assertEqual(second['selected_ip'], '104.16.10.10')

    def test_render_cf_backend_unit_uses_selected_ip_and_sni(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            unit = agent.render_cf_wss_backend_unit(
                service_name='wstunnel-svc-1-184',
                backend_port=24001,
                target='127.0.0.1:51820',
                selected_ip='104.16.10.10',
                remote_port=443,
                sni_host='nrt.example.com',
                path_prefix='a43547b6350f72cd',
                transport_mode='tcp',
            )
            self.assertIn('wss://104.16.10.10:443', unit)
            self.assertIn('--tls-sni-override nrt.example.com', unit)
            self.assertIn('--http-headers "Host: nrt.example.com"', unit)
            self.assertIn('--http-upgrade-path-prefix "a43547b6350f72cd"', unit)
```

- [ ] **Step 2: Run the test and verify it fails**

Run:

```bash
python -m unittest slave-agent.tests.test_slave_cf_location_group_selection
```

Expected: FAIL because there is no group-scoped cache or `render_cf_wss_backend_unit(...)`.

- [ ] **Step 3: Add the shared location-group cache and candidate scoring**

In `slave-agent/slave_agent.py`, add:

```python
def cf_location_state_path(self) -> Path:
    return self.state_dir / "cf-location-best-ip.json"


def load_cf_location_state(self) -> dict[str, Any]:
    path = self.cf_location_state_path()
    if not path.exists():
        return {}
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}
    return payload if isinstance(payload, dict) else {}


def save_cf_location_state(self, payload: dict[str, Any]) -> None:
    self.write_json(self.cf_location_state_path(), payload)


def select_cf_location_ip(self, cf_mode: dict[str, Any]) -> dict[str, Any]:
    group_id = int(cf_mode.get("location_group_id", 0) or 0)
    state = self.load_cf_location_state()
    cache_key = str(group_id)
    cached = state.get(cache_key, {}) if isinstance(state.get(cache_key), dict) else {}
    now = time.time()
    ttl = max(30, int(cf_mode.get("cache_ttl_seconds", 120) or 120))
    if cached and (now - float(cached.get("last_probe_at", 0) or 0)) < ttl and str(cached.get("selected_ip", "")) != "":
        return cached

    candidates = self.expand_cf_candidates(list(cf_mode.get("cidrs", [])), int(cf_mode.get("max_candidates", 32) or 32))
    results = self.probe_cf_candidates(candidates, int(cf_mode.get("dial_port", 443) or 443), str(cf_mode.get("probe_mode", "auto") or "auto"))
    best = sorted(results, key=lambda item: (item["latency_ms"], item["jitter_ms"], item["failures"]))[0]
    state[cache_key] = {
        "location_group_id": group_id,
        "selected_ip": best["ip"],
        "last_latency_ms": best["latency_ms"],
        "last_jitter_ms": best["jitter_ms"],
        "last_probe_at": now,
        "backup_ips": [item["ip"] for item in results[1:4]],
        "consecutive_failures": 0,
        "cooldown_until": 0,
    }
    self.save_cf_location_state(state)
    return state[cache_key]
```

Implement `expand_cf_candidates(...)` with `ipaddress.ip_network(..., strict=False)` and cap the total candidates to `max_candidates`. Implement `probe_cf_candidates(...)` using the same TCP-connect timing style already used elsewhere in the repo: sort by latency first, jitter second, and record failures.

- [ ] **Step 4: Add the WSS backend bridge for `cf_mode`**

In `slave-agent/slave_agent.py`, branch `publish_task(...)` before the current `render_backend_unit(...)` path:

```python
cf_mode = payload.get("cf_mode", {}) if isinstance(payload.get("cf_mode", {}), dict) else {}
backend_mode = str(payload.get("backend_mode", "current_mode") or "current_mode")

if backend_mode == "cf_mode" and cf_mode:
    selection = self.select_cf_location_ip(cf_mode)
    unit_body = self.render_cf_wss_backend_unit(
        service_name=service_name,
        backend_port=backend_port,
        target=target,
        selected_ip=str(selection["selected_ip"]),
        remote_port=int(cf_mode.get("dial_port", 443) or 443),
        sni_host=str(cf_mode.get("sni_host", "") or ""),
        path_prefix=str(cf_mode.get("path_prefix", path_prefix) or path_prefix),
        transport_mode=transport_mode,
    )
    if "udp" in transport_mode:
        self.ensure_cf_uot_shadow_unit(
            service_name=service_name,
            selected_ip=str(selection["selected_ip"]),
            remote_port=int(cf_mode.get("dial_port", 443) or 443),
            sni_host=str(cf_mode.get("sni_host", "") or ""),
            route_key=str(cf_mode.get("path_prefix", path_prefix) or path_prefix) + ".uot",
            target=target,
        )
else:
    unit_body = self.render_backend_unit(service_name, backend_port, target, path_prefix)
```

Add the renderer:

```python
def render_cf_wss_backend_unit(
    self,
    service_name: str,
    backend_port: int,
    target: str,
    selected_ip: str,
    remote_port: int,
    sni_host: str,
    path_prefix: str,
    transport_mode: str,
) -> str:
    local_proto = "udp" if transport_mode == "udp" else "tcp"
    return f"""[Unit]
Description=Leikwan CF WSS Backend {service_name}
After=network.target

[Service]
Type=simple
ExecStart={self.config['wstunnel_bin']} client -L {local_proto}://127.0.0.1:{backend_port}:{target} \
  --tls-sni-override {shell_arg(sni_host)} \
  --http-headers "Host: {sni_host}" \
  --http-upgrade-path-prefix "{path_prefix}" \
  wss://{selected_ip}:{remote_port}
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
"""
```

Persist the selected CF IP into service metadata so admin observability can surface it later.

The new `ensure_cf_uot_shadow_unit(...)` helper should mirror the current bond-side UDP sidepath idea:

- separate process from the main TCP bridge
- same selected CF IP
- same SNI
- separate shadow route key
- usable for `tcp+udp` and `udp`

- [ ] **Step 5: Ensure fresh installs have `wstunnel` available**

In `install/install-slave.sh`, add:

```bash
curl -fsSL "$MASTER_URL/artifacts/wstunnel/wstunnel_linux_amd64" -o "$BIN_DIR/wstunnel"
chmod 0755 "$BIN_DIR/wstunnel"
```

Place this next to the existing `leikwan-ingress` download so the new backend units do not depend on manual system prep.

- [ ] **Step 6: Run the slave tests**

Run:

```bash
python -m unittest slave-agent.tests.test_slave_cf_location_group_selection
python -m unittest slave-agent.tests.test_slave_plain_tcp_mux
```

Expected: both tests pass.

- [ ] **Step 7: Commit the slave runtime**

```bash
git add slave-agent/slave_agent.py slave-agent/tests/test_slave_cf_location_group_selection.py install/install-slave.sh
git commit -m "feat: add slave CF group best-ip selection and WSS bridge"
```

## Task 6: Observability And Final Verification

**Files:**
- Create: `master/tests/admin_cf_location_observability_test.php`
- Modify: `slave-agent/slave_agent.py`
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Support/ProvisioningService.php`

- [ ] **Step 1: Write the failing observability regression test**

Create `master/tests/admin_cf_location_observability_test.php` with:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');

foreach ([
    'Current Best CF IP',
    'last_jitter_ms',
    'last_latency_ms',
    'forward_combination_name',
] as $snippet) {
    if (!str_contains($admin . $tenant, $snippet)) {
        fail('missing observability snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the test and verify it fails**

Run:

```bash
php master/tests/admin_cf_location_observability_test.php
```

Expected: FAIL because no CF-selection stats are surfaced yet.

- [ ] **Step 3: Send and render location-group stats**

In `slave-agent/slave_agent.py`, add the latest group selection state to the existing heartbeat payload:

```python
payload["cf_location_stats"] = list(self.load_cf_location_state().values())
```

No panel schema change is required here because `NodeController::heartbeat(...)` already stores the full request body in `node_heartbeats.payload_json`.

In `master/src/Controllers/AdminController.php`, extend the new forward-combinations page with a small details table built from the latest node heartbeat payloads:

```php
$latestHeartbeats = $this->db->fetchAll(
    'SELECT nh.node_id, nh.payload_json, n.name AS node_name
     FROM node_heartbeats nh
     INNER JOIN nodes n ON n.id = nh.node_id
     INNER JOIN (
         SELECT node_id, MAX(id) AS max_id
         FROM node_heartbeats
         GROUP BY node_id
     ) latest ON latest.max_id = nh.id
     ORDER BY n.name ASC'
);
```

Render rows for any `cf_location_stats` entries with columns:

- node name
- location group id
- current best IP
- latency
- jitter
- last probe timestamp

Also show `forward_combination_name` in tenant forward tables and the build-client-config output so future diagnostics can correlate runtime state with the admin-selected combination.

- [ ] **Step 4: Run the full verification set**

Run:

```bash
php master/tests/provisioning_forward_combination_regression_test.php
php master/tests/admin_forward_combination_page_test.php
php master/tests/tenant_forward_combination_regression_test.php
php master/tests/admin_cf_location_observability_test.php
python -m unittest sidecar-agent.tests.test_sidecar_cf_mode_runtime
python -m unittest slave-agent.tests.test_slave_cf_location_group_selection
python -m unittest slave-agent.tests.test_slave_plain_tcp_mux
```

Expected: every command passes.

- [ ] **Step 5: Commit the observability pass**

```bash
git add master/src/Controllers/AdminController.php master/src/Support/ProvisioningService.php master/tests/admin_cf_location_observability_test.php slave-agent/slave_agent.py
git commit -m "feat: add CF location observability for admin"
```

## Self-Review Checklist

- **Spec coverage:**
  - independent `cf_location_groups` asset model -> Task 1
  - admin-only `forward_combinations` -> Tasks 1 and 2
  - tenant chooses combination only -> Task 3
  - sidecar stays unaware of selected CF IP -> Task 4
  - slave shares one best IP per location group -> Task 5
  - `single` and `bond` only, reject `race` and `mptcp` -> Tasks 1 through 3
  - admin observability for current selected IP / latency / jitter -> Task 6
- **Placeholder scan:** no `TBD`, `TODO`, or “implement later” steps remain.
- **Type consistency:** the plan uses one backend-mode vocabulary throughout: `current_mode` and `cf_mode`; one client-mode vocabulary throughout: `single` and `bond`.

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-04-20-cf-location-groups-forward-combinations.md`. Two execution options:**

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**

## GSTACK REVIEW REPORT

| Review | Trigger | Why | Runs | Status | Findings |
|--------|---------|-----|------|--------|----------|
| CEO Review | `/plan-ceo-review` | Scope & strategy | 0 | - | not run |
| Codex Review | `/codex review` | Independent 2nd opinion | 0 | - | not run |
| Eng Review | `/plan-eng-review` | Architecture & tests (required) | 2 | clean | 5 issues, 0 critical gaps |
| Design Review | `/plan-design-review` | UI/UX gaps | 1 | clean | score: 7/10 -> 8/10, 4 decisions |
| DX Review | `/plan-devex-review` | Developer experience gaps | 0 | - | not run |

- **UNRESOLVED:** 0
- **VERDICT:** design + eng cleared, ready to implement

