# macOS 1.0.37 / runtime 2026.09.14.3 — FIELD VERIFICATION (2026-09-14, Mac test host 100.103.253.21)

Author: field-verification subagent. Everything below was measured on the Mac test host through
paramiko (key auth) and, where noted, cross-checked against the live panel's nginx access log.
Nothing on the panel and nothing under master/src was modified. No secret is recorded here.

## 0. HOST / BUNDLE STATE

  macOS 26.6.2 (25G83) arm64, user leikwan, app at `/Applications/LeiKwan Bridge.app` (space in name).
  Installed when this session started:  **1.0.36**, embedded runtime
  `04c8b1eb0dbe084befe6e5bbae2337bce8016e1bd42704b8f3c659a730d13675` (stamp 2026.09.14.2.0efe369f).
  Installed when this session ended:   **1.0.37**, embedded runtime
  `b92a4e3425152307149a6f130e79460d8c22a0624b8c6c0de3cd7e9886c6a832` (stamp 2026.09.14.3.ec224e60),
  codesign --verify --deep --strict = 0, 234 files.
  Prior test state untouched; added dated backups only:
    ~/lkb-smoke/app-backup-1036-pre1037-20260915-011745/LeiKwan Bridge.app  (1.0.36, byte-identical to the pre-update bundle: diff -rq clean, 234 files)
    ~/lkb-smoke/config-backup-20260915-014950.json (snapshot taken while master_url was pointed at the test proxy; then restored)
  All prior DMGs, app-backup-1034, app-backup-1034-pre1036, /Applications/.leikwan-update-1.0.13/14/15.* kept.

## 1. UPDATE CHECK AGAINST client-manifest.json — PASS

The desktop updater does NOT read versions.json. `MacAppUpdateService.CheckAsync` GETs
`<panel>/artifacts/client-manifest.json`, reads `macos_client_update_{arm64|x64}` and
`macos_client_app_{arm64|x64}`, and throws `update_manifest_version_mismatch`
(MacAppUpdateService.cs:99-100) unless the two `version` values are equal as System.Version.
The trigger used was the shipped CLI surface (Program.cs `--cli` → HeadlessCli `update --check`
→ MacClientUpdateService.CheckAsync → MacAppUpdateService.CheckAsync); the GUI cannot be used on
this host because Avalonia aborts without a display device.

Live manifest fields the client reads (fetched from the Mac, 2026-09-14T17:15Z):

    macos_client_update_arm64  version=1.0.37 format=zip  architecture=arm64  size=69026774
                               sha256=2f51a56365d8113f4cbb8f03bc3169d3f25d3fbd786560be67c7da7d7f2e75ec
                               runtime_sha256["bin/ultra-client"]=b92a4e3425152307149a6f130e79460d8c22a0624b8c6c0de3cd7e9886c6a832
    macos_client_app_arm64     version=1.0.37 format=dmg  architecture=arm64  size=77445270
                               sha256=1b57084e2ef4791f757e65fb215728720eec86f10decfd2a8fbed87db5bab515
    (x64: update version=1.0.37 sha=5c96da78fecc6fdf…, app version=1.0.37 sha=6186a7b6afdb0ccc…)

Installed 1.0.36, real panel, real bytes — the check SUCCEEDS and offers 1.0.37:

    $ leikwan update --check          # ~/bin/leikwan wrapper -> "<app>/Contents/MacOS/LeikwanBridge.Mac" --cli update --check
    installed : 1.0.36
    published : 1.0.37
    update    : available (run "leikwan update" to apply)
    exit=0

Independent proof of the URL that was actually fetched (panel nginx access log, Mac egress IP):

    113.74.99.92 - - [14/Sep/2026:17:15:04 +0000] "GET /artifacts/client-manifest.json HTTP/1.1" 200 5807 "-" "-"   <- leikwan doctor
    113.74.99.92 - - [14/Sep/2026:17:15:08 +0000] "GET /artifacts/client-manifest.json HTTP/1.1" 200 5807 "-" "-"   <- leikwan update --check

DISCRIMINATING TEST (same installed client, local mock panel, exact pre-fix live values).
A mock panel on the Mac (`.tmp/field-verify-20260914/fvmock-server.py`) serving
`/artifacts/client-manifest.json` was driven by pointing a scratch HOME's config.json at it:

    A: update=1.0.36 / app=1.0.34  -> exit 134, "Unhandled exception. System.IO.InvalidDataException:
       update_manifest_version_mismatch" with the stack ending in
       MacAppUpdateService.CheckAsync -> MacClientUpdateService.CheckAsync -> HeadlessCli.UpdateAsync
    B: update=1.0.37 / app=1.0.37  -> exit 0, "published : 1.0.37 / update : available"
    C: the REAL live client-manifest.json bytes -> exit 0, "published : 1.0.37 / update : available"

Every one of those three requests was recorded by the mock panel as
`GET /artifacts/client-manifest.json` — the client never asked for versions.json.

After installing 1.0.37 the same check reports the installed build as current, three consecutive
runs, each matched by exactly one client-manifest request in the panel log:

    installed : 1.0.37 / published : 1.0.37 / update : already current   (exit 0; 17:50:05, 17:50:13, 17:50:20)

HISTORICAL FIELD FAILURE — NOT CONFIRMED ON THIS HOST. `~/Library/Logs/Leikwan/ClientAgent/client-2026*.log`
contains 6 `update_check_failed` warnings (2026-09-12 13:44:46, 13:52:43, 22:59:08, 23:01:52, 23:20:28,
2026-09-13 00:24:49). Two are `System.InvalidOperationException: Call from invalid thread` in
`App.get_UpdateViewModel()` (an Avalonia UI-thread defect in the same method); the others follow
`startup_connect_failed type=OperationCanceledException` (app restart). The literal
`update_manifest_version_mismatch` appears in NO log file, and exceptions do render in these logs,
so this host's logs do not evidence the mismatch firing here. What is proven is the failure MODE
(deterministic, reproduced above) and that the live manifest no longer triggers it.

## 2. DMG / ARTIFACT VERIFICATION — PASS

`~/lkb-smoke/arm64-1.0.37.dmg` (sha256 1b57084e…515 == manifest macos_client_app_arm64.sha256,
size 77445270 == manifest size), mounted read-only at `/Volumes/LeiKwan Bridge 1.0.37`:

    codesign --verify --deep --strict  = 0        (adhoc, Mach-O thin arm64)
    CFBundleShortVersionString         = 1.0.37
    CFBundleIdentifier                 = com.leikwan.bridge
    bin/ultra-client sha256            = b92a4e3425152307149a6f130e79460d8c22a0624b8c6c0de3cd7e9886c6a832  == b92a4e34…
    bin/ultra-client --version         = 2026.09.14.3.ec224e60
    file                               = Mach-O 64-bit executable arm64

`~/lkb-smoke/x64-1.0.37.dmg` (sha256 6186a7b6… == manifest, size 81774208): strict verify = 0,
CFBundleShortVersionString 1.0.37, embedded ultra-client sha256 fc84b9d256f8b968… == fc84b9d2… arm64
host cannot execute the x86_64 binary so no stamp was read for x64 ("Bad CPU type").
Update ZIPs, whose bytes are what `leikwan update` installs, match the manifest exactly:
u37-arm64.zip sha256 2f51a563… size 69026774, runtime inside b92a4e34… / 2026.09.14.3.ec224e60,
strict verify 0; u37-x64.zip sha256 5c96da78… size 73041044, runtime inside fc84b9d2….

## 3. NEW DEFECT — THE ZIP UPDATE APPLIES *NOTHING* AND REPORTS SUCCESS

`leikwan update` (1.0.36 -> 1.0.37, 2026-09-14T17:18:03Z, exit 0) printed

    update    : downloading https://v3.leikwanhost.com/artifacts/macos/leikwan-bridge-update-arm64.zip?v=1.0.37&sha256=2f51a563…
    applied    : the update launcher is replacing the bundle
    update    : applied 1.0.37

The installed bundle was still 1.0.36 afterwards (version, runtime sha and `diff -rq` vs the pre-update
backup all unchanged), i.e. the user is told the update was applied when it was not.

Reproducer and differential (all with real, hash-matching bundles, verified by sha256):

    applier launched from the STAGED bundle (as MacAppUpdateService.ApplyAsync does:
    `_launcher.StartProcess(stagedExecutable, ["--apply-update", requestPath])`)
        -> `--apply-update` exits 1, prints nothing, rolls back: staged bundle deleted, original
           bundle restored byte-identical. Reproduced 3x (1 real CLI update + 2 hand-written
           apply requests). Watcher at 5 Hz: current=yes staged=yes backup=no -> staged disappears.
    applier launched from a bundle that is NOT the staged one (same real bundles, same request)
        -> exit 0, staged bundle becomes the installed bundle (1.0.37 / b92a4e34), backup deleted.

So the failure is not the manifest, not the download, not hashes and not the applier logic: the
applier is started from the very bundle it then renames, and after that rename something inside
`ApplyFromRequestAsync`'s single try/catch throws (the managed payload lives in
`Contents/Resources/dotnet/` and is resolved relative to the running bundle), the catch-all swallows
it and returns false. Two things follow for whoever fixes it: (a) the applier must run from a path
that is not swapped underneath it (or re-exec after the swap), and (b) `ApplyFromRequestAsync` must
log/report WHY it rolled back — today it returns a bare `false` and the CLI reports success because
it only checks that the helper process started.

## 4. HEADLESS SS-none FIELD RUN, RUNTIME 2026.09.14.3 — PASS

App 1.0.37 (embedded runtime b92a4e34…, stamp 2026.09.14.3.ec224e60), `--headless` against the real
panel, four forwards on 127.0.0.1:30522 (158), :32469 (159), :10001 (170), :32502 (171), 16 SS-none
workers (`lkb-ssnone-gen.py`, shadowsocks address header + method=none, cp.cloudflare.com:80
/generate_204, 2 s timeout, 0.25 s gap) for 900 s, 26978 requests.

Runtime-log verdicts (tmp/ultra-e2e/ultra-log-report.py, 14646 reconstructed streams):

    forward 158: streams=884   OK=0     SILENT=876  idle=8   silent_rate=100.0%  generations=4
                 quarantine INSTANT(<=250ms)=441 SILENT-HANG(~1s)=71  max=1027 ms
                 setup failures=12470, 01:26:29 -> 01:41:25, longest unbroken window 896 s
                 longest run of minutes with zero successful streams = 16
    forward 159: streams=2632  OK=2632  SILENT=0    idle=0   silent_rate=0.0%   generations=2
    forward 170: streams=8473  OK=8425  SILENT=0    idle=48  silent_rate=0.0%   generations=2
    forward 171: streams=2657  OK=2657  SILENT=0    idle=0   silent_rate=0.0%   generations=2

Independent axis — client-side generator rc classes over the same 900 s:
port 30522/158 13307 requests rc0=0 rc2(zero downstream)=13307, longest failure streak 899 s;
port 32469/159 2625 requests rc3(non-HTTP bytes)=2624 rc2=1; port 10001/170 8396 rc0=8396;
port 32502/171 2650 rc3=2650. The 158 verdict is confirmed by both axes; the rc3 class on 159/171 is
the generator's "bytes but not an HTTP status line" and corresponds to the runtime's OK streams.

TEARDOWN (single SIGTERM to the headless PID, no reaping, then measured):

    headless_alive_after_SIGTERM = no
    stopping/stopped macOS processes for forward 158, 159, 170, 171 (count=1 each, same second)
    survivor_count      = 0
    orphan_ppid1_count  = 0
    held_port_count     = 0
    FINAL_ultra_client_count=0  FINAL_headless_count=0  FINAL_listening_count=0

## 5. LISTENERS ACROSS A POLICY LAPSE — PASS

A lapse was produced without touching the panel: a local passthrough proxy
(`.tmp/field-verify-20260914/fv-proxy.py`, Mac 192.168.0.13:8099) forwards every request to
https://v3.leikwanhost.com and, while its mode file says `strip:170`, deletes only
`ultra_access` for forward 170 from config responses. The client was started with
`--panel http://192.168.0.13:8099`; a 1 Hz monitor on port 10001 recorded lsof LISTEN state and one
SS-none request per sample (432 samples):

    lapse on  2026-09-14T17:45:16Z
    lapse off 2026-09-14T17:48:38Z (the client only saw it when the panel's revision changed:
              config pull returned 304 until 17:48:38, then 200 with "stripped=1")
    client log 2026-09-15 01:48:38 [WRN] Ultra policy missing for running forward 170;
              reusing last valid policy until "2026-09-14T17:49:19.0000000+00:00" and forcing full config refresh
    2026-09-15 01:48:40 [WRN] Ultra lease unavailable for forward 170; preserving current runtime and retrying config sync
    2026-09-15 01:49:19 [WRN] [fwd-170/ultra-runtime] tcp stream setup failed … no healthy authorized candidate  (grace expired)
    2026-09-14T17:49:21Z  lease obtained again after the proxy went back to passthrough

    monitor: samples=432 listen_true=432 listen_false=0  rc_histogram={0:424, 2:8}
             samples_after_lapse=341 listen_false_after=0 first_unbound_after_lapse=none
             listener remained bound for the whole lapse and for the 41 s reuse window that was left
             (2026-09-14T17:48:38 -> 17:49:19); the 8 zero-byte samples fall in the seconds between
             grace expiry and lease recovery.

MECHANISM, stated so the constant is not mistaken for the behaviour: there is no fixed grace
constant and no `policyGapGrace`. `ConfigPollingService.RestoreLastKnownUltraPolicies` re-uses the
last valid policy only while it still validates, i.e. until **that grant's own `expires_at`**, which
the panel computes as min(now + MAX_POLICY_TTL_SECONDS = 300, line-material expiry, service expiry).
The 41 s measured here is simply how much of the last grant was left when the lapse was observed; a
lapse right after a lease would leave up to ~300 s. The durable result is that the listener never
left LISTEN (432/432 samples) across the lapse and the reuse window.
Teardown after this phase was again survivor_count=0 / orphan_ppid1_count=0 / held_port_count=0.
NOTE: the headless client persists `--panel` into config.json — it had been set to the proxy URL and
was restored to https://v3.leikwanhost.com and re-verified (`doctor`: published 1.0.37 (current)).

## 6. ZIP-APPLY DEFECT — FIX COMMITTED, SOURCE ONLY (commits 6e5e0ab3, c0d01367)

The field defect in section 3 is fixed in source, with regression tests, and deliberately NOT built
or published (shipping a client build is a release decision, and the shipped bytes must keep
matching the repo — this host still runs 1.0.37 / runtime b92a4e34…).

Three changes in `LeikwanBridge.Desktop/src/LeikwanBridge.Mac`:

  1. `MacAppUpdateService.ApplyAsync` no longer launches the helper from the staged bundle it then
     renames. It makes a stable copy under the updates root (APFS clone through `cp -cR`, which
     costs no extra blocks; a real copy anywhere else), records it as
     `applier_bundle_path` in the request, and `TryLaunchPendingUpdate` applies the same rule to
     deferred updates. Stale helper copies are pruned after an hour, never the one a
     `pending-update.json` still references.
  2. `MacAppUpdateApplier.ApplyFromRequestOutcomeAsync` returns `MacUpdateApplyOutcome` (code +
     detail) instead of a bare `false`, appends every step and the rollback reason to
     `updates/update-apply.log`, and publishes `updates/last-apply-result.json`; Program.Main also
     prints the reason on stderr. A silent rollback can no longer look like a non-event.
  3. `MacClientUpdateService.ApplyAsync` waits for the helper outcome and verifies the installed
     bundle's `CFBundleShortVersionString` really is the published version before returning true.
     The CLI path starts the helper with `current_process_id = 0` (`waitForCallerExit: false`) so the
     helper does not wait for the very process that is waiting for it.

Regression tests, and what they do pre-fix:

    ApplyAsync_LaunchesHelperFromAStableCopyOutsideTheStagedBundle
        pre-fix FAILS: "Assert.StartsWith() Failure: String start does not match"
        prefix actual   = <tmp>/…/.LeiKwan Bridge.update-<guid>.app/Contents/MacOS/LeikwanBridge.Mac
        expected prefix = <tmp>/…/updates/
        (captured by running the test in a worktree at the pre-fix commit)
    ApplyHelper_PublishesTheRollbackReasonInsteadOfSwallowingIt
    ApplyHelper_PublishesSuccessAfterTheSwap
    ClientUpdateService_FailsWhenTheInstalledVersionDoesNotChange

`LeikwanBridge.Mac.Tests` 226/226; full desktop solution 860 tests, 0 failures.

## 7. FORWARD 184 — NOT MEASURABLE FROM THIS HOST (blocked, with evidence)

The intermittent fault under investigation is forward 184, and it was NOT re-measured. From the live
panel DB (read-only queries only):

    fwd 184 | service 26 | enabled 1 | backend ultra | listen 10809 | target 45.143.235.221:10809
            | combo 54 (ultra-sha-tyo) | transport tcp+udp | line_client_id 20 | native 0
    service 26 -> user 29 ; clients.owner_user_id = 29 is client 20
    this Mac is client 3 (service 11): fwd 158 combo 51, fwd 159/170/171 combo 54, line_client_id 3
    the only agent tokens on this host are client-agent-3 (active) and client-agent-22
    (client 22 -> service 31, combo 53/55 forwards, none of them 184)

A client's config only carries its own service's forwards, and the runtime needs a panel-minted
session ticket bound to (client, forward, line scope), so forward 184 cannot be exercised from this
host without one of:

  (a) the client-20 agent token (or that device) — then the existing harness runs unchanged with
      `--client-id 20`; or
  (b) an authorized panel write attaching a forward with line_client_id 20 (i.e. combo 54 scoped to
      that line client) to client 3's service.

IMPORTANT NEGATIVE RESULT, WITH ITS LIMIT: forwards 159/170/171 share forward 184's *combination*
(54, ultra-sha-tyo) and were clean here — 13,762 OK streams, 0 SILENT over 900 s. But they are scoped
to `line_client_id 3` while 184 is scoped to `line_client_id 20`, and combo 54 has 16 lines, so
**this clean result does NOT cover 184's line pool** and must not be reported as evidence about 184.
The line scoping (`forwards.line_client_id`, `line_group_mode = selected`) is why the same combination
can behave differently for two clients.

## 8. NOT VERIFIED / CAVEATS

  * Forward 184 (section 7) — blocked on credentials or an authorized panel write; the run that
    matters for the original intermittent-fault question has still not happened.
  * The applier fix is committed but NOT built: no osx-arm64 bundle was produced and nothing was
    installed, so its field behaviour is argued from the differential (applier succeeds when it does
    not run from the swapped bundle) plus unit tests, not from a field run.
  * The historical mismatch firing in the field is not evidenced by this host's logs (section 1);
    only the failure mode is proven.
  * x64 artefacts are verified by sha256/strict-verify only — this arm64 host cannot execute them.
  * Both defects found in the update path (`update_manifest_version_mismatch` equality gate and the
    applier self-swap) are structural: nothing enforces update/app version equality at publish time.
    The apply log now removes the "silent" part of the second one; a publish-time assertion would
    remove the first.

## 9. ARTEFACTS LEFT ON THE MAC (~/field-verify-20260914/, /tmp/fv-*)

  fvp2-runtime.log (11 MB phase-4 runtime log), fvp2-traffic.txt (generator output),
  fvp2-status.json, fvp2-stdout.txt, ultra-client-1037 (runtime copy, sha b92a4e34…),
  fvpb-runtime.log, fvpb-monitor.txt, fvpb-proxy.log.  Scripts live in .tmp/field-verify-20260914/
  in this repo (.tmp is git-ignored).

## 10. CLIENT-20 FORWARD-184 MEASUREMENT (2026-09-14/15)

The previously blocked run was completed with the operator-provided client-20 agent token
passed by file only. No panel writes or forward changes were made. The token was deleted after
the run and verified absent. The Mac configuration was restored byte-for-byte to its pre-run
SHA-256 (`f12a67f14a5a837aca3ad7acc93cc3b6e4290b2887d0e9bb3bac4dca8361e7af`); no runtime,
headless client, generator, or test listener remained.

Runtime: macOS 1.0.37, `ultra-client` 2026.09.14.3 (`b92a4e3425152307...`), 900 seconds,
SS-none traffic, four generator workers per local listener.

| forward | reconstructed streams | OK | SILENT | idle | silent rate | longest setup-failure window |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 184 (port 10809, client 20) | 766 | 0 | 755 | 11 | 100.0% | 894 s |
| 185 (port 11809, client 20) | 843 | 5 | 832 | 6 | 99.4% | 897 s |

The client-side generator independently corroborated the result: forward 184 received 11,656
requests and all were `rc2_zero_downstream`; forward 185 received 12,849 and all were
`rc2_zero_downstream`. The runtime offered four candidates per lease, used 16 distinct
selection references per forward, and recorded `no_downstream_bytes` for every quarantine.
Teardown was clean: `survivor_count=0`, `orphan_ppid1_count=0`, and `held_port_count=0`.

### Same-session control (client 3)

The same binary and generator were then run for 900 seconds against client 3's four forwards;
this is a control for a host-wide outage, not a substitute for client 20's line scope:

| forward | reconstructed streams | OK | SILENT | idle |
| --- | ---: | ---: | ---: | ---: |
| 158 | 832 | 0 | 828 | 4 |
| 159 | 7,948 | 7,948 | 0 | 0 |
| 170 | 8,301 | 8,283 | 6 | 12 |
| 171 | 9,911 | 9,911 | 0 | 0 |

The client-side control agreed: port 10001 (forward 158) was 100% `rc2_zero_downstream`,
port 30522 (forward 159) was 100% successful, and ports 32469/32502 had no zero-downstream
responses. Control teardown was also clean with zero survivors, orphaned children, and held
ports.

### Interpretation boundary

This is the first real-client evidence that the silent-stream behaviour is reproducible in the
client data plane and is not a source-host-only observation. It is also not the original 6.2%
intermittency: both enabled client-20 forwards were effectively wiped out for this run, while
client-3 forwards 159/171 were healthy and 170 was nearly healthy. The result therefore narrows
the fault to the affected forward/line scope (or its target/material), but does not identify
the mechanism behind the historical 6.2% rate. Forward 158 is a known dead-forward control and
must not be combined with the client-20 result.

The remaining discriminating test is still an SS-framed request using the target service's real
method/password. Those target credentials are not persisted on the host and were not placed in
the repository or logs. The first post-deploy panel-issued review and the windowed
`relay_ips_json` union remain separate acceptance/code items; neither is inferred from this
field run.
