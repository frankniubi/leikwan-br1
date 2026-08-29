# Third-party Windows proxy runtimes

The Windows client can stage the following upstream runtimes under
`artifacts/windows/proxy-runtime` during a release build:

- `sing-box` (GPL-3.0): <https://github.com/SagerNet/sing-box>
- Netch NetFilter2 payload (`Redirector.bin`, `nfapi.dll`, and the driver,
  adapted through its exported ABI) (GPL-3.0):
  <https://github.com/netchx/netch>
- `CN.conf` is a local mainland-China CIDR list supplied with this workspace
  and used only for the opt-in direct route.

Run `scripts/fetch-windows-proxy-runtimes.ps1` through the configured local
proxy before packaging. The script verifies the pinned upstream archive and
records the local `CN.conf` hash in the runtime manifest.
The process redirector is optional at runtime; when its payload is absent the
GUI reports that process mode is unavailable instead of enabling a partial
redirector.
