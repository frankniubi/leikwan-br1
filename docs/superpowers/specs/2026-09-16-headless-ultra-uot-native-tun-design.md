# Headless Ultra Forward Rule + Provider UoT + Native TUN

**Status:** spec only. Do not implement from this document until the relay-source question in §12 is answered.
**Date:** 2026-09-16
**Baseline:** `master` @ `e0e4a71f` (`fix(ultra): retry active candidate after probe exhaustion`)
**Supersedes as the client-side delivery plan:** `docs/reports/2026-09-14-ultra-uot-implementation-plan.md` (protocol sketch kept; this document adds headless / TUN / OS matrix and corrects the single-peer mistake).
**Related:** `docs/reports/2026-09-13-ultra-probe-failure-evidence-and-uot-assessment.md`, `docs/superpowers/specs/2026-09-01-native-outbound-ultra-carrier-design.md`, `docs/superpowers/plans/2026-08-25-ultra-linux-ephemeral-runtime.md`

---

## 1. Why this exists

Two user-facing jobs on Windows / Linux / macOS **without a GUI**:

1. **Ordinary Ultra Forward Rule.** Pick one assigned Ultra forward. The client opens `127.0.0.1:<listen_port>` (TCP+UDP). A user-owned node (ss/vmess/vless/wg) is rewritten to that loopback address and then connected. This is packet forwarding, not a public proxy.
2. **Native outbound (TUN / mixed proxy).** The same Ultra carrier issues a short-lived SOCKS5 lease. sing-box captures OS traffic (TUN) or a local mixed inbound and sends it through an app-private ultra-client SOCKS5 facade. No imported node is required. Provider identity never appears in config.

UDP is the failure that makes both jobs incomplete. Roughly a fifth of Ultra relays accept the TCP control handshake and then close command `3` (`datagram handshake: EOF`). Today that relay is unusable for games, QUIC, DNS, WireGuard-over-the-forward, and TUN UDP. `transport=tcp` is **not** a workaround: it makes the runtime serve TCP only, so UDP simply stops.

**UoT here means: Ultra provider UDP payloads ride the relay's TCP stream.** It is **not** LeiGod `lguot` (slave↔sidecar WG). Do not wrap ultra-client with `tools/lguot`.

UoT is a **fallback**, not an upgrade. Head-of-line blocking and TCP retransmits are worse for games than native UDP. Default is `auto`: native UDP when the candidate arms, UoT when it does not.

---

## 2. Baseline and worktrees

`master` is the source of truth (CLAUDE.md). Implementation starts from current `master`, not from a topic worktree, unless a scoped diff shows a worktree is strictly newer **and** relevant.

Checked 2026-09-16 (`git worktree list`):

| Worktree | HEAD | Verdict |
|---|---|---|
| repo root `master` | `e0e4a71f` | **Use this.** Headless Windows/macOS, native relay host, ultra-client HA/UDP, Windows/mac TUN builders are here. |
| `.worktrees/task-native-outbound-rule` | `9bafe97f` | Historical native-rule work. Re-read only if a scoped `master..worktree` still contains unmerged native-rule behaviour; do not copy wholesale. |
| `.worktrees/ultra-ha-20260911` / `ultra-policy-v2` / `ha-release-publish-20260911` | older | Already landed on master in later form. Ignore. |
| `.worktrees/macos-client`, `task-macos-ha-20260911`, `task-macos-1027-release` | older | macOS headless + native TUN already on master (`MacHeadlessHost`, `MacConnectionService.StartNativeAsync`). Ignore. |
| `.claude/worktrees/windows-workflow-parity` | `33ca7c12` (2026-06-01) | Stale. Ignore. |
| `.claude/worktrees/android-routing-workflow` and other `.claude/worktrees/agent-*` | stale / unrelated | Ignore. |
| `.worktrees/uot-feedback-scheduler-20260808` | LeiGod UoT scheduler | Wrong UoT. Ignore for Ultra. |

Dirty files currently on the workspace (`master/public/assets/bridge-home.css`, homepage tests, `packages.lock.json`, …) are homepage/i18n work and must not be mixed into this changeset.

Android already has ordinary Ultra + native outbound + TUN. This spec does not rebuild Android unless a protocol bump forces a runtime rebuild (it will). Android is a **consumer of the same ultra-client**, not the delivery target.

---

## 3. Product model (do not redraw)

LeiKwan clients only forward packets. Nodes are user-owned. Ultra is not a vendor node picker.

```
Ordinary Ultra Forward Rule
  user node (ss/vless/…)  →  127.0.0.1:<forward.listen_port>  →  ultra-client  →  Ultra relay  →  user node origin

Native outbound / TUN
  OS (TUN or mixed :2080)  →  sing-box  →  127.0.0.1:<private socks>  →  ultra-client (native_socks5)  →  Ultra relay  →  arbitrary dest
```

Constraints that this spec must not violate:

- Provider IP / credentials never persist, never appear in sing-box config, never appear in status JSON.
- Native rows (`IsNativeOutboundRule`) are not started by `StartForwardAsync`. The data plane belongs to a user-initiated native session.
- Local SOCKS5 facade is no-auth (or dummy user/pass that is discarded). Real provider material enters ultra-client only through the control channel.
- sing-box native outbound keeps `"udp_over_tcp": { "enabled": false }`. UoT happens **inside** ultra-client, not as sing-box-to-socks UoT.

---

## 4. What already works on `master`

### 4.1 Ordinary Ultra Forward Rule (local listen)

| Layer | Path |
|---|---|
| Panel policy + lease | `ClientAgentController`, `UltraLeaseService` |
| Linux systemd client | `client-agent/client_agent.py` + `install_forward_runtime.py` + `tools/ultra-client` Unix control socket |
| Windows GUI / headless | `ForwardRuntimeManager` → `WindowsUltraRuntimeHost` → named pipe `\\.\pipe\<PipeName>` |
| macOS GUI / headless | `MacUltraRuntimeHost` → Unix socket under `/tmp/lk-…/control.sock` |
| Headless lifecycle | `HeadlessRunner` + `WindowsClientStack` (Windows), `MacHeadlessHost` (macOS) |
| Local data plane | ultra-client listens `127.0.0.1:<listen_port>` TCP+UDP, fixed `target` from the forward |

Headless CLI today (`HeadlessClientOptions`):

```
--headless --panel <url> --client-id <n> --agent-token-file <path>
           [--forwards all|1,2] [--once] [--print-status]
           [--log-file] [--status-file]
```

`--forwards` starts ordinary Ultra (and bond/mptcp) runtimes. It does **not** start native outbound / TUN. Native rules are skipped by `IsNativeOutboundRule`.

### 4.2 Native outbound / TUN (GUI only)

| OS | Host | TUN inbound | Ultra facade |
|---|---|---|---|
| Windows GUI | `WindowsProxyConnectionService` | sing-box `tun` (`LeiKwan Bridge`, gvisor/mixed, auto_route) | `NativeOutboundRelayHost` named pipe, `native_socks5=true` |
| macOS GUI | `MacConnectionService.StartNativeAsync` | sing-box `tun` (Vpn mode) or mixed (Proxy mode) | same host, Unix control socket |
| Android | `TunnelOrchestrator` + `SingboxConfigBuilder` | VpnService TUN | app-private ultra-client + `UltraLeaseClient.sendNative` |
| Windows headless | **missing** | — | stack has no `WindowsProxyConnectionService` |
| macOS headless | **missing** | `MacHeadlessHost` never calls `StartNativeAsync` | — |
| Linux | **missing** | no C# Linux desktop; client-agent has no native/TUN | — |

`NativeOutboundRelayHost` already writes `transport=tcp+udp`, `udp_max_associations=1024`, and delivers `leikwan-ultra-native-socks5-v1` leases. Local UDP ASSOCIATE works. The provider hop is still command `3` real UDP.

### 4.3 Ultra-client provider UDP

- Handshake commands: `1` = TCP byte stream, `3` = datagram association. `buildHandshakeWithGreeting` rejects anything else (`unsupported handshake command`).
- Command `3`: TCP is control only. Payloads are real UDP to `relay_ip:decoded_port` with 16-byte envelope `{1,0,mode,sid[3],…,ip4,port2,payload}`.
- Native SOCKS5 (`native_socks5.go`) already does CONNECT + UDP ASSOCIATE and multiplexes many destinations onto one `DatagramSession`.
- Probe/HA: `probeUDPLease` / `armRuntimeCandidates`. UDP-dead relays are skipped; TCP-only `transport=tcp` does not carry UDP.

### 4.4 Existing UoT that must not be reused

| Thing | Role | Why not Ultra |
|---|---|---|
| `tools/lguot` | LeiGod slave↔sidecar, 2-byte length frames, single WG peer | Wrong hop, single-peer, SOCKS CONNECT to LeiGod line |
| `client-agent/leikwan_uot.py` / `leikwan_uot_bond.py` | same LeiGod path | Python, Linux-agent only |
| sing-box `udp_over_tcp` on SS/VLESS outbounds | user-node transport | Native builder **explicitly disables** it so the local SOCKS hop stays real UDP |
| `transport=tcp` on an Ultra lease | TCP-only listener | Drops UDP |

---

## 5. Recommended design

One change in **ultra-client + Ultra relay**, reused by every client surface.

```
local UDP (ordinary listen OR SOCKS5 ASSOCIATE OR TUN via sing-box)
        │
        ▼
ultra-client
  udp_mode=native → command 3 + real UDP envelope     (today)
  udp_mode=uot    → command 4 + length-prefixed frames on the TCP stream
  udp_mode=auto   → try native; on arm/probe UDP failure, arm command 4
        │
        ▼
Ultra relay
  cmd 3: UDP socket to dest (today)
  cmd 4: read framed datagrams, sendto() dest, return framed replies
```

TCP (command `1`) is unchanged.

**Do not** put UoT between sing-box and ultra-client. The local hop stays real UDP so MTU / ASSOCIATE / DNS stay one code path. UoT is only the provider hop.

### 5.1 `udp_mode` is not `transport`

| Field | Meaning |
|---|---|
| `transport` | What the local listener serves: `tcp` / `udp` / `tcp+udp` |
| `udp_mode` | How provider UDP is carried: `native` / `uot` / `auto` |

A `tcp+udp` forward with `udp_mode=uot` still accepts local UDP; it frames it onto TCP toward the relay.

### 5.2 Multi-peer is required (correction to the 2026-09-14 sketch)

The 9/14 plan said “one association = one target host:port, single-peer only in v1”. That is enough for **ordinary Ultra** (fixed `forward.target`). It is **not** enough for native outbound / TUN: one SOCKS5 UDP ASSOCIATE carries DNS, QUIC, games, STUN, to many destinations.

v1 UoT **must multiplex destinations on one command-4 stream**, reusing the existing 16-byte datagram envelope so native SOCKS5 and ordinary UDP share one parser.

Ordinary Ultra can still pin the destination to `forward.target` in policy (drop frames whose dest ≠ target). Native outbound does not pin.

---

## 6. Protocol: command 4 (UoT association)

### 6.1 Handshake

Same greeting + user + credential + checksum as today, `command=4`, address = relay IP:port (same dummy as command 3; real destinations travel in frames).

```
greeting || len(user) || user || len(cred) || cred || {5, 4, checksum} || atyp || addr || port
```

Reply: same 10-byte control success frame as command 3 (`{4,1,1,0,1, mode^1, sid0^1, sid1^1, port^0x0101}`) so existing reply parsers work. The decoded UDP port in the reply is **ignored** for sending; the TCP connection *is* the data plane. SID/mode are still used inside the envelope so keepalive and native parsers stay shared.

Old relays: treat command 4 like any unknown command (close / reject). Client classifies as `uot_arm_failed` (transport reason, **not** a reputation strike) and fails that candidate.

### 6.2 Framing on the TCP stream after success

Big-endian, max payload 65535, max frame 16 + 65507.

```
uint16_be length
bytes    body[length]
```

`length == 0` is a control frame: next byte is a reason code, then optional UTF-8 (≤128).

| Reason | Meaning |
|---|---|
| `0x01` | idle timeout |
| `0x02` | dest unreachable / icmp |
| `0x03` | policy / port rejected |
| `0x04` | payload too large |
| `0x05` | quota / rate limit |
| `0x06` | unknown dest / parse error |

Data body is the **existing datagram envelope** (not a new address format):

```
{1, 0, mode, sid0, sid1, sid2, 0, 0, 0, 1, ip4[4], port_be[2], payload}
```

Keepalive: dest `2.0.0.0:0` with the existing keep payload, same as native. Relay answers with the existing 10-byte SOCKS-shaped echo, framed.

Return path: relay wraps `sendto` replies in the same envelope (dest = original peer) and frames them back. Client unwraps and writes to the local UDP socket / SOCKS UDP header.

### 6.3 Association rules

- One command-4 TCP stream per DatagramSession (same HA unit as today).
- Many destinations per stream (`udp_max_associations`, already 1024 on native).
- Idle timeout: 60s without a non-keepalive frame either way.
- TCP_NODELAY on.
- Max in-flight unacked datagrams: reuse `udp_queue_packets` (256).
- Half-close: FIN after sending reason `0x01`; client tears the association down and HA-rearms.
- IPv4 only in v1 (matches `makeDatagramEnvelope` / `udp4`). IPv6 is a later bump.
- Payload ceiling still measured. On UoT the ceiling is `min(measured, tcp_mss_minus_frame)`. Status field `active_udp.transport` is `native` or `uot`.

### 6.4 Selection (`auto`)

Per candidate, in order:

1. If `udp_mode=native`: today's UDP probe/arm. Failure → skip candidate.
2. If `udp_mode=uot`: skip UDP probe; TCP probe + command-4 arm. Failure → `uot_arm_failed`.
3. If `udp_mode=auto`:
   - Prefer a candidate that arms native UDP.
   - If none in the batch, arm command 4 on a candidate that is `tcpUsable`.
   - Never count a UDP probe EOF as a failure strike (already true for `probe_udp_failed`).
   - Mid-session: if native association dies with `datagram handshake: EOF` / payload-ceiling 0, HA may rearm the **same** candidate over command 4 before walking the rest of the batch.

`usableFor("tcp+udp")` today requires both halves. Under `auto`/`uot`, UDP-half failure is not fatal when UoT armed.

### 6.5 Capability gate

Lease/policy carries `udp_mode` only when the runtime fingerprint is ≥ the first UoT-capable release. Older fingerprints: omit the field (legacy = native). Explicit `uot` requested by an old runtime → panel refusal `uot_unsupported`, no silent downgrade.

---

## 7. Ordinary Ultra Forward Rule (no-GUI)

Unchanged local contract: `127.0.0.1:<listen_port>` TCP+UDP, target = user node.

### 7.1 Windows headless

Already: `LeikwanBridge.Headless` → `WindowsClientStack` → `WindowsUltraRuntimeHost`.

Add:

- Honour `udp_mode` from the lease when writing `ultra-forward-N.json` (`udp_mode`, default `auto` once fleet-ready; until then omit = native).
- Status JSON: per-forward `udp_transport: native|uot|none`.
- No extra binary. Same `ultra-client.exe`.

Service install (`--install-service`) unchanged. Ordinary Ultra does not need Administrator; TUN does (see §8).

### 7.2 macOS no-GUI

Already: `MacHeadlessHost` + `MacUltraRuntimeHost` + launchd SIGTERM fix.

Same `udp_mode` plumbing as Windows. Bundle still ships `Contents/Resources/bin/ultra-client`.

### 7.3 Linux no-GUI

Already: systemd `client-agent` + `/etc/ultra/forward-N.json` + Unix control socket.

Changes:

- `install_forward_runtime.py` writes `udp_mode` into the static config (non-secret).
- `client_agent.py` copies `udp_mode` from the lease frame onto the control socket JSON.
- No new Linux C# headless for ordinary Ultra. Do not invent a second Linux stack.

Linux “desktop headless” (`LeikwanBridge.Headless` published as `linux-x64`) is **out of scope for ordinary Ultra** because client-agent already is that product. A C# Linux headless is only justified if we also ship Linux native TUN in the same binary (see §8.3).

---

## 8. Native outbound / TUN (no-GUI)

Goal: the GUI native path, driven by flags, without a window.

Shared sequence (all OS):

1. Pull configs. Require `native_outbound.enabled` and a carrier forward.
2. `ResolveNativeOutboundLeaseAsync(forwardId, scope, policy)` (already on `IDesktopRuntimeSession`).
3. `NativeOutboundRelayHost.StartCandidatesAsync` → loopback SOCKS5.
4. Start sing-box with `BuildNative` / `MacConnectionConfigBuilder.BuildNative`.
5. Refresh leases in place via `RefreshCandidatesAsync`. Do not tear TUN on a rejected refresh (already documented on `INativeOutboundRelayHost`).
6. Apply measured payload ceiling to TUN MTU (`UltraRuntimeMtu.Resolve`), same 60s cadence as GUI.

### 8.1 Windows headless TUN

Wire `WindowsProxyConnectionService` into `WindowsClientStack` (today the stack explicitly has “no proxy/game UI” and never constructs it).

New flags (all optional; default off so existing `--forwards` behaviour is unchanged):

```
--native-mode off|proxy|tun        # default off
--native-forward <id>              # default: capability.default_forward_id
--native-scope global|cn_only|intl_only
--native-policy restricted|unrestricted
```

`proxy` = mixed inbound `127.0.0.1:2080` (no admin). `tun` = sing-box tun inbound (Administrator; Wintun already shipped next to sing-box).

If `--native-mode tun` and the process is not elevated: fail with `native_tun_privilege_required` and a non-zero exit, do not fall back to proxy.

Status file gains:

```json
"native": {
  "mode": "tun",
  "forward_id": 180,
  "scope": "global",
  "policy": "restricted",
  "socks": "127.0.0.1:3xxxx",
  "udp_transport": "uot",
  "running": true
}
```

Windows Service (`--install-service`) running as LocalSystem can open TUN. Document that the service account must be allowed to create the `LeiKwan Bridge` interface; reuse the GUI helper’s Wintun/WinDivert install if already present.

Process-mode (`WindowsProxyMode.Process` / NetFilter2) stays GUI-only. Headless is `proxy` or `tun`.

### 8.2 macOS no-GUI TUN

`MacHeadlessHost.RunAsync` after ordinary forwards:

- if `--native-mode vpn|proxy`, call `MacConnectionService.StartNativeAsync`.
- `vpn` needs root (utun via sing-box). launchd plist: `UserName root` **or** a documented `sudo` console run.
- Same flags as Windows (`vpn` is the macOS name for TUN; accept `tun` as an alias).

Do not use Network Extension / system-extension for v1. Headless = sing-box tun, same as the windowed Vpn mode.

### 8.3 Linux native / TUN

Not on master. Two acceptable deliveries; pick **A** unless a C# Linux headless is already being productised.

**A (recommended). Extend client-agent.**

- New static unit `leikwan-native-<forward>.service` running ultra-client with `native_socks5=true`.
- New unit `leikwan-native-tun.service` running packaged `sing-box` with a generated config (no credentials; socks → 127.0.0.1).
- `client_agent.py` requests `purpose=native_outbound` leases and sends them on the native control socket (Python equivalent of `UnixUltraLeaseClient.SendNativeAsync`; the framing is already specified).
- TUN: sing-box `tun` inbound, `auto_route`, `CAP_NET_ADMIN` + `/dev/net/tun`. Default off; `native_mode=tun` in agent config.json.
- Reuse `NativeOutboundConfigBuilder` JSON shape (port the builder to Python **or** have ultra-client emit the socks listen and a tiny Python writer copy the Windows JSON). Prefer porting the JSON writer once into a shared golden fixture tested from both.

**B. Publish `LeikwanBridge.Headless` for `linux-x64`.**

- Same `HeadlessRunner` + a `LinuxClientStack` that is `WindowsClientStack` with `UnixUltraLeaseClient` and a Linux `NativeOutboundRelayHost(windows: false)` plus a Linux sing-box process host cloned from `MacConnectionProcessHost`.
- Only worth it if product wants one binary/CLI across three OS. More C# surface, new packaging.

v1 recommendation: **A**. Linux operators already run client-agent. Do not require .NET on Linux for TUN.

Privilege: TUN requires root/capabilities. Ordinary Ultra and `native_mode=proxy` (mixed 127.0.0.1:2080) do not.

---

## 9. Panel

| Change | Detail |
|---|---|
| `udp_mode` | `native` \| `uot` \| `auto` on the lease JSON and on `ultra_access` (non-secret). |
| Storage | `clients.ultra_udp_mode` + `service_plans.default_ultra_udp_mode`. Default `native` until relay fleet + canary; then `auto`. |
| Tenant UI | Client page: UDP 承载 = 自动 / 仅原生 UDP / 仅 TCP 承载. Copy must not say “public proxy”. |
| Capability | Do not send `udp_mode=uot/auto` to a runtime fingerprint older than the UoT release. |
| Candidate filter | `native` clients never receive UoT-only inventory. `auto` may. Inventory flag `uot_state=verified` once source-agent probes command 4. |
| Native lease | Same `udp_mode`. Native SOCKS5 local hop unchanged. |
| Refusal | `uot_unsupported` if the client asked `uot` and the line/runtime cannot. |
| Accounting | Command-4 bytes count as UDP quota, tagged `udp_transport=uot` on `ultra_usage_events`. Frame a 1-byte protocol tag in the reason/control channel or count from framed payload length; do not bill them as opaque TCP. |
| Feedback allowlist | Add `uot_arm_failed`, `uot_stream_failed` as **transport reasons** (no reputation strike), same as `probe_udp_failed`. |

No new lease endpoint. `POST /api/client/ultra/lease` already multiplexes `purpose=ultra` and `purpose=native_outbound`.

---

## 10. Relay (the long pole)

Production Ultra relay source is **not in this repo**. This repo has `tools/ultra-client/tokyo_sim/relay` only.

Phase 0 (blocking):

1. Locate the production relay tree and its deploy path.
2. Implement command 4 + framing + `sendto` pump + idle timeout.
3. Extend `tokyo_sim/relay` in **this** repo so ultra-client can be tested without the production binary.
4. Fleet rollout with capability probe (`uot_state`).

Until (1) is answered, client-side UoT can be written against `tokyo_sim/relay` but **must not** be defaulted on in panel.

---

## 11. File map (when implementation starts)

Create:

- `tools/ultra-client/uot.go` — command-4 arm, frame read/write, pump to local UDP / native ASSOCIATE.
- `tools/ultra-client/uot_test.go`
- `tools/ultra-client/tokyo_sim/relay` — command 4 support.
- `docs/superpowers/specs/2026-09-16-ultra-uot-wire.md` — extract §6 as the frozen wire doc after review (optional split).

Modify:

- `tools/ultra-client/main.go` — allow command 4 in `buildHandshakeWithGreeting`; `DatagramSession` send path branches native vs UoT.
- `tools/ultra-client/udp_probe.go`, `ha_udp.go`, `selector.go`, `policy.go`, `status.go` — `udp_mode`, `active_udp.transport`.
- `tools/ultra-client/native_socks5.go` — no local protocol change; `sendUDPRequest` uses the session’s transport.
- `tools/ultra-client/control.go`, `control_windows.go` — accept `udp_mode` on lease JSON.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/WindowsUltraRuntimeHost.cs` — write `udp_mode`.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacUltraRuntimeHost.cs` — same.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/NativeOutboundRelayHost.cs` — pass `udp_mode` in runtime.json.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/UltraLease.cs`, `NativeOutboundLease.cs` — `UdpMode`.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HeadlessClientOptions.cs` — native flags.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Headless/WindowsClientStack.cs` — optional native host.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Headless/HeadlessRunner.cs` — start/stop native session.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacHeadlessHost.cs` — same.
- `client-agent/client_agent.py`, `install_forward_runtime.py` — `udp_mode`; later native units.
- `master/src/Support/UltraLeaseService.php` (and policy / plan flags) — persist and emit `udp_mode`.
- Android `UltraModels.kt` / `UltraLeaseClient.kt` / `UltraForwardRunner.kt` — accept `udp_mode` so a rebuilt runtime is not stuck native.

Do **not** modify `NativeOutboundConfigBuilder` to enable sing-box `udp_over_tcp`.

Do **not** call `tools/lguot` from desktop/headless.

---

## 12. Phases

| Phase | Work | Depends |
|---|---|---|
| **0** | Locate relay source. Freeze §6 with relay owners. Extend `tokyo_sim/relay`. | — |
| **1** | Relay command 4 + tests. | 0 |
| **2** | ultra-client UoT data plane + `auto` selection + status. `go test ./...` | 0 (sim relay enough) |
| **3** | Panel `udp_mode`, capability gate, transport reasons, usage tag. PHP tests. | 2 fingerprint known |
| **4** | Host plumbing: write `udp_mode` into Windows/Mac/Linux static config + lease wire. Rebuild ultra-client for win/linux/mac/android. | 2 |
| **5** | Headless native: Windows `--native-mode`, macOS `--native-mode`. Tests around `HeadlessRunner`. | 4 |
| **6** | Linux native/TUN via client-agent (delivery A). | 4, 5 patterns |
| **7** | Canary one client + one UoT-capable line. Compare RTT/jitter vs native. Default stays `native`. | 1+3+4 |
| **8** | Relay fleet `uot_state=verified`. Flip plan default to `auto`. Rollback = panel setting. | 7 |

Ordinary Ultra UoT is usable after phase 4 even without TUN. TUN UoT is free once 2+5 exist, because TUN already uses the same DatagramSession.

---

## 13. Tests (minimum)

Runtime:

- Framing round-trip, truncated/oversized rejected, length-0 reason codes.
- Command 4 rejected by old sim-relay → candidate failure, next candidate selected.
- `auto` prefers native when both work; uses UoT when command 3 EOF and command 4 works.
- `native` never selects a UDP-dead candidate.
- Native SOCKS5 UDP ASSOCIATE to **two destinations** over one UoT stream (this is the TUN-critical test).
- Ordinary Ultra listen: UDP echo to the pinned target over UoT; foreign dest dropped.
- HA: native association EOF mid-session rearms UoT on the same candidate under `auto`.
- Existing command 1 / command 3 tests stay green.

Hosts:

- Windows/Mac runtime host writes `udp_mode` and does not persist provider material.
- Headless `--native-mode off` does not construct the proxy service.
- Headless `--native-mode proxy` starts relay + mixed inbound without TUN.
- Headless `--native-mode tun` without privilege → stable error code.
- Native refresh does not stop sing-box when control refresh returns false (existing contract).

Panel:

- Lease JSON carries `udp_mode` only for capable fingerprints.
- `uot_unsupported` for explicit UoT + old fingerprint.
- `uot_arm_failed` is a transport reason.

---

## 14. Non-goals

- Wrapping LeiGod `lguot` around Ultra.
- sing-box `udp_over_tcp` on the native SOCKS outbound.
- Network Extension / wintun-from-scratch / new TAP driver.
- Headless process-proxy (NetFilter2) mode.
- IPv6 UoT.
- Multi-stream UoT bonding (one TCP stream per association in v1).
- Changing the product into a public proxy or vendor node list.
- Mixing this work with the dirty homepage/i18n files on the current workspace.

---

## 15. Open questions (must close in phase 0)

1. **Where is the production Ultra relay source and how is it deployed?** Blocks phase 1 sizing and fleet `uot_state`.
2. Keep panel default `native` until canary is clean, then `auto`? (Recommendation: yes.)
3. Linux native: client-agent (A) vs C# linux-x64 headless (B)? (Recommendation: A.)
4. May HA rearm the **same** UDP-dead candidate over UoT, or only walk the batch? (Recommendation: same candidate first, then batch.)
5. Does any inventory already secretly speak a framed-TCP datagram dialect we should match instead of inventing command 4? Phase 0 must dump a command-3-EOF relay and a healthy relay before freezing §6.

---

## 16. Operator picture (what “done” looks like)

Windows (no GUI), ordinary Ultra:

```
LeikwanBridge.Headless --headless --panel https://v3.leikwanhost.com \
  --client-id 12 --agent-token-file C:\ProgramData\Leikwan\token \
  --forwards 158 --print-status
```

`127.0.0.1:<158.listen_port>` accepts TCP+UDP. If the chosen relay is UDP-blind, status shows `"udp_transport":"uot"` and games still flow.

Windows (no GUI), TUN:

```
LeikwanBridge.Headless --headless … --native-mode tun --native-forward 180 --native-scope global
```

Runs as Administrator / service. OS default route through `LeiKwan Bridge`. UDP to the world uses the same UoT fallback.

macOS: same flags on the bundle executable `--headless …`; TUN needs root.

Linux ordinary Ultra: existing client-agent. After phase 4, UoT is automatic from the lease. Linux TUN: phase 6 `native_mode=tun` in agent config, root unit, sing-box + ultra-client native facade.
