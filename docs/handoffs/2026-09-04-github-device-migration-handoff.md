# GitHub Device Migration Handoff

Date: 2026-09-04 (Asia/Taipei)
Status: SUCCESS (SANITIZED SNAPSHOT PUSHED)

## Purpose

Push the complete recoverable LeiKwan source to GitHub through
`http://127.0.0.1:2080` before changing devices, while excluding credentials,
build scratch, duplicate artifacts, emulator captures, and other generated
noise.

## Completed

- Active checkout: `C:\Users\frankniubi\Downloads\leikwan-br1`, branch
  `master`.
- Local committed tip observed before pause: `fd3d3e565966d6a13af3310bea8357c6f6989abc`.
- Remote `origin/master` observed through the required proxy:
  `8eb662dcdb2e31effb0ca82ea903e87cf39dc446`.
- `git merge-base --is-ancestor origin/master master` returned success; the
  local history was 50 commits ahead at that point.
- Proxy TCP check for `127.0.0.1:2080` passed.
- Proxied `git ls-remote origin refs/heads/master` passed.
- Existing Android phase 2 commit `e4f03370` and coordinated release commits
  `6b373af5`, `fd3d3e56` are in the local history.
- Pending source tests already run before the pause:
  - source-agent Python: 23 tests, PASS.
  - Shadowsocks installer static test: PASS.
  - `tools/ultra-client` Go tests using the local OpenGFW module: PASS.
  - Android artifact manifest PHP test: PASS after replacing stale hard-coded
    version checks with Gradle/manifest comparison and pointing it at the
    existing `package-android-native-test.ps1` packager.
  - artifact route controller PHP test: PASS.

## Important Push Blocker

A direct push of the 50 unpublished commits must not be attempted. The range
contains two obsolete intermediate Windows installer blobs of approximately
135 MB in commits around `8c057a24` and `c9d8c37c`, over GitHub's 100 MB file
limit. The current final installer is approximately 22 MB; the two historical
oversized blobs are build garbage, not required source.

The safe continuation is to create a new commit whose parent is the latest
remote `origin/master` and whose tree is the curated final local source tree.
This preserves the complete final code without making the oversized historical
blobs reachable from the pushed ref. Do this on a new temporary migration
branch/worktree; do not reset the dirty shared `master` checkout.

## Curated Pending Source

Include after review and secret scanning:

- `install/install-shadowsocks-none.sh`
- `install/tests/test_shadowsocks_none_installer.sh`
- `master/tests/android_client_artifact_manifest_test.php`
- `master/tests/artifact_route_controller_response_guard_test.php`
- `source-agent/ultra_source_agent.py`
- `source-agent/tests/test_ultra_source_agent.py`
- `opengfw/` because committed `tools/ultra-client/go.mod` has the required
  local replacement `github.com/apernet/OpenGFW => ../../opengfw`
- `scripts/build-windows-native-test.ps1` and its referenced current runbook
- current directly referenced native/Android handoff and design documents
- this handoff

Leave the newline-only `LeikwanBridge.Desktop/src/LeikwanBridge.Core/packages.lock.json`
change uncommitted unless separately justified.

## Explicit Exclusions

Never push:

- `leikwan-v3.txt`, `cantons.txt`, `shanghais.txt`, `sidecars.txt`, or other
  credential/environment files.
- `apps-base.apk`, `apps-extract/`, duplicate APK/AAR/EXE/SO/JAR files,
  `tools/artifacts/`, emulator dumps, old logcat directories, QA build staging,
  `.tmp*`, `task_plan.md`, `findings.md`, or `progress.md`.
- `leikwan-android/app/src/androidTest/java/com/leikwan/bridge/security/AttestationChainSmokeTest.kt`
  until it is intentionally made emulator-safe and reviewed; it is currently
  an uncommitted hardware-only diagnostic.
- stale root-level audit handoffs and mojibake historical reports unless the
  user explicitly requests archival publication.

## Final Push Result

- A sanitized migration repository was created at
  `.codex-tmp\\github-snapshot-20260904-run3`; the shared `master` checkout
  was not reset, rebased, or rewritten.
- Remote baseline before push: `8eb662dcdb2e31effb0ca82ea903e87cf39dc446`.
- Snapshot commit pushed through `http://127.0.0.1:2080`:
  `672b62a0b5131b068726fcf5d4e5460ed12f9e1f`.
- Final handoff-document commit pushed to `master`:
  `97d94d14e9938c53e112a7da5f7d764ae30d6c3c`.
- The push was a fast-forward from the remote baseline to `master`; no force
  push was used. Proxied `git ls-remote origin refs/heads/master` returned the
  same `672b62a0b5131b068726fcf5d4e5460ed12f9e1f` hash.
- Reachable-object verification after the snapshot commit:
  `reachable_blobs_over_100MB=0` and `reachable_blobs_over_95MB=0`.
- Final tracked artifact sizes in the pushed tree: Windows installer
  `22,436,617` bytes; Android APK `88,858,971` bytes. GitHub emitted only its
  50 MB recommendation warning for the APK; it is below the 100 MB rejection
  limit.
- Filename exclusion scan: `forbidden_names=PASS`; scratch exclusion scan:
  `scratch_exclusion=PASS`; credential-pattern and long-assignment scans:
  `PASS`. The only private-key marker match was an existing CI denylist test
  fixture, not a key or credential.
- The two obsolete approximately 135 MB historical Windows installer blobs
  were intentionally left unreachable. Credentials, environment files,
  emulator/build scratch, duplicate binaries, and hardware-only diagnostics
  were also excluded.
- No production Panel deployment was performed.

## Remaining Steps

1. Re-read current `HEAD`, `origin/master`, and worktree status because another
   session advanced `master` during this work.
2. Stage only the curated source list and run a filename/content secret scan
   without printing secret values.
3. Commit the curated source locally.
4. Create a sanitized snapshot/migration commit parented directly to the latest
   proxied `origin/master`; verify no reachable blob exceeds 100 MB.
5. Push only that sanitized commit to `refs/heads/master` with
   `git -c http.proxy=http://127.0.0.1:2080 -c https.proxy=http://127.0.0.1:2080 push`.
6. Verify the exact remote hash with proxied `git ls-remote`.
7. Update this handoff with SUCCESS or the exact failure, commit it, and push
   the handoff update. If push fails, retain the local commit and error here.

## Current Outcome

The sanitized source snapshot and this handoff are published. A replacement
device can recover the exact migration evidence from GitHub. No long-running
build, test, or push process is intentionally left running.
