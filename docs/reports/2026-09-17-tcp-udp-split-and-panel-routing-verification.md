# TCP/UDP split and panel routing support - verification (2026-09-17)

Status: read-only verification of the live panel + runtime, plus one small installer fix
(`install/install-bond-sidecar.sh`, section 4). Answers three operator questions against the
tree that is deployed right now.

## 1. Does client TCP keep the direct path while UDP goes through the sidecar UoT?

Per forward, yes - the split lives in the runtime, not in the routing table:

- `tools/ultra-client/main.go:2340`: when `transport` is `udp`/`tcp+udp` *and* the runtime is not
  in `native_socks5` mode, the UDP leg is served by `serveExternalUoT` (the external UoT
  carrier); TCP keeps `serveTCP` and UDP without an `external_uot` block keeps `serveUDP`.
  Policy stop/start drives both on the same lifecycle, so enabling/disabling UoT does not
  touch the TCP path.
- Live proof: `docs/reports/2026-09-17-external-uot-forward-170-live-acceptance.md` lines 8-14 -
  `UDP client -> Japan runtime -> Ultra TCP carrier -> Tokyo sidecar :7443 -> fixed UDP target`, and
  "forward and all TCP traffic keep the native paths"; the run ended with
  `VERDICT udp_roundtrip=PASS sustained=20/20`.

Scope (live DB `v3_leikwanhost_c`, 2026-09-17):

- Opted-in combinations: 51, 52, 53, 55, 57 (`external_uot_enabled=1`, all `backend_mode=ultra`,
  `client_mode=single`, `transport_mode=tcp+udp`, no bond sidecar point).
- 8 enabled forwards under them: 158, 180, 185, 190 (combo 51), 169 (52), 170 (57), 187, 194 (55).
- 11 of the 16 enabled forwards are *not* on a UoT combination - their UDP still uses the native
  relay path, which is the expected default.

Caveats:

- `native_socks5: true` runtimes (the desktop native-outbound path, see
  `LeikwanBridge.Desktop/.../NativeOutboundRelayHost.cs:221`) skip the external-UoT branch by
  design; the UoT split applies to the normal ultra client runtime.
- The bond-sidecar "direct TCP" gate is unrelated to this split and is currently a no-op: the
  live `.env` sets `SIDECAR_DIRECT_TCP_FORWARD_IDS=66` and `SIDECAR_DIRECT_TCP_POINT_IDS=4`, but
  forward 66 does not exist (max id 194) and `sidecar_points` has ids 5, 7, 8, 9, 10 only. If the
  intent was hongkong-hkg1 (now id 9), the env needs updating - operator decision, left untouched.

## 2. Does the admin panel already support installing the new sidecar?

Yes, for both halves of "the new sidecar", and the served bytes match the repo:

- Transport-endpoint install command: `BaseController::bondSidecarInstallCommand` (deployed
  sha256 `a39aa49e...1897ad3`, 80369 B) now appends
  `--external-uot-config <panel>/external-uot-config?host=<sidecar_host> --require-external-uot`
  whenever the point carries `sidecar_host`. Rendered by the admin page
  `/admin-transport-endpoints` (`AdminController:3632`, deployed `1b5b3ba9...a9d5c7` = repo) and the
  tenant page (`TenantController:1529`).
- Fresh install: `install/install-bond-sidecar.sh` defaults the config URL to
  `$ARTIFACT_BASE/external-uot-config` and installs the Go sidecar via `install-external-uot.sh`
  (`0506d838...a4d665` on the panel = repo), so a clean host no longer comes up with the old
  `UDP candidate failed` signature.
- Artifact routes (live): `/artifacts/install-external-uot.sh`, `/artifacts/install-bond-sidecar.sh`,
  `/artifacts/uot-sidecar/uot-sidecar_linux_amd64|arm64`, `/artifacts/versions.json`.
- Config endpoint: `/external-uot-config` (+ `/artifacts/external-uot-config`), deployed
  `fa021a2f...347d57` = repo. It resolves `?host=`/`?ip=`/caller address (X-Forwarded-For and
  X-Real-IP are not trusted) and answers a valid empty document for hosts that terminate no
  enabled UoT forward.

## 3. Does a forward combination support choosing this routing?

Per combination, yes:

- Admin combination editor carries the seven `external_uot_*` fields; the save path is
  `ProvisioningService::saveForwardCombination` (deployed `5cd84808...177f888` = repo), lines
  860-921: the block is honoured only when `UltraMode::isBackendMode()` (i.e. `backend_mode=ultra`),
  the sidecar target and token are validated, blank values keep the stored ones, and the knobs are
  clamped (queue 1-4096, keepalive 5-30 s, grace 5-300 s, frame payload 256-65507). It applies to
  every forward of the combination - there is no per-forward override.
- Delivery chain: `forwardMetadata` -> `ClientAgentController` (deployed `d503abbf...71dbf`, lines
  874-875 attach `external_uot` to the broker forwards) -> `LeigodTokenBroker` (deployed
  `9dc5069a...79a45bf` = repo) -> client config -> runtime.
- Deployment nuance: the panel still runs the pre-`forward_revision` ClientAgentController, so
  `external_uot_revision` is HEAD-only. Enable/token/sidecar changes reach clients on the next lease
  renewal (2-3 min); a pure knob-only edit needs the renewal window or a contract-signature change
  to apply immediately.

## 4. Closed in this pass

- `install/install-bond-sidecar.sh` (sha256 `c9dd7f6a...26715d`, 12517 B): a failed external-UoT
  step no longer aborts a host that does not require it (warn + continue); with
  `--require-external-uot` - what the panel command passes - the failure stays fatal, so a
  panel-driven install of a UoT host cannot silently ship without its carrier. This closes item 2
  of `2026-09-17-external-uot-live-store-publish.md` section 6.
- Deployed to the panel and verified end to end: panel file sha256 = repo sha256, served
  `/artifacts/install-bond-sidecar.sh` sha256 = repo sha256, `artifacts/versions.json`
  `bond_sidecar_installer` re-recorded (`c9dd7f6a...26715d`, 12517 B, version stays 2026.09.17.1 so no
  involuntary fleet re-install), `bash -n` clean on the host. Backup:
  `/root/codex-backups/panel-uot-installer-guard-20260917-043114` (also holds `versions.json.before`).
- Tests: `external_uot_install_wiring_test` 18/18 (3 new checks pin the guard), plus the 7-test UoT
  sweep `artifact_versions_manifest_consistency_test`, `installer_artifact_hash_verification_test`,
  `sidecar_agent_update_artifact_regression_test`, `external_uot_config_endpoint_test`,
  `client_agent_update_artifact_regression_test`, `ultra_client_artifact_distribution_test` - all PASS.

## 5. Still open (not part of this pass)

- A full fresh install on a scratch host: install -> unit enabled -> live UDP session through the
  sidecar (the panel-driven command path is what sections 2 and 4 wire together).
- 4 of the 5 fleet UoT hosts have no `sidecar_points` row; they rely on the bare-URL resolution, so
  their first re-install or agent update is worth watching.
- Go sidecar adoption is verified for the forward-170 host only (fleet canary); no fleet-wide swap
  was performed.
- Android client `external_uot` bootstrap: `2026-09-17-android-external-uot-gap.md` (separate
  workstream).
- Intermittent empty `up-20M` report in the synthetic rig: fleet-canary section 7.
- `SIDECAR_DIRECT_TCP_*` env ids (66 / 4) are stale as described in section 1.
