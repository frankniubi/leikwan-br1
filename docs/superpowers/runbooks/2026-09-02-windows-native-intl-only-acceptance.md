# Windows native outbound: Mainland China -> International acceptance

This runbook is for the Windows client only. It covers the local test build
for an Ultra carrier whose direction is `Mainland China -> International`.
The test scope is `route_scope=intl_only`; do not use `global` during this
acceptance pass.

## Contract

- The native card is visible only when the panel returns an entitled native
  capability. No capability means no card and no client-side fallback.
- `intl_only` sends `geoip-cn` and `geosite-cn` traffic through the direct
  outbound. Other public traffic uses the app-private native SOCKS5 facade.
- Loopback, private addresses, and the native control pipe stay local/direct.
  Control-plane HTTP bypasses the native data plane and uses the normal direct
  international route. Provider credentials never enter the sing-box config.
- Native SOCKS5 is loopback-only and carries TCP plus UDP. The facade owns the
  provider lease and refreshes it with the normal Ultra lease lifetime.
- The mainland-China bypass switch is hidden and forced off for `intl_only`.
- A lease request must be rejected as `no_approved_candidate` when the panel
  has no verified native SOCKS5 candidate for the selected forward. This is a
  panel inventory/assignment prerequisite, not a reason to silently use a
  personal node or a global route.

## Freshness gate

Run from the repository root before editing or building:

```powershell
git status --short --branch
git log -1 --format='%H %ad %s' --date=iso-strict master
git worktree list --porcelain
git for-each-ref --format='%(refname:short) %(objectname)' refs/heads refs/remotes
```

Compare the target worktree with `master` (`git merge-base --is-ancestor
<master> <worktree>` and the reverse). Use `master` when it is newer; preserve
unrelated dirty files and never reset them as part of this build.

## Build and package

The commands below create a new, uniquely named local artifact. They do not
touch `artifacts/versions.json`, update manifests, release metadata, or upload
targets.

```powershell
Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1\tools\ultra-client'
$env:GOOS = 'windows'
$env:GOARCH = 'amd64'
$env:CGO_ENABLED = '0'
go build -trimpath -buildvcs=false -ldflags='-s -w -buildid=' `
  -o '..\..\artifacts\ultra\ultra-client_windows_amd64.exe' .
Remove-Item Env:GOOS,Env:GOARCH,Env:CGO_ENABLED -ErrorAction SilentlyContinue

Set-Location 'C:\Users\frankniubi\Downloads\leikwan-br1'
dotnet publish .\LeikwanBridge.Desktop\src\LeikwanBridge.App\LeikwanBridge.App.csproj `
  -c Release -r win-x64 --self-contained true --no-restore `
  -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true `
  -p:EnableCompressionInSingleFile=true `
  -o .\artifacts\windows\publish-native-intl-20260902

.\scripts\build-windows-native-test.ps1 `
  -AppPublishDir .\artifacts\windows\publish-native-intl-20260902 `
  -OutputSetup .\artifacts\windows\native-outbound-intl-only-20260902-win.exe `
  -StageDir .\artifacts\windows\_native_setup_stage-intl-20260902
```

Record SHA-256 for the setup, apphost, `bin\ultra-client.exe`, and
`bin\sing-box.exe`. Install only to the local per-user directory with
`scripts\windows-client-install-helper.ps1`; compare installed hashes with the
staged files before launching.

### Current local build

- Setup: `artifacts/windows/native-outbound-intl-only-20260902c-win.exe`
- Version: `0.1.20.2`
- Setup SHA-256: `e74a182f4decbc882c704030b0b171d9f7eced5583a33c9e77da5638d66bc17b`
- Apphost SHA-256: `1a2a11b2d7cdf054b3b5a141eff4bdb8cebf57a5e061827563b58cc30a76aaa5`
- `bin\ultra-client.exe` SHA-256: `b60d6a101bdb1cc0a753108d8e0f0c5d8506b00e0f31270358a424f1a2d6fb85`
- `bin\sing-box.exe` SHA-256: `a4476dd768168a77e249050066bb32774addefcc37da123978623e7b2819de28`

The package was installed locally at `%LOCALAPPDATA%\Programs\LeiKwan Bridge`
and the three payload hashes matched the stage exactly. No upload or update
manifest change was made.

## Automated checks

Run with low parallelism on a shared build host:

```powershell
Set-Location '.\tools\ultra-client'
go test ./... -count=1
go vet ./...
Set-Location '..'

dotnet test .\LeikwanBridge.Desktop\LeikwanBridge.Desktop.sln `
  --no-restore --nologo --configuration Debug --maxcpucount:1

$env:LEIKWAN_SINGBOX_PATH = '.\artifacts\windows\proxy-runtime\sing-box.exe'
dotnet test .\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj `
  --no-restore --nologo --configuration Debug --filter FullyQualifiedName~WindowsProxyConfigBuilderTests --maxcpucount:1
Remove-Item Env:LEIKWAN_SINGBOX_PATH -ErrorAction SilentlyContinue

php .\master\tests\native_outbound_lease_contract_test.php
php .\master\tests\native_outbound_pull_visibility_test.php
php .\master\tests\ultra_policy_lease_contract_test.php
php -l .\master\src\Support\UltraMode.php
git diff --check
```

The sing-box test validates the generated `intl_only` schema and rule-set
references. It does not prove that the live panel has an approved reverse
carrier.

## Manual Windows acceptance

1. Start the installed app and pull configs over the ordinary international
   control-plane route. Confirm the native card is present only when the
   capability is returned.
2. Select a `Mainland China -> International` carrier, choose `International
   only` (`intl_only`), and confirm the bypass-China control is absent.
3. Test HTTP mode first: a mainland IP/domain must be direct and a known
   international endpoint must use the native SOCKS5 egress. Check both TCP
   HTTPS and a UDP probe; run at least three samples and record median and p95.
4. Stop HTTP mode, then test TUN mode as Administrator. Confirm the TUN
   interface/listener appears, TCP and UDP work, and no global route is
   installed. Do not test the old `cn_only` direction in this pass.
5. Leave the connection running past one refresh boundary. Confirm the lease
   generation changes without a user-visible outage; a transient pipe reset
   must trigger refresh/restart, not a permanent disconnected state.
6. Stop the connection and verify sing-box, Ultra, and helper processes exit;
   the per-session native runtime directory and ephemeral config are removed.

Capture only redacted evidence: app version, OS/architecture, artifact hashes,
selected forward id/direction/scope, mode, timestamps, latency samples, process
exit state, and error codes. Never copy tokens, passwords, or provider names
into logs or reports.

## Expected blocker

If the panel returns `no_approved_candidate`, inspect the read-only native
inventory and assignment for the selected reverse carrier. The carrier must
have `native_socks5_state=verified`, `transport=tcp+udp`, and be assigned to a
native-enabled plan/service. Do not bypass this response in the Windows client.

## Rollback

Stop the client, restore the previous per-user install directory from the
installer helper backup, and remove only the newly named publish/stage/setup
paths. Do not alter the update manifest or push an artifact while this runbook
is being used for local testing.
