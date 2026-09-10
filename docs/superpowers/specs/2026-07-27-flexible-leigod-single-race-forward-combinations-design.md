# Flexible LeiGod Single/Race Forward Combinations Design

Date: 2026-07-27
Status: approved for implementation
Baseline: `master` at `39d1887`

## 1. Objective

Build truthful, sidecar-free LeiGod Forward Combinations for both single and race routes while keeping the line set operator-configurable.

The initial production catalog contains these five proven directions:

| Line ID | Initial direction label |
| --- | --- |
| 451 | Shanghai to Korea |
| 453 | Shanghai to Japan |
| 456 | Beijing to Russia |
| 455 | Beijing to Germany |
| 447 | Guangzhou to Hong Kong |

These IDs and labels are initial data, not application constants. An administrator can add, disable, remove, reorder, or reassign any provider line whose ID is in the runtime-valid range `1..45535` without a code release. The upper bound follows the existing local SOCKS convention `20000 + line_id <= 65535`.

## 2. Existing Constraints

- `forward_combinations.client_mode` remains `single`, `bond`, or `mptcp`. `race` is not a client mode.
- `forward_routes.race_enabled` continues to define whether multiple enabled combinations are raced.
- A dedicated `leigod_mode + single` combination has no sidecar. The required topology is `client -> slave -> LeiGod line -> target`.
- A race route must use candidates that share one client-facing ingress contract. In this edition that means the same `backend_mode`, `node_group_key`, `client_mode`, and `bond_ingress_mode`.
- Slave ingress ports remain in the project-mandated `7000-9000` range.
- Line TOML and account session material remain operator secrets and must not be committed, rendered back to tenants, or written into provisioning-task rows.
- Current production has no sidecars. Sidecar-dependent bond/MPTCP behavior is outside this feature.

## 3. Product Semantics

### 3.1 Line catalog

The panel has an administrator-only LeiGod line catalog. Each row has:

- a unique external line ID;
- an operator label and notes;
- enabled/disabled state and sort order;
- a TCP probe host and port;
- an optional UDP probe host, port, and method;
- the expected SHA-256 of the line TOML;
- a derived configuration state: `ready`, `missing`, or `hash_mismatch`.

Configuration bytes continue to come from `LEIGOD_LINE_TOML_<line_id>` in the panel environment. This keeps secrets out of the database and permits an arbitrary line ID. A catalog row cannot become runtime-eligible unless its environment material exists, decodes successfully, and matches the configured hash.

The shared account session continues to come from `LEIGOD_SESSION_JSON_B64`.

### 3.2 Flexible combination membership

A LeiGod Forward Combination owns an ordered pool of catalog lines through a many-to-many membership table. A line may belong to more than one combination, and an administrator may change membership and order at any time.

`forward_combinations.leigod_line_id` remains temporarily as a compatibility projection of the first enabled member. New selection and availability logic reads the membership table. Saves update the legacy column in the same transaction.

The combination's existing `tenant_label` is the direction label. The line catalog does not hard-code geography, so an operator can build a pool containing any desired line set.

### 3.3 Single routes

A non-racing route contains exactly one enabled combination.

- The slave uses the first healthy member by configured order.
- The selected line is sticky while healthy.
- If it fails the configured threshold, the slave moves to the next healthy member.
- A recovered higher-priority line is not selected until it satisfies the recovery threshold and minimum hold period.
- A single route is available on a node when at least one member line has fresh healthy TCP state on that node.

### 3.4 Race routes

A racing route contains at least two enabled combinations. Any enabled combinations with the same ingress contract may be mixed; their direction labels and line memberships are otherwise unrestricted.

- The slave probes candidates concurrently through their local line SOCKS listeners.
- It selects the successful candidate with the lowest target-specific connect time.
- An improvement must exceed the configured hysteresis before replacing a healthy winner.
- Failure immediately removes a candidate from new-connection selection.
- Existing TCP connections remain pinned to the line that accepted them; selection changes affect new connections.
- A race route is available on a node only when at least two distinct enabled combinations have fresh healthy candidates on that same node.

The initial data creates five single combinations, five pinned single routes, and one race route containing all five combinations. Administrators may later change the race subset and order.

### 3.5 Protocol truth

TCP is implemented through SOCKS5 `CONNECT` on each local LeiGod listener.

UDP is capability-gated independently per node and line. The agent may report UDP healthy only after a complete SOCKS5 `UDP ASSOCIATE` data-plane probe receives the expected response. A successful control handshake alone is insufficient.

- TCP-only forwards can use any fresh TCP-healthy candidate.
- UDP-only forwards require fresh UDP health.
- `both` forwards require the same candidate to be healthy for both protocols.
- When no line passes the required protocol probe, provisioning fails with an explicit unavailable reason. It never silently sends traffic directly or reports a healthy endpoint.

The initial five lines may therefore produce usable TCP routes while UDP remains unavailable until live probes prove otherwise.

## 4. Data Model

### 4.1 `leigod_lines`

New catalog table:

```text
id                       BIGINT primary key
line_id                  INT unique, 1..45535
label                    VARCHAR(191)
notes                    TEXT nullable
tcp_probe_host           VARCHAR(255)
tcp_probe_port           INT
udp_probe_host           VARCHAR(255) nullable
udp_probe_port           INT nullable
udp_probe_method         VARCHAR(32) nullable
config_sha256            CHAR(64)
is_active                TINYINT(1)
sort_order               INT
created_at               DATETIME
updated_at               DATETIME
```

Deleting a referenced line is rejected. Operators disable it or remove its combination memberships first.

### 4.2 `forward_combination_leigod_lines`

New membership table:

```text
id                       BIGINT primary key
forward_combination_id   BIGINT foreign key
leigod_line_id           BIGINT foreign key to leigod_lines.id
enabled                  TINYINT(1)
sort_order               INT
created_at               DATETIME
updated_at               DATETIME
unique(forward_combination_id, leigod_line_id)
```

Dedicated `leigod_mode + single` combinations require at least one enabled membership. Non-LeiGod combinations cannot retain memberships.

### 4.3 `node_leigod_line_health`

New observed-state table:

```text
node_id                  BIGINT foreign key
leigod_line_id           BIGINT foreign key
config_sha256            CHAR(64)
service_state            VARCHAR(32)
tcp_status               VARCHAR(32)
tcp_connect_ms           INT nullable
udp_status               VARCHAR(32)
consecutive_successes    INT
consecutive_failures     INT
last_error               VARCHAR(500) nullable
checked_at               DATETIME
updated_at               DATETIME
primary key(node_id, leigod_line_id)
```

Rows are observations, not desired state. A row is eligible only when `checked_at` is within `LEIGOD_LINE_HEALTH_MAX_AGE_SECONDS`, default 180 seconds, and its `config_sha256` matches current catalog material.

### 4.4 Runtime selection observability

The existing node heartbeat runtime JSON gains a bounded `leigod_forward_selections` list with forward ID, route ID, combination ID, line ID, mode, state, last switch time, and last error. The panel stores only the latest bounded state in `nodes.agent_config_json`; no unbounded event table is added in this edition.

## 5. Control-Plane Flow

### 5.1 Administrator saves a line

1. Validate the positive line ID, probe fields, and SHA-256.
2. Resolve `LEIGOD_LINE_TOML_<line_id>`.
3. Decode and hash the TOML without returning its bytes to the browser.
4. Permit saving incomplete metadata, but force `is_active=0` when configuration is missing or mismatched.
5. Republish management state only to nodes affected by active combination memberships.

### 5.2 Administrator saves a combination

1. Validate the existing ingress fields.
2. Normalize and deduplicate ordered line memberships.
3. Require one or more enabled, active, configuration-ready lines for an active dedicated LeiGod single combination.
4. Update memberships and the legacy first-line projection atomically.
5. Re-evaluate affected route availability and republish affected forwards.

### 5.3 Administrator saves a route

1. Preserve the existing common-ingress guard.
2. Require exactly one enabled combination when `race_enabled=0`.
3. Require at least two enabled combinations when `race_enabled=1`.
4. Allow arbitrary line memberships and direction labels inside those combinations.
5. Show an operator warning when the database shape is valid but no node currently satisfies the health threshold.

### 5.4 Node management

The node-management response contains a new `leigod_lines` collection for every active, configuration-ready line referenced by an active dedicated LeiGod combination whose node group contains the node.

Each entry contains only the line ID, TOML bytes, expected hash, and probe policy. The shared session is included when this collection is non-empty. Existing sidecar uplink management remains compatible.

The agent atomically writes line TOMLs, verifies their hash, ensures `leigod@<line_id>.service`, and probes each desired line. Lines removed from desired state are stopped only when no currently published forward references them.

### 5.5 Heartbeat ingest and node selection

The heartbeat carries bounded per-line health rows. The panel upserts them after validating that each line is in that node's desired set.

Forward placement filters nodes by route protocol requirements:

- single: one healthy combination on the node;
- race: two or more healthy combinations on the same node;
- `both`: protocol health must be satisfied by the candidates used for both sides.

If no node qualifies, the panel returns `leigod_route_no_healthy_candidate` and does not create a selectable-looking active endpoint.

## 6. Publish Contract and Slave Runtime

### 6.1 Additive publish contract

Dedicated LeiGod publishes gain:

```json
{
  "leigod_forward": {
    "mode": "single",
    "route_id": 12,
    "health_max_age_seconds": 180,
    "switch_improvement_ms": 15,
    "minimum_hold_seconds": 30,
    "failure_threshold": 2,
    "recovery_success_threshold": 2,
    "candidates": [
      {
        "combination_id": 21,
        "line_id": 451,
        "socks_host": "127.0.0.1",
        "socks_port": 20451,
        "sort_order": 10,
        "tcp_connect_ms": 42,
        "tcp_healthy": true,
        "udp_healthy": false,
        "config_sha256": "..."
      }
    ]
  }
}
```

`mode` is derived from the route's `race_enabled`; it does not change `client_mode`.

TOML and session bytes are excluded from publish tasks and remain in authenticated node management.

### 6.2 Runtime helper

A source-controlled slave helper owns the stable public TCP listener and SOCKS5 connection setup. It receives an atomically replaced JSON config per forward.

- In single mode it follows membership priority with sticky failover.
- In race mode it concurrently probes candidates and applies latency hysteresis.
- It binds only the existing assigned slave ingress port.
- It never opens a line SOCKS port publicly.
- It uses bounded connect, handshake, idle, and drain timeouts.
- Reload starts a new generation for new connections and lets existing connections drain.
- Invalid or empty candidate config exits non-zero and leaves the endpoint unhealthy.

The missing UDP fanout helper is shipped in the same release, but its runtime is enabled only for candidates with proven UDP data-plane health.

Source, `artifacts/`, installer payloads, and `artifacts/versions.json` are updated together. The slave agent version is bumped so the panel can gate the new publish contract.

## 7. Availability and Switching Defaults

Defaults are operator-configurable and deliberately conservative:

| Setting | Default |
| --- | --- |
| Health freshness | 180 seconds |
| Probe interval | 15 seconds |
| Probe connect timeout | 2 seconds |
| Failure threshold | 2 consecutive failures |
| Recovery threshold | 2 consecutive successes |
| Race improvement | 15 ms |
| Minimum winner hold | 30 seconds |
| Maximum candidates per route/node | 8 |

Disabled, stale, hash-mismatched, service-failed, protocol-failed, or over-cap candidates are excluded. Ordering is deterministic by configured membership order, measured latency, line ID, then combination ID as appropriate for the selected mode.

## 8. Admin Experience

The administrator receives:

- a LeiGod Lines resource page with configuration readiness and per-node health counts;
- ordered line membership controls on the Forward Combination edit page;
- route validation for single versus race cardinality;
- current healthy candidate count and explicit unavailable reasons;
- node detail rows for line service, TCP/UDP health, latency, freshness, and last error;
- forward detail state showing the selected combination and line without exposing TOML/session material.

Tenants continue to see the existing route label, description, badge, and availability only. Internal line IDs, TOML state, and node-level failures remain administrator-only.

## 9. Migration and Initial Data

The schema migration is additive and idempotent.

- Existing positive `forward_combinations.leigod_line_id` values are imported into catalog and membership rows, inactive until configuration readiness is proven.
- No fixed five-line seed is embedded in the migration.
- Initial production data is inserted through an explicit, repeatable operator command after the five local TOMLs are installed in panel environment and their hashes are verified.
- That command creates or updates the five combinations and five non-racing routes by stable names, then creates or updates one all-direction race route.
- It does not create billing plans, services, or tenant bindings when none exist.

## 10. Rollout

1. Back up the panel database, `.env`, deployed panel files, and current slave artifacts.
2. Deploy the additive schema and panel code with line catalog disabled.
3. Install the five verified TOMLs in the panel environment and check exact hashes.
4. Update one Shanghai slave as a canary and verify management, line services, heartbeat rows, and direct TCP forwarding.
5. Roll the agent artifact to the remaining eligible slaves.
6. Create the five catalog rows and combinations while routes remain inactive.
7. Activate and verify one pinned single route.
8. Activate the remaining single routes after independent target-through-line tests.
9. Activate the race route only after one node reports at least two healthy distinct combinations and a forced winner failure switches new traffic.
10. Leave UDP unavailable unless a complete data-plane probe succeeds.

Rollback disables the routes and catalog first, restores the previous agent artifact, reverts deployed panel files, and restores the database backup only when a schema rollback is required. It never deletes unrelated node or forward state.

## 11. Verification and Acceptance

Automated tests must prove:

- arbitrary line IDs can be added, disabled, reordered, and assigned to multiple combinations;
- missing or hash-mismatched configuration cannot become active;
- combination save is atomic and maintains the legacy first-line projection;
- non-racing routes require exactly one enabled combination;
- racing routes require at least two enabled same-ingress combinations;
- stale and protocol-ineligible health rows are excluded;
- single placement requires one healthy candidate on the selected node;
- race placement requires two healthy combinations on the same selected node;
- management sends only desired line material and session data;
- heartbeat ingest rejects unrelated/unbounded health rows;
- publish payloads contain candidate metadata but no TOML/session bytes;
- the slave helper performs SOCKS5 TCP connect, sticky single failover, race hysteresis, quarantine, and recovery;
- UDP is unavailable after handshake-only success and available only after a complete reply probe;
- missing helper, missing binary, missing session, missing TOML, empty candidate set, and stale health all fail explicitly;
- source/artifact/installer/version manifests remain synchronized.

Live acceptance requires fresh evidence that:

1. All intended slaves remain heartbeat-active.
2. Each activated initial line returns the expected test content through its own SOCKS path.
3. A single route survives failure of its current line by moving to another configured member when one exists.
4. The all-direction race route selects among at least two healthy combinations on the same slave.
5. Stopping the winning line causes a bounded switch and new TCP traffic succeeds through another direction.
6. A recovered line is readmitted only after the success threshold and hold rules.
7. No route is advertised usable when its protocol-specific candidate count is below the required threshold.
8. Public health, admin state, task payload, slave process, listening socket, and target response agree on the realized route.

## 12. Non-Goals

- Restoring sidecars or implementing bond/MPTCP aggregation.
- Treating race as a new `client_mode` value.
- Hard-coding the five initial directions into application logic.
- Storing line TOML or account session bytes in Git, normal logs, tenant responses, or provisioning tasks.
- Claiming native UDP support for a line that has not passed a live data-plane probe.
- Creating customer plans, purchases, or services without an explicit product decision.
