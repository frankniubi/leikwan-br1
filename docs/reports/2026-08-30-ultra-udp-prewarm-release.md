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
| Linux Ultra runtime | `2026.08.30.4` | `56d56d6d5de1cd0c98cb34221b26fc50fb8792c7ff31eee24d8024c060248a56` | 15,528,202 |
| Windows Ultra runtime | `2026.08.30.4` | `5fac22da77dc6d6cc81abb8f824fe57cd9dff736655f498af36236f4df5a7d47` | 6,695,936 |
| Windows setup | `0.1.19.9` | `ddc54a76f1b65544692925d9aa76bbdb423834380cc0bae4cfa6dc2f720b3450` | 22,341,822 |
| Linux client-agent/install-forward runtime | `2026.08.30.4` | source/artifact mirrors verified | n/a |

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

## Platform Sample Status

- Windows sample: local Release publish and Inno Setup package completed; static bootstrap and bundled-helper hash checks passed. A live Program Files replacement was not performed in this worktree because it would stop the user-owned running client and requires an operational maintenance window.
- Linux sample: installer ordering and authenticated desired-hash convergence are covered by tests. No remote Linux mutation was performed; a live client must report the new helper hash after its next authenticated pull.
- macOS sample: not built or published. The repository has no macOS artifact directory in this environment, and `build-macos-client.sh` correctly refused to run on Windows (`real builds require macOS`). Existing macOS manifest entries remain unchanged; no false macOS release pointer was created.

## Client Update Guidance

- Linux: allow the authenticated client-agent config pull to see `desired_ultra_runtime_sha256`; it will request the existing signed installer/update helper and report convergence only after the installed helper hash matches.
- Windows: use the existing app update checker to download `windows_client_app` `0.1.19.9`; the installer verifies the bundled Ultra helper against `ultra_client_runtime_windows` before handoff.
- macOS: keep the current `1.0.2` channel until a macOS build host produces and verifies both `arm64` and `x86_64` packages; Android remains deferred.

## Rollback

Restore the previous manifest pointers and binaries (`ultra_client_runtime` `2026.08.30.2`, Windows Ultra `2026.08.30.3`, Windows app `0.1.19.8`), then restart only the previously owned client/runtime processes. Do not remove unknown processes or foreign listeners, and do not restore credential-bearing files.
