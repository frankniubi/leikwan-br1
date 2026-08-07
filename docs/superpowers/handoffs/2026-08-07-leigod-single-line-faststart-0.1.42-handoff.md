# LeiGod Single-Line Bond Fast-Start 0.1.42 Handoff

Date: 2026-08-07 (Asia/Taipei)

Status: deployed and validated during this session. This document starts from
`2026-08-07-single-leigod-line-local-bond-0.1.41-handoff.md` and records only
the subsequent fast-start work and its production rollout.

Passwords, tokens, private keys, and raw connection material are intentionally
omitted.

## 1. Requirement that remains unchanged

The desired production behavior is still:

- reuse one physical LeiGod line several times, currently `693,693,693`;
- create three independent local TCP bond legs through that same LeiGod SOCKS
  line on the Linux source host;
- reassemble them at the Frankfurt receiver so one TCP application flow can
  use their aggregate capacity;
- keep the public/source listener on port `20013` available;
- improve bulk-flow startup without adding a continuous padding flow or a
  material steady-state latency penalty.

Topology remains unchanged:

```text
client -> source Linux bond/bridges -> repeated LeiGod line 693 -> Frankfurt receiver
```

This is not a multi-provider or multi-physical-line solution. The three legs
share the same physical LeiGod line, so it can improve how much the tunnel
uses that line, but it cannot exceed the line's real congestion or provider
capacity.

## 2. What was actually causing the initial slow result

The `0.1.41` local same-line bond was functioning. The poor first second seen
in the early `iperf3 -R` runs was primarily the first bulk burst through a
newly created, long-lived outer transport. It was not a per-application-flow
penalty after the transport had warmed up.

Observed facts from an isolated test using the same real repeated line `693`:

| Case | First one-second sample | Ten-second average |
| --- | ---: | ---: |
| Fresh outer transport before fast-start | 0.52 Mbit/s | 249.29 Mbit/s |
| Immediately repeated hot run | 285.45 Mbit/s | 296.14 Mbit/s |
| Hot run after 60 seconds idle | 289.92 Mbit/s | 336.90 Mbit/s |

Both source and receiver already had `net.ipv4.tcp_slow_start_after_idle=0`,
and both used BBR. Therefore, standard Linux idle congestion-window reset was
not the explanation. The relevant boundary was creation/reconnection of the
persistent three-leg transport, not every later application connection or an
ordinary one-minute quiet period.

## 3. New 0.1.42 fast-start implementation

### 3.1 Protocol and receiver

`leikwan-bond` was raised from `0.1.41` to `0.1.42`.

- Added `FrameKind::StartupWarmup = 18` and
  `WireFrame::StartupWarmup { payload }`.
- Added receiver CLI option `--startup-warmup-bytes-per-leg`; default is `0`,
  and the implementation caps an explicit value at 2 MiB per leg.
- When configured with a positive value, the receiver sends the warmup frame
  before `HelloAck`. The client consumes it and only marks the leg ready after
  the following acknowledgement.
- Initial legs warm concurrently. A later replacement leg receives its own
  warmup before it is accepted.
- UDP keeps a zero-byte warmup path.

The zero default preserves old receiver behavior. A `0.1.42` client can still
connect to an old receiver that sends no warmup; the new production fast-start
receiver is intentionally isolated on a new port.

### 3.2 Source policy and generator

`leigod-forward/scripts/leigod_bond.py` now supports these optional fields per
bond rule:

```text
bond_preferred_initial_legs
bond_minimum_initial_legs
bond_initial_leg_wait_ms
```

Their legacy-compatible defaults are `min(2, number_of_legs)`, `1`, and
`600 ms`. The generator validates the values and renders them into the source
environment and the client command as:

```text
--preferred-initial-legs
--minimum-initial-legs
--initial-leg-wait-ms
```

The production same-line rule now explicitly requires three preferred initial
legs, permits startup after two, and waits up to five seconds for initial leg
formation. Existing `speed_max` bulk behavior and latency-first handling stay
in place. No continuous keepalive or bulk padding loop was added.

## 4. Controlled A/B evidence

The fast-start A/B used temporary source bridge ports `26160-26162`, temporary
receiver port `29126`, and the same repeated LeiGod line `693`. It did not
replace the production `20013` listener during measurement.

The `0.1.42` test configuration used:

```text
client:   preferred=3, minimum=2, initial-leg-wait=5000 ms
receiver: startup-warmup-bytes-per-leg=1048576
```

The persistent transport reached ready state in about `1183 ms`. Its first
reverse business flow produced the following ten one-second samples:

```text
228.31, 324.01, 332.40, 419.43, 318.75,
320.86, 367.02, 465.56, 420.52, 455.05 Mbit/s
```

The ten-second average was `365.19 Mbit/s`, compared with `249.29 Mbit/s` for
the fresh no-fast-start baseline. Most importantly, the first-second collapse
was removed in this controlled run.

All temporary listeners, test services, and `/run/leigod-faststart-probe` were
removed afterwards. No temporary port among `20016`, `26160-26162`, `29126`,
or `5210` remained listening.

## 5. Production rollout completed

### 5.1 Frankfurt receiver: `89.144.30.119`

The existing shared receiver remains active and untouched:

```text
service: leigod-bond-target.service
listen:  0.0.0.0:29026
```

A separate fast-start receiver was installed and enabled:

```text
service: leigod-bond-target-faststart.service
unit:    /etc/systemd/system/leigod-bond-target-faststart.service
script:  /etc/leigod-bond/bond-target-faststart.start.sh
binary:  /usr/local/bin/leikwan-bond-faststart-0.1.42
listen:  0.0.0.0:29027
warmup:  1048576 bytes per leg
```

The separate port allows rollback to `0.1.41` without disrupting other users
of the shared `29026` receiver. At deployment verification time, the unit was
active, enabled, had `NRestarts=0`, `ExecMainStatus=0`, three established
legs, and no recent receiver errors during a quiet 35-second observation
window.

### 5.2 Source: `10.10.10.171`, bond rule `id=3`

The active source rule is now equivalent to:

```text
bond_legs=[693,693,693]
bond_server=89.144.30.119:29027
bond_bin=/opt/leigod/bin/leikwan-bond-faststart-0.1.42
bond_preferred_initial_legs=3
bond_minimum_initial_legs=2
bond_initial_leg_wait_ms=5000
bond_leg_base=26130
listen_port=20013
```

The source binary is the `0.1.42` static musl build with SHA256:

```text
a4157920135f62ba99826e493974b9a81799b15a1d51892766cc55b81c236ab9
```

The generated runtime was checked to use Frankfurt port `29027` and the three
initial-leg settings above. `leigod-bond-bridge@3.service` and
`leigod-bond@3.service` were both active and enabled. Port `20013` remained
listening and was owned by `leikwan-bond`.

The deployed source generator hash was:

```text
8e42a5c26607a97c941d7b5ccf99dbcb2cc759e1d5cd73be9080c35ea4e75a44
```

Source rollback material exists at:

```text
/root/leigod-forward-backups/id3-faststart-0.1.42-20260807T061601Z
```

It includes the pre-switch rule and generated runtime files. It does not need
to be copied into the repository.

## 6. Validation performed

### 6.1 Source and build validation

- `cargo test -p leikwan-bond` under WSL passed: 156 Rust library tests, 10
  CLI tests, and 1 integration test.
- New coverage includes warmup frame encoding, warmup-before-ack ordering,
  client handshake consumption, and receiver CLI acceptance.
- `cargo build -p leikwan-bond --release --target x86_64-unknown-linux-musl`
  produced the deployed static x86-64 binary.
- `python -m py_compile scripts/leigod_bond.py` passed.
- Two focused generator tests passed:
  `test_bond_add_renders_separate_runtime_and_preserves_legacy_lgfwd_output`
  and `test_bond_sync_honors_explicit_initial_leg_policy`.
- `git diff --check` passed in the `leikwan-br1` worktree.

### 6.2 Current local change inventory

The `master-hybrid-runtime-20260807` worktree has these intentional,
uncommitted `0.1.42` changes:

```text
wstunnel-main/Cargo.lock
wstunnel-main/leikwan-bond/Cargo.toml
wstunnel-main/leikwan-bond/src/frame.rs
wstunnel-main/leikwan-bond/src/main.rs
wstunnel-main/leikwan-bond/src/pathd.rs
wstunnel-main/leikwan-bond/src/session.rs
```

The separate `leigod-forward` directory has no Git metadata; its changed
generator and focused test are therefore local working files rather than a
commit. No unrelated repository changes were reverted.

## 7. Known startup behavior and follow-up

During the source deployment restart, `leigod-bond@3` made its first outbound
connection attempt before the three bridge listeners had finished binding. It
logged connection-refused errors once, then its existing persistent-transport
retry succeeded about one second later. The receiver logged corresponding
aborted-header attempts only in that restart interval.

This was a bridge-readiness race during service restart, not a steady-state
bond failure. The final 35-second quiet window showed no further errors or
restarts. If minimizing restart outage is a separate future goal, add an
explicit bridge-listener readiness gate before starting `leigod-bond@3` rather
than weakening the fast-start logic.

The controlled A/B demonstrates the transport improvement. A real client-side
acceptance test should still be run to measure the actual OpenWrt path and
current provider conditions:

```sh
iperf3 -c 10.7.1.1 -R -t 60 -i 1
```

Let that test finish normally so the stable average is meaningful. Measure
interactive latency separately while idle and under load; the new warmup runs
only when the persistent transport is created or re-created, so it does not
create an ongoing bandwidth stream by design.

## 8. Rollback outline

The old receiver on `29026` was deliberately retained. To revert the source
rule, restore the backed-up `id=3` rule/generated files or change its receiver
back to `89.144.30.119:29026` and binary back to the prior `0.1.41` binary,
then regenerate and restart the source bridge and bond services in that order.
Only after no source is using `29027` should
`leigod-bond-target-faststart.service` be stopped and disabled.

Do not stop `leigod-bond-target.service`; it is the original shared receiver
on `29026` and was intentionally left online throughout this rollout.
