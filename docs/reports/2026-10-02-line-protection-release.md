# Line-protection release (2026-10-02)

Plan: `docs/plans/2026-10-02-line-protection-security-remediation-plan.md` (Appendix B). Protocol:
`docs/specs/2026-10-02-ultra-sealed-lease-v1.md`. Branch `feat/line-protection-2026-10-02` (not pushed).

## Versions

| Component | Before | Now |
|---|---|---|
| Panel PHP | — | external-uot auth/target binding, sealed leases, attestation gates, lease-seal route, attestation rollover |
| Linux agent / installer / frozen amd64 / arm64 offline installer | 2026.09.27.1 / 2026.09.28.3 | **2026.10.02.1** (+ new `client_lease_seal`) |
| Ultra runtime Linux amd64/arm64, macOS arm64/x64 | 2026.09.25.1 (plain go) | **2026.10.02.1.d1bac327** (garble v0.17.0 `-literals -tiny`) |
| Ultra runtime Windows / Android | 2026.09.25.1 | unchanged |
| Windows app + headless | 0.1.20.59 | **0.1.20.60** |
| macOS app | 1.0.52 | **1.0.53** (bundled ultra-client = published macOS runtime, byte-identical) |
| Android | 0.2.35 (37) | **0.2.36 (38)**, R8 on |
| OpenWrt | 1.0.0 | **1.0.1-r1** ipks + firmware in `tmp/candidate-20261002-1/openwrt/` (no panel channel) |

## Publication

1. **Panel PHP**, 11:49 UTC (backup `/root/codex-backups/line-protection-20261002/`). Lease-seal route 13:25 UTC.
2. **External-UoT token rotation**, 12:00:51–57 UTC: 12 combinations, 5 standalone sidecars hot-reloaded (tokens replaced in place, every forward bound to its own target, configs 0600), pool nodes re-synced healthy.
3. **Artifacts `line-protection-20261002-1`**, 13:35:00 UTC, status `committed`, 17 entries, all public hashes verified (and on the Aliyun mirror). Backup `/root/codex-backups/line-protection-20261002-1/`.
4. **Artifacts `line-protection-20261002-2`** (Android), 13:43:27 UTC, `committed`, public hash verified.

## Verification

- `/external-uot-config` anonymous / `?host=` / `?ip=` / `?point_id=` / wrong token → 401; sidecar self-probe on every host: own target admitted, foreign target / sidecar localhost / bad token refused.
- Sealed leases against production: Linux (172.21.255.189, 4/4 opened), Android emulator API 34 (Keystore agreement key, forward 170 connected through a renewal, also with R8), leikwand mock panel (39/39), Desktop unit tests (Win 919 / App 122 / Mac 288; Mac 0600 key harness on the build Mac).
- Fleet at 13:44 UTC: 3/4 Linux agents on 2026.10.02.1, one already on the garbled runtime with its lease key pinned; no 5xx after the publish; 0 seal errors.

## Incidents

1. **Site-wide 500 for ~70 s (11:47:00–11:48:09 UTC, 176 requests).** I ran the migration by hand and then
   uploaded it; the panel's MigrationRunner re-ran it on every request and failed. Fixed by recording it in
   `schema_migrations`. Lesson: only ship the migration file.
2. **Stale manifest 11:49–12:52 UTC.** A false-alarm rollback restored the old `versions.json`; the redeploy
   re-copied only PHP/installer files, so the bond-sidecar installer hash in the manifest was stale for an hour
   (sidecar self-updates would have refused, not broken). Fixed surgically.
3. **Old Linux/macOS clients refused leases 13:35:00–13:37:06 UTC (65 refusals, clients 10 and 20).**
   Publishing the new runtime replaced the per-platform attestation hashes, so 2026.09.25.1 binaries no longer
   matched a released hash. Mitigated by `ULTRA_ATTESTATION_ENFORCEMENT=report`, fixed with
   `ULTRA_ATTESTATION_EXTRA_HASHES_JSON` (e025a7c4) listing the four previous hashes, `enforce` restored 13:39:48.
4. QA: client 3 service 11 was extended 12:55–13:39 UTC for sealed-lease QA and restored exactly.

## Follow-ups

- **Remove `ULTRA_ATTESTATION_EXTRA_HASHES_JSON`** from the panel `.env` once every Linux/macOS client reports
  fingerprint `2026.10.02.1.d1bac327` (check `client_device_sessions.ultra_runtime_fingerprint`).
- **`ULTRA_LEASE_SEALING=require`** only after every live device has `ultra_lease_key_sha256` set (desktops update
  on their own schedule; 0/3 desktop devices had updated at 13:44).
- Android first-connect race (pre-existing): `UltraLeaseClient` tries the runtime control socket 3 × 50 ms; 2/6
  first connects after install failed with "Ultra lease was rejected by the local runtime".
- Keep `tmp/candidate-20261002-1/leikwan-android-client-0.2.36.mapping.txt` (R8) for crash traces; never publish it.
- Pre-existing: the live 2026.09.28.3 arm64 offline installer embedded a stale `install-forward-runtime.py`
  (fixed in 2026.10.02.1); one sidecar (`2a0f:5707:aaf1:ede6::1`) reinstalls itself every 2–3 minutes;
  client 3 has two leftover emulator device sessions (462, 463).
