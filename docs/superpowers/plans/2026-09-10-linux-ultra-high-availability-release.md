# Linux Ultra High Availability Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Publish a Linux agent release that handles more than four Ultra forwards, hot-swaps lease generations without listener restarts, and avoids restart loops for forwards that have no local runtime.

**Architecture:** Keep the existing panel lease endpoint contract unchanged by chunking client requests into batches of four and merging the returned leases. Treat Ultra policy generation as control-plane state delivered over the existing Unix control socket; only rewrite/restart a runtime when its static contract changes. Gate health restarts on a locally applied runtime entry.

**Tech Stack:** Python client agent, Go `ultra-client`, PHP panel contracts, pytest/unittest, Go tests, SSH deployment to the Linux test client.

**Spec:** Read-only Linux diagnosis from 2026-09-10: `resolveUltraLease` truncates requests to four, `policy_generation` changes with material rotation, and the Ultra runtime already supports policy replacement through its control socket.

## Global Constraints

- Preserve all unrelated dirty worktree files.
- Do not change the panel lease API shape or existing client authentication.
- Keep the last known-good listener running during transient lease/control-plane failures.
- Do not publish an artifact without focused tests, artifact hashes, and a live Linux canary.

### Task 1: Lease batching and restart guard

**Files:**
- Modify: `client-agent/client_agent.py`
- Test: `client-agent/tests/test_ultra_client.py`
- Test: `client-agent/tests/test_client_agent_bond_endpoint_health.py`

- [x] Add failing tests for chunking lease requests into groups of four and skipping health restart when no applied runtime exists.
- [x] Run the focused tests and observe the expected failures.
- [x] Implement bounded request chunking and merge lease responses by forward id.
- [x] Implement the applied-entry guard in `ensure_runtime_health`.
- [x] Run the focused tests and verify they pass.

### Task 2: Hot lease generation refresh

**Files:**
- Modify: `client-agent/client_agent.py`
- Test: `client-agent/tests/test_client_agent_bond_endpoint_health.py`
- Test: `client-agent/tests/test_ultra_client.py`

- [x] Add a failing regression proving a changed Ultra lease generation does not cause a systemd restart when the static runtime contract is unchanged.
- [x] Run the regression and observe the expected failure.
- [x] Exclude only policy generation from the static comparison; keep target digest, port and transport restart-sensitive. Deliver fresh leases through the control socket. Honor explicit forced restarts.
- [x] Run focused Python tests and verify no restart is issued for generation-only rotation.

### Task 3: Release verification and deployment

**Files:**
- Modify: `artifacts/versions.json` only if the release process requires a new agent version entry.
- Create: release artifact under the existing Linux artifact directory.

- [x] Run Python focused tests, PHP contract tests, and artifact consistency checks. Go tests are blocked by the pre-existing missing `opengfw` replacement directory in this checkout.
- [x] Hash the Linux agent artifact and update the release manifest.
- [x] Commit only the scoped fix and release metadata, preserving unrelated dirty files.
- [x] Back up the Linux test client, deploy the artifact, verify service state/listeners, and confirm 180 is applied.
- [x] Run a live canary across at least one lease-generation rotation window before claiming high availability. 308.1 seconds, 11 samples, five ready forwards, ten listeners, stable PIDs; panel generations changed during observation.
