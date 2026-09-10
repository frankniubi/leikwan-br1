# JP-only Aggregation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the verified JP Sidecar direction the only live direction that permits multi-path aggregation.

**Architecture:** Discover the live control-plane records and rendered runtime first, then mutate the narrowest existing combination policy and republish only affected services. Runtime evidence, rather than names or checked-in assumptions, determines JP identity and completion.

**Tech Stack:** WSL SSH, PHP panel CLI/API, MySQL, systemd, rendered slave runtime configuration.

---

### Task 1: Capture live identity and rollback state

**Files:**
- Read locally: `leikwan-v3.txt`, `cantons.txt`, `shanghais.txt`
- Write remotely: timestamped backup outside the served application tree

- [ ] Connect to the panel through WSL without printing credentials.
- [ ] Record the active panel path, deployed commit, migration status, and relevant service versions.
- [ ] Query Sidecars, slaves, direction mappings, forward combinations, plan mappings, and published snapshots needed to trace each aggregate offering end to end.
- [ ] Verify the JP Sidecar through its slave hop and identify the exact record IDs and rendered endpoints.
- [ ] Export only affected database rows/API payloads and rendered snapshots into a timestamped rollback directory; verify checksums and non-zero sizes.

### Task 2: Apply the narrow live policy mutation

**Files:**
- Modify remotely: existing persisted forward-combination policy records through the supported admin/API/CLI path
- Do not edit: generated runtime files as the source of truth

- [ ] Build an affected-set table with direction, combination ID, Sidecar ID, current aggregate state, and intended state.
- [ ] Keep aggregation enabled only where the resolved Sidecar ID is the verified JP Sidecar.
- [ ] Change each non-JP aggregate offering to its existing supported single-path mode; disable an offering if no valid single-path representation exists.
- [ ] Confirm all ingress ports in the resulting policy remain within `7000-9000` before publishing.
- [ ] Republish only affected combinations and services, recording each task/result ID.

### Task 3: Verify rendered runtime and path usability

**Files:**
- Read remotely: rendered slave configuration, systemd unit state, service journal, socket state

- [ ] On representative slaves, prove the JP direction renders an aggregate runtime through `slave -> JP Sidecar` with the intended legs eligible or active.
- [ ] Prove every non-JP affected direction has no active multi-leg bond or aggregate runtime.
- [ ] Confirm no direct client-to-Sidecar path was introduced and all ingress ports remain within `7000-9000`.
- [ ] Verify relevant units are active and inspect recent journals for publish/reconcile errors.
- [ ] Test path usability without relying on ICMP; report control-loop correctness and actual leg quality separately.
- [ ] If any invariant fails, restore the exported records, republish, and verify rollback before reporting.

