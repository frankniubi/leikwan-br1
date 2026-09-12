# macOS client 1.0.28 release (ultra lease-delivery fix + native-outbound specialization)

Release date: 2026-09-12
Branch: `release/macos-1.0.28` (worktree `.worktrees/task-macos-1027-release`, base master `9bafe97f`)

## Why

1.0.27 shipped the managed rebuild, but a real-Mac test VM exposed a hard startup
bug: every forward spawned its `ultra-client`, the control socket came up, then
`ultra lease delivery` was cancelled after 20 s (`TaskCanceledException` →
`InvalidOperationException` → teardown), while the runtime was still inside its
selection window. Root cause: `MacUltraRuntimeHost` used a hard-coded 20 s lease
delivery timeout, but the Mac probe contract is
`probe_duration_seconds=30` + association budget + 5 s grace (~45-50 s), and the
runtime only answers after the selection window. Windows was unaffected
(`CancellationToken.None` + the client's internal 70 s batch timeout) and the
Linux agent uses 70 s for multi-candidate delivery / 3 s for a single candidate
(`client_agent.py`, "window + response margin") — macOS was the only outlier.

Fix `6e49a34b` raises the Mac lease-delivery budget to 90 s (strictly above the
inner 70 s budget, so the inner layer decides).

1.0.28 also carries `9bafe97f` (native outbound rules are a first-class special
case: health-check skip, one-shot transition cleanup, `IsNativeOutboundRule`
derived flag, "(Native Outbound Rule)" label with no start/stop in both GUIs) and
`4cfbee67` (android lease timeout parity).

## Artifacts (published)

| artifact | version | sha256 | size |
|---|---|---|---|
| `artifacts/macos/leikwan-bridge-arm64.dmg` | 1.0.28 | `9a4f2f6dc616c3fcc54ef214f57ea69093ac5f209a828e7de08561afadc6440c` | 68903814 |
| `artifacts/macos/leikwan-bridge-x64.dmg` | 1.0.28 | `60b8db5ed92906e220e23a631664404c5921ce19173a3ebc4d8b26cd9a7ea3f6` | 72856446 |
| `artifacts/macos/leikwan-bridge-update-arm64.zip` | 1.0.28 | `4ee31747b6bf46275770de183aed5b625630f11845b7fbafd01612322162beaa` | 68859895 |
| `artifacts/macos/leikwan-bridge-update-x64.zip` | 1.0.28 | `4fcc52f5f438d53bdac2da96523a14974e28ae5a4224020d01622c5f06fdecdb` | 72866649 |

Embedded `Contents/Resources/bin/ultra-client` stays byte-identical to the
published runtime artifacts (arm64 `04a22d29…`, amd64 `1373d771…`).

## Build and verification

* Managed payload: `dotnet publish -c Release -r osx-arm64|osx-x64 --self-contained true -p:Version=1.0.28`.
* Bundles/zips: `scripts/build-macos-client-bundle.py` (base = 1.0.27 bundle, payload
  swapped, Info.plist 1.0.28, rcodesign 0.29.0 ad-hoc double pass with determinism
  assertion, zip rebuilt with the original entry order/metadata).
* `scripts/verify-macos-update-zip.py`: both archs `install_surface_ok`,
  `version=1.0.28`, `seal_verified`, 6 helpers, embedded runtime == published artifact.
* Tests: `LeikwanBridge.Mac.Tests` 172/172, `LeikwanBridge.Core.Tests` 443/443.
* DMGs: **the macOS test VM went offline during this window**, so the DMGs were
  produced with the project's historical Windows-side pipeline
  (`xorriso -hfsplus` HFS+ volume → HFS partition extract → libdmg-hfsplus
  `dmg build`, the same flow used for the 1.0.8-era images). Evidence collected:
  7z integrity test on both images; the DMG contents are byte-identical to the
  signed bundle (234 files, 0 mismatches, exec bits preserved); the HFS catalog
  records `Applications` as a symlink (mode 120777, 13-byte target =
  `/Applications`); `verify-macos-dmg-layout.py` on the materialised volume
  returns `install_surface_ok` for arm64 and x86_64; apphost Mach-O arch correct.
  **Not verified on macOS for this revision: mount + `codesign --verify --deep
  --strict`** (the 1.0.27 DMGs were verified that way before the VM was lost).
* Real-Mac validation of the lease fix (before the outage): hot-swapping the
  1.0.28 assemblies into the installed bundle produced, for all four forwards,
  `ultra lease delivery result=true` and `ultra listener ready=true` after
  ~34 s (impossible under the old 20 s cap), with four `ultra-client` processes
  and live traffic in the runtime stderr; the same log before the swap showed
  `TaskCanceledException` teardown loops.


### Independent filesystem-level verification on Linux (panel host)

The prod panel host (Debian 13, kernel 6.12.85) was used as a second, independent
verifier while the macOS VM was offline:

```
apt-get install -y dmg2img      # 1.6.7+git20201227
modprobe hfsplus                # -> listed in /proc/filesystems
dmg2img -i leikwan-bridge-<arch>.dmg -o <arch>.img
mount -o loop,ro -t hfsplus <arch>.img /mnt/dmgv
```

Both images mounted read-only and reported: `Applications -> /Applications`
symlink, `LeiKwan Bridge.app`, `CFBundleShortVersionString = CFBundleVersion =
1.0.28`, `CFBundleIdentifier = com.leikwan.bridge`, apphost 0755, embedded
`ultra-client` sha256 `04a22d29…` (arm64) / `1373d771…` (x64), `sing-box`
matching the helper manifest, and both managed DLLs present. `umount` clean;
temporary images deleted (log: `.tmp/macos-1027-release/evidence/panel-dmg-loop-verify.log`).

## Acceptance (test VM, macOS 26.6.2 arm64)

| item | result |
|---|---|
| `--ultra-leak-fixture` managed load probe | exit 2 (pass) |
| `codesign --verify --deep --strict` / `codesign -dv` | valid on disk / satisfies DR; `Signature=adhoc`, `TeamIdentifier=not set` (1.0.27 build) |
| `spctl -a -vvv -t exec` | rejected (expected for ad-hoc, not notarized) |
| bootstrap chain | `mac_bootstrap_begin → config_load → identity → connections_load → mac_bootstrap_complete` |
| secret store | directory 0700, `secrets.json` 0600 (935 B), `secrets.json.migrated` present, `fallback-secrets.json` consumed; app log has **zero** keychain lines |
| panel direct egress (console harness) | physical interface `en0` (index 5, gw 192.168.0.1, not a tunnel) while `utun4` holds the Tailscale default route; `/api/health` + `/artifacts/versions.json` HTTP 200 on both panel endpoints |
| update check (same code path as the GUI) | `up_to_date` on both endpoints (~3.5 s); the one `update_check_failed` line in the GUI log was not reproducible |
| legacy keychain migration | item created by a foreign binary is blocked by the keychain ACL: the native read times out (8 s) → warning `keychain_migration_failed`, nothing imported/deleted, marker not written, **no interactive prompt**; the self-seeded variant could not be run before the VM went offline |
| GUI screenshots | **not executed — environment unavailable**: the VM reports no display device (`system_profiler SPDisplaysDataType` empty, `ioreg -rc IOAppleDisplay` empty, `screencapture` "could not create image from display" even via `sudo launchctl asuser 501`), and the host later became unreachable |

Reproducible screenshot commands (once a display is attached again):

```bash
ssh leikwan@100.103.253.21 "system_profiler SPDisplaysDataType | grep -qiE 'resolution|chipset' && echo READY || echo NOT_READY"
ssh leikwan@100.103.253.21 "echo '<pw>' | sudo -S launchctl asuser 501 /usr/sbin/screencapture -x /tmp/lkb-A1.png"
```

## Console harness

`tools/mac-e2e-harness` (self-contained osx-arm64 single file) exercises the
production Core/Mac code paths without the GUI: secret-store set/get/delete with
0600/0700/atomic-write assertions, the one-time keychain migration, physical
interface selection, real HTTPS calls through `LeikwanHttpClientFactory`, and
`MacAppUpdateService.CheckAsync`. Latest report: `.tmp/macos-1027-release/evidence/harness-report-gui.txt`
(24 pass / 5 fail, all five in the foreign-item keychain variant documented above).