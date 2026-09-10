# Ultra Candidate Quarantine and Runtime Isolation Implementation Plan

> **For agentic workers:** Execute this plan task-by-task with a failing test before each production change.

**Goal:** Add a reversible, scoped Ultra candidate reputation layer that can quarantine repeatedly failing candidates before new lease issuance, without changing existing behavior until the feature flag is enabled.

**Architecture:** A dedicated `UltraCandidateReputationService` owns stable candidate identity, state transitions, TTL/backoff, and bounded failure evidence. `UltraMaterialService` consults it while resolving candidates. The existing static inventory/membership predicates remain hard gates. Client feedback and reserve-pool attachment are separate follow-up phases.

**Tech Stack:** PHP 8.3, MySQL additive migration, existing `Database` wrapper, source-level PHP contract tests.

**Spec:** `docs/superpowers/specs/2026-09-10-ultra-quarantine-isolate-design.md`

## Global Constraints

- Stable identity is `source_id + line_id + ingress_zone + destination_zone + protocol`.
- `selection_ref`, credentials, relay ports, and lease tokens never become reputation identity.
- Static `is_enabled=0`, source absence, and disabled memberships remain hard exclusions.
- Dynamic filtering is disabled by default and must be reversible without deleting evidence.
- Quarantine is scoped and time-bounded; the candidate floor prevents dynamic total exclusion.
- Failure reason codes are bounded; `process_stderr` and one transient timeout are not counted.

## Task 1: Add Reputation Schema and State Machine

**Files:**
- Create: `master/migrations/20260910_add_ultra_candidate_reputation.sql`
- Modify: `master/migrations/schema.sql`
- Create: `master/src/Support/UltraCandidateReputationService.php`
- Test: `master/tests/ultra_candidate_reputation_contract_test.php`

- [x] Write tests for stable-key hashing, failure thresholds, TTL expiry, recovery, backoff, duplicate evidence, and availability floor.
- [x] Run the contract test red-first, then implement the service with fake-table support and production SQL paths.
- [x] Add additive tables for current state and bounded events, with a unique scoped candidate key.
- [x] Run the focused contract test and `php -l` on the new service.

## Task 2: Filter Candidates Before Lease Issuance

**Files:**
- Modify: `master/src/Support/UltraMaterialService.php`
- Modify: `master/src/Support/UltraLeaseService.php` only if internal candidate metadata must be carried to the audit row
- Test: `master/tests/ultra_candidate_resolution_quarantine_test.php`

- [x] Add a failing resolver test proving a quarantined candidate is skipped before `max_candidates`/offset accounting.
- [x] Keep dynamic filtering disabled when the setting is absent or false.
- [x] Filter only matching route/protocol scope, expire old quarantine rows into probation, and preserve a one-candidate floor.
- [x] Include a deterministic candidate-set/reputation fingerprint in policy generation inputs when filtering is enabled.
- [x] Run the resolver, lease, and existing Ultra material contract tests.

## Task 3: Add Authenticated Feedback Boundary

**Files:**
- Modify: `master/src/Support/UltraClientStatusService.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `master/migrations/20260910_add_ultra_candidate_reputation.sql` only if lease audit linkage is required
- Test: `master/tests/ultra_candidate_feedback_contract_test.php`

- [x] Reject unknown reason codes, malformed scopes, duplicate reports, over-sized batches, and reports for unassigned forwards.
- [x] Resolve the stable candidate from server-side lease/audit linkage rather than trusting client-supplied line IDs.
- [x] Count only authenticated, bounded, classified data-plane evidence; `process_stderr` is excluded.
- [x] Keep ingestion shadow-only until the server filter flag is explicitly enabled.
- [x] Run heartbeat/status regressions and the new feedback contract test.

## Task 4: Client Local Isolation and Reserve-Pool Follow-up

**Files:**
- Modify: `tools/ultra-client/status.go`, `control.go`, `control_windows.go`, and runtime selection code
- Modify: `master/src/Support/ProvisioningService.php` and admin surface for reserve policy
- Create focused Go/PHP tests

- [x] Add local isolate state keyed by stable server-provided candidate identity and preserve healthy siblings.
- [ ] Add reserve-pool policy with minimum/target ready counts and manual deny/tombstone precedence.
- [ ] Attach only pre-authorized, fresh, canary-verified lines and rotate policy generation atomically.
- [ ] Keep these behaviors behind explicit feature flags until live canary evidence is collected.
