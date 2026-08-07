# SHA-Tencent to Hong Kong Sidecar Forward Throughput Handoff

Date: 2026-08-05 (Asia/Taipei)

Last updated: 2026-08-06 18:32 (Asia/Taipei)

Status: release 0.1.39 Linux hybrid correctness code, artifacts, schema, panel deployment, local soak, isolated live controls, and cleanup are complete. Production hybrid activation remains intentionally off: the candidate egress was on the wrong side of the LeiGod path and delivered 0 Mbps directly to the fixed target, so the required 400 Mbps Reverse-P1 gate was not validly exercised or passed.

This handoff intentionally contains no passwords, private keys, tokens, or raw credential values. Use the project-local `leikwan-v3.txt`, `cantons.txt`, and `shanghais.txt` files for access. Keep those values local only.

## 0. Completion record

Section 14 is now the current source of truth for release 0.1.39 and the Linux hybrid follow-up. Section 13 records the preceding 0.1.34 LeiGod line-native/adaptive-parallel release, section 12 records the preceding 0.1.31 fixed-endpoint release, while this section records the preceding 0.1.30 backpressure milestone. Sections 1-8 retain the original diagnosis and execution plan because they explain why the release sequence was necessary. Section 9 contains the historical resume procedure.

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

## 11. LeiGod `leigod_forward`: fixed-endpoint single / race / bond throughput

This section supersedes the earlier analysis-only draft. A live benchmark was run on 2026-08-05 from approximately `03:42Z` to `04:14Z` (`11:42` to `12:14` Asia/Taipei). All three modes used the same customer-facing slave endpoint and the same target endpoint:

```text
client:                 sh36.ctyun.mcbar.cc
fixed slave endpoint:   101.133.142.65:7003 (node 15, private 172.24.61.32)
target endpoint:        v4-hkt-b.mcbar.cc:5201
LeiGod lines:           447, 451, 453, 455, 456
relay version:          2026.07.27.1
bond artifact:          0.1.30
bond artifact SHA256:   513b0ad9e496c4e71a225fd381453acb45a1413b6766bf5dcfb91d7babfefb7b
```

The panel had zero enabled forwards before the run. The benchmark therefore used isolated transient units instead of creating partial database records or changing service permissions. Production agents, LeiGod line services, and persisted forwards were not changed.

### 11.1 Fixed endpoint topology used by each mode

| Mode | Public client transport | Slave behavior | Overseas behavior |
| --- | --- | --- | --- |
| `single` | Raw TCP to `101.133.142.65:7003` | Fixed front door forwarded to one `leigod_forward` relay on loopback. The primary candidate was line `451`. | Relay connected through LeiGod SOCKS to `v4-hkt-b.mcbar.cc:5201`. |
| `race` | Raw TCP to the same `101.133.142.65:7003` | The same front door forwarded to one `leigod_forward` relay containing all five candidates. The relay selected one line by TCP connect latency; it did not stripe traffic. | Same target `:5201`. |
| `bond` | Five persistent `leikwan-bond` TCP legs, every one connected to the same `101.133.142.65:7003` tuple | The fixed front door round-robin assigned those connections to five line-specific loopback relays (`48010-48014`), one for each LeiGod line. | A transient 0.1.30 bond server listened on the same target `:5201` and forwarded reassembled streams to inner iperf on `127.0.0.1:5210`. |

This proves that the externally visible slave `ip:port` can remain unchanged for a fixed-front-door implementation. It does **not** mean the current persisted control plane already exposes this topology: the existing product bond contract still normally publishes multiple `service_endpoints`. Productizing the measured topology requires a supported fixed-endpoint demultiplexer and in-place mode reconciliation.

### 11.2 Local and harness gates

The focused local relay suite passed before live work:

```text
python -m unittest slave-agent/tests/test_leigod_forward_relay.py -v
Ran 8 tests: OK
```

Two harness issues were found and excluded from the authoritative result set:

1. `leigod_forward_relay.py` probes every candidate by opening a SOCKS CONNECT to the configured target. Repeating that every five seconds against a single-session iperf server caused empty probe connections to occupy `:5201`. For `single` and `race`, a transient cookie-aware front on the same target `:5201` discarded empty/short probes and forwarded valid iperf cookies to `127.0.0.1:5210`. The relay still performed its initial real five-line probe; the follow-up interval was raised to 3600 seconds for the bounded benchmark.
2. The first custom fixed front door waited indefinitely for both copy directions and accumulated large `FIN-WAIT-2` send queues after a client closed. Its close path was corrected to cancel the peer copy when either direction completed, then all transient units were restarted. A later exact client socket cleanup found no active test socket, and a fresh five-second fixed-endpoint smoke still received only `1.203 Mbps`; the low `single`/`race` state was therefore not attributed to a leftover gateway queue.

The directories `20260805T033348Z` and `20260805T034638Z` contain those rejected pre-runs and are not used below. They are retained only as root-cause evidence.

### 11.3 Formal commands and result integrity

Each mode completed five normal runs of both shapes, for `30/30` authoritative JSON results:

```bash
# Current P4 shape
iperf3 -4 -c <mode-listen> -p <mode-port> -P 4 -O 2 -t 30 --json

# Historical reverse comparison shape
iperf3 -4 -c <mode-listen> -p <mode-port> -R -b 400M -t 10 --json
```

For `single` and `race`, `<mode-listen>:<mode-port>` was the fixed public endpoint `101.133.142.65:7003`. For `bond`, iperf connected to the local bond listener `127.0.0.1:11061`; all five underlying bond legs still used only `101.133.142.65:7003`.

```text
authoritative result directory:
  /root/codex-benchmarks/leigod-forward-fixed-endpoint-20260805T035115Z

summary file:
  /root/codex-benchmarks/leigod-forward-fixed-endpoint-20260805T035115Z/summary.json

summary SHA256:
  ffe33fb0b38c5a54a4d1ed8b4b60dfcebf1fe059f12332b8c57b8cf67b5d390e
```

Receiver throughput is the primary comparison metric. Sender throughput is retained because large sender/receiver divergence reveals queued or lost data rather than completed delivery.

### 11.4 Results

| Mode | Shape | Normal runs | Receiver median Mbps | Receiver min-max Mbps | Sender median Mbps | Total retransmits | Zero-throughput intervals |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `single` | P4 | 5/5 | 0.904 | 0.757-0.931 | 1.021 | 6,989 | 79 |
| `single` | reverse | 5/5 | 1.048 | 0.925-1.083 | 16.739 | 26 | 2 |
| `race` | P4 | 5/5 | 0.940 | 0.932-0.995 | 0.994 | 10,590 | 85 |
| `race` | reverse | 5/5 | 1.071 | 1.029-1.092 | 20.864 | 23 | 1 |
| `bond` | P4 | 5/5 | 172.858 | 81.225-202.433 | 171.267 | 2,370 | 0 |
| `bond` | reverse | 5/5 | 118.803 | 0.629-211.811 | 135.345 | 487 | 6 |

The individual bond P4 receiver results were `202.433`, `81.225`, `165.784`, `187.252`, and `172.858 Mbps`; none contained a zero-throughput interval. The bond reverse receiver results were `0.629`, `118.803`, `211.811`, `140.716`, and `118.803 Mbps`. The first reverse run was a real low outlier with six zero-throughput intervals; it was not discarded.

### 11.5 Runtime evidence

`race` selected line `451` because its startup connect latency was `123.77 ms`, narrowly below line `447` at `136.42 ms`; lines `453`, `456`, and `455` measured approximately `398`, `432`, and `638 ms`. The selected line remained `451`, so the result demonstrates a key limitation: current race policy optimizes connect latency, not sustained throughput. It therefore followed the same degraded line as `single` and delivered nearly identical throughput.

The correct-token bond generation had:

```text
client unit restarts:              0
server unit restarts:              0
client transport establishments:  1
client transport reconnects:      0
initial bond-leg failures:         0
cooldown events:                   0
server transport starts:           1 (started with 3 legs)
server add-leg events:             2 (five total established legs)
server invalid-token events:       0
```

The first bond reverse outlier therefore was not caused by a bond transport rebuild, client/server restart, initial leg failure, or cooldown. It is retained as observed path variance. This run does not support a claim of stable 400 Mbps reverse throughput.

Against the same fixed public endpoint, the bond median was approximately `191x` the single P4 median and `184x` the race P4 median. Reverse medians were approximately `113x` and `111x`, respectively. The reason is not endpoint identity: all modes used the same tuple. Bond used five independent LeiGod sessions, while single and race both stayed on the currently degraded line `451`.

### 11.6 Cleanup proof

Independent final verification at `04:14Z-04:16Z` showed:

```text
sh36:
  leikwan-client-agent active
  codex-leigod-speed units 0
  listener 11061 count 0
  sockets to 101.133.142.65:7003 count 0

node 15:
  leikwan-slave-agent active
  codex-leigod-speed units 0
  benchmark listeners 7003/48003-48014 count 0
  original LeiGod SOCKS listeners 20447/20451/20453/20455/20456 count 5

target:
  iperf3.service inactive and disabled
  codex-leigod-speed units 0
  listeners 5201/5210 count 0
  temporary bond binary absent

panel:
  enabled forwards 0
  live endpoints for enabled forwards 0
  pending/running provision tasks 0
```

### 11.7 Bottom line

The fixed public endpoint can be preserved across `single`, `race`, and a server-demultiplexed `bond` implementation. The live throughput result is unambiguous for this test window: `single` and `race` were both pinned by latency selection to degraded line `451` and delivered about `1 Mbps`, while five-line bond on the same public tuple delivered a `172.858 Mbps` P4 median and `118.803 Mbps` reverse median. Bond was materially faster but still variable; the reverse outlier prevents a blanket stability or 400 Mbps claim.

No production runtime or database synchronization is required from this benchmark alone. The only durable repository change is this handoff. A future product change should add a supported fixed-endpoint demultiplexer, pin endpoint identity during mode changes, and add throughput-aware race health if race is expected to avoid low-bandwidth lines.

## 12. Release 0.1.31 fixed-endpoint optimization, live benchmark, and deployment

This section supersedes the no-sync conclusion in section 11.7. The fixed-endpoint product work was implemented after that benchmark, tested locally, exercised with isolated server canaries, then synchronized to the panel, selected slave, Linux client, and Hong Kong sidecar.

### 12.1 Committed release

```text
branch:                         codex/sidecar-shared-wg-peers
release source commit:          f9f21ac07bb8c14f5978d91aa5b5acd84ccc68c4
leikwan-bond version:           0.1.31
bond artifact SHA256:           ff568c8bfe0d393476f878df39bf21b2910648b912e7b13f917d483050280e0c
bond artifact size:             8610768 bytes
leigod relay version:           2026.08.05.1
leigod relay SHA256:            40ce291792891381d2b61c6a0c4dea478115c8fb7b9309986f753c140bfdda7c
slave agent version:            2026.08.05.1
slave agent SHA256:             f4ae3ca682ff481336c7e8ecc4070e440278314e6f75a38a309b13135518a5d1
install-forward-runtime version: 2026.08.05.1
install-forward-runtime SHA256: c6bce8f1c2653187c0099799cb724b4c7cce0a89fb801d56976e360c86d5e9e9
```

The fixed public tuple remains `101.133.142.65:7003`. The durable implementation adds:

1. An LBND hello route identifier, so every logical bond leg can use the same physical endpoint while the slave deterministically maps it to one LeiGod candidate.
2. Per-route bond counters and connection/error state in the relay state file.
3. Initial bond-leg readiness (`preferred`, `minimum`, bounded wait) and bounded background reconnects.
4. Linux fixed-endpoint expansion from one physical endpoint to logical `leg_route_id` URIs.
5. Race sustained-throughput EWMA, bidirectional samples, stall penalties, exploration, and target/candidate-scoped state restore. The default remains `connect_latency`; `throughput_ewma` is opt-in.

Relevant commits are:

```text
010abe6 feat(bond): expose fixed leg bootstrap and route counters
2a9d63c feat(bond): wait for fixed leg readiness and retry boundedly
f5ee738 feat(client): expand fixed endpoint into logical bond legs
1580608 feat(panel): publish fixed-endpoint leigod bond contracts
a7e9894 feat(race): learn sustained throughput for leigod routes
c2b3b62 release: publish fixed-endpoint leigod throughput runtime
123e7ad test: cover fixed bond process integration
f9f21ac test(bond): expect release version 0.1.31
```

### 12.2 Local release gates

```text
Rust:                         91 / 91 passed
Python release suite:         47 / 47 passed
broader Linux client suite:   59 / 59 passed
fixed bond process tests:      2 / 2 passed
PHP focused release tests:    11 / 11 passed
git diff --check:             passed
```

The process integration test runs the real Python relay and the real static Linux 0.1.31 binary. It proves normal and reversed arrival order, exact line `451`/`453` demultiplexing, rejection of route `0` and unknown route `999`, and route counters. Two unrelated legacy MPTCP regression tests remain outside this release and were not treated as release gates.

### 12.3 Live method

The authoritative test ran from approximately `07:42Z` to `08:35Z` on 2026-08-05. All modes used:

```text
client:                sh36.ctyun.mcbar.cc
fixed slave endpoint:  101.133.142.65:7003
target:                v4-hkt-b.mcbar.cc:5201
LeiGod lines:          447, 451, 453, 455, 456
relay:                 2026.08.05.1 from committed source
bond client/server:    0.1.31, exact committed static artifact
```

The panel had zero enabled forwards and zero pending/running tasks. The test used isolated `codex-leigod-canary-*` transient units and `/root/codex-leigod-canary-*` uploads. It did not modify production forward records. A cookie-aware target front discarded short relay health probes and passed valid iperf sessions to `127.0.0.1:5210`.

Each row below contains five normal runs of both shapes:

```bash
iperf3 -4 -c <listen-host> -p <listen-port> -P 4 -O 2 -t 30 --json
iperf3 -4 -c <listen-host> -p <listen-port> -R -b 400M -t 10 --json
```

The result directory and immutable summary are:

```text
/root/codex-benchmarks/leigod-fixed-031-throughput-20260805T074142Z
summary SHA256: 264107a20ad060a3a1d5b52ff0e75cf2bf3a34c4c5122c54e16d4f594335a7ac
authoritative JSON runs: 80 / 80 normal
```

### 12.4 Individual-line controls

| Line | Shape | Receiver median Mbps | Receiver min-max Mbps | Zero intervals |
| ---: | --- | ---: | ---: | ---: |
| 447 | P4 | 201.567 | 201.386-201.795 | 0 |
| 447 | reverse | 165.459 | 127.222-210.755 | 0 |
| 451 | P4 | 158.515 | 102.835-201.851 | 7 |
| 451 | reverse | 194.476 | 118.351-205.024 | 0 |
| 453 | P4 | 184.732 | 62.505-203.043 | 1 |
| 453 | reverse | 201.455 | 178.385-209.441 | 0 |
| 455 | P4 | 42.548 | 35.277-52.176 | 34 |
| 455 | reverse | 39.467 | 0.072-44.492 | 1 |
| 456 | P4 | 159.015 | 107.479-204.567 | 1 |
| 456 | reverse | 61.234 | 60.795-62.501 | 0 |

Line `455` was consistently degraded in both directions. Line `456` had usable forward throughput but a hard reverse ceiling near `61 Mbps`. The best stable P4 control was line `447`; the strongest reverse control was line `453`.

### 12.5 Race and bond results

Before formal race runs, five concurrent bidirectional warm connections were opened through the same public endpoint. Relay state proved all five candidates had `qualifying_samples=1` and no `sampling_in_flight` residue. After formal runs, every candidate had multiple fresh samples; the selected line converged to `447`, and line `455` carried the largest stall penalty.

| Mode | Routes | Shape | Receiver median Mbps | Receiver min-max Mbps | Zero intervals |
| --- | --- | --- | ---: | ---: | ---: |
| `race` throughput EWMA | 5 candidates | P4 | 126.373 | 88.692-178.954 | 5 |
| `race` throughput EWMA | 5 candidates | reverse | 185.995 | 45.760-195.063 | 0 |
| `bond` | 447/451/453/455/456 | P4 | 172.252 | 64.992-186.784 | 6 |
| `bond` | 447/451/453/455/456 | reverse | 128.554 | 0.105-205.414 | 15 |
| `bond3` admission control | 447/451/453 | P4 | 196.436 | 121.199-201.098 | 0 |
| `bond3` admission control | 447/451/453 | reverse | 185.334 | 76.545-213.908 | 0 |

Race no longer remains pinned to the old approximately `1 Mbps` degraded result. Its reverse median is strong, but P4 multi-connection sampling still exposes traffic to weak candidates, so it did not meet the planned `80%` of best-control P4 threshold.

Five-leg bond met the `>=150 Mbps` P4-median threshold, and the fixed route header worked: every route reported `accepted=1`, `active=1`, and `connect_successes=1`. It did not meet the no-stall or all-reverse-runs-above-100 thresholds. The target formed one transport from three initial legs and added the remaining two without a restart.

The measured operational optimization is admission control: keeping the same public endpoint and using healthy routes `447/451/453` raised the P4 median from `172.252` to `196.436 Mbps`, raised reverse median from `128.554` to `185.334 Mbps`, and reduced zero intervals from `21` to `0` across ten formal runs. One reverse result was still `76.545 Mbps`, so this is not a blanket per-run floor guarantee. Do not hard-code these line IDs globally; refresh controls and admit currently healthy routes.

### 12.6 Cleanup proof

Independent checks at `08:35Z` showed:

```text
sh36 client agent:                 active, 0 restarts
sh36 canary units/listener 11061:  0 / absent
node15 slave agent:                active, 0 restarts
node15 canary units/7003/48003:    0 / absent / absent
node15 LeiGod line services:       5 active
target iperf3.service:             inactive and disabled
target canary units/5201/5210:     0 / absent / absent
panel enabled forwards/endpoints:  0 / 0
panel pending/running tasks:       0
```

No benchmark binary or relay upload remained on the three benchmark hosts. The authoritative result directory remains on sh36.

### 12.7 Production synchronization

The committed `f9f21ac` archive contained `1216` tracked files, was `123675501` bytes, and had SHA256 `bfc34c282086753c291406176629fef08161b7e60dc957922a48ea8f78fbec4c`. The panel compared every staged tracked file with the live artifact tree after overlay.

Panel deployment evidence:

```text
backup:                    /root/codex-backups/leigod-fixed-031-20260805T084503Z
application backup SHA256: 9a200bcf2cf9f149fea09f233c42a488c696ef695be0bcb2a9fab648cbe3a461
database backup SHA256:    a58f45bb4a8c3ddfa8137ab7aad77f9a81405c1beb327fecb56291b3065bd254
migrations:                71 applied, 0 pending before and after
runtime .env:              hash unchanged
tracked equality:          1216 / 1216
origin login:              HTTP 200
public login:              HTTP 200
origin versions manifest:  HTTP 200
```

The first deployment attempt stopped before database backup, overlay, or migration because that PHP CLI did not accept `php -`; the validated application backup was reused with the compatible stdin invocation. The successful resume performed the database backup, overlay, migration status checks, equality proof, and HTTP checks.

Runtime backups and final state:

```text
node15: /root/codex-backups/leigod-fixed-031-slave-20260805T085843Z
  leikwan-bond 0.1.31, relay 2026.08.05.1, slave agent 2026.08.05.1
  slave agent active, 0 restarts; five original LeiGod services active

sh36:  /root/codex-backups/leigod-fixed-031-client-20260805T085843Z
  leikwan-bond 0.1.31; client agent active; disable_auto_update=true

HK sidecar: /root/codex-backups/leigod-fixed-031-sidecar-20260805T085854Z
  leikwan-bond 0.1.31; sidecar and agent active, 0 restarts
  shared WG UDP 18502, 4 peers, TCP frontend 18501, no port 443 listener, no CLOSE_WAIT
```

The current sidecar was reached only through an authorized slave hop. The canonical older hop timed out to sidecar port 22 during this window; `47.116.134.3 -> 103.153.5.172` was reachable and was used for the verified update. No direct client-to-sidecar path was introduced.

### 12.8 Final conclusion

The externally visible endpoint can remain unchanged for `single`, `race`, and bond. Version 0.1.31 supplies the missing deterministic route identity and lifecycle controls. Throughput-aware race fixes the catastrophic latency-only lock-in, but parallel workloads still need conservative exploration/admission policy. Five-leg bond is throughput-capable but unstable when current weak routes are forced in. The best measured configuration in this window was the same fixed endpoint with routes `447/451/453`, at `196.436 Mbps` P4 median and `185.334 Mbps` reverse median with no zero intervals.

## 13. Release 0.1.34 LeiGod line-native controls and adaptive internal parallelism

This section supersedes section 12 for the LeiGod follow-up. The critical topology correction is that `hk-bgp-b` and its sidecar domain are independent of LeiGod mode. No sidecar was used, modified, or deployed for this work.

### 13.1 Scope and authoritative topology

The supported benchmark path was:

```text
sh36
  -> 101.133.142.65:7003              fixed slave ingress
  -> slave-local bond and LeiGod relay
  -> line-specific LeiGod SOCKS
  -> v4-hkt-b.mcbar.cc:5201            unchanged forwarded target
```

`v4-hkt-b.mcbar.cc:5201` is the relay target, not the sh36 client address during a forward benchmark. One accidental direct-target invocation reached the bond listener without an LBND header and correctly failed with `invalid bond frame magic`; it is marked `INVALID-DIRECT-TARGET.txt` and excluded from every throughput conclusion.

The required product shape is:

- the user opens one normal TCP stream;
- the user's test is Reverse `-P 1`, not P4;
- the slave creates internal parallel LeiGod connections;
- aggregation occurs on the slave/server side;
- no client-side bond process is required;
- Point 10 rollout gates remain off.

### 13.2 Fresh individual-line P1/P2 controls

Each physical LeiGod line was tested independently through the same fixed slave ingress and forwarded target. Every row is a 10-second Reverse test. Receiver throughput is the authoritative comparison.

| Line | P1 receiver Mbps | P1 sender Mbps | P1 retransmits | P2 receiver Mbps | P2 sender Mbps | P2 retransmits | Zero intervals |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 447 | 15.715 | 33.931 | 13 | 204.013 | 246.940 | 35 | 0 |
| 451 | 185.770 | 199.407 | 111 | 191.315 | 237.212 | 45 | 0 |
| 453 | 90.732 | 100.609 | 4 | 209.631 | 250.205 | 7 | 0 |
| 455 | 41.619 | 59.887 | 23 | 76.574 | 114.589 | 25 | 0 |
| 456 | 60.517 | 78.673 | 4 | 116.179 | 151.937 | 8 | 0 |

The immutable line-control summary is:

```text
/root/codex-benchmarks/leigod-line-current-20260805T140500Z/summary.json
SHA256: 23ee16d1415235006f71fd0d2bc3e2a0ae5bf58ca0bb20f0a479efa249ab2dd4
```

The result answers the line-native question directly: lines `447` and `453` each exceeded 200 Mbps with P2, while the same line could be much slower with P1. LeiGod appears to have connection-level variability or allocation behavior that an internal second connection can sometimes escape.

This is not deterministic. A paired line `453` retest produced P1 `90.851 Mbps`, followed immediately by P2 `50.137 Mbps` with one zero interval. P2 improves the probability of obtaining a good allocation; it does not create guaranteed physical capacity.

### 13.3 Durable adaptive-parallel implementation

The committed release is:

```text
branch:               codex/sidecar-shared-wg-peers
release source:       a2b60a2300a4be0c89e38b2fd047b86b5559610b
leikwan-bond:          0.1.34
bond bytes:           9044440
bond SHA256:          c196984ea90011e0fa03053b4499a7f4ef4ec6f3cdb7560449b0f20c42bbc655
LeiGod relay:         2026.08.05.2
relay bytes:          59161
relay SHA256:         64b64b6ac1464c220e82e2582c6a2d3845d29ee969d07a961de81260916ef700
slave agent:          2026.08.05.3
slave agent bytes:    355778
slave agent SHA256:   543f4f0818d1db10f06988e3ad20278a966432c6d41f02fba2b0d5f4a80dd430
```

Relevant commits are:

```text
c789556 fix(bond): attribute throughput by route delivery
da90336 fix(bond): calibrate per-session line capacity
a2b60a2 feat(leigod): add adaptive parallel bond lanes
```

The implementation expands five physical candidates into at most eight logical legs. Physical lines are attempted first; a second connection is then added for the first three current candidates. Defaults are configurable and bounded:

```text
BOND_LEIGOD_PARALLEL_LANES_PER_LINE=2   # clamped to 1..4
BOND_LEIGOD_PARALLEL_LEG_LIMIT=8        # clamped to 2..8
```

The base route IDs are `447/451/453/455/456`. Additional lanes use unique synthetic route IDs, for example `1004472`, `1004512`, and `1004532`, while still resolving to their physical line. The relay shares physical-line health/configuration but exposes every logical route ID. Duplicate physical lines remain rejected for `single` and `race`; they are allowed only in `bond` when route IDs are unique.

Capacity sampling begins at first real delivery rather than connection creation. Calibration temporarily saturates one logical lane, records delivered throughput, and freezes that capacity estimate instead of overwriting it with the scheduler's later weighted share. A degraded lane at or above 10 Mbps continues exclusive calibration until admitted or quarantined. A lane below 10 Mbps remains available only for interactive/control traffic and probes; it is excluded from bulk scheduling. Interactive chunks retain the latency-oriented selector, while bulk chunks use measured capacity and admission state.

### 13.4 Automatic exclusion observed live

After the clean product canary, the target snapshot contained eight active logical legs and one transport. Final TCP admission state was:

| Logical route | Measured Mbps | Bulk admission |
| ---: | ---: | --- |
| 447 | 81.959 | admitted |
| 1004472 | 45.983 | admitted |
| 1004512 | 41.572 | admitted |
| 453 | 16.968 | quarantined |
| 451 | 5.563 | quarantined |
| 1004532 | 4.412 | probation |
| 455 | 14.440 | quarantined |
| 456 | 3.684 | quarantined |

The relative floor can quarantine a route above the absolute 10 Mbps floor when it is still materially worse than the current best capacity. Bulk bytes converged onto the three admitted connections. This proves that exclusion is based on observed connection delivery rather than a hard-coded physical line list; a good and bad connection belonging to the same physical line can receive different admission decisions.

### 13.5 User-P1 Reverse results

An initial manually split internal-P2 prototype used the same single user stream and produced five 20-second Reverse P1 receiver results:

```text
201.169, 194.877, 210.291, 164.337, 208.141 Mbps
median: 201.169 Mbps
min-max: 164.337-210.291 Mbps
zero intervals: 0
```

Evidence:

```text
/root/codex-benchmarks/leigod-internal-p2-20260805T142500Z/formal/summary.json
SHA256: 79189e2778edb5c80ccdbb7de789518033310e47bf66e6c5d8775629075873ba
```

The final 0.1.34 product-shaped canary used one relay, eight logical legs, exactly one target transport, and no sh36 bond process. Its 45-second warmup was `187.640 Mbps`. Three formal 20-second Reverse P1 runs were:

| Run | Receiver Mbps | Sender Mbps | Retransmits | Zero intervals |
| ---: | ---: | ---: | ---: | ---: |
| 1 | 187.520 | 197.549 | 323 | 0 |
| 2 | 199.841 | 209.785 | 320 | 0 |
| 3 | 198.372 | 208.335 | 423 | 0 |

```text
receiver median: 198.372 Mbps
receiver min-max: 187.520-199.841 Mbps
/root/codex-benchmarks/leigod-product-p2-034-valid-20260805T150500Z/formal/summary.json
SHA256: 4cbdd7e1e7140a2c3fe22390d2c47a2f1e5939158f1bdeede0dcd67447163562
```

The correct conclusion is therefore precise:

1. Greater than 200 Mbps aggregation is possible and was demonstrated with one user stream in the prototype window.
2. The latest clean release window was just below 200 Mbps, so current LeiGod variability prevents a strict greater-than-200 guarantee.
3. Internal parallelism improves user experience without asking the user to open P2 or run client-side bond.
4. Additional lanes beyond two per physical line are not justified by this evidence and remain bounded/configurable.

### 13.6 Verification gates

Local release validation completed before deployment:

```text
Rust tests:                         116 / 116 passed
real release integration:            1 / 1 passed
focused and broad Python tests:     68 / 68 passed
focused PHP contract/artifact:       8 / 8 scripts passed
tracked PHP deployment lint:       388 / 388 passed
git diff --check:                  passed
```

Regression coverage includes route-delivery attribution, calibration start/freeze semantics, degraded-lane resolution, duplicate physical lines with unique bond route IDs, synthetic route validation, relay state sharing, and server-side payload expansion.

### 13.7 Production synchronization

The panel was deployed from committed source `a2b60a2` through a validated archive:

```text
archive files:        1220
archive bytes:        123736030
archive SHA256:       529fd7ff5eedfb2687cd4edb9123e41968c7028da95550fb99a68543e780e7cb
changed live files:   13
tracked equality:     1220 / 1220
runtime .env SHA256:  unchanged
migrations:           71 applied, 0 pending before and after
enabled forwards:     0
outstanding tasks:    0
Point 10 gates:       fixed_endpoint_leigod_bond=false
                      server_side_leigod_bond=false
origin/public login:  HTTP 200 / HTTP 200
versions manifest:    HTTP 200
artifact range:       HTTP 206
public artifact SHA:  c196984ea90011e0fa03053b4499a7f4ef4ec6f3cdb7560449b0f20c42bbc655
```

Panel rollback material:

```text
/root/codex-backups/leigod-adaptive-034-20260805T152619Z
application SHA256: 9b3b74b15adbf0164ba462262143d08e76b204044217dbf6d915d054c5675099
database SHA256:    1f4693ca4e34a184455c6b948cf3f4d53de3ea0b928e282ebad1f504240a7ff0
```

The selected slave was updated atomically and only its agent was restarted:

```text
backup:             /root/codex-backups/leigod-adaptive-034-slave-20260805T153913Z
production bond:    0.1.34
production relay:   2026.08.05.2
production agent:   2026.08.05.3
agent:              active, 0 restarts, no post-deploy warnings
LeiGod line units:  5 active
```

The sh36 user client was not changed because aggregation is slave-side. The `hk-bgp-b` sidecar was intentionally untouched because it is not part of LeiGod mode.

### 13.8 Cleanup and final state

The isolated canary was removed while retaining compact JSON evidence:

```text
sh36:
  canary units/processes/listeners: 0
  evidence summaries retained

selected slave:
  canary units/processes: 0
  ports 7003/48003-48005/58003-58005: no listeners
  production agent: active, 0 restarts
  original LeiGod services: 5 active

target v4-hkt-b:
  bond processes/canary units: 0
  port 5210: no listener
  iperf3.service: active and disabled
  port 5201: one official iperf3 listener

panel:
  enabled forwards: 0
  pending/running provision tasks: 0
  Point 10 gates: both false
  slave node 15: active, agent 2026.08.05.3, current load 0
  temporary release upload/staging: removed
```

The operational recommendation is to keep the rollout gates off until a longer observation window shows that the clean product median has adequate margin above 200 Mbps. The release is deployable now for adaptive exclusion and transparent internal parallelism; it should not be marketed as a guaranteed greater-than-200 Mbps service on the current LeiGod lines.

## 14. Release 0.1.39 Linux hybrid runtime, live placement canary, and rollout decision

This section supersedes section 13 for the Linux hybrid follow-up. It records the approved flat
client/slave runtime, the committed panel release, fresh LeiGod-only P1/P2 controls, the failed
egress placement gate, and exact cleanup. It does not supersede the valid 0.1.34 line-capacity
evidence in section 13.

### 14.1 Committed release and local gates

The release candidate is committed on the isolated worktree:

```text
branch:                  codex/sidecar-shared-wg-peers
release source commit:   8d4f52af7c4829d42a39116d3626eab1cb4e859d
client agent version:    2026.08.06.5
client agent SHA256:     f5b0e2965a59905fc5886b31e5f3a2773ce40f15865081ed7be77cd2b754dc48
leikwan-bond version:    0.1.39
bond bytes:              9868592
bond SHA256:             6f6cd4269c3fba2e1e9f4389302e56e8bd0c7c8e9532e4e77b3ab997f68aa794
leikwan-pathd version:   0.1.39
pathd bytes:             9385880
pathd SHA256:            2a6ecc831967a101483acdece13a5ba3d307a531bef5f917363dcd8dec04c18a
versions.json SHA256:    0c8ea6e08052f8f15f8370fc37538ff20cdb26e904f379170f52d95f0dfb30ca
```

The implementation sequence is:

```text
23f43db..1e7d282  fenced execution lease schema and preservation
fba8817            fenced managed-line execution
d1c7952            bounded client configuration watch
9cd4d39            Linux hybrid runtime contract
b9fc803..4e09152   multi-forward path daemon and leased local runtimes
0a0bb7a            flat local/slave bond placement
07a2edc            flat slave relay publication
fdfd5fe..c77b69b  client pathd reconcile and malformed-generation retention
0bde14c            pinned Linux artifacts
e456654            adaptive admission and expiry stabilization
31a9a2f            lease authorization and membership guardrails
48284ae            pathd lease validation and self-heal
8d4f52a            bounded final runtime artifact publication
```

The final serial local gate passed after the last review fixes:

```text
Rust library tests:                  147 passed
Rust CLI tests:                        9 passed
real flat-hybrid integration:          1 passed
focused PHP hybrid scripts:            8 / 8 passed
focused client/artifact/installer:     42 passed
full release gate:                     10 / 10 passed in 285.7 seconds
git diff --check:                      passed
```

The formal 30-minute bounded-resource soak completed 361 samples and 90 traffic sessions. No
process showed strict tail growth. Incremental RSS was `0 KiB` for client-agent, `3052 KiB` for
pathd, and `256 KiB` for both provider totals and the slave relay, all below the `128 MiB` limit.

Two independent `gpt-5.6-sol` xhigh reviews found lease membership leakage, listener-readiness
recovery, node execution-policy gaps, transactional revalidation, missing endpoint `node_id`, and
the legal maximum-line port boundary. Those findings were fixed before the final gates. A final
follow-up review returned `APPROVED` after the gate-count wording was corrected; the passing focused
and full regression gates remain the primary closure evidence.

### 14.2 Panel deployment and inert production state

Committed `8d4f52a` was deployed to the panel from a tracked `git archive`; dirty operational files
were not packaged.

```text
archive bytes:          127760378
archive SHA256:         fe938af5c3007f714cf1d844b61c697d18acb486501809dd121d0a1db17ab944
rollback directory:     /root/codex-backups/leigod-hybrid-8d4f52a
application backup:     retained with SHA256 sidecar file
database backup:        retained with SHA256 sidecar file
migrations before:      71 applied
migrations after:       72 applied, 0 pending
origin/public login:    HTTP 200 / HTTP 200
versions manifest:      HTTP 200
```

The final live panel audit matched all three release artifact hashes above and reported:

```text
enabled forwards:                       0
pending/running provision tasks:        0
leigod_execution_leases rows:           0
leigod_egress_profiles rows:             0
LEIGOD_EXECUTION_LEASES_ENABLED:         false
LEIGOD_LINUX_HYBRID_ENABLED:             false
CLIENT_CONFIG_WATCH_ENABLED:             false
selected nodes 15/16/19:                 active
client 3:                                active
```

The mandatory read-only seed audit returned:

```text
catalog_lines=5 desired_pairs=4 seeded=0 conflicts=2 unchanged=0
```

The seed algorithm requires one unambiguous current node owner per managed physical line. The two
conflicts make global lease enforcement unsafe: enabling it now could remove desired lines from
existing nodes. No seed apply, egress profile, lease row, forward, or production gate was created.

### 14.3 Fresh LeiGod-only line controls

The valid fresh controls ran on node 15 through its existing line-specific loopback SOCKS and the
unchanged target `v4-hkt-b.mcbar.cc:5201`. A temporary raw TCP proxy performed only the SOCKS5
CONNECT; no sidecar or `hk-bgp-b` path participated.

```bash
iperf3 -4 -c 127.0.0.1 -p <temporary-line-proxy> -R -P 1 -O 2 -t 20 --json
iperf3 -4 -c 127.0.0.1 -p <temporary-line-proxy> -R -P 2 -O 2 -t 20 --json
```

| Line | Shape | Receiver Mbps | Sender Mbps | Retransmits | Zero intervals |
| ---: | --- | ---: | ---: | ---: | ---: |
| 447 | Reverse P1 | 106.362 | 108.087 | 5,599 | 0 |
| 447 | Reverse P2 | 168.610 | 169.336 | 28,132 | 0 |
| 453 | Reverse P1 | 208.068 | 209.173 | 0 | 0 |
| 453 | Reverse P2 | 201.480 | 200.695 | 1,501 | 0 |

```text
evidence directory:
  /root/codex-benchmarks/leigod-hybrid-live-line-controls-20260806T085925Z

line-447-p1.json SHA256: 32f9ee51f12d8ce5a21ca6eb885b3d7c7ff2e085fddfa4ffdc67564c1b336977
line-447-p2.json SHA256: 91222ee6c030efa3a185a9602af1b0e021319e5e44bf8739b19f248d039cb503
line-453-p1.json SHA256: dc02ef20e94feba8af156b049ef2b9bb0acd5dd5ebc7948f7f51e4d3506f600f
line-453-p2.json SHA256: 49c57441f64cdeec233eb6db578414cb4df63ee4ad50f61f8aede0f8fac77dc5
```

This fresh window proves that one slave-hosted LeiGod line can exceed `200 Mbps`: line 453 reached
`208.068 Mbps` with P1. P2 improved line 447 by approximately 58.5%, but did not improve line 453
in this sample. Internal P2 is therefore a useful allocation hedge, not a deterministic multiplier.

The first attempted control directory at `08:54:50Z` is invalid: a temporary Python callback had
the wrong arity, so every JSON contained zero bytes. It is excluded from every conclusion; the
corrected `08:59:25Z` directory above is authoritative.

### 14.4 Flat-hybrid canary and failed egress placement gate

The isolated canary used the committed binaries and no production assignments:

```text
sh36 user listener:             127.0.0.1:61017
sh36 pathd:                     committed 0.1.39 artifact
sh36 local execution group:     physical line 17, two logical lanes
node 15 execution group:        lines 447/451/453/455/456
node 15 public canary ingress:  TCP 8003 (inside the required 7000-9000 range)
node 19 candidate egress:       TCP 8001, committed 0.1.39 bond server
token target:                   v4-hkt-b.mcbar.cc:5201
user test shape:                Reverse P1, never P4
```

Pathd accepted generation 1, started its leased local provider on loopback `21017`, published the
single user listener, and reported `status=ready`. The node 15 relay accepted and connected all
five routes with zero connect failures. Thus provider lifecycle, route identity, flat execution
groups, relay demultiplexing, and bond transport establishment worked on real hosts.

The application smoke nevertheless delivered zero bytes. Segment controls showed why:

| Segment | Reverse-P1 receiver Mbps | Meaning |
| --- | ---: | --- |
| node 15 direct to fixed target | 0.000 | A Shanghai slave is before the required LeiGod exit. |
| node 19 direct to fixed target | 0.000 | The chosen egress was also before the required LeiGod exit. |
| node 19 line 447 SOCKS to fixed target | 141.838 | The fixed target is reachable after a LeiGod line. |
| full pathd/node15/node19 smoke | 0.000 | The egress server opened the target directly from the wrong location. |

```text
sh36 smoke:
  /root/codex-benchmarks/leigod-hybrid-live-8d4f52a-20260806T091055Z/smoke.json
  SHA256 b5642bc3fad1d003692d3798369f56aa22fa4619a7846bee370c75e11b7eb678

node 15 direct control:
  /root/codex-benchmarks/leigod-hybrid-direct-target-20260806T091828Z/reverse-p1.json
  SHA256 6968b66168bf8e55803dd2b11ec3ee86cef0cf8f2cc3d8aadab08236d2ceb765

node 19 direct control:
  /root/codex-benchmarks/leigod-hybrid-egress-control-20260806T091438Z/reverse-p1.json
  SHA256 e5c3d180a7baf0a9265c4793d659f9bb8fab06a2c036f470c86cee89aa57d0c0

node 19 LeiGod control:
  /root/codex-benchmarks/leigod-hybrid-egress-socks-control-20260806T092430Z/reverse-p1.json
  SHA256 7fb80f5753364733b8e47aeb9aedfc2ca4f1ed41875ce6545bb226681026124d
```

This is a topology failure, not a 0.1.39 throughput measurement. A valid egress must be after the
LeiGod paths, in the target/Hong Kong region, and must have a high-capacity direct route to the
fixed target. Putting the aggregator on another Shanghai slave merely adds an unaccelerated final
hop; placing a single additional LeiGod SOCKS after the aggregator would cap one arbitrary TCP
stream at that one line and cannot transparently create 400 Mbps without a corresponding
target-side reassembly endpoint.

Consequently the five-run `>=400 Mbps` / every-run `>=350 Mbps` formal gate was not run. Automatic
bulk exclusion and the interactive-latency-under-bulk gate were also not claimed live because no
valid hybrid bulk stream reached the candidate egress. Their deterministic integration coverage,
the 30-minute RSS soak, and the real 0.1.34 exclusion evidence remain valid, but they are not a
substitute for the missing target-side live gate.

### 14.5 Cleanup and production decision

All isolated canary state was removed after evidence collection:

```text
sh36:
  pathd/provider canary processes: 0
  listeners 21017/61017:           absent
  original line 17 / port 20017:   active / present
  client agent:                    active

node 15:
  relay/front canary processes:    0
  listeners 48003/8003:            absent
  lines 447/451/453/455/456:       all active
  slave agent:                     active

node 19:
  egress canary unit:              inactive and removed
  listener 8001:                   absent
  temporary SOCKS proxy 5202:      absent
  slave agent:                     active

panel:
  enabled forwards/tasks:          0 / 0
  lease rows/egress profiles:      0 / 0
  hybrid and lease gates:          false / false

local worktree:
  temporary scripts/secrets/archive: 0
```

The current `hk-bgp-b` host, domain, sidecar unit, WireGuard state, and data listener were not
modified or used by this LeiGod canary. Read-only reachability attempts to both the older
independent Hong Kong host and the current management endpoint through node 15 did not complete an
SSH banner, so no sidecar login or mutation occurred.

The production decision is **do not activate hybrid yet**. Release 0.1.39 remains deployed inert on
the panel as correctness code and pinned artifacts; the existing client/slave agents were not
force-upgraded because the live throughput gate failed before rollout.

### 14.6 Exact prerequisites for the next run

1. Provision a LeiGod-specific egress in the target/Hong Kong region, independent of
   `hk-bgp-b`, with direct Reverse-P1 capacity comfortably above `500 Mbps` to
   `v4-hkt-b.mcbar.cc:5201`.
2. Associate it through `leigod_egress_profiles` only after its direct segment passes. Do not use
   another Shanghai slave or a single post-aggregator SOCKS line as the egress substitute.
3. Resolve the two seed conflicts so every managed physical line has exactly one intended node
   owner; rerun dry-run until `conflicts=0` before applying or enabling lease enforcement.
4. Recreate one isolated assignment, then run five 20-second Reverse-P1 samples. Activation still
   requires median `>=400 Mbps`, every run `>=350 Mbps`, zero zero-throughput intervals, at least
   two execution groups, no duplicate physical lease, and zero restarts.
5. Only after that gate, run live sub-10 Mbps exclusion and small-request p95-under-bulk checks,
   then enable one production canary. Until then, do not advertise 400 Mbps hybrid aggregation.

## 15. Final operator handoff

This section is the short resumption boundary for the next operator. Section 14 remains the detailed
source of commands, hashes, benchmark evidence, and cleanup state.

### 15.1 What is complete

1. Linux release `0.1.39` is implemented and committed from release source `8d4f52a`. It includes
   fenced execution leases, bounded config watching, a multi-forward `leikwan-pathd`, flat local and
   slave execution groups, slave relay publication, adaptive admission and expiry, membership and
   authorization guards, artifact pinning, and pathd validation/self-heal.
2. The panel contains the committed client, bond, and pathd artifacts and migration 72. The feature
   remains inert: lease enforcement, Linux hybrid execution, and config watching are all disabled.
3. Local correctness gates passed after the final implementation fixes: 147 Rust library tests,
   9 Rust CLI tests, the real flat-hybrid integration, 8/8 focused PHP scripts, 42 focused client
   and installer checks, and the 10/10 full release gate. The 30-minute soak stayed within the
   128 MiB incremental RSS limit and showed no strict tail growth.
4. Fresh line controls used the unchanged target `v4-hkt-b.mcbar.cc:5201`, Reverse P1/P2, and no
   P4. Line 453 proved that one slave-hosted LeiGod line can exceed 200 Mbps by delivering
   208.068 Mbps with P1. Line 447 improved from 106.362 Mbps P1 to 168.610 Mbps P2.
5. The isolated live canary proved provider lifecycle, route identity, flat execution groups,
   relay demultiplexing, and bond transport establishment across sh36, node 15, and node 19.
6. Every temporary provider, relay, listener, proxy, egress unit, upload, and local helper was
   removed. Production agents remained active. `hk-bgp-b` was not used or modified.
7. The user-facing mode remains `Aggregation`; the provider-specific implementation stays inside
   the Linux runtime. Windows parity was explicitly deferred and is not part of this release.

### 15.2 What remains uncompleted or unproved

1. Transparent Reverse-P1 throughput of 400 Mbps or more has not been proved. The full hybrid smoke
   delivered zero bytes because the selected egress was before the required LeiGod exit.
2. No suitable independent target/Hong Kong egress currently exists. It must have direct
   Reverse-P1 capacity comfortably above 500 Mbps to the fixed target before it is attached to a
   hybrid profile. It must remain independent of `hk-bgp-b`.
3. The lease seed dry-run still has two ownership conflicts. Global lease enforcement cannot be
   enabled until a dry-run reports `conflicts=0`.
4. Live automatic exclusion of lines below 10 Mbps and small-request latency under concurrent bulk
   traffic are not yet production-proved. The runtime support exists, but these checks intentionally
   come after the 400 Mbps placement gate.
5. Existing client and slave agents were not force-upgraded to the new hybrid runtime after the
   failed egress gate. Do not treat panel artifact publication as a completed production rollout.

### 15.3 What the measurements proved false

1. It is false that all low LeiGod results identify an architecture ceiling. Line 453 exceeded
   200 Mbps with one P1 stream, while other lines were materially slower. Line and server behavior
   must be measured separately from the aggregation design.
2. It is false that P2 is a deterministic 2x multiplier. P2 improved line 447 by about 58.5 percent,
   but line 453 changed from 208.068 Mbps P1 to 201.480 Mbps P2. Internal parallelism should be an
   adaptive hedge, not a fixed capacity claim.
3. It is false that a Shanghai slave or node 19 can act as a direct target egress in the tested
   topology. Both direct Reverse-P1 controls delivered 0 Mbps; node 19 reached 141.838 Mbps only
   after its line 447 SOCKS exit.
4. It is false that placing one SOCKS connection after the aggregator transparently creates a
   400 Mbps single user stream. That design serializes the aggregate through one physical line.
   Transparent single-stream aggregation requires striping before the bottlenecks and reassembly
   after them at a target-side egress.
5. It is false that application-side `-P 2` alone proves a transparent single-flow product. A client
   can aggregate multiple application connections locally, but an unaware single TCP application
   still needs the internal multi-lane runtime and remote reassembly described above.
6. It is false that latency-only race selection reliably avoids poor throughput. Earlier controls
   selected a low-latency but approximately 1 Mbps line. Throughput admission and expiry remain
   required.
7. It is false that the current evidence supports advertising 400 Mbps hybrid service. The required
   end-to-end gate was never validly exercised, so all production hybrid gates remain off.

### 15.4 Recommended next execution order

1. Provision and first benchmark an independent LeiGod-specific egress in the target/Hong Kong
   region. Reject it unless five bounded direct Reverse-P1 samples show comfortable headroom above
   500 Mbps to `v4-hkt-b.mcbar.cc:5201`.
2. Correct the two catalog ownership conflicts. Repeat the read-only seed audit until
   `conflicts=0`; only then apply seeds or consider lease enforcement.
3. Attach the verified egress through `leigod_egress_profiles`. Keep the public client endpoint
   unchanged and preserve the supported `client -> slave -> sidecar` rule for any sidecar access.
4. Recreate one isolated Linux assignment using at least two execution groups. Run five 20-second
   Reverse-P1 samples. Require median >=400 Mbps, every run >=350 Mbps, zero zero-throughput
   intervals, no duplicated physical lease, and zero process restarts.
5. After the throughput gate passes, inject a sub-10 Mbps line and prove automatic exclusion. Then
   measure small-request p95 during bulk traffic. Prefer a low-latency line for small transfers only
   when it also clears the 10 Mbps floor; send bulk traffic over the admitted high-capacity lanes.
6. Enable one Linux production canary only after all gates pass. Keep the panel label unchanged,
   monitor lease ownership, pathd/provider RSS, restarts, route counters, and user-visible latency,
   and retain immediate rollback. Handle Windows in a later release.

### 15.5 Operational cautions

- Keep benchmarks serial and bounded to avoid loading the operator workstation. Use Reverse P1/P2,
  not P4, unless a later test plan explicitly changes that constraint.
- Do not enable global lease or hybrid gates while the current placement and seed blockers remain.
- Do not reuse `hk-bgp-b` as the LeiGod egress; it is an independent sidecar domain and topology.
- The configured primary panel jump password was rejected during the final documentation sync on
  2026-08-06. The handoff sync completed through an authenticated node 15 SSH management tunnel.
  Refresh the authoritative local credential entry before the next deployment; never copy its value
  into this repository or a handoff.
- At the end of every canary, verify zero temporary units, processes, listeners, uploads, forwards,
  tasks, leases, and egress profiles before declaring cleanup complete.
