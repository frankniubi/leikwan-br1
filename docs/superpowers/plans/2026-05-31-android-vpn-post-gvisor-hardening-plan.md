# Android VPN Post-gvisor Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the three remaining data-plane hardening fixes to the Leikwan Android sing-box client — after the confirmed `stack: "mixed"`→`"gvisor"` root-cause fix — so UDP/QUIC, IPv6, and throughput behave correctly without regressing the now-working TCP path.

**Architecture:** All three changes are localized to the single sing-box TUN inbound emitted by `SingboxConfigBuilder.buildInbounds()`. libbox parses that JSON and drives `LeikwanVpnService.openTun()`; no Kotlin data-path hook exists, so the config object is the only lever. Each change is gated by a unit test against the generated config JSON and then confirmed on a real device with the captured-UID reproduction harness.

**Tech Stack:** Kotlin, Gson (`JsonObject`/`JsonArray`), JUnit4 (`SingboxConfigBuilderTest`), Gradle (`gradlew.bat`), `io.nekohasekai.libbox` (official sing-box gomobile), Android `VpnService`, `adb`.

---

## Background (read before starting)

Root cause already fixed and verified on-device (OnePlus 9 Pro `LE2123`, Android 14): `SingboxConfigBuilder.kt` previously set `"stack": "mixed"` (= **system TCP + gVisor UDP** per sing-box docs). The system TCP stack cannot work in an unprivileged `VpnService`, so every TCP flow timed out while UDP/DNS worked. It is now `"gvisor"`. Full evidence and method: `docs/2026-05-31-android-direct-vpn-still-bad-handoff.md`.

These three tasks are the explicitly-deferred follow-ups from that handoff. **None of them blocks basic browsing** (which works now); they fix UDP/QUIC NAT behavior, an IPv6 blackhole, and throughput. They are ordered so the riskiest/throughput change (MTU) comes last and is verified against the UDP behavior fixed in Task 1.

---

## Guidelines (the fix-plan discipline — follow for every task)

These rules exist because the previous multi-session debug thrashed by changing several things at once and by using a Gradle command that wiped device state. Do not repeat that.

1. **One variable at a time.** Each task changes exactly one config property (plus its test). Build, deploy, and re-verify on-device before starting the next task. If a task regresses TCP browsing, revert that task before continuing.
2. **Never regress the gvisor TCP fix.** After every task, re-run the TCP smoke (`curl https://1.1.1.1/` from a captured UID must return a non-`000` HTTP code, and sing-box must log `inbound connection to 1.1.1.1:443`). This is the canary; if it breaks, stop.
3. **Unit test is the hard gate; device test is the truth.** Every config change gets a red→green JUnit test in `SingboxConfigBuilderTest` first (TDD). The unit test proves the JSON shape; the on-device test proves real traffic. Both are required to mark a task done.
4. **Scope discipline — do not commit the unrelated dirty tree.** The branch has unrelated dirty control-plane work (DoH/login/panel) and the desktop `.cs` changes. Stage and commit **only** `SingboxConfigBuilder.kt` and `SingboxConfigBuilderTest.kt` per task. Never `git add -A` / `git add .`.
5. **Deploy with `adb install -r` only.** It preserves VPN consent (the `ACTIVATE_VPN` appop) and the restored Direct-mode proxy DB. **Never run `./gradlew.bat :app:connectedDebugAndroidTest`** — it uninstalls the app and wipes consent + data (documented incident in the handoff).
6. **Reconnect path after each install (Direct mode):** open the app → **Proxies** tab → ensure **Direct** is selected → tap the connect toggle on the selected Shadowsocks proxy card. The VPN start regenerates `files/latest-singbox-config.json` from the new code; always confirm the new property landed by reading that file with `run-as`.
7. **Mirror the reference client.** When unsure of a default, match sing-box-for-android (SFA) / NekoBox, which use the same `io.nekohasekai.libbox`. SFA defaults: `stack=gvisor`, `endpoint_independent_nat=true`, `mtu=9000`.
8. **Reproduction harness (the standard verification tool).** Shell UID `2000` is captured by the VPN, so `adb shell curl ...` is a clean captured-UID test. `curl`/`nc` exist on-device. The canonical sequence is in each task's "Verify on-device" step.

### Per-task Definition of Done

- [ ] New/updated unit test fails first (red) for the right reason, then passes (green).
- [ ] `./gradlew.bat :app:testDebugUnitTest --tests com.leikwan.bridge.singbox.SingboxConfigBuilderTest` passes (whole class).
- [ ] `./gradlew.bat :app:assembleDebug` → BUILD SUCCESSFUL.
- [ ] `adb install -r` succeeds; `ACTIVATE_VPN: allow` still present.
- [ ] After reconnect, `files/latest-singbox-config.json` shows the new property value.
- [ ] TCP canary still passes (Guideline 2).
- [ ] The task-specific on-device check passes.
- [ ] Commit touches only the two sing-box files.

---

## File Structure

Only two files change across all three tasks:

- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`
  - `buildInbounds()` (currently lines 126–146) — the TUN inbound JSON. Tasks 1, 2, 3.
  - `buildDns()` (lines 86–124) — DNS `strategy`. Task 2 only.
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt`
  - New tests for Tasks 1 & 2; update the existing MTU test for Task 3.
  - Reuses existing helpers `baseSettings()` (lines 486–494) and `ssNode(id)` (lines 496+).

Current `buildInbounds()` for reference (post-gvisor-fix state):

```kotlin
private fun buildInbounds(): JsonArray {
    return JsonArray().apply {
        add(JsonObject().apply {
            addProperty("type", "tun")
            addProperty("tag", "tun-in")
            add("address", JsonArray().apply {
                add("172.19.0.1/30")
                add("fdfe:dcba:9876::1/126")
            })
            addProperty("auto_route", true)
            addProperty("strict_route", true)
            // gVisor userspace stack: required for an unprivileged Android VpnService. ...
            addProperty("stack", "gvisor")
            addProperty("mtu", 1280)
        })
    }
}
```

---

## Task 1: Enable `endpoint_independent_nat` for UDP/QUIC full-cone NAT

**Why:** With gVisor now the only UDP stack, UDP apps (QUIC/HTTP3, WebRTC, voice/game) need full-cone NAT. `endpoint_independent_nat` defaults to `false` (symmetric-style mapping), which breaks p2p UDP and degrades QUIC. SFA sets it `true`.

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt` (`buildInbounds`, ~line 142)
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt`

- [ ] **Step 1: Write the failing test**

Add this test method inside `class SingboxConfigBuilderTest` (e.g. after the existing MTU test at line 216):

```kotlin
@Test
fun `tun inbound enables endpoint independent nat for full cone udp`() {
    val builder = SingboxConfigBuilder(settings = baseSettings())

    val config = JsonParser.parseString(
        builder.build(
            proxyNodes = listOf(ssNode(1)),
            runtimeContext = null,
            connectionMode = ProxyConnectionMode.DIRECT,
        ).configJson
    ).asJsonObject
    val tunInbound = config.getAsJsonArray("inbounds").first().asJsonObject

    assertTrue(tunInbound.get("endpoint_independent_nat").asBoolean)
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest"
```
Expected: FAIL on the new test — `NullPointerException`/`IllegalStateException` because `tunInbound.get("endpoint_independent_nat")` is `null` (property absent).

- [ ] **Step 3: Write minimal implementation**

In `buildInbounds()`, add one line immediately after `addProperty("stack", "gvisor")`:

```kotlin
            addProperty("stack", "gvisor")
            addProperty("endpoint_independent_nat", true)
            addProperty("mtu", 1280)
```

- [ ] **Step 4: Run tests to verify they pass**

```
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest"
```
Expected: PASS (all tests in the class, including the new one).

- [ ] **Step 5: Build, deploy, reconnect**

```
.\gradlew.bat :app:assembleDebug
```
Expected: `BUILD SUCCESSFUL`. Then from repo root:
```
.\platform-tools\adb.exe install -r leikwan-android\app\build\outputs\apk\debug\app-debug.apk
```
Expected: `Success`. Reconnect via the app: Proxies → Direct → tap the proxy connect toggle (Guideline 6).

- [ ] **Step 6: Verify on-device**

Confirm the property landed and TCP canary still works:
```
.\platform-tools\adb.exe shell run-as com.leikwan.bridge cat files/latest-singbox-config.json
```
Expected: the `tun` inbound contains `"endpoint_independent_nat":true`.

```
.\platform-tools\adb.exe logcat -c
.\platform-tools\adb.exe shell "curl -4 -s -o /dev/null -w 'HTTP=%{http_code}\n' --max-time 12 https://1.1.1.1/"
```
Expected: `HTTP=301` (TCP canary intact).

QUIC/UDP smoke (best-effort — `curl --http3` is usually unavailable on the device, so use a real app): open YouTube or Chrome and load a video/page, then:
```
.\platform-tools\adb.exe logcat -d | findstr /C:"network=udp" /C:"message too long"
```
Expected: UDP flows appear with `outbound=proxy-1` and **no** `message too long`. (If `message too long` appears, that is the MTU/UDP-fragment interaction handled in Task 3 — note it and proceed.)

- [ ] **Step 7: Commit**

```
git add leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt
git commit -m "fix(android): enable endpoint_independent_nat on tun for full-cone UDP/QUIC"
```

---

## Task 2: Make the TUN IPv4-only by default to kill the IPv6 blackhole

**Why:** `buildInbounds()` always adds the IPv6 tun address `fdfe:dcba:9876::1/126`, and with `auto_route` libbox installs an IPv6 default route — but the WiFi underlay (`192.168.1.15`, net 388) has no IPv6. AAAA-preferring apps then send IPv6 SYNs into a blackhole → happy-eyeballs stalls. The clean, leak-free fix: gate IPv6 capture behind a builder flag defaulting to **off**, and when off also force DNS to `ipv4_only` so no AAAA addresses are produced (no IPv6 attempt → no stall, no leak). When the flag is on (future, once underlay-IPv6 detection is wired), behavior is unchanged.

> Follow-up (explicitly OUT OF SCOPE for this plan, do not implement here): detect the active underlay's IPv6 capability (via `UnderlyingNetworkSelector` / `ConnectivityManager.getLinkProperties`) and pass `enableIpv6Tun = true` from `TunnelOrchestrator` when the underlay actually has IPv6. This task only adds the flag and the safe default.

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt` (`build` signature, `buildInbounds`, `buildDns`)
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt`

- [ ] **Step 1: Write the failing tests**

Add two tests inside `class SingboxConfigBuilderTest`:

```kotlin
@Test
fun `tun inbound omits ipv6 address by default to avoid v6 blackhole`() {
    val builder = SingboxConfigBuilder(settings = baseSettings())

    val config = JsonParser.parseString(
        builder.build(
            proxyNodes = listOf(ssNode(1)),
            runtimeContext = null,
            connectionMode = ProxyConnectionMode.DIRECT,
        ).configJson
    ).asJsonObject
    val tunInbound = config.getAsJsonArray("inbounds").first().asJsonObject
    val addresses = tunInbound.getAsJsonArray("address").map { it.asString }

    assertTrue(addresses.any { it == "172.19.0.1/30" })
    assertFalse(addresses.any { it.contains(":") })
}

@Test
fun `dns strategy is ipv4 only when ipv6 tun disabled`() {
    val builder = SingboxConfigBuilder(settings = baseSettings())

    val config = JsonParser.parseString(
        builder.build(
            proxyNodes = listOf(ssNode(1)),
            runtimeContext = null,
            connectionMode = ProxyConnectionMode.DIRECT,
        ).configJson
    ).asJsonObject

    assertEquals("ipv4_only", config.getAsJsonObject("dns").get("strategy").asString)
}
```

- [ ] **Step 2: Run tests to verify they fail**

```
cd C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest"
```
Expected: FAIL — the address test fails because `fdfe:dcba:9876::1/126` (contains `:`) is still present; the strategy test fails because strategy is `prefer_ipv4` from `baseSettings()`.

- [ ] **Step 3: Write minimal implementation**

**(3a)** Add the flag to the `build()` signature (currently lines 51–56). Add the parameter as the last argument with a safe default:

```kotlin
    fun build(
        proxyNodes: List<ProxyNode>,
        runtimeContext: RuntimeContext?,
        routingPolicySnapshot: RoutingPolicySnapshot? = null,
        connectionMode: ProxyConnectionMode = ProxyConnectionMode.SERVICES,
        enableIpv6Tun: Boolean = false,
    ): CompiledConfigResult {
```

**(3b)** Thread it into the DNS and inbound builders. In the `build()` body, change the `dns` and `inbounds` lines:

```kotlin
        root.add("dns", buildDns(dnsProxyDetourTag, dnsBootstrapDomains, enableIpv6Tun))
        root.add("inbounds", buildInbounds(enableIpv6Tun))
```

**(3c)** Update `buildInbounds()` to accept the flag and conditionally add the IPv6 address:

```kotlin
    private fun buildInbounds(enableIpv6Tun: Boolean): JsonArray {
        return JsonArray().apply {
            add(JsonObject().apply {
                addProperty("type", "tun")
                addProperty("tag", "tun-in")
                add("address", JsonArray().apply {
                    add("172.19.0.1/30")
                    if (enableIpv6Tun) {
                        add("fdfe:dcba:9876::1/126")
                    }
                })
                addProperty("auto_route", true)
                addProperty("strict_route", true)
                addProperty("stack", "gvisor")
                addProperty("endpoint_independent_nat", true)
                addProperty("mtu", 1280)
            })
        }
    }
```

**(3d)** Update `buildDns()` to force `ipv4_only` when IPv6 is disabled. Change its signature and the final `strategy` line:

```kotlin
    private fun buildDns(
        proxyDetourTag: String?,
        bootstrapDomains: Set<String>,
        enableIpv6Tun: Boolean,
    ): JsonObject {
```
and replace the existing `dns.addProperty("strategy", settings.dnsStrategy)` (line 122) with:

```kotlin
        dns.addProperty("strategy", if (enableIpv6Tun) settings.dnsStrategy else "ipv4_only")
```

- [ ] **Step 4: Run tests to verify they pass**

```
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest"
```
Expected: PASS — all tests in the class. (No existing test asserts the IPv6 address or a specific `dns.strategy` value, so none regress; verified by grep before writing this plan.)

- [ ] **Step 5: Build, deploy, reconnect**

```
.\gradlew.bat :app:assembleDebug
```
Expected: `BUILD SUCCESSFUL`. Then:
```
cd C:\Users\frankniubi\Downloads\leikwan-br1
.\platform-tools\adb.exe install -r leikwan-android\app\build\outputs\apk\debug\app-debug.apk
```
Expected: `Success`. Reconnect (Proxies → Direct → toggle).

- [ ] **Step 6: Verify on-device**

```
.\platform-tools\adb.exe shell run-as com.leikwan.bridge cat files/latest-singbox-config.json
```
Expected: tun `address` is `["172.19.0.1/30"]` only (no `fdfe:...`); `dns.strategy` is `"ipv4_only"`.

Confirm the tun no longer advertises an IPv6 address/route:
```
.\platform-tools\adb.exe shell ip -o addr show tun0
.\platform-tools\adb.exe shell ip -6 route show table all | findstr tun0
```
Expected: tun0 shows only the IPv4 `172.19.0.1/30` (plus link-local `fe80::`); **no** `fdfe:dcba:9876::/126` and **no** IPv6 default route via tun0.

TCP canary + an AAAA-heavy host (should resolve to A and connect fast, no stall):
```
.\platform-tools\adb.exe shell "curl -4 -s -o /dev/null -w 'HTTP=%{http_code} TIME=%{time_total}\n' --max-time 12 https://1.1.1.1/"
.\platform-tools\adb.exe shell "curl -s -o /dev/null -w 'HTTP=%{http_code} TIME=%{time_total} IP=%{remote_ip}\n' --max-time 12 https://www.google.com/generate_204"
```
Expected: first `HTTP=301`; second `HTTP=204` with an IPv4 `remote_ip` and `TIME` well under a second (no happy-eyeballs stall).

- [ ] **Step 7: Commit**

```
git add leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt
git commit -m "fix(android): default tun to IPv4-only (drop v6 addr/route + ipv4_only dns) to avoid v6 blackhole"
```

---

## Task 3: Raise TUN MTU 1280 → 9000 for throughput

**Why:** `mtu=1280` (the IPv6 minimum, chosen defensively) throttles per-segment payload and adds fragmentation through the SS2022 proxy. gVisor handles a large tun MTU and the egress MSS-clamps to the underlay; SFA uses `9000`. **Do this last** and re-verify that the old `message too long` (oversized UDP-over-Shadowsocks) error from the handoff does not return now that Task 1's NAT is in place.

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt` (`buildInbounds`, the `mtu` line)
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt` (UPDATE the existing MTU test at lines 202–216)

- [ ] **Step 1: Update the existing MTU test to the new expected value (this makes it fail = red)**

In `SingboxConfigBuilderTest.kt`, rename and update the existing test (lines 202–216):

```kotlin
@Test
fun `tun inbound uses large mtu for throughput`() {
    val builder = SingboxConfigBuilder(settings = baseSettings())

    val config = JsonParser.parseString(
        builder.build(
            proxyNodes = listOf(ssNode(1)),
            runtimeContext = null,
            connectionMode = ProxyConnectionMode.DIRECT,
        ).configJson
    ).asJsonObject
    val tunInbound = config.getAsJsonArray("inbounds").first().asJsonObject

    assertEquals(9000, tunInbound.get("mtu").asInt)
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest"
```
Expected: FAIL — `expected:<9000> but was:<1280>`.

- [ ] **Step 3: Write minimal implementation**

In `buildInbounds()`, change the MTU line:

```kotlin
            addProperty("mtu", 9000)
```

- [ ] **Step 4: Run tests to verify they pass**

```
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest"
```
Expected: PASS.

- [ ] **Step 5: Build, deploy, reconnect**

```
.\gradlew.bat :app:assembleDebug
```
Expected: `BUILD SUCCESSFUL`. Then:
```
cd C:\Users\frankniubi\Downloads\leikwan-br1
.\platform-tools\adb.exe install -r leikwan-android\app\build\outputs\apk\debug\app-debug.apk
```
Reconnect (Proxies → Direct → toggle).

- [ ] **Step 6: Verify on-device (throughput up, no UDP regression)**

```
.\platform-tools\adb.exe shell run-as com.leikwan.bridge cat files/latest-singbox-config.json
```
Expected: tun `"mtu":9000`.

TCP canary + a real download, then check for the oversized-UDP error:
```
.\platform-tools\adb.exe logcat -c
.\platform-tools\adb.exe shell "curl -4 -s -o /dev/null -w 'HTTP=%{http_code} SPEED=%{speed_download}\n' --max-time 30 https://speed.cloudflare.com/__down?bytes=10000000"
.\platform-tools\adb.exe logcat -d | findstr /C:"message too long"
```
Expected: `HTTP=200` with a higher `SPEED` than at MTU 1280; **no** `message too long` lines.

**Fallback (if `message too long` returns or UDP/QUIC breaks):** the oversized UDP-over-Shadowsocks datagram problem has re-surfaced. Do NOT leave 9000. Try, in order, re-verifying each: (a) add `addProperty("udp_fragment", true)` to the tun inbound; (b) if still failing, set MTU to `8500` then step down; (c) if UDP throughput is the only victim and TCP is fine, revert MTU to `1280` and record that this server's SS UDP path caps datagram size. Capture the decision in the handoff.

- [ ] **Step 7: Commit**

```
git add leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt
git commit -m "perf(android): raise tun mtu to 9000 for throughput (SFA parity)"
```

---

## Self-Review (run after implementing all three)

**1. Coverage:** Three deferred items from the handoff — `endpoint_independent_nat` (Task 1), IPv6 blackhole (Task 2), MTU (Task 3) — each has a task with a failing-first unit test and an on-device check. ✓

**2. Placeholder scan:** No `TBD`/`handle edge cases`/"write tests for the above" — every code and command step is concrete. The one "Follow-up" note (underlay-IPv6 detection) is explicitly marked out of scope and is NOT a step. ✓

**3. Type consistency:** The `enableIpv6Tun: Boolean = false` parameter added to `build()` in Task 2 is threaded into `buildDns(...)` and `buildInbounds(...)` with matching signatures; `buildInbounds(enableIpv6Tun)` is the same name in declaration and call sites. The `endpoint_independent_nat` line added in Task 1 is preserved verbatim in Task 2's `buildInbounds` rewrite. The MTU line edited in Task 3 sits below the `endpoint_independent_nat` line from Task 1. ✓

**4. Ordering/dependencies:** Task 1 (UDP NAT) precedes Task 3 (MTU) so the MTU UDP-regression check runs against the corrected NAT. Task 2 is independent. Each task's "Verify on-device" includes the TCP canary so a regression is caught immediately. ✓
