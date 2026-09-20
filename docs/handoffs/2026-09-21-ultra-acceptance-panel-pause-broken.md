# Ultra acceptance: the deployed panel still has the broken pause API (2026-09-21)

## The pause fix is verified necessary by live reproduction

The deployed panel does **not** carry the `setForwardEnabled` fix from this
session:

```
/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Support/ProvisioningService.php
sha256 68cb9b760df42cdd16a5d8ab36c4d8becc2a4f65f815aebe0e0ca5b3b1713dcb
size   622028
contains "'state' => 'paused" early return : false
contains restart_relevant_content          : false
```

Its pause branch still ends with `return $this->buildClientConfig($forwardId)`,
whose query requires `f.enabled = 1 AND s.status = active`, immediately after
setting `enabled = 0`. That always throws, so the transaction rolls back and the
pause never persists.

**Reproduced live.** Disabling the test forwards through the real API failed for
every one of them:

```
ERR 203 forward_not_found
ERR 204 forward_not_found
ERR 205 forward_not_found
ERR 206 forward_not_found
ERR 207 forward_not_found
ERR 208 forward_not_found
ERR 209 forward_not_found
ERR 210 forward_not_found
```

That is the defect fixed in `e5ae6de6`, observed on production. It also explains
the handoff note that the previous round could only stop forwards 198/199 by
"strictly checking ownership and disabling transactionally": the API path was
never usable.

## Workaround used here

The forwards were disabled transactionally with ownership checks, matching what
the handoff records, with no API call:

```sql
UPDATE forwards SET enabled=0, native_direction_key=NULL,
  balance_suspended_at=NULL, api_revision=api_revision+1,
  updated_at=UTC_TIMESTAMP() WHERE id=:id AND enabled=1
```

Each row was first read `FOR UPDATE` and checked for `service_id=30`,
`line_client_id=3` and `backend_mode=ultra`; a mismatch would have thrown and
rolled the whole transaction back.

## Lease quota was the reason the extra forwards kept being rejected

`ultra_lease_refusals` records the real cause for every test forward:

```
error_code = lease_device_live_limit
client_id  = 3
forward_id = 201 .. 210
```

Ten test forwards plus the production forwards exceeded the per-device live lease
limit, so the panel refused leases for whichever forwards lost the race on each
refresh. A refused forward with no prior definition is stopped and its files
deleted, which is what produced the alternating "this one works, that one drops"
behaviour the operator originally reported.

This is an operating condition of the test setup, not a product defect, and it is
now removed: only 201 (UDP) and 202 (TCP) remain enabled, and the host reports
`invalid_forward_ids = []` with both listeners up.
