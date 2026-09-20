# Ultra acceptance: two corrections and the real churn cause (2026-09-21)

## Correction 1: the deployed panel already has the UoT lease fix

I previously claimed the panel lacked `attachExternalUotToLease`. That was
wrong. The live panel matches the handoff exactly:

```
ClientAgentController.php   sha256 b11090f0531bc4a4ae8240580fcf4b6a2227b02044463f9b99224634ba0394e9
UltraClientStatusService.php sha256 0aec051929d36f90f5d0a902150ad2d73483b56da7b8328fc2a52492e6e5839b
```

Both are the hashes recorded in the handoff deployment table, and the file
contains `attachExternalUotToLease`, nine `external_uot` references and
`ultra_status_accepted`. So the panel-side fix is live and does not need
deploying. It also means changing the panel would not have fixed anything.

## Correction 2: the agent is not missing UoT support either

The merged `client-agent/client_agent.py` and the deployed
`/usr/local/bin/leikwan-client-agent.py` are **byte-identical** at 339,755 bytes,
both stamped `AGENT_VERSION = "2026.09.19.1"`. Neither mentions `external_uot`,
`delivery_mode`, `sidecar_target` or `udp_transport`. There is no
merge-versus-deployment gap here; whatever handles UoT delivery lives elsewhere,
and the earlier "the deployed agent is old" statement was wrong.

## The real reason lease delivery fails

The agent logs the cause one line before the rollback:

```
WARNING Ultra lease delivery failed for forward 207: no_healthy_candidate
WARNING Ultra lease delivery failed for forward 203: no_healthy_candidate
WARNING forward 207 Ultra runtime handoff rolled back after lease delivery failure
ERROR failed to apply forward 207 mode=single transport=udp endpoints=0: ultra lease delivery failed
```

So the failure is `no_healthy_candidate` — the runtime reached the panel, asked
for candidates, and every one was rejected — not a missing or malformed
`external_uot` grant. Only forwards **203 and 207** show this; 201, 202, 209 and
210 do not. That matches the earlier finding that 203 and 207 are the two whose
candidate pools consist entirely of Chinese provider relays that time out.

## The churn that caused ConnectionRefusedError

Forward 202 was stopped and restarted four times inside the 600 s window:

```
02:19:38 Stopped  -> 02:20:41 Started  (63 s down)
02:28:14 Stopped  -> 02:28:51 Started  (37 s down)
02:31:12 Stopped  -> 02:31:39 Started  (27 s down)
02:41:12 Stopped  -> 02:41:46 Started  (34 s down)
```

The units were deactivated cleanly (`NRestarts=0`, `Result=success`), so these
are supervised stop/start cycles rather than crashes. 161 seconds of the 600 were
therefore down, which is where the 391,543 `ConnectionRefusedError` values come
from and where the 12% UDP loss overlaps.

The agent log shows the restart reason explicitly:

```
forward 203 runtime restart reasons=unit_changed,files_changed,service_inactive
```

So a config re-apply that changes the unit or its files tears the forward down and
brings it back up rather than leaving a running forward in place. That is the
mechanism behind the user-visible disconnects, and it is the same one recorded in
the handoff as the reason a ten-minute run could not complete.

## What this means for the fix

Two separate problems, and neither is where I said it was:

1. **Forwards 203 and 207** never get a healthy candidate, so their lease
   delivery fails, they roll back, and they stay down. Candidate-pool problem.
2. **Forwards 201/202/209/210** work, but any config re-apply that changes unit or
   files stops and restarts them, producing the refusal window under sustained
   load. Preservation-across-rotate problem.

Problem 2 is the one the operator reported as disconnects, and it is what the
`TryPreserveRunningUltra` / lease-renewal-deadline work in the merged tree
targets.
