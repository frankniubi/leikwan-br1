# CF Mode / Forward Combinations Change Log

Date: 2026-04-20
Branch: `codex/android-single-bond-linux-parity`

## Related design and plan

- Design: `docs/superpowers/specs/2026-04-20-cf-location-groups-forward-combinations-design.md`
- Implementation plan: `docs/superpowers/plans/2026-04-20-cf-location-groups-forward-combinations.md`

## Summary

This work added a new admin-controlled forwarding model built around:

- independent **Cloudflare Location Groups**
- tenant-hidden **Forward Combinations**
- a new backend mode, **`cf_mode`**
- slave-side **best CF IP selection per location group**
- sidecar-side **WSS mux runtime**
- UDP / WireGuard-compatible traffic support through an **independent UoT shadow channel**

The tenant panel no longer needs to know slave groups, sidecar points, or Cloudflare CIDR groups directly. Tenants choose a forward combination only.

## What was developed

### 1. Panel schema and provisioning foundation

Added foundation for the new control-plane objects:

- `cf_location_groups`
- `cf_location_group_cidrs`
- `forward_combinations`
- forward snapshot fields:
  - `forward_combination_id`
  - `backend_mode`
  - `cf_location_group_id`
- sidecar point field:
  - `cf_listen_port`

Main files:

- `master/migrations/20260420_add_cf_location_groups_and_forward_combinations.sql`
- `master/migrations/schema.sql`
- `master/src/Support/ProvisioningService.php`
- `master/tests/provisioning_forward_combination_regression_test.php`

What changed in provisioning:

- added CRUD-style save helpers for Cloudflare location groups and forward combinations
- added service-scoped combination filtering through existing allowed slave-group and sidecar-point plan rules
- added forward snapshot resolution from `forward_combination_id`
- added `cf_mode` payload generation for slave publish tasks
- exposed `forward_combination_name` / `backend_mode` in service summary and client config views

Commit:

- `ca76575` ? `feat: add CF location group and forward combination foundation`

### 2. Admin control surfaces and tenant-visible simplification

Built admin pages for:

- Cloudflare Location Groups
- Forward Combinations

Built tenant-facing route-package flow:

- tenant chooses **Forward Combination** only
- tenant sees friendly route labels and no backend topology
- tenant gets a clear empty-state message when no route package exists

Also extended sidecar point forms with:

- `CF Listen Port`
- `CF TLS Cert Path`
- `CF TLS Key Path`

Main files:

- `master/src/Bootstrap.php`
- `master/src/Controllers/AdminController.php`
- `master/src/Controllers/TenantController.php`
- `master/src/Controllers/BaseController.php`
- `master/src/Support/ProvisioningService.php`
- `master/tests/admin_forward_combination_page_test.php`
- `master/tests/tenant_forward_combination_regression_test.php`

Commit:

- `4cb7e16` ? `feat: add CF group and forward combination control surfaces`

### 3. Sidecar `cf_mode` runtime

Built a new sidecar-side WSS routing layer for `cf_mode`:

- added `cf_runtime` management payload branch in sidecar heartbeat controller
- added sidecar route rendering for:
  - main TCP WSS route
  - optional `.uot` shadow route for UDP-compatible traffic
- added sidecar local route materialization into route JSON plus preview service files
- added standalone WSS path mux runtime:
  - `sidecar-agent/leikwan_wss_mux.py`

Main files:

- `master/src/Controllers/SidecarAgentController.php`
- `sidecar-agent/sidecar_agent.py`
- `sidecar-agent/leikwan_wss_mux.py`
- `sidecar-agent/tests/test_sidecar_cf_mode_runtime.py`
- `master/src/Controllers/ArtifactController.php`
- `master/src/Bootstrap.php`
- `install/install-bond-sidecar.sh`

### 4. Slave best-IP selection and CF transport bridge

Built slave-side CF selection state and runtime bridge:

- per-slave, per-location-group best IP cache
- candidate expansion from CIDRs
- TCP probe-based candidate scoring
- main WSS client bridge toward the selected CF IP
- independent `.uot` shadow channel for UDP / WireGuard-compatible traffic
- shadow route separation from the main TCP route

Main files:

- `slave-agent/slave_agent.py`
- `slave-agent/tests/test_slave_cf_location_group_selection.py`
- `slave-agent/tests/test_slave_udp_uot_shadow_routes.py`
- `slave-agent/tests/test_slave_plain_tcp_mux.py`
- `install/install-slave.sh`

### 5. Observability

Added first-pass visibility for admin:

- latest reported CF location stats from slaves
- columns for:
  - current best CF IP
  - `last_latency_ms`
  - `last_jitter_ms`
  - last probe timestamp
- forward tables now surface `forward_combination_name`

Main files:

- `master/src/Controllers/AdminController.php`
- `master/src/Controllers/TenantController.php`
- `master/tests/admin_cf_location_observability_test.php`
- `slave-agent/slave_agent.py`

### 6. Installer and artifact updates

Updated installers so the new runtime path has the binaries it needs:

- sidecar installer now pulls:
  - `leikwan-wss-mux.py`
  - `wstunnel`
- slave installer now ensures:
  - `wstunnel`

Files:

- `install/install-bond-sidecar.sh`
- `install/install-slave.sh`
- `master/src/Controllers/ArtifactController.php`
- `master/src/Bootstrap.php`

### 7. Final runtime path delivered

The implemented `cf_mode` path is now:

```text
client -> slave -> selected CF IP -> WSS -> sidecar -> target backend
```

For UDP / WireGuard-compatible traffic in `cf_mode`:

```text
client -> slave -> selected CF IP -> WSS(UoT shadow) -> sidecar -> UDP compatibility runtime
```

Key runtime rules preserved:

- topology remains `client -> slave -> sidecar`
- one concrete forward binds to one location group only
- one slave shares one best-IP decision per location group
- sidecar does not need to know which CF IP was chosen
- tenant chooses route package only

Commit:

- `4474ba5` ? `feat: complete CF mode control and runtime path`

## Files changed in this delivery

- `install/install-bond-sidecar.sh`
- `install/install-slave.sh`
- `master/migrations/20260420_add_cf_location_groups_and_forward_combinations.sql`
- `master/migrations/schema.sql`
- `master/src/Bootstrap.php`
- `master/src/Controllers/AdminController.php`
- `master/src/Controllers/ArtifactController.php`
- `master/src/Controllers/BaseController.php`
- `master/src/Controllers/SidecarAgentController.php`
- `master/src/Controllers/TenantController.php`
- `master/src/Support/ProvisioningService.php`
- `master/tests/admin_cf_location_observability_test.php`
- `master/tests/admin_forward_combination_page_test.php`
- `master/tests/provisioning_forward_combination_regression_test.php`
- `master/tests/tenant_forward_combination_regression_test.php`
- `sidecar-agent/leikwan_wss_mux.py`
- `sidecar-agent/sidecar_agent.py`
- `sidecar-agent/tests/test_sidecar_cf_mode_runtime.py`
- `slave-agent/slave_agent.py`
- `slave-agent/tests/test_slave_cf_location_group_selection.py`

## Verification run

The following commands were run successfully after implementation:

```bash
php master/tests/provisioning_forward_combination_regression_test.php
php master/tests/admin_forward_combination_page_test.php
php master/tests/tenant_forward_combination_regression_test.php
php master/tests/admin_cf_location_observability_test.php
python -m unittest sidecar-agent.tests.test_sidecar_cf_mode_runtime
python -m unittest slave-agent.tests.test_slave_cf_location_group_selection
python -m unittest slave-agent.tests.test_slave_plain_tcp_mux slave-agent.tests.test_slave_udp_uot_shadow_routes
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/AdminController.php
php -l master/src/Controllers/TenantController.php
php -l master/src/Controllers/SidecarAgentController.php
php -l master/src/Controllers/ArtifactController.php
php -l master/src/Bootstrap.php
python -m py_compile sidecar-agent/sidecar_agent.py sidecar-agent/leikwan_wss_mux.py slave-agent/slave_agent.py
```

Note:

- local PHP still prints `Invalid date.timezone value 'UTC+8'` warnings
- these warnings did not block successful test or lint completion

## Delivered behavior checklist

- [x] independent Cloudflare location groups
- [x] admin-defined forward combinations
- [x] tenant chooses combination only
- [x] sidecar point supports CF listener and CF TLS path settings
- [x] service summary exposes available forward combinations
- [x] provisioning snapshots combination state into forwards
- [x] provisioning emits `cf_mode` publish payloads
- [x] sidecar receives and applies `cf_runtime`
- [x] sidecar hosts WSS mux routing for `cf_mode`
- [x] slave chooses best CF IP per location group
- [x] slave reuses one best-IP result per location group
- [x] slave main TCP bridge for `cf_mode`
- [x] independent UoT shadow channel for UDP / WireGuard-compatible traffic
- [x] admin observability for reported CF IP selection metrics

## Commits in this workstream

- `dd0d295` ? `docs: add CF mode forward combination design`
- `ca76575` ? `feat: add CF location group and forward combination foundation`
- `4cb7e16` ? `feat: add CF group and forward combination control surfaces`
- `4474ba5` ? `feat: complete CF mode control and runtime path`

## Recommended next steps

1. Push these commits to the remote branch if not already pushed.
2. Do live panel deployment and runtime artifact publication.
3. Verify one `current_mode` combination and one `cf_mode` combination against the real environment.
4. Specifically smoke-test:
   - `cf_mode + single + tcp`
   - `cf_mode + bond + tcp+udp`
   - WireGuard-compatible traffic through the independent UoT shadow route.
