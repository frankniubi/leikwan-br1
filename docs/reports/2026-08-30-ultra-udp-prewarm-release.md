# Ultra UDP Prewarm Release Report

Date: 2026-08-30
Release scope: Windows, Linux, and the shared Ultra runtime contract. Android is intentionally deferred.

## Shipped Behavior

- UDP-capable Ultra forwards start one asynchronous provider UDP association as soon as the local listener is running.
- The first local UDP association consumes the warm session and flushes its bounded queue; later client associations retain lazy setup and existing worker limits.
- Active associations send the existing protocol-reserved keepalive envelope every 5 seconds. The destination is `2.0.0.0:0` and the payload is the existing `keepPayloadHex`; no arbitrary game/application payload is generated.
- Reserved keepalive replies are consumed inside the runtime and cannot be emitted to the local game socket or counted as user traffic.
- Linux config pulls now carry the desired Ultra helper hash/version, so an installed helper mismatch requests the existing authenticated update path.
- Windows/macOS bootstrap config emits `udp_prewarm=true` and `udp_keepalive_interval_seconds=5` only for `udp`/`tcp+udp`; TCP-only payloads are unchanged.

## Artifact Evidence

| Artifact | Version | SHA-256 | Size |
|---|---:|---|---:|
| Linux Ultra runtime | `2026.08.30.4` | `0a3932e023b5de90da0b6792c672bd5c2e275060013e1c31ba6980d0bdb22d11` | 15,462,666 |
| Windows Ultra runtime | `2026.08.30.4` | `59d6fa47896e51308749850098f6f61fdbdc01525ca3f1bc992f22ab59fbad74` | 6,699,008 |
| Windows setup | `0.1.19.9` | `af0ba604300a76b6f153a8384c9d9f957a8179c1f235fe3b0b101fea02c3d425` | 22,345,758 |
| Linux client-agent/install-forward runtime | `2026.08.30.4` | source/artifact mirrors verified | n/a |
| macOS arm64 DMG | `1.0.4` | `7e29b18bf0c783d6e60a0b5d132970ed0de10a62f8b2e8f626f21a35b32caa25` | 74,759,413 |
| macOS arm64 update ZIP | `1.0.4` | `907aaba99de47a01d3c57293b72c30062601016d761ae1cfc3b1fc819eb76c24` | 65,646,509 |
| macOS x86_64 DMG | `1.0.4` | `01b7a4606abda3f345ad05a4e0096600cd9c52e34e62218f95c80c8a9ecd8284` | 78,015,346 |
| macOS x86_64 update ZIP | `1.0.4` | `acf9960a960ebe4268f6aab49d32c0220bb72fbc130ef0a90bca5b84d8b7198b` | 68,762,844 |

Linux was built with the existing garble/reproducibility script. Windows was built locally with `GOOS=windows GOARCH=amd64 CGO_ENABLED=0`, `-trimpath`, and stripped linker flags; the local garble output was rejected by Windows Defender as a false-positive executable, so the published Windows helper does not claim garble hardening metadata.

## Verification

- Go: `go test -count=1 ./...` and `go vet ./...` passed in `tools/ultra-client`.
- Go repeatability: `go test -count=3 ./...` passed.
- Go race: WSL race suite passed; native Windows race could not run because `gcc` is unavailable. The garbled Windows test executable was also blocked by Defender during the attempted cross-platform race build.
- Python: full client-agent suite passed, `380 tests OK`.
- PHP: Ultra artifact distribution, manifest consistency, Windows app version, revision, and Linux Ultra contract tests passed. PHP emitted the pre-existing `date.timezone=UTC+8` warning and still returned success.
- .NET: full solution passed, Core `255`, Mac `52`, App `42`.
- Source/artifact SHA-256 mirrors for `client-agent.py` and `install_forward_runtime.py` matched.
- Windows setup staging contained the exact published Windows Ultra SHA-256.
- The live Panel artifact source was updated after backups `ultra-udp-prewarm-20260830-1`, `ultra-udp-prewarm-20260830-2`, and `ultra-udp-prewarm-20260830-3`; root artifacts and `master/public/artifacts` shadows match for the Ultra binaries, Python mirrors, Windows setup, macOS packages, and `versions.json`.
- HTTPS manifest canary from `https://v3.leikwanhost.com/artifacts/client-manifest.json` returned Linux/Windows Ultra `2026.08.30.4`, Windows app `0.1.19.9`, and macOS `1.0.4` entries with the recorded hashes. Direct range GETs returned HTTP 206 for all four macOS packages.

## Platform Sample Status

- Windows sample: local Release publish and Inno Setup package completed; static bootstrap and bundled-helper hash checks passed. The all-users `C:\Program Files\LeiKwan Bridge` install was force-replaced with `0.1.19.9`, both root/bin Ultra helpers match the published SHA-256, and `leikwan-client-win.exe` was relaunched. Rollback copy: `C:\ProgramData\LeiKwan Bridge\backups\force-replace-20260830-114020`.
- Linux sample: the live Panel artifact source and static shadows are updated; installer ordering and authenticated desired-hash convergence are covered by tests. No remote Linux client was restarted or mutated; each live client must report the new helper hash after its next authenticated pull.
- macOS sample: arm64 and x86_64 Release packages were built on the remote macOS builder with the final Ultra runtime source, then published and hash-verified. No end-user macOS runtime smoke test was run; device-level launch/forward validation remains an operational follow-up.

## Client Update Guidance

- Linux: allow the authenticated client-agent config pull to see `desired_ultra_runtime_sha256`; it will request the existing signed installer/update helper and report convergence only after the installed helper hash matches.
- Windows: use the existing app update checker to download `windows_client_app` `0.1.19.9`; the installer verifies the bundled Ultra helper against `ultra_client_runtime_windows` before handoff.
- macOS: the authenticated manifest now advertises `1.0.4` for both `arm64` and `x86_64` DMG/update channels; Android remains deferred.

## Rollback

Restore the previous manifest pointers and binaries (Linux Ultra `2026.08.30.2`, Windows Ultra `2026.08.30.3`, Windows app `0.1.19.8`, macOS `1.0.2`) from the dated production backups, then restart only the previously owned client/runtime processes. Do not remove unknown processes or foreign listeners, and do not restore credential-bearing files.
