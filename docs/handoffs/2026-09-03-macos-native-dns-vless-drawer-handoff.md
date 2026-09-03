# macOS native outbound / VLESS / optional drawer follow-up

- Date: 2026-09-03 (Asia/Taipei)
- Scope: macOS client source and the shared Windows optional native-outbound
  surface. No production panel mutation or client installation was performed.
- Reference: `docs/handoffs/2026-09-03-windows-native-cn-only-dns-handoff.md`
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

### Native outbound parity

- macOS `cn_only` and `intl_only` now use split DNS matching the Windows
  handoff. Cloudflare DoH (`1.1.1.1`, SNI `cloudflare-dns.com`) handles the
  international/default path; `cn_only` adds AliDNS DoH (`223.5.5.5`, SNI
  `dns.alidns.com`) through the app-private SOCKS relay for `geosite-cn`.
- Restricted scopes add an explicit `resolve` action with
  `strategy=ipv4_only` before the SOCKS handoff, preventing a second upstream
  hostname lookup.
- TUN configs include IPv4/IPv6 addresses, MTU 1280, mixed stack, endpoint
  independent NAT, DNS hijacking, gateway loop rejection, and direct bypasses
  for panel/forward/relay endpoint addresses.
- Rule-set downloads use the direction-appropriate bootstrap detour. Native
  credentials are handed to the local `ultra-client` relay and are not placed
  in the generated sing-box config when the service starts the native path.
- Native config files are ephemeral and securely removed after helper startup;
  relay and lease cleanup also runs on startup failure and stop.
- Short-lived lease renewal first uses the existing relay control channel; a
  full sing-box/relay restart is guarded as the fallback only when hot refresh
  reports an unusable channel.

### VLESS removal on macOS

- `MacConnectionProtocol.Supported` now contains only `shadowsocks` and
  `wireguard`.
- The macOS editor and UI no longer expose VLESS fields or transport choices.
- The config builder rejects VLESS. The profile store drops unsupported records
  and rewrites legacy Keychain JSON on first load, removing stale VLESS fields.
- Windows and other platform protocol support is unchanged.

### Optional native-outbound drawer

- macOS and Windows now present the built-in native outbound controls inside a
  collapsed `Expander` drawer with an `Optional` badge.
- The drawer body is collapsed by default. The whole card is hidden when the
  capability is disabled or has no carrier forwards; revocation also collapses
  it and stops an active native session.
- Route scope options include `cn_only`, `intl_only`, and `global` when granted
  by the capability. The mainland-bypass toggle is available only for `global`.

## Verification

Passed locally:

- `LeikwanBridge.Desktop.sln` serial Debug tests: Core `275/275`, App `47/47`,
  Mac `65/65`.
- On the Apple Silicon test Mac, the Mac test project passed in Release with
  `65/65`, including real bundled sing-box checks for profile and native
  configs.
- Mac and App projects built serially with `dotnet build` and no build errors.
- Mac native config sing-box checks with the bundled helper: `2/2`.
- PHP contracts (run sequentially): five `ok` results covering macOS policy,
  install surface, download regression, artifact manifest, and agent policy.
- `go test ./...` and `go vet ./...` in `tools/ultra-client` passed.
- macOS packaging scripts passed `bash -n`, both RID dry-runs, and the
  packaging fixture test on the test Mac. The fixture now emits a valid
  x86_64 Mach-O subtype so macOS `lipo` and non-macOS fallbacks agree.
- The test Mac built version `1.0.5` self-contained arm64 and x64 publishes,
  freshly compiled the pinned sing-box `v1.13.12` helper from its local Go
  module cache, and generated DMG/update ZIP pairs for both RIDs.
- The arm64 DMG was mounted read-only; the app and all six helpers reported
  arm64 via `lipo`. The real Ultra canary leak scan passed with `ok`.
- Mac AXAML and Windows XAML XML parsing passed; `git diff --check` passed.
- Static production-source check found no VLESS references under
  `LeikwanBridge.Desktop/src/LeikwanBridge.Mac`.

Known non-blocking workstation warnings:

- The Mac restore/build emitted the existing `NU1900` warning because the
  NuGet vulnerability endpoint was unreachable; packages were restored from
  the locked local cache.
- The first all-in-one build attempt could not download Go modules from the
  network; the pinned sing-box source was then compiled successfully with the
  test Mac's existing local module cache (`GOPROXY=off`, `GOSUMDB=off`) before
  packaging.
- One pre-existing Core test warning (`CS0067`, unused fake event).
- PHP CLI emits the existing `UTC+8` timezone warning before each contract.
- WSL emits its existing encoding warning during the packaging dry-run.

Not proven here:

- Real macOS Tahoe Network Extension/TUN behavior, DNS-leak capture, sustained
  TCP/UDP throughput, sleep/wake, and clean-host installation.
- Signing, notarization, public artifact publication, and a clean-host install.

## Artifact and deployment status

The test artifacts were copied to the credential-free local staging directory
`artifacts/macos-test-20260903/{arm64,x64}/` and were not copied over the
published `artifacts/macos` files. The isolated remote build directory was
`/Users/leikwan/leikwan-macos-build-20260903-native-dns`; no panel, public
manifest, or installed client was mutated.

Final test artifact hashes:

| Artifact | Size | SHA-256 |
| --- | ---: | --- |
| arm64 DMG | 75,516,938 | `2536bbb86c2a8228512f449f4a1293dfb2bca3cf778544439ec45b7c3c8e67d8` |
| arm64 update ZIP | 66,500,674 | `130ca9fd71455509509e809ea1a03d87cb5e95258906fc5303464c7b710a1e0e` |
| x64 DMG | 79,812,021 | `9414dbb7bc92da4210582f0e0c8f2d461541037bc262526be71c14afbf9ed09f` |
| x64 update ZIP | 69,928,188 | `c1f30e5c74865f8f4a57c1620320ec0ea925f786f057b8922935da6b7a7a3fc4` |

The package metadata records source commit
`c9d8c37c81e8cb46308adb176ad5ba95a966e67b` and the exact toolchain/helper
hashes. Existing public macOS artifacts and `artifacts/versions.json` remain
unchanged and must not be described as containing this follow-up.

The source worktree was already dirty with unrelated Windows/Android/server
artifacts; those changes were preserved. No credentials, provider identity, or
sidecar connection details are included in this handoff.

## Next release actions on a macOS CI/host

1. Review the staged 1.0.5 artifacts and metadata, then copy them into the
   release artifact directory only under an explicit publication change.
2. Update the manifest atomically after the four artifact files are in place,
   verifying route URLs, sizes, and SHA-256 values.
3. Run the real Tahoe smoke matrix: Proxy and VPN, `cn_only`, `intl_only`, and
   `global`, including DNS-leak and long-running UDP checks.
4. Publish only after the artifact routes and manifest return matching size and
   SHA-256 values. Keep the prior macOS packages as rollback artifacts.

## Rollback

This change has no server-side or local-install mutation to roll back. After a
future package is published, restore the previous macOS manifest and the four
previous artifact files if any Darwin build, smoke, or HTTP canary fails.
