# Device limit + panel egress release (2026-09-27)

## Versions

| Component | Before | Now |
|---|---|---|
| Panel PHP | — | 7 files, commit 8989d396 |
| Windows app / headless | 0.1.20.58 | **0.1.20.59** |
| Android | 0.2.34 (36) | **0.2.35 (37)** |
| Linux agent | 2026.09.24.3 | **2026.09.27.1** |
| Linux installer, tcp-accel installer, arm64 offline installer | 2026.09.24.3 | **2026.09.27.2** |
| macOS | 1.0.51 | 1.0.51 (not built: build Mac offline, see below) |
| Ultra runtime | 2026.09.25.1 | unchanged |
| OpenWrt | — | not released (owner's decision) |

## What shipped

- **Online device limit enforced** (c365141c): a device that is not already online is refused with 429
  `client_online_limit_reached` once the client's limit is full; online devices are never kicked.
  `legacy-*` ids (user-agent|ip fallback) are exempt. Every client shows a clear, persistent message and
  retries every 60 s; nothing logs out.
- **Geo lock kept closed** (42be1dbd) until `ACCESS_POLICY_LOCATION_CLIENTS_READY` is set, because no client
  implements the location check. Plan: `docs/superpowers/plans/2026-09-27-client-location-check.md`.
- **Panel egress hardening**:
  - Windows/macOS sign-in "Ethernet Binding" selector and a stricter TUN/TAP filter (aa5da056).
  - Android panel traffic ignores proxies and is bound to a non-VPN network (39d5fcc8).
  - The Linux agent has a persisted device id, ignores proxies, binds its sockets with SO_BINDTODEVICE, and the installer `--proxy` feature is removed (6f2245bc).
- **Installer fix** (5f5f6add, release -2): tcp-accel downloads are pinned to the strict-DoH panel address. See the incident below.

## Publication

1. **Panel PHP**, 10:20:36 UTC. Deployed by `deploy-php.sh`:
   - Files: `index.php`, `ClientAgentController`, `TenantController`, `AdminController`, `ApiException`, `ClientOnlineSessionService`, `AccessPolicyStore`.
   - Backup and `rollback.sh`: `/root/codex-backups/panel-device-limit-20260927/`.
   - Six files matched the pre-change git versions exactly. The seventh, `index.php`, carried a production-only detailed exception log line; it was adopted into git first (8989d396).
   - Checks afterwards: php-fpm active, `/api/health` ok, login page 200, no PHP fatal, no 429 or 5xx. The only 5xx were 4 × 502 at the reload second, from long-polls cut off by the reload.
2. **Artifacts `device-limit-egress-20260927-1`**, 10:21:54 UTC. `deploy-client-artifacts.py`, status `committed`.
   - Backup: `/root/codex-backups/device-limit-egress-20260927-1/`.
   - All 14 entries downloaded through the public domain match their manifest hashes (no static shadow copies).
3. **Artifacts `device-limit-egress-20260927-2`**. Installer fix, 3 entries, status `committed`, public hashes verified.

## Incident: Linux self-update rolled back on an IPv4-only host

- **Symptom:** host `172.21.255.189` (public `49.64.162.145`, client 3) tried to update to 2026.09.27.1. The install failed with curl exit 7 and was rolled back cleanly. The old agent kept serving throughout.
- **Cause:** with `proxy.env` removed, `install-tcp-accel.sh` used plain curl through the system resolver.
  - On this host the system resolver returns only Cloudflare IPv6 (AAAA) records, and the host has no IPv6 route.
  - The proxy used to carry that request.
- **Fix:** the installer resolves the panel with its strict DoH lookup and passes `LEIKWAN_CURL_RESOLVE` (`curl --resolve`).
  - Verified on the host: plain curl `000`, pinned curl `200`.
  - Shipped as installer 2026.09.27.2.
- Client 25 (`153.3.3.144`) showed the same pattern.

## Fleet convergence (10:44 UTC)

- **Linux agents:** all three are on 2026.09.27.1 and report stable `linux-<uuid>` device ids. `114.80.33.50` updated right away. `49.64.162.145` and `153.3.3.144` updated on their first retry after release -2 (the log shows `proxy.env removed`). Their old `legacy-*` sessions age out of the 180 s window.
- **Error rates:** no 429 and no 5xx since the deploy, apart from the reload second.
- **Desktop:** the Windows and macOS GUIs still send the stale `AgentVersion` 2026.04.09.1 in their user agent, so the panel cannot show whether desktop builds have updated.

## Not done / follow-ups

- **macOS 1.0.52:** the build Mac `leikwan@100.103.253.21` has been offline for about a day (Tailscale last seen).
  - A prepared source tarball and a build script (helpers reused from 1.0.51, no runtime rebuild) are in `tmp/candidate-20260927-1/macos/`.
  - When the Mac is back:
    1. Re-bump the macOS version (held at 1.0.51 in d7fea743).
    2. Build.
    3. Publish the four `macos_client_*` keys together with `macos_headless_installer`.
  - Until then, macOS users over the device limit see the old generic connection error.
- **Live drift, pre-existing and not changed here:** the deployed `ClientAgentController` calls `AccessPolicyService::isRouterPlatform()`, but the deployed `AccessPolicyService` predates that method.
  - It only matters on the location endpoints, which no client calls.
  - `ExternalUoTPool`, `HomePageContent`, `UltraCandidateReviewService` and `home-preview.php` also differ from git, and four live-only files exist.
- **Release gate:** the 600 s throughput gate was not run. This release changes only control-plane code, not the data plane or the runtime.
