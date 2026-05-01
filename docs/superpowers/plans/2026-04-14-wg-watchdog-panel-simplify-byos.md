# WG Watchdog, Panel Simplification & BYOS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement three approved features: (1) WireGuard health watchdog with auto-restart, (2) simplified single-page forward creation form, (3) Bring Your Own Slave node enrollment.

**Architecture:** Feature 1 is a standalone bash script + systemd units deployed to client/slave/sidecar via install scripts. Feature 2 replaces the 3-step `forwardWizard()` in TenantController with a single-page `quickCreateForward()` method, reusing the existing `ProvisioningService::saveForward()` backend unchanged. Feature 3 adds a `node_enrollment_tokens` table and ownership columns to `nodes`, modifies the NodeController register API to handle per-service tokens, and adds visibility filtering to `pickNodes()`/`pickNodesForGroups()`.

**Tech Stack:** Bash (watchdog script), systemd (timer/service), PHP (panel controller + provisioning), SQL (migrations)

**Spec:** `docs/superpowers/specs/2026-04-14-wg-watchdog-panel-simplify-byos-design.md`

---

## File Map

### Feature 1: WG Watchdog
| Action | File | Purpose |
|--------|------|---------|
| Create | `artifacts/leikwan-wg-watchdog.sh` | Watchdog bash script |
| Create | `deploy/systemd/leikwan-wg-watchdog.service` | Systemd oneshot unit |
| Create | `deploy/systemd/leikwan-wg-watchdog.timer` | Systemd timer (60s) |
| Modify | `install/install-client.sh` | Install watchdog after artifact downloads |
| Modify | `install/install-slave.sh` | Install watchdog after artifact downloads |
| Modify | `install/install-bond-sidecar.sh` | Install watchdog after artifact downloads |

### Feature 2: Panel Simplification
| Action | File | Purpose |
|--------|------|---------|
| Modify | `master/src/Controllers/TenantController.php` | Add `quickCreateForward()`, redirect old wizard |
| Modify | `master/src/Bootstrap.php` | Add route for new form, keep old route as redirect |

### Feature 3: BYOS
| Action | File | Purpose |
|--------|------|---------|
| Create | `master/migrations/20260414_add_byos_node_ownership.sql` | DB migration for ownership columns + enrollment tokens table |
| Modify | `master/src/Controllers/NodeController.php` | Handle BYOS enrollment tokens in `register()` |
| Modify | `master/src/Controllers/TenantController.php` | Add "My Slaves" section + token generation UI |
| Modify | `master/src/Support/ProvisioningService.php` | Add ownership filter to `pickNodes()`, `pickNodesForGroups()`, `availableNodeCountForGroups()` |
| Modify | `master/src/Bootstrap.php` | Add routes for BYOS token generation + node removal |

---

## Task 1: WG Watchdog — Bash Script

**Files:**
- Create: `artifacts/leikwan-wg-watchdog.sh`

- [ ] **Step 1: Create the watchdog script**

```bash
#!/usr/bin/env bash
# leikwan-wg-watchdog.sh — WireGuard health monitor
# Invoked by systemd timer every 60s. Checks all lw-*/wg* interfaces for stale
# handshakes and restarts wg-quick if a peer exceeds the threshold.
set -euo pipefail

STALE_THRESHOLD_SECONDS=180
COOLDOWN_SECONDS=300
STATE_DIR="/run/leikwan-wg-watchdog"

# Source optional config override
if [[ -f /etc/leikwan/wg-watchdog.conf ]]; then
  # shellcheck disable=SC1091
  . /etc/leikwan/wg-watchdog.conf
fi

mkdir -p "$STATE_DIR"

now=$(date +%s)

for iface in $(wg show interfaces 2>/dev/null); do
  # Only monitor leikwan-managed or wg-prefixed interfaces
  case "$iface" in
    lw-*|wg*|lwm*) ;;
    *) continue ;;
  esac

  # Check cooldown — skip if we restarted this interface recently
  cooldown_file="$STATE_DIR/$iface.last_restart"
  if [[ -f "$cooldown_file" ]]; then
    last_restart=$(cat "$cooldown_file" 2>/dev/null || echo 0)
    elapsed=$(( now - last_restart ))
    if (( elapsed < COOLDOWN_SECONDS )); then
      continue
    fi
  fi

  # Parse handshake timestamps for each peer on this interface
  needs_restart=false
  stale_peer=""
  stale_age=0
  while IFS=$'\t' read -r peer_pubkey handshake_epoch; do
    if [[ -z "$handshake_epoch" || "$handshake_epoch" == "0" ]]; then
      # Never had a handshake — interface may be freshly created, skip
      continue
    fi
    age=$(( now - handshake_epoch ))
    if (( age > STALE_THRESHOLD_SECONDS )); then
      needs_restart=true
      stale_peer="${peer_pubkey:0:8}...${peer_pubkey: -6}"
      stale_age=$age
      break
    fi
  done < <(wg show "$iface" latest-handshakes 2>/dev/null)

  if $needs_restart; then
    echo "leikwan-wg-watchdog: restarted wg-quick@${iface} — peer ${stale_peer} handshake stale (${stale_age}s > ${STALE_THRESHOLD_SECONDS}s threshold)"
    systemctl restart "wg-quick@${iface}.service" 2>/dev/null || true
    echo "$now" > "$cooldown_file"
  fi
done
```

- [ ] **Step 2: Verify script is syntactically valid**

Run: `bash -n artifacts/leikwan-wg-watchdog.sh`
Expected: no output (clean parse)

- [ ] **Step 3: Commit**

```bash
git add artifacts/leikwan-wg-watchdog.sh
git commit -m "feat(watchdog): add WireGuard health watchdog script"
```

---

## Task 2: WG Watchdog — Systemd Units

**Files:**
- Create: `deploy/systemd/leikwan-wg-watchdog.service`
- Create: `deploy/systemd/leikwan-wg-watchdog.timer`

- [ ] **Step 1: Create the service unit**

```ini
[Unit]
Description=Leikwan WireGuard Watchdog
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/leikwan-wg-watchdog.sh
```

- [ ] **Step 2: Create the timer unit**

```ini
[Unit]
Description=Run Leikwan WG Watchdog every 60s

[Timer]
OnBootSec=90s
OnUnitActiveSec=60s
Unit=leikwan-wg-watchdog.service

[Install]
WantedBy=timers.target
```

- [ ] **Step 3: Commit**

```bash
git add deploy/systemd/leikwan-wg-watchdog.service deploy/systemd/leikwan-wg-watchdog.timer
git commit -m "feat(watchdog): add systemd timer and service units"
```

---

## Task 3: WG Watchdog — Install Script Integration

**Files:**
- Modify: `install/install-client.sh:135` (after artifact downloads)
- Modify: `install/install-slave.sh:129` (after artifact downloads)
- Modify: `install/install-bond-sidecar.sh:156` (after artifact downloads)

Each install script needs the same block appended after the binary downloads section: download the watchdog script, write the systemd units inline, and enable the timer.

- [ ] **Step 1: Add watchdog install to install-client.sh**

After line 135 (`chmod 0755 ...`), insert:

```bash
# --- WireGuard watchdog ---
curl -fsSL "$ARTIFACT_BASE/artifacts/leikwan-wg-watchdog.sh" -o "$BIN_DIR/leikwan-wg-watchdog.sh"
chmod 0755 "$BIN_DIR/leikwan-wg-watchdog.sh"
cat > /etc/systemd/system/leikwan-wg-watchdog.service <<'WDUNIT'
[Unit]
Description=Leikwan WireGuard Watchdog
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/leikwan-wg-watchdog.sh
WDUNIT
cat > /etc/systemd/system/leikwan-wg-watchdog.timer <<'WDTIMER'
[Unit]
Description=Run Leikwan WG Watchdog every 60s

[Timer]
OnBootSec=90s
OnUnitActiveSec=60s
Unit=leikwan-wg-watchdog.service

[Install]
WantedBy=timers.target
WDTIMER
systemctl daemon-reload
systemctl enable --now leikwan-wg-watchdog.timer 2>/dev/null || true
```

- [ ] **Step 2: Add watchdog install to install-slave.sh**

After line 129 (`chmod 0755 "$BIN_DIR/leikwan-uot.py"`), insert the identical block but using `$MASTER_URL` instead of `$ARTIFACT_BASE`:

```bash
# --- WireGuard watchdog ---
curl -fsSL "$MASTER_URL/artifacts/leikwan-wg-watchdog.sh" -o "$BIN_DIR/leikwan-wg-watchdog.sh"
chmod 0755 "$BIN_DIR/leikwan-wg-watchdog.sh"
cat > /etc/systemd/system/leikwan-wg-watchdog.service <<'WDUNIT'
[Unit]
Description=Leikwan WireGuard Watchdog
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/leikwan-wg-watchdog.sh
WDUNIT
cat > /etc/systemd/system/leikwan-wg-watchdog.timer <<'WDTIMER'
[Unit]
Description=Run Leikwan WG Watchdog every 60s

[Timer]
OnBootSec=90s
OnUnitActiveSec=60s
Unit=leikwan-wg-watchdog.service

[Install]
WantedBy=timers.target
WDTIMER
systemctl daemon-reload
systemctl enable --now leikwan-wg-watchdog.timer 2>/dev/null || true
```

- [ ] **Step 3: Add watchdog install to install-bond-sidecar.sh**

After line 156 (`download ... leikwan-uot.py`), insert the identical block using `$ARTIFACT_BASE`:

```bash
# --- WireGuard watchdog ---
download "$ARTIFACT_BASE/artifacts/leikwan-wg-watchdog.sh" "$BIN_DIR/leikwan-wg-watchdog.sh"
cat > /etc/systemd/system/leikwan-wg-watchdog.service <<'WDUNIT'
[Unit]
Description=Leikwan WireGuard Watchdog
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/leikwan-wg-watchdog.sh
WDUNIT
cat > /etc/systemd/system/leikwan-wg-watchdog.timer <<'WDTIMER'
[Unit]
Description=Run Leikwan WG Watchdog every 60s

[Timer]
OnBootSec=90s
OnUnitActiveSec=60s
Unit=leikwan-wg-watchdog.service

[Install]
WantedBy=timers.target
WDTIMER
systemctl daemon-reload
systemctl enable --now leikwan-wg-watchdog.timer 2>/dev/null || true
```

Note: the sidecar script has a `download()` helper function, so use that for the script download.

- [ ] **Step 4: Commit**

```bash
git add install/install-client.sh install/install-slave.sh install/install-bond-sidecar.sh
git commit -m "feat(watchdog): integrate watchdog install into client/slave/sidecar scripts"
```

---

## Task 4: BYOS — Database Migration

**Files:**
- Create: `master/migrations/20260414_add_byos_node_ownership.sql`

- [ ] **Step 1: Create the migration file**

```sql
ALTER TABLE nodes ADD COLUMN owner_service_id BIGINT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE nodes ADD COLUMN enrollment_type VARCHAR(32) NOT NULL DEFAULT 'platform';
ALTER TABLE nodes ADD INDEX idx_nodes_owner_service (owner_service_id);

CREATE TABLE IF NOT EXISTS node_enrollment_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    service_id BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    used_by_node_id BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_enrollment_token_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    INDEX idx_enrollment_token (token),
    INDEX idx_enrollment_service (service_id)
);
```

- [ ] **Step 2: Commit**

```bash
git add master/migrations/20260414_add_byos_node_ownership.sql
git commit -m "feat(byos): add node ownership columns and enrollment tokens table"
```

---

## Task 5: BYOS — NodeController Registration with Enrollment Tokens

**Files:**
- Modify: `master/src/Controllers/NodeController.php:22-83` (the `register()` method)

The current `register()` validates the token against a single global `NODE_ENROLLMENT_TOKEN` config value. We need to add a fallback path: if the global token check fails, try looking up the token in `node_enrollment_tokens`. If found and valid (not expired, not used), set `owner_service_id` and `enrollment_type` on the created node, and mark the token as consumed.

- [ ] **Step 1: Modify the register() method**

Replace the authentication block (lines 24-29) and the insert block (lines 58-76) in `NodeController::register()`.

Current auth block (lines 24-29):
```php
        $body = is_array($request->body()) ? $request->body() : [];
        $token = $request->header('x-enrollment-token') ?? (string) $request->input('enrollment_token', '');
        $expected = (string) $this->config->get('NODE_ENROLLMENT_TOKEN', '');
        if ($token === '' || !hash_equals($expected, $token)) {
            throw new ApiException('node_enrollment_failed', 401);
        }
```

New auth block:
```php
        $body = is_array($request->body()) ? $request->body() : [];
        $token = $request->header('x-enrollment-token') ?? (string) $request->input('enrollment_token', '');
        if ($token === '') {
            throw new ApiException('node_enrollment_failed', 401);
        }

        // Try global enrollment token first
        $expected = (string) $this->config->get('NODE_ENROLLMENT_TOKEN', '');
        $byosToken = null;
        if ($expected !== '' && hash_equals($expected, $token)) {
            // Platform enrollment — global token matched
        } else {
            // Try BYOS per-service enrollment token
            $byosToken = $this->db->fetchOne(
                'SELECT * FROM node_enrollment_tokens WHERE token = :token LIMIT 1',
                ['token' => $token]
            );
            if ($byosToken === null) {
                throw new ApiException('node_enrollment_failed', 401);
            }
            if ($byosToken['used_at'] !== null) {
                throw new ApiException('enrollment_token_already_used', 422);
            }
            if (strtotime((string) $byosToken['expires_at']) < time()) {
                throw new ApiException('enrollment_token_expired', 422);
            }
        }
```

Then modify the `$this->db->insert('nodes', [...])` call (line 58-76) to include ownership fields:

Current insert:
```php
        $nodeId = $this->db->insert('nodes', [
            'group_id' => $group['id'],
            'credential_profile_id' => $credentialProfile['id'] ?? null,
            'name' => (string) $request->input('name', 'node-' . substr($apiToken, 0, 8)),
            'region' => (string) $request->input('region', 'unknown'),
            'public_ip' => (string) $request->input('public_ip', ''),
            'private_ip' => (string) $request->input('private_ip', ''),
            'machine_id' => trim((string) $request->input('machine_id', '')) ?: null,
            'api_token' => $apiToken,
            'agent_version' => $agentVersion !== '' ? $agentVersion : null,
            'status' => 'active',
            'current_load' => 0,
            'supports_udp' => (int) $supportsUdp,
            'supports_filtering' => (int) $supportsFiltering,
            'agent_config_json' => $capabilities !== [] ? json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
```

New insert:
```php
        $nodeId = $this->db->insert('nodes', [
            'group_id' => $group['id'],
            'credential_profile_id' => $credentialProfile['id'] ?? null,
            'name' => (string) $request->input('name', 'node-' . substr($apiToken, 0, 8)),
            'region' => (string) $request->input('region', 'unknown'),
            'public_ip' => (string) $request->input('public_ip', ''),
            'private_ip' => (string) $request->input('private_ip', ''),
            'machine_id' => trim((string) $request->input('machine_id', '')) ?: null,
            'api_token' => $apiToken,
            'agent_version' => $agentVersion !== '' ? $agentVersion : null,
            'status' => 'active',
            'current_load' => 0,
            'supports_udp' => (int) $supportsUdp,
            'supports_filtering' => (int) $supportsFiltering,
            'agent_config_json' => $capabilities !== [] ? json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'owner_service_id' => $byosToken !== null ? (int) $byosToken['service_id'] : null,
            'enrollment_type' => $byosToken !== null ? 'user_enrolled' : 'platform',
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
```

Then after the insert (before the return), mark the BYOS token as consumed:

```php
        if ($byosToken !== null) {
            $this->db->execute(
                'UPDATE node_enrollment_tokens SET used_at = :used_at, used_by_node_id = :node_id WHERE id = :id',
                ['used_at' => gmdate('Y-m-d H:i:s'), 'node_id' => $nodeId, 'id' => $byosToken['id']]
            );
        }
```

- [ ] **Step 2: Commit**

```bash
git add master/src/Controllers/NodeController.php
git commit -m "feat(byos): handle per-service enrollment tokens in node registration"
```

---

## Task 6: BYOS — Node Visibility Filtering in ProvisioningService

**Files:**
- Modify: `master/src/Support/ProvisioningService.php:2981-2998` (`pickNodes()`)
- Modify: `master/src/Support/ProvisioningService.php:3047-3074` (`availableNodeCountForGroups()`)

Both `pickNodes()` and `availableNodeCountForGroups()` need to accept an optional `$serviceId` parameter and add a WHERE clause: `AND (n.owner_service_id IS NULL OR n.owner_service_id = :owner_service_id)`. Since `pickNodesForGroups()` delegates to `pickNodes()`, it also needs the parameter threaded through.

- [ ] **Step 1: Modify pickNodes()**

Current signature (line 2981):
```php
    private function pickNodes(string $groupKey, int $count): array
```

New signature:
```php
    private function pickNodes(string $groupKey, int $count, ?int $serviceId = null): array
```

After the existing WHERE clauses (line 2990), add:
```php
        if ($serviceId !== null) {
            $sql .= ' AND (n.owner_service_id IS NULL OR n.owner_service_id = :owner_service_id)';
            $params['owner_service_id'] = $serviceId;
        }
```

- [ ] **Step 2: Modify pickNodesForGroups()**

Current signature (line 3005):
```php
    private function pickNodesForGroups(array $groupKeys, int $count): array
```

New signature:
```php
    private function pickNodesForGroups(array $groupKeys, int $count, ?int $serviceId = null): array
```

Update the inner call at line 3015:
```php
            $groupNodes = $this->pickNodes($groupKey, max(1, $count), $serviceId);
```

- [ ] **Step 3: Modify availableNodeCountForGroups()**

Current signature (line 3047):
```php
    private function availableNodeCountForGroups(array $groupKeys): int
```

New signature:
```php
    private function availableNodeCountForGroups(array $groupKeys, ?int $serviceId = null): int
```

Add visibility filter in the query (after the `AND g.group_key IN (...)` clause, before the closing `'`):
```php
        $ownerClause = '';
        if ($serviceId !== null) {
            $ownerClause = ' AND (n.owner_service_id IS NULL OR n.owner_service_id = :owner_service_id)';
            $params['owner_service_id'] = $serviceId;
        }
```

And append `$ownerClause` to the SQL string.

- [ ] **Step 4: Thread serviceId through callers**

Find all internal callers of `pickNodes()`, `pickNodesForGroups()`, and `availableNodeCountForGroups()` within `ProvisioningService`. They are called from `saveForward()` and related methods that already have `$serviceId` available. Pass `$serviceId` through to these calls.

Key call sites to update:
- `panelManagedEndpointCount()` (line 3080) — add `?int $serviceId = null` parameter, pass to `availableNodeCountForGroups()`
- Inside `saveForward()` where `pickNodesForGroups()` and `panelManagedEndpointCount()` are called — pass the `$serviceId` from the method parameter
- Inside `reconcileActiveForwardsForGroup()` where node picking happens — look up the forward's service_id and pass it

- [ ] **Step 5: Commit**

```bash
git add master/src/Support/ProvisioningService.php
git commit -m "feat(byos): add ownership visibility filter to node selection"
```

---

## Task 7: BYOS — Tenant UI for Token Generation and Node Management

**Files:**
- Modify: `master/src/Controllers/TenantController.php` (add methods + UI section)
- Modify: `master/src/Bootstrap.php` (add routes)

- [ ] **Step 1: Add routes in Bootstrap.php**

After line 164 (`POST .../forwards`), add:

```php
        $this->router->add('POST', '/tenant/services/{id}/enrollment-token', [$tenant, 'generateEnrollmentToken']);
        $this->router->add('POST', '/tenant/nodes/{id}/remove', [$tenant, 'removeUserNode']);
```

- [ ] **Step 2: Add generateEnrollmentToken() method to TenantController**

```php
    public function generateEnrollmentToken(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $serviceId = $this->idParam($params);
        try {
            SessionAuth::validateCsrf($request);
            $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id AND user_id = :user_id LIMIT 1', [
                'id' => $serviceId,
                'user_id' => $user['id'],
            ]);
            if ($service === null) {
                throw new ApiException('service_not_found', 404);
            }
            $summary = $this->provisioning->serviceSummary($serviceId);
            if (empty(($summary['effective_features'] ?? [])['allow_custom_slaves'])) {
                throw new ApiException('feature_denied_custom_slaves', 403);
            }

            $token = bin2hex(random_bytes(32));
            $this->db->insert('node_enrollment_tokens', [
                'token' => $token,
                'service_id' => $serviceId,
                'created_by_user_id' => (int) $user['id'],
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $masterUrl = (string) ($this->config->get('AGENT_API_URL', $this->config->get('APP_URL', '')) ?? '');
            $installCmd = 'curl -fsSL ' . $masterUrl . '/artifacts/install-slave.sh | bash -s -- --master ' . $masterUrl . ' --token ' . $token;
            SessionAuth::flash('success', 'Enrollment token generated (valid 24h). Install command: <code>' . $this->escape($installCmd) . '</code>');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to generate token: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/tenant/services/' . $serviceId);
    }
```

- [ ] **Step 3: Add removeUserNode() method to TenantController**

```php
    public function removeUserNode(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $nodeId = $this->idParam($params);
        try {
            SessionAuth::validateCsrf($request);
            $node = $this->db->fetchOne(
                'SELECT n.*, s.user_id, s.id AS service_id
                 FROM nodes n
                 INNER JOIN services s ON s.id = n.owner_service_id
                 WHERE n.id = :id AND n.enrollment_type = :enrollment_type LIMIT 1',
                ['id' => $nodeId, 'enrollment_type' => 'user_enrolled']
            );
            if ($node === null || (int) $node['user_id'] !== (int) $user['id']) {
                throw new ApiException('node_not_found', 404);
            }

            $this->db->execute('UPDATE nodes SET status = :status, updated_at = :updated_at WHERE id = :id', [
                'status' => 'inactive',
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id' => $nodeId,
            ]);
            SessionAuth::flash('success', 'Node removed.');
            return Response::redirect('/tenant/services/' . (int) $node['service_id']);
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to remove node: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/tenant/services');
    }
```

- [ ] **Step 4: Add "My Slaves" section to serviceDetail()**

In `TenantController::serviceDetail()`, after the forward rules section (around line 1564, before the final `return $this->page(...)` call), add a "My Slaves" section gated by the `allow_custom_slaves` feature flag.

The section should:
1. Query user-enrolled nodes for this service
2. Show a table with: Name, Public IP, Status, Last Heartbeat, Enrolled
3. Show a "Remove" button per node
4. Show an "Add Your Own Slave" button that triggers token generation

```php
        $mySlavesSection = '';
        if (!empty($features['allow_custom_slaves'])) {
            $userNodes = $this->db->fetchAll(
                'SELECT * FROM nodes WHERE owner_service_id = :service_id AND enrollment_type = :enrollment_type ORDER BY created_at DESC',
                ['service_id' => $serviceId, 'enrollment_type' => 'user_enrolled']
            );
            $nodeRows = '';
            foreach ($userNodes as $node) {
                $nodeRows .= '<tr>'
                    . '<td>' . $this->escape((string) $node['name']) . '</td>'
                    . '<td>' . $this->escape((string) $node['public_ip']) . '</td>'
                    . '<td>' . $this->escape((string) $node['status']) . '</td>'
                    . '<td>' . $this->escape((string) ($node['last_heartbeat_at'] ?? 'never')) . '</td>'
                    . '<td>' . $this->escape((string) $node['created_at']) . '</td>'
                    . '<td><form class="inline" method="post" action="/tenant/nodes/' . (int) $node['id'] . '/remove"><input type="hidden" name="csrf_token" value="' . $csrf . '"><button class="button secondary small" type="submit">Remove</button></form></td>'
                    . '</tr>';
            }
            if ($nodeRows === '') {
                $nodeRows = '<tr><td colspan="6" class="muted">No user-enrolled nodes yet.</td></tr>';
            }
            $mySlavesSection = '
            <section class="card" style="margin-top:20px;">
              <h3>My Slaves</h3>
              <p class="muted">Your own VPS machines enrolled as transit nodes for this service.</p>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Name</th><th>Public IP</th><th>Status</th><th>Last Heartbeat</th><th>Enrolled</th><th>Actions</th></tr></thead>
                  <tbody>' . $nodeRows . '</tbody>
                </table>
              </div>
              <div class="actions" style="margin-top:12px;">
                <form class="inline" method="post" action="/tenant/services/' . (int) $service['id'] . '/enrollment-token">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <button class="button" type="submit">Add Your Own Slave</button>
                </form>
              </div>
            </section>';
        }
```

Insert `$mySlavesSection` into the `$body` string, after the Forward Rules section closing `</section>`.

- [ ] **Step 5: Commit**

```bash
git add master/src/Controllers/TenantController.php master/src/Bootstrap.php
git commit -m "feat(byos): add tenant UI for enrollment token generation and node management"
```

---

## Task 8: Panel Simplification — quickCreateForward() Method

**Files:**
- Modify: `master/src/Controllers/TenantController.php:1569-1795` (replace `forwardWizard()`)
- Modify: `master/src/Bootstrap.php:162-163` (update routes)

- [ ] **Step 1: Add the new route and redirect in Bootstrap.php**

Replace lines 162-163:
```php
        $this->router->add('GET', '/tenant/services/{id}/forward-wizard', [$tenant, 'forwardWizard']);
        $this->router->add('POST', '/tenant/services/{id}/forward-wizard', [$tenant, 'forwardWizard']);
```

With:
```php
        $this->router->add('GET', '/tenant/services/{id}/create-forward', [$tenant, 'quickCreateForward']);
        $this->router->add('POST', '/tenant/services/{id}/create-forward', [$tenant, 'quickCreateForward']);
        $this->router->add('GET', '/tenant/services/{id}/forward-wizard', [$tenant, 'forwardWizardRedirect']);
        $this->router->add('POST', '/tenant/services/{id}/forward-wizard', [$tenant, 'forwardWizardRedirect']);
```

- [ ] **Step 2: Add forwardWizardRedirect() method**

```php
    public function forwardWizardRedirect(Request $request, array $params): Response
    {
        $serviceId = $this->idParam($params);
        return Response::redirect('/tenant/services/' . $serviceId . '/create-forward');
    }
```

- [ ] **Step 3: Add quickCreateForward() method**

This is the single-page replacement. Essential fields (target host, target port, connection mode, sidecar point) are always visible. All other fields are in a `<details>` collapse.

```php
    public function quickCreateForward(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $serviceId = $this->idParam($params);
        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id AND user_id = :user_id LIMIT 1', [
            'id' => $serviceId,
            'user_id' => $user['id'],
        ]);
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }

        $summary = $this->provisioning->serviceSummary($serviceId);
        $features = $summary['effective_features'] ?? [];
        $bondReady = $this->isBondReady($service);
        $availableSidecarPoints = is_array($summary['available_sidecar_points'] ?? null) ? $summary['available_sidecar_points'] : [];
        $hasSidecarPoints = $availableSidecarPoints !== [];

        // Handle POST — create the forward
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                SessionAuth::validateCsrf($request);
                if (empty($features['allow_manage_forwards'])) {
                    throw new ApiException('feature_denied_manage_forwards', 403);
                }
                $payload = $this->normalizeTenantForwardPayload($request->body());
                $this->provisioning->createForward($serviceId, $payload, 'tenant');
                SessionAuth::flash('success', 'Forward created.');
                return Response::redirect('/tenant/services/' . $serviceId);
            } catch (\Throwable $throwable) {
                SessionAuth::flash('error', $this->forwardCreateFlashMessage($throwable));
            }
        }

        // Build option lists
        $allowedGroups = $summary['allowed_node_groups'] ?? [];
        $availableClients = $summary['available_clients'] ?? [];
        $availableChains = $summary['available_forward_chains'] ?? [];
        $allowedClientGroups = $summary['allowed_client_groups'] ?? [];

        $clientOptions = '<option value="">Auto choose</option>';
        foreach ($availableClients as $client) {
            $clientOptions .= '<option value="' . (int) $client['id'] . '">' . $this->escape((string) $client['name']) . ' - ' . $this->escape((string) $client['display_host']) . '</option>';
        }
        $clientGroupOptions = '';
        foreach ($allowedClientGroups as $group) {
            $clientGroupOptions .= '<option value="' . (int) $group['id'] . '">' . $this->escape((string) $group['name']) . '</option>';
        }
        $groupOptions = '<option value="">Auto choose</option>';
        foreach ($allowedGroups as $group) {
            $groupOptions .= '<option value="' . $this->escape((string) $group['group_key']) . '">' . $this->escape((string) $group['name']) . '</option>';
        }
        $chainOptions = '<option value="">No chain</option>';
        foreach ($availableChains as $chain) {
            $chainOptions .= '<option value="' . (int) $chain['id'] . '">' . $this->escape((string) $chain['name']) . '</option>';
        }
        $sidecarPointOptions = $this->renderSidecarPointOptions($availableSidecarPoints);

        // Determine available connection modes
        $modeOptions = '<option value="single">Single (Direct)</option><option value="race">Race (Failover)</option>';
        if ($bondReady) {
            $modeOptions .= '<option value="bond">Bond (Aggregation)</option>';
            if ($hasSidecarPoints) {
                $modeOptions .= '<option value="mptcp">MPTCP (Kernel Multipath)</option>';
            }
        }

        // Count available modes for auto-selection
        $modeCount = 2; // single + race always
        if ($bondReady) {
            $modeCount++;
            if ($hasSidecarPoints) {
                $modeCount++;
            }
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $defaultBondRemotePort = $this->defaultBondRemotePort();

        $sidecarField = '';
        if ($bondReady && $hasSidecarPoints) {
            $sidecarField = '
                <div class="field" id="qcf-sidecar-field" style="display:none;">
                  <label>Sidecar Point</label>
                  <select id="qcf-sidecar-point" name="bond_sidecar_point_id">' . $sidecarPointOptions . '</select>
                  <p class="field-note">Required for Bond/MPTCP modes.</p>
                </div>';
        }

        $body = '
            <div class="hero">
              <div>
                <h2>Create Forward</h2>
                <p class="muted">Specify your target and create a forward rule. Advanced options are auto-filled with safe defaults.</p>
              </div>
            </div>
            <div class="card">
              ' . (empty($features['allow_manage_forwards']) ? '<div class="flash danger">Forward creation is disabled for your plan/account.</div>' : '') . '
              <form method="post" action="/tenant/services/' . $serviceId . '/create-forward">
                <input type="hidden" name="csrf_token" value="' . $csrf . '">
                <div class="form-grid">
                  <div class="field"><label>Target Host <span style="color:var(--danger)">*</span></label><input type="text" name="target_host" placeholder="10.0.0.5 or example.com" required></div>
                  <div class="field"><label>Target Port <span style="color:var(--danger)">*</span></label><input type="number" name="target_port" placeholder="443" required></div>
                  <div class="field"><label>Connection Mode</label>
                    <select id="qcf-client-mode" name="client_mode">' . $modeOptions . '</select>
                    <p class="field-note">Single = simplest. Race = failover.' . ($bondReady ? ' Bond = aggregation. MPTCP = kernel multipath.' : '') . '</p>
                  </div>
                  ' . $sidecarField . '
                </div>

                <details style="margin-top:20px;">
                  <summary style="cursor:pointer;font-weight:600;color:var(--text-muted);">Advanced Options</summary>
                  <div class="form-grid" style="margin-top:12px;">
                    <div class="field"><label>Line Client</label><select name="line_client_id" ' . ($availableClients === [] || empty($features['allow_select_specific_line_client']) ? 'disabled' : '') . '>' . $clientOptions . '</select></div>
                    <div class="field"><label>Line Client Groups</label><select name="line_client_group_ids[]" ' . (!empty($features['allow_select_multiple_line_groups']) ? 'multiple size="4"' : '') . '>' . $clientGroupOptions . '</select></div>
                    <div class="field"><label>Line Group Mode</label>
                      <select name="line_group_mode">
                        <option value="selected">selected groups only</option>
                        ' . (!empty($features['allow_use_all_line_groups']) ? '<option value="all_allowed">all allowed groups</option>' : '') . '
                      </select>
                    </div>
                    <div class="field"><label>Slave Group</label><select name="node_group">' . $groupOptions . '</select></div>
                    <div class="field"><label>Forward Chain</label><select name="forward_chain_id" ' . (empty($features['allow_forward_chain']) ? 'disabled' : '') . '>' . $chainOptions . '</select></div>
                    <div class="field"><label>Transport</label>
                      <select name="transport_mode">
                        <option value="tcp" selected>TCP</option>
                        <option value="tcp+udp">TCP + UDP</option>
                        <option value="udp">UDP</option>
                      </select>
                    </div>
                    <div class="field"><label>Local Listen Port</label><input type="number" name="listen_port" placeholder="auto from line range"><p class="field-note">Leave empty for auto.</p></div>
                    <div class="field"><label>Route Mode</label><select name="endpoint_mode">' . $this->renderEndpointModeOptions('slave_group', !empty($features['allow_forward_chain'])) . '</select></div>
                    <div class="field"><label>Sidecar Host</label><input type="text" name="bond_remote_host" placeholder="manual override"><p class="field-note">Only for Bond/MPTCP without a saved sidecar point.</p></div>
                    <div class="field"><label>Sidecar Port</label><input type="number" name="bond_remote_port" value="' . $defaultBondRemotePort . '" placeholder="' . $defaultBondRemotePort . '"></div>
                    <div class="field"><label>Filter Profile</label>
                      <select name="filter_profile">
                        <option value="off">off</option>
                        <option value="log-only">log-only</option>
                        <option value="drop-suspicious">drop-suspicious</option>
                      </select>
                    </div>
                  </div>
                </details>

                <div class="actions" style="margin-top:20px;">
                  <button class="button" type="submit" ' . (empty($features['allow_manage_forwards']) ? 'disabled' : '') . '>Create Forward</button>
                  <a class="button ghost" href="/tenant/services/' . $serviceId . '">Cancel</a>
                </div>
              </form>
            </div>
            <script>
            (function(){
              var mode = document.getElementById("qcf-client-mode");
              var sidecar = document.getElementById("qcf-sidecar-field");
              if (mode && sidecar) {
                function toggle() {
                  sidecar.style.display = (mode.value === "bond" || mode.value === "mptcp") ? "" : "none";
                }
                mode.addEventListener("change", toggle);
                toggle();
              }
            })();
            </script>';

        return $this->page('Create Forward', $body, $user, $this->tenantNav('wizard', $serviceId));
    }
```

- [ ] **Step 4: Update the service detail page link**

In `serviceDetail()`, change the wizard link (around line 1490) from:
```php
<a class="button" href="/tenant/services/' . (int) $service['id'] . '/forward-wizard">Open Guided Setup</a>
```
to:
```php
<a class="button" href="/tenant/services/' . (int) $service['id'] . '/create-forward">Create New Forward</a>
```

- [ ] **Step 5: Commit**

```bash
git add master/src/Controllers/TenantController.php master/src/Bootstrap.php
git commit -m "feat(panel): replace 3-step wizard with single-page quick create form"
```

---

## Task 9: Final Review and Integration Commit

- [ ] **Step 1: Verify all files are consistent**

Check that:
- The watchdog script is at `artifacts/leikwan-wg-watchdog.sh` with execute permission
- The systemd units are in `deploy/systemd/`
- All three install scripts have the watchdog block
- The migration file parses as valid SQL
- NodeController handles both global and BYOS tokens
- ProvisioningService threads `$serviceId` through node selection
- TenantController has `quickCreateForward()`, `forwardWizardRedirect()`, `generateEnrollmentToken()`, `removeUserNode()`
- Bootstrap.php has all new routes

- [ ] **Step 2: Check for no unintended changes**

Run: `git diff --stat`
Expected: Only the files listed in the File Map above are modified/created.

- [ ] **Step 3: Final commit if any adjustments were made**

```bash
git add -A
git commit -m "chore: final integration adjustments for watchdog/panel/byos"
```
