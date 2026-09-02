# Windows native outbound: `cn_only` DNS handoff

- Date: 2026-09-03 (Asia/Taipei)
- Scope: Windows client only. Android/macOS/Linux were not changed or accepted in this pass.
- Direction under test: Hong Kong -> Mainland China (`forward 174`), route scope `cn_only` (Mainland China only).
- Publication status: Windows release artifact prepared locally; server deployment follows commit verification.

## What changed

The Windows native outbound config now treats `cn_only` as a protected split-DNS route:

1. `geosite-cn` DNS matches use the `china` DoH resolver (`223.5.5.5:443`, TLS SNI `dns.alidns.com`) through the app-private native SOCKS relay.
2. The default `remote` resolver is Cloudflare DoH (`1.1.1.1:443`, SNI `cloudflare-dns.com`) on the direct/bootstrap path for non-China names.
3. A `local` resolver is retained only for bootstrap/explicit bypass domains. In `cn_only`, user-supplied custom DNS is intentionally not allowed to override the protected China rule.
4. `geoip-cn` (CNIP) and `geosite-cn` are loaded as remote rule sets. Both the DNS and route rule-set downloads use the direct bootstrap path for this Hong Kong -> Mainland direction.
5. Before routing a restricted native destination to SOCKS, the route adds `resolve` with `strategy: ipv4_only` and no explicit server. This lets DNS rule matching choose the correct resolver and prevents the upstream SOCKS service from resolving the original hostname again through a polluted resolver.
6. Resolved Chinese IPs and literal Chinese IP destinations are routed to the native proxy; the route final remains direct for non-China traffic. TUN keeps DNS hijacking and the existing gateway loop guard.

The reverse `intl_only` path keeps the complementary policy: `geosite-cn` resolves locally and CNIP/geosite traffic is direct, while international traffic uses the native relay. It also uses the same pre-SOCKS resolve guard.

## Root cause addressed

The earlier pollution symptom was not only the local resolver. A hostname could be resolved once by the client and then be handed to SOCKS as a hostname; the upstream relay could perform a second lookup on a different, polluted path. The explicit route resolve action makes the selected split-DNS answer an IP before the SOCKS handoff. DoH protects the resolver transport from ordinary UDP/53 interception; the rule-set and bootstrap detours keep control-plane downloads on the usable path.

## Backend/control-plane evidence

The panel pull and lease contract for the test account returned an enabled native capability for forward 174 with route scopes `cn_only` and `global`. A fresh lease request returned HTTP 200, TCP+UDP native SOCKS5 leases, and no upstream/provider identity fields in the client response. Lease lifetime/refresh remains short-lived and aligned with the existing Ultra runtime contract.

## Artifact and installation

- Public installer: `artifacts/windows/leikwan-bridge-setup.exe`
- Version: `0.1.20.3`
- Public installer SHA-256: `194653bd1ac0611517b461ada5bed1df7b6be4459f056dc9650840b8119c7528`
- Public installer size: `135,026,256` bytes
- Previous public installer backup: `artifacts/windows/_public_setup_backup-20260903-pre-v0203/leikwan-bridge-setup.exe`
- Previous test installer: `artifacts/windows/native-outbound-intl-only-20260903d-win.exe` (`0.1.20.2`, retained for comparison)
- Stage and installed apphost SHA-256: `05c16bdea1f37b385528b4c836f2df2940ddcd8265e5346f4c7dcd06f958cfbe`
- `LeikwanBridge.Core.dll` stage/installed SHA-256: `e93c2e8f1febdf7458c789eb108384f6c511bdde0329b12782f2fbb28a5ad3ba`
- `LeikwanBridge.App.dll` stage/installed SHA-256: `b884c12d8124e8f79fe6a01896f015d64c90e86463fb4b1520b8c5cafef0b3b9`
- `bin\\ultra-client.exe` stage/installed SHA-256: `b60d6a101bdb1cc0a753108d8e0f0c5d8506b00e0f31270358a424f1a2d6fb85`
- `bin\\sing-box.exe` stage/installed SHA-256: `a4476dd768168a77e249050066bb32774addefcc37da123978623e7b2819de28`
- Install root: `%LOCALAPPDATA%\\Programs\\LeiKwan Bridge`

The source build used `master` at `32d2d69157455836f177b616b940d29649ab377e` plus the Windows working-tree changes described here; the branch was 8 commits ahead of `origin/master` before this release commit. The worktree was already dirty before this change; unrelated edits and artifacts were preserved.

## Verification

Passed:

- WindowsProxyConfigBuilder focused tests: 26/26.
- Full `LeikwanBridge.Core.Tests`: 274/274.
- `LeikwanBridge.App.Tests`: 45/45.
- `LeikwanBridge.Mac.Tests`: 55/55 (regression coverage; macOS scope was not changed).
- Go `tools/ultra-client`: `go test ./...` and `go vet ./...` passed.
- PHP native lease/visibility/entitlement/inventory/direction contract tests: all five `ok`.
- sing-box 1.13.19 `check` passed for generated `cn_only` and `intl_only` TUN configs.
- Installed/stage hashes match for the app and native runtime binaries.
- Local UI smoke: installed client displayed the native outbound card and the Hong Kong -> Mainland China carrier; the user subsequently confirmed that the selected Hong Kong -> Mainland test worked.

Not proven in this workstation pass:

- Sustained throughput, loaded RTT, and long-duration UDP under the user's physical network.
- A clean-host install or an independent external DNS-leak capture.
- Android/macOS/Linux behavior.

## Rollback and cleanup

Before the local install, the previous per-user installation was copied to:

`C:\\ProgramData\\Leikwan\\ClientAgent\\backups\\native-cn-split-dns-20260903-0208`

That backup includes the prior app/runtime files and a `pre-test-state.json` snapshot of the Windows proxy registry state. To roll back, stop the client, restore that directory through the existing installer helper, and verify the app/runtime hashes and `ProxyEnable`/`ProxyServer` independently. Do not delete the backup until the test window is closed.

The current local client was left in the user's working state. A pre-existing unrelated `ThroneCore` listener on `127.0.0.1:2080` was not modified.

## Operational notes

- Use `cn_only` for the current Hong Kong -> Mainland acceptance; do not infer global behavior from this test.
- Keep API/control-plane calls on the normal international route. Do not set the system proxy to the native listener while refreshing the lease.
- If a future build reports `default domain resolver not found`, check the generated config's DNS tags and `route.default_domain_resolver` before changing the relay.
- The PHP CLI on this workstation emits a pre-existing `date.timezone=UTC+8` warning; the contract tests still pass. Normalize it to `Asia/Taipei` in the shell when exact timestamp output matters.
