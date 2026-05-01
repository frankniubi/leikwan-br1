# Bond Leg Reputation and Task Ops Manual

## How adaptive leg states work

Each bond leg now tracks TCP and UDP health independently.

- `Healthy`: full traffic participation
- `Degraded`: still used, but with a much lower effective weight
- `Probation`: almost no real traffic, mostly recovery observation
- `Quarantined`: excluded from normal bulk traffic until recovery signals show up

This matters because one toxic leg should stop dragging the whole pool down.

## TCP and UDP are judged separately

A leg can be bad for TCP and still look acceptable for UDP, or the reverse.

The runtime keeps separate health state, score, effective weight, delivery EWMA, and penalty EWMA for:

- `tcp`
- `udp`

Panel visibility follows that same split. Panel reputation is reference-only. It does not feed back into runtime routing.

## What the admin sees on `/admin`

The admin landing page now surfaces three fast triage blocks:

### Task Queue Health

Shows:

- pending count
- running count
- failed count
- oldest pending timestamp
- oldest running timestamp

This is the fastest way to tell whether the queue is simply busy or actually stuck.

### Recent Failed Tasks

Shows the newest failed provisioning tasks first, with:

- task id
- node
- action
- forward id
- endpoint id
- attempt count
- updated time
- short error summary
- inline expandable details

Use this before diving into raw logs.

### Worst Legs Right Now

Shows the currently weakest visible legs across current snapshots, including:

- observer type and id
- forward id
- protocol
- host
- state
- effective weight
- update time

## Reading admin diagnostics

`/admin/diagnostics` now includes a `Bond Leg Reputation` section.

It contains:

- current reputation rows
- recent reputation events

Use it to answer two questions fast:

1. which leg is weak now
2. did it just degrade, or has it been unstable for a while

## Safe use of stale pending purge

The purge action is intentionally narrow.

It only deletes tasks where:

- `status = pending`
- `updated_at` is older than the chosen threshold

Thresholds:

- 15 min
- 30 min
- 60 min

The result flash reports:

- deleted count
- skipped count
- oldest affected timestamp, when available

## When not to purge

Do not use stale pending purge when:

- the queue is mostly `running`
- workers are actively draining tasks
- a downstream dependency is flaky and you still want retries to resume naturally

Never use it as a substitute for understanding why tasks are failing.

## How tenant-scoped reputation should be interpreted

Tenant diagnostics now include a `Leg Reputation` section inside the existing client diagnostics surface.

Interpret it calmly:

- it is reference-only
- runtime already adapts automatically
- action is usually unnecessary unless the line itself is actually failing

Tenants only see reputation tied to their own forwards.

## Runtime snapshot locations

Initial rollout paths:

- client forward snapshot: `/run/leikwan/bond-leg-health-forward-<forward_id>.json`
- sidecar snapshot: `<sidecar state dir>/bond-leg-health.json`

Examples:

- `/run/leikwan/bond-leg-health-forward-26.json`
- `/var/lib/leikwan-sidecar-4/bond-leg-health.json`

## Verification checklist after deployment

Run these checks on the relevant hosts:

```bash
cat /run/leikwan/bond-leg-health-forward-26.json | jq .
cat /var/lib/leikwan-sidecar-4/bond-leg-health.json | jq .
```

Then confirm:

- client and sidecar snapshots exist and refresh over time
- client heartbeat payloads include `bond_leg_reputation`
- sidecar heartbeats include `bond_leg_reputation`
- `/admin` shows `Task Queue Health`, `Recent Failed Tasks`, and `Worst Legs Right Now`
- `/admin/diagnostics` shows `Bond Leg Reputation`
- tenant diagnostics show scoped `Leg Reputation` only

## Operational reading pattern

Use this order:

1. `/admin` for quick triage
2. `/admin/diagnostics` for detailed reputation history
3. local snapshot files on the affected client or sidecar when you need runtime truth

Runtime is still the source of truth.
