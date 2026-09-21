# 2026-09-21 — WARNING: the Linux test host no longer runs the candidate build

Found while doing final state checks after deploying the agent fix. **The deployed runtime is the
old released binary, not the candidate this session's fixes were built from.**

## Evidence

```
/usr/local/bin/ultra-client               a391e5b9388b86d5...  2026.09.19.1.f18d01fb
/root/lk-accept-backup/ultra-client.orig  a391e5b9388b86d5...  2026.09.19.1.f18d01fb   <- identical
/root/lk-accept-backup/ultra-client.cand  fbf6d9777568048b...  2026.09.21.1.localcandidate
```

`/var/lib/leikwan-client-agent/agent_update_state.json`:

```json
{
  "request_token": "auto-release:install_forward_runtime=de6c4277...|cfwg_watchdog=9665bb2d...",
  "status": "success",
  "last_attempt_at": 1789966944.88,
  "installed_version": "2026.09.19.1",
  "last_completed_at": 1789966945.27
}
```

## What happened

The candidate was deployed to `/usr/local/bin/ultra-client` earlier in the session (sha
`fbf6d977...`, version `2026.09.21.1.localcandidate`). Restarting `leikwan-client-agent` — which I
did several times while deploying and diagnosing the agent fix — let the agent perform its normal
**auto-release self-update**, which replaced the candidate with the released
`2026.09.19.1` build. The agent did exactly what it is designed to do; a locally-built candidate
is simply not a release, so the channel overwrote it.

## Consequence for this session's results — read carefully

Any acceptance number I reported for the Linux host must be treated as **"build not verified at
measurement time"**, because I did not re-check the binary hash at each measurement:

- The headless-path results and the `transport=tcp+udp` UDP 0.0% numbers were **reported while
  the candidate was believed to be deployed**, which was true at deploy time but is not provable
  afterwards from the artifacts I kept.
- Therefore the honest status is: **the macOS number is attributable** (the Mac helper is
  code-signature-attested as `2026.09.21.1.9b484db9`, sha `a6c3b7b6...`, verified in-place), but
  **the Linux numbers are not provably from the candidate.**

This does not invalidate the two code defects found and fixed this session (both were diagnosed
from code and logs, and the `wait_listener` one was reproduced and then resolved on macOS with an
attested build), but it does mean **the Linux acceptance run must be repeated on a verified
binary** before any 10-minute result is claimed.

## Required fix for the harness

Pin the runtime so the auto-update cannot replace it, and assert identity at measurement time.
Concretely, every acceptance run must:

1. record `sha256` and `--version` **immediately before** the run,
2. re-check both **immediately after** the run,
3. refuse to treat a result as valid if either differs, and
4. disable or hold the auto-release channel for the duration (the
   `zz-acceptance-hold.conf` mechanism sets `LEIKWAN_UPDATE_HOLD_UNTIL`, but the observed
   update still completed — so the hold needs re-verification, or the candidate must be
   installed in a way the updater does not own).

## Current host state (verified)

- forwards 201/202 `state=ready`, both TCP and UDP listeners bound on 60501 and 60502
- `leikwan-client-agent` active, running the agent fix (`f6d7d945...`)
- `ultra-client` = `2026.09.19.1.f18d01fb` (released build, **not** the candidate)
- candidate still available at `/root/lk-accept-backup/ultra-client.cand`
- `lk-accept-rollback.timer` armed for 16:02 CST and not yet fired
