# sh36 lw-mptcp-47-0 Sidecar Route Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore sh36 forward `47` slave-transit reachability so `10.203.201.135` behind `lw-mptcp-47-0` works again through `client -> slave -> sidecar`.

**Architecture:** sh36 already reaches the slave side of the WG leg (`10.203.201.134`) and handshakes with `111.231.3.220:7329`; the dead part is the sidecar address (`10.203.201.135`) behind that slave. Fix the stale node-2 provision queue and the slave-agent systemd failure path that prevents `lwf47n2` from being applied, then republish only forward `47`/endpoint `456` and verify from sh36.

**Tech Stack:** PHP panel (`NodeController`, `AdminController`, `ProvisioningService`), Python slave agent (`slave-agent/slave_agent.py`), MySQL provision queue, systemd/WireGuard/MPTCP on live nodes.

---

## Live facts from investigation

- sh36 `lw-mptcp-47-0` is up: `10.203.201.133/32`.
- WG peer is alive: `111.231.3.220:7329`; `10.203.201.134` replies in ~6 ms.
- `10.203.201.135` is `100% packet loss`.
- sh36 runtime expects endpoint `456` on node `2`:
  - slave public host `111.231.3.220`
  - slave-side interface `lwf47n2`
  - `client_address=10.203.201.133`
  - `slave_address=10.203.201.134`
  - `sidecar_address=10.203.201.135`
  - sidecar endpoint `185.220.238.143:14974`
- Panel node `2` is heartbeating, but its provision queue is wedged:
  - many stale `running` rows with expired leases
  - ~172k `pending` rows
  - repeated duplicate pending rows for forward `47` endpoint `456`
  - recent failures: `systemctl enable ... Transport endpoint is not connected` / `Reload daemon failed: Connection timed out`

## Files to change

- `master/src/Controllers/NodeController.php`
  - Reclaim expired `running` leases before selecting tasks in `/api/node/tasks/pull`.
- `master/src/Support/ProvisioningService.php`
  - Collapse duplicate pending provision tasks before inserting a replacement task for the same `(node_id, action, service_id, forward_id, endpoint_id)`.
- `master/src/Controllers/AdminController.php`
  - Add bounded queue repair controls: reclaim expired running leases and collapse duplicate pending rows for a chosen node.
- `master/src/Bootstrap.php`
  - Register the admin queue repair POST route.
- `slave-agent/slave_agent.py`
  - Tolerate transient systemd enable/reload failures in listener sync so one systemd hiccup does not abort the publish before MPTCP underlay can settle.
- Tests:
  - `master/tests/node_task_pull_reclaims_expired_running_test.php`
  - `master/tests/provisioning_dedupes_pending_publish_tasks_test.php`
  - `master/tests/admin_queue_repair_source_test.php`
  - extend `slave-agent/tests/test_slave_plain_tcp_mux.py`

---

## Task 1 - Make node task pull reclaim expired running leases

- [ ] Add source regression `master/tests/node_task_pull_reclaims_expired_running_test.php` that checks `NodeController.php` contains:
  - `private function reclaimExpiredProvisionTaskLeases(int $nodeId): void`
  - `lease_expires_at IS NOT NULL`
  - `lease_expires_at < :now`
  - `status = :running_status`
  - `status = :pending_status`
  - call to `$this->reclaimExpiredProvisionTaskLeases((int) $node['id']);` before selecting pending rows.

- [ ] Implement in `NodeController::pullTasks()` immediately after node auth:

```php
$this->reclaimExpiredProvisionTaskLeases((int) $node['id']);
```

- [ ] Add method:

```php
private function reclaimExpiredProvisionTaskLeases(int $nodeId): void
{
    if ($nodeId <= 0) {
        return;
    }
    $now = gmdate('Y-m-d H:i:s');
    $this->db->execute(
        'UPDATE provision_tasks
         SET status = :pending_status,
             lease_expires_at = NULL,
             attempt_count = GREATEST(attempt_count - 1, 0),
             updated_at = :updated_at,
             error_message = :error_message
         WHERE node_id = :node_id
           AND status = :running_status
           AND lease_expires_at IS NOT NULL
           AND lease_expires_at < :now',
        [
            'node_id' => $nodeId,
            'pending_status' => 'pending',
            'running_status' => 'running',
            'now' => $now,
            'updated_at' => $now,
            'error_message' => 'lease expired; reclaimed for retry',
        ]
    );
}
```

- [ ] Verify:

```powershell
php master/tests/node_task_pull_reclaims_expired_running_test.php
php -l master/src/Controllers/NodeController.php
```

---

## Task 2 - Collapse duplicate pending publish tasks

- [ ] Add source regression `master/tests/provisioning_dedupes_pending_publish_tasks_test.php` checking `ProvisioningService.php` contains:
  - `private function collapsePendingProvisionTasksForNode(`
  - `superseded_by_new_publish_task`
  - `status = :completed_status`
  - filters for `node_id`, `action`, `service_id`, `forward_id`, `endpoint_id`, `status = :pending_status`
  - a call before `insert('provision_tasks', ...)`.

- [ ] Add helper in `ProvisioningService`:

```php
private function collapsePendingProvisionTasksForNode(
    int $nodeId,
    string $action,
    int $serviceId,
    int $forwardId,
    int $endpointId
): void {
    if ($nodeId <= 0 || $action === '' || $serviceId <= 0 || $forwardId <= 0 || $endpointId <= 0) {
        return;
    }
    $now = gmdate('Y-m-d H:i:s');
    $resultJson = json_encode([
        'note' => 'superseded_by_new_publish_task',
        'node_id' => $nodeId,
        'action' => $action,
        'service_id' => $serviceId,
        'forward_id' => $forwardId,
        'endpoint_id' => $endpointId,
        'collapsed_at' => $now,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $this->db->execute(
        'UPDATE provision_tasks
         SET status = :completed_status,
             result_json = :result_json,
             error_message = NULL,
             lease_expires_at = NULL,
             updated_at = :updated_at
         WHERE node_id = :node_id
           AND action = :action
           AND service_id = :service_id
           AND forward_id = :forward_id
           AND endpoint_id = :endpoint_id
           AND status = :pending_status',
        [
            'completed_status' => 'completed',
            'pending_status' => 'pending',
            'result_json' => $resultJson,
            'updated_at' => $now,
            'node_id' => $nodeId,
            'action' => $action,
            'service_id' => $serviceId,
            'forward_id' => $forwardId,
            'endpoint_id' => $endpointId,
        ]
    );
}
```

- [ ] Call it before new provision task inserts, using the exact scalar values being inserted for `node_id`, `action`, `service_id`, `forward_id`, and `endpoint_id`. The newest task remains pending; older identical pending rows become completed.

- [ ] Verify:

```powershell
php master/tests/provisioning_dedupes_pending_publish_tasks_test.php
php -l master/src/Support/ProvisioningService.php
```

---

## Task 3 - Add bounded admin queue repair

- [ ] Add source regression `master/tests/admin_queue_repair_source_test.php` checking `AdminController.php` contains:
  - `public function repairProvisionQueue(Request $request, array $params): Response`
  - `repair_node_id`
  - `reclaim_expired_running`
  - `collapse_duplicate_pending`
  - `GROUP BY node_id, action, service_id, forward_id, endpoint_id`
  - `superseded_by_admin_queue_repair`
  - no deletion of `running` rows.

- [ ] Add `AdminController::repairProvisionQueue()` with two modes:
  - `reclaim_expired_running`: set expired `running` rows back to `pending`, clear lease, decrement `attempt_count` with `GREATEST(attempt_count - 1, 0)`.
  - `collapse_duplicate_pending`: for one node or all nodes, keep `MAX(id)` per `(node_id, action, service_id, forward_id, endpoint_id)` and mark older pending rows `completed` with note `superseded_by_admin_queue_repair`.

- [ ] Register route in `master/src/Bootstrap.php`:

```php
$this->router->add('POST', '/admin/provision-queue/repair', [$admin, 'repairProvisionQueue']);
```

- [ ] Add a compact diagnostics form with node ID and repair mode. Default node ID can be `2` because this incident is node-2-specific.

- [ ] Verify:

```powershell
php master/tests/admin_queue_repair_source_test.php
php master/tests/admin_pending_task_purge_test.php
php -l master/src/Controllers/AdminController.php
php -l master/src/Bootstrap.php
```

---

## Task 4 - Make slave listener sync tolerant to transient systemd failures

- [ ] Add Python test to `slave-agent/tests/test_slave_plain_tcp_mux.py`:
  - Create a fake route on listen port `7000`.
  - Mock `run_command` so `systemctl enable leikwan-tcp-mux-7000.service` returns or raises `Transport endpoint is not connected`.
  - Assert `sync_listener(7000)` still writes route JSON and attempts `systemctl start leikwan-tcp-mux-7000.service`.

- [ ] Add helpers in `slave-agent/slave_agent.py`:

```python
def is_transient_systemd_error(detail: str) -> bool:
    text = str(detail or "").lower()
    return any(item in text for item in (
        "transport endpoint is not connected",
        "connection timed out",
        "failed to connect to bus",
        "system has not been booted with systemd",
    ))


def run_systemctl_tolerating_transient(command: str) -> bool:
    result = run_command(command, check=False)
    if int(getattr(result, "returncode", 0) or 0) == 0:
        return True
    detail = str(getattr(result, "stderr", "") or getattr(result, "stdout", "") or "")
    if is_transient_systemd_error(detail):
        logging.warning("transient systemd failure ignored for %s: %s", command, detail.strip())
        return False
    raise RuntimeError(f"command failed: {command}\nstdout={getattr(result, 'stdout', '')}\nstderr={getattr(result, 'stderr', '')}")
```

- [ ] Use the helper in `sync_listener()` for strict `daemon-reload` and `enable` calls. Keep permanent unit errors fatal.

- [ ] Verify:

```powershell
py -3 -m unittest slave-agent.tests.test_slave_plain_tcp_mux -v
py -3 -m py_compile slave-agent/slave_agent.py
```

---

## Task 5 - Local full verification

- [ ] Run PHP checks:

```powershell
php master/tests/node_task_pull_reclaims_expired_running_test.php
php master/tests/provisioning_dedupes_pending_publish_tasks_test.php
php master/tests/admin_queue_repair_source_test.php
php master/tests/admin_pending_task_purge_test.php
php master/tests/provisioning_node_republish_malformed_task_normalization_test.php
php master/tests/provisioning_self_leg_cf_wg_transport_regression_test.php
php -l master/src/Controllers/NodeController.php
php -l master/src/Controllers/AdminController.php
php -l master/src/Support/ProvisioningService.php
php -l master/src/Bootstrap.php
```

- [ ] Run Python checks:

```powershell
py -3 -m py_compile slave-agent/slave_agent.py client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py sidecar-agent/sidecar_agent.py
py -3 -m unittest slave-agent.tests.test_slave_plain_tcp_mux -v
py -3 -m unittest slave-agent.tests.test_slave_mptcp_cleanup -v
py -3 -m unittest client-agent.tests.test_self_leg_cf_wg_runtime -v
```

---

## Task 6 - Deploy/sync code and artifact metadata

- [ ] Sync only changed panel files to `/www/wwwroot/v3.leikwanhost.com/leikwan-v3` on panel host `10.0.10.196`.
- [ ] Sync the new `slave-agent/slave_agent.py` into the panel artifact tree.
- [ ] Update `artifacts/versions.json` if the slave-agent artifact hash/size is tracked there.
- [ ] On panel host, run:

```bash
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
php -l master/src/Controllers/NodeController.php
php -l master/src/Controllers/AdminController.php
php -l master/src/Support/ProvisioningService.php
php -l master/src/Bootstrap.php
```

---

## Task 7 - Live bounded node-2 queue repair

- [ ] Snapshot first on panel host:

```sql
SELECT status, COUNT(*), MIN(created_at), MAX(created_at)
FROM provision_tasks
WHERE node_id=2
GROUP BY status;

SELECT id,action,forward_id,endpoint_id,status,created_at,updated_at,lease_expires_at,attempt_count,LEFT(error_message,300)
FROM provision_tasks
WHERE node_id=2 AND status IN ('running','pending')
ORDER BY id ASC LIMIT 80;
```

- [ ] Reclaim expired running rows for node `2`:

```sql
UPDATE provision_tasks
SET status='pending',
    lease_expires_at=NULL,
    attempt_count=GREATEST(attempt_count - 1, 0),
    error_message='reclaimed expired running task by sh36 route repair',
    updated_at=UTC_TIMESTAMP()
WHERE node_id=2
  AND status='running'
  AND lease_expires_at IS NOT NULL
  AND lease_expires_at < UTC_TIMESTAMP();
```

- [ ] Collapse duplicate pending rows for node `2`, keeping newest `MAX(id)` per `(node_id, action, service_id, forward_id, endpoint_id)`. Prefer the new admin queue repair route; direct SQL is acceptable only if the route is not available.

- [ ] Republish only forward `47` / endpoint `456` through the panel code path. Do not handcraft payload JSON unless the panel path is unavailable.

- [ ] If node `2` continues heartbeating but the fresh `47/456` task remains pending after two poll intervals, inspect/restart only `leikwan-slave-agent.service` on `111.231.3.220`. Do not reboot unless systemd itself is unrecoverable.

---

## Task 8 - Verify the exact route

- [ ] On sh36:

```bash
systemctl is-active leikwan-client-agent.service
systemctl is-active leikwan-client-47.service
ip -br addr show lw-mptcp-47-0
wg show lw-mptcp-47-0
ping -I lw-mptcp-47-0 -c 3 -W 1 10.203.201.134
ping -I lw-mptcp-47-0 -c 5 -W 1 10.203.201.135
ip mptcp endpoint show
```

Expected:

- `10.203.201.134` remains reachable.
- `10.203.201.135` becomes reachable, or if ICMP is blocked, prove TCP to `10.203.201.135:8898`.
- `lw-cfwg-47` remains the primary/healthy path; `lw-mptcp-47-0` can stay backup/standby.

- [ ] Panel queue should no longer show duplicate pending groups for node `2`:

```sql
SELECT forward_id, endpoint_id, COUNT(*) AS pending_count
FROM provision_tasks
WHERE node_id=2 AND status='pending'
GROUP BY forward_id, endpoint_id
HAVING COUNT(*) > 1;
```

Expected: zero rows.

## Rollback notes

- Do not delete queue history; mark rows completed/reclaimed only.
- If sh36 forward `47` primary CFWG regresses, restore known-good sh36 runtime from:

```text
/root/leikwan-mptcp-leg-priority-sh36-20260519-005901
```

- Keep `disable_auto_update=true` on the affected client/sidecar path until the panel artifact state is verified safe.
