# LeiKwan iOS client

Native SwiftUI client with a Network Extension packet tunnel. Feature scope
follows the Android client, with these iOS-specific decisions:

- **Sign-in**: Client ID + Agent Token (same as the Windows client). Device id
  `ios-client-<clientId>-<hash16>`, `X-Device-Platform: ios`.
- **Forward modes**: only `current_mode` bond with `plain_tcp_mux` ingress, and
  `ultra`. Everything else returned by `configs/pull` is filtered out and
  listed under Settings as unsupported. wstunnel is not shipped.
- **Routing**: iOS cannot route per app without MDM, so routing is rule based
  (Shadowrocket style): global/rule/direct modes, app preset packs mapped to
  geosite/geoip rule sets, country rules, custom DOMAIN / DOMAIN-SUFFIX /
  DOMAIN-KEYWORD / IP-CIDR / GEOIP rules, China bypass.
- **Nodes**: Shadowsocks and SOCKS5, manual entry, `ss://` / `socks5://`
  import, subscriptions.

## Layout

| Path | What |
|---|---|
| `Core/` | `LeikwanIOSCore` Swift package: panel client, forward filter, bond argv, ultra models, node parsing, routing, sing-box config builder. Platform neutral, unit tested with `swift test`. |
| `App/` | SwiftUI app: plans the tunnel with Core and hands it to the extension. |
| `PacketTunnel/` | `NEPacketTunnelProvider`: starts the forward runtimes in-process, then sing-box (libbox); heartbeat and lease renewal while connected. |
| `Shared/` | App group paths and the app ↔ tunnel protocol. |
| `runtime/rust/` | `leikwan-ios-runtime`: static library hosting leikwan-ingress and leikwan-bond behind a C ABI (Android runs them as child processes; iOS cannot). |
| `runtime/go/lkmobile/` | gomobile surface for the ultra runtime, bound into the same framework as libbox (one Go runtime per process). |
| `scripts/` | Framework build scripts. |
| `project.yml` | XcodeGen spec for the app and extension targets. |

## Building (macOS with Xcode, Go 1.25, rustup)

```sh
bash ios/scripts/build-go-mobile.sh      # ios/Frameworks/Libbox.xcframework (sing-box v1.13.12 + ultra)
bash ios/scripts/build-rust-runtime.sh   # ios/Frameworks/LeikwanRuntime.xcframework (ingress + bond)
cd ios && xcodegen generate && open LeiKwan.xcodeproj
(cd ios/Core && swift test)
```

`.github/workflows/ios-client.yml` runs the same steps: Core tests, runtime
host tests (Rust, leikwan-bond, ultra), both frameworks (cached), a simulator
build and an unsigned device build packaged as `LeiKwan-unsigned.ipa`.

## Signing and devices

CI produces an **unsigned** ipa. The packet tunnel needs the Network Extension
entitlement, which requires a paid Apple Developer team (free personal teams
and AltStore/SideStore re-signing do not get it), or TrollStore on a supported
iOS version. Packet tunnels do not run in the iOS Simulator, so traffic can
only be verified on a signed device build. Bundle ids:
`com.leikwan.bridge.ios` (app) and `com.leikwan.bridge.ios.tunnel`
(extension), app group `group.com.leikwan.bridge.ios`.

The extension has a ~50 MB memory budget; libbox is built with
`with_low_memory` and enforces it, but bond and ultra running together must be
checked on a device.
