# macOS installation regression investigation

Status: 1.0.9 native packages and installation acceptance completed. Canonical production publication is withheld because default Gatekeeper still rejects the unnotarized app. Production remains 1.0.8; do not claim a warning-free release.

## Evidence

- The customer screenshot shows the app icon, but no `Applications` installation shortcut, and a Gatekeeper warning that Apple cannot check the app for malicious software.
- The original `scripts/package-macos-client.sh` creates `Applications -> /Applications`. The temporary Windows/WSL 1.0.8 packaging workflow bypassed that script and omitted this root-level link. Both final extracted 1.0.8 DMGs are rejected by the new install-surface validator for this exact omission.
- Gatekeeper is a separate acceptance requirement from file hashes, CodeDirectory digest checks, DMG mounting and executable presence. Previous ad-hoc signing is not Developer ID signing/notarization.
- New test Mac: `100.103.253.21`, user `leikwan`, macOS 26.6.2 build 25G83, arm64. SSH works after public-key authorization and storing the private key with mode 0600 inside WSL rather than using the Windows-mounted 0777 path.
- Native reproduction found a second defect: `codesign --verify --deep --strict` failed with `Disallowed xattr com.apple.FinderInfo` on `Contents/MacOS/createdump`. Cleaning only FinderInfo/ResourceFork from a staged copy made the old signature verify. No quarantine flag or system Gatekeeper policy was removed.
- Native deep signing of managed DLLs in `Contents/MacOS` produces `com.apple.cs.*` xattrs, which the existing updater does not restore from AppleDouble files. The repair relocates the managed runtime into `Contents/Resources/dotnet`, uses the .NET SDK CreateAppHost task to embed that relative entry path, and leaves only the native launcher under `Contents/MacOS`.

## Changes Prepared

- `scripts/verify-macos-dmg-layout.py` requires one root-level app, the exact `/Applications` symlink, correct bundle identity/version/architecture and executable mode.
- The native package script now verifies and mounts the completed DMG and invokes this gate. It retains the mount directory if cleanup cannot detach it.
- The macOS workflow runs the nine layout regression tests and validates the actual mounted image.
- Existing packaging tests still pass. Local evidence: `.tmp/macos-install-repair-20260906/validation.json` and adjacent logs.
- Remote read-only audit is prepared at `.tmp/macos-install-repair-20260906/audit-test-mac.sh`. It downloads the exact public 1.0.8 artifact, verifies its hash, mounts it, and records Applications-link, Apple codesign and Gatekeeper outcomes independently. It does not disable Gatekeeper or modify the installed app.

## Next Steps

1. Configure a Developer ID Application identity and notarization credentials, or obtain an explicit decision to publish with a documented per-app Open Anyway requirement. `security find-identity -v -p codesigning` found 0 identities; default Gatekeeper remains rejected (exit 3).
2. Re-sign/notarize as applicable, then publish the native-generated 1.0.9 Mac-only artifact matrix with backup-first guards; do not modify other platforms/PHP.
3. Re-download the public final bytes and verify mounted DMG layout, Apple signature integrity, and updater-style ZIP extraction.

Apple's documented per-app manual exception is System Settings > Privacy & Security > Open Anyway after attempting to open the app: https://support.apple.com/102445. This is a temporary user action, not a substitute for signing/notarization or a reason to disable system-wide protection.

## Native Acceptance

- Managed app cross-published from .NET 8.0.419 on Windows; native helpers were retained from verified inputs, not recompiled. Packaging/signing/DMG creation used the new Mac's Apple `codesign`, `ditto`, `hdiutil`, `sips` and `iconutil`.
- A pinned standalone Python 3.13.15 was installed only in the build user's private tool directory (SHA `b9054a9d3d54f4cb5573d44907fddb29874b08909bde73f29f2868cf872223ee`). No Xcode/system Python replacement, certificate fabrication or sudo was used.
- Both architecture DMGs mount, contain the exact `/Applications` shortcut, show app version 1.0.9 and pass Apple strict/deep signature verification.
- Both updater ZIPs were extracted as regular file bytes plus executable modes, without xattrs or `__MACOSX` restoration; both still pass Apple strict/deep verification. 231 file hashes per architecture match the DMGs.
- Installed at `/Applications/LeiKwan Bridge.app` on the test Mac, `open` returned 0, and WindowServer reported a visible 1180x668 app window. The installation was backed up before replacement. Intel execution is untested because Rosetta is absent; Intel packaging, architecture and signature checks passed.
- Native test JSON: `.tmp/macos-install-repair-20260906/native-acceptance.json`; build/launch/audit logs reside alongside it and on the Mac under `/Users/leikwan/leikwan-native-package-1.0.9/acceptance`.
- Mac unit tests: 71/71. Layout tests: 9/9. Metadata/signature-order tests: 3/3. Existing packaging tests plus the new CRLF helper-manifest fixture pass.
- Implementation commit: `56d7726a`; previous layout gate: `27579f83`. Exact source diff and artifact manifests are retained with local native evidence. The packaging metadata's `27579f83` field denotes the base checkout; the final source includes the recorded repair commit.
- A separate Avalonia 11.3.20 display-sleep startup error (`RenderTimer`, native code `-6661`) was reproduced on the virtual Mac when it had no awake display. Waking the display allowed normal launch. This matches upstream issue https://github.com/AvaloniaUI/Avalonia/issues/18895 and is not claimed fixed by packaging. No dependency-wide graphics upgrade was included.
- Screenshots failed due to screen-capture permission/display access; no screenshot-based visual acceptance is claimed. WindowServer visibility/bounds and successful installed startup are the actual evidence.

## Artifact Matrix

| Artifact | Bytes | SHA-256 |
|---|---:|---|
| arm64 DMG | 76865794 | `fb29462f6f951387ec663f5d46d786f84afd562c2f8f0b291b455a538b2e4274` |
| x64 DMG | 81298606 | `bada54a0ed417b7ed714a115024821243e545b730ec94be8ead15369f23c1915` |
| arm64 update ZIP | 68265412 | `3efd56bbd610bcf527759703953b10c63e7bbd403ab3a83c2a20f3cb549d5f7e` |
| x64 update ZIP | 72542005 | `c4c74d621bd19ce1073ebb03763a3a9f2aabb42f8b4463c53724071d10d4985e` |
