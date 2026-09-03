# macOS native outbound / VLESS removal / optional drawer release

- Date: 2026-09-03 (Asia/Taipei)
- Scope: macOS client source, dual-architecture macOS 1.0.6 artifacts, the
  artifact manifest, and the production artifact publication. The Windows
  handoff is the policy reference; Android is explicitly out of scope for this
  pass and no Android client or artifact was changed by this work.
- Reference: `docs/handoffs/2026-09-03-windows-native-outbound-port-policy-handoff.md`
- This follow-up supersedes the protocol and Connections-view claims in
  `docs/handoffs/2026-08-27-macos-client-handoff.md`; that document remains an
  historical record of the earlier package.

## Current macOS baseline

Apple's current security release is [macOS Tahoe 26.6.2](https://support.apple.com/en-ca/148281),
released 2026-08-17. The Tahoe compatibility list is maintained in Apple's
[official model list](https://support.apple.com/en-us/122867), and the upgrade
path is documented in [How to upgrade to macOS Tahoe](https://support.apple.com/en-us/122727).

The project keeps `LSMinimumSystemVersion=12.0` and the existing runtime guard,
so Tahoe is the primary acceptance target while older supported macOS versions
remain available. The supplied Apple Silicon test Mac was used for the real
Darwin Release build and package checks; it reported `Darwin 25.6.0 arm64` and
macOS `26.6.2`.

## Implemented

### Native outbound policy and OpenGFW

- macOS native startup now carries an explicit route policy through lease
  resolution, the desktop controller, and the macOS connection service. The
  default is the restricted policy; a policy mismatch fails closed.
- Restricted native routing sends TCP and UDP ports `1-999` directly. Ports
  `1000-65535` follow the selected native scope and use the integrated
  `opengfw_tcp` filter for TCP. UDP is not inspected by OpenGFW.
- The explicitly entitled `unrestricted` case is opt-in only: the selected
  carrier must grant `UnrestrictedAllowed` and the user must enable the hidden
  optional control. It permits ports `1-65535` and sets the native filter to
  `off`; revocation or an invalid policy request stops the native session.
- macOS `cn_only` and `intl_only` use split DNS matching the Windows handoff.
  Cloudflare DoH (`1.1.1.1`, SNI `cloudflare-dns.com`) handles the
  international/default path; `cn_only` adds AliDNS DoH (`223.5.5.5`, SNI
  `dns.alidns.com`) through the app-private SOCKS relay for `geosite-cn`.
- Restricted scopes add an explicit `resolve` action with
  `strategy=ipv4_only` before the SOCKS handoff, preventing a second upstream
  hostname lookup.
- TUN configs include IPv4/IPv6 addresses, MTU 1280, mixed stack, endpoint
  independent NAT, DNS hijacking, gateway loop rejection, and direct bypasses
  for panel/forward/relay endpoint addresses. Private-IP direct handling and
  direction-specific rule-set bootstrap detours are retained.
- Native credentials are handed to the local `ultra-client` relay and are not
  placed in generated sing-box config. Native configs are ephemeral and
  securely removed after helper startup; relay and lease cleanup runs on
  startup failure and stop.
- Lease renewal first uses the existing relay control channel. A full
  sing-box/relay restart is guarded as fallback only when hot refresh reports
  an unusable channel.

### VLESS removal on macOS

- `MacConnectionProtocol.Supported` now contains only `shadowsocks` and
  `wireguard`.
- The macOS editor and UI no longer expose VLESS fields or transport choices.
- The config builder rejects VLESS. The profile store drops unsupported records
  and rewrites legacy Keychain JSON on first load, removing stale VLESS fields.
- Windows and other platform protocol support is unchanged.

### Optional native-outbound drawer

- The macOS native-outbound controls are inside a collapsed `Expander` drawer
  marked `Optional`; the drawer body is not shown by default.
- The card is hidden when the capability is disabled or has no carrier
  forwards. Capability revocation collapses the drawer and stops an active
  native session.
- Route scope options include `cn_only`, `intl_only`, and `global` when granted
  by the capability. The mainland-bypass toggle is available only for
  `global`. The unrestricted control is entitlement-gated and fail-closed.

### Release hardening

- The Go `ultra-client` release is built with pinned `garble v0.17.0`,
  `-literals -tiny`, `-trimpath`, and stripped/linker-build metadata (`-s -w
  -buildid=`) using a deterministic seed.
- Rust, sing-box, wstunnel, and other native helpers are post-processed with
  `strip -S -x`. Managed macOS builds disable debug symbols, and packaging
  rejects PDB, source, and private-material files.
- Provider credentials remain ephemeral and in runtime memory; the macOS relay
  control socket is owner-only. These measures raise the cost of casual static
  extraction but are not wire confidentiality: a privileged local capture,
  debugger, or compromised host can still observe runtime traffic. The existing
  provider transport is unchanged; TLS/Noise/AEAD would be a separate protocol
  migration.

## Verification

Passed locally:

- Mac test project Release: `70/70`; Core tests Release: `279/279`.
- `go test ./...` and `go vet ./...` in `tools/ultra-client` passed.
- `bash -n` for the macOS build/package scripts and the packaging fixture
  passed.
- On the Apple Silicon test Mac, the Mac Release test project passed `70/70`
  and the packaging fixture returned `ok`.
- The test Mac built version `1.0.6` self-contained `arm64` and `x86_64`
  publishes, reusing the pinned wstunnel source and sing-box `v1.13.12`.
  `garble v0.17.0` ran with the test Mac's isolated Go 1.26.5 toolchain.
- The app and bundled helpers reported the expected architecture via `lipo`.
  Real Ultra runtime leak scans passed with `ok` for both RIDs, covering
  argv/environment, runtime state, logs, update ZIP, read-only DMG, and clean
  shutdown.
- Mac AXAML/XML parsing, focused route-policy tests, and `git diff --check`
  passed. Static production-source checks found no VLESS references under
  `LeikwanBridge.Desktop/src/LeikwanBridge.Mac`.

Known non-blocking warnings:

- The Mac restore/build emitted the existing `NU1900` warning because the
  NuGet vulnerability endpoint was unreachable; packages came from the locked
  local cache.
- Rust emits existing dead-code warnings in bundled helper sources.
- The production checkout does not contain the local `master/tests` tree, so
  PHP contracts were run locally rather than on-host.

Not proven here:

- Real macOS Tahoe Network Extension/TUN behavior, independent DNS-leak
  capture, sustained TCP/UDP throughput, sleep/wake, and clean-host install.
- Signing, notarization, and a clean-host install.

## Artifact and deployment status

The credential-free test artifacts are staged under
`artifacts/macos-test-20260903-native-policy/{arm64,x64}/`. The published local
files in `artifacts/macos/` and `artifacts/versions.json` point to version
`1.0.6`. The isolated remote build directory was
`/Users/leikwan/leikwan-macos-build-20260903-native-policy-v2`.

Final test artifact hashes:

| Artifact | Size | SHA-256 |
| --- | ---: | --- |
| arm64 DMG | 77,852,465 | `75daf4e27bc882ef5da95696ef8d03be3652e5d29e5a8528e981da956ee69dbe` |
| arm64 update ZIP | 68,515,374 | `f50c461535808d2d4bdabf913b888ffe861ffd0906c62f3171e3a4fac40cae16` |
| x64 DMG | 81,470,402 | `bd9ca6fc103be8d0f7b4e386647c05a7167b84582cf4acfeb5658595f6b5b61f` |
| x64 update ZIP | 72,023,639 | `010f7ad75350c0cfdadcb71aafc39e35eef00496abe503fda014d8fc6c841ed1` |

The package metadata and public manifest record source commit `5101cb62` and
the release hardening values. No credentials, provider identity, or sidecar
connection details are included in this handoff.

## Remaining release actions

1. Keep the production backup until the Tahoe smoke window closes; restore it
   if any artifact route, client startup, or native DNS check regresses.
2. Add signing/notarization and a clean-host install gate before calling the
   package generally distributable.
3. Run the real Tahoe smoke matrix: Proxy and VPN, `cn_only`, `intl_only`, and
   `global`, including DNS-leak and long-running UDP checks.

## Rollback

Restore the previous macOS files and `versions.json` from the timestamped
production backup recorded below, verify the old hashes, and repeat the public
manifest/Range canary before reopening the download route. The local pre-deploy
copy is under `artifacts/backups/macos-native-policy-20260903-pre-1.0.6/`.
