# Tenant Client Install-Only Page Design - 2026-08-25

## Status

The visual direction in the Superpowers preview is approved. This document is
the implementation contract and is awaiting written-spec review before code
changes begin.

## Decision

Reduce `GET /tenant/clients` to a small installation surface backed by one
tenant default client:

- show one Linux install command, with the current install token embedded in
  the command;
- show one Windows application installer download;
- show one global concurrent-online indicator in the form `最大在线 0 / 3`;
- do not show a client list, client-install history, total client count,
  Android, diagnostics, line information, provider information, advanced
  settings, or internal grouping/policy terminology;
- keep client-profile limits and session accounting on the server, without
  exposing installation-count information to the tenant;
- keep the default client outside any system-distribution/client-group
  assignment in the data model. This is an internal invariant and must never
  be rendered as explanatory UI text.

The existing Panel-integrated gateway and Ultra control-plane design are not
changed by this page simplification. No slave is introduced or involved.

## Goals

1. Make the first screen immediately useful for installing Linux or Windows.
2. Remove expensive and low-value reads from the tenant page.
3. Give the backend one stable client identity per tenant while preserving
   existing legacy client rows for administrator and compatibility workflows.
4. Track only the concurrent online state needed for the `最大在线` indicator.
5. Preserve existing authentication, entitlement, rate-limit, quota, expiry,
   and revocation enforcement in the runtime APIs.
6. Keep the page safe to refresh and safe to retry under concurrent requests.

## Non-Goals

- Do not redesign the Windows application or its installer.
- Do not add a public SOCKS5 listener, a new gateway process, or a new data
  path.
- Do not expose provider lines, temporary credentials, source/provider IPs, or
  Ultra lease material on this page.
- Do not remove administrator client-management screens or server-side API
  enforcement for legacy client profiles in this scoped change.
- Do not remove the existing artifact route merely because the tenant page now
  has one Windows download action.
- Do not add a persistent installation counter or a new installation-history
  table.
- Do not delete existing client rows as part of the page change.

## Approved User Experience

### Canonical layout

The canonical tenant page remains `/tenant/clients`. It contains one compact
installation section with this order:

```text
Client

安装

Linux
<one-line install command containing the token>
[复制命令] [重置]

Windows App
[下载 installer]

最大在线        0 / 3
```

The exact CSS can follow the existing panel skin, but the information
hierarchy is fixed. The `最大在线` row is global to the page and is not inside
either platform block.

### Allowed visible copy

The approved visible labels are deliberately short:

- `Client`
- `安装`
- `Linux`
- `复制命令`
- `重置`
- `Windows App`
- `下载 installer`
- `最大在线`
- the numeric value such as `0 / 3`

The command itself may contain the existing artifact URL, agent base URL,
client id, and install token because those are required to perform the local
Linux installation.

### Forbidden visible copy and elements

The tenant page must not render any of the following, including equivalent
translations or prompt-like explanatory phrases:

- `System distribution`, `system distribution`, `client group`, or text saying
  that the client belongs to no group;
- `后台策略`, `安装次数`, total client/profile counts, or plan-limit prose;
- Android download/import controls;
- provider/line/temporary-account fields;
- client tables, client selectors, per-client cards, tabs, advanced forms,
  diagnostics, reputation, assigned-forward lists, or session-device lists;
- raw database names, internal endpoint names, or implementation notes.

The absence of these elements is part of the acceptance contract, not merely a
visual preference.

### Linux command behavior

The rendered command is generated from the existing artifact and agent URL
helpers. It must carry the stable default client identity and token, for
example:

```text
curl -fsSL https://v3.leikwanhost.com/artifacts/install-client-agent.sh | bash -s -- --master <agent-base> --artifact-base <artifact-base> --client-id <id> --agent-token <token>
```

The actual command is HTML-escaped and placed in a copyable control. The token
is not displayed a second time in a separate label or data panel. A page
refresh returns the same token until the tenant explicitly uses `重置`.

### Windows download behavior

`下载 installer` points to the existing `windowsClientAppUrl()` artifact URL.
The URL is generic and must not put the client token, provider credentials, or
line information in its query string or filename. The Windows app continues to
resolve the tenant's default client through its existing authenticated sign-in
flow.

### Reset behavior

`重置` is a deliberate secondary action, not an automatic refresh action.

When submitted:

1. require the normal tenant authorization and a valid CSRF token;
2. generate a new cryptographically random `agent_token` for the default
   client in one transaction;
3. invalidate the previous token immediately;
4. revoke the default client's active device sessions so old Linux/Windows
   sessions cannot continue indefinitely;
5. redirect back to the canonical page and render the new command.

The reset endpoint must be POST-only and must not accept a client id supplied
by the browser as an authority boundary. The server resolves the tenant's
current default client itself.

## Backend Contract

### Page view model

Introduce a narrow read model for this page, conceptually:

```php
[
    'linux_install_command' => string,
    'windows_installer_url' => string,
    'active_online_devices' => int,
    'online_device_limit' => int,
]
```

No client row, group, forward, line, diagnostic, or provider object is passed
to the template. The controller should render this model directly instead of
looping over `listClientsForUser()` results.

### Default-client resolution

Use the existing `clients.is_default_for_owner` model and provisioning rules.
The page path must behave as follows:

1. Resolve the authenticated tenant and the relevant service/entitlement using
   the existing authorization boundary.
2. Read one active default client by `(owner_user_id,
   is_default_for_owner, status)`.
3. If the tenant has no default (only possible for legacy or partially
   repaired data), call an idempotent provisioning helper in a short
   transaction, then re-read the row. Normal page loads must remain read-only.
4. Never create a new client or rotate a token on an ordinary GET when a
   default already exists.
5. Repeated concurrent repair calls must converge to one default row and one
   stable token; they must not create duplicate clients.

The default client invariant is:

- `owner_user_id` is the tenant id;
- `is_default_for_owner = 1`;
- `status = active`;
- `group_id IS NULL`;
- `agent_token` is non-empty and stable until reset.

### Legacy grouped clients

Existing non-default clients and their group assignments remain intact. During
default repair, prefer an existing active, ungrouped owned client. If no such
row exists, create a new ungrouped default row and demote any grouped default
marker. This avoids silently changing the routing semantics of legacy grouped
clients or their forwards.

If multiple ungrouped default markers exist, retain the deterministic oldest
active ungrouped row and clear the marker on the others. If no ungrouped row is
available, create one rather than assigning a system-distribution group to the
default.

The repair is idempotent and must be covered by an explicit migration/repair
test. It must not delete rows or rewrite explicitly group-bound forwards.

### Concurrent-online calculation

The numerator is the count of active rows in `client_device_sessions` for the
default client, using the existing `ClientOnlineSessionService::ACTIVE_WINDOW_SECONDS`
window (currently 180 seconds) and the indexed `client_id/last_seen_at` query.

The denominator is the effective `online_device_limit` already resolved for
the tenant service/plan. It is clamped to a positive integer. The page only
renders the resulting pair; it does not expose how the limit was calculated.

Use `activeCountForClient()` or an equivalent `COUNT(*)` query. Do not call
`activeSessionsForClient()`, select device metadata, or serialize session rows
into HTML.

The page must not create an installation event, increment a total-install
counter, or persist a historical device list as a side effect. Existing
heartbeat/session writes remain part of the client runtime contract because
they are required to enforce concurrent limits.

### Server-side client limits

The existing plan/service `max_client_profiles` and billing fields remain
server-side controls for admin/API compatibility. The tenant page does not
query them solely to display a number and does not offer create/delete/profile
controls. Any API that creates an additional client continues to enforce its
existing limit and audit behavior. This preserves the requested silent
backend enforcement without making total installation count part of the user
experience.

## Controller and template boundaries

`TenantController::clientsPage` should be reduced to:

1. authentication, tenant ownership, and feature checks already required by
   the route;
2. default-client resolution;
3. the lightweight online count;
4. URL/command construction through existing helpers;
5. rendering the compact template and one reset POST result.

The page must stop invoking these low-value or high-cost paths:

- `listClientsForUser()` for a full collection;
- `availableClientGroupsForUser()`;
- `availableForwardChainsForService()`;
- `BondLegReputationService::scopedRowsForTenant()` or `recentEvents()`;
- per-client `assignedForwardConfigsForClient()`;
- per-client diagnostics, active-session detail, and health/reputation
  aggregation;
- Android/import, remote-install, advanced-settings, and client CRUD forms.

The existing helpers `clientAgentInstallCommand()` and
`windowsClientAppUrl()` remain the source of artifact URLs. If their current
signatures require a full row, pass only the resolved default row and do not
expand the view model with unrelated data.

Old `?view=...`, `?tab=...`, or equivalent tenant-page query parameters may be
ignored and canonicalized to `/tenant/clients`. Existing admin/API endpoints
remain available until a separate deprecation decision is made.

## Data and privacy rules

- No new table or persistent installation-count column is required.
- Do not persist provider IPs, temporary credentials, line leases, or line
  metadata in this page's session, cache, logs, analytics payloads, or HTML
  comments.
- The Linux token is intentionally present in the rendered command. It must
  not be copied to application logs, request tracing, exception messages, or
  telemetry.
- Reset and page errors must use opaque ids and generic messages; never echo a
  token or provider material.
- Do not expose client-group/system-distribution membership in hidden fields,
  JSON blobs, DOM data attributes, or accessibility labels.

## Performance target

For a normal authenticated GET, the page should require no more than:

- the existing authentication/tenant/service lookups;
- one indexed default-client lookup;
- one indexed active-session `COUNT(*)` query.

It must not perform a query proportional to the number of clients, forwards,
groups, reputation events, or session devices. The rendered HTML should have
one platform block per supported platform, not one block per client.

The implementation should record a query-count/timing regression assertion in
the targeted test or benchmark fixture. A production canary must compare
request latency and database query volume against the current page before
removing the old implementation.

## Security and authorization

- Keep the existing tenant authentication, service ownership, and entitlement
  checks.
- Resolve the default client server-side from the authenticated tenant; never
  trust a browser-supplied owner or client id for reset or token reads.
- Escape all command/URL values in HTML and use a safe copy-to-clipboard
  control without inline script injection.
- Protect reset with POST, CSRF, authorization, rate limiting, and an audit
  event that identifies the tenant/default-client id but never the old/new
  token.
- Do not add a local listener, proxy endpoint, or SOCKS5 capability while
  simplifying this page.

## Compatibility and rollout

1. Implement the view model and idempotent default repair behind the existing
   route; preserve the existing schema and artifact URLs.
2. Add the page contract/regression tests before removing the old controller
   branches.
3. Deploy to a canary tenant and verify:
   - Linux command installs against the default client;
   - Windows installer download and authenticated default-client sign-in;
   - `最大在线` changes with a real heartbeat and returns to zero after the
     active window or revoke;
   - repeated page loads do not create clients or rotate tokens;
   - reset invalidates the old token and sessions;
   - no forbidden internal/provider/group text appears in HTML or response
     payloads;
   - request latency/query volume improves or does not regress.
4. Roll back the template/controller switch if canary verification fails. Do
   not roll back or delete existing client rows or session history.

No schema migration is expected for the normal path. If the legacy repair
needs a data migration, it must be a separate idempotent migration with a
backup, bounded batch size, and a dry-run report of affected default markers.

## Test plan

### Controller/template contract

Update or replace the tenant client page regression test to assert:

- the response contains Linux, Windows App, `最大在线`, and the installer
  command/action;
- the Linux command includes the default client's token and client id;
- the response contains no Android, client list/table, tabs, diagnostics,
  reputation, line/provider fields, group/system-distribution wording, total
  install count, or internal policy prose;
- the online indicator is global and rendered once as `active / limit`;
- no client collection is iterated by the page controller.

### Default-client lifecycle

Add focused tests for:

- existing default reuse with stable token;
- repeated missing-default repair producing exactly one ungrouped default;
- concurrent repair convergence;
- grouped legacy clients remaining non-default and unchanged;
- multiple default markers normalizing deterministically;
- reset rotating the token, revoking sessions, and requiring CSRF/tenant
  authorization.

### Online limit

Keep the existing online-device-limit tests and add a page-level assertion
that:

- active rows inside the configured window are counted;
- stale rows are excluded;
- the denominator is the effective service limit;
- no device metadata or session list is rendered.

### Static/security checks

- PHP lint for every changed PHP file.
- `git diff --check`.
- Search the rendered fixture/source for forbidden prompt-like strings and
  provider/credential field names.
- Verify the Windows URL has no token-like query parameters.
- Run the targeted tenant/default-client/session tests, then the related
  regression suite before deployment.

## Acceptance criteria

The change is complete only when all of the following are true:

1. A tenant sees one concise page with Linux install, Windows installer, and a
   global `最大在线 0 / N` indicator.
2. Linux installation uses one stable default client token until an explicit
   reset.
3. The default client has no system-distribution/client-group assignment, and
   that implementation fact is absent from all user-visible UI and payloads.
4. No total installation count or client-list UI is present; server-side
   profile-limit enforcement remains intact.
5. The page no longer performs full client, forward, group, reputation, or
   diagnostic reads.
6. Token reset is explicit, authorized, CSRF-protected, audited without secret
   values, and revokes the prior sessions.
7. Existing Windows artifact delivery and authenticated app flow continue to
   work.
8. Targeted tests and a canary prove the behavior and the query/latency
   improvement.
