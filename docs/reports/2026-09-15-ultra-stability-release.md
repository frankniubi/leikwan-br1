# Ultra stability release 2026-09-15

## Published package revision

The package revision is `2026.09.15.2`, fingerprinted to source commit
`f10c1845`. The package contains the same stability fix described below and
was rebuilt for Linux, Windows, macOS, and Android.

## Scope

This release addresses the `no_healthy_candidate` false-negative path for Ultra
clients. TCP availability is a transport check against `1.1.1.1:443`; it does
not send an application header or require a response from an encrypted peer.

## Changes

- TCP and UDP probe accounting are independent. A TCP-only result no longer
  creates a UDP failure or UDP reputation evidence.
- Protocol-silent user TCP streams are telemetry only. They do not quarantine a
  candidate because an encrypted destination may legitimately return no bytes.
- LeiGod UDP health uses a bounded five-sample quality window, accepts up to 25%
  loss, and uses one-window hysteresis before removing a previously healthy line.
- Runtime and slave-agent status retain the UDP sample/loss/latency telemetry.

Source fix: `4ea492e7` (`fix(ultra): separate reachability from protocol-silent traffic`).
The release artifact also includes the parsed `1.1.1.1:443` TCP probe target.

## Published artifacts

The guarded panel publication was verified against the public manifest and
download bytes:

| Artifact | Version | SHA-256 | Size |
| --- | --- | --- | ---: |
| Linux amd64 Ultra runtime | `2026.09.15.2` | `b0abed28b8c34f65529d05068398c7d951ee9948867a4a0fda4e1a51df70b9f3` | 16,273,674 |
| Linux arm64 Ultra runtime | `2026.09.15.2` | `52e3f9c9ddac5daa469a1b250e1aa13148e6df71b02db8ab43a35f415ae74079` | 14,614,794 |
| Windows amd64 Ultra runtime | `2026.09.15.2` | `07015b2d0b0d5a571a36434f64607c251b8337293c173b5d9630f798326673d2` | 7,115,264 |
| macOS arm64 Ultra runtime | `2026.09.15.2` | `7f52b6b2f66304147804a96b8ce93e21000b6caf54bd70f1195994f64bb9407d` | 13,396,818 |
| macOS x64 Ultra runtime | `2026.09.15.2` | `063ebe185c62934d672d62f93c5390febaa34c4cd4e18962c69f35ccdcfef989` | 15,006,592 |
| Android arm64 Ultra runtime | `2026.09.15.2` | `7889d078706a3a8a1ea77d3e2abec2b27d8d3fbda06b2144c4cded4951dd9e69` | 14,614,788 |
| Android x86_64 Ultra runtime | `2026.09.15.2` | `f0ac8f1a447650b4a457b6d9f32de81a37d80e6d99da5180b0e5e6656ed0936c` | 16,332,544 |
| Windows setup | `0.1.20.48` | `a9f2bac6be9c3d680f8d1ba8eba01202b6a3d90c78943937327064cde3b9ccf9` | 69,566,084 |
| Android APK | `0.2.29` / versionCode 31 | `b145146889bd2a8473cee9910500bfb5508f8b17fb842b4cea51b6b01d30054f` | 89,563,483 |
| macOS arm64 update ZIP | `1.0.38` | `b547a105b51c75c7e2f9728a94f841b967dbebd8954d1d494f76e90ea31dea55` | 68,953,768 |
| macOS x64 update ZIP | `1.0.38` | `a58a72797e495f39ca870fa36d09a4c4e769ce0251f510750dedbeba68b06a91` | 72,861,401 |
| macOS arm64 DMG | `1.0.38` | `353edb78e466756e40c4419e1a4d2209db2c3c2b2379e5759115d433f2183a85` | 77,455,932 |
| macOS x64 DMG | `1.0.38` | `57c7ed9e024cdaf6908cf5644126f23f5e44df99767b0425d05ea11d007224f7` | 81,670,994 |
| Slave agent | `2026.09.15.1` | `75ba376870e1e71a69a09d0a12b2f8b4b201278e3f563e555f6d820fadbad739` | 355,310 |

Backups are retained on the panel at:

- `/root/leikwan-br1-backups/ultra-stability-20260914T204933Z`
- `/root/leikwan-br1-backups/slave-stability-20260914T205848Z`

The new Ultra runtime attestation is `2026.09.15.2.f10c1845`.

## Verification

- `go test -count=1 ./...`: pass (`157.142s`).
- Focused slave-agent tests: 9 pass.
- Four focused PHP candidate/health contract tests: pass.
- Mac no-GUI run for forwards 159/170/171: connected, runtime
  `2026.09.15.1.4ea492e7`; the original embedded runtime was restored and no
  test processes or temporary files remained.
- Windows runtime `-version` reports `2026.09.15.2.f10c1845`; the rebuilt setup
  embeds the same hash and carries app version `0.1.20.48`.
- Android release APK is signed with v2/v3, version `0.2.29` / versionCode 31,
  and its arm64/x86_64 JNI and asset runtime copies are hash-identical.
- macOS arm64/x64 update ZIPs were rebuilt on the Mac test host, ad-hoc signed,
  and the DMGs were created with the full `.app` bundle and passed
  `hdiutil verify`.
- Linux forward 170 used the new runtime (PID `3491564`) while forwards 158,
  159, 171, and 180 remained running. At the last reachable 50-minute soak
  sample: tunnel UDP `81,661/81,663`, tunnel TCP `1` connection / `0` breaks /
  `2,636/2,636` heartbeats. Lease refresh delivered 64 successful batches or
  per-forward renewals after the test restart; one failed batch retained the
  successful batches.
- This is a forward-170 bidirectional data-plane measurement. The other four
  forward services were checked for continuity only; they do not have a
  separate one-hour traffic result in this run.

## Acceptance gap

The soak unit was still active at the 50-minute sample and the test host became
network-unreachable at the one-hour boundary. The final `/root/soak-stability-20260915/summary.json`
and final unit state could not be retrieved, so the full one-hour gate is not
marked PASS here.

The panel review queue still had no post-cooldown row for line 581. The SS-framed
target retest remains blocked because the target Shadowsocks method/password are
not persisted on the source host. The historical forward-184 ~6.2% zero-downstream
incident and advertised-vs-live relay window mismatch remain open investigations.

The Linux one-hour soak remains an acceptance gap: the host became unreachable
at the boundary, so this release does not claim a completed one-hour PASS.
The Windows and macOS package builds are packaging/format verified; they do not
replace the missing full Windows data-plane soak or the incomplete Linux soak.
