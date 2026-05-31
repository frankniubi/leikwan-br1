# Android Direct VPN still-bad handoff — 2026-05-31

Workspace: `C:\Users\frankniubi\Downloads\leikwan-br1`  
Android module: `leikwan-android`  
Package under test: `com.leikwan.bridge`  
Device observed: OnePlus `LE2123`, Android SDK 34  
Branch at time of handoff: `feature/android-routing-v2`

## Current worktree and file situation

Snapshot command:

```powershell
git status --short --branch
```

Current status snapshot after temp cleanup:

```text
## feature/android-routing-v2
 M LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/EndpointConfig.cs
 M LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondEndpointHealthService.cs
 M LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondEndpointHealthServiceTests.cs
 M artifacts/windows/leikwan-bridge-setup.exe
 M leikwan-android/app/build.gradle.kts
 M leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelApi.kt
 M leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelModels.kt
 M leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt
 M leikwan-android/app/src/main/java/com/leikwan/bridge/proxy/SubscriptionFetcher.kt
 M leikwan-android/app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt
 M leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunSetupPlanner.kt
 M leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt
 M leikwan-android/app/src/main/java/com/leikwan/bridge/ui/login/LoginScreen.kt
 M leikwan-android/app/src/main/java/com/leikwan/bridge/ui/login/LoginViewModel.kt
 M leikwan-android/app/src/test/java/com/leikwan/bridge/service/LeikwanVpnServiceTest.kt
 M leikwan-android/app/src/test/java/com/leikwan/bridge/service/TunSetupPlannerTest.kt
 M leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt
?? GFRClogo.png
?? docs/2026-05-31-android-direct-vpn-still-bad-handoff.md
?? docs/superpowers/plans/2026-05-29-windows-app-workflow-parity.md
?? docs/superpowers/specs/2026-05-29-windows-app-workflow-parity-design.md
?? leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelErrorParser.kt
?? leikwan-android/app/src/main/java/com/leikwan/bridge/net/
?? leikwan-android/app/src/test/java/com/leikwan/bridge/api/ClientSelectionResponseTest.kt
?? leikwan-android/app/src/test/java/com/leikwan/bridge/api/LoginResponseTest.kt
?? leikwan-android/app/src/test/java/com/leikwan/bridge/api/PanelErrorParserTest.kt
?? leikwan-android/app/src/test/java/com/leikwan/bridge/net/
```

Tracked modified files from `git diff --name-only`:

```text
LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/EndpointConfig.cs
LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondEndpointHealthService.cs
LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondEndpointHealthServiceTests.cs
artifacts/windows/leikwan-bridge-setup.exe
leikwan-android/app/build.gradle.kts
leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelApi.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelModels.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/proxy/SubscriptionFetcher.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunSetupPlanner.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/ui/login/LoginScreen.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/ui/login/LoginViewModel.kt
leikwan-android/app/src/test/java/com/leikwan/bridge/service/LeikwanVpnServiceTest.kt
leikwan-android/app/src/test/java/com/leikwan/bridge/service/TunSetupPlannerTest.kt
leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt
```

Untracked files from `git ls-files --others --exclude-standard`:

```text
GFRClogo.png
docs/2026-05-31-android-direct-vpn-still-bad-handoff.md
docs/superpowers/plans/2026-05-29-windows-app-workflow-parity.md
docs/superpowers/specs/2026-05-29-windows-app-workflow-parity-design.md
leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelErrorParser.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/net/AppDns.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/net/DohEndpoints.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/net/FallbackDns.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/net/MirrorNoSni.kt
leikwan-android/app/src/test/java/com/leikwan/bridge/api/ClientSelectionResponseTest.kt
leikwan-android/app/src/test/java/com/leikwan/bridge/api/LoginResponseTest.kt
leikwan-android/app/src/test/java/com/leikwan/bridge/api/PanelErrorParserTest.kt
leikwan-android/app/src/test/java/com/leikwan/bridge/net/AppDnsTest.kt
leikwan-android/app/src/test/java/com/leikwan/bridge/net/DohEndpointsTest.kt
leikwan-android/app/src/test/java/com/leikwan/bridge/net/FallbackDnsTest.kt
leikwan-android/app/src/test/java/com/leikwan/bridge/net/MirrorNoSniTest.kt
```

Relevant Android VPN diff stat only:

```text
 .../leikwan/bridge/service/LeikwanVpnService.kt    | 135 ++++++++++++++++++++-
 .../com/leikwan/bridge/service/TunSetupPlanner.kt  |  11 +-
 .../leikwan/bridge/singbox/SingboxConfigBuilder.kt |  28 +++--
 .../leikwan/bridge/service/TunSetupPlannerTest.kt  |  18 +++
 .../bridge/singbox/SingboxConfigBuilderTest.kt     |  75 +++++++++++-
 5 files changed, 248 insertions(+), 19 deletions(-)
```

Important file-state notes:

- The checkout is **not clean** and contains unrelated dirty work outside Android VPN debugging.
- Do not commit the whole tree blindly.
- Android VPN/direct-mode code changes are concentrated in:
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt`
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunSetupPlanner.kt`
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`
  - matching tests under `leikwan-android/app/src/test/java/com/leikwan/bridge/...`
- This handoff file is currently untracked:
  - `docs/2026-05-31-android-direct-vpn-still-bad-handoff.md`
- Temporary instrumentation-test source files used for ADB seeding were removed before this handoff.
- The installed Android test package was uninstalled; only target package `com.leikwan.bridge` should remain installed.
- Repo-root temp artifacts matching `.codex-*`, `.codex-*` directories, `.codex-tmp`, `.codex-tools`, `.codex-libbox*`, and `.tmp` were deleted after this handoff was created.

## Current outcome

Android Direct VPN mode is **still not fixed**.

One concrete runtime bug was found and patched:

- Chrome / Google traffic was entering sing-box as QUIC/UDP.
- sing-box failed sending an oversized UDP packet through Shadowsocks:
  - `write udp 192.168.1.15:...->47.100.39.54:30522: write: message too long`
- The generated TUN inbound now has `mtu: 1280`.

After the user re-approved VPN, the app is running with the MTU fix, but browsing is still bad:

- `dumpsys vpn_management` shows active VPN type `1`.
- live config contains `mtu: 1280`.
- current logs no longer center on `message too long`; they show timeouts / no useful downlink.
- sing-box status repeatedly shows uplink bytes but `down=0`.

Current remaining root-cause direction:

> Android sing-box/libbox outbound sessions through the proxy appear to time out / receive no downlink even though the VPN is active and the TUN MTU is reduced.

## Current device/runtime state

Latest observed `appops` after user approval:

```text
ACTIVATE_VPN: allow
START_FOREGROUND: allow (running)
ESTABLISH_VPN_SERVICE: allow (running)
```

Latest observed VPN state:

```text
Active package name: com.leikwan.bridge
Active vpn type: 1
sessionId=LeiKwan Bridge
OwnerUid: 10324
Uids: {0-10323, 10325-20323, 20325-99999}
UnderlyingNetworks: [388]
```

The owner UID is excluded from the captured UID ranges, so the app/libbox process itself should bypass the VPN.

Latest live TUN inbound from `files/latest-singbox-config.json`:

```json
{
  "type": "tun",
  "tag": "tun-in",
  "address": ["172.19.0.1/30", "fdfe:dcba:9876::1/126"],
  "auto_route": true,
  "strict_route": true,
  "stack": "mixed",
  "mtu": 1280
}
```

Latest still-bad log pattern after VPN was active:

```text
LeikwanVpnService: box conn ... network=udp dest=play-fe.googleapis.com:443 ... outbound=proxy-1 chain=[proxy-1]
Finsky: Self-update check failed ... net::ERR_CONNECTION_TIMED_OUT
LeikwanVpnService: box status in=0 out=2 up=2464 down=0
AuthPII: getToken() -> NETWORK_ERROR
ServerPush: failed to connect ... from /172.19.0.1 ... ETIMEDOUT
```

Interpretation: traffic enters the VPN/sing-box path, but usable response traffic is not coming back.

## Android app structure

Important source paths:

```text
leikwan-android/app/src/main/java/com/leikwan/bridge/ui/MainActivity.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModel.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunSetupPlanner.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/data/Prefs.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/data/AppDatabase.kt
leikwan-android/app/src/main/java/com/leikwan/bridge/data/ProxyNodeDao.kt
```

Runtime flow:

1. `MainActivity`
   - Handles Android VPN consent via `VpnService.prepare(...)`.
   - Shows `LoginScreen` or `MainScreen`.

2. `TunnelsViewModel`
   - UI state for selected tunnel/mode.
   - Calls start/stop logic.

3. `TunnelOrchestrator`
   - Reads `Prefs` and Room DB proxy nodes.
   - In Services mode, pulls forward/contract info and starts local forward runtime.
   - In Direct mode, uses the selected proxy directly.
   - Builds routing snapshot.
   - Calls `SingboxConfigBuilder`.
   - Writes `files/latest-singbox-config.json`.
   - Sets `LeikwanVpnService.configJson`.
   - Starts `LeikwanVpnService`.

4. `SingboxConfigBuilder`
   - Builds the sing-box JSON:
     - `dns`
     - `inbounds` with TUN
     - proxy/direct `outbounds`
     - `route`

5. `LeikwanVpnService`
   - Android `VpnService`.
   - Implements libbox `PlatformInterface`.
   - Calls Android `VpnService.Builder` to establish the TUN fd.
   - Owns `protect(fd)` callback.
   - Starts libbox `CommandServer`.
   - Current dirty version also starts a diagnostic `CommandClient`.

6. `TunSetupPlanner`
   - Converts libbox `TunOptions` into Android builder plan:
     - addresses
     - routes
     - DNS
     - disallowed packages

## Code changes made so far

Current relevant dirty files:

```text
M leikwan-android/app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt
M leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunSetupPlanner.kt
M leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt
M leikwan-android/app/src/test/java/com/leikwan/bridge/service/TunSetupPlannerTest.kt
M leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt
```

### `LeikwanVpnService.kt`

Changes made:

- Added libbox `CommandClient` diagnostics:
  - logs
  - connection events
  - status counters
- Added package exclusion:
  - `builder.addDisallowedApplication(packageName)`
- Enabled platform interface auto-detect control:
  - `usePlatformAutoDetectInterfaceControl() = true`
- `autoDetectInterfaceControl(fd)` calls Android `protect(fd)`.

Purpose:

- Avoid VPN self-capture for libbox outbound sockets.
- Get enough runtime visibility to see whether traffic enters TUN and which outbound is used.

### `TunSetupPlanner.kt`

Changes made:

- `TunSetupPlan` now includes `disallowedPackageNames`.
- `plan(...)` accepts `ownerPackageName`.
- The app package is returned as a disallowed package.

Purpose:

- Keep `com.leikwan.bridge` out of its own VPN capture set.

### `SingboxConfigBuilder.kt`

Changes made:

- Removed route-level `auto_detect_interface`.
- DNS server construction changed:
  - if active proxy exists, remote DNS is TCP and detoured through the proxy.
  - if no active proxy exists, remote DNS stays UDP with no detour.
- Direct-mode proxy hostnames are put into bootstrap domains resolved by direct DNS.
- TUN inbound now has:

```json
"mtu": 1280
```

Purpose:

- Avoid DNS/proxy bootstrap loops.
- Avoid large Android/Chrome UDP packets causing `message too long` on Shadowsocks UDP encapsulation.

## Verification performed

### Unit/build verification

Commands run:

```powershell
cd C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android
./gradlew.bat :app:testDebugUnitTest --tests com.leikwan.bridge.singbox.SingboxConfigBuilderTest
./gradlew.bat :app:testDebugUnitTest :app:assembleDebug
```

Result:

```text
BUILD SUCCESSFUL
```

### TDD for MTU regression

Added test:

```kotlin
fun `tun inbound uses mobile safe mtu to avoid oversized udp proxy packets`() {
    ...
    assertEquals(1280, tunInbound.get("mtu").asInt)
}
```

Red state:

- Failed before implementation because `tunInbound.get("mtu")` was null.

Green state:

- Passed after adding `addProperty("mtu", 1280)` in `buildInbounds()`.

### Host-side proxy smoke

Host-side sing-box using the same Shadowsocks config returned Google HTTP 204:

```text
HTTP/1.1 204 No Content
```

This suggests the proxy credentials/server can work outside the Android TUN/libbox path.

### Android app UID underlay test, before the app reinstall incident

Earlier, from the app UID / owner-excluded path:

```text
APP_PROXY_EXIT:0
SHELL_PROXY_EXIT:1
nc: Timeout
```

Interpretation at that time:

- App UID could directly reach `47.100.39.54:30522`.
- Shell UID, captured by VPN, timed out.

This test should be repeated now after the latest app reinstall/approval.

## Things tried that did not solve it

1. Only adding `protect(fd)`.
   - Necessary, but not sufficient.

2. Only removing `route.auto_detect_interface`.
   - Necessary / cleaner, but not sufficient.

3. DNS detour/bootstrap hardening.
   - DNS queries resolve through proxy, but browsing still times out.

4. Reducing TUN MTU to `1280`.
   - Removed/mitigated the observed `message too long` direction.
   - Still not enough; current failure is timeout / no downlink.

5. Relying on Chrome tests only.
   - Chrome adds QUIC, GMS, cached tabs, and background noise.
   - Need a controlled non-Chrome TCP test through a captured UID.

## Incident during testing

I ran:

```powershell
./gradlew.bat :app:connectedDebugAndroidTest ...
```

Bad side effect:

- Gradle uninstalled the target app afterward.
- This wiped Android VPN consent and app data.

Mitigation performed:

- Reinstalled debug APK.
- Restored proxy DB from `.codex-databases-leikwan_bridge.db`.
- Seeded Direct mode / selected proxy through a temporary instrumentation test.
- Removed the temporary instrumentation test source afterward.
- Uninstalled `com.leikwan.bridge.test`.
- User/VPN approval was redone afterward; latest `appops` shows VPN allow/running.

Important: if the UI login state looks wrong, this app-data wipe is why. The restored state was focused on Direct mode proxy availability, not a full login/session restore.

## Local artifacts after cleanup

Repo-root temp artifacts were deleted after this handoff was created. The previous `.codex-*` evidence files are no longer present in the worktree.

If more runtime evidence is needed, regenerate fresh artifacts using the commands in the next section instead of relying on old `.codex-*` files.

## Next recommended investigation

Do **not** make more code changes first. Prove the current failing boundary.

### Step 1 — clean controlled reproduction

Run:

```powershell
$adb = ".\platform-tools\adb.exe"
& $adb logcat -c
```

Then trigger only one test:

- preferably not Chrome first.
- use a controlled captured UID test app or an existing non-excluded app.
- force TCP if possible.

Capture:

```powershell
& $adb logcat -d -v threadtime > .codex-next-controlled-logcat.txt
& $adb shell dumpsys vpn_management > .codex-next-vpn.txt
& $adb shell dumpsys connectivity > .codex-next-connectivity.txt
& $adb shell run-as com.leikwan.bridge cat files/latest-singbox-config.json > .codex-next-live-config.json
```

### Step 2 — repeat the app-UID proxy reachability test

This confirms whether the owner-excluded app UID can still reach the proxy directly:

```powershell
$adb = ".\platform-tools\adb.exe"
& $adb shell "run-as com.leikwan.bridge sh -c 'echo x | nc -4 -w 5 47.100.39.54 30522 >/dev/null; echo APP_PROXY_EXIT:`$?'"
```

Expected if underlay/proxy is reachable:

```text
APP_PROXY_EXIT:0
```

If this now fails, the current issue is not TUN routing; it is the app UID underlay/proxy reachability.

### Step 3 — prove Android libbox TCP path separately from QUIC

Need a TCP-only captured flow.

Options:

- install/use a tiny debug test app with its own UID not excluded from VPN, then make an HTTPS request.
- or use Chrome with QUIC disabled if possible.
- or use a simple app already on the phone that makes TCP HTTP and is captured by VPN.

Need to observe in logs:

```text
inbound/tun[tun-in]: inbound connection to <target>:443
outbound/shadowsocks[proxy-1]: outbound connection to <target>:443
connection download/downlink > 0
```

If TCP through TUN also has `down=0`, the issue is Android libbox outbound/proxy path.

### Step 4 — if TCP works, handle QUIC/UDP

If TCP works but Chrome/Google still fails, add an explicit QUIC policy:

- reject/block UDP/443 to force TCP fallback; or
- configure UDP-over-TCP if compatible with this Shadowsocks server; or
- further tune UDP/MTU.

Do this only after TCP path is proven.

### Step 5 — if TCP does not work

Compare Android live config against the host sing-box config that returns HTTP 204.

Focus differences:

- Android TUN path vs host mixed inbound.
- Android `protect(fd)` actually being called for outbound TCP sockets.
- Android owner package exclusion and UID ranges.
- libbox mobile stack behavior with Shadowsocks 2022.
- whether outbound sockets are bound to the wrong network/interface.

## Current likely diagnosis

Previous bug:

```text
Oversized UDP/QUIC packet due TUN MTU 9000 -> Shadowsocks UDP write "message too long"
```

Current unresolved bug:

```text
VPN active + MTU fixed, but Android sing-box outbound sessions still time out / receive no downlink.
```

Most likely remaining areas:

1. Android libbox outbound socket binding/protect behavior.
2. Shadowsocks 2022 UDP/TCP behavior specifically on Android.
3. VPN-captured app traffic reaching proxy outbound but response packets not returning to the TUN app.
4. Chrome/Google QUIC still causing noise, but current logs suggest broader timeout/no-downlink, so prove TCP first.

## Do not repeat

- Do not run Gradle `connectedDebugAndroidTest` unless you explicitly accept app uninstall/data/consent reset behavior.
- Do not chase login/config-refresh state as the browsing root cause unless the Direct-mode selected proxy is missing.
- Do not assume Chrome equals clean network evidence; Chrome is noisy due QUIC/GMS/background requests.
- Do not claim success without live browsing or a controlled captured-UID HTTP success.

---

# INVESTIGATION UPDATE — 2026-05-31 (continued session) — ROOT CAUSE FOUND

This section supersedes the "Current likely diagnosis" above. A controlled
on-device reproduction isolated the failure to the **sing-box TUN stack**, not
DNS, not MTU, not protect(), not routing.

## TL;DR

- The live TUN inbound uses `"stack": "mixed"`.
- In sing-box, `mixed` = **system TCP stack + gVisor UDP stack**.
- The **system TCP stack does not work inside an unprivileged Android
  `VpnService`** (it needs kernel-level socket redirection / NET_ADMIN it does
  not have). The **gVisor UDP stack does work**.
- Result, exactly matching observations: **UDP/DNS works, every TCP connection
  is silently never accepted by the TUN stack** → all browsing/auth times out.
- Fix direction: set `"stack": "gvisor"` (what sing-box-for-android / NekoBox
  use). `SingboxConfigBuilder.kt:137`.

> STATUS: **FIXED AND VERIFIED ON-DEVICE.** `SingboxConfigBuilder.kt:137`
> changed `mixed` → `gvisor`; rebuilt, reinstalled (`install -r`, consent +
> Direct-mode proxy DB preserved), reconnected in Direct mode, and the same
> tests that timed out now succeed. See "VERIFICATION — FIX CONFIRMED" below.

## Method — controlled reproduction (live device)

Device is connected and VPN is active: OnePlus 9 Pro `LE2123`, Android 14,
`adb` transport_id 3, VPN type 1, owner UID `10324` excluded, underlying
network `388` (`wlan0`, `192.168.1.15`).

Shell UID is `2000`, which **is inside the captured ranges** (`0-10323`), so
`adb shell curl ...` traffic is captured by the VPN — this is the clean
controlled captured-UID test the previous handoff asked for. `curl`/`nc` exist
on-device.

### Test 1 — captured-UID TCP, with and without DNS

```text
curl http://connectivitycheck.gstatic.com/generate_204  -> HTTP=000 TIME=12.0 REMOTE=  EXIT=28 (timeout)
curl https://cp.cloudflare.com/generate_204             -> HTTP=000 TIME=12.0 REMOTE=  EXIT=28 (timeout)
curl https://1.1.1.1/   (literal IP, NO DNS)            -> HTTP=000 TIME=15.0 REMOTE=  EXIT=28 (timeout)
curl http://1.1.1.1/    (literal IP, NO DNS)            -> HTTP=000 TIME=15.0 REMOTE=  EXIT=28 (timeout)
```

`REMOTE` empty even for a literal-IP target ⇒ the TCP connection never even
established (no `connect()` completion). This kills the earlier "QUIC/UDP only"
and "DNS bootstrap" theories: **plain TCP to a literal IP fails too.**

### Test 2 — underlay + proxy are fine (control)

```text
app-UID (10324, excluded) direct TCP to proxy 47.100.39.54:30522 -> APP_PROXY_EXIT:0  (works)
host-side sing-box, same SS config                               -> Google HTTP 204    (works)
```

### Test 3 — sing-box debug log during `curl https://1.1.1.1/`

`log.level=debug`. Counted over the buffer for that single curl:

```text
"inbound connection to"        (TCP stream) : 0
"inbound packet connection to" (UDP packet) : 3   <- all are DNS to 172.19.0.2:53
"outbound connection to ... :443"           : 0
```

DNS-over-proxy works repeatedly and fast:

```text
dns: exchange <name> IN A
outbound/shadowsocks[proxy-1]: outbound connection to 1.1.1.1:53
dns: exchanged <name> NOERROR  (~135-175ms)
```

So a **TCP connection through the Shadowsocks proxy to 1.1.1.1:53 succeeds and
returns data** — the proxy/outbound/SS-2022 path is healthy. But a captured
app's TCP connection to `1.1.1.1:443` **never produces an
`inbound connection`** in sing-box at all.

### Test 4 — routing is correct (rules out route-install bug)

```text
ip addr: tun0 = 172.19.0.1/30 , fdfe:dcba:9876::1/126
ip route show table all: "default dev tun0 table 1053"  (full default route into TUN exists)
ip route get 1.1.1.1 uid 2000  (captured shell) -> "1.1.1.1 dev tun0 table 1053"   (into TUN, correct)
ip route get 1.1.1.1 uid 10324 (excluded app)   -> "via 192.168.1.1 dev wlan0"     (bypass, correct)
```

The SYN for `1.1.1.1:443` from the captured shell **provably enters `tun0`**,
i.e. it reaches sing-box's tun fd — yet the stack never accepts it. Therefore
the failure is **inside sing-box's TCP stack handling**, not Android routing,
not protect(), not the proxy.

## Why `mixed` breaks TCP on Android

sing-box `inbound.tun.stack`:

- `gvisor` — full userspace netstack; reads/writes packets directly on the tun
  fd; implements TCP+UDP in userspace. Works in an unprivileged app. **This is
  what sing-box-for-android / NekoBox use.**
- `system` — uses the host kernel TCP/IP stack; needs to redirect TCP to a
  local listener (TPROXY/redirect) which requires NET_ADMIN/root. **Does not
  work in an unprivileged Android VpnService.**
- `mixed` — **system TCP + gVisor UDP.** ⇒ UDP works, TCP dead. Exactly the
  symptom.

(Reference confirmation of SFA's stack choice + PlatformInterface method is
being validated in parallel; will be appended.)

## Misleading signals to ignore

- `NetworkMonitor/388 ... www.google.us ... SocketTimeoutException from
  /192.168.1.15` — that is the OS connectivity probe hitting the **bare WiFi**
  (which is behind the GFW and cannot reach Google directly). It is NOT the VPN
  path and NOT the bug. OnePlus's own probes (`allawnos.com`) succeed directly,
  confirming WiFi has internet.
- `box status in=0 out=0 up=N down=M` — `in`/`out` are *active connection
  counts* (0 = nothing currently proxied), `up`/`down` are cumulative. The
  small `down` is just DNS answers.
- `icmp is not supported by default outbound: proxy-1` — normal (Shadowsocks
  can't carry ICMP/ping). Not the bug.

## Confirming test (next action — not yet done)

Minimal single-variable fix to verify the root cause:

1. `SingboxConfigBuilder.kt:137`: `"stack": "mixed"` → `"stack": "gvisor"`.
2. Rebuild `:app:assembleDebug`, reinstall (do NOT use
   `connectedDebugAndroidTest` — it uninstalls the app / wipes consent).
3. Re-approve VPN, then re-run the captured-UID test:
   `adb shell curl -4 --max-time 12 https://1.1.1.1/`
4. PASS criteria: sing-box logs `inbound connection to 1.1.1.1:443` AND
   `outbound/shadowsocks[proxy-1]: outbound connection to 1.1.1.1:443`, curl
   returns an HTTP status (not 000), and `box status` `down` climbs.

## Config-builder hygiene bugs noticed (secondary)

Live config contains **duplicated rules** (the config builder emits each twice):

- `dns.rules` / `route.rules`: `v3-mirror.leikwanhost.com` direct rule appears
  twice.
- `route.rules`: the private-CIDR `direct` rule appears twice (with the CIDRs
  in different order).

Harmless to function but indicates double-application in `SingboxConfigBuilder`.
Worth fixing while in there. (Full code sweep for additional post-fix issues —
MTU 1280 vs SFA's 9000, missing `endpoint_independent_nat` for UDP/QUIC over SS,
IPv6 tun address with no IPv6 underlay — is in progress and will be appended.)

## Handoff completeness note (answering "does it include all android things?")

**No.** The original handoff is correctly scoped to the Direct-VPN trio
(`LeikwanVpnService` / `TunSetupPlanner` / `SingboxConfigBuilder`) but **omits**:

- The whole **control-plane change set** that is also dirty in this branch:
  `api/PanelApi.kt`, `api/PanelModels.kt`, `api/PanelRepository.kt`,
  `api/PanelErrorParser.kt` (new), `proxy/SubscriptionFetcher.kt`,
  `ui/login/LoginScreen.kt`, `ui/login/LoginViewModel.kt`, and the new
  `net/` DoH package (`AppDns`, `DohEndpoints`, `FallbackDns`, `MirrorNoSni`)
  plus their tests. These implement login/subscription over DoH to bypass GFW
  DNS poisoning and are unrelated to the browsing-timeout root cause, but they
  are real uncommitted Android work the handoff doesn't mention.
- Several **`service/` classes** that exist but aren't in the
  "Android app structure" list: `UnderlyingNetworkSelector.kt`,
  `VpnStartupMonitor.kt`, `ProxyConnectionMode.kt`, `ProxySelectionPolicy.kt`.

(A precise per-file audit is in progress and will be appended.)

## Independent code verification (everything except the stack is correct)

Read in full this session to rule out other causes:

- `LeikwanVpnService.kt` — `openTun()` applies addresses, **routes
  (`0.0.0.0/0` via `TunSetupPlanner` fallback / libbox `inet4RouteAddress`)**,
  DNS, `setUnderlyingNetworks(resolveUnderlyingNetwork())`, and
  `addDisallowedApplication(packageName)`. `autoDetectInterfaceControl(fd)` →
  `protect(fd)`, `usePlatformAutoDetectInterfaceControl()=true`. All correct.
  **`protect()` is proven working on-device**: the Shadowsocks outbound reaches
  `1.1.1.1:53` and the proxy server returns DNS data — impossible unless the
  outbound socket is protected and bound to the underlay.
- `TunSetupPlanner.kt` — `plan()` returns libbox routes when `autoRoute` &&
  non-empty, else `0.0.0.0/0` + `::/0`; `bindActiveNetwork=true`. DNS server is
  the libbox-provided tun address (`172.19.0.2`). Matches the correct device
  routing observed. Correct.
- `SingboxConfigBuilder.kt` — single defect: `stack="mixed"` (line 137). MTU
  1280 (line 138). Duplicate rules originate from `safePathRegistry` protected
  entries (route step 1) overlapping the `bypassLan` toggle (route step 4) and
  the panel/mirror domain appearing in two sources — cosmetic.

Conclusion: protect / routes / DNS / underlying-network are all correct and
proven working. The **only** thing preventing TCP is the system-TCP stack
selected by `stack="mixed"`.

## Authoritative stack semantics (resolved an adversarial challenge)

A skeptical review claimed `mixed` = "gVisor TCP + system UDP" (which would make
the fix a no-op). The **official sing-box documentation** settles it:

> `stack`:
> - `system`: Perform L3↔L4 translation using the system network stack.
> - `gvisor`: Perform L3↔L4 translation using gVisor's virtual network stack.
> - `mixed`: **Mixed `system` TCP stack and `gvisor` UDP stack.**
>
> — https://sing-box.sagernet.org/configuration/inbound/tun/

So `mixed` uses the **system stack for TCP**. The system stack needs kernel
socket redirection (NET_ADMIN) the unprivileged VpnService does not have →
TCP dead; gVisor handles UDP → DNS works. Reinforcing logic: gVisor-TCP-over-tun
is the most battle-tested path in sing-box-for-android / NekoBox / v2rayNG
(millions of installs), so it cannot be "broken" — the broken half under `mixed`
must be the system-TCP path. `gvisor` routes TCP through the proven gVisor
engine. The on-device verification below confirms this empirically.

## VERIFICATION — FIX CONFIRMED (2026-05-31, same device, gvisor build)

Applied `SingboxConfigBuilder.kt:137` `mixed` → `gvisor`, `./gradlew.bat
:app:assembleDebug` (BUILD SUCCESSFUL), `adb install -r` (consent + Direct
proxy preserved), reconnected Direct mode. Live config confirmed `stack=gvisor`,
`mtu=1280`. Same captured-UID tests as the failure repro:

```text
                                              BEFORE (mixed)        AFTER (gvisor)
curl https://1.1.1.1/  (v4 literal, no DNS)   EXIT28 REMOTE empty   HTTP=301 0.26s REMOTE=1.1.1.1 EXIT0
curl https://cp.cloudflare.com/generate_204   timeout              HTTP=204 0.37s EXIT0
curl https://www.google.com/generate_204      timeout (GFW)        HTTP=204 0.41s EXIT0  <- via proxy
sing-box "inbound connection to" (TCP)        0                    3  (1.1.1.1:443, 104.16.133.229:443, 142.251.153.119:443)
sing-box "outbound shadowsocks[proxy-1] :443" 0                    3
box status                                    up>0 down=0          in=38 out=38 up=1.7MB down=3.8MB (climbing)
```

`www.google.com` — blocked by the GFW on the bare WiFi — is now reachable
**through the proxy**, i.e. the intended product behavior. The single one-line
stack change resolves the entire browsing failure. Direct VPN mode works.

## Remaining issues AFTER the stack fix (prioritized — none block basic browsing)

Confirmed by a parallel code sweep. Browsing/auth now work; these improve
robustness/throughput and should be done as **separate** changes (one at a time,
re-verify each):

1. **`endpoint_independent_nat` absent on the tun inbound** (high). With gVisor
   now the only UDP stack, full-cone NAT matters for QUIC/HTTP3/WebRTC. SFA sets
   it true. `SingboxConfigBuilder.kt:126-139` → add
   `endpoint_independent_nat: true`.
2. **IPv6 tun address + v6 default route on a v4-only underlay** (high). The tun
   always adds `fdfe:dcba:9876::1/126` and (via auto_route) a v6 default route,
   but wlan0 (`192.168.1.15`, net 388) has no IPv6 → AAAA-preferring apps send
   v6 SYNs into a blackhole (happy-eyeballs stalls). Either drop the v6 tun
   addr/route when the underlay is v4-only, or force `dns.strategy` to
   `prefer_ipv4`. `SingboxConfigBuilder.kt:133`, `TunSetupPlanner.kt:47-51`,
   `LeikwanVpnService.kt:202,204`. (Current dns.strategy is `prefer_ipv4` in the
   live config, which mitigates DNS-produced v6 but not v6-literal/route.)
3. **`mtu=1280` vs SFA's `9000`** (medium). 1280 (v6 minimum) throttles
   throughput; gVisor handles a large tun MTU and the egress MSS-clamps. Raise to
   `9000` after the stack fix. `SingboxConfigBuilder.kt:138`.
4. **Bare `action: sniff`** with no `override_destination`/sniffers (medium).
   Weakens domain-based v2 country/category rule_set routing; IP-only flows fall
   to `route.final`. `SingboxConfigBuilder.kt:294-296`.
5. **Duplicate route/DNS rules** (low, cosmetic). `v3-mirror` domain emitted in
   both `dns.rules` (via `panelDirectDomains()`) and `route.rules` (via
   `SafePathRegistry` protected entries); private-CIDR direct rule emitted by
   both protected entries and the `bypassLan` block. First-match-wins, harmless;
   `panelDirectDomains()` route injection is now redundant.
   `SingboxConfigBuilder.kt:108-113, 306-320, 361-371`.
6. **No default-interface monitor** (medium). `startDefaultInterfaceMonitor`/
   `getInterfaces`/`readWIFIState` are stubs (`LeikwanVpnService.kt:228-238`), so
   libbox cannot self-heal on Wi-Fi↔cellular handoff (`protect()` may bind stale
   fds). Fine for static WiFi testing; implement for real-world resilience.

## Handoff completeness audit (full answer to "does it include all android things?")

The original handoff is correctly scoped to the **data-plane Direct-VPN trio**
but does **not** cover the rest of the branch's Android work. Omitted:

**Control-plane DoH/login overhaul (orthogonal to the browsing bug — runs
outside the TUN, over OkHttp):**
- `net/AppDns.kt`, `net/DohEndpoints.kt`, `net/FallbackDns.kt` — OkHttp `.dns()`
  DoH-first resolution (AliDNS → DNSPod → 360, system fallback) so GFW UDP/53
  poisoning can't redirect login/subscription.
- `net/MirrorNoSni.kt` — SNI-free, IP-pinned (Photon edge `23.147.52.x`),
  cert-pinned (leaf SHA-256) connection to `v3-mirror.leikwanhost.com` to defeat
  SNI-based TLS resets; wired in `PanelRepository.buildApi`.
- `api/PanelApi.kt` (`login()` → `Response<LoginResponse>`, new
  `selectClient()` POST `api/client/select`), `api/PanelModels.kt`
  (nullable login fields, `ClientSelectionRequest/Response`, `agent_token`),
  `api/PanelRepository.kt` (~140 lines: two-phase login→select,
  `persistSelectedClient` stores `agent_token` as `prefs.token`, 20s timeout),
  `api/PanelErrorParser.kt` (new).
- `proxy/SubscriptionFetcher.kt` (uses `AppDns.shared()` DoH),
  `ui/login/LoginViewModel.kt` + `LoginScreen.kt` (client-picker state machine,
  error dialog).
- `build.gradle.kts`: added `okhttp-dnsoverhttps:4.12.0` (only build change).
- New tests: `api/ClientSelectionResponseTest`, `api/LoginResponseTest`,
  `api/PanelErrorParserTest`, `net/AppDnsTest`, `net/DohEndpointsTest`,
  `net/FallbackDnsTest`, `net/MirrorNoSniTest`.

**`service/` classes omitted from the handoff's "Android app structure":**
- `UnderlyingNetworkSelector.kt` — picks the non-VPN INTERNET network fed to
  `setUnderlyingNetworks()` (data-plane relevant; was implicated in the old
  "bound to wrong network" theory — now exonerated).
- `VpnStartupMonitor.kt` — polls `VpnStartupState`, awaited by
  `TunnelOrchestrator` during Direct startup (start/timeout boundary).
- `ProxyConnectionMode.kt` — `SERVICES` vs `DIRECT` enum gating the whole code
  path (defines what "Direct VPN mode" is).
- `ProxySelectionPolicy.kt` — validates the selected proxy before startup.
- `TunnelOrchestrator.kt` — listed as a path but never stated whether modified;
  it builds the routing snapshot, calls `SingboxConfigBuilder`, writes
  `latest-singbox-config.json`, and starts the service.

Relevance to the browsing failure: **LOW** — all control-plane/OkHttp, outside
the libbox TUN data path. None of it caused the timeout. Caveat: it can affect
how login/session looks on the device (compounded by the earlier app-data-wipe
incident), which a future agent could mistake for a browsing failure — the
existing "Do not chase login/config-refresh state" note partially covers this.

