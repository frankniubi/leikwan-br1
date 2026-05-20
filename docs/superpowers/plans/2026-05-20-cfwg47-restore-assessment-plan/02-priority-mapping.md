# CFWG Forward 47 Handoff Priority Mapping

Source handoff: [`leikwan-br1/docs/superpowers/handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md`](../../handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md)

This document is the second deliverable of the CFWG forward 47 assessment plan and is the companion to [`01-cfwg-contract-assessment.md`](./01-cfwg-contract-assessment.md). It walks each of the five priorities the handoff lists under "Suggested next work for inspection group" and splits every priority into two halves: work items that can be done from this repository alone (in scope for this sandbox) and work items that touch the live host `sh36` / `114.80.33.50` or the panel server at `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/` (out of scope for this sandbox under network mode `INTEGRATIONS_ONLY`). The subject is forward `47`, interface `lw-cfwg-47`, currently pinned via panel-side `disable_auto_update` on client `id=3` and sidecar point `id=5`. The user's hard rule for this pass is carried forward verbatim: **"Do not modify production code in this pass. Plan only."** No file under `leikwan-br1/` is edited by this document; every concrete change proposed below is described as work for a future coder agent and is gated on the diff harness in [`03-regression-fixture-plan.md`](./03-regression-fixture-plan.md) and the operator runbook in [`04-rollout-unpin-runbook.md`](./04-rollout-unpin-runbook.md).

The literal label `OUT OF SCOPE` is applied below to every action that requires shell access to `sh36`, SQL or admin-UI access to the panel database, or any external network reachability beyond this repository.

## Priority 1: Preserve current working runtime before touching anything

The handoff opens this priority with the directive to archive the live runtime files exactly as they are on `sh36` so that any later remediation step can be rolled back to the known-good state. The work splits cleanly into a fixture-skeleton job that lives in this repo and a byte-capture job that lives on the live host.

### In-repo actions (in scope)

1. Stand up the placeholder fixture directory `leikwan-br1/client-agent/tests/fixtures/cfwg-forward-47/` so that future host operators have a single, well-known location to drop the captured bytes into. This is a directory and a `README.md` in this pass, with no live bytes committed.
2. Write that `README.md` to enumerate the live files the operator must capture and the expected sha256 for each. The list is:
   - `leikwan-client-agent.py` from `/usr/local/bin/leikwan-client-agent.py` on `sh36`. Expected sha256 from the handoff: `3abb7ecd4c572d3bcc18915b3ba0bdb8cf598ffcf3baf36b10288dc27c9cbf64`.
   - `install_forward_runtime.py` from `/usr/local/bin/install_forward_runtime.py` on `sh36`. Expected sha256 from the handoff: `2e4c0c883e12901a56c85c9cca38941a4492703837d6b66a8f309ab2a9715548`.
   - `leikwan-client-start-47.sh` from `/usr/local/bin/leikwan-client-start-47.sh`. No declared hash; capture and record at the same time as the bytes.
   - `mptcp-forward-47.json` from `/etc/leikwan/mptcp-forward-47.json`. No declared hash; capture and record.
   - `applied.json` from `/var/lib/leikwan-client-agent/applied.json`. Capture the post-restoration version (after Action 2 of the handoff cleared the stale signatures) so that the recorded shape reflects a successful re-render, not the broken pre-fix state.
3. State explicitly in that README that no live bytes are committed in this pass and that the next agent who receives a captured payload must apply the scrubbing rules from [`03-regression-fixture-plan.md`](./03-regression-fixture-plan.md) before committing anything.
4. Cross-reference the handoff path `leikwan-br1/docs/superpowers/handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md` from the README so the link to the source of truth is preserved at the fixture site.

The full byte-capture and the harness that consumes those bytes are deferred to FEAT-002. This priority only justifies the fixture-skeleton work product so the in-repo path is ready to receive the bytes once the operator can produce them.

### Live-host / panel-server actions (OUT OF SCOPE for this sandbox)

The following are quoted from the handoff Priority 1 section verbatim and require shell on `sh36`. They are **OUT OF SCOPE** for this sandbox under `INTEGRATIONS_ONLY`:

```bash
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py
cp -a /usr/local/bin/leikwan-client-agent.py /root/<new-audit-backup>/
cp -a /usr/local/bin/install_forward_runtime.py /root/<new-audit-backup>/
cp -a /usr/local/bin/leikwan-client-start-47.sh /root/<new-audit-backup>/
cp -a /etc/leikwan/mptcp-forward-47.json /root/<new-audit-backup>/
cp -a /var/lib/leikwan-client-agent/applied.json /root/<new-audit-backup>/
systemctl is-active leikwan-client-agent.service leikwan-client-47.service
wg show lw-cfwg-47
ping -I lw-cfwg-47 -c 3 -W 1 10.204.53.42
```

Anything that reads from or copies out of `/root/leikwan-mptcp-leg-priority-sh36-20260519-005901` is also out of scope. SSH to `sh36` is not available from this sandbox; the planner cannot run any of the commands above.

## Priority 2: Diff known-good local runtime against current panel artifacts

This is the priority that motivates the entire planning task: a byte-level diff between what the live restored runtime emits and what the current panel build emits, scoped to the eight contract semantics enumerated in [`01-cfwg-contract-assessment.md`](./01-cfwg-contract-assessment.md). The repo half of the work is the harness design; the host half is the actual capture and diff execution.

### In-repo actions (in scope)

1. Design the regression-fixture and render-diff harness end to end. The full design is the deliverable of FEAT-002 and lives in [`03-regression-fixture-plan.md`](./03-regression-fixture-plan.md). This priority section forwards to that document for the fixture layout, scrubbing rules, the four proposed test files (`test_cfwg_forward_47_render_diff.py`, `test_cfwg_forward_47_wg_conf_render.py`, `test_cfwg_forward_47_route_render.py`, `cfwg_forward_47_panel_render_diff_test.php`), and the workflow that ties the harness to the unpin runbook.
2. Document the eight contract semantics under diff (already enumerated in `01-cfwg-contract-assessment.md` sections A through H). The harness must assert each semantic by name so that a future regression that flips one of them is rejected with a comprehensible failure label rather than a generic byte diff.
3. Replace the two existing source-grep regression tests with execution-based tests in the FEAT-002 plan: `leikwan-br1/master/tests/sidecar_cfwg_route_publishes_wg_listen_port_regression_test.php` and `leikwan-br1/master/tests/provisioning_self_leg_cf_wg_transport_regression_test.php`. Both are coverage gaps (see `01-cfwg-contract-assessment.md` "Existing test coverage gaps" section).
4. Extend `leikwan-br1/client-agent/tests/test_artifact_versions_consistency.py` to cover `install_forward_runtime`, `sidecar_agent`, and `mptcp_client_runtime` entries, not only `client_agent`. The current branch's working tree fails the existing assertion for `client_agent` (declared sha256 `75354f40...` vs. actual `ebae5089...`); document that as a pre-existing finding the next coder must either fix or quarantine before extending coverage.

### Live-host / panel-server actions (OUT OF SCOPE for this sandbox)

The byte-level diff itself is **OUT OF SCOPE** for this sandbox. It requires reading both of the following pairs that live on hosts the planner cannot reach:

```text
Known-good runtime on sh36:
/root/leikwan-mptcp-leg-priority-sh36-20260519-005901/leikwan-client-agent.py
/root/leikwan-mptcp-leg-priority-sh36-20260519-005901/install_forward_runtime.py

Current panel artifacts on the panel host:
/www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/client_agent.py
/www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/install_forward_runtime.py
/www/wwwroot/v3.leikwanhost.com/leikwan-v3/sidecar-agent/sidecar_agent.py
/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/SidecarAgentController.php
/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Support/ProvisioningService.php
```

Capturing the JSON body of the latest successful client-agent heartbeat response from the panel for forward `47` is also out of scope; that capture is the seed for `panel-payload.json` in the FEAT-002 fixture layout, but it must be obtained on the panel host before the fixture can be filled.

## Priority 3: Reproduce the broken current artifact in a controlled clone, not on the working live path

The handoff insists that the current panel build be reproduced on a clone before any unpin is attempted, because reproducing on `sh36` directly would risk regressing the very forward we are trying to protect. The repo half is the reproduction test plan; the host half is provisioning the clone.

### In-repo actions (in scope)

1. Define the reproduction test plan: a Python test (proposed path `leikwan-br1/client-agent/tests/test_cfwg_forward_47_render_diff.py`, designed in detail in FEAT-002) that loads a captured panel payload from `leikwan-br1/client-agent/tests/fixtures/cfwg-forward-47/panel-payload.json`, calls `install_forward_runtime.render_forward_runtime(forward, {})`, and asserts byte-equal output for the rendered `start_script`, `mptcp-forward-47.json`, and the systemd unit text against the corresponding `expected-*` fixture files.
2. State explicitly that `python3 -m py_compile leikwan-br1/client-agent/client_agent.py leikwan-br1/client-agent/install_forward_runtime.py leikwan-br1/sidecar-agent/sidecar_agent.py` is **not** reproduction. `py_compile` only checks that the source parses; it does not exercise the rendering path that produced the broken live runtime in the first place. Reproduction requires running the renderer on a real captured payload and diffing the output.
3. Identify the seam in `leikwan-br1/client-agent/install_forward_runtime.py` that the test must call. The relevant entry points are `self_leg_wg_underlay()` (which rewrites the WG `Endpoint` line and captures `cf_target_port` before the local UDP override) and `render_forward_runtime()` (which emits the `wstunnel client -L udp://127.0.0.1:<local>:<cf_target_host>:<cf_target_port>` line and the `ss -lun` wait). The test must exercise the full render, not just the underlay helper.
4. Wire a `unittest.skipIf(not fixture_path.exists(), ...)` guard so the test scaffolding can be committed before the live capture lands, per the FEAT-002 "Bootstrapping the harness without the live capture" subsection. The synthetic-fixture run is necessary but not sufficient to declare reproduction.

### Live-host / panel-server actions (OUT OF SCOPE for this sandbox)

Renting or provisioning a clone node, wiring it into the panel as a test client (creating a new `clients` row, attaching it to a sidecar point, having the panel push forward `47` to it), and then watching the panel-served runtime install on that clone are **OUT OF SCOPE** for this sandbox. None of the involved hosts are reachable; there is no panel admin access from the sandbox; the panel database is not visible. The cloned-node payload capture itself is also out of scope, but is the input the harness consumes once the operator returns it.

## Priority 4: Validate sidecar route state

The handoff describes the expected logical route on sidecar point `id=5` for forward `47` as `path_prefix=07289d1f5ed25399.cfwg`, `target=127.0.0.1:14969`, `transport_mode=udp`. Validating that the sidecar actually renders this route is two jobs: a sidecar-side fixture in this repo, and live inspection of the sidecar host.

### In-repo actions (in scope)

1. Define the sidecar-side fixture under `leikwan-br1/sidecar-agent/tests/fixtures/cfwg-forward-47/`. The fixture set is detailed in FEAT-002 and includes `cf-runtime-routes.json` (the captured `cf_runtime.routes` payload the panel ships in heartbeat), `expected-cf-routes-14969.json` (the `cf-routes-<listen>.json` the sidecar writes), and `expected-cf-backend-cf-forward-47-cfwg.service` (the per-route wstunnel server unit text).
2. Cross-reference the renderer the test must drive: `render_cf_backend_unit` in `leikwan-br1/sidecar-agent/sidecar_agent.py` around line 798. Backend port allocation in `[26000, 26100)` is in the same file and must be asserted as a stable assignment for the captured route, not as a free-running counter.
3. Use `leikwan-br1/sidecar-agent/tests/test_sidecar_cf_mode_runtime.py` as the pattern for the new test. That existing test already drives sidecar route rendering with synthetic input and asserts on the outputs; the new `test_cfwg_forward_47_route_render.py` follows the same shape but pins the inputs to forward 47 fixtures and asserts on the live-captured expected outputs.
4. Pin the assertions to the three contract fields the handoff calls out: `path_prefix=07289d1f5ed25399.cfwg`, `target=127.0.0.1:14969`, `transport_mode=udp`. Each must be checked individually so a failure points at the field that drifted.

### Live-host / panel-server actions (OUT OF SCOPE for this sandbox)

Inspecting `/etc/systemd/system/leikwan-bond-sidecar-5-*.service`, reading `cf-routes-<listen>.json` from the sidecar host file system, and watching `wg show lw-cfwg-47` receive counters increase on `sh36` are all **OUT OF SCOPE** for this sandbox. Capturing the heartbeat-time `cf_runtime.routes` JSON from the panel response for sidecar point `id=5` is also out of scope; that capture seeds `cf-runtime-routes.json` in the fixture, but it must be obtained on the panel host or by tcpdump on the sidecar host.

## Priority 5: Only after fixing the artifact contract, unpin updates carefully

This is the rollback-of-the-pin priority. The handoff sequences it last because none of the other work matters if the unpin re-introduces the failure mode. The repo half is writing the runbook; the host half is the actual sequenced toggle.

### In-repo actions (in scope)

1. Write the unpin runbook. The full document is the deliverable of FEAT-003 and lives at [`04-rollout-unpin-runbook.md`](./04-rollout-unpin-runbook.md). It is sequenced: pre-flight host backup, pre-flight repo harness run, unpin sidecar point `id=5`, unpin client `id=3`, post-unpin verification, and rollback if any gate fails.
2. Cross-reference the in-repo gate files for `disable_auto_update` so the runbook makes the toggle's effect explicit:
   - `leikwan-br1/master/src/Controllers/SidecarAgentController.php` line 245 echoes `'disable_auto_update' => $runtimeState['disable_auto_update'] ?? null` into the `config_patch` returned to the sidecar at heartbeat time. This is the panel-side carrier of the flag.
   - `leikwan-br1/client-agent/client_agent.py` line 860 short-circuits the `auto_update_request_token()` function with `if str(self.config.get("disable_auto_update", "") or "").lower() in {"1", "true", "yes", "on"}: return ""`. This is the client-side gate that consumes the flag and that, while truthy, prevents the panel from pushing a new artifact.
3. Document the "stop conditions" the runbook must encode: the unpin is aborted if the diff harness from FEAT-002 reports any non-zero diff, if the artifact manifest consistency check still fails on the new panel build, or if the post-unpin `wg show lw-cfwg-47` receive counter does not increase across two client config pulls and one sidecar heartbeat cycle.
4. State that the planner cannot determine the exact panel UI control or SQL statement that toggles `disable_auto_update` from the repo alone; that detail is a question for the panel operator and is captured as an open question in [`05-risk-register-and-non-goals.md`](./05-risk-register-and-non-goals.md).

### Live-host / panel-server actions (OUT OF SCOPE for this sandbox)

The actual sequenced toggle on the panel and the post-toggle verification on `sh36` are **OUT OF SCOPE** for this sandbox. Specifically out of scope:

- The panel admin UI action or DB write that clears `disable_auto_update` for sidecar point `id=5`. The schema location is whichever JSON column on `bond_sidecar_point` carries the flag (`agent_config_json` per `ProvisioningService::decodeJsonAssoc`), but the planner cannot run the write.
- The panel admin UI action or DB write that clears `disable_auto_update` for client `id=3`. Same constraint.
- The waiting period between toggles for the heartbeat cycle to settle.
- Re-running `wg show lw-cfwg-47` and `ping -I lw-cfwg-47 10.204.53.42` on `sh36` to confirm the path is still up after each gate.
- The rollback path that restores `/usr/local/bin/leikwan-client-agent.py` and `/usr/local/bin/install_forward_runtime.py` from the pre-flight backup created at the top of the runbook.

## Out of scope summary

Every action below is referenced in the handoff and is unreachable from this sandbox under network mode `INTEGRATIONS_ONLY`. They are listed once here so the boundary is unambiguous:

- SSH to `sh36` (`114.80.33.50`) for any of: `sha256sum`, `cp -a`, `systemctl is-active`, `wg show lw-cfwg-47`, `ping -I lw-cfwg-47 10.204.53.42`, `systemctl restart leikwan-client-agent.service`.
- Reading or copying from the known-good backup directory `/root/leikwan-mptcp-leg-priority-sh36-20260519-005901` on `sh36`.
- Reading or modifying the live installed runtime files `/usr/local/bin/leikwan-client-agent.py`, `/usr/local/bin/install_forward_runtime.py`, `/usr/local/bin/leikwan-client-start-47.sh` on `sh36`.
- Reading or modifying the live config files `/etc/leikwan/mptcp-forward-47.json` and the applied-state file `/var/lib/leikwan-client-agent/applied.json` on `sh36`.
- Reading the panel artifact tree `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/` (paths under `client-agent/`, `sidecar-agent/`, `master/src/Controllers/`, `master/src/Support/`).
- Inspecting sidecar host files `/etc/systemd/system/leikwan-bond-sidecar-5-*.service` and the live `cf-routes-<listen>.json` written by the sidecar.
- Panel database writes against `clients.id=3` or `bond_sidecar_point.id=5` to toggle `disable_auto_update`.
- Panel admin UI access for the same toggles.
- Capturing the JSON body of a successful client-agent heartbeat response or the `cf_runtime.routes` JSON the panel ships to sidecar point `id=5`.

The sandbox network mode is `INTEGRATIONS_ONLY`, which permits repository (GitHub) access only. Public registries, external HTTP, and any host outside the configured GitHub integration are unreachable, which blocks every action in the list above. The repo-side work products designed in this document are sufficient to leave the in-repo half ready for a live operator to drop in the captured bytes and run the harness; they do not, and cannot, complete the validation on their own.
