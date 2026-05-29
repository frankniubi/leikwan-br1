# Slave-managed MPTCP forward WG implementation plan

## Goal

Make MPTCP forward-combination delivery a desired-state pipeline:

`panel forward/combination enabled → selected slave receives publish task → slave creates/keeps WG underlay → optional CF wstunnel UDP preset → sidecar WG peer → preconnect relay stays warm → client only fast-connects to slave`.

The key change is: slave should establish WG for new/changed forwards, but identical republishes must not tear down or refresh WG.

## Implementation plan

### 1. Panel: make slave MPTCP underlay desired-state explicit

Relevant code:
- `master/src/Support/ProvisioningService.php` `saveForwardCombination()`
- `master/src/Support/ProvisioningService.php` already republishes active forwards after combination save
- `master/src/Support/ProvisioningService.php` `reconcileForwardEndpoints()`
- `master/src/Support/ProvisioningService.php` `publishTaskPayloadForForward()`
- `master/src/Support/ProvisioningService.php` `buildMptcpSlaveUnderlayPayload()`

Plan:
1. Treat `mptcp_underlay` as the authoritative slave-side desired state for MPTCP-capable combinations.
2. Ensure payload generation covers both:
   - `client_mode = mptcp`
   - `runtime_preference = mptcp_preferred_bond_fallback` if the selected runtime can use MPTCP via slave.
3. Add a stable underlay fingerprint/generation to payload, derived from:
   - forward id / endpoint id / node id
   - WG interface name
   - client/slave/sidecar WG public keys
   - slave listen port
   - sidecar endpoint host/port
   - CF path/dial settings if CF mode
   - preconnect relay ports/tokens
4. Add underlay health policy into payload, e.g.:
   - `slave_wg_latency_policy.enabled`
   - `ideal_latency_ms`
   - `probe_interval_seconds`
   - `window_samples`
   - `cooldown_seconds`
   - `restart_actions: ["wstunnel", "wireguard"]`

### 2. Panel admin setting for ideal slave WG latency

Existing `forward_combinations.desired_latency_ms` is already used for donation and helper logic, but that is not exactly the same as slave WG inner RTT.

Plan:
1. Add nullable combination-level field:
   - `slave_wg_ideal_latency_ms`
2. Admin UI:
   - Add field near existing `desired_latency_ms` in `AdminController.php`.
   - Label it as “Ideal slave WG inner RTT”.
3. Fallback rules:
   - if `slave_wg_ideal_latency_ms > 0`, use it
   - else if `desired_latency_ms > 0`, use it as fallback
   - else disable latency restart policy for that forward
4. Include this field in:
   - `saveForwardCombination()`
   - `listForwardCombinations()`
   - `buildClientConfig()`
   - `buildMptcpSlaveUnderlayPayload()`

### 3. Panel: preserve fixed-port / keepalive invariants

Relevant code:
- `master/src/Support/ProvisioningService.php` `stableSidecarLegPort()`
- `master/src/Support/ProvisioningService.php` `mptcpSidecarManagementForPoint()`
- `master/src/Support/ProvisioningService.php` `buildMptcpSlaveUnderlayPayload()`

Plan:
1. Keep sidecar per-leg WG listen ports stable with `stableSidecarLegPort()`.
2. Preserve sidecar-side `peer_keepalive_seconds = 0` in `mptcpSidecarManagementForPoint()` to avoid CNIX endpoint roaming.
3. Keep slave-side keepalive enabled, currently from `MPTCP_PEER_KEEPALIVE_SECONDS`.
4. Keep slave public/preconnect ports inside `7000-9999`, especially for Canton.

### 4. Slave: make `apply_mptcp_underlay()` idempotent

Relevant code:
- `slave-agent/slave_agent.py` `publish_task()`
- `slave-agent/slave_agent.py` `apply_mptcp_underlay()`
- `slave-agent/slave_agent.py` `ensure_clean_mptcp_underlay()`

Current issue:
- `apply_mptcp_underlay()` calls `ensure_clean_mptcp_underlay()` unconditionally, and that stops/deletes the WG interface. This means republishing the same forward can refresh WG even when nothing changed.

Plan:
1. Compute desired config fingerprint before applying.
2. Store last applied fingerprint in service metadata.
3. If fingerprint unchanged:
   - do not call `wg-quick down`
   - do not delete the interface
   - do not restart `wg-quick`
   - only ensure the service is enabled/started if inactive
4. Only call cleanup/reload when:
   - rendered config changed
   - live peers differ from expected public keys
   - listen port/interface changed
   - CF backend endpoint changed
   - watchdog explicitly requests a path reset
5. Keep the existing stale-peer safety behavior, but make it conditional rather than every publish.

### 5. Slave: CF mode creates a persistent wstunnel UDP preset

Relevant code:
- `slave-agent/slave_agent.py` `ensure_mptcp_underlay_cf_backend()`
- `slave-agent/slave_agent.py` `render_cf_wss_backend_unit()`

Plan:
1. For CF-mode MPTCP underlay, keep using a dedicated service:
   - `leikwan-mptcp-cf-{interface}`
2. Persist selected CF IP/path in metadata so ordinary republishes reuse it.
3. On unchanged payload, do not restart wstunnel.
4. On watchdog-triggered path change:
   - invalidate selected CF IP
   - re-run CF candidate selection
   - restart only the wstunnel client first
   - restart WG only if wstunnel restart/reselection does not recover RTT/handshake
5. Fix/verify wstunnel TLS behavior:
   - use `--tls-sni-disable`
   - keep HTTP `Host` header
   - avoid `--tls-sni-override br1.nbwly.com`

### 6. Slave: add WG inner-latency watchdog

Use `client-agent/cfwg_latency_watchdog.py` as the design reference, but implement slave-side scanning in `slave-agent/slave_agent.py`.

Plan:
1. Add periodic loop in slave agent that scans active service metadata with enabled `mptcp_underlay`.
2. Probe:
   - `ping -I <interface> <sidecar_address>`
   - `wg show <interface> latest-handshakes`
   - optional preconnect relay health endpoint
3. Maintain per-interface state:
   - recent samples
   - p95 RTT
   - loss ratio
   - best observed RTT
   - last restart time
   - last selected CF IP
4. If RTT/loss exceeds policy for enough windows:
   - CF mode: restart/reselect `leikwan-mptcp-cf-{iface}` first
   - direct UDP mode: `wg-quick down <iface>` + `wg-quick up <iface>`
   - if CF restart fails to recover after cooldown/escalation, restart WG
5. Use cooldown to avoid flap loops.
6. Include watchdog status in slave heartbeat:
   - forward id
   - endpoint id
   - interface
   - current RTT/p95/loss
   - selected CF IP
   - last remediation action

### 7. Panel: ingest slave underlay health

Relevant code:
- `master/src/Controllers/NodeController.php` heartbeat
- `master/src/Controllers/NodeController.php` stores node heartbeat payload
- `master/src/Support/ProvisioningService.php` `pickFastestSlavesForCombination()`

Plan:
1. Add `recordMptcpUnderlayHealth($nodeId, $payload)` in `ProvisioningService`.
2. From node heartbeat, parse `mptcp_underlay_health`.
3. Update matching `service_endpoints` latency fields, e.g.:
   - `last_chain_latency_ms`
   - `best_latency_ms`
   - `last_error`
   - `health_status = degraded` if persistently bad
4. Let existing `pickFastestSlavesForCombination()` use updated latency when future republish/reselection happens.
5. Do not make panel perform fast path switching; slave local watchdog should react first.

### 8. Sidecar management consistency

Relevant code:
- `master/src/Support/ProvisioningService.php` `mptcpSidecarManagementForPoint()`

Plan:
1. Ensure sidecar management always includes the matching slave peer for active MPTCP forwards.
2. Keep sidecar WG listen port stable per leg.
3. Keep sidecar `peer_keepalive_seconds = 0`.
4. Accept race condition on new forward:
   - sidecar may apply peer slightly after slave
   - slave keepalive/preconnect retry loop should converge without client involvement

### 9. Tests to add/update

Panel tests:
1. New/updated forward combination republishes active forwards and queues slave publish tasks.
2. MPTCP publish payload includes `mptcp_underlay`, preconnect fields, and latency policy.
3. `mptcp_preferred_bond_fallback` payload includes MPTCP slave underlay when applicable.
4. CF-mode payload includes:
   - `outer_transport = wstunnel_udp`
   - `cf_*` fields
   - stable sidecar WG port
5. Sidecar management regression:
   - fixed per-leg listen port
   - sidecar keepalive remains `0`

Slave tests:
1. Reapplying identical `mptcp_underlay` does not call:
   - `wg-quick down`
   - `ip link delete`
   - `systemctl restart wg-quick@...`
2. Changed key/port/path does restart WG.
3. CF backend unchanged payload does not restart wstunnel.
4. Watchdog high RTT restarts/reselects wstunnel first in CF mode.
5. Watchdog high RTT restarts WG in direct mode.
6. Cooldown prevents repeated restart loops.
7. Rendered wstunnel CF client uses SNI disable behavior and keeps Host header.

Client regression:
1. MPTCP client config uses `slave_relays` / preconnect relay path.
2. Client does not directly dial sidecar MPTCP when slave-preconnect is enabled.

### 10. Rollout order

1. Deploy slave-agent changes first; unknown future payload fields should be ignored safely.
2. Deploy sidecar-agent compatibility if needed.
3. Deploy panel schema/payload changes.
4. Enable policy on one test forward/combination.
5. Republish that combination.
6. Verify:
   - slave WG interface created once
   - repeated publish does not reset handshake
   - preconnect relay pool is warm
   - CF mode wstunnel service exists only when needed
   - RTT watchdog only acts after threshold/cooldown
7. Roll out to all MPTCP forward combinations.

## Web traffic investigation and optimization plan

### Scope and evidence standard

This section is based only on repository evidence from the current code. It does not assume CDN logs, nginx access logs, Cloudflare analytics, or production request counts that are not present in the repo.

### Code-backed findings

1. The web frontend itself does not contain a polling loop that can explain 200+ GB/day.
   - Repository search found no `setInterval()`, `EventSource`, WebSocket, or repeated browser `fetch()` loop in the PHP-rendered web UI.
   - The only frontend `fetch()` found is the i18n dictionary load in `master/public/i18n/patch.js`: it fetches `/i18n/{locale}.json` only for non-English locales.
   - `patch.js` is 3,442 bytes and `zh-Hans.json` / `zh-TW.json` are about 89,914 bytes each in this checkout. That is measurable, but too small to explain 200+ GB/day unless page views are extremely high.

2. The PHP response layer emits no cache validators or cache policy by default.
   - `master/src/Http/Response.php` sets only `Content-Type` for `json()`, `html()`, and `text()` responses.
   - There is no `ETag`, `Last-Modified`, `Cache-Control`, or conditional `304 Not Modified` support in `Response`.
   - `Response::json()` uses `JSON_PRETTY_PRINT`, so agent/config JSON is larger than compact JSON, but the payloads are still small compared with binaries.

3. Artifact routes serve large files through PHP and do not set cache validators.
   - `deploy/nginx/master.conf` has `root /var/www/master/public` and `try_files $uri /index.php?$query_string`; artifacts are not under `master/public`, so `/artifacts/*` routes go through PHP.
   - `master/src/Controllers/ArtifactController.php` serves most repo artifacts with `serveRepoFile()`, which returns `Response::text()` after `file_get_contents()` and adds no `Content-Length`, `ETag`, `Last-Modified`, `Cache-Control`, or `Accept-Ranges`.
   - `wstunnelPackage()` does set `Content-Length`, but still uses `file_get_contents()` and does not add cache validators or range support.

4. The artifacts are large enough to explain hundreds of GB/day if downloaded repeatedly.
   - Measured file sizes in this checkout:
     - `artifacts/wstunnel/wstunnel_linux_amd64`: 12,326,416 bytes
     - `artifacts/wstunnel/leikwan-bond_linux_amd64`: 8,853,776 bytes
     - `artifacts/wstunnel/leikwan-ingress_linux_amd64`: 3,079,928 bytes
     - `artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64`: 3,499,080 bytes
     - `artifacts/windows/leikwan-bridge-setup.exe`: 8,335,360 bytes
   - Measured installer-triggered download totals from code-referenced files:
     - `install/install-client-agent.sh` downloads about 15.12 MiB per full client-agent install/update.
     - `install/install-slave.sh` downloads about 14.94 MiB per full slave install/update.
     - `install/install-bond-sidecar.sh` downloads about 20.37 MiB per full sidecar install/update.
   - Therefore, roughly 14,000 client-agent full updates/day or about 10,000 sidecar full updates/day would be enough to reach 200+ GB/day from artifacts alone. This is a scale calculation from measured bytes, not proof of current production counts.

5. Update paths can repeatedly pull the full installer bundle when a desired hash/version mismatch persists.
   - `ClientAgentController::pullConfigs()` returns desired hashes/versions for client agent, installer, bond binary, MPTCP runtime, CFWG watchdog, and UoT runtime.
   - `client-agent/client_agent.py` compares local files to those desired hashes. A mismatch produces an `auto-release:...` token and schedules `leikwan-client-agent-update`.
   - `install/client-agent-update.sh` downloads `install-client-agent.sh`, then that installer unconditionally downloads all client runtime files and large Linux binaries referenced above.
   - Client-agent retry constants are `UPDATE_RETRY_SECONDS = 300`, `UPDATE_STALE_SECONDS = 900`, and `UPDATE_ERROR_RETRY_SECONDS = 1800`. A persistent mismatch or failing post-update state can therefore retry periodically.
   - Slave and sidecar agents have the same pattern: panel sends desired version/hash, local agent schedules updater, updater downloads installer, installer unconditionally downloads runtime files and binaries.

6. There is a code-level risk of desired metadata not matching actual served artifact content.
   - `BaseController::artifactMetadata()` recomputes `sha256` and `size_bytes` from the file at `path` when the file exists.
   - `ArtifactController::manifestFor()` also recomputes `sha256` and `size_bytes` from the file at `path`.
   - But the actual artifact route still serves whatever `serveRepoFile()` or `wstunnelPackage()` maps for that URL. Any mismatch between `artifacts/versions.json` `path`, route method path, and installed destination path can make agents repeatedly believe a component is stale.
   - This needs log validation before calling it the production root cause, but it is a real code path to audit.

7. App downloads are also panel-served artifacts.
   - Tenant client setup renders `Download Windows App` pointing at `/artifacts/leikwan-bridge-setup.exe` via `BaseController::windowsClientAppUrl()` and `TenantController` client setup.
   - The Windows installer is about 7.95 MiB in this checkout.
   - Repeated human downloads or automated retries can add traffic, but the code evidence points to agent binary update loops as the more plausible high-volume source because they can run unattended across many nodes.

### Optimization plan grounded in the code

1. Add artifact request observability before changing behavior.
   - Add structured access logging for `/artifacts/*` responses in `ArtifactController`: route name, file path, bytes served, user agent, remote IP, status, and whether the request was full body vs `304` / range.
   - Add counters by artifact key in the panel DB or append-only log so daily totals can identify whether traffic is from `wstunnel_linux_amd64`, `leikwan-bond_linux_amd64`, installers, app downloads, or i18n/static files.
   - This is required because repo code shows likely sources, but only request logs can prove which route is consuming production bandwidth.

2. Make artifacts cacheable and conditional.
   - Replace `serveRepoFile()` with a binary-safe `serveArtifactFile()` for artifacts that sets:
     - `Content-Length`
     - `ETag` from SHA-256 or `mtime-size`
     - `Last-Modified`
     - `Cache-Control: public, max-age=31536000, immutable` for versioned/hash-stable artifact URLs
     - `Cache-Control: no-cache` with validators for stable mutable URLs like `/artifacts/client-agent.py`
   - Honor `If-None-Match` / `If-Modified-Since` and return `304` without body.
   - Add `Accept-Ranges: bytes` and range handling for `.exe` and large binaries so interrupted downloads do not restart from byte zero.

3. Move large artifacts out of PHP routing where possible.
   - Update `deploy/nginx/master.conf` with an explicit `/artifacts/` location using `alias` to the repo artifact directory or a release artifact directory.
   - Enable nginx `sendfile`, cache headers, and range serving for large binaries.
   - Keep PHP artifact routes only for wrappers that need dynamic query-specific content, such as forward-specific PowerShell wrappers.

4. Stop full reinstall downloads when only one component is stale.
   - Current installers download all runtime files unconditionally.
   - Add component-scoped updater logic:
     - if only `desired_mptcp_runtime_sha256` mismatches, download only `/artifacts/leikwan-mptcp-client.py`
     - if only `desired_bond_sha256` mismatches, download only `/artifacts/wstunnel/leikwan-bond_linux_amd64`
     - if only watchdog/UoT mismatches, download only that script
   - Keep the full installer path for major agent/installer version changes, but avoid it for single-file drift.

5. Add local download short-circuiting to installers.
   - Before `download_atomic`, compare existing destination SHA-256 with desired SHA-256 from a manifest or installer arguments.
   - Skip downloading files that already match.
   - This directly reduces repeated reinstall traffic because current scripts always fetch every file they list.

6. Make update success/failure state more diagnostic.
   - Agents already store update state and throttle retries.
   - Extend update state with:
     - component mismatch list
     - expected SHA
     - actual SHA after installer completed
     - URLs downloaded
     - total bytes downloaded if known
   - Report this in heartbeat so the panel can show nodes stuck in a download/update loop.

7. Add a panel-side guard against mass auto-update storms.
   - When `autoUpdateTokenForVersion()` / desired-hash logic would trigger updates for many clients/slaves/sidecars, add rollout controls:
     - percentage or node-count cap per hour
     - per-role disable switch
     - per-artifact emergency hold if repeated failures are reported
   - This is especially important because one full sidecar update is about 20.37 MiB and one full client/slave update is about 15 MiB.

8. Compact and cache small web assets too, but treat this as secondary.
   - Remove `JSON_PRETTY_PRINT` from agent JSON responses if human-readable API output is not required.
   - Add cache headers for `/i18n/patch.js` and `/i18n/*.json`.
   - Consider moving large inline CSS from `BaseController` into static files with cache headers. This reduces normal page weight, but it is not the primary code-backed explanation for 200+ GB/day.

9. Validation steps after implementation.
   - Compare daily bytes by route before/after.
   - Confirm `/artifacts/wstunnel/wstunnel_linux_amd64` and other large binaries return `304` on validator hits.
   - Confirm interrupted `.exe` / binary downloads resume via `206 Partial Content`.
   - Confirm client/slave/sidecar updates download only mismatched components.
   - Confirm no node repeatedly reports the same desired-vs-actual SHA mismatch after a successful update.
