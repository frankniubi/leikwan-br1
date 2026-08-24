# Ultra Catalog Topology Metadata Design

Date: 2026-08-24
Status: Approved

## Objective

Make every source-discovered Ultra line groupable in the Panel before any
short-lived connection material is acquired. Material lifecycle state must not
control whether an administrator can see the line's catalog ingress and
catalog destination.

## Current Problem

The source inventory currently merges TOML numeric fields with `index.json`.
Only part of the 110-line inventory has descriptive index metadata. The Panel
then classifies both ingress and destination from the same combined
`title/family_key` text. Lines without that text remain `unknown`, even though
they exist in the official catalog.

`not acquired` and `expired` describe short-lived connection material only.
They are not topology states and must not prevent inventory categorization.

## Approved Approach

Use safe, current `linelist` output as the authoritative grouping source:

- catalog ingress label from the first title segment;
- catalog destination label from the second title segment;
- discovery IP from catalog/TOML metadata;
- optional observed relay IPs only after material has been acquired.

The Bilibili zone endpoint remains client-location detection only. It cannot
classify an arbitrary discovery or relay IP and is not used for catalog
enrichment.

## Source Inventory Contract

Each inventory line may add these bounded, non-secret fields:

```json
{
  "line_id": 453,
  "title": "Shanghai-Tokyo-BGP",
  "family_key": "Shanghai-Tokyo-BGP",
  "catalog_ingress": "Shanghai",
  "catalog_destination": "Tokyo",
  "discovery_ip": "203.0.113.20",
  "config_sha256": "<sha256>"
}
```

The source agent merges metadata in this order:

1. a root-only cache populated by current `linelist` output;
2. the existing line `index.json` record;
3. safe numeric and discovery-IP fields from the TOML file.

Explicit catalog fields win over inferred title segments. The agent sends no
ports, credentials, sessions, account identifiers, line-file contents, or
material. Inventory remains capped at 256 lines and 256 KiB. Its normalized
fingerprint includes the catalog labels so a metadata change triggers upload.

The source groups TOMLs by `(game_id, area_id)`, queries each unique pair once,
and never invokes `startacc` for categorization. Queries are sequential,
timeout-bounded, and spread across polling cycles after material renewal jobs.
No city list or IP-geolocation inference is used. A successful current result
replaces that pair's cached records; a failed request retains the last safe
result. Invalid, oversized, ambiguous, or control-character-bearing values
become empty rather than failing the complete inventory.

Until every current `(game_id, area_id)` pair has completed at least one
successful query, local TOML records remain visible to avoid a partial scan
retiring valid lines. Once the scan is complete, the current catalog becomes
the availability boundary: a local config omitted by a successful result is
retained as Panel history with `is_source_present = 0` and is hidden from the
default available-line view.

## Panel Storage And Classification

Add nullable bounded columns to `ultra_lines`:

```text
catalog_ingress_label VARCHAR(191) NULL
catalog_destination_label VARCHAR(191) NULL
```

Automatic classification uses separate inputs:

- ingress: `catalog_ingress_label`, then source title/family fallback;
- destination: `catalog_destination_label`, then source title/family fallback.

An explicit admin override remains authoritative and is never overwritten by
inventory refresh. New or existing lines remain disabled until an administrator
enables them.

The migration is additive and idempotent. Existing rows are backfilled on the
next signed inventory upload; no material acquisition is required.

## Admin Interface

The `/admin/ultra?view=lines` table separates topology from material:

```text
Line ID
Source title
Catalog ingress
Effective ingress zone
Catalog destination
Effective destination zone
Discovery IP
Observed relay IPs
Material state / expiry
Policy
```

Ingress and destination filters continue to use effective normalized zone
codes. Raw catalog labels remain visible when classification is `unknown`, so
the administrator can create or apply a safe override without activating the
line.

Zone aliases are limited to geographic labels actually observed in current
catalog output. Domestic-service category markers, game names, and generic
backup titles are not guessed into geographic zones. A line-specific admin
override may supply verified topology when the catalog title is
non-geographic.

## Material Lifecycle

No change is made to on-demand acquisition or renewal:

- inventory topology exists independently of material;
- only active, enabled, assigned Ultra lines request material;
- due material is renewed in the existing bounded refresh window;
- unassigned or disabled lines report `not required`, even if historical
  material has expired;
- `expired` is reserved for material required by an active Ultra forward;
- observed relay IPs are clearly marked as runtime observations, not catalog
  ingress or destination labels.

## Resource And Failure Bounds

- No new listener, gateway, slave, or sidecar.
- No per-line Bilibili requests.
- No automatic acquisition for all catalog lines.
- Catalog files are size-checked and line-count bounded.
- Source polling, acquisition concurrency, retry count, and queue bounds remain
  unchanged.
- A missing, malformed, or not-yet-complete catalog snapshot preserves numeric
  inventory instead of prematurely retiring local lines.

## Acceptance Criteria

1. A line with no material can display catalog ingress and destination.
2. Destination classification uses the explicit exit alias even when the title
   is missing or ambiguous.
3. Admin overrides survive later inventory uploads.
4. Inventory payloads and Panel HTML contain no secret or business port.
5. Every line currently returned by `linelist` is imported without starting a
   data-plane process. A local line omitted after all catalog pairs have been
   scanned remains available only through the explicit unavailable-history
   view and receives no guessed metadata.
6. Source-agent, Panel inventory, admin page, migration, and no-slave contracts
   pass focused regression tests.
7. Production deployment preserves the outbound-only source role and passes
   Panel health/authentication canaries.
