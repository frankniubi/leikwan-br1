# Client location check (geo lock) — implementation plan

Date: 2026-09-27
Status: plan, not started. Panel side already exists (2026-08-28); this plan
covers the clients plus the Panel changes needed to turn geo lock on safely.

## Why

- The Panel implements "main province + adjacent provinces" (`AccessPolicyService`,
  `MainlandRegionPolicy`, plan switch `service_plans.geo_lock_enabled`) and the
  wire protocol in `docs/handoffs/2026-08-28-panel-access-policy-distribution-handoff.md`.
- **No client implements it.** `git grep access/location` finds nothing outside
  `master/` and `docs/`. With geo lock on, every client gets
  `location_check_required` (428) on select/pull and shows a generic error.
- Production (2026-09-27): every plan has `geo_lock_enabled=0`; the
  access_blacklist_rules, access_location_observations and
  access_policy_events tables are empty.
- Since 2026-09-27 the admin panel cannot enable geo lock or geographic
  blacklist rules while `ACCESS_POLICY_LOCATION_CLIENTS_READY` is off (the
  default). Flip it only after this plan ships and step 5 passes.

## Building blocks already in place

| Need | Where it exists |
| --- | --- |
| Panel traffic that ignores proxies and leaves through the physical NIC | Desktop `LeikwanHttpClientFactory` + `PanelDirectEgress` (since 09-11); login-page "Ethernet Binding" + TUN/TAP filter (09-27); Android bound to a non-VPN `Network` (09-27); client-agent / leikwand no-proxy + SO_BINDTODEVICE (09-27) |
| Stable device identity | Windows `windows-client-*`, Mac `macos-client-*` file, Android keystore id, Linux `<state_dir>/device-id` (09-27), OpenWrt machine-id |
| Clear, persistent refusal UX pattern | device-limit work (09-27): typed exception, banner/card/notification, steady retry, no logout |

The physical-NIC-bound client **is** the `physical_no_proxy` collector. It
needs no extra machinery, only a lookup call made through it.

## Client contract (per the handoff, unchanged)

1. `POST /api/client/access/location/challenge` gets back `challenge_token` (5 min, single use).
2. Collect observations. Each is a lookup of `https://api.bilibili.com/x/web-interface/zone`:
   - `effective_path`: made over the user's normal route (desktop `CreatePlain()`; Android default network; Linux/OpenWrt unbound socket).
   - `physical_no_proxy`: made through the physical-NIC-bound client. Always send
     it together with `effective_path`, so a foreign effective path never costs
     an extra 428 round trip.
3. `POST /api/client/access/location/report` with both observations, then retry
   the original request (select/pull) with the same `attempt_id`.
4. Schedule: every 30 min + deterministic 0–5 min jitter, and immediately on an
   interface/source change. Follow the server's `next_location_check_at`.
5. Send `x-access-policy-version: 1` on every panel call once implemented.

Error handling (the same UX as the device limit: persistent banner, no logout, no tight loop):

| Code | HTTP | Client behaviour | User text (zh-CN) |
| --- | ---: | --- | --- |
| `location_check_required` | 428 | run steps 1–3 transparently, then retry once | — (invisible) |
| `direct_location_check_required` | 428 | send `physical_no_proxy` (should already be included) | — |
| `location_check_unavailable` | 503 | back off 60 s; not a violation | 暂时无法确认所在地区，稍后自动重试 |
| `geo_lock_violation` | 403 | stop; show the block expiry | 当前所在省份不在允许范围内（主省份：{P}） |
| `device_temporarily_banned` | 423 | wait `retry_after_seconds` | 设备已被临时限制，{T} 后可重试 |
| `access_blacklisted` / `device_banned` / `account_banned` | 403 | stop | 访问被拒绝，请联系服务商 |

Extra hint: when the physical lookup fails, or a third-party VPN is detected
(Android `isThirdPartyVpnActive()`, desktop no eligible physical NIC), append
"检测到其他 VPN/代理正在运行，请关闭后重试".

## Work breakdown

1. **Panel**
   - Add the Panel-side codes above to `$clientSafeCodes` where missing.
   - Add a regression test that renders every code through `publicErrorResponse`.
   - **Decision needed:** what happens to clients that do not send `x-access-policy-version: 1` while geo lock is on:
     - (a) refuse them with a clear `client_upgrade_required` (recommended);
     - (b) exempt them, which is a bypass.
   - Replace the global `ACCESS_POLICY_LOCATION_CLIENTS_READY` with that version gate once all clients ship.
2. **Desktop Core (Windows + Mac + both headless):**
   - Add a `LocationCheckService` in Core: challenge, dual lookup, report, schedule, handling of `NetworkAddressChanged`.
   - Hook it into `MasterApiClient` so a 428 on select/pull/heartbeat runs the check and retries once.
   - Surface the states in the existing banner (Windows) and notice (Mac); in headless, the status file and log.
3. **Android:**
   - Same service in Kotlin, using `PanelNetworkBinder` for the physical lookup and the default network for the effective lookup.
   - Wire it into the enrollment and control loops.
   - Show states on the Connections card.
4. **Linux client-agent:**
   - Same flow in `sync_once`, running the physical lookup through the bound opener.
   - Record the state in the pull state and in `check`.
5. **OpenWrt leikwand:**
   - Same flow.
   - Take the physical lookup from the bound WAN dialer.
   - Show it in LuCI and `ctl status`.
6. **Pilot:**
   - Staging panel, one pilot plan with geo lock on, a test account per platform.
   - Verify: inside the allowed province → allowed; proxy on (Clash TUN/system proxy) → the physical lookup still reports the true province; foreign effective path + physical Mainland → allowed; disallowed province → one violation and a 30-min block, with retries not counting extra; provider down → `location_check_unavailable`, with no violation.
   - Then set `ACCESS_POLICY_LOCATION_CLIENTS_READY=1` on production and enable one plan.

## Risks / open questions

- The Bilibili zone API is a single third-party provider. Add a second provider or a Panel-side IP-to-region fallback before relying on it for bans.
- A third-party Android VPN without `allowBypass` makes a physical lookup impossible. The user must turn it off; the hint covers this.
- Rolling client updates: the desktop GUI does not auto-update (the UA `AgentVersion` constant is stale at 2026.04.09.1, so the Panel cannot see real builds either). Fix that constant first, so the version gate in step 1 can work.
- Three violations in 24 h ban the whole account. Pilot carefully and keep provider failures out of the count (already true on the Panel).
