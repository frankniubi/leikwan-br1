# Bridge iOS client

Native SwiftUI client with a Network Extension packet tunnel. Feature scope
follows the Android client, with these iOS-specific decisions:

- **Language**: the UI is English (development language `en`). The country and
  category catalogs still carry their Chinese names as data, but the UI does not show them.

- **Sign-in**: server address + Client ID + Agent Token, saved as profiles.
  No server address is built in: the user types it (https only; a backup
  address is optional and used on transport failure). Profiles live in the
  app's Application Support, tokens in the Keychain. Signing in to another
  server/Client ID clears that device's cached forward choices and cloud
  nodes. Device id `ios-client-<clientId>-<hash16>`, `X-Device-Platform: ios`.
- **No outside addresses**: the app contacts only the profile's server and the
  user's own nodes/subscriptions. Routing rule-sets are bundled
  (`RuleSets/`, refreshed by `scripts/update-rule-sets.sh` from
  `scripts/rule-sets.tsv`, ~1.9 MB) and read as sing-box `local` rule-sets;
  there is no exit-IP probe.
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
cd ios && xcodegen generate && open Bridge.xcodeproj
(cd ios/Core && swift test)
```

`.github/workflows/ios-client.yml` runs the same steps: Core tests, runtime
host tests (Rust, leikwan-bond, ultra), both frameworks (cached), a simulator
build and an unsigned device build packaged as `Bridge-unsigned.ipa`.

## Signing and devices

CI produces an **unsigned** ipa. The packet tunnel needs the Network Extension
entitlement, which requires a paid Apple Developer team (free personal teams
and AltStore/SideStore re-signing do not get it), or TrollStore on a supported
iOS version. Packet tunnels do not run in the iOS Simulator, so traffic can
only be verified on a signed device build. Identifiers are set once in
`project.yml`: `BRIDGE_BUNDLE_ID` (app, default
`org.forfoxsakesantuary.foxbridgeinternal`; the extension is `<that>.tunnel`),
`BRIDGE_APP_GROUP` (default `group.org.forfoxsakesantuary.foxbridgeinternal`)
and `DEVELOPMENT_TEAM`. The Keychain service follows the bundle id. If the app
group changes, also update `AppGroup.fallbackIdentifier`.

The extension has a ~50 MB memory budget; libbox is built with
`with_low_memory` and enforces it, but bond and ultra running together must be
checked on a device.
