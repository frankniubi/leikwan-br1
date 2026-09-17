# 2026-09-17 External UoT panel config verification (panel_uot_config)

Status: read-only verification. No writes were performed on the panel, database,
or client runtimes. Branch snapshot: HEAD `8c62f672` (2026-09-17 04:00 +0800),
`master/` working tree clean; all "deployed" md5s below are from pinned copies in
`.tmp/deploy-panel/` pulled from the live panel during this audit.

## Verdict

- The panel-side external UoT configuration surface is complete and live:
  schema, admin UI, save path, broker attach, live DB values, and (for the
  Tokyo pilot) the runtime files on the client carry exactly the panel bytes
  that matter - token sha256 equality is proven, not inferred.
- Two deploy gaps remain between the live panel tree and repo HEAD, both
  outside the pilot's acceptance path:
  1. `forward_revision` external-UoT projection (HEAD `26047618`) is not on the
     panel; until deployed, an edit that *only* retunes UoT knobs (queue /
     keepalive / grace / frame) does not move the client-polled revision.
     Token / enable / sidecar changes still propagate via lease renewal (see
     live proof below).
  2. OpenWrt pairing + arm64 single-file installer wiring (`Bootstrap`,
     `BaseController`) - unrelated feature workstream.
- Homepage files (`HomePageContent.php`, `bridge-home.css`, `i18n/zh-*.json`)
  diverge in BOTH directions between the live panel and HEAD; treat as the
  homepage workstream, do not blind-deploy master/ home files over the panel.

## Chain proof (panel -> broker -> client-agent -> runtime)

- Live DB (panel): `forward_combinations` with `external_uot_enabled=1`:
  51 ultra-can-hkg -> 70.36.96.183:7443, 52 ultra-can-sin -> 77.93.90.45:7443,
  53 ultra-foc-tpe -> 103.246.219.39:7443, 55 ultra-sha-lax -> 208.122.17.50:7443,
  57 ultra-sha-tyo-uot-pilot -> 185.220.238.143:7443. All tokens 48 hex chars,
  queue 256 / keepalive 10 s / grace 30 s / frame 65507, enabled 2026-08-24.
- `forwards.forward_combination_id / active_combination_id`: 170 -> 57;
  18 forwards ride UoT combos (158,160,166,167,168,169,170,173,175,180,181,
  182,185,187,189,190,192,194).
- Broker: `LeigodTokenBroker::attachExternalUoT` attached per lease;
  `target_digest = hash('sha256', target)`.
- Runtime (172.21.255.189, GCP "instance-20241020-0131"):
  `ultra-forward-170.service` active since 2026-09-17 01:18:25 CST,
  drop-in `20-extuot.conf` runs `/usr/local/bin/ultra-client-extuot`,
  config mode 0600, agent journal shows continuous
  "forward 170 independent Ultra lease renewal delivered".
- Exact matches:
  - combo 57 token sha256 `359e2db7330a358f23e8dd775985db898af9f08602bf6d3ae867a0819b0efd05`
    == token sha256 in `/etc/ultra/forward-170.json`.
  - `sha256('185.220.238.143:10001')` = `0f877086c7cff08cd00f6778f0f18654464fc41cb5a0b45a4d787f4e1244170b`
    == runtime `target_digest`.
  - Forward subset parity: 158/180 -> combo 51 (token sha12 `8120c3dc09b6` ==
    panel), 159/171 -> combo 54 (UoT off, runtime file `enabled=None`) - all
    consistent with panel intent.

## Deployed panel tree vs repo HEAD 8c62f672

| file | deployed md5 | HEAD blob md5 | state |
| --- | --- | --- | --- |
| src/Support/ProvisioningService.php | 3a81d52184e1e34b457f0ac59d5e6783 | same | SAME |
| src/Controllers/AdminController.php | 229d929e1540009b03a3795c525c6531 | same | SAME |
| src/Support/LeigodTokenBroker.php | 93ba951647261f151f3be4678177603d | same | SAME |
| src/Controllers/ArtifactController.php | 794a0fd9586b5677d70813e0172306a7 | same | SAME |
| src/Controllers/ClientAgentController.php | 4ae41c2be37a8fec914292e8658038e9 | ecbd084ea856157000ed325e9038def1 | DIFF |
| src/Bootstrap.php | 32cf7dba5a15e2356338abc33c37f4bc | fa540b1ea30fbd0a8ddde06179470697 | DIFF (OpenWrt routes) |
| src/Controllers/BaseController.php | 22f08f50c632621b77deb5f85b3c5b9a | c02badc7e338ba95345028a400cf3412 | DIFF (arm64 installer, +139 lines on HEAD) |
| src/Support/HomePageContent.php | 4ff8f7b8c1f4c5bd3df57818f89b481d | 1e9c096138ce656152e70c3af6d84d83 | DIFF (both ways) |

ClientAgentController detail (deployed 4ae41c2b vs HEAD ecbd084e):

- deployed = repo blob `973f3d7a` (recorded pre-hotfix at
  `/root/codex-backups/panel-uot-patch-20260916-153325/`) + attach hotfix;
  neither variant exists in any commit (`git log --all -S
  'ultra_lease_forward_refused'` is empty; pre-patch blob matches nothing).
- deployed-only: lease failure logging (`ultra_lease_forward_refused`,
  `failures[]` in the lease response) - decide to port or drop before the next
  panel release.
- HEAD-only: `forward_revision` projection carrying
  `external_uot_revision` (commit `26047618`) + OpenWrt pairing (commit
  `49c0be31`). Test coverage exists: `master/tests/client_configs_external_uot_revision_test.php`,
  `master/tests/client_agent_external_uot_grant_wiring_test.php`.

## Unit tests

- Full suite on this tree: TOTAL=400 FAILURES=16; all 16 reproduce identically
  on a pristine `master` archive under the same local PHP 8.3 - environment
  noise (local `date.timezone='UTC+8'` warning) and pre-existing failures, NOT
  branch regressions. The UoT-specific tests pass.

## Recommendations

1. Next scheduled panel release: deploy `ClientAgentController.php`
   (ecbd084e) + `Bootstrap.php` (fa540b1e). Reconcile the panel-only
   lease-failure logging first (either port it into the repo or accept
   dropping it).
2. Until (1) ships: enabling/disabling/retuning UoT on a live combination is
   effective for enable/token/sidecar changes via the 2-3 min lease renewal;
   for pure knob edits, re-check the runtime config file after the next renewal
   window. Roll a contract-signature-changing action if an immediate apply is
   required.
3. Rollback assets (panel side): `/root/codex-backups/panel-uot-patch-20260916-153325`
   (pre-patch ClientAgentController), deployed pre-patch copy saved locally at
   `.tmp/deploy-panel/cac-pre-patch.php` (973f3d7a).
4. Homepage divergence: route to the homepage workstream owner; exclude
   `HomePageContent.php` / `bridge-home.css` / `i18n/zh-*.json` from any
   panel-deploy step that is not homepage-scoped.

## Reproduction (read-only)

- Panel DB: `SELECT id,name,external_uot_enabled,external_uot_sidecar_target,
  CHAR_LENGTH(external_uot_token),LEFT(SHA2(external_uot_token,256),12)
  FROM forward_combinations WHERE external_uot_enabled=1;`
- Runtime: `systemctl status ultra-forward-170`,
  `python3 - <<'PY' ... hashlib.sha256(open config external_uot.token)`,
  `journalctl -u leikwan-client-agent --since 01:14 --until 01:24`.
- Panel drift: `md5sum` on panel vs `git show HEAD:master/... | md5sum`.

## Fresh-install wiring (deployed 2026-09-17 20:38 UTC)
Scope note: the sections above are the read-only audit of the originally deployed panel; this section records the follow-up deploy (repo commit a062260d + amended tip) plus its verification.


Problem: a clean host that ran the *recommended* flow
(`curl .../artifacts/install-bond-sidecar.sh | bash -s -- --master ... --agent-token ...`)
never received its external UoT forwards, so the runtime's first session logged the
`UDP candidate failed` signature. Only the manual operator flow
(`--external-uot-config <panel>/external-uot-config?host=<ip>`) worked.

Changes (repo + live panel):

1. `install/install-bond-sidecar.sh` (sha256 `c7389c5a...e17c69261`, 11884 B): the
   external-UoT step now defaults to
   `--external-uot-config "$ARTIFACT_BASE/external-uot-config" --require-external-uot`
   (bare URL, resolved server-side by the host's own address) instead of the old
   silent `--skip-external-uot`. Explicit `--skip-external-uot` /
   `--external-uot-config <path|url>` still override the default.
2. `ExternalUotConfigController` (sha256 `fa021a2f...347d57`, 13622 B): when `?host=`
   is missing the endpoint falls back to the caller's address (`$_SERVER['REMOTE_ADDR']`
   — the direct peer address; `X-Forwarded-For` / `X-Real-IP` are deliberately not
   trusted, so the value cannot be spoofed from the internet). "No enabled forwards
   for the caller" still answers `200` with a valid empty document, and
   `panel.request.host_source` reports which input was used.
3. `BaseController::bondSidecarInstallCommand` (deployed sha256 `a39aa49e...1897ad3`,
   80369 B): when the point carries `sidecar_host`, the one-click command now passes
   `--external-uot-config <panel>/external-uot-config?host=<host> --require-external-uot`
   (previously nothing, i.e. silent skip).
4. `artifacts/versions.json`: `bond_sidecar_installer` re-recorded
   (`c7389c5a...e17c69261`, 11884 B). The `version` field is deliberately **not**
   bumped: `SidecarAgentController` publishes `desired_installer_version` from it and
   a bump would start an involuntary fleet-wide sidecar re-install. Integrity is
   carried by the digest, and `GET /artifacts/versions.json` recomputes
   `sha256`/`size_bytes` from the actual file at serve time, so hosts always get the
   matching value.

Verification:

- Local: `master/tests/*` sweep 402 tests -> 386 pass / 16 fail, identical to the
  pre-change baseline (all 16 are unrelated tenant/provisioning/knowledgebase
  fixtures). The seven tests that touch this feature pass:
  `external_uot_config_endpoint_test` (58 checks), `external_uot_install_wiring_test`
  (15), `artifact_versions_manifest_consistency_test`,
  `installer_artifact_hash_verification_test`,
  `sidecar_agent_update_artifact_regression_test`,
  `client_agent_update_artifact_regression_test`,
  `ultra_client_artifact_distribution_test`; plus
  `client-agent/tests/test_artifact_versions_consistency.py` (6 tests, OK).
- Panel (post-deploy): `php -l` clean on both controllers, `bash -n` clean on the
  installer, 58 + 15 checks green, manifest + installer-hash guards green.
- Panel (live): `GET /artifacts/versions.json` advertises
  `bond_sidecar_installer 2026.09.17.1 11884 c7389c5a49a7abee` (matches the file
  byte-for-byte); bare `GET /external-uot-config` from the panel IP answers `200`,
  775 B, `forwards {}` — i.e. the remote-address fallback resolves and the empty
  contract holds.
- External caller: the same bare URL fetched from a workstation (not the panel)
  answers 200, 763 B and reports panel.request.host_source=remote-address with
  remote_address=112.81.104.187 - the panel sees the caller's own public address
  (no CDN/proxy masking), which is exactly the key used by
  sidecar_points.sidecar_host / forward_combinations. Spoofing the address via
  X-Forwarded-For / X-Real-IP was tested and is ignored (the request then resolves
  as the direct peer).
- Config validity: the published Go sidecar binary (18bd060a...ac60ef) accepts both
  the empty document and a populated one (9 forwards for 70.36.96.183) with
  --check-config, rc 0 - what a fresh install fetches validates against the shipped
  binary.
- Still open: a full fresh install on a scratch host (install -> unit enabled ->
  live UDP session). 4 of the 5 fleet hosts have no sidecar_points row and rely on
  this bare-URL path, so the first real re-install/agent update is worth watching.

Rollback: panel `/root/codex-backups/panel-uot-install-wiring-20260916-203802`
holds `.before` copies of all six replaced files; repo-side `git revert <commit>`.

## Default-on follow-up (2026-09-17 12:33 UTC)

The originally opt-in combination behavior is now default-on for Ultra. The
shared `ExternalUoTDefaults` resolver enables an omitted flag, generates a
fresh token, selects the live destination-zone map, and keeps an explicit `0`
as an opt-out. The live panel map is HK `70.36.96.183`, SG `77.93.90.45`,
South Korea/Taiwan `103.246.219.39`, Frankfurt/Moscow `157.228.138.23`, LA
`208.122.17.50`, and Tokyo `185.220.238.143`, all `:7443`; unknown zones fall
back to Tokyo.

Production readback after the transaction: 12/12 Ultra combinations enabled,
all tokens length 48, 10 non-Ultra combinations disabled. The six sidecars
passed `install-external-uot.sh --check-only --strict` and run the same Go
artifact (`2026.09.17.2.95535c6`, SHA
`bdc18d8efc81d28341449ef51c29792edcd6e5d32206fff470edae3715a32b09`). The
scratch client was refreshed and its real runtime -> Tokyo sidecar harness
returned UDP `20/20` sequential and `50/50` burst, zero loss; the five local
Ultra status files were `ready` with no `no_healthy_candidate` or
`udp_probe_failed` in the verification window.
