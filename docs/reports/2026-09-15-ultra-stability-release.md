# Ultra stability release 2026-09-15

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
| Linux amd64 Ultra runtime | `2026.09.15.1` | `98d2a3220232784d1da5380d272ae8db368b89a9710e4cda07d72fcea66509ad` | 16,318,730 |
| Slave agent | `2026.09.15.1` | `75ba376870e1e71a69a09d0a12b2f8b4b201278e3f563e555f6d820fadbad739` | 355,310 |

Backups are retained on the panel at:

- `/root/leikwan-br1-backups/ultra-stability-20260914T204933Z`
- `/root/leikwan-br1-backups/slave-stability-20260914T205848Z`

The Linux runtime attestation is `2026.09.15.1.4ea492e7`.

## Verification

- `go test -count=1 ./...`: pass (`157.142s`).
- Focused slave-agent tests: 9 pass.
- Four focused PHP candidate/health contract tests: pass.
- Mac no-GUI run for forwards 159/170/171: connected, runtime
  `2026.09.15.1.4ea492e7`; the original embedded runtime was restored and no
  test processes or temporary files remained.
- Windows standalone runtime: `2026.09.15.1.4ea492e7` reported by `-version`.
- Linux forward 170 used the new runtime (PID `3491564`) while forwards 158,
  159, 171, and 180 remained running. At the last reachable 50-minute soak
  sample: tunnel UDP `81,661/81,663`, tunnel TCP `1` connection / `0` breaks /
  `2,636/2,636` heartbeats. Lease refresh delivered 64 successful batches or
  per-forward renewals after the test restart; one failed batch retained the
  successful batches.

## Acceptance gap

The soak unit was still active at the 50-minute sample and the test host became
network-unreachable at the one-hour boundary. The final `/root/soak-stability-20260915/summary.json`
and final unit state could not be retrieved, so the full one-hour gate is not
marked PASS here.

The panel review queue still had no post-cooldown row for line 581. The SS-framed
target retest remains blocked because the target Shadowsocks method/password are
not persisted on the source host. The historical forward-184 ~6.2% zero-downstream
incident and advertised-vs-live relay window mismatch remain open investigations.

Full macOS/Windows/Android installer packages were not rebuilt: this Windows
environment lacks `rcodesign`, `codesign`, `hdiutil`, `ISCC.exe`, Gradle/SDK, and
`adb`. Only the Linux runtime and slave-agent artifacts are published in this
release.
