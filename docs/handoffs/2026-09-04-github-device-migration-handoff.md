# GitHub Device Migration Handoff

Date: 2026-09-04 (Asia/Taipei)
Status: PAUSED BY USER BEFORE PUSH

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

No GitHub push was attempted after the user said to stop because a sanitized
snapshot was still required and a large push could delay shutdown. No long
build/test/push process from this task was left running.
