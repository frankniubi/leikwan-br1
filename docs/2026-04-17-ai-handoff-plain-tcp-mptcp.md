# AI Handoff: Plain TCP Mux / Bond 11024 / MPTCP 11023

## Goal

继续收口两条线：

- `forward 26`，`bond`，本地监听 `11024`
- `forward 25`，`mptcp`，本地监听 `11023`

这次已经把 `plain_tcp_mux` 从“入口不通”推进到了“入口通了，但后段协议还有问题”。
交接重点不是重新找 plain TCP 入口 bug，而是继续追：

1. `bond` 在入口恢复后的后半段稳定性
2. `mptcp` 在 point `4` 上的数据阶段 reset / close

## Branch / Workspace

- branch: `master`
- 当前工作区是脏的，不要做大范围清理
- repo 里有很多 `.tmp-*` 调试脚本，不要默认当成真相，优先相信当前 live 状态和本文档

## Hosts

- panel repo host: `10.0.10.196`
- client host: `172.21.255.189`
- Guangzhou slaves:
  - `8.163.90.132`
  - `8.163.90.235`
  - `8.163.87.42`
  - `8.163.69.176`
  - `8.163.71.73`
- sidecar point `4` host:
  - `138.252.162.120`
- sidecar access must continue to go through a slave hop, do not switch to direct client -> sidecar topology

Do not duplicate credentials into new repo files. Use the local credential files already in the workspace.

## What Was Fixed

### 1. Plain TCP mux code path

Implemented and locally verified:

- `wstunnel-main/leikwan-ingress/src/main.rs`
- `wstunnel-main/leikwan-ingress/src/mux.rs`
- `slave-agent/slave_agent.py`
- `slave-agent/tests/test_slave_plain_tcp_mux.py`
- `master/tests/plain_tcp_mux_rollout_versions_regression_test.php`

Key fix:

- plain TCP mux unit now emits both configured listen hosts, not only `[::]`
- Rust `leikwan-ingress mux` now accepts repeated `--listen`
- live Linux ELF `leikwan-ingress` artifact was rebuilt and pushed

### 2. Slave control-plane loop

Implemented and locally verified:

- `slave-agent/slave_agent.py`
- `slave-agent/tests/test_slave_mptcp_cleanup.py`

Key fix:

- slave management update scheduling no longer pre-empts task handling in the same pull cycle
- stale explicit update token is ignored when the slave is already on the desired agent/install state

This was necessary because the live Guangzhou slaves kept getting stuck behind old `agent_update_request_token` values and never applied fresh publish tasks.

### 3. Admin panel missing actions

Implemented and pushed to panel repo:

- `master/src/Controllers/AdminController.php`
- `master/src/Bootstrap.php`
- `master/tests/admin_dashboard_actions_regression_test.php`

Restored:

- dashboard button: `Republish All Forwards`
- dashboard link: `Diagnostics`
- routes:
  - `POST /admin/republish/all`
  - `GET /admin/diagnostics`

This was verified by grepping the deployed panel repo files on `10.0.10.196`.

### 4. Bond join-timeout config bug

Implemented and pushed:

- `client-agent/install_forward_runtime.py`

Key fix:

- when bond legs are WSS / plain TCP only and there are no QUIC legs, the runtime now preserves panel-provided `join_timeout_ms`
- `forward 26` startup script now renders `--join-timeout-ms 400` instead of collapsing to `115`

## Live State: Forward 26 (`bond`, `11024`)

### Current truth from panel

`buildClientConfig(26)` on panel now shows:

- mode: `bond`
- sidecar target: `138.252.162.120:9000`
- all 5 Guangzhou endpoints:
  - `ingress_protocol = plain_tcp_mux`
  - `port = 7001`
  - valid per-endpoint `ingress_auth_token`

### Current live Guangzhou slave state

All 5 Guangzhou slaves were manually corrected and then brought back with the fixed slave-agent:

- each has metadata for `forward 26` on:
  - `listen_port = 7001`
  - `ingress_protocol = plain_tcp_mux`
  - `backend_protocol = tcp_relay`
- each has:
  - `/var/lib/leikwan-slave/listeners/routes-7001.json`
  - active `leikwan-tcp-mux-7001`
  - active listener on `*:7001`

### Current client state

On `172.21.255.189`:

- `leikwan-client-26.service` is active
- local listener `0.0.0.0:11024` exists
- client `leikwan-ingress` binary was replaced with the current Linux ELF build
- generated runtime script now uses `--join-timeout-ms 400`

### Current sidecar state

On point `4` sidecar host `138.252.162.120`:

- bond runtime:
  - `leikwan-bond-sidecar-4.service`
  - listening on `0.0.0.0:9000`
- mptcp runtime:
  - `leikwan-mptcp-sidecar-4.service`
  - listening on `0.0.0.0:14969`

### What improved

The plain TCP ingress replacement is real now:

- all 5 client-side ingress probes to the Guangzhou slaves returned exit `0`
- sidecar bond server now sees legs arriving from Guangzhou slaves again

One earlier reverse test through `11024` completed and showed around `260 Mbit/s`.

After the later slave-agent/control-plane fixes, the system moved again, but the bottleneck is no longer “entry path unreachable”.

### What is still broken on 11024

`11024` is not fully stable yet.

Latest observed failure modes:

- reverse `iperf3` can still terminate with:
  - `unable to receive results: Resource temporarily unavailable`
- logs still show noisy UDP-side bond issues:
  - `failed to open UDP bond session: invalid bond frame magic`

Interpretation:

- `plain_tcp_mux` itself is not the primary blocker anymore
- remaining issue is later in the bond session lifecycle, especially UDP framing / result-channel completion behavior

## Live State: Forward 25 (`mptcp`, `11023`)

### Current truth from panel

`buildClientConfig(25)` currently shows:

- mode: `mptcp`
- local listen: `11023`
- sidecar runtime target: `14969`
- underlay legs mapped through the 5 Guangzhou slaves

### Current client state

On `172.21.255.189`:

- `leikwan-client-25.service` is active
- `0.0.0.0:11023` is listening
- active process:
  - `/usr/bin/python3 /usr/local/bin/leikwan-mptcp-client.py --config /etc/leikwan/mptcp-forward-25.json`

### Required test command

Use this exact command when checking `11023`:

```bash
iperf3 -c 127.0.0.1 -p 11023 -R -b 500M
```

### Latest result

Latest verified run:

```text
iperf3: error - control socket has closed unexpectedly
```

So this is not fixed.

### Strong evidence gathered

From live logs:

- client MPTCP relay connects to remote sidecar overlay addresses on `:14969`
- sidecar MPTCP relay verifies the token and connects to `193.227.109.82:5201`
- then the stream dies early, often with:
  - client side: `Connection reset by peer`
  - sidecar side: `remote->local eof after 0 bytes`

Direct target tests:

- from **client** to `193.227.109.82:5201`:
  - `iperf3 -R -b 500M` fails with control/reset behavior
- from **sidecar** to `193.227.109.82:5201`:
  - `iperf3 -R -b 500M` succeeds cleanly

This matters because it means:

- target `193.227.109.82:5201` is not universally broken
- sidecar can reach it fine
- the remaining bug is between client local relay and sidecar MPTCP relay, not just “remote iperf server bad”

### Additional suspicious finding

On client, `forward 25` underlay interfaces exist:

- `lw-mptcp-25-0`
- `lw-mptcp-25-1`
- `lw-mptcp-25-2`
- `lw-mptcp-25-3`
- `lw-mptcp-25-4`

But `ip mptcp endpoint show` only consistently shows 2 endpoints for `forward 25`:

- `10.203.107.181 id 3`
- `10.203.107.189 id 4`

Missing:

- `10.203.107.177 id 2`
- `10.203.107.185 id 5`
- `10.203.107.173 id 6`

Trying to add the missing ones manually returned:

```text
Error: too many addresses or duplicate one.
```

That makes `client-agent/leikwan_mptcp_client.py` endpoint management a very strong suspect.

## Known Runtime Noise

### Client agent update helper missing

Client agent still logs:

```text
update helper missing: /usr/local/bin/leikwan-client-agent-update
```

This is noisy and may interfere with clean management loops, but it is not the primary root cause for the current `11023` reset.

### MPTCP endpoint device names drift

`ip mptcp endpoint show` sometimes shows devices like:

- `if19639`
- `if19706`

instead of the expected stable interface names.

This is another sign that MPTCP endpoint registration / cleanup is not converging cleanly on the client.

## Most Likely Current Blockers

### Blocker 1: client MPTCP endpoint convergence is broken

Why this matters:

- only part of the desired `forward 25` endpoint set exists in kernel state
- manual add attempts collide
- current code likely leaves stale endpoint/dev associations behind or deletes/re-adds in the wrong way

Primary files:

- `client-agent/leikwan_mptcp_client.py`
- `client-agent/install_forward_runtime.py`

### Blocker 2: relay stream lifecycle / half-close behavior

Why this matters:

- client relay connects to sidecar relay
- sidecar relay verifies token and connects to target
- then the stream still dies early

Primary files:

- `wstunnel-main/leikwan-mptcp-relay/src/client.rs`
- `wstunnel-main/leikwan-mptcp-relay/src/server.rs`

Especially look at:

- handshake-to-relay transition
- EOF / half-close propagation
- when `remote->local eof after 0 bytes` should or should not terminate

### Blocker 3: UDP stream-compat path is still wrong

This is currently still noisy for bond and may or may not share root cause with MPTCP stream close behavior.

Primary files:

- `wstunnel-main/leikwan-mptcp-relay/src/client.rs`
- `wstunnel-main/leikwan-mptcp-relay/src/server.rs`
- `wstunnel-main/leikwan-mptcp-relay/src/udp_frame.rs`

## Recommended Next Actions

1. Fix client MPTCP endpoint convergence first.

   Check:

   - `client-agent/leikwan_mptcp_client.py`
   - how it parses `ip mptcp endpoint show`
   - how it decides stale endpoints
   - whether it safely handles same-address / wrong-dev cases

   Goal:

   - after restart, `forward 25` should show all 5 expected `subflow` endpoints in kernel state

2. Re-test `11023` only after that with:

   ```bash
   iperf3 -c 127.0.0.1 -p 11023 -R -b 500M
   ```

3. If `11023` still resets, instrument `leikwan-mptcp-relay`:

   - client side before / after handshake flush
   - server side after token verification and after target connect
   - exact EOF / shutdown reason on both directions

4. Only after TCP stabilizes, revisit UDP `invalid bond frame magic`.

## Useful Commands

### Panel

Forward `25` truth:

```php
$cfg = $app->provisioning()->buildClientConfig(25);
```

Forward `26` truth:

```php
$cfg = $app->provisioning()->buildClientConfig(26);
```

### Client

Check `11023`:

```bash
ss -ltnp | grep 11023
iperf3 -c 127.0.0.1 -p 11023 -R -b 500M
journalctl -u leikwan-client-25 --no-pager -n 160 --since '20 min ago'
ip mptcp endpoint show
wg show
```

Check `11024`:

```bash
ss -ltnp | grep 11024
iperf3 -c 127.0.0.1 -p 11024 -R -t 5
journalctl -u leikwan-client-26 --no-pager -n 160 --since '20 min ago'
```

### Sidecar point 4

Through slave hop:

```bash
systemctl status leikwan-bond-sidecar-4 --no-pager -n 80
systemctl status leikwan-mptcp-sidecar-4 --no-pager -n 80
journalctl -u leikwan-bond-sidecar-4 --no-pager -n 160 --since '20 min ago'
journalctl -u leikwan-mptcp-sidecar-4 --no-pager -n 160 --since '20 min ago'
ss -ltnp | egrep ':9000|:14969'
ip mptcp endpoint show
wg show
```

### Guangzhou slaves

Each of the 5 Guangzhou nodes should now show:

```bash
systemctl is-active leikwan-slave-agent
systemctl is-active leikwan-tcp-mux-7001
cat /var/lib/leikwan-slave/listeners/routes-7001.json
ss -ltnp | grep 7001
```

## Do Not Redo

- do not revert the `plain_tcp_mux` slave state back to `7000 / wss_path_mux`
- do not remove the admin dashboard `Republish All Forwards` / `Diagnostics` restore
- do not switch topology away from `client -> slave -> sidecar`
- do not assume `11023` is still “just no listener”, that stage is already past

## Bottom Line

- `plain_tcp_mux` ingress work is real and live
- admin missing-button issue is fixed and deployed
- `11024` is past the original ingress blocker, but still not fully stable
- `11023` is now the clearest remaining hard blocker
- the next best use of time is **client MPTCP endpoint convergence** and then **relay EOF / half-close behavior**

## 2026-04-17 Follow-up Addendum: Bond-Focused Investigation

This section captures later investigation work after the original handoff above.
It is intended to prevent the next session from re-debugging already-cleared layers.

### 1. Live `leikwan-bond` binary is using the newer async session logic

The active client binary at:

- `/usr/local/bin/leikwan-bond` on `172.21.255.189`

contains the exact log strings from:

- `wstunnel-main/leikwan-bond/src/session.rs`

Examples confirmed in the binary string table:

- `bond client TCP transport session established`
- `bond tcp transport closed before accepting`
- `bond client UDP session`
- `bond tcp stream`

This means the remaining `11024` work should target the `session.rs`-based implementation, not the legacy synchronous `client.rs` / `server.rs` path.

### 2. Plain TCP mux and slave backend routing are now consistent across all 5 Guangzhou slaves

Verified on:

- `8.163.90.132`
- `8.163.90.235`
- `8.163.87.42`
- `8.163.69.176`
- `8.163.71.73`

Each slave currently has:

- active `leikwan-tcp-mux-7001`
- `routes-7001.json` with route key `a43547b6350f72cd`
- `wstunnel-svc-1-18x.json`
- `backend_protocol = tcp_relay`
- target `138.252.162.120:9000`

Conclusion:

- the Guangzhou slave route layer is probably **not** the primary remaining bottleneck for `11024`

### 3. Point `4` bond vs MPTCP sidecar separation is real

Live point `4` currently runs separate services:

- `leikwan-bond-sidecar-4.service`
- `leikwan-mptcp-sidecar-4.service`

So the current `11024` issue is not best explained by “bond and MPTCP sharing the exact same sidecar runtime”.

### 4. The noisy UDP bond path is real, but only part of the problem

Client `forward 26` normally renders two bond client processes on the same local port:

- TCP:
  - `leikwan-bond client --protocol tcp --listen 0.0.0.0:11024 ...`
- UDP:
  - `leikwan-bond client --protocol udp --listen 0.0.0.0:11024 ...`

The UDP side keeps logging:

- `failed to open UDP bond session: invalid bond frame magic`

A manual live experiment temporarily removed the UDP process and forced a TCP-only
`/usr/local/bin/leikwan-client-start-26.sh`.

Observed result:

- the UDP noise disappeared
- but `11024` still did **not** reach 400M+
- representative reverse run was still around `175 Mbits/sec`

So:

- background UDP session churn is noise and likely some interference
- but it is **not** the full root cause of the `11024` throughput/stability problem

### 5. Latest representative `11024` measurements

With the normal runtime:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 500M
```

One observed run:

- receiver around `221 Mbits/sec`
- still ended with:
  - `unable to receive results: Resource temporarily unavailable`

With a temporary manual TCP-only `start-26.sh`:

- receiver around `175 Mbits/sec`
- no 400M+ result
- still unstable

Interpretation:

- the ingress replacement is real
- but the remaining problem now looks like a `leikwan-bond` TCP transport/session issue

### 6. Strongest current bond-specific root cause hypothesis

The most suspicious code is:

- `wstunnel-main/leikwan-bond/src/session.rs`

Why:

1. Single-flow striping may start too late

   The current TCP transport logic promotes a stream into `bulk_mode` only after
   fairly large backlog / sent-byte thresholds.
   Before that, the stream keeps a sticky `preferred_leg_id`, so a single large
   TCP flow may spend too long pinned to one leg before multi-leg striping really starts.

2. Stream close / reset handling may be too aggressive

   Relevant live client log lines:

   - `bond tcp transport closed before accepting ...`
   - `bond tcp stream 2 local read failed: Connection reset by peer (os error 104)`

   In `session.rs`, these paths are worth re-checking:

   - `LocalReadFailed`
   - `LocalWriteFailed`
   - `close_timeout`
   - `close_transport_stream(...)`
   - `arm_transport_stream_close_deadline(...)`

   It is plausible that a transient leg problem or local half-close is collapsing
   the whole transport stream too early.

### 7. Source/build state note

Important repo fact:

- live `leikwan-bond` behavior matches the newer async `session.rs` strings
- but repo `wstunnel-main/leikwan-bond/src/main.rs` initially still pointed at the legacy synchronous `client.rs` / `server.rs`

This mismatch was corrected locally during the investigation so that:

- `cargo check -p leikwan-bond`

now succeeds against the `session.rs` code path.

Touched local files during that alignment:

- `wstunnel-main/leikwan-bond/Cargo.toml`
- `wstunnel-main/leikwan-bond/src/main.rs`
- `wstunnel-main/leikwan-bond/src/token.rs`
- `wstunnel-main/leikwan-bond/src/transport.rs`
- `wstunnel-main/leikwan-bond/src/session.rs`

Do **not** assume the previously checked-in Rust entrypoint still matches the live artifact.

### 8. Client agent overwrite caveat

While testing `11024`, `leikwan-client-agent.service` kept restoring `forward 26`
to the normal TCP+UDP startup script.

During one experiment the client agent was manually stopped so `start-26.sh`
could stay in a temporary TCP-only form.

Before any next live test, explicitly check:

```bash
systemctl is-active leikwan-client-agent
sed -n '1,220p' /usr/local/bin/leikwan-client-start-26.sh
ps -ef | grep '[l]eikwan-bond client --protocol.*11024'
```

### 9. Recommended next action from this point

For `11024`, the next best use of time is no longer ingress/slave plumbing.
It is:

1. Add targeted regression coverage around:
   - earlier multi-leg striping for a single TCP stream
   - less aggressive reset/close behavior when a leg blips

2. Then patch:
   - `wstunnel-main/leikwan-bond/src/session.rs`

Priority areas inside that file:

- `flush_tcp_stream_backlogs(...)`
- `should_enable_bulk_tcp_striping(...)`
- `select_stream_affinity_leg(...)`
- `select_retransmit_leg(...)`
- `LocalReadFailed` / `LocalWriteFailed` handlers
- `close_timeout` logic

## 2026-04-17 Planning Addendum: Multi-Slave Aggregation / Bad-Node Avoidance

This addendum is meant to keep the next session from re-deriving the scheduler
shape from scratch.

### 1. What the repo already has

`wstunnel-main/leikwan-bond/src/session.rs` already contains the right base
pieces:

- small / interactive traffic keeps sticky affinity with `preferred_leg_id`
- large flows eventually promote into `bulk_mode`
- retransmits already try to escape the previous leg via `select_retransmit_leg(...)`
- leg choice already considers:
  - `priority`
  - `inflight_bytes`
  - `smoothed_rtt`

So the next pass should **not** throw away the async `session.rs` transport.
The missing part is a clearer policy for:

- when the second slave should join a long TCP flow
- when the remaining slaves should be allowed to join
- how to temporarily avoid a bad slave without dropping the whole session
- how to remember bad slaves across service restarts

### 2. Recommended striping policy

Use a 3-stage model for TCP transport streams:

1. `sticky`

   - one preferred slave only
   - keep today's low-latency behavior for tiny / interactive traffic

2. `warmup`

   - allow **2** slaves
   - enter when a stream looks long-lived, instead of waiting for today's late
     `bulk_mode` threshold
   - recommended defaults:
     - `warmup_trigger_bytes = 65536`
     - `warmup_slice_bytes = 65536`
     - `warmup_leg_delay_ms = 80`

3. `bulk`

   - allow all healthy slaves
   - recommended defaults:
     - `bulk_promote_bytes = 262144`
   - once in bulk, keep today's inflight / RTT aware scheduling

Interpretation:

- the first slave gets the earliest bytes
- the second slave joins quickly once the flow proves it is not interactive
- the remaining slaves do not jump in immediately; they join only once the flow
  has enough volume to justify full striping

This is the safest way to improve single-flow aggregation without making short
flows noisier.

### 3. Recommended bad-node avoidance model

Use **two layers** of protection.

#### Layer A: fast, in-session avoidance inside `leikwan-bond`

When a slave leg closes or becomes retransmit-heavy:

- do **not** kill the whole transport immediately
- mark that leg as cooling down for new data
- keep ACK / FIN / control draining behavior intact
- recommended default:
  - `leg_cooldown_ms = 3000`

Practical rule:

- `LegClosed` should mark the leg inactive as today
- repeated retransmits on the same leg should apply a short scheduling penalty
- `select_leg_for_data(...)` should ignore cooling-down legs first
- if every leg is cooling down, fall back to the least-bad active leg so the
  stream does not deadlock

#### Layer B: slower, persistent avoidance inside `client-agent`

The client agent already probes each slave endpoint. Extend that into a
persistent per-forward health store:

- key: `forward_id + endpoint_id`
- fields:
  - consecutive success count
  - consecutive failure count
  - last error
  - `quarantine_until`
  - `probation_until`

Recommended defaults:

- 2 consecutive probe failures -> degrade to `probation` / backup priority
- 3 consecutive probe failures -> `quarantine` for 90 seconds
- first success after quarantine -> re-enter as `probation`
- 3 consecutive successes -> restore normal priority

This gives:

- fast local avoidance during a live transport session
- slower cross-restart memory so obviously bad slaves stop being selected first

### 4. Where to implement it

#### Rust transport runtime

- `wstunnel-main/leikwan-bond/src/session.rs`
- `wstunnel-main/leikwan-bond/src/main.rs`

Add:

- striping stage state per TCP stream
- leg cooldown metadata
- CLI/config fields for:
  - `warmup_trigger_bytes`
  - `warmup_slice_bytes`
  - `warmup_leg_delay_ms`
  - `bulk_promote_bytes`
  - `leg_cooldown_ms`

#### Client runtime selection

- `client-agent/client_agent.py`
- `client-agent/install_forward_runtime.py`

Add:

- persistent endpoint health JSON in the client state dir
- endpoint roles:
  - `healthy`
  - `probation`
  - `quarantined`
- dynamic leg priorities when rendering bond legs
  - healthy legs keep normal priority
  - probation legs render with worse priority
  - quarantined legs are omitted unless every endpoint is quarantined

Important:

- first pass does **not** need panel schema changes
- runtime defaults can live in `install_forward_runtime.py` first
- panel/env tunables can be a later pass if needed

### 5. What to test

At minimum add regression coverage for:

- a long TCP flow enters `warmup` much earlier than today's `bulk_mode`
- warmup only admits 2 slaves before full bulk
- bulk admits all healthy slaves
- a retransmit-heavy / recently closed leg is skipped for new data
- a repeatedly failing slave endpoint becomes quarantined in `client-agent`
- a quarantined endpoint re-enters as lower-priority probation instead of
  immediately taking full traffic again

### 6. Recommended implementation order

1. Client-agent persistent health + runtime priorities
2. Rust striping stage machine (`sticky -> warmup -> bulk`)
3. Rust fast leg cooldown
4. Diagnostics/logging
5. Live validation on `forward 26`

This order should produce value early:

- we can start avoiding obviously bad slaves before touching the Rust scheduler
- then we can improve throughput by admitting additional slaves earlier
- then we can smooth out live-session failures without over-resetting streams
