# Ultra real failover drill

`.github/workflows/ultra-real-failover.yml` is a manually triggered,
production-gated drill. It stops one real sidecar, waits past the configured
pool TTL, asks the deployed panel `ExternalUoTPool` to assign the same client /
forward tuple, probes the selected standby's real UoT + DNS path, and restores
the sidecar in an `always()` step.

## Required Actions configuration

Create a protected `production-ha` environment with reviewers, then add these
repository/environment secrets:

- `ULTRA_HA_PANEL_SSH_HOST`
- `ULTRA_HA_PANEL_SSH_USER`
- `ULTRA_HA_PANEL_SSH_PASSWORD`
- `ULTRA_HA_NODES_JSON`

`ULTRA_HA_NODES_JSON` is an array. Include every pool target that may be
selected, not just the node being faulted:

```json
[
  {
    "id": "cn-primary",
    "host": "70.36.96.183",
    "port": "22",
    "user": "root",
    "password": "[secret]",
    "target": "70.36.96.183:7443",
    "origin": "70.36.96.183:7443"
  }
]
```

Do not commit this JSON or place it in workflow YAML. The runner passes the
values through environment memory and never prints them.

The selected `test_origin` and `forward_id` must be a real production
`external_uot` origin/forward pair. Leave `forward_id` as `0` only when the
first binding on the selected sidecar is known to belong to the requested
origin.

The drill intentionally refuses to start unless the dispatch confirmation is
`true` and the protected environment approval succeeds.
