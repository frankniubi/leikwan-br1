# Ultra acceptance: exact mechanism behind the remaining churn (2026-09-21)

## Correction: there is no cross-forward coupling

I previously claimed that one forward failing its lease delivery stopped the
others in the same sync cycle. That was wrong. `sync_once` already isolates each
forward:

```python
for future in concurrent.futures.as_completed(apply_futures):
    forward_id, mode, transport, endpoint_count = apply_futures[future]
    try:
        future.result()
    except Exception as exc:
        logging.exception("failed to apply forward %s ...", forward_id, ...)
```

Each failure is caught and logged against its own forward id, so one forward
cannot abort another. The cross-forward story should be discarded.

## The real mechanism

`sync_once` stops a forward outright, deleting its files, when the panel gives it
no usable Ultra lease **and** it has no previously applied definition to fall
back on:

```python
if backend_mode == "ultra" and not ultra_lease_candidates(runtime_forward):
    if definition_present:
        # preserve last known-good runtime and keep retrying
        desired_ids.add(forward_id); continue
    skipped_invalid_forward_ids.append(forward_id)
    self.log_invalid_forward(forward_id, "ultra lease missing or rejected by panel")
    self.stop_forward(forward_id, delete_files=True)
```

So the behaviour depends on whether a prior definition exists:

| prior definition | behaviour |
|---|---|
| present | keeps running, logs `preserving last known-good runtime`, retries |
| absent | logs `ultra lease missing or rejected by panel`, **stops and deletes** |

## Observed state

```
applied  = [158, 159, 170, 171, 203, 204]
invalid  = [201, 202, 207, 209, 210]
total    = 15
```

Forward 202 shows the transition: it ran, then at 03:00:28 logged
`ultra lease missing or rejected by panel`, after which its unit went
`inactive` with `NRestarts=0, Result=success`. That is this branch stopping it,
not a crash and not another forward affecting it.

## What is not yet established

The forward rows themselves are all sound, so the rejection is not about the
forward definition:

```
201..210: service_id=30 line_client_id=3 backend_mode=ultra enabled=1
          user_deleted_at=null balance_suspended_at=null api_revision=2
```

There is no clean split by combination, sidecar or transport between the five
that are invalid and the five that are not. Two candidate explanations remain
untested:

1. **Burst creation.** Ten forwards were created within a few minutes, and the
   last five to be created are largely the ones rejected. A per-client or
   per-device lease issuance limit would look exactly like this.
2. **Grant cache scope.** The desktop holds sidecar grants in memory keyed by
   forward/generation/target/expiry. That cache is desktop code, but if the
   agent has an equivalent path, a target change after the grant was cached
   would invalidate it.

Both are cheap to distinguish: delete the test forwards and recreate two of the
rejected ones, then see whether they obtain leases. That is the next step.

## What is established and stands

The `policy_generation` restart fix is verified and unaffected by any of this:

| metric | before fix | after fix |
|---|---|---|
| UDP loss over 600 s | 12.1973% | **0.0%** |
| TCP failure rate | 93.8168% | **0.6707%** |
| ConnectionRefusedError | 391,543 | **0** |
| forward unit restarts in 600 s | 4 | **0** |
