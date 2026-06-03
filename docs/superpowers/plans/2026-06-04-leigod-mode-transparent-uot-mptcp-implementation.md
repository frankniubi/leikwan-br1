# LeiGod Mode — MPTCP-over-LeiGod (Compose Model) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Follow superpowers:test-driven-development for every code step (write the failing test, watch it fail, minimal implementation, watch it pass, commit).

**Goal:** Make `leigod_mode` run the client→slave tier as **true MPTCP** (which never launches the `leikwan-bond` Rust binary, so it cannot re-admit-storm), with the slave→sidecar hop carried by the **existing LeiGod static sidecar uplink** that the MPTCP underlay already rides *over*. The carrier is reused, not re-implemented.

**Architecture (compose model):** The client opens MPTCP across the N per-slave WG underlay interfaces (`lwf<fid>n<nid>`, each rendered with the *client peer only* because `uses_static_sidecar_uplink=true`). Each slave's slave→sidecar bytes route through that same slave's **static uplink interface** (`outer_transport='leigod'`: one lguot TCP-UoT over a LeiGod SOCKS-CONNECT line, MTU 1280, terminated by the sidecar lguot server). Aggregation is across slaves; per slave = one UoT.

**Tech Stack:** PHP 8.3 (`master/`, standalone-script tests run with `php`, lint with `php -l`), Python 3 slave/sidecar agents (`unittest`), `wg`/`wg-quick`/`iperf3`/`journalctl`/`ss`, `plink`/`pscp`, **panel VM (10.0.10.196) as the SSH jump host** to the Shanghai slaves + the Tokyo sidecar (185.220.238.143). Slave creds in `leikwan-v3.txt`; sidecar `root@185.220.238.143` / `DefaultPasswd123.`.

**Spec:** `docs/superpowers/specs/2026-06-04-leigod-mode-transparent-uot-mptcp-design.md`.

**Validated foundation (do NOT re-litigate):** LeiGod is TCP-only (no UDP/TUN); one lguot UoT/slave ≈ 165–190 Mbit/s (canonical **~175**), lossless, no stalls (30 s); 3 slaves dial line 453 concurrently (30 s); MPTCP does not run the bond binary; the MPTCP underlay already composes over the static uplink (`buildMptcpSlaveUnderlayPayload` → `uses_static_sidecar_uplink`). Forwards 65/67 are currently disabled (parked).

**Authorization boundary (read before starting):** Phases 0–3 are **commit-only** — this is the current task and is fully committable. Phases 4–5 (pscp to production, flipping combo 5, live iperf3) **require an explicit separate deploy authorization** from the user, mirroring the `[[appendix-c-deploy-authorization]]` precedent. Do not deploy or touch production state until that go is given.

---

## File Structure

| File | Change | Responsibility |
|---|---|---|
| `slave-agent/slave_agent.py` | Modify dispatch in `publish_task` (~`:3169-3209`) | Apply the leigod static uplink **alongside** the MPTCP underlay in the true-MPTCP branch (the core bug fix). |
| `slave-agent/tests/test_mptcp_underlay_applies_leigod_uplink.py` | Create | Prove the true-MPTCP branch now applies the static uplink. |
| `master/src/Support/ProvisioningService.php` | Modify leigod derivation (~`:428` + `:470-475`), MTU literal (`:5841`) | leigod_mode → true-MPTCP runtime selection; MTU from config. |
| `master/tests/saveforwardcombination_leigod_mode_true_mptcp_test.php` | Create | Prove leigod_mode persists `client_mode='mptcp'`+`runtime_preference='single'`; bond stays bond. |
| `master/tests/provisioning_leigod_wg_mtu_config_test.php` | Create | Prove MTU comes from `BOND_LEIGOD_WG_MTU` config, not a literal. |
| `master/migrations/20260604_leigod_mode_true_mptcp.sql` | Create | Flip parked single-leg leigod combos to mptcp+single; idempotent. |
| `master/tests/leigod_mode_true_mptcp_migration_test.php` | Create | Source-grep guard for the migration snippets. |
| `docs/2026-06-04-mptcp-leigod-compose-findings.md` | Create (Phase 0) | The investigation deliverable that gates Phases 1–2. |
| `artifacts/versions.json` | Bump (Phase 4) | The slave agent changed → three-artifact deploy. |

---

## Phase 0 — Confirm the target & the runtime-selection facts (GATE; read-only)

**Files:**
- Create: `docs/2026-06-04-mptcp-leigod-compose-findings.md`

This phase gates Phases 1–2: it must confirm (a) that this redesign actually targets the real outage, and (b) the three runtime-selection facts the panel edit depends on. No code is written. Use the panel VM as the jump host for any live read.

- [ ] **Step 1: Capture the ACTUAL last-outage signature of 65/67.** Via the panel jump host, read-only, on the parked combo-5 slaves + the Tokyo sidecar:

  ```bash
  # restart storms / EADDRINUSE (the documented 65/66 failure class)
  journalctl -u 'leikwan-*' --since '-7d' | grep -iE 'EADDRINUSE|address already in use|start-limit|Failed' | tail -50
  systemctl list-units 'leikwan-*' --all | grep -iE 'failed|activating'
  ss -tlnp | sort        # look for selector/gost/lguot port collisions
  ```

  **Decision (write into the findings doc):** if the outage signature is an agent restart storm / port collision (per `[[selector-port-collision-storm]]`, `[[cf-edge-selector-blindspot]]`), record explicitly that **MPTCP alone will NOT fix it** and that Phase 5 must verify the MPTCP path does not reintroduce the collision (the leigod gost/lguot units + WG `ListenPort` sharing at `slave_agent.py:4436` still apply under MPTCP). If the signature is bond cooldown/re-admit, this redesign targets it directly.

- [ ] **Step 2: Confirm the runtime gate by code read** (record exact lines in the findings doc):
  - `master/src/Support/ProvisioningService.php:3532` and `:6421`: `$publishesBondRuntime = $clientMode === 'bond' || $runtimePreference === 'mptcp_preferred_bond_fallback';`
  - `slave-agent/slave_agent.py:3169`: `if mptcp_underlay and mptcp_underlay.get("enabled") and not publish_bond_runtime:` … `return` at `:3201` — confirming the true-MPTCP branch skips `apply_sidecar_uplink` (`:3211`).
  - `client-agent/client_agent.py:2635-2636`: `if runtime_preference != "mptcp_preferred_bond_fallback": return requested_mode` — confirming `client_mode='mptcp'` + `runtime_preference='single'` returns `'mptcp'` directly.

- [ ] **Step 3: Confirm `forward['mptcp']` is runtime-ready for a leigod combo.** Verify `forward_has_mptcp_runtime_config` (`client_agent.py:2619-2630`) will return true: the published forward's `mptcp` block needs `tcp_session_token != ''` AND `sidecar_port > 0` (or `remote_port > 0`). `buildMptcpSlaveUnderlayPayload` emits `sidecar_mptcp_port` (`:9112`) + `tcp_session_token` (`:9113`) at the underlay level — confirm the **forward-level** `mptcp` block the client reads is also populated for a `leigod_mode`+`client_mode='mptcp'` combo (trace the publish path that builds `forward['mptcp']`). **If it is NOT populated for leigod combos, that is an extra Phase-2 task — record it.**

- [ ] **Step 4: Confirm the aggregation cap.** Read `defaultMaxLegsUsedForCombination('mptcp', 'single', 'leigod_mode')` (called at `ProvisioningService.php:434`). It must return **> 1** so MPTCP can span multiple slaves. If it returns 1 for this tuple, add a Phase-2 task to give `leigod_mode`+`mptcp` a multi-leg cap. Record the value.

- [ ] **Step 5: Exit criteria.** The findings doc must definitively state: (i) does this redesign target the real outage, and what (if anything) must accompany it; (ii) the exact early-return fix site (`slave_agent.py:3201`); (iii) whether the forward-level mptcp block is already populated for leigod combos; (iv) the aggregation cap value. Then commit:

  ```bash
  git add docs/2026-06-04-mptcp-leigod-compose-findings.md
  git commit -m "docs(leigod): confirm compose-model target + runtime-selection facts"
  ```

---

## Phase 1 — Slave agent: apply the static uplink alongside the MPTCP underlay (core fix, TDD)

**Files:**
- Modify: `slave-agent/slave_agent.py` (the `publish_task` dispatch, `:3169-3209`)
- Test: `slave-agent/tests/test_mptcp_underlay_applies_leigod_uplink.py`

**Root cause (from Phase 0):** in the true-MPTCP branch (`mptcp_underlay.enabled and not publish_bond_runtime`), `publish_task` applies the underlay then `return`s at `:3201`, never reaching `apply_sidecar_uplink` at `:3211`. So the LeiGod static uplink (the lguot carrier) is never provisioned under true MPTCP, leaving the underlay (which has no sidecar peer of its own when `uses_static_sidecar_uplink=true`) with no route to the sidecar.

- [ ] **Step 1: Write the failing test.** Mirror the harness of `test_publish_task_uses_selector_port_as_local_backend_target` (`slave-agent/tests/test_static_sidecar_uplinks.py:832`). Create `slave-agent/tests/test_mptcp_underlay_applies_leigod_uplink.py`:

  ```python
  import importlib.util
  import pathlib
  import sys
  import unittest
  from unittest import mock

  REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


  def load_module(name: str, relative: str):
      path = REPO_ROOT / relative
      spec = importlib.util.spec_from_file_location(name, path)
      module = importlib.util.module_from_spec(spec)
      assert spec.loader is not None
      sys.modules[name] = module
      spec.loader.exec_module(module)
      return module


  slave_agent_module = load_module("slave_agent_mptcp_leigod_under_test", "slave-agent/slave_agent.py")


  class MptcpUnderlayAppliesLeigodUplinkTests(unittest.TestCase):
      def make_agent(self):
          import json
          import tempfile
          tmp_dir = tempfile.TemporaryDirectory()
          self.addCleanup(tmp_dir.cleanup)
          tmp = pathlib.Path(tmp_dir.name)
          config_path = tmp / "config.json"
          config_path.write_text(json.dumps({
              "master_url": "https://panel.example.com",
              "node_token": "token",
              "state_dir": str(tmp / "state"),
              "systemd_dir": str(tmp / "systemd"),
              "wireguard_config_dir": str(tmp / "wireguard"),
              "wstunnel_bin": "/usr/local/bin/wstunnel",
          }), encoding="utf-8")
          return slave_agent_module.SlaveAgent(config_path)

      def test_true_mptcp_branch_applies_leigod_static_uplink(self):
          # A leigod_mode combo publishes BOTH an mptcp_underlay and a leigod
          # sidecar_uplink, with publish_bond_runtime=False (true MPTCP). The
          # slave MUST provision the leigod static uplink (the lguot carrier)
          # in addition to the MPTCP underlay -- otherwise the underlay has no
          # route to the sidecar.
          agent = self.make_agent()
          task = {"service_id": 6, "forward_id": 65, "endpoint_id": 571, "payload": {}}
          payload = {
              "target": "10.240.8.1:9000",
              "listen_port": 7000,
              "path_prefix": "f65",
              "route_key": "f65",
              "transport_mode": "tcp+udp",
              "ingress_protocol": "tcp_relay",
              "backend_protocol": "tcp_relay",
              "ingress_auth_token": "token",
              "publish_bond_runtime": False,
              "mptcp_underlay": {"enabled": True, "uses_static_sidecar_uplink": True},
              "sidecar_uplink": {"enabled": True, "outer_transport": "leigod", "interface_name": "lws6c4n19p"},
              "sidecar_uplink_selector": {"enabled": False},
          }
          applied_underlay = []
          applied_uplink = []
          with mock.patch.object(agent, "apply_mptcp_underlay", side_effect=lambda s, u: applied_underlay.append(u)):
              with mock.patch.object(agent, "apply_sidecar_uplink", side_effect=lambda s, u: applied_uplink.append(u)):
                  with mock.patch.object(agent, "ensure_sidecar_uplink_selector"):
                      with mock.patch.object(agent, "remove_sidecar_uplink_selector"):
                          with mock.patch.object(agent, "cleanup_superseded_services_for_forward"):
                              agent.publish_task(task, payload)

          self.assertEqual(1, len(applied_underlay), "MPTCP underlay must still be applied")
          self.assertEqual(1, len(applied_uplink), "leigod static uplink must be applied in the true-MPTCP branch")
          self.assertEqual("leigod", applied_uplink[0]["outer_transport"])


  if __name__ == "__main__":
      unittest.main()
  ```

- [ ] **Step 2: Run the test to verify it fails.**

  Run: `python slave-agent/tests/test_mptcp_underlay_applies_leigod_uplink.py`
  Expected: **FAIL** — `AssertionError: leigod static uplink must be applied in the true-MPTCP branch` (`len(applied_uplink) == 0`).

- [ ] **Step 3: Write the minimal implementation.** In `slave-agent/slave_agent.py`, in the true-MPTCP branch, insert the static-uplink apply between `apply_mptcp_underlay` and the selector block:

  ```python
              self.write_json(metadata_path, metadata)
              self.apply_mptcp_underlay(service_name, mptcp_underlay)
              if sidecar_uplink.get("enabled"):
                  # Compose model: under true MPTCP the slave->sidecar hop is carried by the
                  # leigod static uplink the underlay rides over (uses_static_sidecar_uplink).
                  # Without this the underlay has no sidecar peer and no route. (Was skipped by
                  # the early return below before this fix.)
                  self.apply_sidecar_uplink(service_name, sidecar_uplink)
              if sidecar_uplink_selector.get("enabled"):
                  self.ensure_sidecar_uplink_selector(service_name, sidecar_uplink_selector)
              else:
                  self.remove_sidecar_uplink_selector(service_name)
              return {
  ```

- [ ] **Step 4: Run the test to verify it passes, and the whole slave suite.**

  Run: `python slave-agent/tests/test_mptcp_underlay_applies_leigod_uplink.py`
  Expected: **PASS** (`OK`).

  Run the full slave-agent suite (no regressions):
  ```bash
  for t in slave-agent/tests/test_*.py; do echo "== $t"; python "$t" || echo "FAILED: $t"; done
  ```
  Expected: all `OK`, no new `FAILED` lines vs. the pre-change baseline.

- [ ] **Step 5: Commit.**

  ```bash
  git add slave-agent/slave_agent.py slave-agent/tests/test_mptcp_underlay_applies_leigod_uplink.py
  git commit -m "fix(slave/leigod): provision leigod static uplink alongside true-MPTCP underlay"
  ```

---

## Phase 2 — Panel: leigod_mode selects true MPTCP + migration (TDD)

**Files:**
- Modify: `master/src/Support/ProvisioningService.php` (leigod derivation near `:428`)
- Test: `master/tests/saveforwardcombination_leigod_mode_true_mptcp_test.php`
- Create: `master/migrations/20260604_leigod_mode_true_mptcp.sql`
- Test: `master/tests/leigod_mode_true_mptcp_migration_test.php`

**Contract:** `leigod_mode` + `client_mode='mptcp'` → force `runtime_preference='single'` (so `publishesBondRuntime=false` → no bond binary). A `single`/unset client_mode upgrades to `mptcp` (single-leg leigod never aggregates). An explicit `bond` stays `bond` — a deliberate best-effort operator choice (it keeps the already-shipped 20 s `BOND_LEIGOD_LEG_COOLDOWN_MS`; MPTCP is the recommended + validated path). This respects the product requirement that users can pick MPTCP **or** bond, while making MPTCP the path that fixes the runaway.

- [ ] **Step 1: Write the failing test.** Mirror the capturing-`Database` harness of `master/tests/saveforwardcombination_leigod_mode_test.php`. Create `master/tests/saveforwardcombination_leigod_mode_true_mptcp_test.php`:

  ```php
  <?php
  declare(strict_types=1);

  namespace Leikwan\Master\Database {
      final class Database
      {
          /** @var array<string, array<int, array<string, mixed>>> */
          public static array $inserts = [];
          public function seed(string $table, array $rows): void {}
          public function fetchOne(string $sql, array $params = []): ?array { return null; }
          public function fetchAll(string $sql, array $params = []): array { return []; }
          public function execute(string $sql, array $params = []): int { return 0; }
          public function insert(string $table, array $row): int { self::$inserts[$table][] = $row; return 42; }
      }
  }

  namespace {
      spl_autoload_register(static function (string $class): void {
          $prefix = 'Leikwan\\Master\\';
          if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
              return;
          }
          $relative = substr($class, strlen($prefix));
          $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
          if (is_file($path)) {
              require_once $path;
          }
      });

      use Leikwan\Master\Config;
      use Leikwan\Master\Database\Database;
      use Leikwan\Master\Support\ProvisioningService;

      function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }

      function lastCombo(): array {
          $rows = Database::$inserts['forward_combinations'] ?? [];
          if (count($rows) !== 1) { fail('expected exactly one forward_combinations insert, got ' . count($rows)); }
          return $rows[0];
      }

      $envPath = tempnam(sys_get_temp_dir(), 'lw-leigod-mptcp-env-');
      file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");
      $service = new ProvisioningService(new Config($envPath), new Database());

      $base = [
          'name' => 'leigod-mptcp-combo',
          'tenant_label' => 'Leigod',
          'node_group_key' => 'sha-tencent',
          'backend_mode' => 'leigod_mode',
          'sidecar_point_id' => 4,
          'is_active' => 1,
          'leigod_line_id' => 453,
      ];

      // 1. single/unset client_mode upgrades to mptcp + runtime_preference single.
      Database::$inserts = [];
      $p = $base; $p['client_mode'] = 'single'; $p['runtime_preference'] = 'single';
      $service->saveForwardCombination($p);
      $row = lastCombo();
      if (($row['client_mode'] ?? '') !== 'mptcp') {
          fail('leigod_mode single must upgrade to client_mode=mptcp, got ' . var_export($row['client_mode'] ?? '', true));
      }
      if (($row['runtime_preference'] ?? '') !== 'single') {
          fail('leigod_mode mptcp must persist runtime_preference=single, got ' . var_export($row['runtime_preference'] ?? '', true));
      }

      // 2. explicit mptcp + bond-fallback runtime is coerced to single (no bond runtime).
      Database::$inserts = [];
      $p = $base; $p['client_mode'] = 'mptcp'; $p['runtime_preference'] = 'mptcp_preferred_bond_fallback';
      $service->saveForwardCombination($p);
      $row = lastCombo();
      if (($row['runtime_preference'] ?? '') !== 'single') {
          fail('leigod_mode mptcp must coerce runtime_preference to single, got ' . var_export($row['runtime_preference'] ?? '', true));
      }

      // 3. explicit bond stays bond (deliberate best-effort choice).
      Database::$inserts = [];
      $p = $base; $p['client_mode'] = 'bond'; $p['runtime_preference'] = 'single';
      $service->saveForwardCombination($p);
      $row = lastCombo();
      if (($row['client_mode'] ?? '') !== 'bond') {
          fail('leigod_mode bond must stay bond, got ' . var_export($row['client_mode'] ?? '', true));
      }

      echo "ok\n";
  }
  ```

- [ ] **Step 2: Run the test to verify it fails.**

  Run: `php master/tests/saveforwardcombination_leigod_mode_true_mptcp_test.php`
  Expected: **FAIL** at assertion 1 — `leigod_mode single must upgrade to client_mode=mptcp, got 'single'` (today leigod_mode does not touch client_mode).

- [ ] **Step 3: Write the minimal implementation.** In `master/src/Support/ProvisioningService.php`, immediately after the `runtime_preference` read (`:428`) and **before** the `helper_group_cap` default (`:432-435`, which reads `$clientMode`/`$runtimePreference`), insert:

  ```php
          $runtimePreference = trim((string) ($payload['runtime_preference'] ?? 'single'));
          // leigod_mode aggregates the slave->sidecar leigod hop across slaves via TRUE MPTCP
          // (no leikwan-bond binary -> no re-admit churn). single-leg leigod never aggregates,
          // so upgrade it to mptcp; and mptcp must NOT carry a bond-fallback runtime_preference
          // (that re-arms the bond runtime / runaway). Explicit 'bond' stays a deliberate
          // best-effort choice (keeps the short BOND_LEIGOD_LEG_COOLDOWN_MS). See plan Phase 2.
          if (trim((string) ($payload['backend_mode'] ?? 'current_mode')) === 'leigod_mode') {
              if (!in_array($clientMode, ['mptcp', 'bond'], true)) {
                  $clientMode = 'mptcp';
              }
              if ($clientMode === 'mptcp') {
                  $runtimePreference = 'single';
              }
          }
  ```

  (The existing big leigod branch at `:470-475` is unchanged — it derives `self_leg_outer_transport`/CF clearing/`leigod_uot_impl` and does not touch `client_mode`/`runtime_preference`.)

- [ ] **Step 4: Run the new test + the existing leigod tests + lint.**

  Run: `php master/tests/saveforwardcombination_leigod_mode_true_mptcp_test.php`
  Expected: **PASS** (`ok`).

  Run (must stay green — these encode the unchanged leigod contract):
  ```bash
  php -l master/src/Support/ProvisioningService.php
  php master/tests/saveforwardcombination_leigod_mode_test.php
  php master/tests/saveforwardcombination_leigod_guard_test.php
  php master/tests/provisioning_leigod_mode_direct_ingress_test.php
  php master/tests/bond_leigod_leg_cooldown_publish_test.php
  ```
  Expected: all print `ok`/`OK` (the cooldown test stays green — the 20 s leigod cooldown is **kept** for the bond path, not retired).

- [ ] **Step 5: Create the migration.** Mirror `master/migrations/20260603_promote_leigod_mode.sql`. Create `master/migrations/20260604_leigod_mode_true_mptcp.sql`:

  ```sql
  -- 20260604_leigod_mode_true_mptcp.sql
  -- leigod_mode aggregates the slave<->sidecar leigod hop across slaves via TRUE MPTCP
  -- (client_mode='mptcp', no leikwan-bond binary -> no re-admit churn). Upgrade parked
  -- single-leg leigod combos to mptcp+single. Leave explicit client_mode='bond' combos
  -- AS-IS (a deliberate best-effort choice); the live combo-5 cutover from bond to mptcp
  -- is a separate, RECORDED operator action (plan Phase 5), not an automatic migration.
  -- Idempotent (safe to re-run).

  -- 1. Upgrade single-leg (or NULL) leigod combos to true MPTCP.
  UPDATE forward_combinations
  SET client_mode = 'mptcp',
      runtime_preference = 'single',
      updated_at = NOW()
  WHERE backend_mode = 'leigod_mode'
    AND (client_mode IS NULL OR client_mode NOT IN ('mptcp', 'bond'));

  -- 2. Belt-and-suspenders: a leigod_mode + mptcp combo must never carry a bond-fallback
  --    runtime_preference (it would re-arm the bond runtime the design exists to avoid).
  UPDATE forward_combinations
  SET runtime_preference = 'single',
      updated_at = NOW()
  WHERE backend_mode = 'leigod_mode'
    AND client_mode = 'mptcp'
    AND runtime_preference = 'mptcp_preferred_bond_fallback';
  ```

- [ ] **Step 6: Write the migration guard test.** Mirror the source-grep style of `master/tests/provisioning_leigod_uot_impl_test.php`. Create `master/tests/leigod_mode_true_mptcp_migration_test.php`:

  ```php
  <?php
  declare(strict_types=1);

  function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }

  $root = __DIR__ . '/..';
  $sql = file_get_contents($root . '/migrations/20260604_leigod_mode_true_mptcp.sql') ?: '';
  foreach ([
      "backend_mode = 'leigod_mode'",
      "client_mode = 'mptcp'",
      "runtime_preference = 'single'",
      "client_mode NOT IN ('mptcp', 'bond')",
      "runtime_preference = 'mptcp_preferred_bond_fallback'",
  ] as $snippet) {
      if (!str_contains($sql, $snippet)) {
          fail('migration missing snippet: ' . $snippet);
      }
  }
  echo "OK\n";
  exit(0);
  ```

- [ ] **Step 7: Run the migration guard test.**

  Run: `php master/tests/leigod_mode_true_mptcp_migration_test.php`
  Expected: **PASS** (`OK`).

- [ ] **Step 8: Regression gate (named-set, not count).** Before this phase, record the exact failing test names **and their failure messages** (the suite baseline is ~144 pass / 16 fail; many fail only for lack of a DB fixture). Capture the baseline:

  ```bash
  for t in master/tests/*_test.php; do
    out=$(php "$t" 2>&1); rc=$?
    if [ $rc -ne 0 ]; then echo "FAIL $t :: $(echo "$out" | tail -1)"; fi
  done | sort > /tmp/leigod_test_baseline.txt
  cat /tmp/leigod_test_baseline.txt
  ```

  After Phases 1–2, re-run the same loop into `/tmp/leigod_test_after.txt` and `diff` them. **Assert:** the same set fails with the same last-line messages, AND none of these MPTCP-adjacent tests changed failure mode (a logic flip while the count stays 16 is a real regression):
  - `provisioning_mptcp_preferred_bond_slave_payload_regression_test.php`
  - `provisioning_slave_mptcp_desired_state_regression_test.php`
  - `bond_leigod_leg_cooldown_publish_test.php`

  ```bash
  diff /tmp/leigod_test_baseline.txt /tmp/leigod_test_after.txt && echo "REGRESSION GATE: clean"
  ```
  Expected: no diff (`REGRESSION GATE: clean`).

- [ ] **Step 9: Commit.**

  ```bash
  git add master/src/Support/ProvisioningService.php \
          master/migrations/20260604_leigod_mode_true_mptcp.sql \
          master/tests/saveforwardcombination_leigod_mode_true_mptcp_test.php \
          master/tests/leigod_mode_true_mptcp_migration_test.php
  git commit -m "feat(panel/leigod): leigod_mode selects true MPTCP (+migration; bond stays best-effort)"
  ```

---

## Phase 3 — MTU single-source (config-ize the leigod 1280, TDD)

**Files:**
- Modify: `master/src/Support/ProvisioningService.php:5841` (`$slave['mtu'] = 1280;`)
- Test: `master/tests/provisioning_leigod_wg_mtu_config_test.php`

The conservative MTU that matters is on the leigod-carried hop — the **static sidecar uplink**, which already ships `mtu=1280` (rendered as `MTU = 1280` per `test_static_sidecar_uplinks.py:184`). This phase makes that a single configurable source so the value can be tuned without a code edit and can't silently diverge. **No MPTCP-underlay MTU work** — that interface carries only the client peer and does not traverse LeiGod.

- [ ] **Step 1: Confirm the literal.** Run:

  ```bash
  grep -n "\$slave\['mtu'\] = 1280;" master/src/Support/ProvisioningService.php
  ```
  Expected: one hit at ~`:5841`. (If the literal text differs, adjust the edit below to match exactly.)

- [ ] **Step 2: Write the failing test.** Mirror the source-grep style of `master/tests/provisioning_leigod_uot_impl_test.php`. Create `master/tests/provisioning_leigod_wg_mtu_config_test.php`:

  ```php
  <?php
  declare(strict_types=1);

  function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }

  $svc = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';

  // The leigod static-uplink MTU must come from BOND_LEIGOD_WG_MTU (default 1280),
  // not a hardcoded literal, so it is a single tunable source of truth.
  if (!str_contains($svc, "\$this->config->getInt('BOND_LEIGOD_WG_MTU', 1280)")) {
      fail('ProvisioningService must read BOND_LEIGOD_WG_MTU (default 1280)');
  }
  if (str_contains($svc, "\$slave['mtu'] = 1280;")) {
      fail('ProvisioningService still hardcodes $slave[\'mtu\'] = 1280; (must use config)');
  }
  echo "OK\n";
  exit(0);
  ```

- [ ] **Step 3: Run the test to verify it fails.**

  Run: `php master/tests/provisioning_leigod_wg_mtu_config_test.php`
  Expected: **FAIL** — `ProvisioningService must read BOND_LEIGOD_WG_MTU (default 1280)`.

- [ ] **Step 4: Write the minimal implementation.** Edit `master/src/Support/ProvisioningService.php:5841`:

  ```php
  // before:
  $slave['mtu'] = 1280;
  // after:
  $slave['mtu'] = $this->config->getInt('BOND_LEIGOD_WG_MTU', 1280);
  ```

- [ ] **Step 5: Run the test to verify it passes, plus lint.**

  Run:
  ```bash
  php master/tests/provisioning_leigod_wg_mtu_config_test.php
  php -l master/src/Support/ProvisioningService.php
  ```
  Expected: `OK` and `No syntax errors detected`.

- [ ] **Step 6: Commit.**

  ```bash
  git add master/src/Support/ProvisioningService.php master/tests/provisioning_leigod_wg_mtu_config_test.php
  git commit -m "refactor(panel/leigod): leigod WG MTU from BOND_LEIGOD_WG_MTU config (single source)"
  ```

---

## Phase 4 — Deploy (REQUIRES explicit deploy authorization; version-safe)

> Do not start until the user gives the deploy go (see Authorization boundary). The slave agent changed, so the three-artifact rule applies unconditionally.

- [ ] **Step 1: Confirm no prod drift on the panel file.** On the panel VM, compare the live `ProvisioningService.php` against the pre-change git blob; abort if they differ (someone hand-edited prod):

  ```bash
  git show HEAD~3:master/src/Support/ProvisioningService.php | sha256sum   # pre-change blob
  # compare to the live file's sha256sum on the panel VM before overwriting
  ```

- [ ] **Step 2: Deploy the panel file + run the migration.**
  - Back up first: copy the live file to `ProvisioningService.php.bak-leigod-compose`.
  - `git show HEAD:master/src/Support/ProvisioningService.php` → temp file → `pscp` to `/www/.../master/src/Support/ProvisioningService.php`.
  - `php -l` the deployed file on prod.
  - Apply `master/migrations/20260604_leigod_mode_true_mptcp.sql` against `v3_leikwanhost_c`.

- [ ] **Step 3: Deploy the slave agent (three-artifact, UNCONDITIONAL).** Per `[[panel-artifact-serving]]` the nginx docroot statically shadows `artifacts/*.py`, so all three copies must match: `slave-agent/slave_agent.py`, `artifacts/...`, and `master/public/artifacts/...`. Bump `artifacts/versions.json` (new `AGENT_VERSION` / sha256). **Ordering constraint:** ship + confirm a canary slave has pulled the new slave agent **before** the panel publishes the true-MPTCP shape, so a leigod combo never reaches an old slave that would early-return without applying the uplink. (The MPTCP client + sidecar agents are unchanged — do not redeploy them.)

- [ ] **Step 4: Confirm clean reconcile on the canary slave** — no churn, no `EADDRINUSE`, agent on the new version:

  ```bash
  journalctl -u 'leikwan-slave-agent' --since '-5min' | grep -iE 'version|EADDRINUSE|error' | tail -20
  ```

---

## Phase 5 — Validation gate + soak + chaos + rollback (REQUIRES explicit deploy authorization)

- [ ] **Step 1: Cut combo 5 / forward 65 to true-MPTCP leigod.** Record the prior combo-5 row values first (for rollback): `SELECT client_mode, runtime_preference, backend_mode FROM forward_combinations WHERE id = 5;`. Then set combo 5 to `client_mode='mptcp'`, `runtime_preference='single'` (the migration left `bond` combos alone, so this is a deliberate recorded action), set `forwards.enabled=1` for 65 only (leave 67 parked — Step 6), and republish via `republishActiveForwardsForCombination`. Let agents reconcile on heartbeat (no manual restart storm).

- [ ] **Step 2: Confirm true MPTCP actually rides leigod** (via the panel jump host):
  - Client: `choose_runtime_family` resolves to `mptcp` (verify the forward's `mptcp` block has `sidecar_port>0` + `tcp_session_token`), and **no `leikwan-bond` process** is running for this forward.
  - Slave: **both** the MPTCP underlay interface (`lwf65n<nid>`) **and** the leigod static-uplink interface exist; the lguot client unit is active (`wg show`, `systemctl is-active leikwan-lguot-*`).
  - Sidecar: the lguot server unit is active and WG handshakes are fresh.

- [ ] **Step 3: Sustained throughput measurement** (one spaced run, ≥60 s): `iperf3` through forward 65. Watch:
  - **No cooldown storm, no agent restart storm (`EADDRINUSE`).**
  - Throughput **approaching the ~223 Mbit/s sidecar cap** (NOT `N×190` — the 2-vCPU sidecar bounds aggregate; see spec §7).
  - Latency ≈ a single leg; per-slave still **one** UoT; 0% loss.
  - If loss/fragmentation appears, tune `BOND_LEIGOD_WG_MTU` downward (re-publish), re-measure.

- [ ] **Step 4: Soak + chaos.**
  - **Soak:** ≥30 min, all leigod slaves concurrent, watching for LeiGod disconnect / IP-rotation / throttle (the 30 s foundation tests do NOT prove sustained fraud-safety — spec §9).
  - **Chaos:** kill `leigod@453` on one slave mid-iperf; record whether the path recovers (watchdog/health-demotion) or wedges (the no-automatic-fallback risk — spec §9).

- [ ] **Step 5: Decision + rollback.**
  - **Pass** (stable, true MPTCP, no storm, latency ≈ single-leg): record results in `docs/2026-06-03-leigod-mode-toplevel-feature.md` (new section) + update memory `[[leigod-mptcp-compose-design]]`.
  - **Fail** (falls back to bond, storms, or wedges): execute the **rollback runbook** — `forwards.enabled=0` for 65 → restore combo 5's prior `client_mode`/`runtime_preference` (the values recorded in Step 1) → republish → if needed restore the panel `ProvisioningService.php.bak-leigod-compose` and the prior `artifacts/versions.json`. Do **not** leave 65 half-migrated. Then re-open Phase 0 with the captured failure signature.

- [ ] **Step 6: Forward 67 — descoped by default.** 67 stays parked. Re-enabling requires a separate check (not "if capacity allows"): 65 with 3 slaves likely already approaches the ~223 cap, and 65/67 must have collision-free per-lane gost/lguot/uot ports (`stableSidecarGostSocksPort` / `stableLocalUotUdpPort`) on the shared sidecar (cf. `[[selector-port-collision-storm]]`). Gate 67 on measured sidecar headroom + a port-collision check.

- [ ] **Step 7: Commit the validation docs.**

  ```bash
  git add docs/2026-06-03-leigod-mode-toplevel-feature.md
  git commit -m "docs(leigod): record true-MPTCP-over-leigod validation results"
  ```

---

## Self-Review (plan vs. spec)

- **Spec §3 compose architecture** → Phase 1 (slave applies both underlay + static uplink) + Phase 2 (true-MPTCP runtime) ✓.
- **Spec §4 levers** — slave early-return fix (Phase 1), panel true-MPTCP selection (Phase 2). The explicitly-rejected non-changes (`local_uot_udp_port`, `allow_private_self_leg`, leigod-field surgery in the underlay builder, MPTCP-agent leigod arms) appear in **no** task ✓.
- **Spec §4 "cooldown kept for bond, not retired"** → Phase 2 Step 4 keeps `bond_leigod_leg_cooldown_publish_test` green ✓.
- **Spec §6 MTU single-source (no underlay MTU)** → Phase 3 ✓.
- **Spec §9 bond-runs-away** → Phase 2 (mptcp forces non-bond runtime_preference; bond is an explicit best-effort choice with the 20 s cooldown) ✓. **Actual-outage attribution** → Phase 0 Step 1 gates the premise ✓. **No-fallback** → Phase 5 Step 4 chaos ✓.
- **Spec §7/§8 realistic cap** → Phase 5 Step 3 measures against ~223, not `N×190` ✓.
- **Migration of parked combos** → Phase 2 Step 5; **live combo-5 cutover** is a recorded operator action (Phase 5 Step 1), not an automatic flip of `bond` combos ✓. **Rollback** → Phase 5 Step 5 ✓. **67 handling** → Phase 5 Step 6 (descoped) ✓.
- **Regression gate** → named-set + message diff, not a count (Phase 2 Step 8) ✓.
- **Placeholder scan:** every code step has the actual test/implementation code or the exact edit; every command has an expected result. No "TBD"/"similar to"/"add validation" ✓.
- **Type/identifier consistency:** `client_mode`/`runtime_preference`/`publish_bond_runtime`/`uses_static_sidecar_uplink`/`outer_transport='leigod'`/`apply_sidecar_uplink`/`apply_mptcp_underlay`/`BOND_LEIGOD_WG_MTU` are used identically across phases and match the code read in Phase 0 ✓.
- **Authorization:** Phases 0–3 committable; 4–5 gated on explicit deploy go ✓. The document itself executes nothing.

---

## Execution Handoff

Plan complete and saved. Two execution options when you're ready (and after the deploy authorization for Phases 4–5):

1. **Subagent-Driven (recommended)** — a fresh subagent per task with two-stage review between tasks (REQUIRED SUB-SKILL: superpowers:subagent-driven-development).
2. **Inline Execution** — execute tasks in this session with checkpoints (REQUIRED SUB-SKILL: superpowers:executing-plans).

Phases 0–3 can run now (commit-only); Phases 4–5 wait for the explicit deploy go.
