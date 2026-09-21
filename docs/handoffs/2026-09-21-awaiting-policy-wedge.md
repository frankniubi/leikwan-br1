# 2026-09-21 — Linux forward wedged in `awaiting_policy`: agent refuses to apply a static runtime change

Discovered while re-pointing test forwards 201/202 at a second sidecar. **This is a product
defect, not a test artifact**, and it leaves a forward permanently down with no listener.

## Sequence

1. Panel-side I changed forward 201/202 `target_host`/`target_port` (and earlier
   `transport_mode`). Each change bumps `api_revision`, which the agent treats as a
   **static runtime change** requiring a runtime restart.
2. The Python client agent (`client-agent/client_agent.py`, Linux) cannot complete that restart.
   It logs, repeatedly:

   ```
   12:48:01 WARNING forward 201 Ultra static runtime change deferred because no previous lease snapshot is available
   12:48:01 WARNING forward 202 Ultra static runtime change deferred because no previous lease snapshot is available
   12:48:39 WARNING forward 201 Ultra static runtime change deferred because no previous lease snapshot is available
   12:49:08 WARNING forward 201 Ultra static runtime change deferred because no previous lease snapshot is available
   ```

3. Meanwhile the runtime process is alive but **holds no lease**, so it blocks on its control
   socket and never binds:

   ```
   $ ss -Hlnut | grep -E ':(60501|60502)'      -> (empty)
   $ cat /run/ultra/forward-201.status.json    -> state=degraded, error_code=awaiting_policy
   201 state= degraded udp_transport= None err= awaiting_policy
   202 state= degraded udp_transport= None err= awaiting_policy
   ```

4. It **does not self-recover.** Retries continue indefinitely; the forward stays down.

## Evidence the config is the problem

`/etc/ultra/forward-201.json` on the Linux host contains everything the runtime needs except
the lease:

```
keys: auto_select, connect_timeout_seconds, control_socket, external_uot, forward_id,
      listen_host, listen_port, location_cache_path, location_lock_path, max_connections,
      policy_generation, probe_*, status_path, target, target_digest, transport,
      udp_keepalive_interval_seconds, udp_prewarm, udp_queue_packets, zone_aliases
has_ultra_access = False
target = "70.36.96.183:62101"      <-- STALE; panel now says 103.246.219.39
```

The lease (`ultra_access`) is **not** in the file by design — it is pushed by the agent over the
control socket. So a runtime whose agent never delivers a lease can never serve, and a config
change that requires a runtime restart cannot be applied without a "previous lease snapshot".

Note the target is also still the OLD sidecar, proving the change never took effect.

## Why this matters beyond the test

Any operator action that bumps `api_revision` on an Ultra forward — changing target, port or
transport — can wedge that forward into `awaiting_policy` with **no listener and no automatic
recovery**, if the agent lacks a lease snapshot at that moment. The panel reports the forward as
enabled; only the runtime status file reveals it is dead. That is a silent outage.

## Contrast: macOS did NOT hit this

The macOS host applied the same change cleanly and remained healthy throughout:

```
ultra-cli 11766 leikwan 7u IPv4 TCP 127.0.0.1:60501 (LISTEN)
ultra-cli 11772 leikwan 7u IPv4 TCP 127.0.0.1:60502 (LISTEN)
201 state= ready err= None
202 state= ready err= None
grep -c 'deferred because no previous lease' -> 0
```

The macOS client is the **.NET** agent; Linux runs the **Python** agent. So the
"no previous lease snapshot" deferral path is at minimum far more reachable in the Python agent.
This is the second defect in this session where the Python and .NET agents disagree.

## CORRECTION after deploying the guard fix — the guard is a symptom, not the cause

I fixed the deferral guard (`efd3f967`) so it only defers when the runtime is genuinely
serving, deployed it to the Linux host, and **the forward still did not recover**. That
falsified my own first diagnosis, and the reason is the important finding:

```
installed sha256 = f6d7d9458587baf6...   (the fixed agent, helper present)
ss -Hlnut | grep -E ':(60501|60502)'  -> 0   (listener genuinely not serving)
/etc/ultra/forward-201.json -> listen_port=60501 transport=tcp+udp
                               has_ultra_access = False
still logged: "deferred because no previous lease snapshot is available"
```

The listener check therefore *passed* (runtime not serving), and the deferral came from the
other clause: **no fresh lease exists to apply**. Verified by comparing every config on the host
— `ultra_access=False` on the healthy forwards too (158/159/170/171/180), so the lease is never
part of the on-disk render; it is delivered separately. The difference is in the log:

```
forward 170 independent Ultra lease renewal delivered        <- healthy forwards get leases
forward 201 ... deferred because no previous lease snapshot  <- this one never does
```

### Actual root cause: changing a forward's target permanently un-provisions it

The wedge was triggered by my own panel change of forward 201/202 `target_host`
(`70.36.96.183` → `103.246.219.39`). That bumps `api_revision` and invalidates the lease policy
for the old target, and **the panel then stops issuing leases for that forward**. So:

1. target changed -> runtime must restart to pick it up
2. the restart requires a lease to hand the new runtime
3. no lease is available (old policy invalid, new one never issued) -> agent defers forever
4. the forward stays `awaiting_policy` with **no listener** while the panel reports it enabled

Removing the guard would not help, because there is still no lease to deliver.

### Recovery

Reverting the target in the panel restored both forwards within one poll cycle:

```
RESTORED 201 -> 70.36.96.183:62101 / 202 -> 70.36.96.183:62102
t+45s listeners=4  201=ready  202=ready  cfg_target=70.36.96.183:62101
13:01:40 forward 201 independent Ultra lease renewal delivered
13:01:40 forward 202 independent Ultra lease renewal delivered
```

**A target change is therefore not reversible-by-retry and must be treated as a
re-provisioning event, not a config edit.** The operator path needs either an explicit
re-provision that issues a fresh lease for the new target, or a UI guard that refuses the edit
while the forward is live.

## Follow-ups

1. **Panel/agent: a target change must issue a fresh lease for the new target, or be refused.**
   As it stands the forward is silently dead until the target is reverted. This is the real fix;
   the deferral guard (`efd3f967`) is necessary but not sufficient, and is retained because it
   stops the agent from deferring for a runtime that is provably not serving.
2. Surface `awaiting_policy` as a health failure — the panel currently shows the forward
   enabled while it has no listener.
3. Reconcile the Python and .NET agent behaviour for static runtime changes.
4. Test environment: Linux forward 201/202 are wedged and need either a fresh lease delivery or
   an agent state reset before they carry traffic again. macOS 201/202 are healthy and serving.

## Also recorded this round

- The A/B I attempted with a persistent-TCP harness variant was **invalid** and is not evidence:
  `tcp_conns_opened=178,282` over 300 s shows the connection was re-established per request, so
  it measured a connection storm the harness itself created, not persistent-connection behaviour.
- The sidecar `103.246.219.39` was checked directly and is **healthy**: `leikwan-external-uot`
  active, pump PID 78102 up 1:48:12, UDP 62101 and TCP 62102 (backlog 1024) bound,
  `skmem f4096 d0` (zero socket drops), no OOM, `do_sys_poll`. The 100% UDP loss seen in that
  window came from the **client side being wedged**, not the sidecar.
