# CFWG Forward 47 Rollout / Unpin Runbook

Source handoff: [`leikwan-br1/docs/superpowers/handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md`](../../handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md)

This runbook is only safe to start after FEAT-001 and FEAT-002 deliverables are reviewed AND the render-diff harness from FEAT-002 has been wired up and is GREEN against both synthetic and live-captured fixtures for forward 47. If the harness has not yet been implemented in code, or has been implemented but not run against a fresh live capture for forward 47, stop here and finish FEAT-002 first. The handoff at `leikwan-br1/docs/superpowers/handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md` is the source of truth for every host-side command quoted below; that handoff explicitly warns that re-enabling auto-update before the panel build has been proven to render the same working CFWG contract is the dominant regression mode for forward 47, which is why this runbook is sequenced behind a hard pre-flight gate. The companion documents in this directory are [`01-cfwg-contract-assessment.md`](./01-cfwg-contract-assessment.md), [`02-priority-mapping.md`](./02-priority-mapping.md), [`03-regression-fixture-plan.md`](./03-regression-fixture-plan.md), and [`05-risk-register-and-non-goals.md`](./05-risk-register-and-non-goals.md); read them in that order if any step below is unclear.

The runbook has six numbered sections (1 pre-flight host, 2 pre-flight repo, 3 unpin sidecar, 4 unpin client, 5 post-unpin verification, 6 rollback) followed by a Sign-off checklist that mirrors the handoff's "Minimal success criteria for final remediation". Sections 1, 3, 4, 5, and 6 require shell on `sh36` or panel admin access and are tagged OUT OF SCOPE for this sandbox under network mode `INTEGRATIONS_ONLY`. Section 2 is the only step a coder agent can run from this repository; it is also the gate the operator must respect before touching any of the OUT OF SCOPE sections.

## 1. Pre-flight (host-side, OUT OF SCOPE for this sandbox)

Capture the current working state on `sh36` (`114.80.33.50`) before touching anything. The commands below are quoted verbatim from the handoff Priority 1 section ("Preserve current working runtime before touching anything") with the audit-backup path resolved to `/root/codex-backups/cfwg47-pre-unpin-<UTC-timestamp>/`; the placeholder `<UTC-timestamp>` is filled in by the operator at the moment they start this runbook (e.g. `20260520-013000`). All commands run as root on `sh36`.

```bash
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py
cp -a /usr/local/bin/leikwan-client-agent.py /root/codex-backups/cfwg47-pre-unpin-<UTC-timestamp>/
cp -a /usr/local/bin/install_forward_runtime.py /root/codex-backups/cfwg47-pre-unpin-<UTC-timestamp>/
cp -a /usr/local/bin/leikwan-client-start-47.sh /root/codex-backups/cfwg47-pre-unpin-<UTC-timestamp>/
cp -a /etc/leikwan/mptcp-forward-47.json /root/codex-backups/cfwg47-pre-unpin-<UTC-timestamp>/
cp -a /var/lib/leikwan-client-agent/applied.json /root/codex-backups/cfwg47-pre-unpin-<UTC-timestamp>/
systemctl is-active leikwan-client-agent.service leikwan-client-47.service
wg show lw-cfwg-47
ping -I lw-cfwg-47 -c 3 -W 1 10.204.53.42
```

Acceptance for this section:

- The two `systemctl is-active` results are both `active`.
- `wg show lw-cfwg-47` reports a recent `latest handshake` and a non-zero `received` byte count.
- `ping -I lw-cfwg-47 -c 3 -W 1 10.204.53.42` returns 0% packet loss.
- The five `cp -a` calls succeed and the resulting backup directory is non-empty.

If any of the above fails the runbook is aborted before any toggle. The unpin must not be attempted from a state that is already failing the live-traffic checks; that is a separate diagnosis loop, not a rollout. The recorded sha256 of the two `/usr/local/bin/` files in the backup directory is the canonical pin point for the rollback in Section 6.

## 2. Pre-flight (repo-side, IN SCOPE)

Run the FEAT-002 render-diff harness on the latest panel build, against both the synthetic seed and the live-captured fixtures for forward 47. The four diff tests plus the manifest consistency check must all be green before any panel-side toggle is considered. Run from the repo root:

```bash
python3 -m unittest leikwan-br1/client-agent/tests/test_cfwg_forward_47_render_diff.py
python3 -m unittest leikwan-br1/client-agent/tests/test_cfwg_forward_47_wg_conf_render.py
python3 -m unittest leikwan-br1/sidecar-agent/tests/test_cfwg_forward_47_route_render.py
php leikwan-br1/master/tests/cfwg_forward_47_panel_render_diff_test.php
python3 -m unittest leikwan-br1/client-agent/tests/test_artifact_versions_consistency.py
```

Acceptance for this section:

- All four diff tests exit zero against the live-captured fixtures committed under `leikwan-br1/client-agent/tests/fixtures/cfwg-forward-47/` and `leikwan-br1/sidecar-agent/tests/fixtures/cfwg-forward-47/`.
- The manifest consistency check exits zero against the in-tree files for `client_agent`, `install_forward_runtime`, `sidecar_agent`, and `mptcp_client_runtime` (per the FEAT-002 extension; today the test fails for `client_agent` due to declared-vs-actual hash drift, see [`01-cfwg-contract-assessment.md`](./01-cfwg-contract-assessment.md) "artifacts/versions.json hash drift" and [`05-risk-register-and-non-goals.md`](./05-risk-register-and-non-goals.md) risk R-06).
- The synthetic-only run from FEAT-002's "Bootstrapping the harness without the live capture" subsection is necessary but not sufficient; the live-captured run is the actual gate.

Any failure aborts the unpin. Do not "skip" a single failing test on the grounds that the diff is "small" or "expected": every assertion in the harness is mapped to a contract semantic from [`01-cfwg-contract-assessment.md`](./01-cfwg-contract-assessment.md) sections A through H, and a "small" diff in any of those semantics is exactly the failure mode the handoff describes. If a test is failing for a known-and-allowed reason, update the expected fixture in the same commit as the producing-side code change (per the FEAT-002 "Render-diff workflow" rule); do not edit the fixture mid-rollout.

## 3. Unpin sidecar point id=5 (panel-side action, OUT OF SCOPE for this sandbox)

Cross-reference: `leikwan-br1/master/src/Controllers/SidecarAgentController.php` line 245, the heartbeat handler echoes `'disable_auto_update' => $runtimeState['disable_auto_update'] ?? null` into the `config_patch` returned to the sidecar; this is the panel-side carrier of the pin flag for sidecar point id=5. The sidecar consumer side is `sidecar-agent/sidecar_agent.py` `apply_config_patch` at line 560, with the `disable_auto_update` branch at lines 592-600 (per `00-plan-corrections.md` C-04): when the panel-pushed `config_patch` carries `disable_auto_update`, that branch reads the value, normalises it to a bool, and writes (or removes) it from `self.config`, which is what makes the short-circuit checks at `sidecar_agent.py` lines 894 and 1453 fire on the next auto-update tick. The unpin is whatever panel admin action sets that JSON key back to `null` or `false` (or removes it entirely) on sidecar point id=5. The schema location is the sidecar's `agent_config_json` column read by `decodeJsonAssoc()` upstream of line 245.

The planner cannot determine the exact panel UI control or the exact SQL UPDATE statement that performs this write from the repository alone. The panel admin UI (or whichever direct DB tool the operator uses) is the canonical mechanism. This is an open question for the panel operator; it is recorded in the open-questions list in [`05-risk-register-and-non-goals.md`](./05-risk-register-and-non-goals.md) and is the reason this section is OUT OF SCOPE for this sandbox.

Verification gate (must pass before proceeding to Section 4):

- Wait at least one full sidecar heartbeat cycle so the sidecar agent has had a chance to fetch the new `config_patch` and reapply.
- Re-run `python3 -m unittest leikwan-br1/sidecar-agent/tests/test_cfwg_forward_47_route_render.py` against a fresh capture of `cf_runtime.routes` from the sidecar host (or from the panel-side response payload to sidecar point id=5). Confirm zero diff against `leikwan-br1/sidecar-agent/tests/fixtures/cfwg-forward-47/expected-cf-routes-14969.json` and `expected-cf-backend-cf-forward-47-cfwg.service`.
- On `sh36`, re-run `wg show lw-cfwg-47` and confirm the `received` byte counter is still increasing. The sidecar-side toggle should not affect client-side WG traffic by itself, but a regression in the sidecar-rendered route would surface as a stalled `received` counter within one heartbeat.

If the diff is non-zero or the wg traffic stops, jump to Section 6 (Rollback) immediately. Do not proceed to Section 4.

## 4. Unpin client id=3 (panel-side action, OUT OF SCOPE for this sandbox)

Cross-reference: `leikwan-br1/client-agent/client_agent.py` line 860, inside `auto_update_request_token()`: `if str(self.config.get("disable_auto_update", "") or "").lower() in {"1", "true", "yes", "on"}: return ""`. While this gate is truthy the client-side agent never asks the panel for a new artifact, regardless of any `desired_agent_sha256` the panel advertises. The unpin is whatever panel admin action removes the `disable_auto_update` key (or sets it to a falsy value) from client id=3's `agent_config_json`. The same caveat as Section 3 applies: the planner cannot determine the panel UI control or the SQL statement from the repo alone.

Sequence: unpin sidecar (Section 3) BEFORE unpinning the client (this section). The handoff rationale, restated: if the panel pushes a new client_agent.py before the sidecar has reapplied the new route shape, the client may render against a stale sidecar route table and re-introduce the failure. Sidecar first, wait one heartbeat cycle, then client.

Verification gate (must pass before proceeding to Section 5):

- Wait one client config-pull interval (default is the agent's heartbeat cadence; consult the live `leikwan-client-agent.service` configuration on `sh36` for the actual interval).
- Re-run the FEAT-002 client-side fixture tests against a fresh capture of the panel forward payload for forward 47:
  ```bash
  python3 -m unittest leikwan-br1/client-agent/tests/test_cfwg_forward_47_render_diff.py
  python3 -m unittest leikwan-br1/client-agent/tests/test_cfwg_forward_47_wg_conf_render.py
  ```
- Confirm zero diff against the live-captured fixtures. Confirm specifically the lines pinned by the harness: the `wstunnel client -L udp://127.0.0.1:40047:127.0.0.1:14969?timeout_sec=0` invocation in `expected-start-script.sh`, and the `Endpoint = 127.0.0.1:40047` line in `expected-wg-conf.conf`.

If the diff is non-zero or the wg traffic stops, jump to Section 6 (Rollback) immediately. Do not proceed to Section 5.

## 5. Post-unpin verification (host-side, OUT OF SCOPE for this sandbox)

Re-run the handoff Priority 1 baseline checks on `sh36`. The intent is to prove that the path is still alive after both pins have been cleared.

```bash
wg show lw-cfwg-47
ping -I lw-cfwg-47 -c 3 -W 1 10.204.53.42
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py
```

Acceptance:

- `wg show lw-cfwg-47` shows a recent `latest handshake`, a non-zero `received` byte counter, and the counter increases across two consecutive `wg show` invocations spaced by the heartbeat cycle.
- `ping -I lw-cfwg-47 -c 3 -W 1 10.204.53.42` returns 0% packet loss with rtt avg in the same band the handoff recorded (~50-52 ms).
- The on-host file hashes for `/usr/local/bin/leikwan-client-agent.py` and `/usr/local/bin/install_forward_runtime.py` now match either (a) the panel-served artifact hashes from `leikwan-br1/artifacts/versions.json` (i.e. the manifest the panel updater authoritatively serves) or (b) an explicitly-allowlisted local hash that the panel manifest references (this is the path for known-good local builds the operator is intentionally pinning to). One of those two must be true; an unrecognized hash means the panel pushed something the harness did not validate, which is itself a reason to roll back.

Stay at this gate for two client config pulls AND one sidecar heartbeat cycle before declaring the unpin done. This mirrors the handoff "Minimal success criteria for final remediation" item 6: "The state remains stable across at least two client config pulls and one sidecar heartbeat cycle." If at any point during this stabilization window the `wg show lw-cfwg-47` `received` counter stalls, the ping starts losing packets, or the on-host hashes drift to an unrecognized value, jump to Section 6 (Rollback).

## 6. Rollback (host-side, OUT OF SCOPE for this sandbox)

If at any verification gate (Section 2 pre-flight repo, Section 3 sidecar verify, Section 4 client verify, or Section 5 post-unpin verify) the diff is non-zero or the wg traffic stops, restore from the pre-flight backup made in Section 1 and re-pin both sides.

Steps on `sh36`:

```bash
cp -a /root/codex-backups/cfwg47-pre-unpin-<UTC-timestamp>/leikwan-client-agent.py /usr/local/bin/leikwan-client-agent.py
cp -a /root/codex-backups/cfwg47-pre-unpin-<UTC-timestamp>/install_forward_runtime.py /usr/local/bin/install_forward_runtime.py
cp -a /root/codex-backups/cfwg47-pre-unpin-<UTC-timestamp>/leikwan-client-start-47.sh /usr/local/bin/leikwan-client-start-47.sh
cp -a /root/codex-backups/cfwg47-pre-unpin-<UTC-timestamp>/mptcp-forward-47.json /etc/leikwan/mptcp-forward-47.json
cp -a /root/codex-backups/cfwg47-pre-unpin-<UTC-timestamp>/applied.json /var/lib/leikwan-client-agent/applied.json
systemctl restart leikwan-client-agent.service
wg show lw-cfwg-47
ping -I lw-cfwg-47 -c 3 -W 1 10.204.53.42
```

Steps on the panel:

- Re-pin `disable_auto_update=true` (or whatever truthy form the panel uses) on sidecar point id=5; refer to `leikwan-br1/master/src/Controllers/SidecarAgentController.php` line 245 for the field shape on the wire.
- Re-pin `disable_auto_update=true` on client id=3; refer to `leikwan-br1/client-agent/client_agent.py` line 860 for the consumer-side gate.

Then reopen the assessment loop with a fresh handoff doc under `leikwan-br1/docs/superpowers/handoffs/`, dated for the rollback day, with a record of which verification gate failed and what the observed diff or traffic state was. The backup pattern this runbook uses is not novel: the 2026-05-20 handoff already records two prior backups under that pattern, `/root/codex-backups/cfwg47-before-known-good-restore-20260519-110349` and `/root/codex-backups/panel-sidecar5-pin-20260519-231939`, both of which were created during the original stabilization pass. The pre-flight backup directory name in Section 1 (`/root/codex-backups/cfwg47-pre-unpin-<UTC-timestamp>/`) is intentionally consistent with that prior pattern so the on-disk archive is greppable and the next operator can find it without consulting this doc.

## Sign-off checklist

The unpin is considered complete only when all six items from the handoff "Minimal success criteria for final remediation" are simultaneously true. Each item below maps one-to-one onto the handoff's six-item list.

- [ ] (1) Current panel artifacts render forward 47 CFWG correctly. Evidence: Section 2 harness run is green against the live-captured fixtures from the post-unpin panel build, with no expected-fixture edits made mid-rollout.
- [ ] (2) Auto-update is enabled on both client id=3 and sidecar point id=5 without regression. Evidence: Section 3 and Section 4 verification gates both passed; `disable_auto_update` is null/false on both rows.
- [ ] (3) On-host file hashes for `/usr/local/bin/leikwan-client-agent.py` and `/usr/local/bin/install_forward_runtime.py` match panel artifact hashes (per `leikwan-br1/artifacts/versions.json` or an explicitly-allowlisted local hash that the panel manifest references). Evidence: Section 5 `sha256sum` output recorded.
- [ ] (4) `wg show lw-cfwg-47` shows a recent `latest handshake` and an increasing `received` byte counter across the stabilization window. Evidence: two `wg show` snapshots from Section 5, spaced by the heartbeat cycle.
- [ ] (5) `ping -I lw-cfwg-47 -c 3 -W 1 10.204.53.42` returns 0% packet loss. Evidence: Section 5 ping output recorded.
- [ ] (6) The state remains stable across at least two client config pulls AND one sidecar heartbeat cycle. Evidence: Section 5 stabilization window observed in full, no rollback triggered.

If any item is unchecked, the runbook is incomplete and the unpin is not done. Re-running the runbook is acceptable; declaring it done with one item unchecked is not.
