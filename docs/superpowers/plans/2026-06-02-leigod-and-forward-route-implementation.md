# LeiGod Basis + Forward Route — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship two sequenced, composable features: (A) **LeiGod** as a third slave↔sidecar WG outer-transport ("basis"), peer to cnix-direct (`udp`) and cf-wstunnel (`wstunnel_udp`); then (B) **Forward Route**, a user-facing bundle of combinations that the ingress slave auto-races across (cnix / cf / leigod), failing over when a basis (e.g. CF) is blocked.

**Architecture:** LeiGod adds a new `sidecar_uplink.outer_transport='leigod'` value. The slave runs `leigod@<line>` (SOCKS `127.0.0.1:20000+line_id`) + a **gost** UDP-over-TCP client that carries the slave↔sidecar WG through the LeiGod line; the **sidecar** runs the matching **gost** UoT server. Forward Route generalizes the existing slave selector (`evaluate_sidecar_uplink_selector`) from "candidate = WG lane" to "candidate = basis," racing the per-combination sidecar uplinks (all from one shared ingress slave) with the selector's existing hysteresis/cooldown/kill-switch. Both compose: a Route races cnix/cf/leigod uplinks; leigod must exist first so there are three real bases.

**Tech Stack:** PHP panel (`master/`, raw PDO + handwritten test harnesses), Python agents (`slave-agent/`, `sidecar-agent/`, stdlib `unittest`), bash installers (`install/`), `versions.json` artifact channel, gost v3.2.6 (Go binary), the recovered `leigod-linux-amd64`.

**Specs:** `docs/superpowers/specs/2026-06-02-leigod-third-forwarding-basis-design.md` (Part A); `docs/superpowers/specs/2026-06-02-forward-route-design.md` (Part B).

---

## Conventions used in this plan

- **Run panel tests:** `php master/tests/<name>.php` (each test file is a standalone runner that prints `OK`/`FAIL` and exits non-zero on failure). Run the suite with `for f in master/tests/*.php; do php "$f" || break; done`.
- **Run agent tests:** `python -m pytest <path>` if pytest is present, else `python <path>` (the repo's agent tests are stdlib `unittest` runnable directly).
- **Dual-write rule (LeiGod + Forward Route):** `slave-agent/slave_agent.py` and `artifacts/slave-agent.py` are byte-identical and MUST both be edited; bump `AGENT_VERSION` (currently `2026.06.02.1`) and `artifacts/versions.json` or the fleet never pulls the change. Verify with `diff -q slave-agent/slave_agent.py artifacts/slave-agent.py` (expect identical).
- **Commit cadence:** one commit per task (after its tests pass). Branch off `master` first: `git checkout -b feat/leigod-and-forward-route`.
- **No deploy** beyond what the user authorizes; this plan stops at green local tests + a documented live-validation runbook.

---

## File Structure

**Part A — LeiGod basis**
- `master/migrations/20260602_add_leigod_self_leg_transport.sql` — CREATE (cols on `forward_combinations`; `nodes.agent_config_json` usage documented).
- `master/migrations/schema.sql` — MODIFY (mirror the new columns).
- `master/src/Support/ProvisioningService.php` — MODIFY (`staticSidecarCombinationUsesLeigod`, transport decision L5451, `buildStaticSidecarUplinkPairForNode` leigod keys, `stableSidecarGostSocksPort`, cred mint, `saveForwardCombination` guard).
- `master/src/Controllers/AdminController.php` — MODIFY (self-leg transport + line picker, feature-flagged).
- `sidecar-agent/sidecar_agent.py` — MODIFY (`apply_slave_sidecar_uplink` leigod branch, `ensure_sidecar_leigod_gost_server_service`, teardown).
- `slave-agent/slave_agent.py` + `artifacts/slave-agent.py` — MODIFY (`apply_sidecar_uplink` leigod arm, `ensure_sidecar_gost_uot_client_service`, `ensure_leigod_line_service`/refcount, orphan reap, `DEFAULT_CONFIG`, `AGENT_VERSION`).
- `install/install-slave.sh`, `install/install-bond-sidecar.sh` — MODIFY (fetch gost + leigod binary, sha256-skip).
- `artifacts/versions.json` — MODIFY (gost + leigod binary entries + sha256).
- Tests: `master/tests/provisioning_leigod_self_leg_transport_regression_test.php`, `master/tests/provisioning_leigod_gost_port_allocator_test.php`, `master/tests/saveforwardcombination_leigod_guard_test.php`, `sidecar-agent/tests/test_static_sidecar_uplinks.py` (extend), `slave-agent/tests/test_leigod_sidecar_uplink.py` (new).

**Part B — Forward Route**
- `master/migrations/20260602_add_forward_routes.sql` — CREATE; `schema.sql` — MODIFY.
- `master/src/Support/ProvisioningService.php` — MODIFY (`availableForwardRoutesForService`, `saveForwardRoute`, ingress-mismatch validation, `reconcileForwardEndpoints` loop, `publishTaskPayloadForForward` `route_race_candidates`, generalize `staticSidecarUplinkSelectorPayload`, route-stable port seed, `republishActiveForwardsForRoute`).
- `master/src/Controllers/AdminController.php` — MODIFY (`/admin/forward-routes` page + save; plan→route checkbox group).
- `master/src/Controllers/TenantController.php` — MODIFY (Route dropdown replaces combination dropdown).
- `master/src/Bootstrap.php` — MODIFY (route wiring).
- `slave-agent/slave_agent.py` + `artifacts/slave-agent.py` — MODIFY (selector candidate=basis generalization, warm standby, demotion, active-combo heartbeat).
- Tests: `master/tests/forward_routes_schema_test.php`, `master/tests/saveforwardroute_ingress_guard_test.php`, `master/tests/publish_route_race_candidates_test.php`, `master/tests/forward_route_selector_port_stable_test.php`, `master/tests/tenant_forward_route_selector_test.php`, `slave-agent/tests/test_route_race_selector.py`.

---

# PART A — LeiGod basis

> Sequencing within Part A: data model → panel publish → sidecar realize → slave realize → install → accounts/UI. Each task is independently testable.

## Task A1: Panel data model + migration for the leigod basis

**Files:**
- Create: `master/migrations/20260602_add_leigod_self_leg_transport.sql`
- Modify: `master/migrations/schema.sql` (the `forward_combinations` CREATE TABLE, ~L141-173)
- Test: `master/tests/provisioning_leigod_self_leg_transport_regression_test.php`

- [ ] **Step 1: Write the failing test** (`provisioning_leigod_self_leg_transport_regression_test.php`)

```php
<?php
// Verifies the schema + migration declare the leigod self-leg columns.
require __DIR__ . '/../vendor/autoload.php'; // if present; else require the file directly
$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
$migration = file_get_contents(__DIR__ . '/../migrations/20260602_add_leigod_self_leg_transport.sql');
$fail = 0;
function expect(bool $cond, string $msg, int &$fail): void { if (!$cond) { fprintf(STDERR, "FAIL: %s\n", $msg); $fail++; } }
expect(str_contains($schema, 'self_leg_outer_transport'), 'schema has self_leg_outer_transport', $fail);
expect(str_contains($schema, 'leigod_line_id'), 'schema has leigod_line_id', $fail);
expect(str_contains($schema, 'leigod_socks_user'), 'schema has leigod_socks_user', $fail);
expect(str_contains($schema, 'leigod_socks_pass'), 'schema has leigod_socks_pass', $fail);
expect(str_contains($migration, 'ALTER TABLE forward_combinations'), 'migration alters forward_combinations', $fail);
expect(str_contains($migration, "self_leg_outer_transport"), 'migration adds self_leg_outer_transport', $fail);
if ($fail) { fprintf(STDERR, "%d FAIL\n", $fail); exit(1); }
echo "OK\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php master/tests/provisioning_leigod_self_leg_transport_regression_test.php`
Expected: FAIL (migration file missing / columns absent).

- [ ] **Step 3: Create the migration**

```sql
-- 20260602_add_leigod_self_leg_transport.sql
-- LeiGod = a 3rd slave<->sidecar WG outer-transport. Selected per forward_combination.
ALTER TABLE forward_combinations
  ADD COLUMN self_leg_outer_transport ENUM('cnix','cf','leigod') NOT NULL DEFAULT 'cnix'
    COMMENT 'slave<->sidecar WG outer transport basis' AFTER self_leg_cf_transport,
  ADD COLUMN leigod_line_id INT UNSIGNED NULL
    COMMENT 'chosen LeiGod line; slave SOCKS = 127.0.0.1:(20000+leigod_line_id)' AFTER self_leg_outer_transport,
  ADD COLUMN leigod_socks_user VARCHAR(64) NULL COMMENT 'panel-minted gost SOCKS user' AFTER leigod_line_id,
  ADD COLUMN leigod_socks_pass VARCHAR(128) NULL COMMENT 'panel-minted gost SOCKS pass (secret)' AFTER leigod_socks_user;
-- Per-slave LeiGod account metadata lives in nodes.agent_config_json->'$.leigod'
-- (account_label, expired_time, session_present); the session.json blob is delivered
-- via management state, never a DB column.
```

- [ ] **Step 4: Mirror the columns into `schema.sql`** (inside the `forward_combinations` CREATE TABLE, after the `self_leg_cf_transport` column):

```sql
    self_leg_outer_transport ENUM('cnix','cf','leigod') NOT NULL DEFAULT 'cnix',
    leigod_line_id INT UNSIGNED NULL,
    leigod_socks_user VARCHAR(64) NULL,
    leigod_socks_pass VARCHAR(128) NULL,
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php master/tests/provisioning_leigod_self_leg_transport_regression_test.php`
Expected: `OK`

- [ ] **Step 6: Commit**

```bash
git add master/migrations/20260602_add_leigod_self_leg_transport.sql master/migrations/schema.sql master/tests/provisioning_leigod_self_leg_transport_regression_test.php
git commit -m "feat(panel): add leigod self-leg transport columns to forward_combinations"
```

---

## Task A2: Panel — leigod detection, transport decision, port allocator, cred mint, save guard

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
  - new `staticSidecarCombinationUsesLeigod()` (next to `staticSidecarCombinationUsesCf` ~L7798)
  - transport decision at `buildStaticSidecarUplinkPairForNode` ~L5451
  - new `stableSidecarGostSocksPort()` (model on `stableStaticLocalUdpPort` ~L7614 / `allocateStaticSidecarUplinkPort` ~L7904)
  - cred mint helper `mintLeigodSocksCredentials()`
  - `saveForwardCombination` guard (~L461/L470 region)
- Test: `master/tests/provisioning_leigod_self_leg_transport_regression_test.php` (extend with behavioral asserts), `master/tests/provisioning_leigod_gost_port_allocator_test.php`, `master/tests/saveforwardcombination_leigod_guard_test.php`

- [ ] **Step 1: Write the failing behavioral test** (extend the A1 test file or add `publishLeigodPair` asserts). Add a new test `master/tests/provisioning_leigod_gost_port_allocator_test.php`:

```php
<?php
require __DIR__ . '/bootstrap_test.php'; // existing helper that loads ProvisioningService with fakes; if absent, mirror an existing provisioning test's setup
$svc = make_provisioning_service_with_fakes(); // existing factory used by provisioning_* tests
$ref = new ReflectionMethod($svc, 'stableSidecarGostSocksPort');
$ref->setAccessible(true);
$p1 = $ref->invoke($svc, /*forwardCombinationId*/ 101, /*nodeId*/ 7);
$p2 = $ref->invoke($svc, 101, 7);
$fail = 0;
if ($p1 !== $p2) { fprintf(STDERR, "FAIL: allocator not stable\n"); $fail++; }
// Must avoid known ranges: 20000-40000 backend, 26000-26999 cf, 43000-44999 wg-local, 45000-45999 uot, selector range.
foreach ([[20000,40000],[26000,26999],[43000,44999],[45000,45999]] as [$lo,$hi]) {
  if ($p1 >= $lo && $p1 <= $hi) { fprintf(STDERR, "FAIL: port %d in reserved range %d-%d\n", $p1, $lo, $hi); $fail++; }
}
if ($fail) exit(1);
echo "OK\n";
```

> NOTE to engineer: open an existing `master/tests/provisioning_*_test.php` (e.g. `provisioning_cf_mode_japan_vm_realization_regression_test.php`) and copy its exact bootstrap/fakes setup — reuse the same `Config`/`Database` fake classes and the same service-construction pattern. Do not invent a new harness.

- [ ] **Step 2: Run to verify it fails**

Run: `php master/tests/provisioning_leigod_gost_port_allocator_test.php`
Expected: FAIL (`stableSidecarGostSocksPort` undefined).

- [ ] **Step 3: Implement `staticSidecarCombinationUsesLeigod()`** (next to `staticSidecarCombinationUsesCf`):

```php
private function staticSidecarCombinationUsesLeigod(array $combination): bool
{
    $t = strtolower(trim((string) ($combination['self_leg_outer_transport'] ?? 'cnix')));
    return $t === 'leigod' && (int) ($combination['leigod_line_id'] ?? 0) > 0;
}
```

- [ ] **Step 4: Implement `stableSidecarGostSocksPort()`** (deterministic, collision-free, outside reserved ranges). Pick a band the codebase does not already use — e.g. `47000-47999`:

```php
private function stableSidecarGostSocksPort(int $forwardCombinationId, int $nodeId): int
{
    // Deterministic per (combination,node) so the sidecar gost SOCKS port is stable
    // across republishes. Band 47000-47999 is disjoint from backend(20000-40000),
    // cf(26000-26999), wg-local(43000+hash%2000), uot(45000+), and selector ports.
    $seed = crc32("leigod-gost-socks:{$forwardCombinationId}:{$nodeId}");
    return 47000 + ($seed % 1000);
}
```

- [ ] **Step 5: Implement `mintLeigodSocksCredentials()`** and wire the transport decision at `buildStaticSidecarUplinkPairForNode` (~L5451). Replace the binary transport decision with a three-way one and emit leigod keys on both halves:

```php
// at the transport decision (was: $outer = $cfMode ? 'wstunnel_udp' : 'udp';)
$leigodMode = $this->staticSidecarCombinationUsesLeigod($combination);
$outer = $leigodMode ? 'leigod' : ($cfMode ? 'wstunnel_udp' : 'udp');

// ...later, where cf re-pins the slave endpoint (~L5509-5510), add a leigod branch:
if ($leigodMode) {
    $lineId      = (int) $combination['leigod_line_id'];
    $gostUdpPort = $this->stableStaticLocalUdpPort($slave);            // local gost -L udp listener
    $gostSocks   = $this->stableSidecarGostSocksPort((int) $combination['id'], (int) $sidecarNodeId);
    [$socksUser, $socksPass] = $this->mintLeigodSocksCredentials($combination);
    // SLAVE half
    $slave['outer_transport']       = 'leigod';
    $slave['leigod_line_id']        = $lineId;
    $slave['gost_listen_udp_port']  = $gostUdpPort;
    $slave['sidecar_gost_host']     = $sidecarHost;                    // LeiGod-line-reachable sidecar IP
    $slave['sidecar_gost_port']     = $gostSocks;
    $slave['sidecar_wg_listen_port']= $sidecarWgListenPort;            // gost -L logical delivery target
    $slave['socks_user']            = $socksUser;
    $slave['socks_pass']            = $socksPass;
    $slave['mtu']                   = 1280;
    $slave['sidecar_endpoint_host'] = '127.0.0.1';
    $slave['sidecar_endpoint_port'] = $gostUdpPort;
    // SIDECAR half
    $sidecar['outer_transport']      = 'leigod';
    $sidecar['gost_socks_listen_port'] = $gostSocks;
    $sidecar['socks_user']           = $socksUser;
    $sidecar['socks_pass']           = $socksPass;
    // sidecar['listen_port'] (WG UDP) is already set by the shared builder.
}
```

```php
private function mintLeigodSocksCredentials(array $combination): array
{
    $user = (string) ($combination['leigod_socks_user'] ?? '') ?: 'wguot';
    $pass = (string) ($combination['leigod_socks_pass'] ?? '');
    if ($pass === '') {
        $pass = rtrim(strtr(base64_encode(random_bytes(21)), '+/', '-_'), '='); // 168-bit url-safe
    }
    return [$user, $pass];
}
```

> NOTE: `$sidecarHost`, `$sidecarWgListenPort`, `$sidecarNodeId`, `$slave`, `$sidecar` are the existing locals in `buildStaticSidecarUplinkPairForNode`; confirm their exact names when editing (the cf branch nearby uses them). If a minted pass must persist, write it back to `forward_combinations.leigod_socks_pass` once (so both halves share a stable secret) — do this in `saveForwardCombination`, Step 7.

- [ ] **Step 6: Run the allocator test**

Run: `php master/tests/provisioning_leigod_gost_port_allocator_test.php`
Expected: `OK`

- [ ] **Step 7: Add the `saveForwardCombination` guard** (`master/tests/saveforwardcombination_leigod_guard_test.php` first):

```php
<?php
require __DIR__ . '/bootstrap_test.php';
$svc = make_provisioning_service_with_fakes();
$fail = 0;
try {
  $svc->saveForwardCombination(['name'=>'x','self_leg_outer_transport'=>'leigod','leigod_line_id'=>0,'sidecar_point_id'=>5]);
  fprintf(STDERR, "FAIL: expected leigod_line_required\n"); $fail++;
} catch (\Throwable $e) {
  if (!str_contains($e->getMessage(),'leigod_line_required')) { fprintf(STDERR,"FAIL: wrong error %s\n",$e->getMessage()); $fail++; }
}
try {
  $svc->saveForwardCombination(['name'=>'x','self_leg_outer_transport'=>'leigod','leigod_line_id'=>451,'sidecar_point_id'=>0]);
  fprintf(STDERR, "FAIL: expected sidecar_required_for_leigod\n"); $fail++;
} catch (\Throwable $e) {
  if (!str_contains($e->getMessage(),'sidecar')) { fprintf(STDERR,"FAIL: wrong error %s\n",$e->getMessage()); $fail++; }
}
if ($fail) exit(1);
echo "OK\n";
```

Then in `saveForwardCombination`, after the existing cf gate (~L470), add:

```php
$selfLeg = strtolower(trim((string) ($payload['self_leg_outer_transport'] ?? 'cnix')));
if (!in_array($selfLeg, ['cnix','cf','leigod'], true)) {
    throw new ApiException('invalid_self_leg_outer_transport', 422, ['self_leg_outer_transport' => $selfLeg]);
}
if ($selfLeg === 'leigod') {
    if ((int) ($payload['leigod_line_id'] ?? 0) <= 0) {
        throw new ApiException('leigod_line_required', 422, []);
    }
    if ((int) ($payload['sidecar_point_id'] ?? 0) <= 0) {
        throw new ApiException('sidecar_required_for_leigod', 422, []);
    }
} else {
    $payload['leigod_line_id'] = null; // null out when not leigod
}
```

Thread `self_leg_outer_transport`, `leigod_line_id`, `leigod_socks_user`, `leigod_socks_pass` into `$normalized` (~L477) and the INSERT/UPDATE column lists (~L514/L536).

- [ ] **Step 8: Run all three panel tests**

Run: `php master/tests/provisioning_leigod_self_leg_transport_regression_test.php && php master/tests/provisioning_leigod_gost_port_allocator_test.php && php master/tests/saveforwardcombination_leigod_guard_test.php`
Expected: three `OK`.

- [ ] **Step 9: Commit**

```bash
git add master/src/Support/ProvisioningService.php master/tests/provisioning_leigod_gost_port_allocator_test.php master/tests/saveforwardcombination_leigod_guard_test.php
git commit -m "feat(panel): leigod transport decision, gost socks port allocator, cred mint, save guard"
```

---

## Task A3: Sidecar-agent — leigod gost UoT server branch

**Files:**
- Modify: `sidecar-agent/sidecar_agent.py` (`apply_slave_sidecar_uplink` dispatch ~L361; new `ensure_sidecar_leigod_gost_server_service` modeled on `ensure_sidecar_uot_server_service` L487; teardown wired into `remove_slave_sidecar_uplink` L411)
- Test: `sidecar-agent/tests/test_static_sidecar_uplinks.py` (extend)

- [ ] **Step 1: Write the failing test** (append to `test_static_sidecar_uplinks.py`):

```python
def test_leigod_uplink_renders_gost_server_unit(self):
    agent, tmp = self._make_agent()  # reuse the file's existing agent factory + tmp systemd dir
    uplink = {
        "interface_name": "lwsc4n9-r1", "local_address": "10.22.0.2", "peer_address": "10.22.0.1",
        "slave_peer_public_key": "PUBKEYAAA=", "listen_port": 51820,
        "outer_transport": "leigod", "gost_socks_listen_port": 47123,
        "socks_user": "wguot", "socks_pass": "s3cret",
    }
    agent.apply_slave_sidecar_uplink(uplink)
    unit = (tmp / "leikwan-leigod-gost-server-lwsc4n9-r1.service").read_text()
    self.assertIn("gost -L", unit)
    self.assertIn("socks5://wguot:s3cret@:47123?udp=true", unit)
    # WG conf still renders ListenPort=51820 (unchanged path)
    conf = agent.sidecar_uplink_interfaces_dir().joinpath("lwsc4n9-r1.conf").read_text()
    self.assertIn("ListenPort = 51820", conf)
```

> Mirror the file's existing `_make_agent`/tmp-dir pattern (the cf/udp tests at the top of the file show it). If `ensure_sidecar_leigod_gost_server_service` writes to `/etc/systemd/system`, override that dir in the agent config like the existing tests do.

- [ ] **Step 2: Run to verify it fails**

Run: `python -m pytest sidecar-agent/tests/test_static_sidecar_uplinks.py -k leigod -q`
Expected: FAIL (`leikwan-leigod-gost-server-*.service` not written).

- [ ] **Step 3: Add the leigod branch in `apply_slave_sidecar_uplink`** (after the `tcp` branch, ~L361-364):

```python
        gost_socks_listen_port = max(0, int(uplink.get("gost_socks_listen_port", 0) or 0))
        socks_user = str(uplink.get("socks_user", "") or "").strip()
        socks_pass = str(uplink.get("socks_pass", "") or "").strip()
        if outer_transport == "leigod" and gost_socks_listen_port > 0 and listen_port > 0 and socks_user != "" and socks_pass != "":
            self.ensure_sidecar_leigod_gost_server_service(interface_name, gost_socks_listen_port, socks_user, socks_pass)
        else:
            self.remove_sidecar_leigod_gost_server_service(interface_name)
```

- [ ] **Step 4: Add `ensure_sidecar_leigod_gost_server_service` + remove** (model on `ensure_sidecar_uot_server_service` L487):

```python
    def ensure_sidecar_leigod_gost_server_service(self, interface_name: str, listen_tcp_port: int, socks_user: str, socks_pass: str) -> None:
        if listen_tcp_port <= 0 or socks_user == "" or socks_pass == "":
            return
        service_name = f"leikwan-leigod-gost-server-{interface_name}"
        unit_path = pathlib.Path(f"/etc/systemd/system/{service_name}.service")
        gost_bin = str(self.config.get("gost_bin", "/usr/local/bin/gost") or "/usr/local/bin/gost")
        body = f"""[Unit]
Description=Leikwan LeiGod gost UoT Server {service_name}
After=network.target

[Service]
Type=simple
ExecStart={gost_bin} -L socks5://{socks_user}:{socks_pass}@:{listen_tcp_port}?udp=true
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""
        changed = write_text_if_changed(unit_path, body, mode=0o644)
        if shutil.which("systemctl") is not None and changed:
            subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "enable", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "restart", f"{service_name}.service"], text=True, capture_output=True, check=False)

    def remove_sidecar_leigod_gost_server_service(self, interface_name: str) -> None:
        service_name = f"leikwan-leigod-gost-server-{interface_name}"
        unit_path = pathlib.Path(f"/etc/systemd/system/{service_name}.service")
        if shutil.which("systemctl") is not None:
            subprocess.run(["systemctl", "stop", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "disable", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)
        if unit_path.exists():
            unit_path.unlink()
```

- [ ] **Step 5: Wire teardown** into `remove_slave_sidecar_uplink` (after L411 `self.remove_sidecar_uot_server_service`):

```python
        self.remove_sidecar_leigod_gost_server_service(interface_name)
```

- [ ] **Step 6: Run the test**

Run: `python -m pytest sidecar-agent/tests/test_static_sidecar_uplinks.py -k leigod -q`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add sidecar-agent/sidecar_agent.py sidecar-agent/tests/test_static_sidecar_uplinks.py
git commit -m "feat(sidecar): leigod gost UoT server branch + teardown"
```

---

## Task A4: Slave-agent — leigod gost client arm + leigod@line refcount + orphan reap

**Files:**
- Modify: `slave-agent/slave_agent.py` AND `artifacts/slave-agent.py` (identical edits)
  - `DEFAULT_CONFIG` (~L155-182): add `gost_bin`, `leigod_bin`.
  - `apply_sidecar_uplink` (~L4274/L4330/L4335): new leigod arm before the `else`.
  - new `ensure_sidecar_gost_uot_client_service` (model on `ensure_sidecar_uot_client_service` L4436).
  - new `ensure_leigod_line_service` / `remove_leigod_line_service` (refcounted).
  - new `remove_sidecar_gost_uot_client_service` wired into `remove_sidecar_uplink` L4390.
  - `cleanup_orphan_sidecar_uplinks` (~L5023/L5030): add `leikwan-gost-lws*` glob+regex.
  - `AGENT_VERSION` bump.
- Test: `slave-agent/tests/test_leigod_sidecar_uplink.py` (new)

- [ ] **Step 1: Write the failing test** (`slave-agent/tests/test_leigod_sidecar_uplink.py`):

```python
import os, pathlib, tempfile, unittest
import slave_agent  # adjust import to match how the repo's slave-agent tests import the module

class LeigodUplinkTest(unittest.TestCase):
    def _agent(self, sysdir):
        a = slave_agent.SlaveAgent.__new__(slave_agent.SlaveAgent)  # or the test factory the repo uses
        a.config = {"systemd_dir": str(sysdir), "gost_bin": "/usr/local/bin/gost", "leigod_bin": "/usr/local/bin/leigod-linux-amd64"}
        return a

    def test_gost_client_unit_exact_flags(self):
        with tempfile.TemporaryDirectory() as d:
            sysdir = pathlib.Path(d); a = self._agent(sysdir)
            a.ensure_sidecar_gost_uot_client_service(
                "lwsc4n9-r1", gost_listen_udp_port=51821, sidecar_wg_listen_port=51820,
                leigod_line_id=451, sidecar_gost_host="138.252.162.120", sidecar_gost_port=47123,
                socks_user="wguot", socks_pass="s3cret")
            unit = (sysdir / "leikwan-gost-lwsc4n9-r1.service").read_text()
            self.assertIn("-L udp://127.0.0.1:51821/127.0.0.1:51820?keepAlive=true&ttl=30s", unit)
            self.assertIn("-F socks5://127.0.0.1:20451", unit)              # arithmetic 20000+451
            self.assertIn("-F socks5://wguot:s3cret@138.252.162.120:47123?udp=true", unit)

if __name__ == "__main__":
    unittest.main()
```

> Match the repo's actual slave-agent test construction (see `slave-agent/tests/test_*.py` for how they instantiate the agent — reuse that factory rather than `__new__` if one exists).

- [ ] **Step 2: Run to verify it fails**

Run: `python slave-agent/tests/test_leigod_sidecar_uplink.py`
Expected: FAIL (`ensure_sidecar_gost_uot_client_service` undefined).

- [ ] **Step 3: Add `gost_bin`/`leigod_bin` to `DEFAULT_CONFIG`** (next to `wstunnel_bin`):

```python
    "gost_bin": "/usr/local/bin/gost",
    "leigod_bin": "/usr/local/bin/leigod-linux-amd64",
```

- [ ] **Step 4: Add the leigod arm in `apply_sidecar_uplink`** — insert BEFORE the final `else:` at L4335, after the `tcp` arm (L4330-4334):

```python
        elif outer_transport == "leigod" and int(uplink.get("gost_listen_udp_port", 0) or 0) > 0 \
                and str(uplink.get("sidecar_gost_host", "") or "") != "" and int(uplink.get("sidecar_gost_port", 0) or 0) > 0:
            gost_listen_udp_port = int(uplink.get("gost_listen_udp_port", 0) or 0)
            leigod_line_id = int(uplink.get("leigod_line_id", 0) or 0)
            sidecar_gost_host = str(uplink.get("sidecar_gost_host", "") or "").strip()
            sidecar_gost_port = int(uplink.get("sidecar_gost_port", 0) or 0)
            sidecar_wg_listen_port = int(uplink.get("sidecar_wg_listen_port", endpoint_port) or endpoint_port)
            socks_user = str(uplink.get("socks_user", "") or "wguot").strip()
            socks_pass = str(uplink.get("socks_pass", "") or "").strip()
            if leigod_line_id > 0 and socks_pass != "":
                self.ensure_leigod_line_service(leigod_line_id)
                self.ensure_sidecar_gost_uot_client_service(
                    interface_name, gost_listen_udp_port, sidecar_wg_listen_port,
                    leigod_line_id, sidecar_gost_host, sidecar_gost_port, socks_user, socks_pass)
                endpoint_host = "127.0.0.1"
                endpoint_port = gost_listen_udp_port
            self.remove_sidecar_uot_client_service(interface_name)
            self.remove_sidecar_wstunnel_udp_client_service(interface_name)
```

Also extend the ListenPort-collision guard at L4338 so leigod is covered:

```python
        if outer_transport in ("wstunnel_udp", "leigod") and local_listen_port == endpoint_port and endpoint_port > 0:
            local_listen_port = 0
```

And set MTU for leigod lanes — in the WG `[Interface]` render (after the `Address =` line, L4352), append an MTU line when present:

```python
        mtu = max(0, int(uplink.get("mtu", 0) or 0))
        mtu_line = f"MTU = {mtu}\n" if mtu > 0 else ""
        # ... include {mtu_line} in the config f-string Interface block
```

- [ ] **Step 5: Add `ensure_sidecar_gost_uot_client_service`** (model on `ensure_sidecar_uot_client_service` L4436):

```python
    def ensure_sidecar_gost_uot_client_service(self, interface_name: str, gost_listen_udp_port: int,
                                               sidecar_wg_listen_port: int, leigod_line_id: int,
                                               sidecar_gost_host: str, sidecar_gost_port: int,
                                               socks_user: str, socks_pass: str) -> None:
        if gost_listen_udp_port <= 0 or sidecar_gost_port <= 0 or sidecar_gost_host == "" or socks_pass == "":
            return
        line_socks_port = 20000 + int(leigod_line_id)
        service_name = f"leikwan-gost-{interface_name}"
        unit_path = Path(str(self.config.get("systemd_dir", "/etc/systemd/system") or "/etc/systemd/system")) / f"{service_name}.service"
        gost_bin = str(self.config.get("gost_bin", "/usr/local/bin/gost") or "/usr/local/bin/gost")
        exec_start = (
            f"{gost_bin} "
            f"-L udp://127.0.0.1:{gost_listen_udp_port}/127.0.0.1:{sidecar_wg_listen_port}?keepAlive=true&ttl=30s "
            f"-F socks5://127.0.0.1:{line_socks_port} "
            f"-F socks5://{socks_user}:{socks_pass}@{sidecar_gost_host}:{sidecar_gost_port}?udp=true"
        )
        body = f"""[Unit]
Description=Leikwan LeiGod gost UoT Client {service_name}
After=network.target

[Service]
Type=simple
ExecStart={exec_start}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""
        changed = self.write_text_if_changed(unit_path, body)
        if shutil.which("systemctl"):
            if changed:
                run_command("systemctl daemon-reload", check=False)
                run_command(f"systemctl enable {service_name}.service", check=False)
                run_command(f"systemctl restart {service_name}.service", check=False)

    def remove_sidecar_gost_uot_client_service(self, interface_name: str) -> None:
        service_name = f"leikwan-gost-{interface_name}"
        unit_path = Path(str(self.config.get("systemd_dir", "/etc/systemd/system") or "/etc/systemd/system")) / f"{service_name}.service"
        if shutil.which("systemctl"):
            run_command(f"systemctl stop {service_name}.service", check=False)
            run_command(f"systemctl disable {service_name}.service", check=False)
            run_command("systemctl daemon-reload", check=False)
        if unit_path.exists():
            unit_path.unlink()
```

- [ ] **Step 6: Add `ensure_leigod_line_service` (refcount-aware) + remove**:

```python
    def ensure_leigod_line_service(self, line_id: int) -> None:
        if line_id <= 0 or not shutil.which("systemctl"):
            return
        unit = f"leigod@{int(line_id)}.service"
        if not self.systemd_is_active(unit):
            run_command(f"systemctl enable {unit}", check=False)
            run_command(f"systemctl start {unit}", check=False)

    def remove_leigod_line_service_if_unused(self, line_id: int, active_line_ids: "set[int]") -> None:
        # Line-scoped: only stop leigod@<line> when NO active uplink references that line.
        if line_id <= 0 or line_id in active_line_ids or not shutil.which("systemctl"):
            return
        unit = f"leigod@{int(line_id)}.service"
        run_command(f"systemctl stop {unit}", check=False)
        run_command(f"systemctl disable {unit}", check=False)
```

Wire `remove_sidecar_gost_uot_client_service(interface_name)` into `remove_sidecar_uplink` (after L4390). Compute the active line set in `apply_static_sidecar_uplinks` (the reconcile loop ~L2384) from the published uplinks and call `remove_leigod_line_service_if_unused` for any line that dropped out.

- [ ] **Step 7: Extend `cleanup_orphan_sidecar_uplinks`** — add the gost helper to the units glob (~L5023) and a regex branch (~L5030):

```python
        # in the list-units glob, add: 'leikwan-gost-lws*'
        # add a regex branch alongside the cf/wgcf match:
        m_gost = re.match(r"^leikwan-gost-(lws[^.]+)\.service$", unit_name)
        if m_gost and m_gost.group(1) not in active_interfaces:
            run_command(f"systemctl stop {unit_name}", check=False)
            run_command(f"systemctl disable {unit_name}", check=False)
            run_command(f"systemctl reset-failed {unit_name}", check=False)
```

- [ ] **Step 8: Bump `AGENT_VERSION`** (e.g. `2026.06.02.1` → `2026.06.03.1`).

- [ ] **Step 9: Mirror ALL edits into `artifacts/slave-agent.py`, then verify identity**

Run: `diff -q slave-agent/slave_agent.py artifacts/slave-agent.py`
Expected: (identical — no output).

- [ ] **Step 10: Run the test**

Run: `python slave-agent/tests/test_leigod_sidecar_uplink.py`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add slave-agent/slave_agent.py artifacts/slave-agent.py slave-agent/tests/test_leigod_sidecar_uplink.py
git commit -m "feat(slave): leigod gost UoT client arm + leigod@line refcount + orphan reap + AGENT_VERSION bump"
```

---

## Task A5: Install/update — self-host gost + leigod binary (sha256-skip)

**Files:**
- Modify: `install/install-slave.sh` (binary fetch block; add `gost` + `leigod-linux-amd64`, `gost_bin`/`leigod_bin` into the written `config.json`)
- Modify: `install/install-bond-sidecar.sh` (fetch `gost`)
- Modify: `artifacts/versions.json` (add `gost`, `leigod-linux-amd64` entries with sha256)
- Test: `scripts/tests/test_windows_client_setup_installer.py` is unrelated; add a shell lint + a sha256-skip assertion via a small bats-free check.

- [ ] **Step 1: Add a guard test** `install/tests/test_leigod_binary_fetch.sh` (pure bash, asserts the installer skips re-download when sha matches):

```bash
#!/usr/bin/env bash
set -euo pipefail
# Assert install-slave.sh references gost + sha256-skip and config keys.
grep -q 'gost' install/install-slave.sh || { echo "FAIL: no gost fetch"; exit 1; }
grep -q 'check_binary_current' install/install-slave.sh || { echo "FAIL: no sha skip"; exit 1; }
grep -q 'gost_bin' install/install-slave.sh || { echo "FAIL: no gost_bin config"; exit 1; }
grep -q 'leigod-linux-amd64' install/install-slave.sh || { echo "FAIL: no leigod binary"; exit 1; }
echo OK
```

- [ ] **Step 2: Run to verify it fails**

Run: `bash install/tests/test_leigod_binary_fetch.sh`
Expected: FAIL.

- [ ] **Step 3: Add the fetch blocks** (mirror the existing `wstunnel` block in `install-slave.sh`, e.g. near L350): fetch `gost` and `leigod-linux-amd64` from `$ARTIFACT_BASE/artifacts/`, each guarded by `check_binary_current` against `versions.json` sha256; `install -m 0755`. Add `"gost_bin": "/usr/local/bin/gost"` and `"leigod_bin": "/usr/local/bin/leigod-linux-amd64"` to the `config.json` writer (~L439). In `install-bond-sidecar.sh`, mirror for `gost` only.

- [ ] **Step 4: Add `versions.json` entries** (`gost`, `leigod-linux-amd64`) with real sha256 of the artifacts you host (compute with `sha256sum`).

- [ ] **Step 5: Run the guard test**

Run: `bash install/tests/test_leigod_binary_fetch.sh`
Expected: `OK`.

- [ ] **Step 6: Commit**

```bash
git add install/install-slave.sh install/install-bond-sidecar.sh artifacts/versions.json install/tests/test_leigod_binary_fetch.sh
git commit -m "feat(install): self-host gost + leigod binary with sha256-skip"
```

---

## Task A6: Per-slave LeiGod account/session provisioning (management state)

**Files:**
- Modify: `master/src/Support/ProvisioningService.php` (management payload: add `leigod_session_json` per node when bound; node `agent_config_json->leigod` metadata)
- Modify: `slave-agent/slave_agent.py` + `artifacts/slave-agent.py` (`apply_management_state`: write `/opt/leigod/session.json` mode 0600 from `management['leigod_session_json']`)
- Test: `slave-agent/tests/test_leigod_session_provisioning.py`

- [ ] **Step 1: Write the failing test**:

```python
def test_leigod_session_written_0600(self):
    with tempfile.TemporaryDirectory() as d:
        a = self._agent_with_root(d)  # leigod root overridable to tmp
        a.apply_management_state({"leigod_session_json": '{"client_token":"x"}'})
        p = pathlib.Path(d) / "opt-leigod" / "session.json"
        self.assertTrue(p.exists())
        self.assertEqual(oct(p.stat().st_mode & 0o777), "0o600")
        self.assertIn("client_token", p.read_text())
```

- [ ] **Step 2: Run to verify it fails** → FAIL.

- [ ] **Step 3: Implement** in `apply_management_state`: when `management.get('leigod_session_json')` is a non-empty str, write it to `<leigod_root>/session.json` (default `/opt/leigod/session.json`; make the root overridable via config `leigod_root` for tests) with `os.chmod(path, 0o600)`. Never log the blob.

- [ ] **Step 4: Panel side** — when a node has `agent_config_json->leigod.session_present` and a stored session secret, include `leigod_session_json` in that node's management payload. (Operator mints the session out-of-band with `leigod-forward.sh login` and uploads it via an admin endpoint; store secret-flagged.)

- [ ] **Step 5: Mirror into `artifacts/slave-agent.py`; `diff -q` identical; run test** → PASS.

- [ ] **Step 6: Commit**

```bash
git add master/src/Support/ProvisioningService.php slave-agent/slave_agent.py artifacts/slave-agent.py slave-agent/tests/test_leigod_session_provisioning.py
git commit -m "feat: per-slave LeiGod session.json delivery via management state"
```

---

## Task A7: Admin UI — self-leg transport + LeiGod line picker (feature-flagged)

**Files:**
- Modify: `master/src/Controllers/AdminController.php` (forward-combination edit form: add `self_leg_outer_transport` select {CNIX-direct, CF, LeiGod line} + a `leigod_line_id` input, gated behind `LEIGOD_BASIS_ENABLED` config)
- Test: `master/tests/admin_forward_combination_leigod_field_test.php`

- [ ] **Step 1: Failing test** — assert the rendered combination form contains `name="self_leg_outer_transport"` with a `leigod` option and `name="leigod_line_id"` when `LEIGOD_BASIS_ENABLED=1`.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** the select + input in the combination form (mirror the cf_location_group field). Gate behind `$this->config->get('LEIGOD_BASIS_ENABLED', '0') === '1'`.
- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** `feat(admin): leigod self-leg transport + line picker (feature-flagged)`.

---

## Task A8: LeiGod live validation runbook (no code; document + verify)

- [ ] Stage one leigod lane on a non-prod slave+sidecar. Confirm (per spec §9):
  1. `ping -c5 <sidecar WG /32>` 0% loss + `wg show <iface>` fresh handshake.
  2. slave `leikwan-gost-<iface>.service` active; cmdline has both `-F socks5://127.0.0.1:20<line>` and `-F socks5://…@<sidecar>:<port>?udp=true`; sidecar `leikwan-leigod-gost-server-<iface>.service` active.
  3. Teardown idempotency + `leigod@<line>` refcount; orphan reap; negative (missing pass → teardown, no fail-loop).
- [ ] Record results in `docs/2026-06-0X-leigod-live-validation.md`.

---

# PART B — Forward Route (depends on Part A: cnix/cf/leigod are the 3 raceable bases)

## Task B0: VERIFY the cf-ingress assumption (spec §3)

- [ ] Read `ProvisioningService::publishTaskPayloadForForward` + `buildClientConfig` + the slave `publish_task` backend arms to confirm whether `backend_mode=cf_mode` changes ONLY the slave↔sidecar uplink (raceable slave-side) or ALSO the client→slave ingress backend.
- [ ] Record the finding in `findings.md`. If cf changes the ingress, constrain v1 routes to bases sharing the ingress (e.g. cnix vs leigod) and note cf-vs-cnix needs the deferred client coordinator (spec §10). This gates how the `saveForwardRoute` ingress check (Task B2) treats `backend_mode`.

## Task B1: Forward Route data model + migration

**Files:** Create `master/migrations/20260602_add_forward_routes.sql`; modify `schema.sql`; test `master/tests/forward_routes_schema_test.php`.

- [ ] **Step 1: Failing test** — assert schema/migration declare `forward_routes`, `forward_route_combinations` (with `enabled`, `sort_order`, UNIQUE), `forwards.forward_route_id`, `forwards.active_combination_id`, `service_plan_forward_routes`.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Migration:**

```sql
-- 20260602_add_forward_routes.sql
CREATE TABLE forward_routes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  tenant_label VARCHAR(191) NULL, tenant_description TEXT NULL, tenant_badge VARCHAR(64) NULL,
  race_enabled TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE forward_route_combinations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  forward_route_id BIGINT UNSIGNED NOT NULL,
  forward_combination_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_route_combo (forward_route_id, forward_combination_id),
  FOREIGN KEY (forward_route_id) REFERENCES forward_routes(id) ON DELETE CASCADE,
  FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE service_plan_forward_routes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  forward_route_id BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_plan_route (plan_id, forward_route_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE forwards
  ADD COLUMN forward_route_id BIGINT UNSIGNED NULL AFTER forward_combination_id,
  ADD COLUMN active_combination_id BIGINT UNSIGNED NULL AFTER forward_route_id;
```

- [ ] **Step 4: Mirror into `schema.sql`.**
- [ ] **Step 5: Run → OK. Step 6: Commit** `feat(panel): forward_routes data model`.

## Task B2: Admin Route CRUD + ingress-mismatch guard + republish

**Files:** `master/src/Controllers/AdminController.php` (`forwardRoutesPage`, `saveForwardRoute`), `master/src/Support/ProvisioningService.php` (`saveForwardRoute`, `republishActiveForwardsForRoute`), `master/src/Bootstrap.php` (routes). Tests: `master/tests/saveforwardroute_ingress_guard_test.php`.

- [ ] **Step 1: Failing test** — `saveForwardRoute` with two enabled combos that differ in `node_group_key`/`client_mode`/`bond_ingress_mode` (the ingress realization) throws `forward_route_combos_ingress_mismatch`; two combos differing only in the slave↔sidecar basis (sidecar/outer_transport/leigod_line) succeed.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** `saveForwardRoute` (model on `saveForwardCombination` L416): persist `forward_routes` + `forward_route_combinations` (enabled, sort_order); validate that all enabled combos share the ingress realization (per Task B0 finding) and error otherwise. Add `republishActiveForwardsForRoute(int $routeId)` (generalize `republishActiveForwardsForCombination` L4979 to `WHERE forward_route_id = :id`). Wire `/admin/forward-routes` (GET+POST) in `Bootstrap.php` mirroring `/admin/forward-combinations` L133.
- [ ] **Step 4: Run → OK. Step 5: Commit** `feat(admin): forward route CRUD + ingress guard + republish`.

## Task B3: Publish path — `route_race_candidates` + selector generalization + stable port seed

**Files:** `master/src/Support/ProvisioningService.php` (`reconcileForwardEndpoints` L5537 loop; `publishTaskPayloadForForward` L6019 `route_race_candidates`; `staticSidecarUplinkSelectorPayload` L5942; `collisionFreeSidecarSelectorPort` L7689 reseed on `forward_route_id`). Tests: `master/tests/publish_route_race_candidates_test.php`, `master/tests/forward_route_selector_port_stable_test.php`.

- [ ] **Step 1: Failing test (candidates)** — a forward bound to a race-ON route with 3 enabled combos publishes a `route_race_candidates` list of 3 entries, each tagged `forward_combination_id` with its own `sidecar_uplink` (incl. leigod keys for the leigod combo); a race-OFF route publishes only the lowest-`sort_order` enabled combo; the payload key is `route_race_candidates` (NOT `forward_routes`).
- [ ] **Step 2: Failing test (port stable)** — `collisionFreeSidecarSelectorPort` returns the SAME port when the winning combo flips but `forward_route_id` is unchanged.
- [ ] **Step 3: Run both → FAIL.**
- [ ] **Step 4: Implement:** in `reconcileForwardEndpoints` generalize the single-combination branch (L5556-5566) to loop the route's enabled combinations; in `publishTaskPayloadForForward` emit `route_race_candidates` (one per enabled combo) + a `route_race` policy block (`race_enabled`, `switch_improvement_ms`, `restart_cooldown`, `candidate_cap` default 3, kill-switch); when `race_enabled=0` emit only the lowest-`sort_order` enabled combo. Reseed `collisionFreeSidecarSelectorPort` on `forward_route_id` (fallback `active_combination_id`). Generalize `staticSidecarUplinkSelectorPayload` candidates from lanes to the per-combo uplinks.
- [ ] **Step 5: Run both → OK. Step 6: Commit** `feat(panel): publish route_race_candidates + route-stable selector port`.

## Task B4: Slave selector — candidate=basis, warm standby, demotion, active-combo heartbeat

**Files:** `slave-agent/slave_agent.py` + `artifacts/slave-agent.py` (`evaluate_sidecar_uplink_selector` L2608, `SidecarUplinkSelectorProxy` L936, `probe_sidecar_selector_candidate` L2523; heartbeat). Test: `slave-agent/tests/test_route_race_selector.py`.

- [ ] **Step 1: Failing test** — given `route_race_candidates` of 3 bases, the slave brings up an uplink per enabled candidate (warm), probes each, and selects the lowest-score healthy basis; a candidate whose probe fails is demoted and the active target switches to the next-best with `switch_improvement_ms` hysteresis honored; a single candidate (race OFF) stays monitor-only/no-switch; candidate count is capped at `candidate_cap`; the heartbeat reports `active_combination_id`.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** the generalization: treat each candidate as a full per-combination uplink (call `apply_sidecar_uplink` for each enabled candidate up to the cap); reuse the existing probe/score/hysteresis/cooldown/kill-switch; the proxy rewrites `active_target_host/port` to the winning candidate; report the winning candidate's `forward_combination_id` in `build_heartbeat_payload` as `active_combination_id`. Bound simultaneous uplinks by `candidate_cap`.
- [ ] **Step 4: Mirror into `artifacts/slave-agent.py`; `diff -q` identical; bump `AGENT_VERSION`.**
- [ ] **Step 5: Run → OK. Step 6: Commit** `feat(slave): race across basis candidates via generalized selector`.

## Task B5: Tenant/client surface — Route dropdown replaces combination dropdown

**Files:** `master/src/Controllers/TenantController.php` (`quickCreateForward` L1717-1724/L1744; `normalizeTenantForwardPayload` L2450; `editForward` L1794), `master/src/Support/ProvisioningService.php` (`availableForwardRoutesForService`, `buildClientConfig` route fields). Test: `master/tests/tenant_forward_route_selector_test.php`.

- [ ] **Step 1: Failing test** — `quickCreateForward` renders `<select name="forward_route_id">` (not `forward_combination_id`); `availableForwardRoutesForService` returns plan-gated active routes; `normalizeTenantForwardPayload` parses `forward_route_id`.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** the dropdown swap + `availableForwardRoutesForService` (repurpose `availableForwardCombinationsForService` L606 against `service_plan_forward_routes`); `saveForward` resolves `forward_route_id` → seeds `forward_combination_id`/`active_combination_id` from the lowest-`sort_order` enabled combo for back-compat; `buildClientConfig` adds `forward_route_id`/name (keep combination fields). Accept legacy `forward_combination_id` during transition.
- [ ] **Step 4: Run → OK. Step 5: Commit** `feat(tenant): forward route selector replaces combination dropdown`.

## Task B6: Migration/backfill — wrap legacy forwards in singleton routes

**Files:** `master/migrations/20260602_backfill_forward_routes.sql` (or a PHP one-shot in `master/cli/`). Test: `master/tests/forward_route_backfill_test.php`.

- [ ] **Step 1: Failing test** — after backfill, every forward with a `forward_combination_id` has a `forward_route_id` pointing to a `race_enabled=0` singleton route whose one `forward_route_combinations` row (enabled=1) references the original combination; each plan→combination gating becomes a plan→singleton-route row.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** the backfill (idempotent: skip forwards that already have `forward_route_id`).
- [ ] **Step 4: Run → OK. Step 5: Commit** `feat(panel): backfill legacy forwards into singleton routes`.

## Task B7: Forward Route live validation runbook

- [ ] Build a route bundling {cnix-bond, cf-bond, leigod} on ONE ingress slave. Verify: selector picks the best basis; **block CF** (drop the cf candidate's reachability) and confirm sub-second failover to leigod/cnix with no flap (hysteresis); race-OFF pins the lowest-`sort_order` enabled combo; admin sees `active_combination_id`. Record in `docs/2026-06-0X-forward-route-live-validation.md`.

---

## Final integration gate (after all tasks)

- [ ] Run the whole panel suite: `for f in master/tests/*.php; do php "$f" || { echo "FAILED: $f"; break; }; done` → all `OK`.
- [ ] Run agent suites: `python -m pytest slave-agent/tests sidecar-agent/tests -q` → all pass.
- [ ] `diff -q slave-agent/slave_agent.py artifacts/slave-agent.py` → identical; `AGENT_VERSION` bumped; `versions.json` updated.
- [ ] Confirm no use of the reserved `forward_routes` JSON payload key (`grep -rn "forward_routes" master/src` → only the unrelated `SidecarAgentController.php:292` mptcp key + the new DB table/route names, NOT a publish payload field).

---

## Self-review notes (gaps to watch during execution)

- **cf-ingress assumption (Task B0) is load-bearing** — if cf changes the client→slave ingress, the v1 raceable set is cnix+leigod and cf-vs-cnix is deferred (spec §10). Do B0 before B2/B3.
- **Panel locals** in `buildStaticSidecarUplinkPairForNode` (`$sidecarHost`, `$sidecarWgListenPort`, `$slave`, `$sidecar`, `$sidecarNodeId`) — confirm exact names against the live cf branch before editing.
- **Slave test factory** — reuse the repo's existing agent construction in `slave-agent/tests/` rather than the `__new__` placeholder shown here.
- **gost ExecStart `&`** in `?keepAlive=true&ttl=30s` is safe in a systemd unit (no shell), but verify gost v3.2.6 accepts chained `-F` SOCKS with the terminating `?udp=true` only on the last hop (spec §2 / leigod spec open decision) on the actual self-hosted build during Task A8.
- **Candidate cap** (Task B3/B4) must bound simultaneous warm uplinks to avoid the selector-port-storm/deadlock precedents.
