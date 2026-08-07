# LeiKwan Bond Jitter and ACK Failover 0.1.40 Handoff

Date: 2026-08-07 (Asia/Taipei)

Status: completed for source audit, code repair, repository commits, panel artifact publication, rollback preparation, and public artifact verification.

This handoff intentionally contains no passwords, private keys, tokens, or raw credential values. Use the project-local `leikwan-v3.txt`, `cantons.txt`, and `shanghais.txt` files for authorized access, and keep those values local only.

## 0. Executive conclusion

The `leigod-forward` jitter report exposed real defects in the deployed `leikwan-bond 0.1.39` core and in the then-current `master` baseline:

1. Retransmitted data could arrive on an alternate leg while cumulative ACKs remained tied to an active but stalled old preferred leg.
2. A fully duplicate inbound data frame returned early and did not refresh the committed cumulative ACK, so alternate-leg retransmission could fail to heal a lost or delayed ACK path.
3. Accepted application sockets and accepted aggregator bond-leg sockets did not enable `TCP_NODELAY`.
4. ACK scheduling originally checked only the bounded channel depth. Once a writer dequeued a frame, the channel appeared empty even if the socket write remained blocked for up to the 30-second writer timeout. This could still place an ACK behind the stalled in-flight DATA frame.

All four defects are fixed. The Linux release is `leikwan-bond 0.1.40`, published together with the matching `leikwan-pathd 0.1.40` artifact.

The input handoff's statement that normal 64-byte `latency_best` payload striping caused the observed reordering was too strong. The probe used one sequential ping-pong stream, while `latency_best` keeps that small flow sticky. The stronger source-supported explanation is stale ACK return affinity, duplicate frames that did not re-ACK, writer head-of-line blocking, and missing `TCP_NODELAY`. Fault retransmission and control frames can cross legs, but ordinary 64-byte payload striping was not proved by that test.

## 1. Input and scope

Input handoff:

```text
C:\Users\frankniubi\Downloads\leigod-forward\docs\handoffs\2026-08-07-leigod-bond-jitter-findings-handoff.md
```

Source repository:

```text
C:\Users\frankniubi\Downloads\leikwan-br1
```

The input artifact was identified exactly as:

```text
version:       leikwan-bond 0.1.39
SHA256:        6f6cd4269c3fba2e1e9f4389302e56e8bd0c7c8e9532e4e77b3ab997f68aa794
source commit: 8d4f52a
lineage:       codex/sidecar-shared-wg-peers
```

The audit deliberately used that 0.1.39 lineage rather than replacing it with the older `master` 0.1.30 code. The same core defects were then ported separately to `master` so that the authoritative future-development baseline is also repaired.

## 2. What was proved

### 2.1 Alternate retransmission existed, but ACK recovery was incomplete

The retransmit selector already attempted to use a leg other than the last failed or stalled leg. However, inbound stream state retained one `preferred_leg_id` for both outbound payload affinity and ACK return traffic.

The old preferred leg was replaced only after becoming inactive. An active socket writer blocked on a real write remained eligible as the ACK path. A fully duplicate frame returned without another `LocalWriteCommitted` event, so no new cumulative ACK was generated.

The failure sequence was therefore:

```text
DATA sent on leg 0
  -> receiver commits DATA locally
  -> cumulative ACK on leg 0 is lost or delayed
  -> sender retransmits DATA on leg 1
  -> receiver recognizes a full duplicate
  -> receiver sends no fresh ACK
  -> later ACK traffic still prefers active-but-stalled leg 0
```

### 2.2 Missing `TCP_NODELAY` was real but limited to accepted sockets

The outgoing bond leg and aggregator-to-target upstream socket already enabled `TCP_NODELAY`. The missing calls were on:

- the client listener's accepted application socket;
- the aggregator listener's accepted TCP bond leg.

This distinction matters. The audit did not claim that every socket lacked `TCP_NODELAY`.

### 2.3 The old read-credit regression was not present

The earlier 256 KiB shared read-credit ceiling had already been repaired in the 0.1.39 lineage. `read_credit_chunks()` used the bounded `max_window_bytes` calculation. It was not the cause of this jitter report and was not modified here.

## 3. Implemented repair

Primary source file:

```text
wstunnel-main/leikwan-bond/src/session.rs
```

### 3.1 Separate ACK return affinity

`TcpStreamState` now has `inbound_ack_leg_id` in addition to outbound `preferred_leg_id`.

- New inbound DATA records its arrival leg as the ACK return candidate.
- Local write commits and final close ACKs use `inbound_ack_leg_id` first.
- Closing a leg clears ACK affinity if it referenced that leg.
- Outbound payload strategy affinity remains independent.

### 3.2 Duplicate cumulative re-ACK

`handle_inbound_data()` now reports both the queued offset and newly received byte count. If a fully duplicate frame contributes zero new bytes, the receiver immediately sends the current committed cumulative ACK, preferring the duplicate's arrival leg.

The duplicate does not generate false delivery credit or write the payload twice.

### 3.3 Queue and in-flight aware ACK scheduling

Bounded TCP leg frames now carry a shared RAII outstanding counter:

- the counter increments before queue admission;
- a failed queue attempt drops the tracked frame and decrements it;
- a queued frame remains counted after `rx.recv()` while `write_frame()` is running;
- completion, cancellation, timeout, queue drain, and error all decrement through frame destruction.

ACK and feedback frames can bypass a preferred leg whose writer has queued or currently-writing data. Control selection chooses the active writable leg with the smallest outstanding frame count, then applies role, health, priority, RTT, and stable tie-breaking.

`StreamOpen` and `Fin` retain the prior preferred-leg behavior so timeout attribution is not silently moved to a different leg. `StreamOpenAck` and cumulative ACK feedback may bypass the queue.

### 3.4 Accepted socket `TCP_NODELAY`

Both accepted TCP paths now call `set_nodelay(true)`. A failure is logged and the affected accepted socket is not admitted into the low-latency transport.

## 4. Regression coverage

New or expanded coverage includes:

- `duplicate_inbound_data_has_no_second_delivery_credit`
- `duplicate_inbound_data_reacks_on_the_arrival_leg`
- `accepted_tcp_stream_enables_nodelay`
- `stream_ack_uses_idle_helper_when_preferred_leg_has_data_queued`
- `leg_writer_pending_frames_includes_socket_write_in_progress`
- `stream_open_stays_on_preferred_leg_when_data_is_queued`

The in-flight writer test uses `tokio::io::duplex` backpressure. It proves that a frame already removed from the channel but still blocked in the socket write keeps the leg non-idle.

## 5. Repository state and commits

### 5.1 Deployed 0.1.39 lineage

Authoritative release worktree:

```text
C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\sidecar-shared-wg-peers
```

Branch and commits:

```text
branch:   codex/sidecar-shared-wg-peers
359724d  fix(bond): fail over stalled ACK paths
6cc304f  fix(bond): track in-flight leg writes
11ac1cc  build(bond): publish 0.1.40 ACK failover runtime
```

`11ac1cc` is the final 0.1.40 release commit. The worktree was clean after publication.

### 5.2 Authoritative `master` baseline

The common source repair was ported without merging the sidecar branch's unrelated feature history:

```text
4b3685b  fix(bond): fail over stalled ACK paths
a563223  fix(bond): track in-flight leg writes
master:  a563223e380f8006956ff1bad86074b0f2cbd57f
```

The `master` port is source-only. It intentionally retains the existing 0.1.30 version and artifacts until a separate master-line release is requested.

`a563223` is the source-repair anchor. The documentation commit containing this handoff may advance `master`; validate that `a563223` remains an ancestor rather than requiring `master` to equal it.

The dirty main checkout remained on `frontend-skin-v2`. Its unrelated tracked and untracked user changes were not modified or cleaned.

## 6. Final 0.1.40 artifacts

```text
artifact: artifacts/wstunnel/leikwan-bond_linux_amd64
version:  leikwan-bond 0.1.40
SHA256:   3abe2fd6954f670a6363c9196487b7837097d063997c31cf4422a6cc14804fca
size:     9880968 bytes

artifact: artifacts/wstunnel/leikwan-pathd_linux_amd64
version:  leikwan-pathd 0.1.40
SHA256:   7ebcc941cbac8911233b4c1edc930781218dbf52a10695c6812da6d83ace530c
size:     9398024 bytes
```

Both files were built as `x86_64-unknown-linux-musl` static PIE ELF executables. `ldd` reported `statically linked`, and neither binary contained a `GLIBC_` version dependency.

## 7. Verification evidence

### 7.1 Release-line gate

The final source and final artifacts passed the full serial release gate:

```text
10 / 10 stages passed in 352.5 seconds

git diff check:                          passed
focused PHP hybrid tests:                passed
all tracked PHP lint:                    passed
focused Python suites:                   passed
broad client and slave Python suites:    passed
Rust leikwan-bond tests:                 passed
Rust leikwan-ingress tests:              passed
real-process Linux hybrid integration:   passed
artifact hash and source mirrors:        passed
Windows and Android contract regressions: passed
```

The bond package result included:

```text
library tests:              152 passed
CLI tests:                    9 passed
flat-hybrid integration:      1 passed
failures:                     0
```

### 7.2 Master-line gate

The independently ported `master` source passed:

```text
leikwan-bond tests: 86 passed, 0 failed
git diff --check:  passed
```

### 7.3 Formatting baseline

`cargo fmt --all --check` still detects broad pre-existing formatting drift across the long-running sidecar workspace, including modules unrelated to this repair. A whole-workspace mechanical reformat was intentionally not performed. The focused diffs pass `git diff --check`.

## 8. Panel artifact deployment

Production artifact root:

```text
/www/wwwroot/v3.leikwanhost.com/leikwan-v3
```

Public base URL:

```text
https://v3.leikwanhost.com
```

The deployment was artifact-only. It changed:

```text
artifacts/wstunnel/leikwan-bond_linux_amd64
artifacts/wstunnel/leikwan-pathd_linux_amd64
the two corresponding entries in artifacts/versions.json
```

It did not deploy unrelated application, tenant documentation, migration, Android, Windows, client-agent, slave-agent, or sidecar-agent changes. No database migration, application restart, nginx restart, or bond runtime restart was required on the panel artifact host.

### 8.1 Production preflight

The panel initially had a mixed artifact state:

```text
bond:  leikwan-bond 0.1.30
SHA:   513b0ad9e496c4e71a225fd381453acb45a1413b6766bf5dcfb91d7babfefb7b

pathd: leikwan-pathd 0.1.39
SHA:   2a6ecc831967a101483acdece13a5ba3d307a531bef5f917363dcd8dec04c18a
```

The production manifest differed from the committed candidate in nine unrelated entries. The deployment therefore merged only the bond/pathd entries into the live manifest instead of overwriting the full file.

The canonical non-target manifest hash remained unchanged before and after deployment:

```text
cca7d308a0b1594ee676cdafcaf352de57adde0335e9ba7c6db9e42111f9c2c1
```

### 8.2 Backup and rollback material

```text
/root/codex-backups/leikwan-v3-bond/0.1.40-20260806T184251Z
```

Backup verification:

```text
directory mode: 700
old bond SHA256:    513b0ad9e496c4e71a225fd381453acb45a1413b6766bf5dcfb91d7babfefb7b
old pathd SHA256:   2a6ecc831967a101483acdece13a5ba3d307a531bef5f917363dcd8dec04c18a
old manifest SHA256:f3ed71b3fa6c3940a2548eb76f529a0907fddbb20bb721ffd91fce866b0eb0de
```

The deployment script used old-hash concurrency guards, validated candidate SHA/size/ELF/version, preserved owner and mode, installed through temporary files plus atomic rename, and automatically restored all three backups on any failure after mutation began.

### 8.3 Origin and public proof

The final server files retained:

```text
owner: www:www
mode:  664
```

Origin and public checks passed:

```text
/api/health:              HTTP 200
/login:                  HTTP 200
/artifacts/versions.json: HTTP 200
```

Both fixed URLs and cache-busting URLs returned the new exact bytes. Public headers included:

```text
bond Content-Length: 9880968
bond ETag:           3abe2fd6954f670a6363c9196487b7837097d063997c31cf4422a6cc14804fca

pathd Content-Length: 9398024
pathd ETag:           7ebcc941cbac8911233b4c1edc930781218dbf52a10695c6812da6d83ace530c

Cache-Control: public, max-age=31536000, immutable
cf-cache-status: DYNAMIC
```

No Cloudflare purge was needed because the fixed public URLs already returned the new bytes.

## 9. Downstream `leigod-forward` state

This operation did not log into, modify, restart, or benchmark the `leigod-forward` source or target runtime hosts. It did not replace their `/usr/local/bin/leikwan-bond` binaries and did not re-enable the repeated-leg bond route.

According to the input handoff, the user-facing `20013/TCP` route had been rolled back to fixed line `693`, while the bond units were inactive. Treat that as input state, not as a fresh live verification from this operation. Re-check it before any future mutation.

The panel publication makes 0.1.40 available for a controlled downstream update. It does not claim that the source and target runtime processes have already loaded 0.1.40.

## 10. What was proved false or remains unproved

### 10.1 Normal 64-byte payload striping caused the measured jitter

Not proved. The probe used one persistent connection and sequential `send -> receive -> sleep` operations. Its total data remained far below bulk promotion, and `latency_best` stays sticky.

### 10.2 The Python light wrapper was the primary reorder engine

False. The Python wrapper handled token/SOCKS bridge functions. The Rust ELF owned bond framing, ACK/retransmission, leg scheduling, and ordered stream delivery.

### 10.3 The old 256 KiB read-credit limit caused this result

False for the deployed 0.1.39 source. That earlier defect was already fixed.

### 10.4 Release 0.1.40 is proved to meet the production low-latency acceptance gate

Not yet proved. Source regressions, static artifacts, panel distribution, and public equality are verified. A same-path downstream A/B benchmark with 0.1.40 has not been run.

## 11. Remaining design risks

The following broader behavior was observed but intentionally not redesigned in this focused repair:

- retransmit RTO remains a fixed 300 ms default;
- server-created reverse streams use server-side strategy behavior that is not fully symmetric with client `latency_best` configuration;
- a full preferred writer queue can still force original control fallback behavior; the repair specifically prevents ACK feedback from waiting behind queued or currently-writing DATA;
- repeated copies of the same physical line do not create independent path diversity and may amplify shared-path stalls.

These are not blockers for publishing the correctness fix, but they matter when interpreting a future multi-leg latency benchmark.

## 12. Required acceptance work before re-enabling repeated legs

There is no remaining source, panel deployment, or cleanup work for this 0.1.40 publication. The separate downstream acceptance task is:

1. Fresh-verify the current `leigod-forward` route, units, listeners, and installed binary hashes.
2. Back up the source and target runtime binaries before replacing either side.
3. Download 0.1.40 from the fixed public URL and verify SHA256 before installation.
4. Use a temporary non-production listener and echo target. Do not change `20013` first.
5. Establish these comparable baselines on the same target and test window:
   - direct fixed single line `693`;
   - one-leg bond using `693`;
   - repeated bond `693,693` and `693,693,693`;
   - distinct-line bond only if independent lines are intentionally under test.
6. Record at least p50, p95, p99, maximum, timeout/effective-loss rate, and aggregate throughput.
7. Inject one controlled leg delay or interruption and prove ACK/retransmit recovery on the alternate leg.
8. Check source and target service restarts, bond logs, socket state, and loaded process hashes.
9. Re-enable `20013` only after an explicit latency/throughput acceptance threshold is agreed and the repeated test meets it.
10. Remove temporary listeners, rules, services, probe endpoints, and uploaded candidates after testing.

Do not infer production readiness from `cargo test`, one successful echo, or artifact publication alone.

## 13. Resume checklist

Repository checks:

```powershell
git -C C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\sidecar-shared-wg-peers status --short --branch
git -C C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\sidecar-shared-wg-peers log -4 --oneline --decorate
git -C C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\bond-jitter-audit-20260807 log -3 --oneline --decorate
git -C C:\Users\frankniubi\Downloads\leikwan-br1 rev-parse master
```

Expected release anchors:

```text
sidecar release branch: 11ac1cc
master source fix anchor: a563223 (must be an ancestor of current master)
```

Public artifact checks:

```bash
curl -fsSL https://v3.leikwanhost.com/artifacts/wstunnel/leikwan-bond_linux_amd64 | sha256sum
curl -fsSL https://v3.leikwanhost.com/artifacts/wstunnel/leikwan-pathd_linux_amd64 | sha256sum
curl -fsSL https://v3.leikwanhost.com/artifacts/versions.json
```

Expected SHA256 values are recorded in section 6. If a fixed URL, manifest, and runtime host disagree, stop before restarting a downstream service.

## 14. Bottom line

The source defects identified from the `leigod-forward` jitter report are repaired in both the actual 0.1.39 deployment lineage and the future `master` baseline. Release 0.1.40 is committed, static, fully gated, backed up, published, and publicly byte-verified. The production `leigod-forward` route was deliberately not changed during this operation; its repeated-leg configuration must remain disabled until a controlled downstream 0.1.40 latency and failover acceptance run succeeds.
