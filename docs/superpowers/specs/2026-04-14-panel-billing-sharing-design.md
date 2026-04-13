# Panel Billing, Slave Sharing & Quick-Fix Design

**Date:** 2026-04-14  
**Status:** Draft  
**Implementation order:** Module C → Module B → Module A

---

## Overview

Five user-facing changes to the Leikwan panel, grouped into three modules by complexity and dependency:

| Module | Scope | Depends on |
|--------|-------|-----------|
| **C** — Quick fixes | Remove SNI field, default TCP+UDP, MPTCP kernel warning | Nothing |
| **B** — Flexible billing | Per-node/per-sidecar traffic pricing, wallet auto-stop | Nothing |
| **A** — Slave sharing groups | User-created sharing groups with invite codes | Module B (for billing exemption logic) |

---

## Module C: Quick Fixes

Three small changes to `TenantController::quickCreateForward()` and `forwardWizard()`.

### C1: Remove SNI Host field from creation form

**Problem:** The `sni_host` and `cdn_host` fields in the forward creation form should not be user-configurable — they are infrastructure concerns.

**Change:**
- Delete the `sni_host` input field from `quickCreateForward()` (currently line 1742)
- Delete the `cdn_host` input field from `quickCreateForward()` (currently line 1743)
- Delete the same fields from `forwardWizard()` if present
- No backend changes needed — `normalizeTenantForwardPayload()` will use config defaults when these fields are absent from POST data

**Files:** `master/src/Controllers/TenantController.php`

### C2: Default transport mode to TCP+UDP

**Problem:** New forwards default to TCP only. Users almost always want TCP+UDP.

**Change:**
- In `quickCreateForward()`, move `selected` attribute from `<option value="tcp">` to `<option value="tcp+udp">` (line 1735)
- In `forwardWizard()`, change the default value of `transport_mode` from `'tcp'` to `'tcp+udp'` (line 1814)

**Files:** `master/src/Controllers/TenantController.php`

### C3: MPTCP kernel requirement warning

**Problem:** MPTCP requires Linux kernel 6.0+ on the client side. Users should be informed before selecting this mode.

**Change:**
- Add a hidden `<p>` element with id `qcf-mptcp-warning` below the sidecar field:
  ```html
  <p id="qcf-mptcp-warning" class="field-note" style="display:none;color:var(--warning);">
    ⚠ MPTCP requires the client machine to run Linux with kernel 6.0 or above.
  </p>
  ```
- Extend the existing JS toggle function to show this warning when `mode.value === "mptcp"` and hide it otherwise
- The warning appears inside the main form area (not inside Advanced Options) since MPTCP mode selection is a primary field

**Files:** `master/src/Controllers/TenantController.php`

---

## Module B: Flexible Billing

### B1: Data model changes

#### New table: `node_traffic_prices`

Stores per-resource traffic pricing. Two sub-tables in one: slave node prices and sidecar prices, each with node-level and group-level tiers.

```sql
CREATE TABLE traffic_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_type ENUM('slave', 'sidecar') NOT NULL,
    -- For slave: references nodes.id / node_groups.id
    -- For sidecar: references sidecar_points.id (node_id column) / NULL (no group-level for sidecars)
    node_id INT NULL COMMENT 'specific node or sidecar_point ID',
    node_group_id INT NULL COMMENT 'node_group ID (slave only)',
    price_per_gb DECIMAL(10, 4) NOT NULL DEFAULT 0.0000,
    currency VARCHAR(8) NOT NULL DEFAULT 'CNY',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

**Uniqueness enforced by application layer** (MySQL does not enforce UNIQUE on nullable columns reliably). Before INSERT/UPDATE, check that no duplicate `(resource_type, node_id)` or `(resource_type, node_group_id)` exists.

**Constraint rules:**
- Exactly one of `node_id` or `node_group_id` must be non-NULL per row
- For `resource_type = 'sidecar'`: `node_id` references `sidecar_points.id`, `node_group_id` is always NULL (no group concept for sidecars)
- For `resource_type = 'slave'`: `node_id` references `nodes.id`, `node_group_id` references `node_groups.id`

#### New table: `service_plan_sidecar_points`

Maps which sidecar points each plan is allowed to use (mirrors existing `service_plan_node_groups` pattern).

```sql
CREATE TABLE service_plan_sidecar_points (
    plan_id INT NOT NULL,
    sidecar_point_id INT NOT NULL,
    PRIMARY KEY (plan_id, sidecar_point_id)
);
```

#### Modify `users` table

```sql
ALTER TABLE users
    ADD COLUMN wallet_low_balance_threshold DECIMAL(10,2) NOT NULL DEFAULT 5.00,
    ADD COLUMN wallet_warning_sent TINYINT(1) NOT NULL DEFAULT 0;
```

### B2: Price lookup logic

New method `ProvisioningService::resolveTrafficPrice(int $resourceId, string $resourceType): ?array`

```
For resource_type = 'slave':
  1. SELECT price_per_gb, currency FROM traffic_prices
     WHERE node_id = :resourceId AND resource_type = 'slave'
  2. If no result, look up the node's group_id, then:
     SELECT price_per_gb, currency FROM traffic_prices
     WHERE node_group_id = :groupId AND resource_type = 'slave'
  3. If still no result, return null (free / no price configured)

For resource_type = 'sidecar':
  1. SELECT price_per_gb, currency FROM traffic_prices
     WHERE node_id = :resourceId AND resource_type = 'sidecar'
  2. If no result, return null (no group fallback for sidecars)
```

### B3: Hourly billing deduction

New CLI script `master/cli/billing-deduct.php`, run by cron every hour.

**Algorithm:**
1. For each active service with `billing_mode = 'metered'` or wallet-based billing:
2. Query `traffic_usage_hourly` for the last hour's traffic delta, grouped by `node_id`
3. For each node's traffic increment:
   - Call `resolveTrafficPrice(nodeId, resourceType)` to get unit price
   - Skip if price is null (free node — user-owned or unpriced)
   - Calculate: `cost = bytes_delta / (1024^3) × price_per_gb`
4. Sum all costs for the user
5. Deduct from `users.wallet_balance` in a transaction
6. Insert a `wallet_transactions` row with breakdown JSON
7. **Warning check:** if `wallet_balance < wallet_low_balance_threshold` and `wallet_warning_sent = 0`:
   - Set `wallet_warning_sent = 1`
   - (Future: could send email/webhook notification)
8. **Auto-stop check:** if `wallet_balance <= 0`:
   - `UPDATE forwards SET enabled = 0 WHERE service_id IN (user's services)`
   - Insert `audit_logs` entry: "auto-suspended due to insufficient balance"

**Recovery:** When user tops up and `wallet_balance > 0`:
- Reset `wallet_warning_sent = 0`
- Show a "Reactivate All Forwards" button on the billing page that sets `enabled = 1`

### B4: Admin pricing UI

#### Admin → Node Groups page

Add a price field per group:
- "Traffic Price (per GB)" — saves to `traffic_prices` with `node_group_id` set, `resource_type = 'slave'`

#### Admin → Nodes page

Per-node override:
- "Traffic Price Override (per GB)" — saves to `traffic_prices` with `node_id` set
- Show "(group default: X.XX/GB)" hint when no override is set

#### Admin → Sidecars page

Per-sidecar pricing:
- "Traffic Price (per GB)" — saves to `traffic_prices` with `resource_type = 'sidecar'`, `node_id` = sidecar_point ID
- Admin-owned sidecars get priced here; user-owned sidecars are free

#### Admin → Plans page

Add sidecar point selection:
- Multi-select checkboxes for allowed sidecar points (same pattern as existing node group checkboxes)
- Saves to `service_plan_sidecar_points`

### B5: Tenant billing page enhancements

On `TenantController::billingPage()`:

1. **Balance display** — prominent card showing current `wallet_balance` with color coding:
   - Green: balance > threshold
   - Yellow: balance ≤ threshold
   - Red: balance ≤ 0 (suspended)

2. **Low balance warning bar** — shown site-wide (in `BaseController::page()`) when `wallet_warning_sent = 1`:
   ```
   ⚠ Your balance is low (¥X.XX). Top up to avoid service interruption.
   ```

3. **Usage breakdown** — table showing this billing cycle's traffic per node/sidecar:
   | Node | Region | Traffic | Unit Price | Cost |
   |------|--------|---------|-----------|------|
   | HK-01 | Hong Kong | 15.2 GB | ¥0.50/GB | ¥7.60 |
   | Sidecar-SZ | Shenzhen | 8.1 GB | ¥0.30/GB | ¥2.43 |

4. **Reactivation button** — when balance > 0 but forwards are suspended:
   ```
   Your forwards were suspended due to insufficient balance. [Reactivate All]
   ```

---

## Module A: Slave Sharing Groups

### A1: Data model

#### New table: `user_slave_groups`

```sql
CREATE TABLE user_slave_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    owner_user_id INT NOT NULL,
    invite_code VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invite_code (invite_code)
);
```

#### New table: `user_slave_group_members`

```sql
CREATE TABLE user_slave_group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('owner', 'member') NOT NULL DEFAULT 'member',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_user (group_id, user_id)
);
```

#### Modify `nodes` table

```sql
ALTER TABLE nodes
    ADD COLUMN sharing_mode ENUM('private', 'group_shared') NOT NULL DEFAULT 'private',
    ADD COLUMN user_slave_group_id INT NULL;
```

- `sharing_mode = 'private'`: only the owning service can use this node
- `sharing_mode = 'group_shared'`: all members of `user_slave_group_id` can use this node

### A2: Node visibility rules

Update `ProvisioningService::pickNodes()` and `pickNodesForGroups()`:

```
For a given service (owned by user U):

1. Admin platform nodes (owner_service_id IS NULL):
   → Visible per plan's allowed_node_groups (existing logic, unchanged)
   → Traffic IS billed

2. User's own nodes (owner_service_id = service.id, sharing_mode = 'private'):
   → Always visible to this service
   → Traffic is NOT billed

3. Group-shared nodes (sharing_mode = 'group_shared'):
   → Visible if user U is a member of the node's user_slave_group_id
   → Traffic is NOT billed

4. Other users' private nodes:
   → Never visible
```

### A3: Tenant UI — Slave Groups page

New route: `GET /tenant/slave-groups` → `TenantController::slaveGroupsPage()`  
New route: `POST /tenant/slave-groups` → `TenantController::saveSlaveGroup()`  
New route: `POST /tenant/slave-groups/join` → `TenantController::joinSlaveGroup()` (by invite code)  
New route: `POST /tenant/slave-groups/{id}/leave` → `TenantController::leaveSlaveGroup()`  
New route: `POST /tenant/slave-groups/{id}/kick` → `TenantController::kickSlaveGroupMember()`

**Page layout:**

```
┌─────────────────────────────────────────────┐
│ My Slave Groups                             │
├─────────────────────────────────────────────┤
│ [Create New Group]  [Join Group by Code]    │
├─────────────────────────────────────────────┤
│ ┌─ Group: "Team Alpha" (Owner) ──────────┐  │
│ │ Invite Code: abc123  [Copy]            │  │
│ │ Members: you, user2@mail.com           │  │
│ │ Shared Slaves: HK-VPS-01, US-VPS-02   │  │
│ │ [Manage Members]                       │  │
│ └────────────────────────────────────────┘  │
│ ┌─ Group: "Friends" (Member) ────────────┐  │
│ │ Owner: user3@mail.com                  │  │
│ │ Shared Slaves: JP-VPS-01              │  │
│ │ [Leave Group]                          │  │
│ └────────────────────────────────────────┘  │
└─────────────────────────────────────────────┘
```

**Create group flow:**
1. User enters group name
2. System generates random 12-char invite code
3. Owner is automatically added as member with `role = 'owner'`

**Join group flow:**
1. User enters invite code
2. System validates code, adds user as `role = 'member'`
3. User can now see group-shared nodes from that group

### A4: Slave sharing controls

On the existing "My Slaves" page (`/tenant/clients` for BYOS nodes):

Each user-owned node gets a sharing dropdown:
- **Private** — only my services can use this node
- **Share to [group name]** — dropdown of user's groups

Saving updates `nodes.sharing_mode` and `nodes.user_slave_group_id`.

### A5: Forward creation integration

In `quickCreateForward()`, the Slave Group dropdown is restructured:

```html
<select name="node_group">
  <option value="">Auto choose</option>
  <optgroup label="Platform Nodes">
    <option value="hk">Hong Kong</option>
    <option value="us">United States</option>
  </optgroup>
  <optgroup label="My Slaves">
    <option value="_user_node_15">HK-VPS-01 (private)</option>
  </optgroup>
  <optgroup label="Shared — Team Alpha">
    <option value="_user_node_22">US-VPS-02 (shared by user2)</option>
  </optgroup>
</select>
```

User-owned and shared nodes use a `_user_node_{id}` value prefix to distinguish them from platform `node_group_key` values. The backend `createForward()` detects this prefix and routes accordingly — setting the forward's node assignment to a specific node rather than a group.

### A6: Admin management

New route: `GET /admin/user-groups` → `AdminController::userSlaveGroupsPage()`

Admin can:
- View all user-created slave groups
- See members and shared nodes per group
- Force-remove members or delete groups
- Override sharing settings on any node

---

## Migration plan

### Migration 1 (Module C): No migration needed

### Migration 2 (Module B): `20260414_add_flexible_billing.sql`

```sql
CREATE TABLE traffic_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_type ENUM('slave', 'sidecar') NOT NULL,
    node_id INT NULL COMMENT 'nodes.id (slave) or sidecar_points.id (sidecar)',
    node_group_id INT NULL COMMENT 'node_groups.id (slave only)',
    price_per_gb DECIMAL(10, 4) NOT NULL DEFAULT 0.0000,
    currency VARCHAR(8) NOT NULL DEFAULT 'CNY',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE service_plan_sidecar_points (
    plan_id INT NOT NULL,
    sidecar_point_id INT NOT NULL,
    PRIMARY KEY (plan_id, sidecar_point_id)
);

ALTER TABLE users
    ADD COLUMN wallet_low_balance_threshold DECIMAL(10,2) NOT NULL DEFAULT 5.00,
    ADD COLUMN wallet_warning_sent TINYINT(1) NOT NULL DEFAULT 0;
```

### Migration 3 (Module A): `20260414_add_user_slave_groups.sql`

```sql
CREATE TABLE user_slave_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    owner_user_id INT NOT NULL,
    invite_code VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invite_code (invite_code)
);

CREATE TABLE user_slave_group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('owner', 'member') NOT NULL DEFAULT 'member',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_user (group_id, user_id)
);

ALTER TABLE nodes
    ADD COLUMN sharing_mode ENUM('private', 'group_shared') NOT NULL DEFAULT 'private',
    ADD COLUMN user_slave_group_id INT NULL;
```

---

## Files affected (summary)

| Module | File | Changes |
|--------|------|---------|
| C | `master/src/Controllers/TenantController.php` | Remove SNI fields, change transport default, add MPTCP warning |
| B | `master/migrations/20260414_add_flexible_billing.sql` | New tables + ALTER |
| B | `master/src/Support/ProvisioningService.php` | Price lookup, billing deduction logic |
| B | `master/src/Controllers/AdminController.php` | Pricing fields on node/group/sidecar/plan pages |
| B | `master/src/Controllers/TenantController.php` | Billing page enhancements, low-balance warning |
| B | `master/src/Controllers/BaseController.php` | Site-wide low-balance warning bar |
| B | `master/cli/billing-deduct.php` | New hourly billing script |
| A | `master/migrations/20260414_add_user_slave_groups.sql` | New tables + ALTER |
| A | `master/src/Support/ProvisioningService.php` | Node visibility rules update |
| A | `master/src/Controllers/TenantController.php` | Slave groups page, sharing controls, forward creation dropdown |
| A | `master/src/Controllers/AdminController.php` | User groups admin page |
| A | `master/src/Bootstrap.php` | New routes |
