# Plan Self-Inspection: CFWG Forward 47 Restore Assessment

Source handoff: [`docs/superpowers/handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md`](../../handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md)

This document is a critical inspection of the five plan docs `01-cfwg-contract-assessment.md` through `05-risk-register-and-non-goals.md` in this directory. It records what the plan got right when cross-checked against the actual repo on branch `codex/cfwg47-assessment-handoff-20260520`, and where the plan has real defects that a follow-up coder must address before implementation. The intent is to surface these gaps before any FEAT-002 fixture or test code is written.

## Verified correct

- File paths and structures: every path cited in the plan exists.
- `disable_auto_update` gate at `client_agent.py:860`: confirmed verbatim.
- `disable_auto_update` echo at `SidecarAgentController.php:245`: confirmed verbatim, inside the `config_patch` block at lines 239-247.
- Default `wireguard_listen_port = 14969` at `sidecar_agent.py:32`: confirmed.
- Hash drift in `artifacts/versions.json`:
  - `client_agent.py`: declared `75354f40...` size 191482, actual `ebae5089...` size 187546. Confirmed.
  - `install_forward_runtime.py`: declared `f88c4c2f...` size 56592, actual `9341ed77...` size 55460. Confirmed.
  - `sidecar_agent.py`: declared `8fffe1d4...` size 79183, actual `3dcc6f6f...` size 77594. **The plan never spotted this third drift; add it to the R-06 evidence list.**
- Capture-before-override on `cf_target_port` at `install_forward_runtime.py` line 391 (`cf_wg_target_port = endpoint_port`) before line 404 override to `40000 + (forward_id % 2000)`: confirmed, this is genuinely load-bearing.
- Four-condition gate at `SidecarAgentController.php:212` (`selfLegCfTransport === 'cf_wg' && backendModeRaw === 'cf_mode' && sidecarWireguardListenPort > 0 && pathPrefix !== ''`): confirmed.
- Both PHP source-grep tests exist as named: `provisioning_self_leg_cf_wg_transport_regression_test.php` and `sidecar_cfwg_route_publishes_wg_listen_port_regression_test.php`.

## Real defects in the plan

### 1. The PHP render-diff test cannot run as designed

`03-regression-fixture-plan.md` proposes `cfwg_forward_47_panel_render_diff_test.php` instantiates `ProvisioningService` and calls "the public entry point that produces the cf_wg branch (currently `buildSelfLegWireGuardProfile` plus the surrounding self_leg candidate assembly)". But:

- `buildSelfLegWireGuardProfile` at line 3056 is `private function`.
- `buildSelfLegCandidate` at line 2971 is also `private function`.
- Neither can be called directly from a test without reflection or a visibility change.

The plan does not identify the actual public entry point, and a coder following it would hit this wall on day one. Fix: identify the nearest public method that drives provisioning end-to-end and ultimately calls `buildSelfLegCandidate`, or propose loosening the visibility to `protected` with a test-side subclass. This must be settled before the FEAT-002 PHP test is implementable.

### 2. The plan missed `sidecar_agent.py:592-600`

The plan cites `sidecar_agent.py:894` and `:1453` for the `disable_auto_update` short-circuit but does not cite **lines 592-600**, which is the `apply_config_patch` consumer. That block reads `disable_auto_update` off the panel-pushed config patch and writes it into `self.config`. That is the actual flow point where the panel's pin lands on the sidecar, and the unpin runbook (`04-rollout-unpin-runbook.md` Section 3) would be more concrete with that line citation.

The same omission applies to `ClientAgentController.php`, which the plan claims "echoes `disable_auto_update` to the client (cross-reference; not directly cited above)" but never cites a line number.

### 3. Risk R-06 (manifest desync) is mis-tiered

R-06 is in Tier 1 in `05-risk-register-and-non-goals.md` ("must be resolved before any unpin attempt"). That is wrong. The unpin is gated on the harness against the live capture; the live host runs a third lineage entirely (`3abb7ecd...` for `client_agent.py` per the handoff) and does not consume the in-tree manifest at all. R-06 is in-repo hygiene, not an operational unpin blocker. It should move to Tier 2 with a note that fixing it improves harness honesty but does not gate the rollout.

### 4. Risk R-07 (path-prefix mismatch) is over-asserted

The plan claims a contract-shape mismatch between the leading-slash form (`/tokyo` in fixtures) and the no-leading-slash form (`07289d1f5ed25399.cfwg` live). Reading `ProvisioningService.php` lines 3011-3012:

```php
$cfBasePathPrefix = (string) ($config['path_prefix'] ?? '/');
$wgProfile['cf_path_prefix'] = $cfBasePathPrefix . '.cfwg';
```

This is pure string concatenation. Whatever the DB stores in `forwards.path_prefix` is what gets the `.cfwg` suffix. If forward 47's row stores `07289d1f5ed25399`, the wire form is `07289d1f5ed25399.cfwg`. If a different forward stores `/tokyo`, it becomes `/tokyo.cfwg`. **There is no contract-shape bug here.** The handoff's slash experiment did not fix the failure because the failure was elsewhere, not because the slash form is wrong globally.

R-07 should be reframed as a fixture-convention question: the new fixtures for forward 47 must use the no-leading-slash form because that is what production stores for forward 47, not because leading-slash is broken. The existing `/tokyo` test stays valid for forwards whose DB row uses that convention.

### 5. Line numbers drift by 3-7 lines in several places

- `render_cf_backend_unit`: plan says "around 798", actual `sidecar_agent.py:791`.
- `buildSelfLegWireGuardProfile`: plan says line 3061, actual line 3056.
- `sidecar_public_key` read: plan says line 3063, actual line 3060.
- `sidecar_public_key` emit on the candidate dict: plan says line 3092, actual approximately line 3088.

None of these are wrong in substance; they will not match in 6 months when the file is edited. Prefer symbol names plus grep snippets over absolute line numbers in long-lived plan docs.

### 6. `02-priority-mapping.md` and `03-regression-fixture-plan.md` disagree on retiring source-grep tests

- `02-priority-mapping.md` Priority 2 in-repo actions item 3: "Replace the two existing source-grep regression tests".
- `03-regression-fixture-plan.md` "Existing test infrastructure" subsection: they "can be retired (or kept as cheap smoke tests) once the execution-based tests are green".

Pick one. Keeping them as cheap smoke tests is the lower-risk call; they catch accidental snippet deletion at near-zero cost.

### 7. `applied.json` schema is asserted-against without ever being read

R-04 in `05-risk-register-and-non-goals.md` says the signature key set must cover all 8 contract semantics, and `04-rollout-unpin-runbook.md` Section 5 leans on `applied.json` stability across config pulls. But neither doc reads `client_agent.py` to identify the actual signature computation function or its key set. A coder following R-04 has to do that discovery from scratch. Add a citation to the function (one grep away) before the FEAT-002 fixture for `applied.json` is committed.

### 8. Synthetic-fixture bootstrapping should warn off `/tokyo` explicitly

`03-regression-fixture-plan.md` "Bootstrapping the harness without the live capture" subsection says to seed `panel-payload.json` from `test_self_leg_cf_wg_runtime.py::CfWgSelfLegTests._candidate()`. That existing test uses `path_prefix='/tokyo'` (lines 47, 372, 418, 446, 472). The new fixture for forward 47 should use the no-leading-slash form. The plan implies this but does not say "do not copy `/tokyo` verbatim", and the implementer will copy verbatim because that is the path of least resistance. Add an explicit instruction.

## Net assessment

The plan is materially correct on its central claims: the hash drift is real, the source-grep tests are a real coverage gap, the four-condition cfwg gate is the right surface to assert on, the unpin sequencing (sidecar first, client second, harness as gate) is sound. The defects are concentrated in **specificity** (private-method visibility, missed `apply_config_patch` flow, missed third hash drift on `sidecar_agent.py`) and **tiering judgement** (R-06 and R-07 are both over-assigned).

A follow-up coder should fix items 1, 2, and 8 before any FEAT-002 implementation begins, because each of them blocks a concrete deliverable named in the plan. Items 3, 4, 5, 6, 7 are correctness-of-the-plan issues that should be patched into the relevant docs but do not block parallel work.

## Companion docs in this directory

- [`01-cfwg-contract-assessment.md`](./01-cfwg-contract-assessment.md): contract-surface map for the eight CFWG semantics from the handoff.
- [`02-priority-mapping.md`](./02-priority-mapping.md): handoff Priorities 1-5 split into in-repo vs. live-host work.
- [`03-regression-fixture-plan.md`](./03-regression-fixture-plan.md): regression fixture and render-diff harness design.
- [`04-rollout-unpin-runbook.md`](./04-rollout-unpin-runbook.md): operator runbook for unpinning auto-update.
- [`05-risk-register-and-non-goals.md`](./05-risk-register-and-non-goals.md): non-goals and R-01 through R-08 risk register.
- [`06-plan-self-inspection.md`](./06-plan-self-inspection.md) (this file).
