# LeiKwan Bridge Android Client Design

## Overview

A native Android app that replicates the Windows LeiKwan Bridge client (Panel login, forward config pull, WireGuard + bond tunnel aggregation) and adds sing-box integration for proxy protocol support (ss://, vless://, vmess://, subscriptions, QR scan). Runs as an Android VPN service with traffic routed through bond tunnels to slave nodes.

## Tech Stack

- **Language:** Kotlin
- **UI:** Jetpack Compose + Material Design 3 (dark theme)
- **sing-box core:** libbox.aar (Go mobile library from sagernet/sing-box)
- **WireGuard:** wireguard-go userspace (Go mobile, no extra tun fd needed)
- **Bond:** leikwan-bond Rust CLI cross-compiled to aarch64-linux-android, run as subprocess
- **HTTP:** Retrofit + OkHttp
- **Database:** Room (local proxy node storage, settings)
- **QR scanning:** ML Kit barcode scanning or ZXing
- **Min SDK:** 21 (Android 5.0)
- **Target SDK:** 34 (Android 14)

## System Architecture

```
+-----------------------------------------------------+
|                   Android App                        |
|  +-----------+  +--------------+  +--------------+   |
|  |  Kotlin   |  |   libbox     |  |  leikwan-bond|   |
|  |  UI Layer |  |   (sing-box  |  |  (Rust CLI,  |   |
|  |  (Jetpack |  |    .aar)     |  |   arm64 bin) |   |
|  |  Compose) |  |              |  |              |   |
|  +-----+-----+  +------+------+  +------+-------+   |
|        |               |                |            |
|  +-----+-----------+   |  +-------------+--------+   |
|  |  Panel API Client|   |  |  Bond Process Mgr   |   |
|  |  (Retrofit)      |   |  |  (ProcessBuilder)   |   |
|  +--------+---------+   |  +-----------+---------+   |
|           |              |              |             |
|  +--------+--------------+--------------+----------+ |
|  |              VPN Service (Android)               | |
|  |  - tun fd owned by sing-box/libbox               | |
|  |  - WG userspace tunnels (wireguard-go)           | |
|  |  - bypass routes for slave IPs & panel mirror    | |
|  +--------------------------------------------------+ |
+------------------------------------------------------+
        |                    |                |
        v                    v                v
   Panel API           Slave Nodes       Proxy Servers
(v3-mirror.          (WG+bond legs)    (ss/vless/vmess
 leikwanhost.com)                       via 127.0.0.1)
```

## Data Flow

1. User opens app -> logs in to Panel -> pulls forward configs
2. App creates WG userspace tunnels + starts leikwan-bond listening on `127.0.0.1:{port}` per forward
3. User imports proxy nodes (ss/vless/vmess/subscription/QR) -> app auto-replaces server address with `127.0.0.1`, port with corresponding bond listen port
4. User starts VPN -> sing-box takes over all traffic via tun -> proxy traffic goes to `127.0.0.1` -> bond tunnel -> slave -> target
5. Panel API and slave IPs are bypassed via VPN route rules to prevent circular routing

## Module Design

### 1. Panel API Module

- Retrofit + OkHttp client, reuses the same 4 endpoints as Windows client:
  - `POST /api/client/login` (email/password -> token)
  - `POST /api/client/heartbeat` (report status)
  - `GET /api/client/configs/pull` (fetch forwards)
  - `GET /api/client/config/{forward_id}` (fetch detailed config)
- Default Panel URL: `https://v3-mirror.leikwanhost.com` (mirror to avoid VPN interception)
- Token stored in Android EncryptedSharedPreferences
- Data classes mirror the Windows Rust structs: `LoginResponse`, `ForwardInfo`, `ForwardConfig`

### 2. WireGuard Userspace Module

- Uses wireguard-go tunnel library (Go mobile)
- Creates userspace WG interface per endpoint, bound to UDP socket
- No additional tun fd needed -- WG operates at socket layer as bond underlay
- Config params match Windows: MTU 1382, PersistentKeepalive 25s
- Interface naming: `lkw-{forward_id}-{endpoint_index}`

### 3. Bond Process Manager Module

- `leikwan-bond` cross-compiled to `aarch64-linux-android` (arm64) and `armv7-linux-androideabi` (armv7) as standalone executables
- Packed into APK via `jniLibs/` directory (Android extracts native libs to app data)
- Launched via `ProcessBuilder` with same CLI args as Windows `bond.rs`:
  - `leikwan-bond client --protocol <tcp|udp> --listen 127.0.0.1:<port> --legs <addrs> --session-token <token> --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 ...`
- `BondManager` class: `startBond(forwardId, ...)`, `stopBond(forwardId)`, `stopAll()`
- Process stdout/stderr captured for logging

### 4. sing-box / libbox Integration Module

- Import `libbox.aar` (compiled from sing-box source via gomobile)
- Core API usage:
  - `BoxService.start(configJSON)` -- start sing-box with generated config
  - `BoxService.stop()` -- stop sing-box
  - tun fd management handled by libbox internally
- Dynamic config generation from current forward + proxy node state
- Server address replacement: when generating outbound config, replace user's original server with `127.0.0.1` and port with bond listen port

### 5. Proxy Node Management Module

**URI parsing:**
- `ss://` -- base64 decode, extract method + password + server + port
- `vless://` -- UUID + server + port + transport params
- `vmess://` -- base64 JSON decode, extract full config
- Each URI type parsed into a unified `ProxyNode` data class

**Subscription support:**
- HTTP GET subscription URL -> decode response (base64 line-separated URIs or sing-box JSON format)
- Auto-refresh on configurable interval
- Stored in Room with source URL for re-fetch

**QR code scanning:**
- ML Kit barcode API (or ZXing fallback)
- Scanned text parsed as proxy URI
- Camera permission requested on first use

**Room database schema:**
```
proxy_nodes:
  id: Long (PK, auto)
  name: String
  type: String (ss/vless/vmess)
  original_server: String
  original_port: Int
  config_json: String (full protocol-specific config)
  subscription_id: Long? (FK, nullable)
  forward_id: Long? (associated forward)
  enabled: Boolean
  created_at: Long

subscriptions:
  id: Long (PK, auto)
  name: String
  url: String
  last_updated: Long
  auto_refresh: Boolean
  refresh_interval_min: Int (default 1440 = 24h)

settings:
  key: String (PK)
  value: String
```

### 6. VPN Service Module

- Extends Android `VpnService`, coordinated with libbox
- Bypass routing (added to VpnService.Builder and sing-box route rules):
  - All slave IPs (extracted from forward config endpoints)
  - `v3-mirror.leikwanhost.com` resolved IPs
  - LAN ranges: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
- Foreground notification (required by Android for background services):
  - Shows connection status, upload/download speed
  - Quick toggle action button
- Handles VPN permission request (`VpnService.prepare()`)

### 7. DNS Configuration

User-configurable in Settings tab:

- **DNS server**: text input, default `1.1.1.1`
- **Fallback DNS**: text input, default `8.8.8.8`
- **DNS strategy**: dropdown `prefer_ipv4` / `prefer_ipv6` / `ipv4_only` / `ipv6_only`

Generated sing-box DNS config:
```json
{
  "dns": {
    "servers": [
      { "tag": "remote", "address": "<user DNS>", "detour": "proxy-1" },
      { "tag": "direct-dns", "address": "<fallback DNS>", "detour": "direct" }
    ],
    "rules": [
      { "domain": ["v3-mirror.leikwanhost.com"], "server": "direct-dns" }
    ],
    "final": "remote",
    "strategy": "<user selected strategy>"
  }
}
```

Proxy traffic DNS queries go through the proxy outbound (prevents DNS poisoning). Bypassed domains use direct DNS.

## sing-box Config Generation

The app dynamically generates the full sing-box JSON config:

```json
{
  "dns": { "..." },
  "inbounds": [{
    "type": "tun",
    "inet4_address": "172.19.0.1/30",
    "auto_route": true,
    "strict_route": true,
    "stack": "system"
  }],
  "outbounds": [
    {
      "type": "shadowsocks",
      "tag": "proxy-1",
      "server": "127.0.0.1",
      "server_port": 10001,
      "method": "...",
      "password": "..."
    },
    { "type": "direct", "tag": "direct" },
    { "type": "block", "tag": "block" }
  ],
  "route": {
    "rules": [
      { "ip_cidr": ["<slave_ip>/32", "..."], "outbound": "direct" },
      { "domain": ["v3-mirror.leikwanhost.com"], "outbound": "direct" },
      { "ip_cidr": ["10.0.0.0/8","172.16.0.0/12","192.168.0.0/16"], "outbound": "direct" }
    ],
    "final": "proxy-1"
  }
}
```

Server address replacement: user-imported proxy nodes have their `server` field replaced with `127.0.0.1` and `server_port` replaced with the corresponding forward's bond listen port. Original values preserved in Room DB for display.

## VPN Startup Sequence

Strict ordering to prevent traffic leaks or circular routing:

1. **Pull configs** -- fetch forward configs from Panel (no VPN yet, direct connection)
2. **Start WG tunnels** -- create userspace WG interface per endpoint
3. **Start bond processes** -- launch leikwan-bond listening on 127.0.0.1:port per forward
4. **Verify bond listeners** -- confirm all bond ports are reachable
5. **Generate sing-box config** -- build JSON from current forwards + proxy nodes
6. **Start VPN Service** -- libbox starts tun, sing-box begins routing
7. **Start heartbeat** -- background timer sends heartbeat via bypass direct route

Shutdown is reverse order: stop heartbeat -> stop VPN -> stop bonds -> tear down WG.

## UI Design

### Theme
- Dark theme matching Windows client: primary background #0f1117, accent blue #4a6cf7
- Material Design 3 components with custom color scheme
- App icon: reuse existing logo.png from Windows client, generate Android adaptive icon variants

### Screens

**Login Screen:**
- LeiKwan Bridge logo + "Connect to your network" subtitle
- Panel URL field (default v3-mirror.leikwanhost.com)
- Email + Password fields
- Sign In button with loading state

**Main Screen (3 bottom nav tabs):**

**Tab 1 - Tunnels:**
- Large VPN toggle switch at top
- Connection status (disconnected/connecting/connected) with animated indicator
- Forward cards (same info as Windows: service name, mode, transport, target, status)
- Per-forward connect/disconnect (if not using global VPN toggle)

**Tab 2 - Proxies:**
- Proxy node list with enable/disable toggles
- FAB with options: Add URI, Scan QR, Add Subscription
- Subscription management (add URL, refresh, delete)
- Swipe-to-delete on individual nodes
- Each node shows: name, type (SS/VLESS/VMESS), original server, associated forward

**Tab 3 - Settings:**
- DNS configuration (server, fallback, strategy)
- Auto-start on boot toggle
- Bypass rules (show current slave IPs, allow user to add custom)
- Logs viewer
- About (version, app info)

## Cross-Compilation

All native builds done in WSL:

### leikwan-bond (Rust -> Android)
```bash
# Install Android NDK cross-compilation targets
rustup target add aarch64-linux-android armv7-linux-androideabi

# Configure NDK linker in ~/.cargo/config.toml
# Build
cargo build --target aarch64-linux-android --release
cargo build --target armv7-linux-androideabi --release
```

### libbox.aar (Go -> Android)
```bash
# Install gomobile
go install golang.org/x/mobile/cmd/gomobile@latest
gomobile init

# Clone sing-box, build libbox
git clone https://github.com/sagernet/sing-box
cd sing-box
gomobile bind -v -androidapi 21 -target=android ./experimental/libbox
# Output: libbox.aar + libbox-sources.jar
```

### App Icons
- Source: `wstunnel-main/leikwan-client-win/src/logo.png`
- Generate adaptive icon variants (mipmap-mdpi through mipmap-xxxhdpi)
- Use Android Studio's Image Asset tool or manual resize

## Project Structure

```
leikwan-android/
  app/
    src/main/
      java/com/leikwan/bridge/
        ui/
          login/LoginScreen.kt
          tunnels/TunnelsScreen.kt
          proxies/ProxiesScreen.kt
          settings/SettingsScreen.kt
          MainActivity.kt
          theme/Theme.kt
        service/
          LeikwanVpnService.kt
          TunnelManager.kt
        api/
          PanelApi.kt
          PanelModels.kt
        bond/
          BondManager.kt
        wireguard/
          WgManager.kt
        singbox/
          SingboxConfigBuilder.kt
          SingboxManager.kt
        proxy/
          ProxyUriParser.kt
          SubscriptionManager.kt
        data/
          AppDatabase.kt
          ProxyNodeDao.kt
          SubscriptionDao.kt
          SettingsDao.kt
          entities.kt
        util/
          EncryptedPrefs.kt
      jniLibs/
        arm64-v8a/
          libleikwan-bond (extracted as native lib)
        armeabi-v7a/
          libleikwan-bond
      libs/
        libbox.aar
      res/
        mipmap-*/ic_launcher*.png
        values/strings.xml
        values/themes.xml
      AndroidManifest.xml
    build.gradle.kts
  build.gradle.kts
  settings.gradle.kts
```

## Package Identity

- **Package name:** `com.leikwan.bridge`
- **App name:** LeiKwan Bridge
- **Version:** 0.1.0 (matching Windows client)
