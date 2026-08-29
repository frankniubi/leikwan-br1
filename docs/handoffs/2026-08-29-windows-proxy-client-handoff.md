# Windows Proxy Client Handoff - 2026-08-29

## Delivery status

The Windows local-proxy work from this session is committed and pushed to
GitHub:

- Branch: `master`
- Commit: `456f69a8930cb34e489c242bb7549b4e01e6f66e`
- Remote: `origin/master`
- Remote verification: `origin/master` resolves to the same commit.

The change was pushed through the local GitHub proxy at
`http://127.0.0.1:2080`. Only the Windows proxy feature files, tests,
documentation, build helpers, and `CN.conf` were included in this commit.
Unrelated Android, panel, login, diagnostics, and temporary files remain
uncommitted in the worktree and were intentionally not synchronized.

No production panel/server deployment was performed. The deployment completed
in this session is the local Windows installation described below plus the
GitHub source synchronization.

## User contract implemented

The Windows desktop client now owns one local `sing-box` runtime and exposes:

- HTTP system proxy mode.
- Full-device TUN mode.
- Process/game acceleration mode through the Netch NetFilter2 payload.
- Protocols `ss`, `ss2022`, and `vless`.
- Selector-backed protocol, cipher, transport, port, mode, DNS, and game
  controls where a fixed option is practical.
- Optional mainland-China direct bypass using the root `CN.conf` route list.
- Local listen-port selection with process-mode fallback when the preferred
  port is occupied.

The proxy UI also includes the convergence hint to use `127.0.0.1`, a visible
connected/starting/error status banner, and a larger error presentation.

## Windows proxy implementation

### Profiles and node import

- Saved node profiles persist in the local profile library.
- `ss://` and `vless://` URI import is supported with stable, secret-free
  parser error codes.
- SS2022 passwords containing colons are preserved.
- Classic Shadowsocks/ss-libev methods are available in the Windows selector:
  `none`, AES CTR/CFB variants, `chacha20-ietf`, `rc4-md5`, supported AEAD
  variants, and SS2022 methods.
- Passwordless `ss://none@host:port` links are accepted. Other ciphers still
  require a password.
- Unsupported Shadowsocks plugins are rejected explicitly; plugin runtimes
  were not added in this release.

### TUN DNS and UDP behavior

- TUN DNS packets are hijacked by the route layer.
- Remote DNS uses Cloudflare DoH through the selected proxy with explicit
  `server_port: 443` and TLS SNI `cloudflare-dns.com`.
- Custom DNS addresses use DNS-over-TCP on port 53 through the selected proxy.
  This avoids depending on native SS2022 UDP for DNS resolution.
- TUN defaults remain `gvisor` with MTU `1280` and endpoint-independent NAT for
  the gVisor/mixed stacks.
- The TUN route rejects packets addressed to the sing-box peer gateway before
  private-network direct rules to avoid strict-route self-loops.
- SS2022 UDP-over-TCP remains an explicit profile setting. Classic Shadowsocks
  profiles use native UDP unless the user selects a transport option supported
  by the runtime.

### Process/game acceleration

- Process mode requires elevation and uses the Netch NetFilter2 ABI.
- Start/restart/stop paths include UAC relaunch handling and bounded driver
  cleanup to prevent stale filters or `proxy_process_exited` reconnect loops.
- Process-mode DNS remains Windows-direct so unrelated applications are not
  globally black-holed by a failed local proxy DNS path.
- The built-in Counter-Strike 2 profile now covers `cs2.exe` plus the Steam
  launcher, web helper, service, overlay, monitor, launcher, error reporter,
  streaming, and dump helper processes, matching the local Netch Steam mode
  coverage.
- Netch `.txt` and `ProcessMode` `.json` profiles can be imported from the
  configured `mode` directory.
- Add Game supports either one executable or recursive folder scanning and
  stores a user-defined display name plus executable-name rules.
- The selected game remains visible after process mode starts.

## Local artifacts and installation

The final local installer was built from `publish-final17` and installed into
the all-users installation directory:

- Installer: `artifacts/windows/leikwan-bridge-setup-final17.exe`
- Installer SHA-256:
  `72F9DBF88163D0C0CF1941F2B7A82195F95441B9AD007FD586ABAC4DEF685B1B`
- Install log: `artifacts/windows/install-final17.log`
- Install log evidence: `Installation process succeeded` and `Log closed`.
- Installed application version: `0.1.19.8`.
- Installed `LeikwanBridge.App.dll` SHA-256:
  `EB50071D955C8BC18D7774308327A81F0A829A36E60635AAF4B2F0CDA12A3766`
- Installed `LeikwanBridge.Core.dll` SHA-256:
  `B38B082C1328235967BCAE256BEA81E73DF53F22BC92797301F3D4B89CD41251`
- The installed App/Core hashes match `artifacts/windows/publish-final17`.
- The bundled `sing-box.exe` remained the pinned `1.13.19` runtime.

The installer script emits the fixed filename
`artifacts/windows/leikwan-bridge-setup.exe`; the final17 filename above is a
copy of that verified output. The application version was not bumped, so use
the commit and hashes rather than the version string to distinguish this build
from the previous local package.

## Verification evidence

Passed during this session:

- Core tests: 245 passed, 0 failed.
- Windows App tests: 42 passed, 0 failed.
- Mac desktop tests: 50 passed, 0 failed.
- Every advertised classic Shadowsocks cipher passed the installed
  `sing-box 1.13.19 check` probe.
- Remote DoH, custom TCP DNS, all three TUN stack variants, SS, SS2022, VLESS,
  and the CN route configuration passed `sing-box check` coverage.
- WPF startup smoke test launched the installed client and reported a
  responding window.
- After controlled smoke-test cleanup, `netfilter2` was confirmed `STOPPED`.
- Direct post-cleanup DNS resolution and TCP/443 reachability were confirmed.
- The supplied test host resolved and its TCP service port was reachable; no
  credentials were persisted in the handoff.

Not proven in this session:

- A real CS2 process was not running, so entering a live CS2 matchmaking
  server was not independently reproduced after the rule expansion.
- A real TUN game session and authenticated SS2022 UDP exchange were not run;
  runtime schema checks do not prove end-to-end UDP reachability.
- No production panel artifact publication, migration, or server-side canary
  was performed.
- No installer auto-update test was performed for the unchanged `0.1.19.8`
  version number.

## Operations

Before starting TUN or process mode on a test machine:

1. Confirm the selected profile, mode, DNS choice, local listen port, and game.
2. For process mode, accept the UAC prompt. The selected game can be started
   after the proxy is connected.
3. For TUN mode, use remote DNS or custom DNS-over-TCP. The custom selector is
   prefilled with `8.8.8.8`.
4. Use the HTTP/process UDP probe for diagnostics. It intentionally does not
   claim to prove TUN UDP behavior.

When replacing an active installation, stop the client and any child
`sing-box`/Ultra processes first, then stop and verify the `netfilter2` driver
is stopped before copying or installing files. This prevents the stale-driver
and stale-listener failures observed earlier in the session.

## Rollback

- Source rollback: revert commit
  `456f69a8930cb34e489c242bb7549b4e01e6f66e` and rebuild from the resulting
  tree. Do not reset or discard the unrelated dirty worktree files.
- Binary rollback: reinstall a previously verified Windows installer from the
  local `artifacts/windows` release history, then verify App/Core hashes.
- Runtime rollback: stop the client, stop `sing-box`, stop `netfilter2`, and
  confirm no LeiKwan listener remains on the configured loopback port.
- If process-mode cleanup fails, keep the UI in an error state and resolve the
  driver service before allowing another start; do not leave NetFilter2
  filtering active while reporting disconnected.

## Next actions

1. Launch CS2 and verify Steam login, server listing, matchmaking, and an
   actual UDP game session in process mode.
2. Test the provided SS2022 node in TUN mode with both remote DNS and custom
   TCP DNS, recording DNS latency and UDP game behavior separately.
3. If CS2 still fails, capture the actual `cs2.exe`/Steam process tree and
   NetFilter2 rule diagnostics before changing filter scope.
4. If a production release is required, publish the installer through the
   panel's Windows artifact route using the backup-first deployment procedure;
   that operation is outside this handoff.
