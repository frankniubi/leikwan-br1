# Windows local proxy

The Windows desktop client owns one local `sing-box` instance and exposes the
same saved profile through three modes:

- **HTTP**: enables the current user's WinINet proxy at the configured local
  listen port (default `127.0.0.1:2080`) and restores the previous value on
  stop.
- **TUN**: creates a full-device TUN interface. Windows TUN starts with the
  `gvisor` stack and MTU `1280`; `system` and `mixed` remain available for
  controlled compatibility tests.
- **Process**: uses the optional Netch NetFilter2 payload to redirect the
  selected game profile (launcher plus game processes). The game can be
  selected before it starts; arbitrary process enumeration is not exposed in
  the UI. The **Add game** dialog can add one `.exe` or recursively scan a
  game folder, asks for a user-facing profile name, and stores up to 64 unique
  executable names. The selected profile remains visible in the game picker
  and in the connected status banner. **Import Netch modes** reads Netch
  `.txt` and `ProcessMode` `.json` profiles from its `mode` directory. This
  mode requires an elevated Windows process and a driver installation accepted
  by Windows.

The proxy page also provides **Bypass mainland China IPs (direct)**. When
enabled, the local `CN.conf` CIDR list sends its mainland China destinations
through the normal interface while foreign traffic uses the
selected node. `CN.conf` is shipped with the Windows package and parsed at
startup; a missing or invalid file fails closed instead of silently proxying
all traffic.

TUN DNS is explicit. **Through proxy (remote DNS)** sends queries to the
built-in Cloudflare DoH resolver through the selected node; **Custom DNS
servers** accepts comma-separated IPv4/IPv6 addresses and starts with
`8.8.8.8`. Custom servers use DNS-over-TCP through the proxy, so resolution
does not depend on native SS2022 UDP. DNS packets from the TUN inbound are
hijacked into this resolver so Windows does not depend on an unconfigured local
DNS server. Process mode intentionally leaves Windows' normal DNS resolver
direct; it does not install a global DNS hijack that could black-hole unrelated
applications while the selected game is being redirected.

The first release imports only `ss`, `ss2022`, and `vless` links. Classic
Shadowsocks/ss-libev methods such as `none`, `aes-128-ctr`, and the supported
CTR/CFB/legacy AEAD choices are selector-backed in the UI. SS2022 method and
VLESS transport choices are also selector-backed; unsupported SS plugins are
rejected instead of being silently dropped.

The proxy page lets the user choose the local listen port from common options
or enter any TCP port from `1` to `65535`. HTTP uses the selected port exactly;
Process mode also starts there and automatically moves to a nearby free port
when the preferred port is occupied. The connected status reports the actual
loopback port in use. TUN mode does not expose a local mixed-proxy listener, so
the setting is retained for the next HTTP or Process connection.

## Runtime staging

Fetch pinned upstream runtimes through the local proxy before building the
installer:

```powershell
pwsh -NoProfile -File scripts/fetch-windows-proxy-runtimes.ps1 `
  -Proxy http://127.0.0.1:2080
```

The script verifies the release archive and the Netch payload, then records the
local `CN.conf` hash. It stages ignored files under
`artifacts/windows/proxy-runtime`. The installer copies `sing-box.exe`,
`CN.conf`, and, when present, the optional `Redirector.bin`, `nfapi.dll`, and
`netfilter2.sys` payloads into the application's `bin` directory.

## UDP/TUN notes

The TUN route rejects UDP/TCP packets addressed to the sing-box peer gateway
before the private-network direct rule. This prevents the Windows strict-route
self-loop that can otherwise make SS2022 UDP appear dead. TUN `gvisor`/`mixed`
also enables endpoint-independent NAT. Use **Test UDP** in HTTP or Process mode
to run a SOCKS5 UDP-associate DNS probe through the active profile.

The probe is diagnostic only; it does not persist the target node or write a
credential into logs. TUN mode itself needs administrator elevation, and the
process redirector also needs administrator access to the NetFilter2 driver.
