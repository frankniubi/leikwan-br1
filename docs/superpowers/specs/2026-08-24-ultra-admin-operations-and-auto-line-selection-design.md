# Ultra Admin Operations and Automatic Line Selection Design

Date: 2026-08-24

Status: Approved design. Implementation and deployment are pending.

## Purpose

Make the additive `ultra` mode discoverable, configurable, and observable in
the Panel while preserving the source-only and direct-client data path:

```text
source 171 -> Panel control plane -> authenticated client -> upstream line -> destination
```

The user supplies only the destination `IPADDR:PORT`. The user does not select
an ingress zone, line number, relay, account, or implementation mode. The
Ultra client runtime automatically selects the best admin-approved line.

## Current-State Findings

- `/admin/forward-combinations` already exists and the form already contains
  the additive `ultra` backend option and ordered line checkboxes.
- `BaseController::adminNavigationItems()` omits Forward Combinations, so the
  page cannot be discovered through the normal `/admin` navigation.
- The existing Managed TYO line catalog mixes legacy node health with line
  configuration and does not represent source-discovered Ultra inventory.
- The Panel has client/device session records and client IPs, but the Ultra
  runtime does not report the actual successful TCP or UDP entry.
- Source host 171 currently has 110 line TOML files. Its `index.json` contains
  descriptive title/family metadata for 33 lines; the remaining entries have
  numeric TOML metadata and must appear as uncategorized until enriched or
  overridden by an administrator.
- `https://api.bilibili.com/x/web-interface/zone` reports the public IP and
  location of the machine making the request. It does not classify an
  arbitrary IP supplied through a query parameter or forwarding header.

## Goals

1. Add visible admin navigation for Ultra Operations and Forward
   Combinations.
2. Add a dedicated `/admin/ultra` operational workspace.
3. Import and categorize the complete source-discovered line inventory.
4. Allow administrators to enable lines, override categories, and control
   candidate/fallback policy.
5. Let the Ultra binary automatically detect its ingress region and choose
   the best allowed line.
6. Show the actual latest TCP and UDP selections for client sessions seen in
   the last 24 hours.
7. Keep telemetry, probing, retry work, storage, and queue growth bounded.
8. Validate the new Linux client on `172.21.255.189` across six destination
   families by forwarding DNS to `8.8.8.8:53`.

## Non-Goals

- No user-facing line, zone, relay, or transport selector.
- No independent gateway, control port, data port, or `18443` listener.
- No traffic path through source host 171.
- No raw account, session, credential, token, or material display in admin
  HTML or telemetry.
- No external GeoIP request from the Panel for arbitrary relay IPs.
- No unbounded status history, per-packet persistence, or one-task-per-packet
  behavior.
- No changes to `current_mode` or `leigod_mode` semantics.

## Admin Information Architecture

Add these shared navigation entries:

```text
Operations
  Ultra Operations       /admin/ultra

Infrastructure
  Forward Combinations   /admin/forward-combinations
```

The existing `/admin/leigod-lines` page remains the legacy Managed TYO line
catalog. It is not renamed and does not become the Ultra inventory page.

### Ultra Operations

`/admin/ultra` is a dense operational workspace with four query-driven tabs:

```text
/admin/ultra?view=sessions
/admin/ultra?view=lines
/admin/ultra?view=combinations
/admin/ultra?view=source
```

The default is `sessions`. The header includes a `New Ultra Combination`
action linking to:

```text
/admin/forward-combinations?backend_mode=ultra
```

The Forward Combination page remains the authoritative write path for line
membership and route configuration.

### Session Tab

Summary counters:

- online sessions;
- stale sessions;
- discovered lines;
- material-ready lines;
- materials expiring in less than ten minutes.

The status table includes:

```text
State
User
Client ID and device
Client public IP
Forward ID and label
Destination IPADDR:PORT
Detected ingress zone
Latest successful TCP line and relay IP
Active UDP line and relay IP
Last seen
```

State thresholds use the existing session clock:

- `Online`: last heartbeat is less than 3 minutes old.
- `Stale`: 3 through 15 minutes old.
- `Offline`: more than 15 minutes old but less than 24 hours old.
- Sessions older than 24 hours are excluded.

The table supports bounded pagination and server-side filters for state,
destination zone, user/client search, and forward search.

### Line Inventory Tab

The inventory table includes:

```text
Line ID
Source title
Effective ingress zone
Effective destination zone
Discovery IP
Current relay IPs
Configuration fingerprint state
Material state and expiry
Last discovered
Admin enabled state
Combination references
```

Relay ports, users, credentials, and source sessions are never rendered.

An edit action permits only safe policy fields:

- admin label;
- ingress-zone override;
- destination-zone override;
- enabled state;
- sort order;
- notes.

### Combination Tab

The tab lists Ultra combinations, destination zone, allowed ingress zones,
fallback policy, ordered line membership, active forwards, and readiness.
Each row links to the existing Forward Combination edit page.

### Source Tab

The source tab displays:

- configured source ID;
- last authenticated contact;
- inventory count and fingerprint;
- last inventory acceptance;
- last jobs request;
- last material upload;
- requested and accepted line counts;
- source agent version;
- material-ready, expiring, and expired counts;
- credential-free last error code.

It never displays the source HMAC secret, account session, upstream material,
or source acquisition response.

## Data Model

### `ultra_sources`

One bounded latest-state row per registered source:

```text
source_id VARCHAR(191) PRIMARY KEY
agent_version VARCHAR(64) NULL
inventory_fingerprint CHAR(64) NULL
inventory_count INT UNSIGNED NOT NULL DEFAULT 0
last_seen_at DATETIME NULL
last_inventory_at DATETIME NULL
last_jobs_at DATETIME NULL
last_material_upload_at DATETIME NULL
last_requested_line_count INT UNSIGNED NOT NULL DEFAULT 0
last_accepted_line_count INT UNSIGNED NOT NULL DEFAULT 0
last_error_code VARCHAR(64) NULL
created_at DATETIME NOT NULL
updated_at DATETIME NOT NULL
```

Status updates replace the row. They do not append an event for every poll.

### `ultra_zones`

Admin-managed category definitions:

```text
id BIGINT UNSIGNED PRIMARY KEY
zone_code VARCHAR(32) UNIQUE
zone_role ENUM('ingress', 'destination')
label VARCHAR(191)
aliases_json JSON NULL
sort_order INT NOT NULL DEFAULT 100
is_active TINYINT(1) NOT NULL DEFAULT 1
created_at DATETIME NOT NULL
updated_at DATETIME NOT NULL
```

Initial ingress zones include `beijing`, `shanghai`, `guangzhou`, and
`unknown`. Initial destination zones include `singapore`, `hong-kong`,
`tokyo`, `frankfurt`, `los-angeles`, `moscow`, and `unknown`.

`aliases_json` supports source-title aliases and Bilibili province/city
aliases. Examples include `北京`, `上海`, `广州`, `香港`, `日本`, `新加坡`,
`德国`, `美国`, and `俄罗斯`. The effective mapping is editable by an
administrator rather than hard-coded as a permanent vendor taxonomy.

### `ultra_lines`

One current inventory row per source and line ID:

```text
id BIGINT UNSIGNED PRIMARY KEY
source_id VARCHAR(191) NOT NULL
line_id INT UNSIGNED NOT NULL
source_title VARCHAR(255) NULL
source_family_key VARCHAR(255) NULL
game_id INT UNSIGNED NULL
area_id INT UNSIGNED NULL
line_type INT UNSIGNED NULL
discovery_ip VARCHAR(64) NULL
config_sha256 CHAR(64) NOT NULL
auto_ingress_zone VARCHAR(32) NOT NULL DEFAULT 'unknown'
auto_destination_zone VARCHAR(32) NOT NULL DEFAULT 'unknown'
admin_ingress_zone VARCHAR(32) NULL
admin_destination_zone VARCHAR(32) NULL
admin_label VARCHAR(191) NULL
relay_ips_json JSON NULL
last_material_acquired_at DATETIME NULL
last_material_expires_at DATETIME NULL
last_discovered_at DATETIME NOT NULL
is_source_present TINYINT(1) NOT NULL DEFAULT 1
is_enabled TINYINT(1) NOT NULL DEFAULT 0
sort_order INT NOT NULL DEFAULT 100
notes TEXT NULL
created_at DATETIME NOT NULL
updated_at DATETIME NOT NULL
UNIQUE KEY (source_id, line_id)
```

Effective zones use the admin override when present and otherwise use the
automatic category. A newly discovered line is disabled by default. A line
missing from a later complete inventory is marked `is_source_present=0`; it
is not deleted automatically.

`relay_ips_json` stores only deduplicated literal IP addresses already
accepted from fresh material. It never stores ports or credentials.

### `forward_combination_ultra_lines`

Ordered, authoritative candidate membership:

```text
id BIGINT UNSIGNED PRIMARY KEY
forward_combination_id BIGINT UNSIGNED NOT NULL
ultra_line_id BIGINT UNSIGNED NOT NULL
enabled TINYINT(1) NOT NULL DEFAULT 1
sort_order INT NOT NULL DEFAULT 100
created_at DATETIME NOT NULL
updated_at DATETIME NOT NULL
UNIQUE KEY (forward_combination_id, ultra_line_id)
```

This is separate from `forward_combination_leigod_lines`. Existing legacy
memberships are not reused for new Ultra writes.

### Forward Combination Policy

Add these Ultra-only fields to `forward_combinations`:

```text
ultra_destination_zone VARCHAR(32) NULL
ultra_allowed_ingress_zones_json JSON NULL
ultra_fallback_policy VARCHAR(32) NOT NULL DEFAULT 'detected_then_any'
```

Allowed fallback values:

- `detected_only`: use only candidates matching the detected ingress zone;
- `detected_then_any`: prefer matching candidates, then try other
  admin-approved ingress zones if all matching candidates fail;
- `any`: ignore the detected zone and rank all approved candidates.

The production default is `detected_then_any` to avoid turning a failed
location lookup or one unavailable zone into a full outage.

There is no tenant permission or selection field because tenants never
select a zone. Administrators control availability through combinations,
plans, client assignment, ordered memberships, and fallback policy.

### Client Session Status

Add latest-state columns to `client_device_sessions`:

```text
ultra_status_json JSON NULL
ultra_status_updated_at DATETIME NULL
```

The JSON contains at most 32 forward status objects. Every accepted heartbeat
replaces the previous snapshot for that device. This provides the requested
24-hour view without an unbounded event table.

## Source Inventory Contract

Add an authenticated source route to the existing Panel API:

```http
POST /api/ultra/source/inventory
```

It uses the same source HMAC canonicalization, clock window, nonce replay
protection, and HTTPS boundary as jobs and material upload.

The source sends inventory:

- at agent startup;
- when the normalized inventory fingerprint changes;
- every 30 minutes as a bounded safety refresh.

The payload is capped at 256 lines and 256 KiB:

```json
{
  "agent_version": "2026.08.24.1",
  "inventory_fingerprint": "<sha256>",
  "complete": true,
  "lines": [
    {
      "line_id": 453,
      "title": "Shanghai to Japan",
      "family_key": "Shanghai to Japan",
      "game_id": 6262,
      "area_id": 11796,
      "line_type": 8,
      "discovery_ip": "203.0.113.20",
      "config_sha256": "<sha256>"
    }
  ]
}
```

The source merges `index.json` descriptions with numeric TOML metadata. It
sends neither TOML contents nor account/session/material fields.

The Panel categorizer normalizes titles and aliases into ingress and
destination zone codes. Unknown or ambiguous values remain `unknown` and are
visible for admin correction. Automatic recategorization never overwrites an
admin override.

## Bilibili Location Detection

The Bilibili endpoint is used only to detect the client machine's apparent
public network location:

```text
https://api.bilibili.com/x/web-interface/zone
```

It is not used by the Panel to classify arbitrary relay IPs. Query parameters
and spoofed forwarding headers are not treated as an IP lookup contract.

The Ultra binary performs the request with these limits:

- HTTPS only;
- 2.5-second total timeout;
- response body capped at 16 KiB;
- one active request across local Ultra runtimes through a shared file lock;
- successful result cached for 24 hours;
- stale last-known result usable for up to 7 days after request failure;
- no Panel token, client ID, device ID, destination, or material in the
  request;
- no retry loop in each per-forward process.

The root-only shared cache is stored atomically under `/var/lib/ultra/` and
contains only normalized country, province, city, ISP, zone code, and
timestamps. If no current or stale result is available, the detected zone is
`unknown` and the combination fallback policy applies.

## Capability and Sealed Material Changes

The normal authenticated client configuration still contains only safe
candidate IPs and the scoped Ultra capability. Line IDs, relay ports, and
credentials remain absent from the normal JSON response.

Material resolution stops accepting client-provided RTT ordering as
authoritative. The Panel seals up to eight approved candidate entries in
combination order. Each entry contains:

```text
relay IP and port
upstream user and credential
effective ingress zone
effective destination zone
opaque entry_ref
```

`entry_ref` is generated from the scoped token, client/device/forward scope,
line ID, material generation, relay IP, and transport. It does not reveal the
line ID and can be validated or recomputed by the Panel without storing raw
credentials in telemetry.

The Panel includes only lines that are:

- source-present;
- admin-enabled;
- members of the active Ultra combination;
- permitted by the combination's destination and ingress policy;
- backed by fresh material.

Missing candidates fail the new connection closed. Existing unexpired
connections are not terminated by an inventory refresh.

## Binary Selection Algorithm

The Go Ultra runtime, not the user and not the Python client agent, owns final
selection.

For each forward:

1. Load and validate the scoped sealed candidate set.
2. Load or refresh the shared Bilibili location result.
3. Map province/city to an admin-provided ingress-zone alias snapshot included
   in sealed policy data.
4. Partition candidates according to the combination fallback policy.
5. Probe at most eight candidates with at most four concurrent operations and
   fixed sub-second connect deadlines.
6. Rank successful candidates by measured relay connection RTT, then admin
   sort order, then stable opaque reference.
7. Establish TCP using the first successful ranked entry, falling through on
   connect or handshake failure.
8. Establish UDP by arming candidates in ranked order until one succeeds.
9. Keep TCP and UDP selections independent because their best or available
   lines may differ.

Probe results have a 60-second, 32-entry local cache. A process may perform
only one selection refresh at a time. Additional refresh requests coalesce
into one pending signal rather than creating a queue.

Existing connections are not migrated merely because another candidate later
measures faster. New TCP sessions use the latest valid ranking. UDP changes
entry only when its current association fails, material is reloaded, or the
association is deliberately refreshed at a bounded interval.

## Runtime Status Contract

The binary writes one credential-free status file per forward under
`/run/ultra/` using atomic replacement. Writes are throttled to at most one
every five seconds per forward.

Example logical shape:

```json
{
  "forward_id": 42,
  "detected_zone": "shanghai",
  "zone_source": "bilibili-cache",
  "tcp": {
    "entry_ref": "<opaque>",
    "relay_ip": "203.0.113.20",
    "rtt_ms": 18,
    "active_connections": 3,
    "last_success_at": "2026-08-24T02:00:00Z"
  },
  "udp": {
    "entry_ref": "<opaque>",
    "relay_ip": "203.0.113.21",
    "rtt_ms": 21,
    "association_state": "active",
    "last_success_at": "2026-08-24T02:00:01Z"
  }
}
```

The Python client agent reads at most 32 status files during its existing
heartbeat cycle and submits `ultra_status`. It does not start a telemetry
thread or send a separate request per forward.

The Panel accepts only status for active assigned Ultra forwards. It validates
the opaque references and relay IP against the current scoped candidate set,
caps text and numeric fields, and discards unknown or expired references. It
then atomically replaces `client_device_sessions.ultra_status_json`.

The latest successful TCP selection is an observation, not a claim that every
active TCP connection uses the same line. The UI labels it accordingly. UDP
shows the currently armed association.

## User Experience

The tenant/user workflow remains:

```text
Destination IP or hostname
Destination port
Transport already defined by the product/forward
```

No line, ingress city, destination zone, Bilibili result, relay, or fallback
control is shown to the user. The assigned service/Forward Combination defines
the destination family and eligible policy; the binary selects the concrete
entry.

## Failure Behavior

### Bilibili Unavailable

Use the 24-hour current cache, then the seven-day stale cache. If neither is
available, use `unknown` and the admin fallback policy. A location failure does
not stop an existing listener or connection.

### Inventory Stale or Missing

Show source inventory as stale in admin. Do not delete lines automatically.
Do not issue new material for a source-absent or disabled line. Preserve
existing unexpired runtime material until its normal expiry.

### Material Expiring

Retain the existing proactive refresh window and bounded retries. Admin shows
the expiration state. A failed refresh preserves an unexpired runtime and
fails closed after hard expiry.

### Status Missing

The forwarding path continues. Admin displays `Not reported` for old client
versions or missing status files. Telemetry is never a prerequisite for data
forwarding.

### Candidate Failure

Try the next bounded candidate according to policy. If every candidate fails,
the new TCP connection or UDP association fails closed. Do not select a legacy
mode or account as fallback.

## CPU, Queue, and I/O Bounds

- Inventory: maximum 256 lines and 256 KiB per source refresh.
- Sealed candidates: maximum eight entries per forward.
- Material-resolution request: maximum 32 forwards.
- Location lookup: one shared in-flight request per host.
- Candidate probes: maximum four concurrent, eight total per refresh.
- Probe cache: maximum 32 entries, 60-second TTL.
- Status files: maximum one per active Ultra forward.
- Status writes: maximum once per five seconds per forward.
- Heartbeat status: maximum 32 forwards, replacing one session row.
- Admin queries: paginated, default 50 rows, maximum 200 rows.
- No new per-packet task, persistence operation, or telemetry queue.

## Security and Privacy

- Source and client authentication remain mandatory.
- Source inventory is signed and replay-protected.
- Bilibili receives only the direct HTTPS request needed to observe the
  client's public IP; no LeiKwan identifiers or destinations are sent.
- Admin HTML never contains token, credential, relay port, TOML, session, or
  sealed-material content.
- Status references are opaque and scoped. Reported relay IPs are validated
  against authorized candidates.
- Logs contain stable error codes, not payloads or tokens.
- The stripped client reduces incidental labels but is not treated as an
  impossible reverse-engineering boundary.

## Migration and Compatibility

All database changes are additive. The deployment order is:

1. Deploy schema and Panel code that accepts old clients without Ultra status.
2. Deploy the source inventory agent and verify the inventory is visible but
   disabled by default.
3. Review categorization and enable only intended lines.
4. Deploy the new static Linux Ultra binary and updated installer/runtime.
5. Deploy the client agent that collects bounded status snapshots.
6. Run the six-direction Linux acceptance test.
7. Enable production Ultra combinations only after acceptance evidence.

Old Ultra clients continue to receive compatible material until their client
artifact is upgraded. The Panel does not require status fields. The new binary
must support the current capability protocol during rollout or the protocol
version must be explicitly bumped and negotiated before activation.

## Test Strategy

### Unit and Contract Tests

- navigation contains Ultra Operations and Forward Combinations;
- source inventory HMAC, nonce, size, count, and complete-snapshot handling;
- line categorization, unknown handling, and admin override precedence;
- newly discovered lines default disabled;
- combination policy and ordered membership validation;
- Bilibili response parser, timeout, body cap, cache, stale cache, and lock;
- sealed entry references do not expose line IDs;
- exact-zone preference and each fallback policy;
- bounded probe concurrency and deterministic tie-breaking;
- independent TCP and UDP selection/failover;
- status atomic writes, throttling, caps, and redaction;
- heartbeat assignment/reference validation;
- 3-minute, 15-minute, and 24-hour admin state boundaries;
- legacy mode non-regression;
- artifact ELF/hash/manifest consistency.

### Panel Integration Tests

- signed source inventory populates safe rows without plaintext material;
- source-present transitions are idempotent;
- material upload updates only safe relay-IP/expiry status fields;
- active Ultra combination exposes only approved candidate IPs before sealed
  resolution;
- admin pages require admin authentication and escape all source/client text;
- pagination and filters remain bounded;
- old clients without status remain accepted.

### Browser QA

Verify `/admin/ultra` and `/admin/forward-combinations` on desktop and mobile:

- navigation visibility and active state;
- all four Ultra tabs;
- empty, loading, stale, expired, and populated states;
- long user, device, IPv6, destination, and line labels do not overlap;
- create/edit flow opens with Ultra preselected;
- no credentials, ports, tokens, or implementation-specific account fields
  appear in rendered HTML or network responses.

## Linux Six-Direction Acceptance Test

Use the authorized Linux canary host `172.21.255.189`. Credentials remain in
the operator's local context and are not written to this repository or the
report.

Create temporary, isolated Ultra test forwards targeting exactly:

```text
8.8.8.8:53
```

Test these destination families:

1. Singapore
2. Hong Kong
3. Tokyo
4. Frankfurt
5. Los Angeles
6. Moscow

For each family:

1. Select an admin-approved combination containing the intended destination
   family and all permitted ingress variants.
2. Confirm the binary obtains or reuses a bounded Bilibili location result.
3. Launch the new Linux ELF on a temporary unoccupied local port.
4. Send three UDP DNS queries and three TCP DNS queries through the local
   forward to `8.8.8.8:53`.
5. Query both `google.com A` and `o-o.myaddr.l.google.com TXT` where supported.
6. Record the selected line ID in the admin-side report, effective ingress and
   destination zones, selected relay IP, detected client zone, DNS RCODE,
   answer values, query time, success ratio, and any failover.
7. Record process CPU and RSS during the bounded test and verify no probe or
   retry burst.
8. Remove the temporary listener, runtime files, units, and test assignments.

The credential-free report table is:

```text
Direction
Detected ingress zone
Selected admin line label/ID
Selected relay IP
UDP success/3, RCODE, answers, median query time
TCP success/3, RCODE, answers, median query time
Failover observed
Peak CPU
Peak RSS
Timestamp
Verdict
```

A direction passes only if at least two of three UDP queries and two of three
TCP queries return a valid DNS response through the intended family. A failed
direction remains failed in the report; it is not silently substituted with a
different destination family.

The final cleanup check must show no temporary test listener or process on
`172.21.255.189` and no temporary test assignment left active in the Panel.

## Production Acceptance

- Both required navigation entries are visible.
- All discovered source lines appear in Ultra inventory.
- New lines are disabled until explicitly enabled.
- Admin categorization and overrides are effective.
- Users provide only destination `IPADDR:PORT`.
- The binary automatically selects candidates using detected ingress zone,
  bounded RTT measurement, and admin fallback policy.
- Admin shows validated latest TCP and current UDP selections for 24-hour
  sessions.
- Existing rate limiting and traffic accounting remain active.
- Source 171 remains outbound-only with no data listener.
- Panel routes use existing HTTPS and no independent Ultra port appears.
- The six-direction DNS report and cleanup evidence are complete.

## Rollback

1. Disable new Ultra combinations and stop issuing new candidate sets.
2. Roll client artifact metadata back to the last known version.
3. Keep the Panel compatible with old clients while new binaries drain.
4. Disable source inventory refresh without stopping the existing source
   material path.
5. Leave additive tables/columns in place; do not destructively roll back
   schema during an incident.
6. Verify existing `current_mode` and `leigod_mode` services remain unchanged.
