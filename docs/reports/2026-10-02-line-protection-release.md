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

- **Client 10 (180.153.83.93, Linux agent 2026.09.27.1) had not updated by 13:46 UTC** while the other three
  updated within ~3 minutes. It pulls config and gets leases normally (attested through the rollover hashes) but
  has fetched no update artifact since 09:02 UTC; its account has no explicit update token, so the agent's own
  gate is holding it (`LEIKWAN_UPDATE_HOLD_UNTIL` or the converge back-off in `client_agent.py`). No SSH access
  from here; check on the host or set an explicit update request in the panel.
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


## 2026-10-02/03 后续（第二批，panel 已部署，master 已含）

- **`ULTRA_LEASE_SEALING=auto`**（commit 2df08af4，18:18 UTC 部署）：已 pin 过 key 的设备、或 `ULTRA_LEASE_SEALING_NEW_DEVICES_SINCE`（设为 2026-10-02 13:35:00）之后首见的设备，不带 X-Leikwan-Lease-Key 领 lease 直接 409 `lease_sealing_required`；更早的老设备仍明文可用。生产实测：带 agent_token 但不带 key 的 curl（老/新 device id）均被拒；正常用户领取量不降。sealing 拒绝改用 lease 端点的 {ok,error} 结构。
- **sidecar 自重装循环修复**（commit 394ce522，18:29 UTC 部署）：根因=一次性 `agent_update_request_token` 永不清除 + agent 只记一个 last_completed，explicit↔auto-release 每次心跳互相重新触发；point 7 fra1 自 08-23 起每 1–3 分钟重装 bond/mptcp 数据面（~27GB/天）。面板现在在 agent 报告完成后清 token（UPDATE 带 token 守卫）；已清 point 5/7/9 的陈旧 token；fra1 重装 18:30 起降为 0/分钟。⚠️遗留：89.144.30.80 上仍有个 2026.08.13.1 旧 Python agent 在跑（无 SSH 凭据，没杀掉；token 清空后它拿不到可执行更新）。
- **lease 时长账本**（commit e5f15e30，18:33 UTC 部署）：新表 `ultra_lease_time`（每 client+device+forward+UTC 日一行，记 lease 窗口并集秒数，不重复计 renewal/candidate）。这是服务器端唯一不可伪造的用量度量，为按时长/并发计费打基础；**仅记录，无强制、无计费改动**。迁移走 MigrationRunner 自动执行（已记入 schema_migrations）。已确认在记录。
- 密码：117.55.193.164 root 改为 `Frank20021212.`。

## 仍需用户决定
1. **推送到 GitHub**：本地 master 领先 origin/master 529 个提交（因 09-26 历史重写）；推送=对远端强推重写历史，未做，等明确确认。
2. **计费模型**：ledger 已同时支持"按时长"和"按并发线路"；选哪种 + 额度，定了再做强制逻辑。

## 2026-10-03 旧凭据实测 + 源主机停刷新告警

- **一天前的 S5 凭据不能握手**（04:37 UTC 实测）：10-02 抓的 10 份凭据（09:03–10:47 UTC，含 18:47 本地的 `eef54665`）全部 DEAD，表现与故意写错的假凭据完全一样；同一时刻、同一批当前中转 IP 上，当前真实凭据 ALIVE。旧凭据换到当前中转 IP 上测仍 DEAD，确认失效的是凭据本身。探测：源主机 `/root/probe_captured.py`（经 LeiGod 连 8.8.8.8:53，真凭据→ok/target_refused，假→no_downstream）。
- **前提**：旧凭据只在源 agent 重新取凭据时作废。源停止刷新时旧凭据可能一直有效 → 加告警：`master/cli/ultra-source-watchdog.php`（root crontab 每分钟，日志 `/root/lk-ultra-source-watchdog.log`），过去 24h 刷新过的源超过 `ULTRA_SOURCE_STALE_MINUTES`(10) 分钟未上传 → 邮件管理员（`ULTRA_SOURCE_ALERT_EMAILS` 可改收件人），每小时提醒，恢复时通知。已发测试邮件。

