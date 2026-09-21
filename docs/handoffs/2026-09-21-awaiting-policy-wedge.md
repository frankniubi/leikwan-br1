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

## Follow-ups

1. The deferral must not be terminal: if no snapshot exists the agent should deliver a **fresh
   lease** rather than defer indefinitely. At present the forward is simply dead.
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
