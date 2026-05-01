# Panel Features Design - 2026-04-16

## Overview

Add three features to the tenant and admin panels:
1. **Edit Forward** - Allow tenants to edit non-structural properties of existing forwards
2. **Republish** - Allow admins to republish forward configurations at multiple scopes
3. **Diagnostics** - Admin-only diagnostic dashboard showing forward health status

## Approach

**Selected:** Approach A (Minimal Inline Integration)

This approach follows existing patterns in the codebase (server-rendered PHP, inline edit forms with `?edit=ID`), minimizes code changes, and delivers all required functionality quickly with low risk.

---

## Feature 1: Edit Forward (Tenant Panel)

### Goal
Allow tenants to edit non-structural properties of existing forwards.

### Editable Fields
- `target_host` - target server hostname/IP
- `target_port` - target server port
- `path_prefix` - URL path prefix (for HTTP endpoints)
- `speed_limit_mbps` - bandwidth limit in Mbps

### Non-editable Fields (locked)
- Client mode (single/race/bond/mptcp)
- Endpoint mode (relay/tunnel/bond)
- Node group selection
- Client/Sidecar selection
- Protocol type

### UI Flow
1. In the service detail page, each forward row gets an "Edit" button (icon or text)
2. Clicking opens the existing edit form inline (same pattern as client/sidecar editing with `?edit=ID`)
3. Form pre-fills with current forward values
4. Only editable fields are shown and enabled
5. On submit: validate input → call `ProvisioningService::updateForward()` → auto-republish endpoints

### Backend Changes
- New `ProvisioningService::updateForward(forwardId, data)` method
- Calls `reconcileForwardEndpoints()` after update to apply changes
- Validation: target_host must be valid hostname/IP, target_port must be 1-65535, speed_limit must be >= 0

### Error Handling
- If forward is in "pending" state, show error "Cannot edit forward while provisioning is in progress"
- If user doesn't own the forward, show 403 error
- On validation failure, show field-level error messages

### Routes
- `GET /tenant/service/:id/forward/:fid/edit` - edit forward form
- `POST /tenant/service/:id/forward/:fid/edit` - submit edit form

---

## Feature 2: Republish (Admin Panel)

### Goal
Allow admins to trigger republish of forward configurations at multiple scopes.

### Scopes
1. **Per Forward** - Republish config for a single forward (auto-triggered on edit)
2. **Per Service** - Republish all forwards belonging to a service
3. **Per Node** - Republish all forwards using a specific node
4. **Per Client** - Republish all forwards for a specific client agent
5. **Global** - Republish all active forwards in the system

### UI Locations
- Per forward: edit form submit already triggers republish (auto)
- Services page: "Republish" button on each service row
- Nodes page: "Republish" button on each node row
- Clients page: "Republish" button on each client row
- Dashboard: "Republish All Forwards" button

### Backend Changes
- New route: `POST /admin/republish/service/:id` - republish by service
- New route: `POST /admin/republish/node/:id` - republish by node
- New route: `POST /admin/republish/client/:id` - republish by client
- New route: `POST /admin/republish/all` - global republish
- New controller methods calling existing `ProvisioningService::republishActiveForwards*()` methods

### Response Format
```json
{
  "success": true,
  "republished": 5,
  "errors": []
}
```

---

## Feature 3: Diagnostics (Admin-Only)

### Goal
Provide a passive diagnostic dashboard showing health status of forwards using existing database data.

### Data Sources (existing tables)
- `forwards` - forward configurations and status
- `forward_endpoints` - provisioned endpoint status per node
- `nodes` - node status, last heartbeat
- `provision_tasks` - recent provisioning task results
- `sidecar_points` - sidecar status and connectivity info

### Pages
- `/admin/diagnostics` - main diagnostics dashboard

### Filter Options
- By Service (dropdown)
- By Node (dropdown)
- By Client (dropdown)
- By Status (active/error/pending/all)
- Time range (last 1h/6h/24h/7d)

### Display Sections
1. **Summary Cards** - total forwards, healthy count, error count, pending count
2. **Forward Health Table** - forward name, service, status, last error, endpoint count, node health
3. **Node Health** - nodes with issues highlighted
4. **Recent Errors** - provision task failures with error messages
5. **Endpoint Status** - per-forward-endpoint connectivity status

### Status Indicators
- 🟢 Green: All endpoints healthy, recent heartbeat
- 🟡 Yellow: Some endpoints unhealthy or stale heartbeat (>5min)
- 🔴 Red: No healthy endpoints or recent errors
- ⚪ Gray: Pending/provisioning

### Error Aggregation
- Group errors by type (connection timeout, auth failure, port conflict, etc.)
- Show count per error type
- Link to relevant provision tasks for details

---

## Technical Implementation

### Files to Modify
- `master/src/Controllers/TenantController.php` - edit forward form/route
- `master/src/Controllers/AdminController.php` - republish routes, diagnostics page
- `master/src/Support/ProvisioningService.php` - updateForward(), republish methods
- `master/views/tenant/service.php` - add Edit button to forward table
- `master/views/admin/services.php` - add Republish button per service
- `master/views/admin/nodes.php` - add Republish button per node
- `master/views/admin/clients.php` - add Republish button per client
- `master/views/admin/dashboard.php` - add global Republish All button
- `master/views/admin/diagnostics.php` - new diagnostics page

### New Routes
- `GET/POST /tenant/service/:id/forward/:fid/edit` - edit forward form
- `POST /admin/republish/service/:id` - republish by service
- `POST /admin/republish/node/:id` - republish by node
- `POST /admin/republish/client/:id` - republish by client
- `POST /admin/republish/all` - global republish
- `GET /admin/diagnostics` - diagnostics dashboard

---

## Backward Compatibility

- Edit forward does not affect existing functionality; it adds new capability
- Republish buttons use existing provisioning service methods; no breaking changes
- Diagnostics page is new and only accessible to admins; no impact on existing functionality

---

## Testing Plan

1. **Edit Forward:**
   - Test with valid input values
   - Test validation errors (invalid host, invalid port, negative speed)
   - Test editing while forward is pending (should fail)
   - Test editing another tenant's forward (should fail)

2. **Republish:**
   - Test each scope (service, node, client, global)
   - Verify correct number of forwards are republished
   - Test with zero forwards (edge case)

3. **Diagnostics:**
   - Test with no data (empty state)
   - Test with various filters applied
   - Verify status indicators match data