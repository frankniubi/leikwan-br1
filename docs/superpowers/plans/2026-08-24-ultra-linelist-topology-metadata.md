# Ultra Linelist Topology Metadata Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Populate Ultra line ingress and destination grouping directly from bounded LeiGod `linelist` output without acquiring connection material.

**Architecture:** The outbound-only source agent groups local TOML files by `(game_id, area_id)`, runs a small sequential batch of `linelist` requests after material jobs, parses the first two title segments as catalog ingress and destination, and persists a root-only cache. Inventory uploads the safe labels to the existing Panel API; the Panel stores, independently classifies, and displays them while retaining admin overrides and on-demand material renewal.

**Tech Stack:** Python 3 source agent, PHP 8.3 Panel, MySQL/MariaDB migrations, systemd, PHPUnit-style focused scripts, Python unittest.

---

### Task 1: Linelist parser and bounded cache refresh

**Files:**
- Modify: `source-agent/ultra_source_agent.py`
- Modify: `source-agent/tests/test_ultra_source_agent.py`

- [ ] Add failing tests for parsing `line_id`, `title`, `speed_ip`, and deriving only the first two non-empty hyphen-separated title segments.
- [ ] Add failing tests proving unique game/area grouping, strict sequential subprocess execution, bounded query count, no shell, no `startacc`, atomic mode-0600 cache writes, stale preservation on failed queries, and removal when a successful current result omits a prior line.
- [ ] Implement the parser and persistent sweep cursor with configurable refresh interval, timeout, and maximum queries per cycle.
- [ ] Run `python -m unittest source-agent/tests/test_ultra_source_agent.py -v` and require all tests to pass.

### Task 2: Inventory contract enrichment

**Files:**
- Modify: `source-agent/ultra_source_agent.py`
- Modify: `source-agent/tests/test_ultra_source_agent.py`

- [ ] Add failing tests showing cached `catalog_ingress`, `catalog_destination`, title, and discovery IP override incomplete index metadata without exposing ports or credentials.
- [ ] Extend `build_inventory()` to merge the validated cache and include bounded catalog labels in its fingerprint.
- [ ] Run source-agent tests and verify the inventory remains below 256 lines and 256 KiB.

### Task 3: Additive Panel storage and classification

**Files:**
- Create: `master/migrations/20260824_extend_ultra_catalog_topology.sql`
- Modify: `master/migrations/schema.sql`
- Modify: `master/src/Support/UltraInventoryService.php`
- Modify: `master/tests/ultra_operations_schema_test.php`
- Modify: `master/tests/ultra_inventory_contract_test.php`

- [ ] Add failing schema tests for nullable `catalog_ingress_label` and `catalog_destination_label` columns.
- [ ] Add failing normalization tests proving separate label inputs, title fallback, ambiguous values remaining `unknown`, and unchanged admin override persistence.
- [ ] Implement an idempotent additive migration and update the canonical schema.
- [ ] Store bounded catalog labels and classify ingress/destination independently during inventory upsert.
- [ ] Run the focused schema and inventory tests.

### Task 4: Admin topology and material separation

**Files:**
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/tests/admin_ultra_operations_page_test.php`

- [ ] Add failing page-contract checks for Catalog ingress, Effective ingress, Catalog destination, Effective destination, Observed relay IPs, and Material state.
- [ ] Render raw catalog labels beside effective normalized zones, while keeping existing admin override inputs and filters.
- [ ] Run the admin page test and verify forbidden secret/port fields remain absent.

### Task 5: Full regression and guarded deployment

**Files:**
- Modify only deployment copies on Panel and source host after backup.

- [ ] Run focused PHP, Python, Go, no-slave, material-renewal, artifact, and `git diff --check` verification.
- [ ] Back up Panel files/database schema state and source-agent files/config/state.
- [ ] Apply the additive Panel migration and deploy the PHP files with low-impact PHP-FPM reload.
- [ ] Deploy the source agent without adding listeners; run one bounded full catalog sweep once to bootstrap current metadata.
- [ ] Verify source service health, zero Ultra data-plane listeners, signed inventory acceptance, Panel health/auth canaries, and admin DB counts for catalog/effective ingress and destination coverage.
- [ ] Report current live-output coverage separately from cached historical-output fallback and leave genuinely unknown lines explicit.
