# LeiGod-Only Activation/Rollout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the guarded Task 40 activation/rollout layer for the locked LeiGod-only standalone-bond contract, as a plan document only.

**Architecture:** Task 40 sits above Tasks 35-39 and converts already-accepted read-only telemetry/capability evidence into fail-closed activation planning, canary cohort evaluation, normalization activation, legacy retirement activation, and bounded rollback. It adds shared LeiGod-only contract validators, standalone-bond leg policy, artifact byte/provenance consistency gates, immutable activation plans/batches/snapshots, scoped rollout locks with fencing tokens, read-only operations surfaces, and explicit rollback/retirement guards. Every mutating path requires an unchanged plan hash, held rollout lock, current fencing token, validated artifact snapshot, validated locked runtime contract, and affected-Forward allowlist.

**Write-fenced mutation contract:** every activation, normalization, retirement, rollback, audit, settings, desired-state, artifact-pointer, and publish-queue write must carry `scope`, confirmed plan or batch hash, `owner_token`, `fencing_token`, affected-Forward allowlist, and `artifact_snapshot_hash` when artifact state is relevant. SQL mutators must persist these values on inserts, include them in `UPDATE`/`DELETE` predicates, verify exact affected-row counts, reject missing predicates, reject stale fences, reject cross-Forward writes, and fail closed when the database adapter cannot report row counts. A pre-write validation call is not sufficient; the fence must be on the write itself.

**Tech Stack:** PHP 8 control plane/support services/controllers/standalone tests, MySQL-compatible SQL migrations/schema mirrors, fake-DB/fake-probe PHP behavior tests, Python 3 client/slave/sidecar proof-guard tests, JSON artifact manifest/provenance validation, local `ArtifactController` route probes, `BaseController` metadata probes, existing admin HTML helpers, and local-only CLI/admin planning surfaces.

---

## Hard Scope and Non-Authorization Boundary

Task 40 is implementation planning only. It does not authorize product implementation in this session, production deployment, artifact publication, static artifact synchronization, remote host operations, cleanup execution, runtime resource deletion, purges, commits, pushes, or live rollout.

No task step, CLI command, Admin POST, lock row, plan row, batch row, campaign row, or canary status row grants separate authorization for outward-facing or hard-to-reverse action. Any future executable surface that could deploy, publish artifacts, synchronize static mirrors, issue cleanup leases, execute cleanup, operate remote hosts, commit, push, or open/merge a PR must require an explicit external authorization reference and must fail closed when that reference is absent. Task 40 itself should not implement such outward actions; it should model blockers and guardrails around them.

The task steps include local tests, `git add`, and `git commit` commands only as future execution instructions for a separately user-authorized implementation session. They are not authorization to implement, stage, commit, push, deploy, publish artifacts, operate production hosts, or activate cleanup during this planning-only session. If implementation is authorized but commit/push is not separately authorized, stop before those steps and report the uncommitted verification state.

No production deployment is authorized for this changeset.

Do not read, hash, inspect, stage, modify, delete, execute, format, or otherwise touch this unrelated protected file:

```text
docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md
```

Future implementation guards may compare that path's Git pathspec-only status against a saved baseline, but must not open or hash its contents.

## Locked Runtime Contract

The only active runtime contract permitted by Task 40 is:

```text
backend_mode = leigod_mode
client_mode = bond
runtime_preference = single
bond_ingress_mode = plain_tcp_mux
active_family = standalone_bond
```

`runtime_preference = single` means the Linux client directly runs standalone `leikwan-bond`; it does not mean one Slave and does not authorize native MPTCP.

The following are forbidden active or fallback markers in planner input, desired state, snapshots, cohorts, activation batches, rollback payloads, Admin/CLI mutators, rendered supervisor configs, publish queues, telemetry used for promotion, and cleanup activation context:

```text
current_mode
cf_mode
native_mptcp
leikwan-mptcp-relay
platform_default
mptcp_preferred_bond_fallback
bond_fallback
cloudflare
wss
classic_sidecar
classic sidecar
```

Native MPTCP may remain diagnostic-only. It must never select, prefer, publish, promote, repair, or roll back Speed Plus.

## Standalone Bond Leg Policy

Stable logical leg key:

```text
bond:<forward_id>:tcp:<node_id>:<endpoint_id>
```

Shared policy outcomes:

```text
0 usable Slaves                    -> invalid_speed_plus_leg_set / no_usable_legs
1 usable Slave                     -> single_leg_only
2..8 usable Slaves, distinct nodes -> speed_plus_eligible
2+ endpoints but one Slave node    -> speed_plus_requires_distinct_slave_nodes
9+ usable Slaves                   -> too_many_speed_plus_legs
duplicate or malformed leg key     -> invalid_speed_plus_leg_set
```

Best-Slave degradation must remain inside the same application TCP socket, the same standalone bond transport session, the same sidecar target TCP connection, and the same bond protocol family.

## Required Shared Services

Task 40 must create and use these shared services everywhere instead of ad-hoc string checks:

```php
LeigodOnlyRuntimeContract::validate(array $row, string $context): array
LeigodOnlyRuntimeContract::assertActiveContract(array $row, string $context): void
StandaloneBondLegPolicy::classify(int $forwardId, int $desiredGeneration, array $legRows): array
StandaloneBondLegPolicy::canonicalLegSetHash(int $forwardId, int $desiredGeneration, array $legRows): string
LeigodOnlyPlatformFamilyGate::assertSupported(string $platformFamily, string $context): void
LeigodOnlyArtifactConsistencyService::validateForActivation(array $context): array
LeigodOnlyActivationAssignmentService::loadCurrent(int $forwardId, int $clientId, int $clientDeviceSessionId): array
LeigodOnlyActivationAssignmentService::assertMatchesContext(array $assignment, LeigodActivationGateContext $context): void
LeigodStandaloneBondActivationGateService::gate(LeigodActivationGateContext $context): array
LeigodOnlyRolloutLockService::requireHeld(string $scope, string $ownerToken, string $fencingToken): array
LeigodOnlyAuthorizationGate::assertNoOutwardAction(array $context): void
LeigodOnlyAuthorizationGate::assertSeparateAuthorization(string $action, array $context): void
```

Every planner, plan store, artifact snapshot, activation gate, canary status/promotion, normalization activation, retirement activation, rollback preview/apply, Admin POST, and CLI mutator must call the shared validator(s) before returning `ok=true` or performing a write. `LeigodOnlyPlatformFamilyGate` permits only `linux-bond` and `windows-bond` for Task 40 activation; `android-bond` returns `unsupported_platform_family:android-bond` before artifact probes, metadata probes, locks, writes, or publish queue work. Native-MPTCP strings, capability rows, route probes, manifest keys, and diagnostics remain negative evidence only and cannot satisfy any activation bundle.

## Dependency Ordering

Task 40 activation may enforce changes only after these previous plans are implemented and locally verified:

1. Task 35 control plane rejects/hides deprecated modes and publishes only the locked contract.
2. Task 36 retirement cleanup creates Forward-scoped ownership-proof leases/results and active keep-set subtraction.
3. Task 37 standalone `leikwan-bond` supports one to eight explicit Slave legs and best-survivor degradation.
4. Task 38 Linux supervisor runs per-Forward standalone-bond units with local port leases and generation fencing.
5. Task 39 telemetry/capability gates accept and reduce client-device standalone-bond snapshots and capability reports as read-only evidence.

Task 39 remains read-only until this Task 40 gate explicitly activates an allowlisted cohort.

## Artifact Consistency Contract

Artifact consistency is a hard gate. `validateForActivation()` must fail closed unless all required axes agree:

| Axis | Required comparison |
| --- | --- |
| package/repo bytes | `artifacts/versions.json[*].path` bytes hash/size equal manifest `sha256`/`size_bytes` |
| source bytes | configured source file/tree digest equals the build provenance source digest for the artifact key |
| generated build output | build provenance output hash/size equals the package/repo artifact bytes and manifest metadata |
| manifest route | local `/artifacts/versions.json` response status/body decodes and recomputed per-entry hashes/sizes match local manifest path bytes |
| artifact route | local `ArtifactController`/router response for each resolved activation route (`artifact_url`, installer-style `install_url`, concrete wstunnel artifact URL from the manifest filename, or route-only helper route) returns status 200 and body hash/size equal package/repo artifact bytes |
| public static shadow | `master/public/artifacts/...` may be absent; if present, bytes must exactly match package/repo artifact bytes |
| BaseController metadata | `BaseController::artifactMetadataForActivation()` and pull/update-helper desired metadata must match manifest route and package bytes |
| expected rollout pins | campaign/batch expected pins must exist for required activation keys and equal package/repo bytes |
| capability-reported hashes | fresh accepted role capability rows must be assignment-scoped and must report expected and actual artifact hashes that equal expected pins/package bytes for the role/platform being activated |

No synthetic-only helper may satisfy the real activation gate. Unit tests may use fake route/metadata probes, but those fakes must drive `validateForActivation()` itself; the production service must fail closed if the real route or metadata probe is unavailable while activation mode is requested.

`LeigodOnlyArtifactConsistencyService::validateForActivation()` must first call `LeigodOnlyPlatformFamilyGate` on the durable assignment platform family. `android-bond`, native-MPTCP platform families, diagnostic MPTCP bundles, and unknown platform families fail before manifest parsing, artifact route probes, metadata probes, lock checks, writes, or publish queue work. The service must derive required artifact keys by role and platform from the durable current assignment, not from caller-provided arbitrary lists. Capability reports are scoped by `(role, forward_id, client_id where applicable, client_device_session_id where applicable, assignment_id, assignment_hash, desired_generation, desired_leg_set_hash, active_family, platform_family)`. Reject unknown, missing, stale, wrong-role, wrong-platform, wrong-session, wrong-assignment, wrong-generation, wrong-family, expected-only, reported-only, or hash-mismatched rows with `capability_gate_failed:<role>:<artifact_key>:<reason>`. Required role bundles:

| Role bundle | Required keys/probes | Notes |
| --- | --- | --- |
| `client:linux-bond` | `client_agent`, `client_agent_installer`, `install_forward_runtime`, `leikwan_bond_linux_amd64`, route-only helper `client_agent_update` | Linux client activation also requires standalone-bond binary hash agreement. |
| `client:windows-bond` | `windows_client_app`, `leikwan_bond_windows_amd64` | Windows activation must not be satisfied by Linux bond hashes. |
| `slave` | `slave_agent`, `slave_installer`, `gost_linux_amd64`, `leigod_linux_amd64`, `lguot_linux_amd64`, route-only helper `slave_agent_update` when that route is registered for the target role | Cleanup-capability rows for slave remain diagnostics until retirement activation also passes the replacement gate. |
| `sidecar` | `sidecar_agent`, `bond_sidecar_installer`, `bond_sidecar_uninstaller`, route-only helper `sidecar_agent_update` | Sidecar capability cannot prove client useful-byte aggregation. |

Task 40 activates only platform families with a complete role/platform artifact bundle, source/build provenance mapping, manifest route mapping, and Task 39 capability scope. Until a separate Android bundle is specified, `android-bond` is read-only/unsupported for activation and canary promotion; Admin/CLI requests for it must return `unsupported_platform_family:android-bond` before artifact probes, locks, or writes.

Source/build mappings are explicit and required for every activation-critical manifest key: the union of all role/platform-required artifact bundles, not every diagnostic key in `artifacts/versions.json`. Route-only update helpers are activation pseudo-keys, not `artifacts/versions.json` keys; their expected bytes and metadata must be derived from the registered local route plus its known source path. Initial mappings:

| Key | Source inputs | Generated/package output | Route |
| --- | --- | --- | --- |
| `client_agent` | `client-agent/client_agent.py` | `client-agent/client_agent.py` | `/artifacts/client-agent.py` |
| `slave_agent` | `slave-agent/slave_agent.py` | `slave-agent/slave_agent.py` | `/artifacts/slave-agent.py` |
| `sidecar_agent` | `sidecar-agent/sidecar_agent.py` | `sidecar-agent/sidecar_agent.py` | `/artifacts/sidecar-agent.py` |
| `gost_linux_amd64` | external upstream release/source provenance supplied by rollout context plus `artifacts/gost/gost_linux_amd64` package bytes | `artifacts/gost/gost_linux_amd64` | `/artifacts/gost/gost_linux_amd64` |
| `leigod_linux_amd64` | external LeiGod binary provenance supplied by rollout context plus `artifacts/leigod/leigod-linux-amd64` package bytes | `artifacts/leigod/leigod-linux-amd64` | `/artifacts/leigod/leigod-linux-amd64` |
| `lguot_linux_amd64` | `tools/lguot/**` when source is present, otherwise external release/source provenance supplied by rollout context plus `artifacts/lguot/lguot_linux_amd64` package bytes | `artifacts/lguot/lguot_linux_amd64` | `/artifacts/lguot/lguot_linux_amd64` |
| `client_agent_installer` | `install/install-client-agent.sh` | `install/install-client-agent.sh` | `/artifacts/install-client-agent.sh` |
| `slave_installer` | `install/install-slave.sh` | `install/install-slave.sh` | `/artifacts/install-slave.sh` |
| `bond_sidecar_installer` | `install/install-bond-sidecar.sh` | `install/install-bond-sidecar.sh` | `/artifacts/install-bond-sidecar.sh` |
| `bond_sidecar_uninstaller` | `install/uninstall-bond-sidecar.sh` | `install/uninstall-bond-sidecar.sh` | `/artifacts/uninstall-bond-sidecar.sh` |
| `install_forward_runtime` | `client-agent/install_forward_runtime.py` | `client-agent/install_forward_runtime.py` | `/artifacts/install-forward-runtime.py` |
| route-only helper `client_agent_update` | `install/client-agent-update.sh` | derived helper bytes, not a `versions.json` entry | `/artifacts/client-agent-update.sh` |
| route-only helper `sidecar_agent_update` | `install/sidecar-agent-update.sh` | derived helper bytes, not a `versions.json` entry | `/artifacts/sidecar-agent-update.sh` |
| route-only helper `slave_agent_update` | `install/leikwan-slave-update` when the route is registered for the target role | derived helper bytes, not a `versions.json` entry | `/artifacts/slave-agent-update.sh` when registered |
| `leikwan_bond_linux_amd64` | `wstunnel-main/leikwan-bond/**` plus Cargo lock/build config | `artifacts/wstunnel/leikwan-bond_linux_amd64` | `/artifacts/wstunnel/leikwan-bond_linux_amd64` |
| `leikwan_bond_windows_amd64` | `wstunnel-main/leikwan-bond/**` plus Cargo lock/build config | `artifacts/wstunnel/leikwan-bond_windows_amd64.exe` | `/artifacts/wstunnel/leikwan-bond_windows_amd64.exe` |
| `windows_client_app` | `LeikwanBridge.Desktop/**` plus Windows packaging project files | `artifacts/windows/leikwan-bridge-setup.exe` | `/artifacts/leikwan-bridge-setup.exe` |

If a manifest entry lacks a concrete source/build mapping, activation returns `source_build_mapping_missing:<key>` and canary promotion is false. If a route-only helper lacks a registered route, expected source path, or derived metadata path, activation returns `route_helper_metadata_missing:<helper>` and canary promotion is false.

## Task 39 Evidence Wall

Activation may use only accepted Task 39 evidence:

```text
forward_aggregation_runtime_snapshots
forward_aggregation_runtime_window_proofs
forward_aggregation_runtime_snapshot_rejections
agent_capability_reports
BondRuntimeEligibilityService
```

The gate must require:

- authenticated client-device telemetry session binding;
- durable current assignment loaded by `LeigodOnlyActivationAssignmentService::loadCurrent()` for `(forward_id, client_id, client_device_session_id)`;
- no trust in caller-supplied `runtime_row`, `leg_rows`, generation, or stored hash until `assertMatchesContext()` verifies them against the loaded assignment;
- computed canonical desired-leg-set hash from the actual sorted stable logical leg keys in the loaded assignment;
- assignment generation match;
- `active_family=standalone_bond`;
- accepted lifecycle snapshot row for the same forward/client/device session/generation/computed leg-set hash;
- lifecycle snapshot `row_expires_at > UTC_TIMESTAMP(3)` and observed freshness strictly under 15 seconds;
- monotonic runtime epoch/sequence already accepted by Task 39, and recent non-monotonic rejection rows as halt blockers;
- bounded payload already accepted by Task 39, and recent oversized-payload rejection rows as halt blockers;
- fresh accepted client capability row scoped to forward/client/device session/generation/computed leg-set hash;
- expected/reported artifact hash agreement against the artifact gate pins;
- separate upload and download proof rows for Speed Plus promotion.

Node/sidecar capability rows and `BondLegReputationService` rows are diagnostics. They never count useful bytes for Speed Plus proof.

Proof requirements branch by `StandaloneBondLegPolicy` state:

- `invalid_speed_plus_leg_set`, zero legs, malformed/duplicate keys, 9+ legs, same-node-only sets: fail closed.
- `single_leg_only`: require locked runtime contract, fresh lifecycle or explicit `degraded_single_leg` standalone-bond evidence, fresh client capability, artifact hash agreement, no zero-leg/fallback/rejection blockers; do not require upload/download Speed Plus proof rows.
- `speed_plus_eligible`: require all `single_leg_only` requirements plus independent accepted upload and download proof rows meeting Task 39 useful-byte thresholds.

Download cannot satisfy upload. Upload cannot satisfy download.

Recent rejection halt rows must be queried with the same scope as accepted evidence. Required query shape:

```sql
SELECT rejection_code, desired_generation, desired_leg_set_hash, active_family, observed_at, row_expires_at
FROM forward_aggregation_runtime_snapshot_rejections
WHERE forward_id = :forward_id
  AND client_id = :client_id
  AND client_device_session_id = :client_device_session_id
  AND desired_generation = :desired_generation
  AND desired_leg_set_hash = :computed_desired_leg_set_hash
  AND active_family = 'standalone_bond'
  AND row_expires_at > UTC_TIMESTAMP(3)
  AND TIMESTAMPDIFF(MICROSECOND, observed_at, UTC_TIMESTAMP(3)) < 15000000
  AND rejection_code IN (
      'assignment_mismatch',
      'desired_generation_mismatch',
      'desired_leg_set_hash_mismatch',
      'unsupported_family',
      'forbidden_runtime_marker',
      'fallback_marker_present',
      'non_monotonic_runtime_sequence',
      'payload_too_large',
      'invalid_sample_age',
      'invalid_runtime_sequence',
      'invalid_useful_byte_window'
  )
ORDER BY observed_at DESC
LIMIT 20
```

Wrong-scope, stale, out-of-window, superseded-generation, wrong-family, and wrong-leg-set rejection rows must be ignored by the halt query and covered by regression tests. In-scope rows with the blocker-code allowlist above halt activation and canary promotion.

## Canary Cohorts

| Cohort | Scope | Entry criteria | Promotion criteria | Rollback trigger |
| --- | --- | --- | --- | --- |
| 0 | Read-only dry run | artifact gate passes; planner writes nothing except explicit immutable `--record` plan rows | no blockers, no mutator calls, no forbidden fallback markers | any dry-run write/mutator call, artifact drift, protected pathspec status drift |
| 1 | one internal/test Forward with exactly one usable Slave | leg policy `single_leg_only`; locked runtime; artifact/capability hash agreement | fresh lifecycle or degraded-single-leg standalone-bond evidence; no rejection blockers | zero legs, unsupported family, stale snapshot, hash/generation mismatch, fallback marker |
| 2 | one internal/test Forward with 2..8 usable Slaves on distinct nodes | leg policy `speed_plus_eligible`; locked runtime; artifact/capability hash agreement | fresh upload and download useful-byte proof rows for the same computed leg-set hash | either direction missing/stale/rejected/below threshold; same-node-only proof; rejection spike |
| 3 | limited real tenant/client/device allowlist | Cohort 2 gates plus owner approval checkpoint and rollback snapshot | observation window complete, no blockers, unchanged plan hash, held lock/fence | any halt rule or rollback snapshot validation failure |
| 4 | broader allowlist | Cohort 3 success and separate explicit approval | configured rollout window complete with no blockers | any high-severity blocker, artifact shadow drift, cleanup capability parse failure |

Every campaign row stores entry criteria, maximum sample size, observation window, promotion criteria, rollback triggers, owner approval state/checkpoint, campaign hash, predecessor campaign id/hash, predecessor success snapshot hash, artifact version pins, generation fences, forward/client/device allowlists, usable leg count, distinct node count, leg policy state, upload gate state, download gate state, and blockers.

### Cohort Predecessor Gate

For every cohort `N > 0`, `LeigodOnlyCanaryCampaignService` must load the immutable predecessor cohort before promotion. Promotion is false unless the predecessor is `succeeded`, its campaign hash and success snapshot hash match the current row's predecessor fields, its artifact pins are unchanged, its generation fences are still valid, its runtime contract and forbidden-marker validation still pass, its observation window completed, its rollback snapshot checks passed where applicable, and its scope/allowlists are a permitted subset for the next cohort. Tests must cover predecessor missing, wrong hash, not successful, still observing, blocker present, artifact pin drift, fence drift, leg-policy drift, and scope drift.

## Rollback Contract

Rollback must:

1. verify held rollout lock `(scope, owner_token, fencing_token)` before any write;
2. validate immutable activation batch hash;
3. recompute and validate immutable previous desired-state snapshot hash;
4. recompute and validate immutable artifact pointer snapshot hash;
5. validate all restored desired rows against the locked runtime contract;
6. enter a fail-closed rollback freeze in one short transaction by superseding current activation settings/allowlists to disabled rows through `LeigodOnlyActivationSettingsService`, with write-fenced predicates and exact row counts;
7. commit the freeze before restoring desired state, so any later rollback failure leaves activation disabled for the scoped cohort instead of re-enabling rollout;
8. begin a second short restore transaction;
9. restore only affected Forward desired rows through `LeigodOnlyDesiredStateFenceService`, restore artifact/version/pointer metadata references through `LeigodOnlyArtifactPointerService`, persist a new desired generation/fencing token, enqueue bounded per-Forward publish work through `LeigodOnlyFencedPublishQueue`, update batch state, and write the audit event inside the same restore transaction;
10. commit the restore transaction only if exact affected-row counts and exact publish-enqueue counts match the immutable batch snapshot;
11. roll back only the restore transaction on any injected failure, row-count mismatch, enqueue mismatch, stale fence, or artifact snapshot drift, while preserving the already-committed fail-closed freeze and marking the batch `rollback_failed_frozen`;
12. make `LeigodOnlyFencedPublishExecutor` re-check the rollback fence against the current desired-state fence row before reconciling endpoints.

Rollback must not restore or re-enable `current_mode`, `cf_mode`, Cloudflare, WSS, classic sidecar, native MPTCP, `bond_fallback`, `mptcp_preferred_bond_fallback`, or any non-standalone-bond path. Generic `reconcileForwardEndpoints($forwardId)` is not proof of rollback correctness; publish work must carry and verify the rollback fence.

## Legacy Retirement and Cleanup Activation Boundary

Legacy retirement activation targets deprecated `current_mode`/`cf_mode` rows only after the active LeiGod replacement has independently passed the same locked-runtime, artifact, capability, assignment, lifecycle freshness, and fence gates used for activation. Desired locked state is not proof that replacement traffic is live. Before any retirement record, cleanup lease, or remote-adapter call, the service must verify replacement lifecycle evidence for the replacement Forward/client/device assignment, matching assignment hash, desired generation, computed leg-set hash, `active_family=standalone_bond`, under-15-second freshness, current publish fence/generation, artifact/capability hash agreement, and validated active keep-set membership. It may call Task 36 `ForwardRetirementService` only through a fenced activation-context API containing unchanged plan hash, activation batch id/hash, held rollout lock, owner/fencing token, immutable artifact snapshot, affected allowlist, replacement activation gate result, and parsed cleanup capability evidence.

Cleanup capability evidence must be parsed JSON, fresh, assignment-scoped, and role-specific:

```text
role
forward_id
assignment_id or desired_generation
desired_leg_set_hash where applicable
client_id and client_device_session_id for client role
minimum agent/protocol version
runtime_retirements support
cleanup/retirement support
authenticated lease schema/version
authenticated result schema/version
bounded result limits
active_keep_set schema/version
ownership_proof_fields schema/version
reported artifact hashes
row_expires_at
```

Missing desired state, missing runtime_retirements section, missing applied state, empty proof, ambiguous proof, formula-derived ports, broad prefixes, generic systemd/WireGuard names, unknown listeners, stale applied state, wrong forward/generation/session/assignment/role, unsupported version, malformed payload, oversized result, or active keep-set conflict is not deletion authorization. Remote cleanup lease issuance/result handling must occur after the master DB transaction commits and must assert no active master DB transaction.

## File Map

**Create:**

- `master/migrations/20260719_add_leigod_only_activation_rollout.sql`
- `master/src/Support/LeigodOnlyRuntimeContract.php`
- `master/src/Support/StandaloneBondLegPolicy.php`
- `master/src/Support/LeigodOnlyPlatformFamilyGate.php`
- `master/src/Support/LeigodOnlyAuthorizationGate.php`
- `master/src/Support/LeigodOnlyArtifactSourceBuildMap.php`
- `master/src/Support/LeigodOnlyArtifactRouteProbe.php`
- `master/src/Support/LeigodOnlyArtifactMetadataProbe.php`
- `master/src/Support/LeigodOnlyArtifactConsistencyService.php`
- `master/src/Support/LeigodOnlyActivationAssignmentService.php`
- `master/src/Support/LeigodOnlyActivationPlan.php`
- `master/src/Support/LeigodOnlyActivationPlanner.php`
- `master/src/Support/LeigodOnlyActivationPlanStore.php`
- `master/src/Support/LeigodOnlyRolloutLockService.php`
- `master/src/Support/LeigodOnlyActivationSettingsService.php`
- `master/src/Support/LeigodOnlyDesiredStateFenceService.php`
- `master/src/Support/LeigodOnlyArtifactPointerService.php`
- `master/src/Support/LeigodOnlyFencedPublishQueue.php`
- `master/src/Support/LeigodOnlyFencedPublishExecutor.php`
- `master/src/Support/LeigodActivationGateContext.php`
- `master/src/Support/LeigodStandaloneBondActivationGateService.php`
- `master/src/Support/LeigodOnlyCanaryCampaignService.php`
- `master/src/Support/LeigodNormalizationActivationContext.php`
- `master/src/Support/LeigodRetirementActivationContext.php`
- `master/src/Support/LeigodOnlyNormalizationActivationService.php`
- `master/src/Support/LeigodOnlyLegacyRetirementActivationService.php`
- `master/src/Support/LeigodOnlyRollbackService.php`
- `master/cli/leigod-only-activation.php`
- `docs/superpowers/runbooks/leigod-only-production-acceptance.md`
- tests listed inside each task below

**Modify:**

- `master/migrations/schema.sql`
- `master/src/Bootstrap.php`
- `master/src/Controllers/AdminController.php`
- `master/src/Controllers/BaseController.php` — add a narrow `artifactMetadataForActivation(array $keys): array` wrapper around existing artifact metadata logic.
- existing artifact regression tests named in Task 2

`ArtifactController.php` is intentionally probe-only for Task 40: do not modify it to make activation pass. Route probes must dispatch the existing controller/router and fail closed on route problems with `artifact_controller_route_staging_blocker:<artifact_key>:<artifact_url>`, `artifact_controller_route_missing:<key>`, `artifact_controller_route_status_not_200:<key>`, `artifact_controller_route_bytes_mismatch:<key>`, or `artifact_controller_versions_json_mismatch`.

**Do not modify in Task 40 implementation:**

- `docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md`
- `wstunnel-main/leikwan-bond/**` data-plane scheduler internals
- LeiGod persisted credentials
- production nginx files through remote operations
- production host services, systemd units, WireGuard links, ports, files, processes, or runtime state
- `artifacts/**` or `master/public/artifacts/**` unless a separate explicit artifact-publication authorization is given

---

### Task 1: Add Activation Schema, Runtime Contract, and Leg Policy

**Files:**
- Create: `master/migrations/20260719_add_leigod_only_activation_rollout.sql`
- Modify: `master/migrations/schema.sql`
- Create: `master/src/Support/LeigodOnlyRuntimeContract.php`
- Create: `master/src/Support/StandaloneBondLegPolicy.php`
- Create: `master/src/Support/LeigodOnlyPlatformFamilyGate.php`
- Create: `master/src/Support/LeigodOnlyAuthorizationGate.php`
- Create: `master/tests/leigod_only_activation_schema_test.php`
- Create: `master/tests/leigod_only_runtime_contract_policy_test.php`
- Create: `master/tests/standalone_bond_leg_policy_test.php`
- Create: `master/tests/leigod_only_platform_authorization_gate_test.php`

- [ ] **Step 1: Record protected pathspec status and base commit**

Run:

```bash
python - <<'PY'
import json
import subprocess
from pathlib import Path
protected = 'docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md'
status = subprocess.check_output(['git', 'status', '--short', '--', protected], text=True).strip()
base = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
path = Path(subprocess.check_output(['git', 'rev-parse', '--git-path', 'leigod-only-activation-rollout-baseline.json'], text=True).strip())
path.parent.mkdir(parents=True, exist_ok=True)
path.write_text(json.dumps({'protected_path': protected, 'protected_status': status, 'base_commit': base}, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(json.dumps({'baseline_path': str(path), 'protected_status': status, 'base_commit': base}, ensure_ascii=False))
PY
```

Expected: prints baseline JSON. This command must not open or hash the protected file.

- [ ] **Step 2: Write failing schema and policy behavior tests**

`leigod_only_activation_schema_test.php` must assert all new tables and immutable hash columns exist in both migration and schema mirror:

```text
leigod_only_activation_settings
leigod_only_activation_plans
leigod_only_activation_batches
leigod_only_activation_assignments
leigod_only_activation_assignment_current_slots
leigod_only_forward_desired_state_fences
leigod_only_artifact_pointer_snapshots
leigod_only_artifact_pointer_states
leigod_only_fenced_publish_jobs
leigod_only_rollout_locks
leigod_only_canary_campaigns
leigod_only_canary_forward_status
leigod_only_activation_audit_events
leigod_only_retirement_activation_records
previous_desired_state_hash CHAR(64)
artifact_snapshot_hash CHAR(64)
plan_hash CHAR(64)
batch_hash CHAR(64)
owner_token CHAR(64)
fencing_token CHAR(64)
affected_forward_ids_json JSON
write_hash CHAR(64)
current_slot_key CHAR(64) NULL
superseded_at DATETIME(3)
current_assignment_id BIGINT UNSIGNED NOT NULL
external_authorization_ref VARCHAR(191) NULL
snapshot_immutable TINYINT(1) NOT NULL DEFAULT 1
UNIQUE INDEX uniq_leigod_rollout_lock_scope
UNIQUE INDEX uniq_leigod_activation_assignment_current_slot
UNIQUE INDEX uniq_leigod_setting_current_slot
UNIQUE INDEX uniq_leigod_desired_state_current_slot
UNIQUE INDEX uniq_leigod_artifact_pointer_current_slot
UNIQUE INDEX uniq_leigod_fenced_publish_job_hash
INDEX idx_leigod_activation_setting_fence
INDEX idx_leigod_activation_plan_fence
INDEX idx_leigod_activation_event_fence
INDEX idx_leigod_desired_state_fence
INDEX idx_leigod_publish_job_fence
INDEX idx_leigod_canary_campaign_fence
INDEX idx_leigod_canary_status_fence
```

It must also assert the migration contains no `DROP TABLE`, `TRUNCATE TABLE`, broad deletes, or legacy-mode rewrites. Add focused schema assertions that `leigod_only_activation_settings`, `leigod_only_activation_plans`, `leigod_only_activation_assignments`, `leigod_only_forward_desired_state_fences`, `leigod_only_artifact_pointer_states`, `leigod_only_fenced_publish_jobs`, `leigod_only_canary_campaigns`, `leigod_only_canary_forward_status`, `leigod_only_activation_audit_events`, and `leigod_only_retirement_activation_records` expose first-class `scope` or scoped keys, `plan_hash`, `batch_hash`, `owner_token`, `fencing_token`, `affected_forward_ids_json`, and `artifact_snapshot_hash` columns for write-fenced predicates. The assertions must prove settings, assignments, desired-state fences, and artifact pointer states use a viable current-slot model: current rows hold a non-NULL `current_slot_key`, superseded rows set `current_slot_key=NULL` before replacement insert, and unique current-slot indexes therefore block two live rows without blocking immutable history. Plan rows and canary rows are immutable/status rows, but their inserts and status transitions are still fenced writes: they must persist owner/fence tokens, affected scope/allowlists, artifact snapshot hash when relevant, external authorization reference when outward action is requested, and exact write hashes; status transitions must predicate on current row hash plus owner/fence tokens.

`leigod_only_runtime_contract_policy_test.php` must instantiate `LeigodOnlyRuntimeContract` and assert:

```text
valid locked row passes
current_mode fails
cf_mode fails
native_mptcp fails
platform_default fails
mptcp_preferred_bond_fallback fails
bond_fallback fails
cloudflare fails
wss fails
classic_sidecar fails
unknown backend_mode fails
missing backend_mode fails
missing client_mode fails
wrong client_mode fails
missing runtime_preference fails
wrong runtime_preference fails
missing bond_ingress_mode fails
wrong bond_ingress_mode fails
missing active_family fails
wrong active_family fails
otherwise-valid row with extra JSON field containing bond_fallback fails
```

`standalone_bond_leg_policy_test.php` must assert exact outcomes for 0, 1, 2, 8, 9, duplicate logical key, malformed logical key, and same-node-only legs. It must also assert `canonicalLegSetHash()` is stable under input order and changes when generation or logical leg keys change.

`leigod_only_platform_authorization_gate_test.php` must assert `LeigodOnlyPlatformFamilyGate` accepts only `linux-bond` and `windows-bond`, rejects `android-bond`, `native_mptcp`, `mptcp_preferred_bond_fallback`, `bond_fallback`, unknown families, and diagnostic MPTCP bundles, and that `LeigodOnlyAuthorizationGate` rejects any deploy/publish/cleanup/remote/commit/push action when the external authorization reference is missing, stale, or mismatched while allowing planning/dry-run/status read-only paths to remain blocked-only.

- [ ] **Step 3: Run failing tests**

Run:

```bash
php master/tests/leigod_only_activation_schema_test.php
php master/tests/leigod_only_runtime_contract_policy_test.php
php master/tests/standalone_bond_leg_policy_test.php
```

Expected: fail because the files/classes do not exist yet.

- [ ] **Step 4: Implement schema**

Create the migration and mirror it into `schema.sql`. Required tables:

```sql
CREATE TABLE IF NOT EXISTS leigod_only_activation_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setting_scope VARCHAR(64) NOT NULL,
    setting_key VARCHAR(96) NOT NULL,
    setting_value_json JSON NOT NULL,
    setting_state VARCHAR(32) NOT NULL,
    setting_hash CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    current_slot_key CHAR(64) NULL,
    superseded_at DATETIME(3) NULL,
    supersedes_setting_id BIGINT UNSIGNED NULL,
    plan_hash CHAR(64) NOT NULL,
    batch_hash CHAR(64) NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    affected_forward_ids_json JSON NOT NULL,
    artifact_snapshot_hash CHAR(64) NULL,
    write_reason VARCHAR(64) NOT NULL,
    created_by VARCHAR(191) NOT NULL,
    created_at DATETIME(3) NOT NULL,
    UNIQUE INDEX uniq_leigod_activation_setting_hash (setting_hash),
    UNIQUE INDEX uniq_leigod_activation_setting_write_hash (write_hash),
    UNIQUE INDEX uniq_leigod_setting_current_slot (setting_scope, setting_key, current_slot_key),
    INDEX idx_leigod_activation_setting_current (setting_scope, setting_key, current_slot_key, superseded_at, created_at),
    INDEX idx_leigod_activation_setting_fence (setting_scope, owner_token, fencing_token)
);

CREATE TABLE IF NOT EXISTS leigod_only_activation_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    plan_hash CHAR(64) NOT NULL,
    plan_json JSON NOT NULL,
    blockers_json JSON NOT NULL,
    artifact_snapshot_hash CHAR(64) NULL,
    dry_run_only TINYINT(1) NOT NULL DEFAULT 1,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    affected_forward_ids_json JSON NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    write_hash CHAR(64) NOT NULL,
    created_by VARCHAR(191) NOT NULL,
    created_at DATETIME(3) NOT NULL,
    expires_at DATETIME(3) NOT NULL,
    UNIQUE INDEX uniq_leigod_activation_plan_hash (plan_hash),
    UNIQUE INDEX uniq_leigod_activation_plan_write_hash (write_hash),
    INDEX idx_leigod_activation_scope_expires (scope, expires_at),
    INDEX idx_leigod_activation_plan_fence (scope, owner_token, fencing_token, artifact_snapshot_hash)
);

CREATE TABLE IF NOT EXISTS leigod_only_activation_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    scope VARCHAR(64) NOT NULL,
    batch_hash CHAR(64) NOT NULL,
    plan_hash CHAR(64) NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    rollout_lock_id BIGINT UNSIGNED NOT NULL,
    affected_forward_ids_json JSON NOT NULL,
    previous_desired_state_json JSON NOT NULL,
    previous_desired_state_hash CHAR(64) NOT NULL,
    previous_artifact_snapshot_id BIGINT UNSIGNED NOT NULL,
    artifact_snapshot_hash CHAR(64) NOT NULL,
    snapshot_immutable TINYINT(1) NOT NULL DEFAULT 1,
    external_authorization_ref VARCHAR(191) NULL,
    activation_state VARCHAR(32) NOT NULL DEFAULT 'pending',
    created_by VARCHAR(191) NOT NULL,
    created_at DATETIME(3) NOT NULL,
    completed_at DATETIME(3) NULL,
    UNIQUE INDEX uniq_leigod_activation_batch_hash (batch_hash),
    UNIQUE INDEX uniq_leigod_activation_fencing_token (fencing_token),
    INDEX idx_leigod_activation_batch_scope_state (scope, activation_state),
    INDEX idx_leigod_activation_batch_snapshot (previous_artifact_snapshot_id, artifact_snapshot_hash, snapshot_immutable)
);

CREATE TABLE IF NOT EXISTS leigod_only_activation_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    client_device_session_id BIGINT UNSIGNED NOT NULL,
    desired_generation INT NOT NULL,
    desired_leg_set_hash CHAR(64) NOT NULL,
    active_family VARCHAR(32) NOT NULL,
    platform_family VARCHAR(32) NOT NULL,
    assignment_hash CHAR(64) NOT NULL,
    runtime_contract_json JSON NOT NULL,
    leg_rows_json JSON NOT NULL,
    superseded_at DATETIME(3) NULL,
    current_slot_key CHAR(64) NULL,
    supersedes_assignment_id BIGINT UNSIGNED NULL,
    assigned_at DATETIME(3) NOT NULL,
    row_expires_at DATETIME(3) NOT NULL,
    plan_hash CHAR(64) NOT NULL,
    batch_hash CHAR(64) NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    affected_forward_ids_json JSON NOT NULL,
    artifact_snapshot_hash CHAR(64) NULL,
    UNIQUE INDEX uniq_leigod_activation_assignment_hash (assignment_hash),
    UNIQUE INDEX uniq_leigod_activation_assignment_generation (forward_id, client_id, client_device_session_id, desired_generation),
    UNIQUE INDEX uniq_leigod_activation_assignment_current_slot (forward_id, client_id, client_device_session_id, current_slot_key),
    INDEX idx_leigod_activation_assignment_fresh (forward_id, client_id, client_device_session_id, current_slot_key, superseded_at, row_expires_at),
    INDEX idx_leigod_activation_assignment_fence (forward_id, owner_token, fencing_token)
);

CREATE TABLE IF NOT EXISTS leigod_only_activation_assignment_current_slots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    client_device_session_id BIGINT UNSIGNED NOT NULL,
    current_assignment_id BIGINT UNSIGNED NOT NULL,
    current_assignment_hash CHAR(64) NOT NULL,
    desired_generation INT NOT NULL,
    desired_leg_set_hash CHAR(64) NOT NULL,
    slot_hash CHAR(64) NOT NULL,
    plan_hash CHAR(64) NOT NULL,
    batch_hash CHAR(64) NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    affected_forward_ids_json JSON NOT NULL,
    artifact_snapshot_hash CHAR(64) NULL,
    updated_at DATETIME(3) NOT NULL,
    UNIQUE INDEX uniq_leigod_assignment_current_slot (forward_id, client_id, client_device_session_id),
    UNIQUE INDEX uniq_leigod_assignment_current_slot_hash (slot_hash),
    INDEX idx_leigod_assignment_current_fence (forward_id, owner_token, fencing_token)
);

CREATE TABLE IF NOT EXISTS leigod_only_forward_desired_state_fences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    desired_generation INT NOT NULL,
    desired_state_hash CHAR(64) NOT NULL,
    previous_desired_state_hash CHAR(64) NULL,
    runtime_contract_json JSON NOT NULL,
    forward_snapshot_json JSON NOT NULL,
    combination_snapshot_json JSON NULL,
    publish_fence_token CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    current_slot_key CHAR(64) NULL,
    superseded_at DATETIME(3) NULL,
    supersedes_fence_id BIGINT UNSIGNED NULL,
    plan_hash CHAR(64) NOT NULL,
    batch_hash CHAR(64) NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    affected_forward_ids_json JSON NOT NULL,
    artifact_snapshot_hash CHAR(64) NULL,
    write_reason VARCHAR(64) NOT NULL,
    created_by VARCHAR(191) NOT NULL,
    created_at DATETIME(3) NOT NULL,
    UNIQUE INDEX uniq_leigod_desired_state_hash (desired_state_hash),
    UNIQUE INDEX uniq_leigod_desired_state_write_hash (write_hash),
    UNIQUE INDEX uniq_leigod_desired_state_current_slot (forward_id, current_slot_key),
    INDEX idx_leigod_desired_state_fence (forward_id, owner_token, fencing_token, publish_fence_token),
    INDEX idx_leigod_desired_state_scope_current (scope, current_slot_key, superseded_at, created_at)
);

CREATE TABLE IF NOT EXISTS leigod_only_rollout_locks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lock_scope VARCHAR(64) NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    owner_label VARCHAR(191) NOT NULL,
    acquired_at DATETIME(3) NOT NULL,
    lease_expires_at DATETIME(3) NOT NULL,
    released_at DATETIME(3) NULL,
    UNIQUE INDEX uniq_leigod_rollout_lock_scope (lock_scope),
    UNIQUE INDEX uniq_leigod_rollout_owner_token (owner_token),
    INDEX idx_leigod_rollout_lock_expiry (lease_expires_at, released_at)
);

CREATE TABLE IF NOT EXISTS leigod_only_artifact_pointer_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    manifest_hash CHAR(64) NOT NULL,
    artifact_pointers_json JSON NOT NULL,
    route_mapping_json JSON NOT NULL,
    public_shadow_state_json JSON NOT NULL,
    source_build_provenance_json JSON NOT NULL,
    expected_pins_json JSON NOT NULL,
    snapshot_immutable TINYINT(1) NOT NULL DEFAULT 1,
    plan_hash CHAR(64) NOT NULL,
    batch_hash CHAR(64) NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    affected_forward_ids_json JSON NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    created_by VARCHAR(191) NOT NULL,
    created_at DATETIME(3) NOT NULL,
    UNIQUE INDEX uniq_leigod_artifact_snapshot_hash (snapshot_hash),
    INDEX idx_leigod_artifact_manifest_hash (manifest_hash),
    INDEX idx_leigod_artifact_snapshot_fence (scope, owner_token, fencing_token, snapshot_hash),
    INDEX idx_leigod_artifact_snapshot_immutable (snapshot_hash, snapshot_immutable)
);

CREATE TABLE IF NOT EXISTS leigod_only_artifact_pointer_states (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    platform_family VARCHAR(32) NOT NULL,
    role_name VARCHAR(32) NOT NULL,
    artifact_key VARCHAR(96) NOT NULL,
    artifact_version VARCHAR(96) NOT NULL,
    artifact_sha256 CHAR(64) NOT NULL,
    artifact_size_bytes BIGINT UNSIGNED NOT NULL,
    artifact_route VARCHAR(255) NOT NULL,
    artifact_snapshot_hash CHAR(64) NOT NULL,
    pointer_hash CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    current_slot_key CHAR(64) NULL,
    superseded_at DATETIME(3) NULL,
    supersedes_pointer_id BIGINT UNSIGNED NULL,
    plan_hash CHAR(64) NOT NULL,
    batch_hash CHAR(64) NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    affected_forward_ids_json JSON NOT NULL,
    write_reason VARCHAR(64) NOT NULL,
    created_by VARCHAR(191) NOT NULL,
    created_at DATETIME(3) NOT NULL,
    UNIQUE INDEX uniq_leigod_artifact_pointer_hash (pointer_hash),
    UNIQUE INDEX uniq_leigod_artifact_pointer_write_hash (write_hash),
    UNIQUE INDEX uniq_leigod_artifact_pointer_current_slot (scope, platform_family, role_name, artifact_key, current_slot_key),
    INDEX idx_leigod_artifact_pointer_fence (scope, owner_token, fencing_token, artifact_snapshot_hash)
);

CREATE TABLE IF NOT EXISTS leigod_only_fenced_publish_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    desired_generation INT NOT NULL,
    desired_state_fence_id BIGINT UNSIGNED NOT NULL,
    desired_state_hash CHAR(64) NOT NULL,
    publish_fence_token CHAR(64) NOT NULL,
    job_hash CHAR(64) NOT NULL,
    job_state VARCHAR(32) NOT NULL DEFAULT 'pending',
    attempt_count INT NOT NULL DEFAULT 0,
    plan_hash CHAR(64) NOT NULL,
    batch_hash CHAR(64) NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    affected_forward_ids_json JSON NOT NULL,
    artifact_snapshot_hash CHAR(64) NULL,
    enqueued_by VARCHAR(191) NOT NULL,
    enqueued_at DATETIME(3) NOT NULL,
    locked_at DATETIME(3) NULL,
    completed_at DATETIME(3) NULL,
    last_error VARCHAR(255) NULL,
    UNIQUE INDEX uniq_leigod_fenced_publish_job_hash (job_hash),
    INDEX idx_leigod_publish_job_state (job_state, enqueued_at),
    INDEX idx_leigod_publish_job_fence (forward_id, owner_token, fencing_token, publish_fence_token)
);

CREATE TABLE IF NOT EXISTS leigod_only_canary_campaigns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    cohort SMALLINT NOT NULL,
    campaign_hash CHAR(64) NOT NULL,
    platform_family VARCHAR(32) NOT NULL,
    entry_criteria_json JSON NOT NULL,
    promotion_criteria_json JSON NOT NULL,
    rollback_triggers_json JSON NOT NULL,
    predecessor_campaign_id BIGINT UNSIGNED NULL,
    predecessor_campaign_hash CHAR(64) NULL,
    predecessor_success_snapshot_hash CHAR(64) NULL,
    owner_approval_state VARCHAR(32) NOT NULL DEFAULT 'not_requested',
    approval_checkpoint VARCHAR(191) NOT NULL DEFAULT '',
    artifact_version_pins_json JSON NOT NULL,
    generation_fences_json JSON NOT NULL,
    forward_allowlist_json JSON NOT NULL,
    client_allowlist_json JSON NOT NULL,
    device_allowlist_json JSON NOT NULL,
    observation_window_seconds INT NOT NULL,
    max_sample_size INT NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'planned',
    plan_hash CHAR(64) NOT NULL,
    batch_hash CHAR(64) NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    affected_forward_ids_json JSON NOT NULL,
    artifact_snapshot_hash CHAR(64) NULL,
    external_authorization_ref VARCHAR(191) NULL,
    write_hash CHAR(64) NOT NULL,
    created_by VARCHAR(191) NOT NULL,
    created_at DATETIME(3) NOT NULL,
    updated_at DATETIME(3) NOT NULL,
    UNIQUE INDEX uniq_leigod_canary_campaign_hash (campaign_hash),
    UNIQUE INDEX uniq_leigod_canary_campaign_write_hash (write_hash),
    INDEX idx_leigod_canary_cohort_state (cohort, state),
    INDEX idx_leigod_canary_campaign_fence (scope, owner_token, fencing_token, artifact_snapshot_hash)
);

CREATE TABLE IF NOT EXISTS leigod_only_canary_forward_status (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    client_device_session_id BIGINT UNSIGNED NOT NULL,
    desired_generation INT NOT NULL,
    desired_leg_set_hash CHAR(64) NOT NULL,
    active_family VARCHAR(32) NOT NULL,
    usable_slave_count SMALLINT NOT NULL,
    distinct_slave_node_count SMALLINT NOT NULL,
    leg_policy_state VARCHAR(64) NOT NULL,
    lifecycle_state VARCHAR(32) NOT NULL,
    upload_gate_state VARCHAR(32) NOT NULL,
    download_gate_state VARCHAR(32) NOT NULL,
    promotion_ready TINYINT(1) NOT NULL DEFAULT 0,
    latest_blockers_json JSON NOT NULL,
    latest_observed_at DATETIME(3) NULL,
    plan_hash CHAR(64) NOT NULL,
    batch_hash CHAR(64) NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    affected_forward_ids_json JSON NOT NULL,
    artifact_snapshot_hash CHAR(64) NULL,
    write_hash CHAR(64) NOT NULL,
    updated_at DATETIME(3) NOT NULL,
    UNIQUE INDEX uniq_leigod_canary_forward_status (campaign_id, forward_id, client_device_session_id),
    UNIQUE INDEX uniq_leigod_canary_forward_status_write_hash (write_hash),
    INDEX idx_leigod_canary_status_forward (forward_id, updated_at),
    INDEX idx_leigod_canary_status_fence (scope, forward_id, owner_token, fencing_token, artifact_snapshot_hash)
);

CREATE TABLE IF NOT EXISTS leigod_only_activation_audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(64) NOT NULL,
    event_hash CHAR(64) NOT NULL,
    scope VARCHAR(64) NOT NULL,
    related_plan_id BIGINT UNSIGNED NULL,
    related_batch_id BIGINT UNSIGNED NULL,
    plan_hash CHAR(64) NOT NULL,
    batch_hash CHAR(64) NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    affected_forward_ids_json JSON NOT NULL,
    artifact_snapshot_hash CHAR(64) NULL,
    event_json JSON NOT NULL,
    created_by VARCHAR(191) NOT NULL,
    created_at DATETIME(3) NOT NULL,
    UNIQUE INDEX uniq_leigod_activation_event_hash (event_hash),
    INDEX idx_leigod_activation_event_scope_type (scope, event_type, created_at),
    INDEX idx_leigod_activation_event_fence (scope, owner_token, fencing_token)
);

CREATE TABLE IF NOT EXISTS leigod_only_retirement_activation_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    retirement_forward_id BIGINT UNSIGNED NOT NULL,
    replacement_forward_id BIGINT UNSIGNED NOT NULL,
    replacement_client_id BIGINT UNSIGNED NOT NULL,
    replacement_client_device_session_id BIGINT UNSIGNED NOT NULL,
    replacement_assignment_id BIGINT UNSIGNED NOT NULL,
    replacement_assignment_hash CHAR(64) NOT NULL,
    replacement_desired_generation INT NOT NULL,
    replacement_desired_leg_set_hash CHAR(64) NOT NULL,
    replacement_evidence_hash CHAR(64) NOT NULL,
    activation_batch_id BIGINT UNSIGNED NOT NULL,
    plan_hash CHAR(64) NOT NULL,
    batch_hash CHAR(64) NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    affected_forward_ids_json JSON NOT NULL,
    artifact_snapshot_hash CHAR(64) NOT NULL,
    cleanup_capability_state VARCHAR(32) NOT NULL,
    cleanup_capability_json JSON NOT NULL,
    write_hash CHAR(64) NOT NULL,
    activated_at DATETIME(3) NOT NULL,
    activated_by VARCHAR(191) NOT NULL,
    UNIQUE INDEX uniq_leigod_retirement_activation_forward (retirement_forward_id),
    UNIQUE INDEX uniq_leigod_retirement_activation_write_hash (write_hash),
    INDEX idx_leigod_retirement_activation_batch (activation_batch_id),
    INDEX idx_leigod_retirement_activation_fence (scope, owner_token, fencing_token, artifact_snapshot_hash),
    INDEX idx_leigod_retirement_replacement (replacement_forward_id, replacement_client_id, replacement_client_device_session_id, replacement_assignment_id)
);
```

- [ ] **Step 5: Implement shared runtime and leg policy**

`LeigodOnlyRuntimeContract` must define exact expected values for every locked field (`backend_mode=leigod_mode`, `client_mode=bond`, `runtime_preference=single`, `bond_ingress_mode=plain_tcp_mux`, `active_family=standalone_bond`) and forbidden markers including `bond_fallback`. Missing locked fields, wrong locked-field values, unknown non-deprecated `backend_mode`, and forbidden markers anywhere in the row return structured errors; `assertActiveContract()` throws the error code.

`StandaloneBondLegPolicy` must:

- reject malformed `node_id`, `endpoint_id`, protocol, or logical leg key;
- deduplicate by exact stable logical leg key and reject duplicates;
- count only healthy/ready/aggregating/degraded_single_leg usable legs that remain inside standalone bond;
- report `usable_slave_count`, `distinct_slave_node_count`, `state`, and `blockers`;
- compute canonical leg-set hash as SHA-256 over `forward_id`, `desired_generation`, and sorted stable logical leg keys.

`LeigodOnlyPlatformFamilyGate` must allow only `linux-bond` and `windows-bond` for Task 40 activation and reject `android-bond`, `native_mptcp`, `mptcp_preferred_bond_fallback`, `bond_fallback`, unknown families, and diagnostic MPTCP bundles before any probe/lock/write work. `LeigodOnlyAuthorizationGate` must encode the non-authorization boundary: planning, dry-run, and status paths return blockers only; any future deploy, artifact publication, cleanup execution, remote operation, commit, push, PR, or release action requires a separate explicit authorization reference and fails closed without it.

- [ ] **Step 6: Run tests**

Run:

```bash
php master/tests/leigod_only_activation_schema_test.php
php master/tests/leigod_only_runtime_contract_policy_test.php
php master/tests/standalone_bond_leg_policy_test.php
```

Expected: all print `PASS ...`.

- [ ] **Step 7: Commit Task 1**

Run:

```bash
git add master/migrations/20260719_add_leigod_only_activation_rollout.sql master/migrations/schema.sql master/src/Support/LeigodOnlyRuntimeContract.php master/src/Support/StandaloneBondLegPolicy.php master/src/Support/LeigodOnlyPlatformFamilyGate.php master/src/Support/LeigodOnlyAuthorizationGate.php master/tests/leigod_only_activation_schema_test.php master/tests/leigod_only_runtime_contract_policy_test.php master/tests/standalone_bond_leg_policy_test.php
git commit -m "feat(master): add leigod-only activation schema policies" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds in a separately authorized implementation session; protected Bridge Lite plan is not staged.

---

### Task 2: Add Real Artifact Byte, Metadata, Source, Build, and Capability Gates

**Files:**
- Create: `master/src/Support/LeigodOnlyArtifactSourceBuildMap.php`
- Create: `master/src/Support/LeigodOnlyArtifactRouteProbe.php`
- Create: `master/src/Support/LeigodOnlyArtifactMetadataProbe.php`
- Create: `master/src/Support/LeigodOnlyArtifactConsistencyService.php`
- Modify: `master/src/Controllers/BaseController.php`
- Create: `master/tests/artifact_versions_manifest_consistency_test.php`
- Create: `master/tests/artifact_route_controller_response_guard_test.php`
- Create: `master/tests/artifact_route_static_shadow_guard_test.php`
- Create: `master/tests/artifact_source_build_provenance_guard_test.php`
- Create: `master/tests/artifact_basecontroller_update_metadata_guard_test.php`
- Create: `master/tests/artifact_capability_hash_consistency_test.php`
- Create: `master/tests/installer_artifact_hash_verification_test.php`
- Create: `master/tests/artifact_cache_headers_regression_test.php`
- Modify or create: `master/tests/client_agent_update_artifact_regression_test.php`
- Modify or create: `master/tests/slave_agent_update_artifact_regression_test.php`
- Modify or create: `master/tests/sidecar_agent_update_artifact_regression_test.php`

- [ ] **Step 1: Write fake-probe behavior tests before implementation**

Create tests that instantiate `LeigodOnlyArtifactConsistencyService` with fake route, metadata, and source/build probes. Each test calls `validateForActivation()` and asserts `ok=false` for the listed blocker. Do not use source-marker assertions for the artifact service.

Required behavior cases:

```text
route_bytes_mismatch:client_agent
manifest_route_json_mismatch
manifest_route_status_not_200
public_shadow_bytes_mismatch:client_agent
source_build_mapping_missing:client_agent
source_build_mapping_missing:gost_linux_amd64
source_build_mapping_missing:leigod_linux_amd64
source_build_mapping_missing:lguot_linux_amd64
source_fingerprint_mismatch:leikwan_bond_linux_amd64
build_output_sha_mismatch:leikwan_bond_linux_amd64
base_controller_sha_mismatch:client_agent
update_helper_metadata_mismatch:client_agent_update
expected_pin_missing:leikwan_bond_linux_amd64
expected_pin_mismatch:leikwan_bond_linux_amd64
capability_gate_failed:client:client_agent:missing
capability_gate_failed:client:client_agent:wrong_session
capability_gate_failed:client:client_agent:wrong_assignment
capability_gate_failed:client:client_agent:wrong_generation
capability_gate_failed:client:client_agent:wrong_family
capability_gate_failed:client:client_agent:wrong_platform
capability_gate_failed:client:client_agent:stale
capability_gate_failed:client:client_agent:expected_only
capability_gate_failed:client:client_agent:reported_only
capability_gate_failed:client:client_agent:hash_mismatch
capability_gate_failed:slave:slave_agent:wrong_role
capability_gate_failed:sidecar:sidecar_agent:wrong_role
capability_unknown_artifact_key
route_helper_metadata_missing:slave_agent_update
unsupported_platform_family:android-bond before manifest parse, artifact route probe, metadata probe, lock lookup, write, or publish enqueue
windows_client_app route_bytes_mismatch
windows_client_app public_shadow_bytes_mismatch
windows_client_app source_build_mapping_missing
leikwan_bond_windows_amd64 build_output_sha_mismatch
capability_gate_failed:client:windows_client_app:wrong_platform
capability_gate_failed:client:leikwan_bond_windows_amd64:hash_mismatch
linux leikwan_bond_linux_amd64 capability cannot satisfy client:windows-bond bundle
windows leikwan_bond_windows_amd64 capability cannot satisfy client:linux-bond bundle
native_mptcp manifest key ignored for linux-bond activation bundle
leikwan-mptcp-relay manifest key ignored for linux-bond activation bundle
native_mptcp manifest key ignored for windows-bond activation bundle
leikwan-mptcp-relay manifest key ignored for windows-bond activation bundle
native_mptcp capability row cannot satisfy client:linux-bond bundle
leikwan-mptcp-relay capability row cannot satisfy client:linux-bond bundle
native_mptcp capability row cannot satisfy client:windows-bond bundle
leikwan-mptcp-relay capability row cannot satisfy client:windows-bond bundle
native_mptcp diagnostic route cannot make canary promotion_ready
native_mptcp diagnostic artifact cannot appear in rollback artifact pointer snapshot
```

The positive fixture must provide matching package bytes, source fingerprint, build provenance, `/artifacts/versions.json` route body, per-artifact route bytes, static mirror bytes, BaseController metadata, expected pins, and fresh capability reports.

- [ ] **Step 2: Expose BaseController metadata for activation**

Modify `BaseController.php` with a narrow method:

```php
/**
 * @param list<string> $keys
 * @return array<string, array<string, mixed>>
 */
public function artifactMetadataForActivation(array $keys): array
{
    $result = [];
    foreach ($keys as $key) {
        $result[$key] = $this->artifactMetadata($key);
    }
    return $result;
}
```

No caller may use this to publish artifacts or mutate files.

- [ ] **Step 3: Implement source/build mapping**

`LeigodOnlyArtifactSourceBuildMap` must return the explicit activation-critical key map from the Artifact Consistency Contract table, validate every role/platform-required manifest key has a mapping, maintain a separate route-only helper map for `client_agent_update`, `sidecar_agent_update`, and registered `slave_agent_update`, expand source globs deterministically, and compute a canonical source fingerprint as SHA-256 over sorted relative paths plus file hashes. For binary artifacts, it must require build provenance supplied by the rollout context:

```php
[
    'artifact_key' => 'leikwan_bond_linux_amd64',
    'source_fingerprint' => '<64-hex>',
    'build_output_sha256' => '<64-hex>',
    'build_output_size_bytes' => 3079928,
]
```

For script artifacts whose source and package path are the same file, the source fingerprint may be the single file hash and build output hash must still equal package bytes. For third-party or recovered binary artifacts (`gost_linux_amd64`, `leigod_linux_amd64`, and any `lguot_linux_amd64` build whose source tree is unavailable), activation must require explicit immutable release/source provenance in the rollout context plus package/route/hash agreement; missing provenance is `source_build_mapping_missing:<key>` and cannot be waived by capability reports alone.

- [ ] **Step 4: Implement route and metadata probes**

`LeigodOnlyArtifactRouteProbe` must call the local controller/router path for:

```text
/artifacts/versions.json
/artifacts/slave-agent.py
/artifacts/sidecar-agent.py
/artifacts/client-agent.py
/artifacts/install-forward-runtime.py
/artifacts/install-client-agent.sh
/artifacts/install-slave.sh
/artifacts/install-bond-sidecar.sh
/artifacts/uninstall-bond-sidecar.sh
/artifacts/gost/gost_linux_amd64
/artifacts/leigod/leigod-linux-amd64
/artifacts/lguot/lguot_linux_amd64
/artifacts/client-agent-update.sh
/artifacts/sidecar-agent-update.sh
/artifacts/slave-agent-update.sh
/artifacts/leikwan-bridge-setup.exe
/artifacts/wstunnel/leikwan-bond_linux_amd64
/artifacts/wstunnel/leikwan-bond_windows_amd64.exe
```

It returns status, headers, body hash, size, and decoded JSON when applicable. The route probe must dispatch the existing local controller/router only; it must not synthesize bytes, repair route mappings, or read `master/public/artifacts/**`. Static mirror checks are a separate filesystem probe that only compares a mirror if one is present. `validateForActivation()` may probe only the manifest route plus role/platform-required artifact keys and route-only helpers from the durable assignment; registered diagnostic native-MPTCP artifact routes must never become activation-required or satisfy fallback readiness. Unknown or drifting required routes fail activation with `artifact_controller_route_staging_blocker:<artifact_key>:<artifact_url>`, `artifact_controller_route_missing:<key>`, `artifact_controller_route_status_not_200:<key>`, `artifact_controller_route_bytes_mismatch:<key>`, or `artifact_controller_versions_json_mismatch`.

`LeigodOnlyArtifactMetadataProbe` must call `BaseController::artifactMetadataForActivation()` for manifest keys and must derive route-only helper metadata from the registered route/source mapping. It compares desired versions/hashes from pull/update-helper metadata with route and manifest bytes without adding fake manifest keys.

- [ ] **Step 5: Implement `LeigodOnlyArtifactConsistencyService::validateForActivation()`**

The method must:

1. parse `artifacts/versions.json`;
2. reject unsupported `platform_family` values such as `android-bond` before artifact probes, metadata probes, locks, writes, or queue work; then validate every activation-required manifest key has `version`, `path`, `sha256`, and `size_bytes`, plus a resolved route URL from `artifact_url` or installer-style `install_url`; route-only helper pseudo-keys come from the helper route map, not from manifest entries;
3. hash repo/package bytes at manifest `path`;
4. validate source fingerprint and build provenance for every activation-required manifest key, including external immutable release/source provenance for third-party/recovered binaries;
5. fetch and validate actual `/artifacts/versions.json` route response;
6. fetch and validate actual per-artifact route response;
7. compare static mirror bytes when present and record `absent` when not present;
8. compare BaseController and update-helper metadata;
9. compare expected rollout pins;
10. derive role/platform-required artifact keys from the durable assignment and require fresh assignment-scoped capability reports for those keys;
11. reject unknown/stale/partial/wrong-role/wrong-platform/wrong-session/wrong-assignment/wrong-generation/wrong-family/mismatched capability rows with `capability_gate_failed:<role>:<artifact_key>:<reason>`;
12. return a matrix with all axes and a sorted blocker list.

Activation mode must return `route_probe_unavailable`, `metadata_probe_unavailable`, or `source_build_probe_unavailable` if real probes are missing. Test mode may use fakes, but the fakes feed the same `validateForActivation()` method.

- [ ] **Step 6: Run artifact tests**

Run:

```bash
php master/tests/artifact_versions_manifest_consistency_test.php
php master/tests/artifact_route_controller_response_guard_test.php
php master/tests/artifact_route_static_shadow_guard_test.php
php master/tests/artifact_source_build_provenance_guard_test.php
php master/tests/artifact_basecontroller_update_metadata_guard_test.php
php master/tests/artifact_capability_hash_consistency_test.php
php master/tests/installer_artifact_hash_verification_test.php
php master/tests/artifact_cache_headers_regression_test.php
php master/tests/client_agent_update_artifact_regression_test.php
php master/tests/slave_agent_update_artifact_regression_test.php
php master/tests/sidecar_agent_update_artifact_regression_test.php
```

Expected: all print `PASS ...`. If current real artifacts lack required provenance, the real-activation test must assert a blocker and the plan/canary gate must remain fail-closed; do not update artifact bytes in Task 40.

- [ ] **Step 7: Commit Task 2**

Run:

```bash
git add master/src/Support/LeigodOnlyArtifactSourceBuildMap.php master/src/Support/LeigodOnlyArtifactRouteProbe.php master/src/Support/LeigodOnlyArtifactMetadataProbe.php master/src/Support/LeigodOnlyArtifactConsistencyService.php master/src/Controllers/BaseController.php master/tests/artifact_versions_manifest_consistency_test.php master/tests/artifact_route_controller_response_guard_test.php master/tests/artifact_route_static_shadow_guard_test.php master/tests/artifact_source_build_provenance_guard_test.php master/tests/artifact_basecontroller_update_metadata_guard_test.php master/tests/artifact_capability_hash_consistency_test.php master/tests/installer_artifact_hash_verification_test.php master/tests/artifact_cache_headers_regression_test.php master/tests/client_agent_update_artifact_regression_test.php master/tests/slave_agent_update_artifact_regression_test.php master/tests/sidecar_agent_update_artifact_regression_test.php
git commit -m "test(master): gate leigod rollout artifact consistency" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit contains no artifact byte/public mirror changes and no protected-file change.

---

### Task 3: Add Read-Only Planner, Immutable Plan Store, and Atomic Rollout Locks

**Files:**
- Create: `master/src/Support/LeigodOnlyActivationAssignmentService.php`
- Create: `master/src/Support/LeigodOnlyActivationPlan.php`
- Create: `master/src/Support/LeigodOnlyActivationPlanner.php`
- Create: `master/src/Support/LeigodOnlyActivationPlanStore.php`
- Create: `master/src/Support/LeigodOnlyRolloutLockService.php`
- Create: `master/src/Support/LeigodOnlyActivationSettingsService.php`
- Create: `master/src/Support/LeigodOnlyDesiredStateFenceService.php`
- Create: `master/src/Support/LeigodOnlyArtifactPointerService.php`
- Create: `master/src/Support/LeigodOnlyFencedPublishQueue.php`
- Create: `master/src/Support/LeigodOnlyFencedPublishExecutor.php`
- Create: `master/tests/leigod_only_activation_dry_run_read_only_test.php`
- Create: `master/tests/leigod_only_activation_plan_store_test.php`
- Create: `master/tests/leigod_only_planner_leg_policy_gate_test.php`
- Create: `master/tests/leigod_only_rollout_lock_service_test.php`
- Create: `master/tests/leigod_only_fencing_token_required_test.php`
- Create: `master/tests/leigod_only_current_slot_services_test.php`
- Create: `master/tests/leigod_only_desired_state_fence_service_test.php`
- Create: `master/tests/leigod_only_artifact_pointer_state_test.php`
- Create: `master/tests/leigod_only_fenced_publish_executor_test.php`

- [ ] **Step 1: Write planner behavior tests**

Use fake DB objects that record reads and fail on writes. Tests must assert:

```text
planner plan() performs no insert/update/delete/enqueue/mutator calls
planner consumes LeigodOnlyArtifactConsistencyService result
planner loads durable current assignment per candidate Forward/client/device
planner loadCurrent joins leigod_only_activation_assignment_current_slots to the pointed assignment row
planner loadCurrent reads only rows where assignment.current_slot_key = '<forward>:<client>:<device>:current', assignment.superseded_at IS NULL, slot.current_assignment_id = assignment.id, slot.current_assignment_hash = assignment.assignment_hash, and assignment.row_expires_at > now
planner loadCurrent returns exactly one current assignment row or fails closed with current_assignment_missing/current_assignment_ambiguous/current_assignment_slot_mismatch
planner assignment supersession locks the current-slot row, updates the old assignment current_slot_key to NULL and superseded_at, inserts the new assignment with the deterministic current_slot_key, and CAS-updates leigod_only_activation_assignment_current_slots in one transaction
planner assignment supersession SQL includes scope/plan_hash/batch_hash/owner_token/fencing_token/affected_forward_ids_json/artifact_snapshot_hash in inserted columns and update predicates
planner assignment supersession rolls back if old-row update count, new-row insert count, or current-slot CAS update count is not exact
planner settings/desired-state/artifact-pointer supersession clear old current_slot_key to NULL before inserting replacement current rows
planner desired-state fence service writes only allowlisted forward_id rows and validates runtime_contract_json before every current-slot replacement
planner artifact-pointer service writes only validated role/platform artifact pointers from immutable snapshot rows
planner fenced publish queue inserts one job per affected Forward and executor re-checks current desired-state fence before reconcile
planner publish job transition from pending->running, running->completed, running->failed, retry scheduling, and stale retry completion each predicate on job_hash, owner_token, fencing_token, publish_fence_token, desired_state_hash, desired_generation, and exact forward_id
planner two-worker publish race accepts exactly one job lock or completion and stale worker result has zero affected rows without changing final state
planner fetches desired leg rows through LeigodOnlyActivationAssignmentService
planner calls StandaloneBondLegPolicy for 0/1/2/8/9/duplicate/same-node cases
planner includes per-forward runtime contract blockers
planner includes per-forward artifact gate blockers
planner includes retirement and normalization preconditions without activating them
plan hash is canonical and changes when payload changes
```

The dry-run test must use spy dependencies proving no calls to `reconcileForwardEndpoints`, `republishActiveForwardsForCombination`, cleanup lease issuance, system commands, or artifact write methods.

- [ ] **Step 2: Implement immutable plan DTO and store**

`LeigodOnlyActivationPlan` canonical payload includes:

```text
scope
dry_run_only
artifact_gate
runtime_contract_gate
leg_policy_gate
telemetry_gate
canary_gate
normalization_gate
retirement_gate
rollback_gate
blockers
```

`LeigodOnlyActivationPlanStore` may insert immutable plan rows only when `--record` is explicit. Even this record-only write must be fenced: it persists scope, owner/fencing tokens, affected Forward allowlist, artifact snapshot hash, external authorization reference if supplied, and write hash as first-class columns. It must reject changed payloads by recomputing the canonical plan hash and must reject expired or missing plans before activation. A plan row is append-only; any attempt to update/delete a recorded plan, or to treat a recorded dry-run plan as authorization for deploy/publish/cleanup/commit/push, fails closed.

`LeigodOnlyActivationAssignmentService::loadCurrent()` must use the durable current-slot pointer plus the assignment row's nullable `current_slot_key`, not highest generation guessing:

```sql
SELECT a.*
FROM leigod_only_activation_assignment_current_slots s
INNER JOIN leigod_only_activation_assignments a
        ON a.id = s.current_assignment_id
       AND a.assignment_hash = s.current_assignment_hash
       AND a.forward_id = s.forward_id
       AND a.client_id = s.client_id
       AND a.client_device_session_id = s.client_device_session_id
WHERE s.forward_id = :forward_id
  AND s.client_id = :client_id
  AND s.client_device_session_id = :client_device_session_id
  AND a.current_slot_key = :current_slot_key
  AND a.superseded_at IS NULL
  AND a.row_expires_at > UTC_TIMESTAMP(3)
LIMIT 2
```

`:current_slot_key` is the deterministic non-NULL marker for the live `(forward_id, client_id, client_device_session_id)` current slot. Historical rows must have `current_slot_key=NULL`, so MySQL's unique `(forward_id, client_id, client_device_session_id, current_slot_key)` index blocks two live rows while allowing immutable history. The service fails closed unless exactly one joined row is returned, validates `assignment_hash`, `desired_leg_set_hash`, `active_family`, slot hash, slot generation/hash fields, and the locked runtime row before returning, and never chooses between multiple fresh generations by sorting. Superseding an assignment must happen in one short transaction that locks `leigod_only_activation_assignment_current_slots ... FOR UPDATE`, verifies the old assignment still matches the slot and fence, sets the old assignment's `current_slot_key=NULL` plus `superseded_at`, inserts the replacement assignment with the same deterministic non-NULL `current_slot_key`, CAS-updates the current-slot pointer to the replacement `id`/hash/generation/leg-set hash with matching `owner_token`/`fencing_token`, verifies exact update/insert counts, and rolls back on duplicate current slot, row-count mismatch, stale fence, or hash mismatch.

- [ ] **Step 3: Implement planner**

`LeigodOnlyActivationPlanner` must:

1. read candidates only;
2. load the durable current assignment with `LeigodOnlyActivationAssignmentService`;
3. validate the loaded assignment's runtime contract with `LeigodOnlyRuntimeContract`;
4. fetch desired standalone-bond leg rows from the loaded assignment;
5. compute canonical leg-set hash with `StandaloneBondLegPolicy`;
5. consume artifact gate result;
6. read Task 39 eligibility/rejection summaries;
7. read retirement capabilities without activating cleanup;
8. return an immutable plan with blockers.

It must never hardcode `leg_policy_gate ok=true`.

- [ ] **Step 4: Write and implement lock tests**

Lock behavior tests must use a fake DB that exposes exact affected-row values and duplicate-key SQLSTATE simulation. Required cases:

```text
absent lock acquire inserts and returns owner/fence
held unexpired lock rejects before insert side effects
released lock reacquires by compare-and-swap update
expired lock reacquires by compare-and-swap update
release returns affected row count
releaseExpired returns affected row count
renew with stale owner fails
requireHeld rejects wrong scope
requireHeld rejects wrong owner
requireHeld rejects wrong fence
requireHeld rejects expired lease
only SQLSTATE duplicate key for uniq_leigod_rollout_lock_scope is translated to lock held
non-duplicate insert errors propagate
```

`LeigodOnlyRolloutLockService` must get affected rows either from the return value of `db->execute()` or from `SELECT ROW_COUNT()`/`PDOStatement::rowCount()` on the same connection immediately after `UPDATE`. It must not assume `0` or ignore affected rows.

- [ ] **Step 5: Enforce fencing token requirements**

Add `LeigodOnlyRolloutLockService::requireHeld($scope, $ownerToken, $fencingToken)` and require later mutators to accept owner/fence arguments before writes. Add `leigod_only_fencing_token_required_test.php` with fake mutators proving activation, assignment supersession, retirement activation, normalization apply, settings updates, desired-state writes, artifact-pointer writes, audit inserts, publish-queue inserts, publish execution, and rollback apply reject before writes when owner/fence is missing, stale, expired, omitted from SQL predicates, persisted only in JSON, or when affected-row counts are unknown. The fake SQL recorder must assert first-class `scope`, `plan_hash`, `batch_hash`, `owner_token`, `fencing_token`, `affected_forward_ids_json` or exact affected Forward id predicates, and `artifact_snapshot_hash` when artifact state is relevant appear in the insert/update/delete predicate or inserted columns for every write class.

`LeigodOnlyActivationSettingsService`, `LeigodOnlyDesiredStateFenceService`, and `LeigodOnlyArtifactPointerService` must all use the same current-slot pattern: lock the current row or slot, verify the held fence, set the old row's `current_slot_key=NULL` and `superseded_at`, insert the replacement with a deterministic non-NULL `current_slot_key`, and verify exact affected counts. Desired-state writes target the sidecar `leigod_only_forward_desired_state_fences` table, not direct blind updates to `forwards`/`forward_combinations`; the row stores canonical snapshots of the existing desired-state rows and a validated locked `runtime_contract_json`, and the existing desired-state mutation may occur only through this service with an exact affected `forward_id` predicate. Artifact pointer writes target `leigod_only_artifact_pointer_states` and are valid only when their `artifact_snapshot_hash` exists in an immutable `leigod_only_artifact_pointer_snapshots` row and the pointer bytes/routes match that snapshot. `LeigodOnlyFencedPublishQueue` must persist first-class queue rows in `leigod_only_fenced_publish_jobs`. Every queue/executor state transition must include `job_hash`, `owner_token`, `fencing_token`, `publish_fence_token`, `desired_state_hash`, `desired_generation`, and exact `forward_id` in its predicate and must verify exact affected-row counts. `LeigodOnlyFencedPublishExecutor` may call only a fence-aware publish wrapper around `ProvisioningService::reconcileForwardEndpoints($forwardId)`, after loading the current desired-state fence row and proving the job's `publish_fence_token`, owner/fencing token, generation, desired-state hash, artifact snapshot hash, and affected-forward scope still match. A generic direct `reconcileForwardEndpoints($forwardId)` call is a test failure in all Task 40 executable surfaces.

- [ ] **Step 6: Run tests**

Run:

```bash
php master/tests/leigod_only_activation_dry_run_read_only_test.php
php master/tests/leigod_only_activation_plan_store_test.php
php master/tests/leigod_only_planner_leg_policy_gate_test.php
php master/tests/leigod_only_rollout_lock_service_test.php
php master/tests/leigod_only_fencing_token_required_test.php
php master/tests/leigod_only_current_slot_services_test.php
php master/tests/leigod_only_desired_state_fence_service_test.php
php master/tests/leigod_only_artifact_pointer_state_test.php
php master/tests/leigod_only_fenced_publish_executor_test.php
```

Expected: all print `PASS ...`.

- [ ] **Step 7: Commit Task 3**

Run:

```bash
git add master/src/Support/LeigodOnlyActivationAssignmentService.php master/src/Support/LeigodOnlyActivationPlan.php master/src/Support/LeigodOnlyActivationPlanner.php master/src/Support/LeigodOnlyActivationPlanStore.php master/src/Support/LeigodOnlyRolloutLockService.php master/src/Support/LeigodOnlyActivationSettingsService.php master/src/Support/LeigodOnlyDesiredStateFenceService.php master/src/Support/LeigodOnlyArtifactPointerService.php master/src/Support/LeigodOnlyFencedPublishQueue.php master/src/Support/LeigodOnlyFencedPublishExecutor.php master/tests/leigod_only_activation_dry_run_read_only_test.php master/tests/leigod_only_activation_plan_store_test.php master/tests/leigod_only_planner_leg_policy_gate_test.php master/tests/leigod_only_rollout_lock_service_test.php master/tests/leigod_only_fencing_token_required_test.php master/tests/leigod_only_current_slot_services_test.php master/tests/leigod_only_desired_state_fence_service_test.php master/tests/leigod_only_artifact_pointer_state_test.php master/tests/leigod_only_fenced_publish_executor_test.php
git commit -m "feat(master): add leigod-only activation planner locks" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds without artifact publication or protected-file change.

---

### Task 4: Add Standalone-Bond Activation Gate and Canary Campaign Service

**Files:**
- Create: `master/src/Support/LeigodActivationGateContext.php`
- Create: `master/src/Support/LeigodOnlyActivationAssignmentService.php` if not already created in Task 3
- Create: `master/src/Support/LeigodStandaloneBondActivationGateService.php`
- Create: `master/src/Support/LeigodOnlyCanaryCampaignService.php`
- Create: `master/tests/leigod_standalone_bond_activation_gate_test.php`
- Create: `master/tests/leigod_standalone_bond_upload_download_gate_test.php`
- Create: `master/tests/leigod_standalone_bond_single_leg_gate_test.php`
- Create: `master/tests/leigod_only_telemetry_proof_source_test.php`
- Create: `master/tests/leigod_only_snapshot_rejection_halt_test.php`
- Create: `master/tests/leigod_only_cohort_selection_test.php`
- Create: `master/tests/leigod_only_canary_promotion_gate_test.php`

- [ ] **Step 1: Write fake-DB activation gate behavior tests**

These tests instantiate `LeigodStandaloneBondActivationGateService` with fake DB, fake `BondRuntimeEligibilityService`, fake artifact gate, and fake lock service. They must assert exact outcomes for:

```text
activation flag disabled -> activation_flag_disabled
forward not allowlisted -> not_allowlisted:forward
client not allowlisted -> not_allowlisted:client
device session not allowlisted -> not_allowlisted:device
runtime row with current_mode/cf_mode/native_mptcp/platform_default/bond_fallback -> unsupported/forbidden blocker
caller-supplied runtime row conflicts with durable assignment -> assignment_context_mismatch
caller-supplied leg rows conflict with durable assignment -> assignment_context_mismatch
computed leg hash H with passed/stored H2 -> desired_leg_set_hash_mismatch
missing accepted lifecycle snapshot -> lifecycle_snapshot_missing
stale lifecycle snapshot at exactly 15 seconds -> lifecycle_snapshot_stale
wrong client device session snapshot -> lifecycle_snapshot_missing
wrong generation snapshot -> lifecycle_snapshot_missing
wrong computed leg-set hash snapshot -> lifecycle_snapshot_missing
non-monotonic rejection row -> snapshot_rejection:non_monotonic_runtime_sequence
oversized payload rejection row -> snapshot_rejection:payload_too_large
missing client capability -> fresh_client_capability_missing
expired client capability -> fresh_client_capability_missing
capability hash drift -> capability_reported_hash_mismatch
artifact gate failure -> artifact_gate_failed
single_leg_only with fresh lifecycle and capability -> ok without upload/download proof
single_leg_only with explicit degraded_single_leg evidence from the same application TCP socket, same standalone-bond transport session, same sidecar target TCP connection, same desired generation, same computed leg-set hash, and same bond protocol family -> ok without upload/download proof
degraded_single_leg evidence with application reconnect, transport-session replacement, sidecar target replacement, generation/hash drift, native_mptcp, bond_fallback, Cloudflare, WSS, or classic-sidecar marker -> blocked
single_leg_only with zero-leg evidence -> blocked
speed_plus_eligible missing upload proof -> upload_gate_failed
speed_plus_eligible missing download proof -> download_gate_failed
speed_plus_eligible stale upload proof -> upload_gate_failed
speed_plus_eligible below-threshold download proof -> download_gate_failed
upload proof cannot satisfy download -> download_gate_failed
download proof cannot satisfy upload -> upload_gate_failed
node/sidecar capability rows without client proof -> fresh_client_capability_missing
BondLegReputationService-only rows -> no proof accepted
```

- [ ] **Step 2: Implement activation context and gate**

`LeigodActivationGateContext` fields:

```text
scope
forward_id
client_id
client_device_session_id
desired_generation
assignment_id
assignment_hash
platform_family
write_mode read_only|activation|retirement|rollback
flags/settings
expected_artifact_pins
owner_token
fencing_token
campaign_id nullable
optional runtime_row/leg_rows display hints that must be ignored until matched against durable assignment
```

`LeigodStandaloneBondActivationGateService::gate()` must:

1. load the durable current assignment using `LeigodOnlyActivationAssignmentService::loadCurrent(forward_id, client_id, client_device_session_id)`;
2. call `assertMatchesContext()` for assignment id/hash, desired generation, platform family, and optional display hints;
3. call `LeigodOnlyRuntimeContract::assertActiveContract()` on the loaded assignment runtime row;
4. compute leg policy and canonical desired-leg-set hash from the loaded assignment leg rows;
5. validate activation settings and allowlists;
6. validate held owner/fence when `write_mode` is not `read_only`;
7. query accepted lifecycle snapshot for same forward/client/device/generation/computed hash/family/freshness;
8. query fresh accepted client capability for same forward/client/device/assignment/generation/computed hash/platform family and artifact hashes;
9. run `LeigodOnlyArtifactConsistencyService` with expected pins, role/platform bundle, assignment identifiers, and capability rows;
10. inspect recent Task 39 rejection rows using the scoped blocker-code query;
11. branch proof requirements by leg policy state;
12. for `speed_plus_eligible`, require independent accepted upload and download proof rows;
13. return detailed blockers, policy state, upload/download gate state, lifecycle state, assignment state, and promotion readiness.

Required lifecycle query shape:

```sql
SELECT runtime_state, desired_generation, desired_leg_set_hash, active_family, runtime_epoch, sequence, observed_at, row_expires_at
FROM forward_aggregation_runtime_snapshots
WHERE forward_id = :forward_id
  AND client_id = :client_id
  AND client_device_session_id = :client_device_session_id
  AND desired_generation = :desired_generation
  AND desired_leg_set_hash = :computed_desired_leg_set_hash
  AND active_family = 'standalone_bond'
  AND row_expires_at > UTC_TIMESTAMP(3)
  AND TIMESTAMPDIFF(MICROSECOND, observed_at, UTC_TIMESTAMP(3)) < 15000000
ORDER BY observed_at DESC
LIMIT 1
```

Required rejection query shape: use the scoped `forward_aggregation_runtime_snapshot_rejections` query in the Task 39 Evidence Wall. Tests must prove wrong forward/client/device/session/generation/hash/family rows do not halt activation, while in-scope allowlisted rejection codes do.

Required proof query shape for each direction:

```sql
SELECT useful_window_direction, useful_window_total_bytes, desired_generation, desired_leg_set_hash, active_family, observed_at, row_expires_at
FROM forward_aggregation_runtime_window_proofs
WHERE forward_id = :forward_id
  AND client_id = :client_id
  AND client_device_session_id = :client_device_session_id
  AND desired_generation = :desired_generation
  AND desired_leg_set_hash = :computed_desired_leg_set_hash
  AND active_family = 'standalone_bond'
  AND useful_window_direction = :direction
  AND row_expires_at > UTC_TIMESTAMP(3)
  AND TIMESTAMPDIFF(MICROSECOND, observed_at, UTC_TIMESTAMP(3)) < 15000000
  AND useful_window_total_bytes >= :minimum_bytes
ORDER BY observed_at DESC
LIMIT 1
```

- [ ] **Step 3: Write canary behavior tests**

`leigod_only_cohort_selection_test.php` must import the correct namespace:

```php
use Leikwan\Master\Support\LeigodOnlyCanaryCampaignService;
```

It must not instantiate `Leigod\Master\...`.

Canary tests must assert:

```text
cohort 0 read-only never calls activation mutators
cohort 1 requires exactly one usable Slave and calls shared leg policy
cohort 2 requires 2..8 usable Slaves on distinct nodes
cohort 2 same-node-only is promotion_ready=false
cohort 2 route/static/BaseController/pin/capability drift is promotion_ready=false
cohort 3/4 require owner approval checkpoint before promotion_ready
cohort N>0 requires immutable successful predecessor cohort snapshot
predecessor missing/wrong/not-successful/still-observing/blockers/pin drift/fence drift/leg policy drift/scope drift -> promotion_ready=false
cohort status recomputes or consumes activation gate result per Forward
campaign artifact_version_pins_json feeds artifact gate
campaign generation_fences_json feeds activation gate
android-bond canary request returns unsupported_platform_family:android-bond before artifact probes, locks, or writes
native_mptcp and leikwan-mptcp-relay diagnostic artifacts/capabilities cannot make promotion_ready true
```

- [ ] **Step 4: Implement canary campaign service**

`LeigodOnlyCanaryCampaignService` must load campaign rows, predecessor campaign/success snapshots, durable current assignments, campaign pins/fences/allowlists, and call the shared platform-family gate, runtime contract, leg policy, artifact gate, assignment service, and activation gate. Canary campaign/status writes are fenced status writes, not informal telemetry: every insert or status transition must persist and predicate on `scope`, `plan_hash`, `batch_hash` when present, `owner_token`, `fencing_token`, `affected_forward_ids_json`, `artifact_snapshot_hash`, and current `campaign_hash`/`write_hash`, then verify exact affected-row counts. Count-only cohort classification may be displayed as a summary but must not be authoritative for promotion. `promotion_ready` is true only when every required gate returns true, the predecessor gate passes for cohort `N > 0`, and owner approval state permits the cohort.

- [ ] **Step 5: Run activation/canary tests**

Run:

```bash
php master/tests/leigod_standalone_bond_activation_gate_test.php
php master/tests/leigod_standalone_bond_upload_download_gate_test.php
php master/tests/leigod_standalone_bond_single_leg_gate_test.php
php master/tests/leigod_only_telemetry_proof_source_test.php
php master/tests/leigod_only_snapshot_rejection_halt_test.php
php master/tests/leigod_only_cohort_selection_test.php
php master/tests/leigod_only_canary_promotion_gate_test.php
```

Expected: all print `PASS ...`.

- [ ] **Step 6: Commit Task 4**

Run:

```bash
git add master/src/Support/LeigodActivationGateContext.php master/src/Support/LeigodStandaloneBondActivationGateService.php master/src/Support/LeigodOnlyCanaryCampaignService.php master/tests/leigod_standalone_bond_activation_gate_test.php master/tests/leigod_standalone_bond_upload_download_gate_test.php master/tests/leigod_standalone_bond_single_leg_gate_test.php master/tests/leigod_only_telemetry_proof_source_test.php master/tests/leigod_only_snapshot_rejection_halt_test.php master/tests/leigod_only_cohort_selection_test.php master/tests/leigod_only_canary_promotion_gate_test.php
git commit -m "feat(master): gate standalone bond activation cohorts" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds with no native MPTCP, Cloudflare, WSS, classic sidecar, or `bond_fallback` activation path.

---

### Task 5: Add Local CLI and Admin Operations Surfaces

**Files:**
- Create: `master/cli/leigod-only-activation.php`
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Controllers/AdminController.php`
- Create: `master/tests/leigod_only_activation_cli_confirm_guard_test.php`
- Create: `master/tests/leigod_only_canary_status_read_only_test.php`
- Create: `master/tests/leigod_only_admin_operations_surface_test.php`

- [ ] **Step 1: Write real Admin dispatch and guard-order tests**

`leigod_only_admin_operations_surface_test.php` must bootstrap the real `Router`/`Bootstrap`/`AdminController` route registration path with fake services injected for planner, canary, activation, rollback, lock, and artifact gates. Do not satisfy this test with source-snippet scans. Dispatch these requests and assert response status/body plus fake-service call logs:

```text
GET /admin/leigod-only-rollout -> 200 read-only page with artifact/runtime/leg/upload/download/canary/normalization/retirement/rollback/audit sections
POST /admin/leigod-only-rollout/dry-run without valid CSRF -> 403 and mutator log empty
POST /admin/leigod-only-rollout/activate without valid CSRF -> 403 and mutator log empty
POST /admin/leigod-only-rollout/rollback without valid CSRF -> 403 and mutator log empty
POST dry-run with valid CSRF -> planner read-only call, no activation/rollback/cleanup mutator
POST activate with CSRF but missing confirmation hash -> rejected before lock/artifact/runtime writes
POST activate with CSRF and confirmation but missing owner/fencing tokens -> rejected before writes
POST activate with platform_family=android-bond -> unsupported_platform_family:android-bond before artifact probes, locks, or writes
POST rollback with CSRF but missing confirmation hash/batch id/owner/fence -> rejected before writes
```

The required guard order for every POST is: `method/route -> CSRF -> confirmations -> scope -> unchanged hashes -> owner/fencing tokens -> held lock -> allowlist -> artifact snapshot -> runtime contract`. Rejected-request tests must assert the mutator log stays empty at the exact guard that failed.

- [ ] **Step 2: Add Bootstrap routes**

Modify `Bootstrap.php` using `Router::add()` only:

```php
$this->router->add('GET', '/admin/leigod-only-rollout', [$admin, 'leigodOnlyRolloutPage']);
$this->router->add('POST', '/admin/leigod-only-rollout/dry-run', [$admin, 'leigodOnlyActivationDryRun']);
$this->router->add('POST', '/admin/leigod-only-rollout/activate', [$admin, 'leigodOnlyActivationApply']);
$this->router->add('POST', '/admin/leigod-only-rollout/rollback', [$admin, 'leigodOnlyRollback']);
```

- [ ] **Step 3: Add Admin handlers**

Admin handlers must call `requireCsrf($request)` on every POST, require confirmation hashes for mutating requests, and return read-only status by default. They must not expose controls to edit, clone, or reactivate deprecated `current_mode`/`cf_mode` combinations.

Admin page sections:

```text
Artifact consistency
Runtime contract
Leg policy
Upload gate
Download gate
Canary cohorts
Normalization activation
Legacy retirement activation
Rollback preview
Audit events
```

- [ ] **Step 4: Add CLI**

`master/cli/leigod-only-activation.php` commands:

```bash
php master/cli/leigod-only-activation.php plan --dry-run --scope=<scope> --output=json
php master/cli/leigod-only-activation.php plan --dry-run --record --scope=<scope> --output=json
php master/cli/leigod-only-activation.php canary-status --platform-family=<windows-bond|linux-bond> --output=json
php master/cli/leigod-only-activation.php activate --plan-id=<id> --confirm-plan-hash=<hash> --scope=<scope> --owner-token=<token> --fencing-token=<token>
php master/cli/leigod-only-activation.php rollback --batch-id=<id> --confirm-batch-hash=<hash> --confirm-batch-id=<id> --owner-token=<token> --fencing-token=<token> --dry-run
php master/cli/leigod-only-activation.php lock-inspect --scope=<scope> --output=json
php master/cli/leigod-only-activation.php release-expired --scope=<scope> --output=json
```

CLI dry-run and canary-status tests must use fake/spy dependencies proving no mutators are called. Activation and rollback commands must reject before writes when confirmation hash, owner token, fencing token, or unchanged plan/batch hash is missing. CLI tests must also pass `--platform-family=android-bond` to `plan`, `canary-status`, and `activate`, receive `unsupported_platform_family:android-bond`, and assert zero artifact-probe, metadata-probe, lock, mutator, and publish-queue calls; the visible enum may advertise only supported values, but the parser must still reject an explicit Android input safely.

- [ ] **Step 5: Run Admin/CLI tests**

Run:

```bash
php master/tests/leigod_only_admin_operations_surface_test.php
php master/tests/leigod_only_canary_status_read_only_test.php
php master/tests/leigod_only_activation_cli_confirm_guard_test.php
```

Expected: all print `PASS ...`.

- [ ] **Step 6: Commit Task 5**

Run:

```bash
git add master/cli/leigod-only-activation.php master/src/Bootstrap.php master/src/Controllers/AdminController.php master/tests/leigod_only_activation_cli_confirm_guard_test.php master/tests/leigod_only_canary_status_read_only_test.php master/tests/leigod_only_admin_operations_surface_test.php
git commit -m "feat(master): add leigod-only rollout operations surfaces" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds. No deployment or cleanup operation is performed.

---

### Task 6: Guard Normalization and Legacy Retirement Activation

**Files:**
- Create: `master/src/Support/LeigodNormalizationActivationContext.php`
- Create: `master/src/Support/LeigodRetirementActivationContext.php`
- Create: `master/src/Support/LeigodOnlyNormalizationActivationService.php`
- Create: `master/src/Support/LeigodOnlyLegacyRetirementActivationService.php`
- Create: `master/tests/leigod_runtime_normalization_activation_test.php`
- Create: `master/tests/leigod_only_legacy_retirement_activation_test.php`
- Create: `master/tests/leigod_only_retirement_cleanup_contract_test.php`
- Create: `master/tests/leigod_only_retirement_transaction_boundary_test.php`
- Create: `master/tests/leigod_only_activation_guarded_operations_test.php`
- Create: `master/tests/leigod_only_activation_context_dto_test.php`
- Create: `client-agent/tests/test_forward_runtime_retirement_proof_guard.py`
- Create: `slave-agent/tests/test_forward_runtime_retirement_proof_guard.py`
- Create: `sidecar-agent/tests/test_forward_runtime_retirement_proof_guard.py`

- [ ] **Step 1: Write normalization tests**

Tests must assert:

```text
normalization validates held owner/fence before writes
normalization validates unchanged plan hash
normalization accepts already locked LeiGod rows only
normalization rejects current_mode as deprecated_forward_combination_backend_mode
normalization rejects cf_mode as deprecated_forward_combination_backend_mode
normalization rejects unknown backend as invalid_forward_combination_backend_mode
normalization rejects runtime mismatch as unsupported_leigod_runtime_contract
normalization never updates backend_mode/client_mode/runtime_preference/bond_ingress_mode from legacy to LeiGod
normalization writes only allowlisted Forward IDs
malformed LeigodNormalizationActivationContext rejects before lock or DB access
```

- [ ] **Step 2: Implement normalization service**

`LeigodNormalizationActivationContext` and `LeigodRetirementActivationContext` must be final immutable DTO validators. They reject malformed scope, hashes, owner/fence tokens, affected-forward allowlists, assignment identifiers, and artifact snapshot hashes before lock or DB access.

`LeigodOnlyNormalizationActivationService::apply(LeigodNormalizationActivationContext $context)` must require held lock/fence, unchanged plan hash, artifact gate pass, write-fenced SQL predicates, and locked runtime contract. It may touch only allowlisted rows that already satisfy the locked contract, for example by recording an activation audit event or republishing a validated desired generation through a fenced queue. It must not rewrite historical legacy modes into LeiGod mode.

- [ ] **Step 3: Write retirement activation tests**

Tests must instantiate `LeigodOnlyLegacyRetirementActivationService` with fake DB, fake lock service, fake `ForwardRetirementService`, and fake remote lease/result adapters. Required cases:

```text
missing held lock -> no writes
wrong fencing token -> no writes
changed plan hash -> no writes
missing artifact snapshot -> no writes
legacy row current_mode accepted only after fresh replacement activation gate passes
legacy row cf_mode accepted only after fresh replacement activation gate passes
replacement desired locked state alone is rejected as insufficient
replacement lifecycle stale at exactly 15 seconds is rejected
replacement publish fence/generation mismatch is rejected
replacement not in validated active keep-set is rejected
malformed LeigodRetirementActivationContext rejects before lock or DB access
non-legacy row rejected
missing desired state rejected
missing runtime_retirements rejected
missing applied state rejected
missing client role capability rejected
missing slave role capability rejected
missing sidecar role capability rejected
wrong forward/generation/session/assignment/role rejected
replacement mapping missing for retired Forward rejected
replacement mapping points to unrelated Forward rejected
replacement mapping cross-tenant rejected
replacement mapping wrong client or device rejected
replacement assignment hash/generation/leg-set hash mismatch rejected
replacement evidence hash drift rejected
unsupported cleanup protocol version rejected
missing authenticated lease schema rejected
missing authenticated result schema rejected
oversized result rejected
active keep-set conflict preserves resource and reports partial
empty ownership proof rejected
ambiguous ownership proof rejected
formula-derived port rejected
broad prefix rejected
generic systemd/WireGuard name rejected
unknown listener rejected
accepted exact manifest/applied-state ownership proof issues fake lease only after DB transaction commit
remote result handling asserts no active master DB transaction
```

- [ ] **Step 4: Implement retirement activation service**

`LeigodOnlyLegacyRetirementActivationService` API:

```php
public function activate(LeigodRetirementActivationContext $context): array
```

Context fields:

```text
scope
plan_id
plan_hash
activation_batch_id
batch_hash
owner_token
fencing_token
affected_forward_ids
artifact_snapshot_hash
expected_pins
replacement_map_by_retirement_forward_id containing replacement_forward_id, client_id, client_device_session_id, assignment_id, assignment_hash, desired_generation, desired_leg_set_hash, replacement_evidence_hash
```

The replacement map is immutable batch input. Every retirement target must have exactly one mapping entry, and the retirement record must persist the mapping fields plus `replacement_evidence_hash` in first-class columns. Evidence from a different Forward, tenant, client, device session, assignment, generation, leg-set hash, platform family, or artifact snapshot must not authorize retiring the legacy row.

Service order:

1. `rolloutLock->requireHeld(scope, owner_token, fencing_token)`;
2. verify plan/batch hashes and affected allowlist;
3. validate immutable artifact snapshot/pins;
4. verify each target row is `current_mode` or `cf_mode`;
5. load the immutable replacement mapping for that exact `retirement_forward_id` and reject missing, duplicate, unrelated, cross-tenant, wrong-client, wrong-device, wrong-assignment, wrong-generation, wrong-leg-set, wrong-family, or artifact-snapshot-drift evidence;
6. validate fresh active replacement by invoking the shared activation gate for the mapped replacement Forward/client/device assignment;
7. require replacement lifecycle evidence, matching assignment hash, desired generation, computed leg-set hash, `active_family=standalone_bond`, under-15-second freshness, current publish fence/generation, artifact/capability hash agreement, and validated active keep-set membership;
8. persist `scope`, plan/batch hashes, owner/fence tokens, affected allowlist, artifact snapshot hash, replacement mapping fields, replacement evidence hash, cleanup capability state, and write hash in `leigod_only_retirement_activation_records`;
9. parse role capability JSON and verify role/forward/generation/session/assignment/protocol/version/auth schemas/bounds;
8. parse desired/applied state and `runtime_retirements`;
9. subtract active keep-set before lease issuance;
10. reject empty/ambiguous/formula/broad/generic/unknown ownership proof;
11. write bounded activation/lease DB rows inside a short transaction with write-fenced predicates and commit;
12. assert no active DB transaction;
13. call Task 36 `ForwardRetirementService`/lease adapter only after commit;
14. validate authenticated bounded results before writing result state through a fenced result update.

- [ ] **Step 5: Add Python proof-guard tests**

Client, slave, and sidecar proof-guard tests must use fake executors and assert no real systemd/WireGuard/process/filesystem deletion calls happen. Fixtures must cover missing retirement section, active keep-set wins, wrong forward, ambiguous proof, formula-only ports, broad prefixes, generic names, unknown listeners, malformed payload, accepted exact manifest/applied-state proof, authenticated lease token mismatch, authenticated result token mismatch, and oversized result truncation/rejection.

- [ ] **Step 6: Run tests**

Run:

```bash
php master/tests/leigod_runtime_normalization_activation_test.php
php master/tests/leigod_only_legacy_retirement_activation_test.php
php master/tests/leigod_only_retirement_cleanup_contract_test.php
php master/tests/leigod_only_retirement_transaction_boundary_test.php
php master/tests/leigod_only_activation_guarded_operations_test.php
php master/tests/leigod_only_activation_context_dto_test.php
python -m pytest client-agent/tests/test_forward_runtime_retirement_proof_guard.py slave-agent/tests/test_forward_runtime_retirement_proof_guard.py sidecar-agent/tests/test_forward_runtime_retirement_proof_guard.py -q
```

Expected: all pass. No test operates on real systemd, WireGuard, processes, remote hosts, or production files.

- [ ] **Step 7: Commit Task 6**

Run:

```bash
git add master/src/Support/LeigodNormalizationActivationContext.php master/src/Support/LeigodRetirementActivationContext.php master/src/Support/LeigodOnlyNormalizationActivationService.php master/src/Support/LeigodOnlyLegacyRetirementActivationService.php master/tests/leigod_runtime_normalization_activation_test.php master/tests/leigod_only_legacy_retirement_activation_test.php master/tests/leigod_only_retirement_cleanup_contract_test.php master/tests/leigod_only_retirement_transaction_boundary_test.php master/tests/leigod_only_activation_guarded_operations_test.php master/tests/leigod_only_activation_context_dto_test.php client-agent/tests/test_forward_runtime_retirement_proof_guard.py slave-agent/tests/test_forward_runtime_retirement_proof_guard.py sidecar-agent/tests/test_forward_runtime_retirement_proof_guard.py
git commit -m "feat(master): guard leigod normalization retirement activation" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds with no cleanup execution and no protected-file change.

---

### Task 7: Add Bounded Rollback Preview and Apply

**Files:**
- Create: `master/src/Support/LeigodOnlyRollbackService.php`
- Create: `master/tests/leigod_only_rollback_plan_test.php`
- Create: `master/tests/leigod_standalone_bond_bounded_rollback_test.php`
- Create: `master/tests/leigod_only_rollback_snapshot_immutability_test.php`
- Create: `master/tests/leigod_only_rollback_fenced_publish_test.php`

- [ ] **Step 1: Write rollback behavior tests**

Use fake DB, fake artifact snapshot validator, fake lock service, and fake publish queue. Required cases:

```text
missing held lock rejects before writes
confirm batch hash mismatch rejects before writes
batch_hash recompute mismatch rejects
previous_desired_state_hash mismatch rejects
artifact_snapshot_hash mismatch rejects
malformed artifact snapshot rejects
referenced artifact snapshot row missing rejects
referenced artifact snapshot row snapshot_immutable != 1 rejects
attempted UPDATE/DELETE of leigod_only_artifact_pointer_snapshots rejects
attempted UPDATE/DELETE of previous_desired_state_json or previous_desired_state_hash rejects
batch previous_artifact_snapshot_id/hash mismatch rejects
route drift in snapshot rejects
static drift in snapshot rejects
expected pin drift in snapshot rejects
capability hash drift in snapshot rejects
disable activation setting fails affected-row check -> no restore
activation settings and scoped allowlists disabled before restore
unrelated Forward desired rows untouched
restored Forward backend/client/runtime/ingress fields equal snapshot and validate locked contract
legacy/fallback marker in snapshot rejected
artifact/version/pointer metadata restored only from validated immutable snapshot
rollback desired-state restore uses current-slot supersession: lock old current row, set old current_slot_key=NULL and superseded_at, insert replacement current row, CAS slot/fence, exact row counts
rollback artifact-pointer restore uses current-slot supersession: lock old current pointer, set old current_slot_key=NULL and superseded_at, insert replacement current pointer, exact row counts
any direct UPDATE of current desired-state or artifact-pointer rows without NULL-supersede+insert replacement fails closed
new desired generation/fencing token persisted
publish queue rows include rollback fencing token
publish execution rejects missing/stale fence
publish job transition predicates include job_hash, owner_token, fencing_token, publish_fence_token, desired_state_hash, desired_generation, and forward_id
two-worker publish race accepts exactly one transition; stale completion has zero affected rows and no final-state overwrite
restore rows, artifact pointer restore, generation/fence persistence, publish enqueue, batch-state update, and audit commit atomically
injected failure after each restore stage rolls back all prior writes
injected enqueue failure rolls back restored desired rows and artifact pointers
batch-state update failure rolls back publish queue rows
row-count mismatch at any stage rolls back and leaves activation disabled state unchanged from pre-transaction snapshot
no generic reconcileForwardEndpoints call without fence
```

- [ ] **Step 2: Implement rollback service**

`LeigodOnlyRollbackService::preview($batchId)` must load the batch, recompute immutable hashes, validate snapshots, verify the referenced `leigod_only_artifact_pointer_snapshots` row exists with the exact `previous_artifact_snapshot_id`/`artifact_snapshot_hash` and `snapshot_immutable=1`, prove no previous desired-state or artifact snapshot row has been updated or deleted since the batch was created, and return blockers if any check fails. Snapshot tables and batch snapshot columns are write-once; implementation must expose no Task 40 update/delete method for them, and fake-DB tests must fail if one is attempted.

`apply($batchId, $confirmBatchHash, $ownerToken, $fencingToken)` order:

1. require held lock/fence;
2. recompute and compare `batch_hash`;
3. validate previous desired-state snapshot hash;
4. validate artifact pointer snapshot hash and content through immutable snapshot validator;
5. validate all restored desired rows with `LeigodOnlyRuntimeContract`;
6. begin one short transaction;
7. persistently disable `LEIGOD_STANDALONE_BOND_ACTIVATION_ENABLED` and scoped allowlists in `leigod_only_activation_settings` using write-fenced predicates and exact row counts;
8. restore only affected Forward desired rows through `LeigodOnlyDesiredStateFenceService` using the current-slot supersession contract: lock the old current row/slot, verify scope plus plan/batch hash plus owner/fence token plus affected Forward id, set the old row's `current_slot_key=NULL` and `superseded_at`, insert the replacement row with a deterministic non-NULL current slot key and validated locked runtime contract, CAS the current slot/fence, and verify exact row counts;
9. restore artifact/version/pointer metadata references only from the validated immutable snapshot through `LeigodOnlyArtifactPointerService` using the same current-slot supersession contract; no direct in-place pointer update is allowed;
10. persist a new desired generation/fencing token;
11. enqueue exactly one bounded per-Forward publish job carrying the rollback fence for every affected Forward;
12. update batch state to `rolled_back` and write the audit event;
13. commit only if every affected-row count and enqueue count matches the immutable batch snapshot; otherwise roll back the entire transaction.

Publish queue execution must re-check the rollback fence against the current desired row before calling any reconcile path. Every executor transition predicates on `job_hash`, `owner_token`, `fencing_token`, `publish_fence_token`, `desired_state_hash`, `desired_generation`, and `forward_id` with exact affected-row counts. `reconcileForwardEndpoints($forwardId)` remains forbidden outside the fenced publish executor's wrapper, and the wrapper must pass the publish fence into endpoint reconciliation.

- [ ] **Step 3: Run rollback tests**

Run:

```bash
php master/tests/leigod_only_rollback_plan_test.php
php master/tests/leigod_standalone_bond_bounded_rollback_test.php
php master/tests/leigod_only_rollback_snapshot_immutability_test.php
php master/tests/leigod_only_rollback_fenced_publish_test.php
```

Expected: all print `PASS ...`.

- [ ] **Step 4: Commit Task 7**

Run:

```bash
git add master/src/Support/LeigodOnlyRollbackService.php master/tests/leigod_only_rollback_plan_test.php master/tests/leigod_standalone_bond_bounded_rollback_test.php master/tests/leigod_only_rollback_snapshot_immutability_test.php master/tests/leigod_only_rollback_fenced_publish_test.php
git commit -m "feat(master): add bounded leigod-only rollback" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds. Rollback is non-destructive and LeiGod-only.

---

### Task 8: Add Non-Deployment Guard, Full Verification, and Acceptance Runbook Stub

**Files:**
- Create: `master/tests/leigod_only_no_production_deploy_in_plan_test.php`
- Create: `docs/superpowers/runbooks/leigod-only-production-acceptance.md`
- Modify: `master/cli/leigod-only-activation.php` only if CLI guard tests require tighter dependency injection
- Modify: this plan only if source guards reveal missing future-test instructions

- [ ] **Step 1: Write executable-surface no-deploy guard**

Create a guard that scans executable source surfaces only, not this Markdown plan and not the guard's own forbidden-pattern array. Scan these paths:

```text
master/cli/leigod-only-activation.php
master/src/Controllers/AdminController.php
master/src/Support/ProvisioningService.php
master/src/Support/LeigodOnlyAuthorizationGate.php
master/src/Support/LeigodOnlyActivationPlanner.php
master/src/Support/LeigodStandaloneBondActivationGateService.php
master/src/Support/LeigodOnlyRollbackService.php
master/src/Support/LeigodOnlyLegacyRetirementActivationService.php
master/src/Support/LeigodOnlyNormalizationActivationService.php
master/src/Support/LeigodOnlyFencedPublishExecutor.php
master/src/Support/LeigodOnlyArtifactPointerService.php
```

Reject executable forms of:

```text
plink
pscp
scp
ssh
rsync
systemctl restart/stop/disable/enable
wg-quick down
ip link delete/del
curl http(s)://
shell_exec(
exec(
system(
proc_open(
git commit
git push
gh pr create
gh pr merge
artifact publication writes
static artifact mirror writes
cleanup lease execution without LeigodOnlyAuthorizationGate
remote operation adapters without LeigodOnlyAuthorizationGate
```

The guard must fail if any executable Task 40 surface can call deploy/publish/cleanup/remote/commit/push behavior without passing through `LeigodOnlyAuthorizationGate::assertSeparateAuthorization()` for that exact action. It must also fail if `ProvisioningService::reconcileForwardEndpoints($forwardId)` remains callable from Task 40 paths except through `LeigodOnlyFencedPublishExecutor`'s fence-aware wrapper.

The test must ignore documented safety prose and fenced examples in Markdown so it cannot fail on this plan's non-authorization wording.

- [ ] **Step 2: Create acceptance runbook stub**

Create `docs/superpowers/runbooks/leigod-only-production-acceptance.md`:

```markdown
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
```

- [ ] **Step 3: Run full Task 40 test matrix**

Run:

```bash
php master/tests/leigod_only_activation_schema_test.php
php master/tests/leigod_only_runtime_contract_policy_test.php
php master/tests/standalone_bond_leg_policy_test.php
php master/tests/artifact_versions_manifest_consistency_test.php
php master/tests/artifact_route_controller_response_guard_test.php
php master/tests/artifact_route_static_shadow_guard_test.php
php master/tests/artifact_source_build_provenance_guard_test.php
php master/tests/artifact_basecontroller_update_metadata_guard_test.php
php master/tests/artifact_capability_hash_consistency_test.php
php master/tests/installer_artifact_hash_verification_test.php
php master/tests/artifact_cache_headers_regression_test.php
php master/tests/client_agent_update_artifact_regression_test.php
php master/tests/slave_agent_update_artifact_regression_test.php
php master/tests/sidecar_agent_update_artifact_regression_test.php
php master/tests/leigod_only_activation_dry_run_read_only_test.php
php master/tests/leigod_only_activation_plan_store_test.php
php master/tests/leigod_only_planner_leg_policy_gate_test.php
php master/tests/leigod_only_rollout_lock_service_test.php
php master/tests/leigod_only_fencing_token_required_test.php
php master/tests/leigod_only_activation_context_dto_test.php
php master/tests/leigod_standalone_bond_activation_gate_test.php
php master/tests/leigod_standalone_bond_upload_download_gate_test.php
php master/tests/leigod_standalone_bond_single_leg_gate_test.php
php master/tests/leigod_only_telemetry_proof_source_test.php
php master/tests/leigod_only_snapshot_rejection_halt_test.php
php master/tests/leigod_only_cohort_selection_test.php
php master/tests/leigod_only_canary_promotion_gate_test.php
php master/tests/leigod_only_admin_operations_surface_test.php
php master/tests/leigod_only_canary_status_read_only_test.php
php master/tests/leigod_only_activation_cli_confirm_guard_test.php
php master/tests/leigod_runtime_normalization_activation_test.php
php master/tests/leigod_only_legacy_retirement_activation_test.php
php master/tests/leigod_only_retirement_cleanup_contract_test.php
php master/tests/leigod_only_retirement_transaction_boundary_test.php
php master/tests/leigod_only_activation_guarded_operations_test.php
php master/tests/leigod_only_rollback_plan_test.php
php master/tests/leigod_standalone_bond_bounded_rollback_test.php
php master/tests/leigod_only_rollback_snapshot_immutability_test.php
php master/tests/leigod_only_rollback_fenced_publish_test.php
php master/tests/leigod_only_no_production_deploy_in_plan_test.php
```

Expected: all print `PASS ...` or, for real artifact provenance absent from the current local repo, the artifact tests explicitly assert fail-closed blockers rather than activation readiness.

- [ ] **Step 4: Run Python proof-guard tests**

Run:

```bash
python -m pytest client-agent/tests/test_forward_runtime_retirement_proof_guard.py slave-agent/tests/test_forward_runtime_retirement_proof_guard.py sidecar-agent/tests/test_forward_runtime_retirement_proof_guard.py client-agent/tests/test_aggregation_runtime_reporter.py -q
```

Expected: all pass with fake executors/adapters only.

- [ ] **Step 5: Run protected pathspec audit without reading protected content**

Run:

```bash
git status --short -- docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md
```

Expected: output exactly matches the baseline recorded in Task 1.

- [ ] **Step 6: Run forbidden-path diff audit excluding protected file**

Run:

```bash
python - <<'PY'
import json
import subprocess
from pathlib import Path
baseline_path = Path(subprocess.check_output(['git', 'rev-parse', '--git-path', 'leigod-only-activation-rollout-baseline.json'], text=True).strip())
baseline = json.loads(baseline_path.read_text(encoding='utf-8'))
protected = baseline['protected_path']
base_commit = baseline['base_commit']
changed = subprocess.check_output(['git', 'diff', '--name-only', f'{base_commit}..HEAD', '--', '.', f':(exclude){protected}'], text=True).splitlines()
status_lines = subprocess.check_output(['git', 'status', '--short', '--', '.', f':(exclude){protected}'], text=True).splitlines()
forbidden_prefixes = ['artifacts/', 'master/public/artifacts/', 'wstunnel-main/leikwan-bond/']
violations = []
for path in changed + [line[3:] for line in status_lines if len(line) > 3]:
    if any(path.startswith(prefix) for prefix in forbidden_prefixes):
        violations.append(path)
if violations:
    raise SystemExit('\n'.join(sorted(set(violations))))
print('ok')
PY
```

Expected: `ok`.

- [ ] **Step 7: Commit Task 8**

Run:

```bash
git add master/tests/leigod_only_no_production_deploy_in_plan_test.php docs/superpowers/runbooks/leigod-only-production-acceptance.md master/cli/leigod-only-activation.php docs/superpowers/plans/2026-07-19-leigod-only-activation-rollout.md
git commit -m "test(master): guard leigod-only rollout non-deployment" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds in a separately authorized implementation session. If this plan document was already committed separately, create a new commit rather than amending unless explicitly authorized.

---

## Failure Criteria and Halt Rules

Halt activation and keep or restore fail-closed flags if any condition occurs:

- `active_family` is absent, malformed, or not `standalone_bond`.
- Any forbidden runtime/fallback marker appears in desired state, snapshots, rendered supervisor config, client process args, publish queue, telemetry used for acceptance, or cleanup activation context.
- Artifact source/build/package bytes, manifest metadata, route-served bytes, static mirror bytes, BaseController metadata, expected pins, or capability-reported hashes drift.
- Real route/controller or metadata probes are unavailable in activation mode.
- Task 39 lifecycle snapshot freshness is not strictly under 15 seconds.
- Durable current assignment is missing, stale, mismatched, wrong platform, wrong generation, wrong family, or differs from caller-provided context.
- Computed desired-leg-set hash differs from stored/passed evidence hash.
- Accepted lifecycle snapshot is missing, stale, wrong session, wrong generation, wrong family, or wrong leg-set hash.
- `speed_plus_eligible` upload or download proof row is missing, stale, rejected, below threshold, wrong session, wrong generation, wrong family, or wrong leg-set hash.
- Recent same-scope rejection rows include assignment mismatch, desired generation/hash mismatch, unsupported family, forbidden/fallback marker, non-monotonic epoch/sequence, payload too large, invalid sample age, invalid runtime sequence, or invalid useful-byte window.
- Required role capability row is missing, stale, wrong role/platform/session/assignment/generation/family/hash, expected-only, reported-only, or reports artifact hash drift.
- Cleanup-capability rows cannot parse exact retirement/lease/result contract.
- Cleanup ownership proof is missing, empty, ambiguous, formula-derived, broad, generic, unknown, stale, wrong assignment, or conflicts with active keep-set.
- Rollback immutable desired-state or artifact snapshot hash cannot be recomputed and verified.
- Activation/rollback mutator lacks current owner token, fencing token, unchanged plan/batch hash, explicit scope, CSRF, and confirmation.
- Any dry-run path calls a runtime mutator or writes outside explicit immutable plan recording.

## Self-Review Checklist for Implementers

Before executing this plan, verify:

- The locked contract appears exactly and only as the active runtime contract.
- `runtime_preference=single` is never interpreted as one Slave.
- `bond_fallback` is included in forbidden marker coverage.
- Upload and download gates are separate and both are required only for `speed_plus_eligible` promotion.
- `single_leg_only` can pass availability/degradation evidence without Speed Plus useful-byte proof.
- Artifact gate uses real route/controller and BaseController metadata probes in activation mode.
- Artifact gate uses source/build provenance and fails closed when missing.
- Planner and canary promotion use shared runtime, leg, artifact, and telemetry gates.
- Rollout locks use exact affected-row semantics and duplicate-key handling.
- All mutators verify owner/fencing token before writes.
- Every write is fenced in the SQL predicate and verifies exact row counts.
- Rollback restores desired rows, artifact pointers, generation/fence, publish queue, batch state, and audit atomically, then publishes only with a rollback fence.
- Retirement activation verifies fresh replacement activation evidence, exact parsed cleanup contract, active keep-set membership, and no remote cleanup occurs in a DB transaction.
- The protected Bridge Lite plan path is untouched and only checked through Git pathspec status metadata.

## Execution Handoff

Plan complete when this document is saved and an Ultracode review reports no remaining blockers. Future execution options, only after separate explicit user authorization:

1. **Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints.

Do not begin implementation, deployment, artifact publication, cleanup activation, production remote operations, commits, or pushes from this plan alone.
