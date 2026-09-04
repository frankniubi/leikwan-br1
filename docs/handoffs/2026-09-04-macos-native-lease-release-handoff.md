# macOS 1.0.7 native lease release handoff

- Date: 2026-09-04 (Asia/Taipei)
- Scope: macOS client package and bundled Ultra runtime only.
- Source: `e4f03370` plus the Mac-only version and contract-test changes in this release.
- Test host: Apple Silicon macOS 26.6.2 (Darwin 25.6.0), with both `osx-arm64` and `osx-x64` targets.
- Direction fixture: forward `180`, `CAN -> HKG`, route scope `global`, transport `tcp+udp`.

## Changes

- Bumped `LeikwanBridge.Mac` to version `1.0.7`.
- Added a Mac config-builder regression for the `180 / CAN -> HKG / global` native lease. It verifies the scope digest, direction-bearing lease, direct low-port bypass, provider endpoint bypass, and proxy final route.
- Built the Mac app and bundled Ultra runtime from the latest shared runtime. Ordinary Ultra uses the legacy greeting first; native Ultra keeps current greeting first with fallback behavior from the shared runtime.
- No Windows, Android, PHP, or server files were changed by the Mac build.

## Build and verification

- Remote build used pinned `garble v0.17.0`, `-literals -tiny`, stripped native helpers, sing-box `v1.13.12`, and wstunnel commit `9d0ec3f27556e3132536401d1b2e4e1aa30c277c`.
- Mac tests: `71/71` passed on the test Mac.
- Core tests and Ultra runtime `go test ./...` passed; `go vet ./...` passed.
- Packaging fixture and both real Ultra leak scans returned `ok`.
- App/helper Mach-O architecture checks passed for arm64 and x86_64; bundle version is `1.0.7`.
- DMG code-sign verification explicitly reports unsigned packages. Signing, notarization, and clean-host installation remain unproven.
- The full Network Extension/TUN path, independent DNS capture, sleep/wake, and sustained traffic tests remain unproven.

## Artifacts

| Artifact | Size | SHA-256 |
| --- | ---: | --- |
| arm64 DMG | 77,854,694 | `0b73922f0806d3118f20cd68b3900d39743d812ab4438a584c92344be9276e81` |
| x86_64 DMG | 81,356,219 | `15da3bbbb67575a29e7c915c5e2a4caa08b9310d0391841cfafca81c846256fb` |
| arm64 update ZIP | 68,500,842 | `2d94fd0f895df8a501f1475f8c107de4083171e3a7de3733ed10175b77acc24d` |
| x86_64 update ZIP | 72,005,046 | `9a01e6956e147fd452f8af39663e372993b986da326e7af513f5d0fad3b48bc2` |
| arm64 Ultra runtime | 13,013,680 | `3f20e9a9524d19c93de1595f9f9bbf6355325ffc43a2b60a1f7d9af562309938` |
| x86_64 Ultra runtime | 14,499,296 | `33cb7a67b61754de536fb5d0bfab9719b8b25413c778b7d1fc7b1ef6eccb3787` |

The canonical files are under `artifacts/macos/` and `artifacts/ultra/`; the manifest entries are version `1.0.7` and runtime version `2026.09.04.3`. The server has not been switched by this handoff.

## Rollback

Before the coordinated release, retain the existing server copies and restore the previous manifest and four Mac packages if a public canary, client startup, or lease probe regresses. Keep the backup until the post-release smoke window closes.
