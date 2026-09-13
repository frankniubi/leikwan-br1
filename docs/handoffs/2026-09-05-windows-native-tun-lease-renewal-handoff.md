# Windows Native TUN Lease Renewal Handoff

Date: 2026-09-05 (Asia/Taipei)

Scope: Windows native outbound, TUN mode, forward `180`, `CAN -> HKG`, `intl_only`, unrestricted routing. This change does not modify Android, macOS, PHP, or the Windows Ultra runtime.

## Incident and root cause

The relevant disconnect was around 18:20, not the earlier 15:58 event.

- The Windows GUI started the native TUN session at 18:09:01.
- Production issued four leases at 10:09:01 UTC. All expired at 10:14:01 UTC, so the grant lifetime was five minutes.
- No subsequent Windows GUI policy pull or lease request reached production before the connection failed.
- The Ultra process enforced the lease expiry. The Windows GUI had no independent renewal clock and depended on an unrelated polling callback, which had stalled.
- The runtime cleanup observed at 18:19:45 was a consequence of the later stop path, not the initiating failure.

Two additional TUN-specific failures were found while reproducing the incident:

- Restricted routing downloaded `geoip-cn` and `geosite-cn` from `raw.githubusercontent.com` through a native SOCKS path that was not ready yet. The bootstrap DNS lookup could therefore terminate sing-box.
- The GUI published `Connected` after a fixed short delay even when the TUN adapter had not reached `Up` with `172.19.0.1`.

## Windows implementation contract

1. Run an independent lease renewal loop while native outbound is active. Do not rely on config polling, UI refresh, or traffic callbacks.
2. Refresh five-minute grants 90 seconds before expiry. Retry unchanged/temporarily unavailable grants every 10 seconds.
3. Check the actual native data plane every 5 seconds. A dead relay, sing-box process, or TUN adapter triggers recovery.
4. Hot-refresh lease candidates without restarting a healthy TUN. Preserve the working data plane when a replacement lease is temporarily rejected or unavailable.
5. Fetch a fresh policy snapshot immediately before each lease request and keep forward, direction, transport, route scope, route policy, and scope digest bound together.
6. Route `raw.githubusercontent.com` through local DNS and direct routing during restricted rule-set bootstrap.
7. Do not publish `Connected` until sing-box is running and the `LeiKwan Bridge` adapter is `Up` with IPv4 `172.19.0.1`. Retry TUN startup once, then fail closed with `proxy_tun_unavailable`.
8. Cancel renewal on stop, disconnect, capability removal, and shutdown. Wipe lease credentials on every rejected, replaced, or stopped path.

## Release artifact

- Desktop version: `0.1.20.10`
- Installer: `artifacts/windows/leikwan-bridge-setup.exe`
- Installer size: `69,427,776` bytes
- Installer SHA-256: `2451ed0906423584a9205452bcebe6c578e2f72cd23d6f4b205c5a21889c5a0c`
- App host SHA-256: `73043a5015827e7c792488fa8c39aeaa5602f6e56bde460d0ca6bc9e38cdad75`
- App DLL SHA-256: `16b773ecb87f2a75f5075a3a050c0d13bcda647bd47d086f004715b328c58c9d`
- Core DLL SHA-256: `305fa8fcba8b94180d5985770392b87ec8cbed88fc2fe7a06d58468795c19586`
- Windows Ultra remains `2026.09.05.1`, size `6,887,424`, SHA-256 `62174c051552d4b127c7a5d7fd692512f5ab9afa59520b6463e28c3da5454f4b`.

The installer remains unsigned under the existing Windows release contract.

## Verification evidence

- Core tests: `285/285` passed.
- Windows App tests: `52/52` passed.
- Mac shared-code tests: `71/71` passed.
- Installer script/helper tests: `13/13` passed.
- The installed local app reports `0.1.20.10`; its app host, App DLL, Core DLL, Ultra, and sing-box hashes match the tested release payload.
- Startup remained `Starting` until sample 5, when sing-box was alive and TUN was `Up`; it did not publish a false `Connected` state.
- Renewal cycle 1 requested at 20:02:27, advanced expiry from 20:03:57 to 20:08:04 at 20:03:09, and crossed the old expiry with unchanged process IDs/runtime directory and increasing byte counters.
- Renewal cycle 2 requested at 20:06:34, advanced expiry from 20:08:04 to 20:12:06 at 20:07:22, and crossed the old expiry at 20:08:05 and 20:08:14 with unchanged process IDs/runtime directory, TUN still `Up`, and increasing byte counters.
- The headed Twilio Network Test on the same native TUN data path passed TURN UDP (2120 ms), TURN TCP (1088 ms), TURN TLS (1172 ms), and bandwidth. The final `0.1.20.10` candidate was also manually confirmed by the operator to pass.
- Later automated Twilio attempts blocked at the site's reCAPTCHA/auth endpoint are not classified as UDP failures.

Evidence files:

- `.tmp/native-renewal-final5-start-readiness-20260905.log`
- `.tmp/native-final5-renewal1-monitor-20260905.jsonl`
- `.tmp/native-final5-renewal1-advance-monitor-20260905.jsonl`
- `.tmp/native-final5-cross-old-expiry-20260905.jsonl`
- `.tmp/native-final5-renewal2-monitor-20260905.jsonl`
- `.tmp/native-final5-cross-old-expiry2-20260905.jsonl`
- `.tmp/twilio-native-tun-final4-start-20260905.log`
- `.tmp/windows-native-renewal-final-core-tests-20260905.log`
- `.tmp/windows-native-renewal-final-app-tests-20260905.log`
- `.tmp/windows-native-renewal-final-mac-tests-20260905.log`
- `.tmp/windows-native-renewal-final-installer-tests-20260905.log`

## Android, macOS, and Linux follow-up

Port the behavior, not Windows-specific named-pipe or adapter code:

- Give each platform an independent renewal scheduler with the same 90-second lead, 10-second retry, and 5-second data-plane health interval.
- Keep the selected candidate sticky for existing TCP and UDP flows. Activate a replacement before retiring the previous grant.
- Keep `client -> slave -> sidecar`; never create direct client-to-sidecar connectivity.
- Gate the UI connected state on the platform's actual TUN interface/address readiness, not only process creation.
- Bootstrap remote rule sets without recursively depending on the not-yet-ready tunnel.
- Verify at least two five-minute expiry boundaries with stable runtime identity, increasing traffic counters, and a real TURN UDP test. NAT type alone is insufficient.
- Test DNS, TCP half-close, UDP idle/keepalive, game traffic, sleep/resume, stop/reconnect, and temporary lease API failure.
- Android must not claim full native TUN acceptance until the packaged `libbox.aar` contains the Java/native-matching `io/nekohasekai/libbox/BridgeOptions.class`.

## Production deployment

Commits:

- `a546ea7e` - `fix(windows): renew native tun leases independently`
- `7499ddaa` - `release(windows): publish native tun lease renewal`

The production deployment replaced only the Windows installer and the
`windows_client_app` manifest fields. The Windows Ultra payload and every
Android/macOS/PHP entry remained unchanged.

Pre-deployment evidence:

- Manifest: size `16,957`, SHA-256 `38ae90b232cb9edc94358422981f1fbbf0db590b2731b892838c6dcb9c484e34`
- Windows setup `0.1.20.9`: size `69,437,952`, SHA-256 `52808cd3145085f5aec37706b4ee36dba66be41c1b78e547294c5191e7a5c5e7`
- Windows Ultra `2026.09.05.1`: size `6,887,424`, SHA-256 `62174c051552d4b127c7a5d7fd692512f5ab9afa59520b6463e28c3da5454f4b`
- `/api/health`: HTTP `200`; `php-fpm-83-source196.service`: `active`

Deployment controls and result:

- A nonblocking `flock` prevented concurrent publication.
- Exact old-state and upload hashes, sizes, manifest version, canonical path/URL, unchanged Ultra runtime, and zero non-Windows manifest drift were required before mutation.
- Backup was created before mutation at `/root/codex-backups/windows-native-renewal-20260905-20260905T124118Z`.
- Backup files are mode `0600`, and their post-deployment hashes and sizes match the pre-deployment state.
- The setup payload was atomically renamed first; the manifest was atomically renamed last. A failure trap restored both files and reloaded php-fpm.
- The first attempt safely rolled back because its public canary incorrectly treated the PHP-expanded `/artifacts/versions.json` response as byte-identical to the static disk manifest. Rollback verification proved all old hashes restored and health remained HTTP `200` before the corrected attempt.
- The corrected canary keeps strict byte equality for disk/origin artifacts and validates the dynamically expanded public manifests by selected field, canonical path, and URL query contract.

Post-deployment evidence:

- Live manifest: size `16,958`, SHA-256 `9d482523130ec9b13ae5744f5f7c57fd719e6c6a20ca81fa33b9ee34f6913380`
- Live Windows setup: size `69,427,776`, SHA-256 `2451ed0906423584a9205452bcebe6c578e2f72cd23d6f4b205c5a21889c5a0c`
- Live Windows Ultra remained size `6,887,424`, SHA-256 `62174c051552d4b127c7a5d7fd692512f5ab9afa59520b6463e28c3da5454f4b`.
- Origin setup canary: HTTP `200`, exact setup size/hash.
- Public cache-busted setup canary: HTTP `200`, exact size/hash, ETag equal to the setup SHA-256.
- Public `/artifacts/versions.json`: HTTP `200`, Windows `0.1.20.10` contract matched; response size/hash recorded as `19,880` / `ac111e0c86959209ec21e05cbb1d0e16cce30263c14398f943ffb44a36dea45c`.
- Public `/artifacts/client-manifest.json`: HTTP `200`, nested Windows `0.1.20.10` path/version/hash query contract matched; response size/hash recorded as `16,502` / `0137ee43cce180117885085a03cc9f0b66cb0986c72fd271fe1b6379d7511fc1`.
- Independent client-side public download repeated the same size/hash and manifest assertions.
- Health passed three consecutive client-side checks and three consecutive server-side postflight checks, all HTTP `200`; php-fpm remained `active`.
- Live native lease probe passed three consecutive cycles: HTTP `200`, `ok=true`, four leases, `CAN -> HKG`, `intl_only`, unrestricted allowed.
- Deployment upload temporary files were absent after completion.

Deployment evidence files:

- `.tmp/windows-native-renewal-production-preflight-20260905.log`
- `.tmp/windows-native-renewal-production-deploy-20260905.log` (first attempt and automatic rollback)
- `.tmp/windows-native-renewal-production-rollback-audit-20260905.log`
- `.tmp/windows-native-renewal-route-diagnostic2-20260905.log`
- `.tmp/windows-native-renewal-production-deploy2-20260905.log`
- `.tmp/windows-native-renewal-production-postflight-20260905.log`
- `.tmp/windows-native-renewal-live-lease-probe-20260905.log`
- `.tmp/public-canary-windows-native-renewal-20260905T124309Z/canary.log`
