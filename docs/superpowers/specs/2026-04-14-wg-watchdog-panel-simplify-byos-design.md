# WireGuard Watchdog, Panel Simplification, and BYOS Design

**Date:** 2026-04-14
**Status:** approved design

## Problem

1. **WireGuard tunnels can silently die.** If a peer's handshake goes stale (network change, transient outage, NAT timeout), the tunnel stops passing traffic. There is no detection or automatic recovery — the operator must manually notice and restart `wg-quick@<iface>`.

2. **The forward creation wizard is too complex.** The 3-step wizard exposes ~15 fields across three pages. Most users just need to specify a target host:port and pick a connection mode — everything else can be auto-filled.

3. **Users cannot bring their own slave nodes.** The `allow_custom_slaves` feature flag exists but has no implementation. Users who have their own VPS machines cannot enroll them as transit nodes for their service.

---

## Feature 1: WireGuard Watchdog

### Architecture

A standalone bash script (`leikwan-wg-watchdog.sh`) invoked by a systemd timer every 60 seconds. Runs on all three roles: client, sidecar, and slave.

### Detection Logic

```bash
# For each WG interface matching the lw-* or wg* pattern:
wg show <iface> latest-handshakes
# Parse each peer's handshake timestamp
# If (now - handshake_epoch) > STALE_THRESHOLD_SECONDS → restart
```

- Default `STALE_THRESHOLD_SECONDS`: 180 (3 minutes)
- Configurable via `/etc/leikwan/wg-watchdog.conf` (optional file, sourced if present)
- Only monitors interfaces that are currently up (have at least one peer)

### Recovery Action

```bash
systemctl restart wg-quick@<iface>.service
```

Log the event to journald:
```
leikwan-wg-watchdog: restarted wg-quick@lw-mptcp-42-0 — peer Abc...xyz handshake stale (245s > 180s threshold)
```

### Rate Limiting

- Track last-restart timestamp per interface in `/run/leikwan-wg-watchdog/`
- Don't restart the same interface more than once per 5 minutes (300s cooldown)
- This prevents restart loops when the peer is genuinely unreachable

### Systemd Units

**`leikwan-wg-watchdog.service`:**
```ini
[Unit]
Description=Leikwan WireGuard Watchdog
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/leikwan-wg-watchdog.sh
```

**`leikwan-wg-watchdog.timer`:**
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

### Deployment

The watchdog is installed automatically when a forward is provisioned:

- **Client:** `install-client.sh` downloads `leikwan-wg-watchdog.sh`, writes the systemd units, enables the timer
- **Slave:** `install-slave.sh` does the same
- **Sidecar:** `install-bond-sidecar.sh` (or the sidecar agent setup path) does the same

The watchdog script is also served from the panel artifacts endpoint so agents can auto-update it during heartbeat pulls.

### Files

| File | Description |
|------|-------------|
| `artifacts/leikwan-wg-watchdog.sh` | The watchdog bash script |
| `deploy/systemd/leikwan-wg-watchdog.service` | Systemd oneshot unit |
| `deploy/systemd/leikwan-wg-watchdog.timer` | Systemd timer (60s interval) |
| `install/install-client.sh` | Modified to install watchdog |
| `install/install-slave.sh` | Modified to install watchdog |
| `install/install-bond-sidecar.sh` | Modified to install watchdog |

---

## Feature 2: Panel Simplification

### Current State

3-step wizard (`forwardWizard()` in TenantController) with ~15 fields across 3 pages:
- Step 1: Line Group Mode, Line Client, Line Client Groups, Route Mode
- Step 2: Slave Group, Forward Chain, Transport, Connection Mode, Sidecar Point, Filter Profile
- Step 3: Listen Port, Target Host, Target Port, Sidecar Host/Port manual entry

### New Design: Single-Page Quick Create

Replace the 3-step wizard with a single form divided into two zones.

**Essential fields (always visible):**

| Field | Default | Notes |
|-------|---------|-------|
| Target Host | (required) | The backend address |
| Target Port | (required) | The backend port |
| Connection Mode | auto-detected | Dropdown only shows modes the service can use |
| Sidecar Point | first available | Only shown when bond/mptcp selected |

**Advanced options (collapsed by default):**

| Field | Default | Notes |
|-------|---------|-------|
| Line Client | first available | Auto-selected |
| Line Client Group | from client default | Auto-selected |
| Line Group Mode | `selected` | |
| Slave Group | from client default or first allowed | |
| Forward Chain | none | |
| Transport Mode | `tcp` | |
| Listen Port | auto from range | |
| Public Port | auto | |
| SNI Host | from config default | |
| CDN Host | from config default | |
| Filter Profile | `off` | |
| Sidecar Host/Port | from sidecar point | Manual override |

**Auto-detect available modes:**
- If service has no bond token secret → hide bond and mptcp from dropdown
- If service has no sidecar points → hide mptcp from dropdown
- If only one mode is available → pre-select it, skip the dropdown
- The dropdown shows user-friendly labels: "Single (Direct)", "Race (Failover)", "Bond (Aggregation)", "MPTCP (Kernel Multipath)"

### Implementation

- Replace `forwardWizard()` in `TenantController.php` with `quickCreateForward()`
- Single GET renders the form, single POST handles creation
- The `<details>` HTML element provides the collapse/expand for advanced options (no JS needed)
- Backend `ProvisioningService::saveForward()` is unchanged — all the same defaults and validation apply
- Route changes: replace `/tenant/services/{id}/forward-wizard` with `/tenant/services/{id}/create-forward` (keep the old route as a redirect for bookmarks)

### Files

| File | Description |
|------|-------------|
| `master/src/Controllers/TenantController.php` | Replace wizard with single-page form |

---

## Feature 3: Bring Your Own Slave (BYOS)

### Database Changes

**`nodes` table — new columns:**

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `owner_service_id` | INT NULL | NULL | When set, this node is owned by that service |
| `enrollment_type` | VARCHAR(32) | `'platform'` | `'platform'` (admin-managed) or `'user_enrolled'` |

**New table: `node_enrollment_tokens`**

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT AUTO_INCREMENT PK | |
| `token` | VARCHAR(64) UNIQUE | The enrollment token (random hex) |
| `service_id` | INT | Which service this token enrolls into |
| `created_by_user_id` | INT | Who generated it |
| `expires_at` | DATETIME | Token expiry (e.g., 24 hours from creation) |
| `used_at` | DATETIME NULL | Set when consumed |
| `used_by_node_id` | INT NULL | Set when consumed |
| `created_at` | DATETIME | |

### Enrollment Flow

1. **Tenant generates token:** Panel UI shows "Add Your Own Slave" button on the service page. Clicking it generates a token (32-byte random hex, 24h expiry) stored in `node_enrollment_tokens`.

2. **Panel shows install command:**
   ```bash
   curl -fsSL https://panel.example.com/artifacts/install-slave.sh | bash -s -- \
     --master https://panel.example.com \
     --token <enrollment-token>
   ```

3. **Slave runs install-slave.sh:** The existing script calls `/api/slave/register` with the token.

4. **Registration API validates token:**
   - Token exists, not expired, not already used
   - Creates node with `owner_service_id = token.service_id` and `enrollment_type = 'user_enrolled'`
   - Marks token as used

5. **Node heartbeats normally** after registration. The slave agent doesn't need to know it's user-owned — that's purely a panel-side concept.

### Node Visibility Rules

Modify `pickNodes()` and `pickNodesForGroups()` in `ProvisioningService.php`:

```sql
-- Existing WHERE clause gets an additional condition:
AND (n.owner_service_id IS NULL OR n.owner_service_id = :current_service_id)
```

This ensures:
- Platform nodes (owner_service_id IS NULL) are visible to everyone
- User-owned nodes are only visible to the owning service
- No cross-tenant data leaks

### Tenant Panel UI

- New "My Slaves" section on the service detail page
- Shows: node name, public IP, status, last heartbeat, enrollment date
- "Add Your Own Slave" button → generates token → shows install command
- "Remove" button → marks node as inactive (soft delete, does not destroy the remote machine)

### Feature Gate

Controlled by the existing `allow_custom_slaves` feature flag. When `false` (default), the "My Slaves" section and enrollment token generation are hidden.

### install-slave.sh Changes

The script already sends the enrollment token to the register API. The only change needed is on the **server side** — the register API handler in `NodeController.php` needs to:
1. Look up the token in `node_enrollment_tokens`
2. If found and valid, set `owner_service_id` and `enrollment_type` on the created node
3. Mark the token as used

No changes to the script itself are needed — the token already encodes the ownership implicitly.

### Files

| File | Description |
|------|-------------|
| `master/src/Support/ProvisioningService.php` | Add BYOS enrollment, modify pickNodes visibility |
| `master/src/Controllers/TenantController.php` | Add "My Slaves" UI section |
| `master/src/Controllers/NodeController.php` | Modify register API to handle BYOS tokens |
| DB migration | Add columns to `nodes`, create `node_enrollment_tokens` |

---

## Verification Criteria

### WireGuard Watchdog
- [ ] Timer fires every 60s on client, sidecar, and slave
- [ ] Script detects stale handshake (>180s) and restarts the interface
- [ ] Rate limiting prevents restart loops (5-min cooldown per interface)
- [ ] Events logged to journald with interface name and stale duration
- [ ] Installed automatically by all three install scripts

### Panel Simplification
- [ ] Single-page form with only target host:port as required fields
- [ ] Advanced options hidden by default, expandable
- [ ] Connection mode dropdown auto-filtered to available modes
- [ ] Creating a forward works with just target host:port (all defaults)
- [ ] Old wizard URL redirects to new form

### BYOS
- [ ] Tenant can generate enrollment token (gated by `allow_custom_slaves`)
- [ ] Install command shown after token generation
- [ ] Slave registers with ownership via enrollment token
- [ ] User-owned nodes only visible to owning service
- [ ] Platform nodes remain visible to all services
- [ ] Tenant can see and remove their own slaves
