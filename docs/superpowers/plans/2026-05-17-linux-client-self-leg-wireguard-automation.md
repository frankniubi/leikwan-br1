# Linux Client Self-Leg WireGuard Automation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Linux-first real client self-leg WireGuard automation distributed by the panel to client and sidecar agents.

**Architecture:** Extend the existing self-leg candidate contract with `self_leg_wg`, have the Linux client agent publish a WireGuard identity and render a forward-scoped `lw-self-<forward_id>` tunnel, and have sidecar-agent consume matching `self_leg_client_peers`. Keep CF bond self-leg and rented helpers as fallback.

**Tech Stack:** PHP panel provisioning, Python client/sidecar agents, WireGuard/wg-quick on Linux, existing unittest/PHP regression tests.

---

### Task 1: Contract tests

**Files:**
- Modify: `client-agent/tests/test_highloss_self_leg_runtime_regression.py`
- Modify: `client-agent/tests/test_mptcp_setup_and_cleanup_regressions.py`
- Create/modify: `sidecar-agent/tests/test_sidecar_self_leg_wg.py`
- Create/modify: `master/tests/provisioning_self_leg_wg_contract_regression_test.php`

- [ ] Add failing tests that assert self-leg candidates can carry `self_leg_wg`, MPTCP runtime points to the sidecar WG address, and stale `lw-self-<forward_id>` cleanup is scoped.
- [ ] Add failing sidecar-agent test that a `self_leg_client_peers` payload renders a WireGuard peer config/restart decision.
- [ ] Add failing PHP source/contract regression that panel provisioning contains `self_leg_wg`, client/sidecar public key gating, and sidecar peer publication.

### Task 2: Client identity and runtime rendering

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `client-agent/leikwan_mptcp_client.py`

- [ ] Add client WireGuard identity helpers and heartbeat fields.
- [ ] Add `self_leg_wg` normalization and selected private WG endpoint rendering.
- [ ] Render `/etc/wireguard/lw-self-<forward_id>.conf` and setup commands before MPTCP runtime start.
- [ ] Add scoped cleanup helper for stale `lw-self-<forward_id>` interfaces.

### Task 3: Panel provisioning

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`

- [ ] Build deterministic self-leg WG addressing and payload only when client and sidecar public keys are present.
- [ ] Attach `self_leg_wg` to eligible self-leg candidates.
- [ ] Add matching sidecar management `self_leg_client_peers` entries.

### Task 4: Sidecar agent peer management

**Files:**
- Modify: `sidecar-agent/sidecar_agent.py`

- [ ] Parse `self_leg_client_peers` from management payload.
- [ ] Render/apply sidecar WG peer config with `AllowedIPs=<client_address>/32`.
- [ ] Restart/reload only when config changes.

### Task 5: Verification

**Commands:**
- `python -m unittest client-agent.tests.test_highloss_self_leg_runtime_regression client-agent.tests.test_mptcp_setup_and_cleanup_regressions sidecar-agent.tests.test_sidecar_self_leg_wg -v`
- `php master/tests/provisioning_self_leg_wg_contract_regression_test.php`
- Existing targeted suites for self-leg policy/runtime.

---

## Live rollout gate

Do not re-enable `leikwan-client-agent.service` or overwrite the current frozen forward 47 runtime until local tests are green. Live rollout starts by publishing panel/client/sidecar artifacts, then applying forward 47 and checking `wg show lw-self-47`, private tunnel reachability, and `iperf3 -R -b 300M`.
