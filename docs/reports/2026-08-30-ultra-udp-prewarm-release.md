# Ultra UDP Prewarm Release Report

Date: 2026-08-31
Release scope: Windows, Linux, macOS, and the shared Ultra runtime/source contract. Android is intentionally deferred.

## Shipped Behavior

- UDP-capable Ultra forwards start one asynchronous provider UDP association as soon as the local listener is running.
- The first local UDP association consumes the warm session and flushes its bounded queue; later client associations retain lazy setup and existing worker limits.
- Active associations send the existing protocol-reserved keepalive envelope every 5 seconds. The destination is `2.0.0.0:0` and the payload is the existing `keepPayloadHex`; no arbitrary game/application payload is generated.
- Reserved keepalive replies are consumed inside the runtime and cannot be emitted to the local game socket or counted as user traffic.
- Auto selection for UDP-capable forwards probes only `1.1.1.1:53` for a 30-second window (30 samples, 1 second apart), ranks usable legs by loss/jitter/latency with paid-tier preference, and keeps the selected leg sticky within one policy generation.
- Linux config pulls now carry the desired Ultra helper hash/version, so an installed helper mismatch requests the existing authenticated update path. Source 171 unions local/index/audit line records, retries only failed acquisitions after a throttled re-login, and never clears working material on a transient login failure.
- Source session files are copied atomically and kept mode `0600`; the source service remains outbound-only and continues its normal rotation loop.
- Windows/macOS bootstrap config emits `udp_prewarm=true` and `udp_keepalive_interval_seconds=5` only for `udp`/`tcp+udp`; TCP-only payloads are unchanged.

## Artifact Evidence

| Artifact | Version | SHA-256 | Size |
|---|---:|---|---:|
| Linux Ultra runtime | `2026.08.31.3` | `88ab40954be6abc78e99970908dab701f6ca777322c8b5379244f4ead711326e` | 15,831,306 |
| Windows Ultra runtime | `2026.08.31.3` | `4863e107326af756996a5e8f3edad5d758a57a64368276bb91be21a15bf6f6a6` | 6,750,720 |
| Windows setup | `0.1.20.0` | `8f88466f71cedd888e1483b3ebd507d39aec52c61141c7ba0dd96640dda11786` | 69,361,417 |
| Linux install-forward runtime | `2026.08.31.3` | `dca7be32de466358b40ba80f7496802f8364aa9e75935541938db6392c17628d` | 95,476 |
| macOS Ultra runtime (x86_64) | `2026.08.31.3` | `df1c50d203baba3a3ad7c30c169ec38e6929e0498eb60c8e21d477cd7265a405` | 14,513,280 |
| macOS Ultra runtime (arm64) | `2026.08.31.3` | `6ad978fba62104cb9f8e0f24ab9e91fb1045bddba28b640e86fece0d0529f38a` | 12,957,426 |
| macOS arm64 update ZIP | `1.0.5` | `82678d561a978c1da516fe27268c31efd15ef25816e78e67b72d9d256f9541ca` | 65,893,586 |
| macOS x86_64 update ZIP | `1.0.5` | `ced6b065472d75cc5cc368cd9c8f048b9360045e93d6772ec2b44e72eb68e298` | 69,150,147 |
| macOS arm64 DMG | `1.0.4` | `7e29b18bf0c783d6e60a0b5d132970ed0de10a62f8b2e8f626f21a35b32caa25` | 74,759,413 |
| macOS x86_64 DMG | `1.0.4` | `01b7a4606abda3f345ad05a4e0096600cd9c52e34e62218f95c80c8a9ecd8284` | 78,015,346 |

Linux and macOS Ultra helpers were built with the existing garble/reproducibility toolchain. Windows was built locally with `GOOS=windows GOARCH=amd64 CGO_ENABLED=0`, `-trimpath`, and stripped linker flags; the local garble output was rejected by Windows Defender as a false-positive executable, so the published Windows helper does not claim garble hardening metadata. The macOS update ZIPs contain the refreshed helper; the existing DMGs remain `1.0.4` because an APFS DMG rebuild is not available on this Windows host.

## Verification

- Go: `go test -count=1 ./...` and `go vet ./...` passed in `tools/ultra-client`.
- Python: source-agent suite passed, `23 tests OK`; the client-agent mirror check and generated-runtime tests passed.
- PHP: Ultra inventory/material/lease/multi-source, Linux runtime, artifact, and macOS install-surface contracts passed. PHP emitted the pre-existing `date.timezone=UTC+8` warning and still returned success.
- .NET: full solution passed, Core `255`, Mac `52`, App `42`.
- Source/artifact SHA-256 mirrors for `client-agent.py` and `install_forward_runtime.py` matched. The live source host reports 119 inventory lines: 90 `native_udp`, 18 `dynamic_ingress`, 5 `wireguard`, and 6 `relay_unreachable`; route classes include 89 `direct`, 18 `single_telecom`, 6 `wireguard`, and 6 `relay`. It reports 90 `native_udp/verified` lines, 19 paid lines, and a `0600` session target.
- Windows setup staging contained the exact published Windows Ultra SHA-256. The installed Windows app is `0.1.20.0`; its root/bin Ultra helper hashes match the release manifest.
- Live Panel root and `master/public/artifacts` manifest shadows were synchronized under backup `manifest-20260831T004021Z`; the source host update is backed up under `/root/codex-backups/ultra-source-20260831T001233Z`.
- HTTPS range GETs returned HTTP `206` with the expected sizes/ETags for Linux, Windows, Windows setup, and both macOS update ZIPs. The fetched manifest reports Ultra `2026.08.31.3`, Windows app `0.1.20.0`, and macOS update `1.0.5`.
- Local acceptance through Shadowsocks `127.0.0.1:10001` completed twice with five consecutive TCP+UDP rounds; the final run was `5/5 TCP+UDP PASS` with no failures.
- Native Windows `go test -race` remains unavailable because the environment has no `gcc`; this does not affect the passing non-race suite.

## Platform Sample Status

- Windows sample: local Release publish and Inno Setup package completed; static bootstrap and bundled-helper hash checks passed. The all-users `C:\Program Files\LeiKwan Bridge` install was force-replaced with `0.1.20.0`, both root/bin Ultra helpers match the published SHA-256, and `leikwan-client-win.exe` was relaunched. Rollback copy: `C:\ProgramData\Leikwan\ClientAgent\backups\desktop-codex-20260831-0730`.
- Linux/source sample: the live Panel artifact source and static shadows are updated; source 171 is active and continues periodic material rotation. The latest inventory upload is recorded by the Panel, with `last_error_code` empty and accepted material cycles continuing. Its partial-failure path retries only failed lines after a throttled re-login and preserves successful/previous material.
- macOS sample: arm64 and x86_64 update ZIPs contain the refreshed `1.0.5` app/helper bundles and were hash-verified. End-user macOS device launch/forward validation was not available on this Windows host; the published DMGs remain `1.0.4`.

## Client Update Guidance

- Linux: allow the authenticated client-agent config pull to see `desired_ultra_runtime_sha256`; it will request the existing signed installer/update helper and report convergence only after the installed helper hash matches.
- Windows: use the existing app update checker to download `windows_client_app` `0.1.20.0`; the installer verifies the bundled Ultra helper against `ultra_client_runtime_windows` before handoff.
- macOS: use the authenticated update ZIP entries `1.0.5` for `arm64` or `x86_64`; the existing DMG entries remain `1.0.4` until rebuilt on macOS. Android remains deferred.

## Rollback

Restore the previous manifest pointers and binaries from `/root/codex-backups/manifest-20260831T004021Z` and the earlier release backups, then restart only the previously owned client/runtime processes. The source-agent rollback is `/root/codex-backups/ultra-source-20260831T001233Z`. Do not remove unknown processes or foreign listeners, and do not restore credential-bearing files.
