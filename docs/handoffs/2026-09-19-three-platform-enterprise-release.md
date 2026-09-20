# Enterprise UoT coordinated release — 2026-09-19

## Result and exact deployment scope

- Source/artifact release commit: `4fd3c15c` on the master-derived
  `feat/enterprise-uot-20260919` branch. Later commits add test-fixture fixes,
  packaging metadata accuracy and acceptance documentation, not runtime changes.
- Production publication: **committed** at `2026-09-19T08:50:20.294786+00:00`.
  **23 public manifest entries** and **24 payload paths** pass complete-download
  SHA-256 and byte-count verification. Payloads were uploaded serially and the
  manifest was installed last; PHP-FPM and `/api/health` passed postflight.
- macOS **1.0.45**: arm64/x64 DMGs and update ZIPs, headless installer,
  standalone helpers and bundled helper identities are aligned.
- Windows **0.1.20.52**: desktop setup, headless ZIP and installer aligned.
  The headless ZIP uses the outer-directory layout required by existing updaters.
- Linux agent/installers/updater/renderer **2026.09.19.1**: frozen amd64 agent,
  shell installers, and rebuilt arm64 offline installer published.
- Ultra runtime and external UoT sidecar: **2026.09.19.1**;
  runtime build fingerprint `2026.09.19.1.f18d01fb`.
- macOS test machine is running the installed `/Applications/LeiKwan Bridge.app`
  **1.0.45**, not a wrapper or temporary test bundle.
- Linux test machine is upgraded, including five active Ultra runtime services.
- **Windows test machine's administrator-owned running installation has NOT
  been replaced.** `lwtest` is not an administrator; normal service control and
  installed-client update operations return access denied. Final artifacts and
  a hash-verified administrator installation script are staged at
  `C:\Users\lwtest\release-20260919`. No privilege bypass was attempted.
  See `2026-09-19-linux-windows-release-deployment.md`.

## Final installed macOS acceptance: PASS

- UTC interval: `2026-09-19T08:58:32.557966Z` → `2026-09-19T09:10:44.911321Z`.
- 720-second measurement, bounded completion drain; SS-none loopback port10001.
- Each protocol requested 20,000,000 bit/s; 128 TCP workers.
- TCP: **13440 successful, 0 failed**, measured
  **20.090968 Mbps**.
- UDP: **1,500,000 unique / 1,500,000 server-sent**, **0 missing**,
  0 duplicate/invalid; measured **19.993400 Mbps**.
  Drain arrivals are excluded from the throughput numerator.
- Every UDP packet uses
  `client -> leased Ultra provider relay -> external UoT sidecar -> UDP target`.
  Reliable mode comes from the managed panel grant, not a bootstrap wrapper.
- Stable runtime PID `39535`; observed CPU approximately
  **35.19% of one core**, sampled peak RSS **70.14 MiB**.
- Runtime self-SHA256:
  `a02191fbd33910b6e611e13f77f1891ddcefc0319903450aa28cd896f667066f`.
- Reliable status: queue drops/timeouts, sequence gaps and unrecoverable frames
  all zero, no failure, final retained window 0 packets.
- macOS socket receive-drop accounting is unsupported (`null`), not zero.
- Harness field `reliable_udp_verdict=NOT_TESTED` is retained unchanged;
  negotiated reliable-mode proof is separately supplied by the panel grant,
  runtime status and sidecar session counters.

## Retained failed run / reliability boundary

The initial post-deployment 12-minute run overlapped the large artifact
publication. It **failed**: TCP 12,287 successes with body/header failures;
UDP 715,984/1,500,000. The sidecar logged carrier EOF at 08:49:49 UTC
and reliable admission deadline exhaustion at 08:49:56 UTC.
Managed clients also logged control-plane request timeouts around 08:50:33 UTC.
Temporal overlap does not prove that publication was the sole cause.
This failure is not erased by the later uninterrupted PASS.

The stable run above began after publication completed and the installed app
was restarted; it did not change binary, grant token, candidate allowlist or
transport limits to manufacture a pass. The reliable window remains bounded
at 4096 packets / 16MiB per direction with 5-second admission. This release does
not establish zero loss across arbitrary carrier/control-plane outages, durable
exactly-once delivery, or application-level ACK semantics. FEC remains disabled.

## Sidecar and rollout safety

- Tokyo production sidecar7443 runs SHA
  `c6d11563b8c1b8a28f2d8b3a9422d4215ccc67755a0d0d32e059eba40dfd5317`.
- Owned Shadowsocks target runs the validated bounded-UDP-output patch:
  `606559fc9225e27470f3068a2d7bc51ffceb838e0df6e18865551c763b311b67`.
  Patch/source/build instructions are under `tools/uot-acceptance/patches/`.
- Reliable grant activation requires both an explicitly selected client/device
  and an upgraded sidecar endpoint. Legacy Windows and other sidecars are not
  forced onto an unsupported wire protocol.
- Existing raw delivery semantics still use the external UoT sidecar; raw does
  not mean direct Internet UDP. Linux's initial raw smoke lost 3/125,000 packets;
  its reliable smoke subsequently received 125,000/125,000 with TCP 1152/0.
- Backups: panel `/root/codex-backups/enterprise-uot-20260919-1`,
  panel config `/root/codex-backups/enterprise-uot-panel-20260919`,
  Tokyo `/root/uot-enterprise-release-backup-20260919`,
  Mac `/Users/leikwan/backups/enterprise-release-20260919`.
  Disable reliable grant opt-ins before rolling back to old runtimes/sidecars.

## Build and regression verification

- Go ultra-client complete suite: PASS (161.340s).
- Core complete suite: initial 598/599 exposed an existing unsynchronized
  test-recorder List.Add race; after test-only locking, rebuilt **599/599 PASS**
  plus repeated focused **10/10 PASS**. Production assemblies did not change.
- Mac managed suite **227/227 PASS** (run on Windows; not native x64 proof).
- Sidecar suite initially saw an empty control frame in the replay fixture;
  the test previously assumed the next frame was UDP_DATA despite a 50ms
  KEEPALIVE. Test-only parsing now skips KEEPALIVE under the original deadline,
  requires actual UDP_DATA, and checks exactly two target packets. The corrected
  complete suite passed five repetitions (16.761s).
- Publisher **14/14 PASS**; Linux delivery-mode **3/3 PASS**;
  shared client/sidecar transport sources **10 byte-identical files**.
- arm64 installer: two byte-identical builds and QEMU 12-file self-test PASS.
- macOS package signatures and DMG layout verified; signatures are ad-hoc,
  **not notarized**. x64 managed IL was actually cross-loaded on the arm64
  host (exit2, hash-bound receipt); **native Intel execution remains untested**.
- Mac independent helper bytes are extracted from the signed bundle so their
  manifest attestation exactly matches the installed runtime.
- Ultra binaries are stripped, non-obfuscated Go builds; manifest metadata
  no longer incorrectly claims garble hardening.

## Local evidence

`tmp/release-20260919/` contains `plan.json`, `publish-receipt.json`,
`public-verification.json`, `mac-stable-720.json`, `mac-stable-metrics.jsonl`,
the retained failed `mac-release-720.json`, sidecar failure logs, native
installation readbacks, build manifests, and test logs. Secrets are not included
in this report or committed artifacts.
