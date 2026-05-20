# CFWG Forward 47 Contract Assessment

Source handoff: [`leikwan-br1/docs/superpowers/handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md`](../../handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md)

This document is the in-repo half of the CFWG forward 47 assessment. It maps each contract semantic the handoff calls out under "Priority 2: Diff known-good local runtime against current panel artifacts" to the exact file and lines in this repo that produce it, records the value or shape the in-tree code emits today, and flags every divergence between the in-repo render and the working live runtime that the handoff captured on `sh36`. Companion docs live in this directory: `02-priority-mapping.md` (handoff Priority 1-5 work split), `03-regression-fixture-plan.md` (render-diff harness), `04-rollout-unpin-runbook.md` (operator sequence to re-enable auto-update), `05-risk-register-and-non-goals.md` (risks and explicit non-goals).

## Subject under assessment

| Field | Value |
| --- | --- |
| Live host | `sh36` / `114.80.33.50` |
| Forward id | `47` |
| Interface name | `lw-cfwg-47` |
| Peer (sidecar) target | `10.204.53.42` |
| Stabilization source | `/root/leikwan-mptcp-leg-priority-sh36-20260519-005901` |
| Restored client-agent path on sh36 | `/usr/local/bin/leikwan-client-agent.py` |
| Restored installer path on sh36 | `/usr/local/bin/install_forward_runtime.py` |
| Auto-update pin (panel) | `disable_auto_update` set on client `id=3` and sidecar point `id=5` |
| In-repo branch | `codex/cfwg47-assessment-handoff-20260520` (one commit ahead of `master`, contains only the handoff doc) |
| Sandbox network mode | `INTEGRATIONS_ONLY` (no live host or panel access) |

The live host, the panel server, and the live restored runtime files are not reachable from this sandbox. Every "live" hash, path, and process line below is quoted from the handoff. Every "in-repo" hash, path, and line range is verified against the working tree of `leikwan-br1/` on this branch.

## Three-component CFWG topology

The handoff describes a CFWG (CloudFlare WireGuard) forward that goes:

```
client (sh36) -> wstunnel client over WSS -> CF edge -> sidecar wstunnel server -> sidecar WG listener (port 14969) -> WG peer 10.204.53.42
```

Three components in this repo collaborate to render that contract:

1. **Panel (PHP)** publishes the forward dict to the client and the sidecar route table to the sidecar:
   - `leikwan-br1/master/src/Support/ProvisioningService.php` builds the `self_leg_wg` profile carrying `outer_transport=wstunnel_udp`, `cf_dial_host`, `cf_dial_port`, `cf_sni_host`, `cf_path_prefix`, `interface_name`.
   - `leikwan-br1/master/src/Controllers/SidecarAgentController.php` heartbeat handler emits the `cf-forward-<id>-cfwg` shadow route into `cf_runtime.routes` so the sidecar opens a wstunnel server that forwards UDP to the local sidecar WG listener.
2. **Client agent (Python)** consumes the panel forward dict and renders the runtime:
   - `leikwan-br1/client-agent/install_forward_runtime.py` `self_leg_wg_underlay()` rewrites the WG `Endpoint` to `127.0.0.1:<40000+forward_id%2000>` for `wstunnel_udp` and `render_forward_runtime()` emits the `wstunnel client -L udp://127.0.0.1:<local>:<cf_target_host>:<cf_target_port>` line that points at the CF edge.
   - `leikwan-br1/client-agent/client_agent.py` decides whether to ask the panel for a new artifact via `auto_update_request_token()`.
3. **Sidecar agent (Python)** consumes the cf_runtime.routes payload and writes the systemd units:
   - `leikwan-br1/sidecar-agent/sidecar_agent.py` defaults `wireguard_listen_port=14969`, allocates a backend port in `[26000, 26100)` per route, and writes `cf-routes-<listen>.json` plus a per-route `Leikwan CF Backend` unit.

## Contract surface map (handoff Priority 2)

The handoff lists eight semantics to diff against the known-good local build. Each subsection below answers four questions for one semantic: (a) which in-repo file produces it, (b) which lines, (c) what value the in-repo code produces, and (d) what divergence (if any) shows up against the live runtime.

### A. `self_leg_cf_transport = cf_wg`

| Aspect | Finding |
| --- | --- |
| Source of truth (panel) | `leikwan-br1/master/src/Support/ProvisioningService.php` |
| Lines | 2994 (read), 3000 (`$wantCfWg = $cfBond && $cfTransport === 'cf_wg';`), 3018 (`$probeTransport = $wgProfile !== null ? 'cf_wg' : 'cf_direct_wss';`) |
| Trigger conditions | `$cfBond` is true only when `forwards.backend_mode='cf_mode'` (line 2989). `$wantCfWg` is true only when both `cf_mode` AND `forward_combinations.self_leg_cf_transport='cf_wg'`. |
| In-repo value | When `wantCfWg && wgProfile !== null`, the candidate carries `probe_transport='cf_wg'`, `runtime_capabilities=['bond','mptcp','wireguard']`, `outer_transport='wstunnel_udp'` on `self_leg_wg`. |
| Persistence | `forward_combinations.self_leg_cf_transport` is read at lines 413 and 469 (combination save) and reflected back via the SQL alias on line 3289. |
| Divergence vs. live | The handoff confirms the live working runtime drives `self_leg_cf_transport='cf_wg'`. No in-repo divergence on the flag itself; the divergence (if any) is downstream in how the cf_wg branch renders. |

The flag is the gate that enables every other CFWG-specific code path below. The two existing master-side regression tests (`master/tests/forward_combination_self_leg_cf_transport_persistence_regression_test.php`, `master/tests/admin_forward_combination_self_leg_policy_test.php`) cover persistence and admin UI, not render output.

### B. CFWG path prefix `<path_prefix>.cfwg`

| Aspect | Finding |
| --- | --- |
| Source of truth (panel) | `leikwan-br1/master/src/Support/ProvisioningService.php` |
| Lines | 3011 (`$cfBasePathPrefix = (string) ($config['path_prefix'] ?? '/');`), 3012 (`$wgProfile['cf_path_prefix'] = $cfBasePathPrefix . '.cfwg';`) |
| Mirror in sidecar emission | `leikwan-br1/master/src/Controllers/SidecarAgentController.php` lines 213-224, the `cf-forward-<id>-cfwg` branch sets `'path_prefix' => $pathPrefix . '.cfwg'` and `'shadow_route_key' => $pathPrefix . '.cfwg'`. |
| In-repo value | Pure string concatenation of `forwards.path_prefix` with the literal `.cfwg`. No leading-slash normalization on either side. |
| Live runtime value | The handoff shows the live wstunnel client invocation uses `--http-upgrade-path-prefix 07289d1f5ed25399.cfwg` (no leading slash). |
| In-repo test fixture | `leikwan-br1/client-agent/tests/test_self_leg_cf_wg_runtime.py` uses `path_prefix='/tokyo'` on lines 372, 418, 446, 472. With concatenation this yields `cf_path_prefix='/tokyo.cfwg'` (leading slash). |
| Divergence vs. live | If the production DB stores `forwards.path_prefix` for forward 47 with a leading slash (matching the test fixture convention), `ProvisioningService` will publish `/<hex>.cfwg` while the working sh36 runtime uses `<hex>.cfwg`. The handoff explicitly records that an experimental leading-slash test (`/root/codex-backups/cfwg47-slash-test-20260519-111301`) did not fix the failure, which is consistent with the live working form being no-slash. The in-repo code does not normalize, so this is a contract-shape risk. Flagged as risk **R-07** in `05-risk-register-and-non-goals.md`. |

The `.cfwg` suffix carries a regression sentinel comment on line 3009-3010 (`Regression sentinel: cf_wg_path_prefix_uses_cfwg_suffix`), but that sentinel is enforced only by source grep (see section "Existing test coverage" below).

### C. Sidecar WireGuard peer public key for `lw-cfwg-47`

| Aspect | Finding |
| --- | --- |
| Source of truth (panel) | `leikwan-br1/master/src/Support/ProvisioningService.php` |
| Lines | 3061 (`$sidecarConfig = $this->decodeJsonAssoc($config['bond_sidecar_point_agent_config_json'] ?? null);`), 3063 (`$sidecarPublicKey = trim((string) ($sidecarConfig['wireguard_public_key'] ?? ''));`), 3092 (`'sidecar_public_key' => $sidecarPublicKey,`) |
| Selection rule | The sidecar pubkey is read from the joined `bond_sidecar_point.agent_config_json -> wireguard_public_key` of the sidecar point that backs the forward (sidecar point `id=5` in the handoff). If the sidecar pubkey is empty the `buildSelfLegWireGuardProfile` returns `null` and the cf_wg branch is silently skipped. |
| In-repo client side | `leikwan-br1/client-agent/install_forward_runtime.py` `self_leg_wg_underlay()` line 384 reads `sidecar_public_key = str(wg.get("sidecar_public_key", "") or "").strip()`. Line 401 returns `None` if it is empty. The pubkey lands in the WG `[Peer]` section emitted by `leikwan-br1/client-agent/leikwan_mptcp_client.py` (function `build_wireguard_interface_config` family). |
| Live runtime value | Handoff `wg show lw-cfwg-47` post-fix shows `peer: bBbi/7CsWMWrX/TrM/mRwTWceO3UoOIAL2S1BXLXCw4=`. |
| Divergence vs. live | None observable from the repo alone. Risk: if sidecar pubkey is rotated on the panel without re-rendering `applied.json`, the client may continue using the stale pubkey because `applied.json` signature would not change in a way the agent recognizes. Tracked as risk **R-03** and **R-04** in `05-risk-register-and-non-goals.md`. |

### D. Sidecar WireGuard listen port `14969`

| Aspect | Finding |
| --- | --- |
| Source of truth (sidecar default) | `leikwan-br1/sidecar-agent/sidecar_agent.py` |
| Lines | 32 (`"wireguard_listen_port": 14969,` in `DEFAULT_CONFIG`), 140-142 (`def wireguard_listen_port(self) -> int:` reads `self.config.get("wireguard_listen_port", DEFAULT_CONFIG["wireguard_listen_port"])` with a positive-int guard). |
| Source of truth (panel side, used by route emit) | `leikwan-br1/master/src/Controllers/SidecarAgentController.php` line 170 (`$sidecarWireguardListenPort = (int) ($runtimeState['wireguard_listen_port'] ?? 0);`), with a fallback to `$mptcpListenPort` on lines 171-173. |
| Trigger for the cfwg shadow route | Lines 210-222 of `SidecarAgentController.php`: `cf_wg_route_publishes_wg_listen_port` sentinel; the route is only appended if `selfLegCfTransport === 'cf_wg' && backendModeRaw === 'cf_mode' && sidecarWireguardListenPort > 0 && pathPrefix !== ''`. |
| In-repo value | The shadow route target is hard-rendered as `'127.0.0.1:' . $sidecarWireguardListenPort` at line 219. |
| Live runtime value | Handoff: `wstunnel client -L udp://127.0.0.1:40047:127.0.0.1:14969` (i.e. CF target host 127.0.0.1, port 14969). The sidecar publishes `target = 127.0.0.1:14969`. |
| Divergence vs. live | None observable. The four-condition gate is fragile: any one missing condition silently drops the cfwg shadow route. The existing source-grep regression test confirms the snippet exists in the source but never runs the controller and inspects the rendered route, so a typo or refactor that breaks the gate logic could pass the test. Flagged as a coverage gap below. |

### E. Client local UDP wstunnel target `127.0.0.1:14969`

| Aspect | Finding |
| --- | --- |
| Source of truth (client render) | `leikwan-br1/client-agent/install_forward_runtime.py` |
| Lines | 1015-1029 inside `render_forward_runtime()` cf_wg branch: reads `local_udp = leg["peer_udp_port"]`, `cf_target_host = leg["cf_target_host"]`, `cf_target_port = leg["cf_target_port"]` and emits the `wstunnel client -L udp://127.0.0.1:{local_udp}:{cf_target_host}:{cf_target_port} ... wss://{cf_host}:{cf_port} &` line, then `append_wait_for_local_listener(lines, cf_wg_ports)` on line 1031 which translates to a `ss -lun "sport = :$port"` poll. |
| Local UDP port derivation | `self_leg_wg_underlay()` line 402: `endpoint_port = 40000 + (forward_id % 2000)` when `outer_transport == "wstunnel_udp" && forward_id > 0`. For forward 47 this is `40047`. |
| `cf_target_host` derivation | `self_leg_wg_underlay()` line 390: `cf_wg_target_host = str(wg.get("cf_target_host", "") or "127.0.0.1").strip()`. The panel does not currently set `cf_target_host` in the `self_leg_wg` dict (see `ProvisioningService.php` lines 3000-3014), so the client defaults to `127.0.0.1`. |
| `cf_target_port` derivation | `self_leg_wg_underlay()` line 391: `cf_wg_target_port = endpoint_port` BEFORE line 402 overrides `endpoint_port` to `40047`. This means `cf_target_port` is captured from the panel's original `endpoint_port` (which `buildSelfLegWireGuardProfile` line 3098 sets to `$sidecarListenPort`, i.e. `14969`). The capture-before-override is load-bearing. |
| Live runtime value | `wstunnel client -L udp://127.0.0.1:40047:127.0.0.1:14969?timeout_sec=0`. |
| Divergence vs. live | None observable on the in-tree code. Risk: if a future refactor moves the `endpoint_port` override before `cf_target_port` is captured, `cf_target_port` would silently become `40047` and the wstunnel client would dial the local listener instead of the sidecar WG port. There is no in-repo test asserting the rendered start-script line for the cf_wg branch byte-for-byte. The closest test, `client-agent/tests/test_self_leg_cf_wg_runtime.py`, asserts on `applied.json`-style underlay payload contents but does not diff the rendered `start_script`. Flagged as a coverage gap. |

### F. Selected CF dial IP and SNI host

| Aspect | Finding |
| --- | --- |
| Source of truth (panel) | `leikwan-br1/master/src/Support/ProvisioningService.php` |
| Lines | 3001-3005 (`$wgProfile['cf_dial_host'] = $cfTarget['dial_host']`, `$wgProfile['cf_dial_port'] = $cfTarget['dial_port'] ... ?: 443`, `$wgProfile['cf_sni_host'] = $cfTarget['cf_sni_host'] ?? ...`) and `selfLegCfTarget()` (called at line 2983) which produces `dial_host`, `dial_port`, `dial_connect_ip`, `cf_sni_host`, `self_leg_cf_candidates`. |
| Client override | `install_forward_runtime.py` `self_leg_wg_underlay()` lines 393-400: when `outer_transport=='wstunnel_udp'` and `forward_id>0`, the client takes the first entry from `self_leg_cf_candidates` as `cf_dial_host`, then overrides again with `dial_connect_ip` if non-empty. The candidate order is panel-controlled. |
| Live runtime value | Handoff: `wss://162.159.39.5:2053` and `--tls-sni-override nrt1.nbwly.com`. (CF Anycast IP and a Cloudflare-fronted SNI host.) |
| Divergence vs. live | None observable on the in-tree code path. Risk: the client uses the FIRST element of `self_leg_cf_candidates`, which means the panel decides connection ordering. If the panel reorders the list (e.g. SQL ordering changed after a migration), the client may dial a different CF edge IP that has different last-mile characteristics or, worse, that does not have the cfwg path provisioned on its CF zone. There is no in-repo test that pins which IP the client picks. |

### G. No-op apply / signature behavior after render

| Aspect | Finding |
| --- | --- |
| Source of truth (client) | `leikwan-br1/client-agent/client_agent.py` |
| Mechanism | The agent maintains `/var/lib/leikwan-client-agent/applied.json`; each `apply_forward_runtime()` call computes a signature over the rendered runtime and skips re-render if the signature already matches. The handoff Action 2 (line "applied-state signatures were cleared/neutralized for the restored runtime") confirms this gate is real and was the reason `applied.json` had to be cleared on sh36 to force a re-render after the runtime swap. |
| Live runtime evidence | The post-fix `wstunnel client` invocation matches the pre-fix one byte-for-byte; the visible difference is the upstream agent code, not the rendered command. The signature gate therefore correctly suppresses no-op re-applies once the renderer is fixed. |
| Divergence vs. live | The exact signature key set is not asserted by any in-repo test. If a future change to the client agent computes the signature over a subset of fields that does not include `cf_path_prefix` or `cf_dial_host`, a panel-side change to either field could be silently ignored by the agent. Flagged as risk **R-04**. |

### H. Auto-update request token behavior

| Aspect | Finding |
| --- | --- |
| Source of truth (client) | `leikwan-br1/client-agent/client_agent.py` |
| Lines | 859 (`def auto_update_request_token(self, client_meta: dict) -> str:`), 860 (`if str(self.config.get("disable_auto_update", "") or "").lower() in {"1", "true", "yes", "on"}: return ""`), 902 (`return self.auto_update_request_token(client_meta) != ""`). |
| Source of truth (sidecar) | `leikwan-br1/sidecar-agent/sidecar_agent.py` lines 894 and 1453 (`if bool(self.config.get("disable_auto_update", False)):` short-circuits the auto-update path). |
| Source of truth (panel echo) | `leikwan-br1/master/src/Controllers/SidecarAgentController.php` line 245 (`'disable_auto_update' => $runtimeState['disable_auto_update'] ?? null,`) inside the `config_patch` returned to the sidecar in heartbeat. |
| Current state | Panel-side `disable_auto_update` is set on client `id=3` (handoff Action 3) and sidecar point `id=5`. Until that flag is cleared, both sides return early and never request a new artifact, even if `desired_agent_sha256` changes. |
| Divergence vs. live | None on the gate itself. The risk this creates is operational: the gate masks the underlying contract drift problem; clearing the gate before the contract is fixed would re-introduce the failure the handoff describes. Tracked in `04-rollout-unpin-runbook.md`. |

## artifacts/versions.json hash drift

`leikwan-br1/artifacts/versions.json` declares the canonical sha256 of every distributed artifact. On this branch (`codex/cfwg47-assessment-handoff-20260520`), the declared values do not match the in-tree files, and neither matches the live restored hashes from the handoff. Three lineages are in flight.

### `client_agent.py`

| Source | sha256 | size_bytes |
| --- | --- | --- |
| Declared in `artifacts/versions.json` (lines 45-50) | `75354f40397f64d78e334e256615547d7f44203fe920323fba90610753ccec5f` | `191482` |
| Actual repo file (`client-agent/client_agent.py` on this branch) | `ebae5089c2a33683421607428ec9e4f3055465cb61226ae663781d45173da5ce` | `187546` |
| Live restored on sh36 (handoff "Live hashes after restoration") | `3abb7ecd4c572d3bcc18915b3ba0bdb8cf598ffcf3baf36b10288dc27c9cbf64` | not recorded |

### `install_forward_runtime.py`

| Source | sha256 | size_bytes |
| --- | --- | --- |
| Declared in `artifacts/versions.json` (lines 144-149) | `f88c4c2f763a0d58ba49a4ea4f1a874eecac51cf3a54a4bc84c63bb22f5f9c24` | `56592` |
| Actual repo file (`client-agent/install_forward_runtime.py` on this branch) | `9341ed7713fb8d76db85bfc75b0a21b77e97af81035139c49b38dfb34817d018` | `55460` |
| Live restored on sh36 (handoff "Live hashes after restoration") | `2e4c0c883e12901a56c85c9cca38941a4492703837d6b66a8f309ab2a9715548` | not recorded |

### Why this matters

`leikwan-br1/client-agent/tests/test_artifact_versions_consistency.py` already implements the manifest-vs-tree check for `client_agent.py` (line 27-28: `digest = hashlib.sha256(agent_path.read_bytes()).hexdigest(); self.assertEqual(digest, client_agent["sha256"])`). On this working tree, that test would FAIL: `ebae5089...` does not equal the declared `75354f40...`. This indicates one of:

1. The manifest was updated for a panel version that was never merged to this branch, and the in-tree files lag the manifest (panel ahead of repo).
2. The in-tree files were edited after the manifest was published (manifest stale, repo ahead).
3. There is no automated regenerator for `artifacts/versions.json`; the values are hand-maintained or written by an out-of-band script not present in the repo.

A fourth piece of evidence: the live restored hashes (`3abb7ecd...`, `2e4c0c88...`) match neither (1) nor (2). The live working file is a third lineage entirely, which is consistent with the handoff narrative that the working build is from "around 2026-05-19 00:59" and predates whatever the panel currently serves.

The `02-priority-mapping.md` doc treats reconciling these three lineages as live-host work (we cannot byte-compare without the live files); the in-repo work is to extend the manifest consistency check (see `03-regression-fixture-plan.md`) so that future panel pushes cannot land with this kind of declared/actual drift in the first place.

## Existing test coverage gaps

Two PHP regression tests exist for the CFWG wiring:

- `leikwan-br1/master/tests/sidecar_cfwg_route_publishes_wg_listen_port_regression_test.php`
- `leikwan-br1/master/tests/provisioning_self_leg_cf_wg_transport_regression_test.php`

Both are **source-grep tests**: they `file_get_contents()` the controller / service PHP source and assert that specific code snippets appear verbatim. Confirmed by reading the tests (the sidecar one greps for substrings such as `"'cf-forward-' . \$forwardId . '-cfwg'"`, `"'127.0.0.1:' . \$sidecarWireguardListenPort"`, `"'transport_mode' => 'udp'"`; the provisioning one greps for `"\$wgProfile['cf_path_prefix'] = \$cfBasePathPrefix . '.cfwg';"` etc.).

This is a coverage gap. Source-grep tests:

1. Pass even when the surrounding control flow is broken. For example, the four-condition gate at `SidecarAgentController.php` line 213 (`selfLegCfTransport === 'cf_wg' && backendModeRaw === 'cf_mode' && sidecarWireguardListenPort > 0 && pathPrefix !== ''`) could be inverted, dead-coded, or wrapped in an outer `if (false)` and the source-grep test would still pass because the snippet text still appears.
2. Pass even when the field shapes are wrong. They never JSON-decode the emitted heartbeat response or the rendered candidate dict; they cannot catch a regression where `path_prefix` gains a leading slash or where `target` becomes `127.0.0.1:0` due to a misread `runtimeState`.
3. Pass even when an upstream caller stops invoking the sentinel block. They never instantiate the controller or service.

The `03-regression-fixture-plan.md` doc proposes execution-based replacements for both tests.

In addition, `leikwan-br1/client-agent/tests/test_self_leg_cf_wg_runtime.py` runs `install_runtime.render_forward_runtime(forward, {})` (line 381 etc.) and asserts on the returned underlay/applied-state payload. It does NOT diff the rendered `start_script` text byte-for-byte. So a regression in the wstunnel client emission line at `install_forward_runtime.py` line 1027 (e.g. swapped `cf_target_host`/`cf_target_port` order, dropped `?timeout_sec=0`, dropped `--http-headers`) would not be caught.

## Files in scope

| File | Role | Contract semantic owned |
| --- | --- | --- |
| `leikwan-br1/master/src/Support/ProvisioningService.php` | panel build (CFWG candidate) | A self_leg_cf_transport, B cf_path_prefix, C sidecar_public_key, D endpoint_port input, F cf_dial_host/cf_sni_host, interface_name `lw-cfwg-<id>` |
| `leikwan-br1/master/src/Controllers/SidecarAgentController.php` | panel route emit (sidecar heartbeat) | D shadow route target `127.0.0.1:14969`, B cfwg path suffix on shadow_route_key, H disable_auto_update echo to sidecar |
| `leikwan-br1/master/src/Controllers/ClientAgentController.php` | panel route emit (client heartbeat) | H disable_auto_update echo to client (cross-reference; not directly cited above but is the symmetric flag carrier) |
| `leikwan-br1/client-agent/install_forward_runtime.py` | client render | E local UDP port `40047`, E `cf_target_host`/`cf_target_port` capture, F first-candidate selection, B passthrough of `cf_path_prefix`, start_script wstunnel client emission |
| `leikwan-br1/client-agent/client_agent.py` | client gate | G applied.json signature, H auto_update_request_token gate |
| `leikwan-br1/client-agent/leikwan_mptcp_client.py` | WG conf renderer | C `[Peer] PublicKey`, D `[Peer] Endpoint = 127.0.0.1:40047` line, interface name |
| `leikwan-br1/sidecar-agent/sidecar_agent.py` | sidecar render | D `wireguard_listen_port=14969`, render_cf_backend_unit (per-route systemd unit), cf-routes-<listen>.json |
| `leikwan-br1/artifacts/versions.json` | declared artifact manifest | hash drift evidence (lines 45-50, 144-149) |
| `leikwan-br1/client-agent/tests/test_self_leg_cf_wg_runtime.py` | existing test, payload-level | runs render_forward_runtime, asserts underlay payload only (not start_script bytes) |
| `leikwan-br1/client-agent/tests/test_artifact_versions_consistency.py` | existing test, manifest | currently covers `client_agent` only; would FAIL today |
| `leikwan-br1/sidecar-agent/tests/test_sidecar_cf_mode_runtime.py` | existing test, sidecar render | pattern reference for new fixture-based test |
| `leikwan-br1/sidecar-agent/tests/test_sidecar_self_leg_wg.py` | existing test, sidecar wg | pattern reference |
| `leikwan-br1/master/tests/sidecar_cfwg_route_publishes_wg_listen_port_regression_test.php` | existing test, SOURCE-GREP | coverage gap (does not exercise controller) |
| `leikwan-br1/master/tests/provisioning_self_leg_cf_wg_transport_regression_test.php` | existing test, SOURCE-GREP | coverage gap (does not instantiate service) |
| `leikwan-br1/master/tests/forward_combination_self_leg_cf_transport_persistence_regression_test.php` | existing test, persistence | covers DB round-trip of the flag, not render |
| `leikwan-br1/master/tests/admin_forward_combination_self_leg_policy_test.php` | existing test, admin UI | covers admin-side toggle visibility |

## Hand-off pointer

The next document to read is `02-priority-mapping.md`, which maps each of the five handoff priorities to in-repo work items (actionable in this sandbox) and live-host work items (out of scope here). After that, `03-regression-fixture-plan.md` proposes the regression harness that converts the divergences identified above into automated render-diff tests.
