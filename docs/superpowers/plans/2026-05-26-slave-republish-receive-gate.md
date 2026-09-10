# Slave Republish Receive Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add and verify a reversible slave-side gate that can stop a selected slave from accepting panel republish publish/resume tasks, then make the panel deliver that same gate through node management config and sample-verify other slaves receive it.

**Architecture:** The panel already sends node management config in `/api/node/tasks/pull`, and the slave applies that config before processing queued tasks. Add a slave-agent config key `accept_panel_republish` that defaults true. When false, the slave still polls management and can process non-republish lifecycle tasks, but skips `publish`/`resume` tasks without acking them, leaving them pending for a safe reversible pause. The panel must include the key in authoritative node config, admin node config preservation, and tests so future edits do not strip it.

**Tech Stack:** Python slave agent, PHP panel controllers, existing PHP/Python regression test runners, live WSL/SSH verification against LeiKwan sandbox slaves.

---

## Files

- Modify: `slave-agent/slave_agent.py`
  - Add default config key `accept_panel_republish: true`.
  - Allow management config patch to update it.
  - Gate `process_tasks()` so `publish`/`resume` tasks are not acked or handled when false.
  - Include skip evidence in pull-state JSON.
- Modify: `slave-agent/tests/test_slave_mptcp_cleanup.py`
  - Add regression proving `publish` task is skipped without ack/result while management is still applied.
- Modify: `master/src/Controllers/NodeController.php`
  - Add `accept_panel_republish` to authoritative node config whitelist/snapshot.
- Modify: `master/src/Controllers/AdminController.php`
  - Preserve the key when saving node management so admin UI saves do not erase the gate.
- Create: `master/tests/node_management_accept_panel_republish_regression_test.php`
  - Static regression proving both panel paths include/preserve the key.

## Task 1: Slave local receive gate test and implementation

- [ ] Step 1: Add a failing Python test in `slave-agent/tests/test_slave_mptcp_cleanup.py` that configures `accept_panel_republish=false`, returns one `publish` task from `/api/node/tasks/pull`, and asserts no `/ack`, no `/result`, no `handle_task`, while `poll_interval_seconds` from management is applied.
- [ ] Step 2: Run the single test and verify it fails because the slave currently acks/handles the publish task.
- [ ] Step 3: Implement the minimal slave-agent gate in `slave-agent/slave_agent.py`.
- [ ] Step 4: Run the single test and verify it passes.

## Task 2: Panel propagation and preservation

- [ ] Step 1: Add `master/tests/node_management_accept_panel_republish_regression_test.php` asserting `NodeController` has the key in authoritative config and `AdminController` preserves it.
- [ ] Step 2: Run the PHP test and verify it fails before panel changes.
- [ ] Step 3: Add `accept_panel_republish` to `NodeController::authoritativeAgentConfig()` and `AdminController::saveNodeManagement()` preserved runtime config.
- [ ] Step 4: Run the PHP test and verify it passes.

## Task 3: Local regression bundle

- [ ] Step 1: Run Python compile for touched agents.
- [ ] Step 2: Run focused Python tests: `slave-agent.tests.test_slave_mptcp_cleanup`.
- [ ] Step 3: Run PHP lint for touched controllers.
- [ ] Step 4: Run focused PHP tests: the new node management test and node republish regressions.

## Task 4: Live single-slave experiment

- [ ] Step 1: Read local credential files only as needed (`leikwan-v3.txt`, `cantons.txt`, `shanghais.txt`) and identify one candidate slave.
- [ ] Step 2: On that slave, backup `/usr/local/bin/leikwan-slave-agent.py` and `/etc/leikwan-slave/config.json` before changes.
- [ ] Step 3: Sync only the modified slave agent to the candidate slave.
- [ ] Step 4: Set local config `accept_panel_republish=false` on that slave only.
- [ ] Step 5: Trigger or observe task pull with a pending publish/resume and verify the slave does not ack/handle it; collect pull-state and panel pending task evidence.
- [ ] Step 6: Re-enable local config or leave paused only long enough for panel propagation test, recording exact rollback command.

## Task 5: Panel sync and sample verification

- [ ] Step 1: Backup live panel files before overwriting.
- [ ] Step 2: Sync `NodeController.php` and `AdminController.php` to the panel, run PHP lint remotely.
- [ ] Step 3: Update the chosen node's panel `agent_config_json` to include `accept_panel_republish=false`.
- [ ] Step 4: Observe chosen slave pulls management and keeps the gate false from panel.
- [ ] Step 5: Pick at least one other slave with default/no override and confirm its pulled management contains `accept_panel_republish=true` or it keeps default true.
- [ ] Step 6: If chosen slave should resume, set the panel config back to true/null and verify it resumes accepting publish/resume.

## Verification checklist

- The gate is reversible.
- It only blocks `publish` and `resume`, not management patches or non-republish tasks.
- Blocked tasks are not acked, so no false success is reported to panel.
- Panel delivers the config key; admin saves preserve it.
- At least one live slave demonstrates the paused state and one other slave demonstrates normal propagated/default state.
