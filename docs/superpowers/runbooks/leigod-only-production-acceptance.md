# LeiGod-Only Production Acceptance Requirements

This runbook is a checklist stub only. It is not production authorization.

1. Separate explicit production authorization exists.
2. Source/build/package bytes, versions metadata, route-served bytes, static mirror state, BaseController metadata, expected pins, and capability-reported hashes agree.
3. Cohort 0 dry-run has no blockers and no writes except explicit immutable plan recording when `--record` is supplied.
4. Cohort 1 proves standalone-bond `single_leg_only` or explicit best-Slave degradation without forbidden fallback markers.
5. Cohort 2 proves upload and download useful-byte windows independently with 2..8 distinct Slave nodes.
6. Degradation preserves the same application TCP socket, same standalone bond transport session, same sidecar target TCP connection, and same bond protocol family.
7. Rollback preview validates immutable desired-state and artifact snapshots before activation apply.
8. Cleanup-capable client, slave, and sidecar roles report parsed authenticated retirement contract readiness before legacy retirement activation.
9. No cleanup lease is issued for missing desired state, missing runtime_retirements, empty proof, ambiguous proof, formula-derived ports, broad prefixes, generic names, unknown listeners, or active keep-set conflicts.
10. No rollout step runs without matching plan id/hash, batch id/hash, held rollout lock, owner token, fencing token, explicit scope, owner approval checkpoint, and rollback snapshot.
