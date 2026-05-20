# CFWG Forward 47 Plan Corrections (2026-05-20)

Source handoff: [`leikwan-br1/docs/superpowers/handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md`](../../handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md)

This is the authoritative corrections index for the five-doc plan in this directory (`01-cfwg-contract-assessment.md` through `06-plan-self-inspection.md`). The self-inspection in `06-` flagged a number of defects in the rest of the plan, and a follow-up code reading found one more lineage drift it had missed. Where I have applied the correction in-place via `str_replace`, the row reads "patched in place". Where the correction is large enough to live in a dedicated section, the row points to that section. Read this file first; treat the older docs as accurate only modulo this index.

The accompanying new doc [`07-wstunnel-sticky-port-and-path-routing.md`](./07-wstunnel-sticky-port-and-path-routing.md) is the next step beyond the assessment: it documents the wstunnel server port and path-routing contract that already ships, then proposes two pieces of new work (deterministic per-forward backend port allocation, and an ICMP-driven latency watchdog with auto-restart and auto-path-correction). It is not a correction of the existing plan; it is an extension. Read `07-` after this index.

## Corrections that are patched in place

| ID | Affected doc | Defect | Fix (verbatim location) |
| --- | --- | --- | --- |
| C-01 | `03-regression-fixture-plan.md` test (d) | Proposed PHP render-diff test calls `buildSelfLegWireGuardProfile` / `buildSelfLegCandidate`; both are `private`. | Patched: the test now drives `ProvisioningService::assignedForwardConfigsForClient(int $clientId)` (public, line 2647), which exercises the full pipeline including `buildForwardCandidatePool` -> `buildSelfLegCandidate`. The fixture's `panel-payload.json` still doubles as the expected output; the test diffs the `candidate_pool` entry whose `kind` is the self-leg. |
| C-02 | `03-regression-fixture-plan.md` "Bootstrapping" subsection | Implementation guidance to "seed `panel-payload.json` from `_candidate()`" risks copying `path_prefix='/tokyo'` (leading slash) verbatim, which is the wrong shape for forward 47. | Patched: explicit "do not copy `/tokyo` literally" warning added; synthetic seed must use the no-leading-slash `0123456789abcdef` form to match the live forward-47 convention. |
| C-03 | `03-regression-fixture-plan.md` "Existing test infrastructure" subsection vs. `02-priority-mapping.md` Priority 2 item 3 | Two docs disagree on whether to retire the source-grep PHP regression tests. | Patched in `02-`: the source-grep tests are kept as cheap smoke tests in addition to the new execution-based tests, not replaced. The old "Replace" wording in `02-` is reworded to "Add execution-based tests alongside, keep the source-grep tests as smoke". |
| C-04 | `04-rollout-unpin-runbook.md` Section 3 | Sidecar `disable_auto_update` consumer side cited indirectly via line 245 (panel echo) and line 894 (auto-update gate) only; the actual config patch landing site is missing. | Patched: Section 3 now also cites `sidecar_agent.py:560 apply_config_patch` and the `disable_auto_update` branch at lines 592-600 as the seam where the panel-pushed flag lands in `self.config`. |
| C-05 | `05-risk-register-and-non-goals.md` R-06 | Evidence list is missing two of the four drifted lineages. | Patched: R-06 evidence now lists all four in-tree-vs-manifest hash drifts (`client_agent`, `install_forward_runtime`, `sidecar_agent`, `mptcp_client_runtime`). See "C-05 evidence" below. |
| C-06 | `05-risk-register-and-non-goals.md` R-06 tier | R-06 is in Tier 1 ("must be resolved before any unpin attempt"). The unpin gate is the harness against the live capture; the live host runs a third lineage entirely (`3abb7ecd...` for `client_agent.py`). The manifest drift is in-repo hygiene, not an operational unpin blocker. | Patched: R-06 moved to Tier 2 with a note that fixing it improves harness honesty but does not gate the rollout. |
| C-07 | `05-risk-register-and-non-goals.md` R-07 | R-07 is overstated as a "contract-shape mismatch": `ProvisioningService.php` does pure string concatenation, so whatever the DB stores (with or without leading slash) is what lands on the wire. There is no global "leading-slash is wrong" bug. | Patched: R-07 reframed as a fixture-convention question ("forward 47's row stores the no-slash form, so the new fixtures must match"). The existing `/tokyo` test stays valid for forwards whose row uses that convention. |

## Corrections that are documented here (not patched in place)

| ID | Defect | Resolution |
| --- | --- | --- |
| C-08 | Line numbers throughout `01-cfwg-contract-assessment.md` and `02-priority-mapping.md` drift by 3-7 lines. Examples: `render_cf_backend_unit` plan said line 798, actual line 791; `buildSelfLegWireGuardProfile` plan said line 3061, actual line 3056; `wantCfWg` plan said line 3000, actual line 2995. | Not patched in place. Future plan docs use symbol names plus grep-quotable substrings instead of absolute line numbers. The current plan's line numbers are accurate as of git revision 17545a4 and will drift naturally; do not rely on them after the next merge. |
| C-09 | `applied.json` is asserted-against in R-04 and Section 5 of `04-` without ever citing where the comparison happens. | Documented here. The relevant code is `client_agent.py:1044 apply_forward()`, which calls `render_forward_runtime()`, then writes each rendered file via `write_text_if_changed` (line 121); per-file content hashing IS the no-op gate, not a top-level digest. The applied.json structure is a per-forward dict `{str(forward_id): {"units": [...], "managed_paths": [...]}}` saved at `client_agent.py:723`. There is no top-level "signature key set" - the hash drift question reduces to "does any rendered file's bytes change?" R-04 should be reread with that in mind. |
| C-10 | `02-priority-mapping.md` claims `ClientAgentController.php` "echoes `disable_auto_update` to the client (cross-reference; not directly cited above)" without a line number. | Documented here. `ClientAgentController` does not currently include a `disable_auto_update` echo in `agent_config_json`; the symmetry with `SidecarAgentController.php:245` is by convention only. The flag lands on the client through `apply_management_state -> apply_config_patch(client_meta.get("agent_config_json"))` at `client_agent.py:801`, which means the panel must include `disable_auto_update` inside the `agent_config_json` it returns to the client. If a coder needs to verify this end-to-end, add a `grep -n "disable_auto_update" master/src/Controllers/ClientAgentController.php` step; today it returns nothing, which is itself worth a note. |

## C-05 evidence (full hash drift table)

This is the corrected R-06 evidence list. Every row is `(declared in artifacts/versions.json) vs. (actual sha256 of the in-tree file on branch codex/cfwg47-assessment-handoff-20260520) vs. (live restored on sh36 from the handoff "Live hashes after restoration" section)`.

| Manifest key | File | Declared | Actual (in-tree) | Live restored on sh36 |
| --- | --- | --- | --- | --- |
| `client_agent` | `client-agent/client_agent.py` | `75354f40397f64d78e334e256615547d7f44203fe920323fba90610753ccec5f` (size 191482) | `ebae5089c2a33683421607428ec9e4f3055465cb61226ae663781d45173da5ce` (size 187546) | `3abb7ecd4c572d3bcc18915b3ba0bdb8cf598ffcf3baf36b10288dc27c9cbf64` |
| `install_forward_runtime` | `client-agent/install_forward_runtime.py` | `f88c4c2f763a0d58ba49a4ea4f1a874eecac51cf3a54a4bc84c63bb22f5f9c24` (size 56592) | `9341ed7713fb8d76db85bfc75b0a21b77e97af81035139c49b38dfb34817d018` (size 55460) | `2e4c0c883e12901a56c85c9cca38941a4492703837d6b66a8f309ab2a9715548` |
| `sidecar_agent` | `sidecar-agent/sidecar_agent.py` | `8fffe1d45da0ae2345cf0e1c623e4375cad931ca9152fc056c757ca6c978b2cd` (size 79183) | `3dcc6f6f85a33d1f570cdc12cd7b7322a4065bdf28a1edd3cee095e22fa8a36c` (size 77594) | not recorded in handoff |
| `mptcp_client_runtime` | `client-agent/leikwan_mptcp_client.py` | `5f150daeeaa8f354036c14761b7beeb54e3518d2177a08db41d8219bf19cd3f7` (size 50810) | `33f177c415ef76dff9058ecf2dd9cca7c1a6d8225642cde2f7fdcc4813d667c1` (size not measured) | not recorded in handoff |

`mptcp_client_runtime` is a fourth drifted lineage that neither the original five docs nor `06-plan-self-inspection.md` flagged. It surfaced during the wstunnel inspection pass that produced `07-`. Add it to the FEAT-002 manifest consistency check from day one; if it is not in the extended `test_artifact_versions_consistency.py`, the harness will leave a known regression class uncovered.

## Authoritative line numbers (git revision 17545a4)

This table supersedes scattered line-number citations in the older docs. Symbol-plus-grep is preferred for long-lived references, but a pinned table is useful for the next coder who has to apply FEAT-002 and FEAT-003 against this exact branch.

| Symbol | File | Line | Visibility / notes |
| --- | --- | --- | --- |
| `assignedForwardConfigsForClient` | `master/src/Support/ProvisioningService.php` | 2647 | public; the test seam for FEAT-002 (d). |
| `buildForwardCandidatePool` | `master/src/Support/ProvisioningService.php` | 2733 | private; called from `assignedForwardConfigsForClient` line 2704. |
| `buildSelfLegCandidate` | `master/src/Support/ProvisioningService.php` | 2971 | private; produces the self-leg candidate dict. |
| `buildSelfLegWireGuardProfile` | `master/src/Support/ProvisioningService.php` | 3056 | private; populates `self_leg_wg.cf_*` fields. |
| `wantCfWg` truthy gate | `master/src/Support/ProvisioningService.php` | 2995 | `$wantCfWg = $cfBond && $cfTransport === 'cf_wg';` |
| `cf_path_prefix .cfwg` concat | `master/src/Support/ProvisioningService.php` | 3012 | `$wgProfile['cf_path_prefix'] = $cfBasePathPrefix . '.cfwg';` |
| Four-condition cfwg shadow-route gate | `master/src/Controllers/SidecarAgentController.php` | 213 | `selfLegCfTransport === 'cf_wg' && backendModeRaw === 'cf_mode' && sidecarWireguardListenPort > 0 && pathPrefix !== ''`. |
| `cf-forward-<id>-cfwg` shadow-route emit | `master/src/Controllers/SidecarAgentController.php` | 216 | `'service_name' => 'cf-forward-' . $forwardId . '-cfwg'`. |
| `127.0.0.1:<wireguard_listen_port>` shadow-route target | `master/src/Controllers/SidecarAgentController.php` | 218 | `'target' => '127.0.0.1:' . $sidecarWireguardListenPort`. |
| `disable_auto_update` echo (sidecar config_patch) | `master/src/Controllers/SidecarAgentController.php` | 245 | `'disable_auto_update' => $runtimeState['disable_auto_update'] ?? null`. |
| `wireguard_listen_port` default | `sidecar-agent/sidecar_agent.py` | 32 | `"wireguard_listen_port": 14969,` in `DEFAULT_CONFIG`. |
| `wireguard_listen_port()` getter | `sidecar-agent/sidecar_agent.py` | 140 | positive-int guard at 142. |
| `apply_config_patch` (sidecar) | `sidecar-agent/sidecar_agent.py` | 560 | consumer of panel-pushed `config_patch`. |
| `disable_auto_update` branch in `apply_config_patch` | `sidecar-agent/sidecar_agent.py` | 592-600 | reads value from patch and writes into `self.config`. |
| `allocate_cf_backend_port` | `sidecar-agent/sidecar_agent.py` | 770 | port range `[26000, 26100)`; reuses existing assignment from `cf-managed.json` if present. |
| `render_cf_backend_unit` | `sidecar-agent/sidecar_agent.py` | 791 | wstunnel server emit; `--restrict-to {target}` and `--restrict-http-upgrade-path-prefix {path_prefix}`. |
| `render_cf_mux_unit` | `sidecar-agent/sidecar_agent.py` | 809 | starts `leikwan-wss-mux.py` on `cf_runtime.listen_port` (the sticky public port). |
| `apply_management` | `sidecar-agent/sidecar_agent.py` | 882 | calls `apply_config_patch` at 888. |
| `disable_auto_update` short-circuit (sidecar update) | `sidecar-agent/sidecar_agent.py` | 894, 1453 | both paths gate on the same flag. |
| `apply_management_state` (client) | `client-agent/client_agent.py` | 800 | calls `apply_config_patch(client_meta.get("agent_config_json"))` at 801. |
| `auto_update_request_token` (client) | `client-agent/client_agent.py` | 859 | `disable_auto_update` short-circuit at 860. |
| `apply_config_patch` (client) | `client-agent/client_agent.py` | 904 | consumer of panel-pushed `agent_config_json`. |
| `apply_forward` | `client-agent/client_agent.py` | 1044 | renders runtime + writes via `write_text_if_changed` (per-file content gate). |
| `applied.json` write site | `client-agent/client_agent.py` | 723, 812 | JSON dict keyed by `str(forward_id)` plus `_last_agent_update_request_token`. |
| `WssPathMux.resolve_route` | `sidecar-agent/leikwan_wss_mux.py` | ~88 | longest-prefix path match against `cf-routes-<listen>.json`. |
| `normalize_path_prefix` | `sidecar-agent/leikwan_wss_mux.py` | 18 | strips leading and trailing `/`; the `.cfwg` suffix is preserved. |

## Reading order after this correction pass

1. [`00-plan-corrections.md`](./00-plan-corrections.md) (this file): start here, treat the older docs as accurate only modulo this index.
2. [`01-cfwg-contract-assessment.md`](./01-cfwg-contract-assessment.md): contract-surface map. Line numbers in this doc are stale per C-08; the symbol names and grep snippets are still correct.
3. [`02-priority-mapping.md`](./02-priority-mapping.md): handoff Priorities 1-5 split into in-repo vs. live-host. Patched per C-03.
4. [`03-regression-fixture-plan.md`](./03-regression-fixture-plan.md): regression fixture and harness design. Patched per C-01 and C-02.
5. [`04-rollout-unpin-runbook.md`](./04-rollout-unpin-runbook.md): operator runbook. Patched per C-04.
6. [`05-risk-register-and-non-goals.md`](./05-risk-register-and-non-goals.md): non-goals and R-01 through R-08. Patched per C-05, C-06, C-07.
7. [`06-plan-self-inspection.md`](./06-plan-self-inspection.md): the self-inspection that surfaced most of the defects above. Read it as historical context; the fixes have now been applied.
8. [`07-wstunnel-sticky-port-and-path-routing.md`](./07-wstunnel-sticky-port-and-path-routing.md): the new doc, an extension rather than a correction. Documents what is already sticky (`cf_runtime.listen_port`, path routing in `leikwan_wss_mux.py`) and proposes two pieces of new work: deterministic per-forward backend port allocation, and an ICMP-driven latency watchdog with auto-restart and auto-path-correction.
