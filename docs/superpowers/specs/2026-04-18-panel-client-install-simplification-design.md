# Panel Client Install Simplification Design - 2026-04-18

## Overview

Simplify the panel install experience so both tenant and admin users see the current client-level model clearly:

- client apps authenticate with `Client ID + Token`
- Windows and Android app downloads are first-class actions
- Linux keeps a manual local install command
- old remote-SSH install from the panel is removed as a product feature

This design applies to both:

- `GET /tenant/clients`
- `GET /admin/clients`

It also includes the product-surface cleanup needed to remove panel-driven remote SSH install for Linux clients and slaves.

## Why This Change Exists

The current panel still presents a mixed install story:

- client-level app login with `client_id + agent_token`
- Linux manual agent install
- Windows script-based manager/bootstrap helpers
- Android import string
- older remote SSH install affordances

That mix no longer matches the current product direction.

The maintenance log in `docs/2026-04-18-windows-client-implementation-maintenance-log.md` confirms that the Windows app now uses the client-level login flow:

- panel URL
- `Client ID`
- `Token`

The panel should therefore lead with that same model instead of burying it inside a dense install/details block.

## Goals

- Make the top of the client experience app-first and client-centric.
- Present `Client ID + Token` as the primary install/login identity.
- Add a clear `App Download` section that includes:
  - Windows app
  - Android app
  - Linux install command
- Simplify the client page GUI on both tenant and admin sides.
- Collapse most advanced client configuration fields behind `Advanced client settings`.
- Keep diagnostics, updates, usage, and assigned-forward visibility available without making the page feel overloaded.
- Remove panel-driven remote SSH install for Linux clients and slaves.

## Non-Goals

- Do not redesign the panel information architecture beyond the client install surface.
- Do not change the underlying client authentication model.
- Do not remove normal manual install, update, uninstall, or diagnostics flows.
- Do not redesign the Windows app itself.
- Do not remove repo-local helper scripts unless they become completely unused and safe to delete in the same pass.

## Selected Approach

Use one shared install presentation model across both admin and tenant client pages.

The page should:

1. lead with app download and client credentials
2. keep one concise client overview per client
3. demote secondary and legacy install helpers into details panels
4. hide most advanced configuration under a collapsed section
5. remove all panel UX and controller execution paths for remote SSH install

This keeps the product simpler without breaking the underlying install/update tooling users still need.

## Design

### 1. App-First Top Section

Both `/tenant/clients` and `/admin/clients` should render a clear top-level install hub.

Required actions:

- `Windows App`
- `Android App`
- `Linux Install`

Expected presentation:

- a dedicated `App Download` section near the top of the page
- short copy explaining that Windows and Android use `Client ID + Token`
- Linux remains a manual local install command for the client-agent

The top section should no longer emphasize forward-specific Windows bootstrap scripts or remote install stories.

### 2. One Client-Level Overview Per Client

Each client card should become a simpler overview centered on the current identity and status.

Primary information:

- `Client ID`
- `Token`
- status
- installed agent version
- latest panel version
- last sync
- quick actions such as diagnostics, update request, and edit/delete where allowed

Primary install actions:

- copy `Client ID`
- copy `Token`
- download Windows app
- download Android app
- copy Linux install command

Secondary details remain available, but should move behind details blocks instead of dominating the card:

- assigned forwards summary
- diagnostics detail
- usage calendar
- uninstall command
- Windows manager/helper scripts
- Android import string

Important product rule:

- the main story is no longer "pick a script"
- the main story is "download the app or run the Linux command, then use `Client ID + Token`"

### 3. Simplified Create/Edit Form

The create/edit form should stay on the same page, but most advanced fields should be tucked away.

Default visible fields:

- `Name`
- `Display Host`
- `Client Group`
- `Status`

Collapsed `Advanced client settings` should contain the rest of the lower-frequency fields:

- `Bind IP`
- `NIC Name`
- `Port Range Start`
- `Port Range End`
- `Traffic Multiplier`
- `Default Route Mode`
- `Default Slave Group Key`
- `Default Forward Chain`
- `Agent Config JSON`

Admin-only or admin-expanded client fields should follow the same spirit:

- leave the basic identity/ownership controls visible
- tuck routing and advanced operational defaults into the advanced section

The goal is not to hide power; it is to prevent the first screen from feeling like a provisioning spreadsheet.

### 4. Shared Install Helper Structure

The shared helper currently rendered from `BaseController::renderClientAgentInstallDetails(...)` should be refactored so both tenant and admin clients pages inherit the same simplified install story.

Expected shared helper behavior:

- render app downloads and Linux install as the primary group
- render `Client ID + Token` prominently
- keep secondary install/uninstall/manager helpers behind details
- avoid presenting old Windows script helpers as the recommended path

This keeps admin and tenant pages visually aligned and avoids duplicating install wording in two controllers.

### 5. Remove Panel-Driven Remote SSH Install

Remote SSH install should be removed as a panel product feature, not merely hidden.

#### 5.1 Remove UI Surface

Remove UI and copy that advertise or trigger remote SSH install:

- admin nodes page remote slave install form
- admin client remote install affordances
- tenant client remote install affordances
- any helper text that says the panel can SSH into the target machine and install Linux client/slave software for the user

#### 5.2 Remove Controller Execution Paths

Remove the controller actions that currently execute remote install commands on behalf of the web app.

Confirmed paths in scope:

- `TenantController::installClientRemote(...)`
- `AdminController::installClientRemote(...)`
- `AdminController::installNodeRemote(...)`

After this change, the panel must no longer spawn local shell commands to SSH into user-managed machines for client/slave installation.

#### 5.3 Remove Public Artifact Routes For Remote Install

Remove artifact routes that exist for the old remote-install panel story:

- `/artifacts/remote-install-slave.sh`
- `/artifacts/remote-install-client.sh`
- `/artifacts/remote-install-client-agent.sh`

Confirmed registration point:

- `master/src/Bootstrap.php`

Confirmed passthrough methods:

- `ArtifactController::remoteInstallSlave(...)`
- `ArtifactController::remoteInstallClient(...)`
- `ArtifactController::remoteInstallClientAgent(...)`

#### 5.4 What Stays

These flows stay supported:

- manual Linux install via normal panel-hosted scripts
- Windows app download
- Android app download
- Android import string
- uninstall/update/diagnostics flows

Product copy after this change should be explicit:

- install is now local/manual
- the panel no longer offers a remote SSH installer path

### 6. Admin And Tenant Page Behavior

Both pages should feel consistent, while still respecting role differences.

Tenant page:

- focus on the tenant's own clients
- app downloads and `Client ID + Token` lead the experience
- advanced defaults remain editable but collapsed

Admin page:

- same install story and same client-centric language
- keep admin-specific management actions
- keep admin ownership and fleet visibility
- remove remote-SSH client/slave install from product UI

The shared install language should not diverge between the two pages.

## Files In Scope

### Primary UI / Shared Rendering

- `master/src/Controllers/BaseController.php`
- `master/src/Controllers/TenantController.php`
- `master/src/Controllers/AdminController.php`

### Remote Install Removal

- `master/src/Bootstrap.php`
- `master/src/Controllers/ArtifactController.php`
- `master/src/Controllers/TenantController.php`
- `master/src/Controllers/AdminController.php`

### Likely Tests

- `master/tests/tenant_controller_regressions_test.php`
- one or more new controller/regression tests for shared client install rendering and route/controller cleanup

## Testing Plan

### Regression Coverage

Add focused tests for the simplified install output and route cleanup.

Required checks:

- shared install helper output includes:
  - `Client ID`
  - `Token`
  - `Windows App`
  - `Android App`
  - `Linux Install`
- secondary tools still exist but are demoted from the primary install story
- tenant clients page includes:
  - top-level app download section
  - collapsed advanced settings container
  - no remote SSH install affordance
- admin clients page includes:
  - the same client-centric install story
  - no remote SSH install affordance
- admin nodes page no longer includes remote SSH slave install UI
- bootstrap/router no longer registers the remote-install artifact routes
- removed controller methods are no longer reachable from route wiring

### Manual Verification

Manual QA after implementation should confirm:

1. `/tenant/clients` leads with app download plus `Client ID + Token`
2. `/admin/clients` does the same
3. advanced settings are collapsed by default
4. Linux install command still works as a visible manual option
5. Windows and Android downloads are obvious and easy to access
6. no page still claims the panel can remotely SSH into Linux client/slave hosts to install software
7. old remote-install artifact URLs are no longer exposed through the router

## Risks And Guardrails

- Do not accidentally remove normal manual install flows while removing remote SSH install.
- Do not break existing diagnostics/update actions when simplifying the card layout.
- Keep the shared helper refactor centralized so admin and tenant output does not drift.
- Keep the new copy aligned with the actual Windows app behavior documented in the maintenance log.

## Summary

This change turns the panel client install experience into a simpler and more current product flow:

- download the app or use the Linux command
- use `Client ID + Token`
- keep advanced configuration tucked away until needed
- remove the old panel-driven remote SSH install feature entirely

That is the smallest coherent UX cleanup that matches the current Windows/Android/Linux client model.
