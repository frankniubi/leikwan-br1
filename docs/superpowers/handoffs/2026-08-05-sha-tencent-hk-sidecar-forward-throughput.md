# SHA-Tencent to Hong Kong Sidecar Forward Throughput Handoff

Date: 2026-08-05 (Asia/Taipei)

Last updated: 2026-08-05 09:55 (Asia/Taipei)

Status: completed. The throughput and sustained-backpressure fixes are implemented, tested, deployed, benchmarked, and cleaned up as version 0.1.30.

This handoff intentionally contains no passwords, private keys, tokens, or raw credential values. Use the project-local `leikwan-v3.txt`, `cantons.txt`, and `shanghais.txt` files for access. Keep those values local only.

## 0. Completion record

This section is the current source of truth and supersedes the historical recommendation and remaining-work sections below. Sections 1-8 retain the original diagnosis and execution plan because they explain why the release sequence was necessary. Section 9 contains the current resume procedure.

### 0.1 Final release

```text
branch:                codex/sidecar-shared-wg-peers
release source commit: 988b6b62e134cee9ce844300ef5ac49329e6c821
version:               leikwan-bond 0.1.30
artifact:              artifacts/wstunnel/leikwan-bond_linux_amd64
SHA256:                513b0ad9e496c4e71a225fd381453acb45a1413b6766bf5dcfb91d7babfefb7b
size:                  8946736 bytes
format:                static PIE x86-64 ELF; no GLIBC version dependency
```

The release sequence after 0.1.23 was:

```text
04ed06e / 0.1.24: restore the bounded 16 MiB transport read window
c3c42df / 0.1.25: timestamp retransmits after the socket write
8c24ba4 / 0.1.26: bound queued transport writes
9ef4689 / 0.1.27: keep the transport event loop responsive under writer backpressure
07baf36 / 0.1.28: start leg-write timeout at the actual socket write, not queue admission
6f57f22 / 0.1.29: reuse the sole active strategy leg when every candidate is in cooldown
988b6b6 / 0.1.30: allow at least 30 seconds for a genuinely blocked leg socket write
```

The final four runtime failures found after restoring the read window were independent:

1. A local writer queue could block the transport event loop; `Closed` handling could also terminate more than the affected stream.
2. Queue residence time counted against the leg socket-write timeout, so valid queued work expired before it reached the socket.
3. ACKs were flowing, but all 128 credits could remain in `local_backlog` when `latency_best` cooled its only active leg and returned no eligible leg.
4. With the preceding issues fixed, sustained real socket backpressure could legitimately exceed the old 1.2 second floor; the bounded 16-frame queue was correct, but the actual stall detector was too aggressive.

Version 0.1.30 retains bounded memory and queueing while addressing all four cases. The minimum actual socket-write stall timeout is 30 seconds; it is not an unbounded wait.

### 0.2 Local release gates

```text
Rust tests:                 80 / 80 passed
PHP artifact/manifest:       3 / 3 passed
Python artifact consistency: 5 / 5 passed
git diff --check:            passed
```

Focused regression coverage now includes nonblocking inbound queueing and later flush, commit and FIN ordering, stream-scoped writer closure, timeout origin at actual socket write, cooldown fallback with and without alternatives, and the 30-second minimum write-stall contract.

### 0.3 Deployment and live state

Version 0.1.30 was deployed from committed release source to the panel, Hong Kong sidecar, and Linux client. Rollback material is retained at:

```text
panel:   /root/codex-backups/bond-backpressure-030-20260804T205523Z
sidecar: /root/codex-backups/bond-backpressure-030-20260804T205613Z
client:  /root/codex-backups/bond-backpressure-030-20260804T205622Z
```

Panel migration state was verified as 71 applied and 0 pending. The public artifact, sidecar binary, client binary, and all benchmark client processes matched the final SHA256 above. The client remains locked to controlled updates with `disable_auto_update=True`.

Final post-cleanup runtime state:

```text
panel enabled forwards:        0
panel outstanding tasks:       0
client agent:                  active
client benchmark units:        0
client benchmark listeners:    0
client leikwan-bond processes:  0
client CLOSE_WAIT:              0
sidecar agent/server:           active / active
sidecar server restarts:        0
sidecar shared WG UDP port:     18502
sidecar WG peers:               4
sidecar frontend TCP port:      18501
sidecar port 443 listener:      none
sidecar CLOSE_WAIT:             0
target ports 5201 and 5210:     no listeners
target iperf3 processes:        0
```

### 0.4 Formal forward benchmark

The formal result directory on the Linux client is:

```text
/root/codex-benchmarks/backpressure-030-final-20260804T210000Z
```

Every row contains five normal completions. All 30/30 runs succeeded and no client service restarted.

| Mode | Shape | Receiver median Mbps | Receiver min-max Mbps | Retransmits median/max | Zero intervals total | Max RSS KiB |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| `single` | P4 forward | 165.171 | 155.341-175.459 | 724 / 927 | 1 | 22,740 |
| `single` | reverse 400M | 199.755 | 157.859-204.404 | 4 / 56 | 0 | 7,228 |
| `race` | P4 forward | 412.264 | 353.865-420.957 | 1,174 / 1,349 | 2 | 23,412 |
| `race` | reverse 400M | 400.553 | 400.343-401.360 | 2 / 8 | 0 | 17,944 |
| `bond` | P4 forward | 390.640 | 315.571-418.910 | 1,203 / 1,295 | 2 | 23,412 |
| `bond` | reverse 400M | 400.561 | 400.469-400.814 | 0 / 576 | 0 | 19,984 |

Some per-run teardown samples observed one `CLOSE_WAIT`; the fresh client and sidecar post-cleanup checks both returned zero, so there was no accumulated final residue. The old deterministic 24-28 Mbps two-credit ceiling is removed. Repeated `race` and `bond` measurements now sustain approximately 400 Mbps in the historical reverse shape, while forward P4 performance depends on mode and route conditions.

### 0.5 Direct controls

Control result directories are retained at:

```text
/root/codex-benchmarks/backpressure-030-controls-20260804T211500Z
```

Each row contains three normal completions; all 12/12 controls succeeded.

| Origin | Shape | Receiver median Mbps | Receiver min-max Mbps | Retransmits median/max |
| --- | --- | ---: | ---: | ---: |
| Linux client direct | P4 forward | 54.987 | 54.463-55.529 | 18,209 / 18,487 |
| Linux client direct | reverse 400M | 81.063 | 71.031-154.215 | 5,336 / 7,933 |
| Hong Kong sidecar direct | P4 forward | 902.299 | 901.358-918.461 | 285 / 297 |
| Hong Kong sidecar direct | reverse 400M | 399.925 | 399.919-399.928 | 0 / 0 |

These controls show that the target and sidecar are not imposing the former 28 Mbps ceiling. The Linux client's direct route is materially worse than the supported `client -> slave -> sidecar` route and is not a substitute topology.

### 0.6 Cleanup evidence

Temporary forwards `104-109` used local client ports `11061-11066`, slave ingress ports `7003-7008`, and target ports `5201` and `5210`. They were deleted through `ProvisioningService`, not by direct database deletion.

Cleanup converged as follows:

```text
destroy tasks completed: 18 / 18
endpoints deleted:       18 / 18
enabled test forwards:    0
outstanding test tasks:   0
```

The four slave agents were active with fresh heartbeats and zero reported load. Direct socket checks passed on three slaves. The fourth slave's SSH daemon rejected management checks with `Exceeded MaxStartups`; its four destroy tasks nevertheless completed through the authenticated node agent, its four endpoints were marked deleted, and its heartbeat remained fresh. This is a separate SSH-management saturation observation, not a data-plane or provisioning failure. It should be handled through the host's normal SSH exposure/hardening workflow rather than by weakening host-key checks or restarting SSH during this release.

The client reconciled all six units and listeners away. The official and transient target `iperf3` services are inactive, the official service remains disabled, and both target listeners are absent. Seven exact panel upload archives were removed; rollback backup directories were preserved. Local untracked deployment wrappers and tar archives are removed during final repository cleanup.

### 0.7 Repository and production synchronization

The production runtime sync is complete. The panel artifact store, public download, Hong Kong sidecar, and Linux client all use the committed 0.1.30 artifact from release source commit `988b6b6`, with the SHA256 and size recorded in section 0.1. Later commits in this branch are handoff-only and do not change the deployed runtime payload.

Repository finalization rules and state:

```text
authoritative branch: codex/sidecar-shared-wg-peers
master relationship:  fast-forward aligned with the authoritative branch
release commit:       988b6b62e134cee9ce844300ef5ac49329e6c821
worktree state:       clean after the handoff commit
local temp helpers:   0
local deploy tar files: 0
```

The dirty main checkout remains on `frontend-skin-v2`; its unrelated tracked and untracked user changes were not modified or cleaned. The `master` ref was updated only because it was not checked out by any worktree and the update was a strict fast-forward. Production-server synchronization here refers to the verified panel/sidecar/client deployment; no Git remote push is claimed by this handoff.

There is no remaining throughput implementation, deployment, benchmark, or cleanup work for this run. The one separate operator follow-up is the slave SSH `Exceeded MaxStartups` condition described in section 0.6; do not conflate that management-plane saturation with the completed data-plane fix.

## 1. Objective and fixed constraints

The requested test is a Linux-client forward speed comparison to:

```text
v4-hkt-b.mcbar.cc:5201
```

The requested production topology is:

```text
test Linux client
  -> one or more sha-tencent slave ingress listeners
  -> one WireGuard peer per slave
  -> one shared WireGuard listener/interface on yt-hk-a
  -> yt-hk-a sidecar target connection
  -> v4-hkt-b.mcbar.cc:5201
```

Required policy:

- Use `current_mode` as the forward-combination `backend_mode`.
- Use `yt-hk-a` as the Hong Kong sidecar.
- Use the `sha-tencent` group, including the node commonly identified as `sha-tencent-2`.
- Compare `single`, `race`, and `bond` on the same Linux test client and target.
- Keep all slave bond/MPTCP ingress ports within `7000-9000`.
- Do not add or move any data-plane listener to port `443`.
- Do not allocate one sidecar WireGuard listen port per slave.
- Do not use direct client-to-sidecar connectivity.
- Access a sidecar only through a slave hop.
- Android is explicitly out of scope for this pass.
- Build the Linux binary so it runs on Debian 12. The current accepted method is a static `x86_64-unknown-linux-musl` artifact with no GLIBC dependency.

`current_mode` is not a client mode. It is the direct-CNIX backend mode. Client behavior is represented as follows:

- `single`: one enabled single combination/path.
- `race`: multiple enabled single combinations on one route with `race_enabled=1`; `client_mode=race` is not a valid persisted value.
- `bond`: a bond combination with multiple legs.

## 2. Authoritative repository state

Use the isolated worktree:

```text
C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\sidecar-shared-wg-peers
```

Repository anchors when the original diagnosis was written (historical):

```text
branch: codex/sidecar-shared-wg-peers
HEAD:   cd5cbbad1e6cbb393c9c6de88c8efe329f1ce25a
master: cd5cbbad1e6cbb393c9c6de88c8efe329f1ce25a
```

The worktree was clean before this handoff file was added. The main checkout is on `frontend-skin-v2` and contains unrelated user changes. Do not use or clean the main checkout for this fix.

Release metadata at the original diagnostic `HEAD` (historical):

```text
leikwan-bond version: 0.1.23
Linux artifact:       artifacts/wstunnel/leikwan-bond_linux_amd64
SHA256:               fe32383543f337d6a408b58f78e35777196af3babc715bb422b53edff30891d5
size:                 8911064 bytes
```

## 3. What was completed

### 3.1 Control-plane and Linux runtime support

The current `master` lineage now supports sidecar-backed `single` and `race` forwards instead of losing the sidecar or applying an unsupported single-leg flag. Relevant commits include:

```text
aab1b53 feat(panel): publish sidecar frontends for single race
63e36d9 feat(client): run sidecar single race via bond frontend
9fa7559 fix(panel): preserve sidecar for single race creation
ebc1717 fix(panel): reuse shared peer without combination
06ed04c fix(client): drop unsupported single leg flag
```

Temporary test forwards were created through the supported `ProvisioningService` flow, not by fabricating database rows. This preserved token generation, desired-state publication, validation, and reconciliation behavior.

### 3.2 Shared WireGuard design restored

The corrected production design is:

- `yt-hk-a` owns one WireGuard UDP listener/interface.
- Every participating slave has its own peer/key/allowed-address state on that shared interface.
- All slave peers use the same sidecar WireGuard endpoint port.
- A slave does not receive a unique sidecar WireGuard listen port.
- The slave-side peer endpoint uses the Hong Kong sidecar CNIX address represented by the configured `zhuji.host` endpoint.

The last verified `yt-hk-a` runtime state was:

```text
sidecar agent TCP listener: 18501
shared WireGuard UDP port:  18502
WireGuard peer count:       4
port 443 listener:          none
idle file descriptors:      10
CLOSE_WAIT sockets:         0
```

This is expected production behavior. The absence of a data-plane service on `443` is not a defect.

### 3.3 Bounded-read correctness fixes shipped through 0.1.23

Three sequential releases are important:

```text
b920c61 / 0.1.21: bound transport read-ahead with a shared semaphore
4eebd7b / 0.1.22: reduce that semaphore to latency_guard_bytes
cd5cbba / 0.1.23: acquire credit after read so idle streams cannot monopolize it
```

Version `0.1.21` addressed the earlier unbounded local/event backlog that had reached multi-gigabyte memory usage. Version `0.1.23` fixed the P4/idle-stream starvation and deadlock behavior while retaining bounded memory.

### 3.4 Version 0.1.23 deployed and verified

The static Linux artifact above was deployed to:

- the panel artifact store;
- the Hong Kong sidecar;
- the Linux test client.

Backups from that rollout:

```text
panel:   /root/codex-backups/bond-read-credit-023-20260804T152251Z
sidecar: /root/codex-backups/bond-read-credit-023-20260804T152434Z
client:  /root/codex-backups/bond-read-credit-023-20260804T152455Z
```

The client automatic-update lock was restored to `disable_auto_update=True` after the controlled deployment.

Validation completed for 0.1.23:

```text
Rust tests:             58 passed
PHP artifact tests:      3 passed
Python manifest tests:   5 passed
git diff --check:        passed
```

`cargo fmt` still reports broad pre-existing formatting drift in this crate. It was not introduced by the focused changes; do not run a whole-file formatting rewrite as part of the throughput fix.

### 3.5 Test resources were cleaned up

Temporary forwards `101`, `102`, and `103` were removed after testing. Their temporary local listeners were:

```text
single: 11051
race:   11052
bond:   11053
```

The associated temporary slave ingress allocation used `7000-7002`, within the required range. At cleanup time:

- the test forward units were absent;
- no `11051-11053` listeners remained;
- no benchmark `iperf3` or bond processes remained;
- no related outstanding panel tasks remained;
- the sidecar returned to the idle FD/socket state recorded above.

Treat these as historical temporary ports. Re-check availability before reusing any of them.

## 4. Last benchmark result

The fixed 0.1.23 P4 benchmark shape was equivalent to:

```bash
iperf3 -4 -c 127.0.0.1 -p <local-listen-port> -P 4 -O 2 -t 30 --json
```

Results:

| Mode | Sender Mbps | Receiver Mbps | Bytes | Retransmits | Max RSS KiB |
| --- | ---: | ---: | ---: | ---: | ---: |
| `single` | 24.816 | 24.606 | 93,323,264 | 235 | 5,492 |
| `race` | 27.962 | 27.667 | 104,726,528 | 236 | 5,520 |
| `bond` | 27.962 | 27.660 | 104,857,600 | 259 | 6,288 |

The important observation is not the small mode-to-mode difference. All three modes hit essentially the same ceiling, which points to a shared upstream transport limit rather than leg selection or bond striping.

## 5. Root cause of the approximately 28 Mbps ceiling

Current code in `wstunnel-main/leikwan-bond/src/session.rs` computes shared read credit as:

```rust
fn read_credit_chunks(config: &SessionConfig) -> usize {
    let chunk_size = config.chunk_size.max(1024);
    let window = config
        .latency_guard_bytes
        .min(config.max_window_bytes)
        .max(chunk_size);
    window.saturating_add(chunk_size - 1) / chunk_size
}
```

Production defaults are:

```text
chunk_size:          131072 bytes (128 KiB)
latency_guard_bytes: 262144 bytes (256 KiB)
max_window_bytes:    16777216 bytes (16 MiB)
```

Therefore the persistent transport session receives only two shared credits:

```text
262144 / 131072 = 2 chunks
```

The semaphore is created once for the whole persistent transport session. A credit is returned only when the corresponding remote cumulative ACK is applied. This creates a global ACK-gated sliding window of only 256 KiB across all local streams and all tested frontend modes.

The bandwidth-delay product calculation matches the live measurements:

```text
throughput = window_bytes * 8 / RTT

262144 * 8 / 0.0758 seconds = 27.67 Mbps
262144 * 8 / 0.0852 seconds = 24.61 Mbps
```

This exactly explains the observed `race`/`bond` plateau and the slightly slower `single` result.

Commit `4eebd7b` introduced this regression in version `0.1.22` by changing the already bounded 16 MiB read-credit window from `max_window_bytes` to `latency_guard_bytes`. Commit `cd5cbba` in 0.1.23 corrected permit timing but did not restore the window capacity, so it fixed starvation without fixing throughput.

This is an agent-introduced software regression. It is not evidence that the line naturally became a 28 Mbps line.

## 6. What has been proved false or rejected

### 6.1 "The sidecar has no normal low-port allocation"

False. Backend data and runtime checks showed normal sidecar port allocation. The remaining throughput ceiling is not explained by missing sidecar ports.

### 6.2 "Each WireGuard slave needs its own sidecar WG listen port"

False. The intended and now verified design is one shared WireGuard interface/listen port with multiple peers.

### 6.3 "The data plane should be moved to 443"

False. No production data-plane component in this path requires `443`. Do not change ports to make the test work.

### 6.4 "The client should connect directly to the Hong Kong sidecar"

Rejected. The required topology remains `client -> slave -> sidecar`. Both forwarding and operational sidecar access must preserve the slave hop.

### 6.5 "Single/race/bond differences explain the common 28 Mbps result"

False for this plateau. All three frontends enter the same persistent transport read-credit gate before mode-specific scheduling can increase throughput.

### 6.6 "Version 0.1.23 already fixed the speed regression"

False. It fixed idle-reader credit starvation and P4 correctness only. The capacity remains two chunks.

### 6.7 "Restoring the 16 MiB window will recreate the earlier 2.7 GiB growth"

False. Version 0.1.21 added a hard shared bound. Restoring `max_window_bytes` keeps the session bounded at 16 MiB of read credit instead of returning to an unbounded event/local backlog.

### 6.8 "The historical 400 Mbps result was imaginary"

False. Repository evidence records multiple real reverse-TCP results:

- `docs/2026-05-06-debug-and-release-report.md` records approximately 400 Mbps receiver results on ports `11030` and `11035` with `iperf3 -R -b 400m`.
- `docs/2026-04-18-ops-handoff-manual.md` records 399 and 405 Mbps receiver results with `-R -b 600M`, plus a 603 Mbps sidecar-to-target control result.

However, those historical tests used different routes, target instances, directions, and/or CF-bond conditions. They prove that this software/path family has exceeded 400 Mbps; they do not guarantee that the current CNIX path to `v4-hkt-b.mcbar.cc:5201` will reach exactly 400 Mbps after the software cap is removed.

### 6.9 "The target or physical line alone explains exactly 28 Mbps"

False as the current primary explanation. A target/path ceiling may still exist above 28 Mbps, but the two-credit BDP formula predicts the observed plateau too precisely and affects every mode equally. Remove this known cap before judging the physical path.

## 7. Historical recommended code fix (completed)

Keep the 0.1.23 behavior that acquires a permit only after `read()` has produced data. Change only the capacity calculation back to the bounded retransmit window:

```rust
fn read_credit_chunks(config: &SessionConfig) -> usize {
    let chunk_size = config.chunk_size.max(1024);
    let window = config.max_window_bytes.max(chunk_size);
    window.saturating_add(chunk_size - 1) / chunk_size
}
```

With production defaults this provides:

```text
16 MiB / 128 KiB = 128 shared chunks
```

At 400 Mbps, the required bandwidth-delay product is only:

```text
75 ms RTT: 3.75 MB, about 29 chunks
85 ms RTT: 4.25 MB, about 33 chunks
100 ms RTT: 5.00 MB, about 39 chunks
```

The 16 MiB limit is therefore large enough for the observed RTT while remaining globally bounded per persistent transport session.

Do not solve this by increasing `latency_guard_bytes`. That setting serves latency promotion/guard behavior and should not silently become the persistent transport flow-control window.

## 8. Historical remaining work (completed)

### Step 1: Add a regression test first

In `wstunnel-main/leikwan-bond/src/session.rs`:

1. Rename `transport_read_credit_uses_latency_guard_instead_of_full_retransmit_window` to describe the intended bounded retransmit window.
2. Change its expected value from `2` to `128`.
3. Run only that test and confirm it fails against current code.
4. Keep these existing tests unchanged and green:
   - `transport_reader_waits_for_read_credit_before_reading_ahead`
   - `idle_transport_reader_does_not_hold_shared_read_credit`
   - `ack_and_stream_close_return_transport_read_credit`

### Step 2: Make the one-function implementation change

Change `read_credit_chunks()` exactly as shown in section 7. Avoid unrelated refactors or formatting churn.

### Step 3: Bump and build the Linux release

The current authoritative manifest is 0.1.23, so the next candidate in this lineage is 0.1.24. Update:

```text
wstunnel-main/leikwan-bond/Cargo.toml
wstunnel-main/Cargo.lock
wstunnel-main/leikwan-bond/src/main.rs
artifacts/versions.json
artifacts/wstunnel/leikwan-bond_linux_amd64
```

Build under WSL for static Linux compatibility:

```bash
cd wstunnel-main
RUSTFLAGS=-Awarnings cargo build --release \
  --target x86_64-unknown-linux-musl \
  -p leikwan-bond
```

`RUSTFLAGS=-Awarnings` is currently needed because the local Rust 1.94.1 toolchain has previously hit an internal compiler error while rendering the crate's existing dead-code warnings. It is not a substitute for test execution.

Before publication, verify:

```bash
file target/x86_64-unknown-linux-musl/release/leikwan-bond
ldd target/x86_64-unknown-linux-musl/release/leikwan-bond || true
readelf --version-info target/x86_64-unknown-linux-musl/release/leikwan-bond
sha256sum target/x86_64-unknown-linux-musl/release/leikwan-bond
stat -c '%s' target/x86_64-unknown-linux-musl/release/leikwan-bond
```

Required result: static or static-PIE x86-64 ELF, no GLIBC version dependency, and manifest SHA/size equal to the copied artifact.

### Step 4: Run local release gates

Run at least:

```bash
cd wstunnel-main
cargo test -p leikwan-bond
```

Also rerun the three PHP artifact/manifest regression tests and the five Python artifact consistency tests used for 0.1.23, followed by:

```bash
git diff --check
```

Do not hide the known whole-crate formatting baseline. Confirm the focused diff itself is clean.

### Step 5: Deploy with rollback material

Use the existing committed-artifact deployment procedure:

1. Commit the focused source, tests, version metadata, and built artifact.
2. Publish the committed artifact to the panel with application/database/file backups and migration checks.
3. Verify the public artifact hash and size.
4. Back up and replace the sidecar binary, then restart the sidecar bond service.
5. Verify shared WG UDP `18502`, four peers, TCP `18501`, no `443`, healthy FD state, and zero `CLOSE_WAIT` accumulation.
6. Update the Linux test client from the panel artifact, verify both on-disk and live-process hashes, and restore `disable_auto_update=True`.
7. Do not deploy or modify Android artifacts.

Use the local credential files and WSL SSH wrappers. Never put credentials into scripts that will be committed. Sidecar SSH must retain the slave hop.

### Step 6: Recreate test forwards through supported provisioning

Create temporary `single`, `race`, and `bond` forwards through `ProvisioningService`, all with:

```text
backend_mode: current_mode
sidecar:      yt-hk-a
slave group:  sha-tencent
target:       v4-hkt-b.mcbar.cc:5201
client:       Linux test client
```

Use fresh available slave ingress ports in `7000-9000`. Do not directly insert partial DB records.

### Step 7: Benchmark both current and historical shapes

For each mode, first rerun the current P4 shape:

```bash
iperf3 -4 -c 127.0.0.1 -p <listen> -P 4 -O 2 -t 30 --json
```

Then run the historical comparison shape:

```bash
iperf3 -4 -c 127.0.0.1 -p <listen> -R -b 400M -t 10 --json
```

Run at least five normal completions per mode before making a stability claim. Capture:

- sender and receiver Mbps;
- transferred bytes and retransmits;
- per-interval zero-throughput stalls;
- process maximum RSS;
- transport RTT/ACK progress if available;
- service restarts, reconnects, cooldowns, and hello failures;
- sidecar FD and `CLOSE_WAIT` counts before and after.

Also run direct client-to-target and sidecar-to-target controls in matching directions. These controls distinguish any remaining physical/target ceiling after the 28 Mbps software cap is removed.

Immediate regression gate: all modes must stop pinning at the two-credit BDP ceiling. Do not claim "400 Mbps restored" unless repeated same-path measurements actually support it.

### Step 8: Clean up exactly the temporary test state

After collecting results:

- delete only the temporary forwards created for this run;
- wait for destroy/reconcile tasks to finish;
- verify the local listeners and per-forward units are gone;
- verify no `iperf3` or temporary bond process remains;
- verify sidecar shared WG state is unchanged and healthy;
- remove temporary upload archives/wrappers without touching unrelated files.

## 9. Current resume checklist

Before doing follow-up work, run:

```powershell
git -C C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\sidecar-shared-wg-peers status --short --branch
git -C C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\sidecar-shared-wg-peers rev-parse HEAD master
git -C C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\sidecar-shared-wg-peers merge-base --is-ancestor 988b6b62e134cee9ce844300ef5ac49329e6c821 HEAD
```

Expected state is a clean `codex/sidecar-shared-wg-peers` worktree, `HEAD` equal to `master`, and the release commit `988b6b6` present in the current history. The exact documentation commit may advance when this handoff is maintained; use ref equality and ancestry rather than a stale handoff-commit hash.

If production behavior is revisited later, fresh-verify the panel public artifact, migration state, sidecar shared-WG state, client binary hash, and agent heartbeats before changing anything. Do not recreate forwards `104-109`; they are deleted historical benchmark resources. Use new supported `ProvisioningService` resources and fresh ports within `7000-9000` for any new benchmark.

## 10. Bottom line

The deterministic 24-28 Mbps transport-window regression and the subsequent backpressure/cooldown failures are fixed in 0.1.30. The committed static artifact is deployed on the panel, Hong Kong sidecar, and Linux client. Formal tests completed 30/30 forward runs and 12/12 direct controls without service restarts; repeated `race` and `bond` reverse runs sustained approximately 400 Mbps. Temporary forwards, listeners, processes, target services, and upload archives were cleaned up while the shared sidecar WireGuard topology remained healthy. The authoritative topic branch and local `master` are fast-forward aligned; the separate slave SSH `MaxStartups` observation remains documented as an operator follow-up.
