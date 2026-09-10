# LeiGod Client Strategies and Precision Grid Web Redesign

**Date:** 2026-07-25
**Status:** Approved design, pending written-spec review
**Authoring process:** Superpowers brainstorming with visual companion

## 1. Objective

Deliver two related improvements without restoring any retired forwarding product mode:

1. Let each client choose a local per-Forward transport strategy named `Speed max`, `Latency Best`, or `Random` while every eligible Slave runs the same service.
2. Redesign every visible admin and tenant Web page with the approved `Precision Grid` design system.

LeiGod remains the only active backend. The three names are client scheduling strategies, not backend modes and not separate panel-side Forward definitions.

## 2. Mandatory Superpowers Process

The Web redesign is skill-driven from design through verification. Implementation must not introduce unreviewed visual decisions based on ad hoc model preference.

Required workflow:

1. `superpowers:brainstorming` and its visual companion define scope, information architecture, visual direction, page templates, and behavior. This specification records those approved decisions.
2. `superpowers:writing-plans` turns this specification into an implementation sequence with explicit files, tests, checkpoints, and rollback boundaries.
3. `superpowers:test-driven-development` supplies a failing test before each behavior or rendering change.
4. Visual implementation must be checked against the approved `Precision Grid` tokens and templates in this specification rather than improvised during coding.
5. `superpowers:verification-before-completion` gates completion claims with fresh automated and rendered-page evidence.

Any requested visual deviation must return to a Superpowers design review step and update this specification before implementation.

## 3. Product Semantics

### 3.1 Common topology

All three strategies retain the authorized topology:

```text
application
  -> stable local Forward listener
  -> client strategy dispatcher
  -> one or more eligible Slaves
  -> that Slave's LeiGod static Sidecar uplink
  -> Sidecar leikwan-bond server
  -> target
```

The panel realizes the same Forward service on every eligible distinct Slave and publishes the full usable endpoint set plus the existing authentication and Sidecar material.

The panel does not create three copies of the Forward, assign three local ports, or select the active client strategy.

### 3.2 Strategy names and behavior

#### Speed max

- Applies bandwidth aggregation to sustained TCP traffic.
- Uses two to eight healthy distinct Slave legs when productive.
- Splits framed application bytes across eligible legs.
- The Sidecar reorders and reassembles the frames into one target TCP connection.
- Small traffic may remain on one best leg until the existing bulk promotion threshold is met.
- One remaining healthy leg is a valid single-leg degradation, not a backend fallback.
- UDP uses one best healthy Slave and is never represented as aggregated bandwidth.

#### Latency Best

- Is the local default for a Forward with no saved client choice.
- Measures and ranks eligible Slaves entirely on the client.
- Pins each new TCP connection to the best current healthy Slave.
- Uses hysteresis before replacing a healthy selected Slave so small latency changes do not cause flapping.
- Keeps alternate Slaves locally eligible for failure recovery.
- UDP uses the best healthy Slave.

#### Random

- Selects uniformly from the locally healthy eligible Slave set for each new TCP connection.
- Pins the connection to the selected Slave for its lifetime unless failure requires migration within the same authenticated Sidecar bond session.
- Different simultaneous connections may use different Slaves.
- UDP selection is per flow and remains pinned until the flow expires or its Slave fails.
- Quarantined, cooling, stale, or failed Slaves are excluded from the random pool.

### 3.3 Strategy switching

- The local listening address and port remain stable.
- A strategy change affects new connections immediately.
- Existing connections continue on the runtime generation that accepted them.
- Old generations drain naturally and exit after their last connection closes.
- A bounded drain timeout handles abandoned connections without leaving permanent workers.
- Strategy switching never recreates the panel Forward, changes its target, or rewrites its backend mode.

### 3.4 Failure behavior

- With no healthy Slave, the client reports the Forward locally unavailable.
- `Speed max` degrades to one healthy leg without changing strategy identity.
- `Latency Best` selects the next eligible locally ranked Slave.
- `Random` retries selection from the remaining healthy pool.
- A Sidecar or authentication failure is not masked by selecting a legacy backend.
- Recovery readmits a Slave through the existing health, cooldown, and probation rules.

## 4. Control Plane and Privacy

### 4.1 Panel responsibility

The panel publishes only the material required to realize the shared service and let the client use the eligible endpoint set:

- Forward identity, local port, protocol, and target contract;
- eligible distinct Slave endpoints and stable logical identities;
- per-endpoint ingress authentication;
- Sidecar target and bond session authentication;
- existing runtime bounds such as maximum legs and timeouts.

### 4.2 Client-only state

The following state remains local and is not synchronized to or displayed by the panel:

- selected strategy;
- locally measured Slave latency;
- local ranking order;
- per-connection random choice;
- the fact that a user changed strategy.

The stable persistence key is the local tuple `(panel identity, client identity, forward_id)`. A missing or invalid value resolves locally to `Latency Best`.

No new periodic API request, request field, telemetry record, database column, or Web status element may be added for this client-only state. Existing required service health and accounting traffic remain unchanged, but must not be expanded to carry strategy or per-Slave client latency.

## 5. Client Experience

### 5.1 Windows and Android

Each Forward detail surface contains the approved descriptive vertical radio layout:

```text
Strategy

( ) Speed max
    Combine healthy Slaves through Sidecar

( ) Latency Best
    Use the lowest-latency healthy Slave

( ) Random
    Random healthy Slave for each new connection
```

Requirements:

- `Latency Best` is selected for an unseen Forward.
- The selection is stored locally per Forward.
- The control shows the local effective value immediately without waiting for the panel.
- Live status may show local connection and leg health, but no strategy or latency is uploaded merely to populate the panel.
- Text must wrap cleanly on narrow Android and Windows layouts.

### 5.2 Linux

Linux exposes the same three values through a local client command/config interface. The command validates the Forward identity and strategy, persists it under the same per-Forward semantics, and applies it only to new connections. It does not require a panel mutation.

## 6. Web Redesign Scope

All visible admin and tenant Web pages are in scope, including authentication and error surfaces that share the application shell.

Admin scope includes:

- Overview;
- tenants, registration codes, and user groups;
- plans and services;
- clients and client groups;
- credentials;
- Transport Endpoints/Sidecars;
- Slave groups and Slave nodes;
- Knowledgebase;
- visible billing, usage, task, diagnostics, and settings surfaces reached from those pages.

Tenant scope includes:

- overview, account, plans, and billing;
- services and Forward creation/detail flows;
- clients;
- Transport Endpoints;
- Slave groups;
- Knowledgebase;
- visible installation, diagnostics, usage, and error surfaces.

The redesign must not restore navigation entries for `CF Location Groups`, `Forward Combinations`, `Forward Routes`, or `Chains`. Compatibility routes may remain non-navigable for teardown or historical support.

The Web panel does not show `Speed max`, `Latency Best`, `Random`, client strategy selection, or client-measured Slave latency.

## 7. Precision Grid Design System

### 7.1 Visual direction

The approved direction is `Precision Grid`:

- high information density;
- hard, consistent dividers;
- compact type sized for operational scanning;
- restrained decoration;
- strong hierarchy from alignment, weight, spacing, and rules rather than large cards or gradients;
- clear semantic status colors.

### 7.2 Tokens

The implementation plan may refine accessible values while preserving this intent:

```css
--page-bg: #f3f3f0;
--surface: #ffffff;
--surface-muted: #e9e9e6;
--ink: #111111;
--muted: #60666c;
--line: #b9bec2;
--line-strong: #111111;
--success: #087f5b;
--danger: #c73f32;
--info: #2563b8;
--warning: #a86800;
```

- Typography uses the existing local system font stack; no remote font dependency is introduced.
- Letter spacing remains `0`.
- Border radius is 0 to 4 px, with one consistent value per component family.
- Gradients, decorative blobs, oversized headings, nested cards, and marketing-style hero layouts are excluded.
- Shadows are absent or limited to menus/dialogs that require elevation.
- Command buttons use text or familiar icons only where the command remains unambiguous.

### 7.3 Shared page templates

#### Overview

- compact page header with freshness indicator;
- one bordered metric strip rather than floating statistic cards;
- live health table;
- action/error queue;
- traffic or utilization visualization only where real data exists;
- no explanatory feature copy.

#### List and operations

- page title and primary action on one stable header row;
- one compact filter/search bar;
- dense table with sticky header when useful;
- status conveyed by text plus color, never color alone;
- row action opens a detail page/drawer without shifting column dimensions;
- empty state occupies the table body and provides only the next valid command.

#### Detail and edit

- identity/status header;
- unframed, ruled sections for related fields;
- labels above inputs;
- destructive actions separated from normal save controls;
- sticky or predictable save/cancel action row;
- diagnostics and command output use bounded monospace regions;
- no card nested inside another card.

## 8. Information Architecture

Admin navigation groups tasks without restoring retired modes:

- **Operations:** Overview, Services, Clients
- **Customers:** Tenants, Registration Codes, User Groups
- **Products:** Plans
- **Infrastructure:** Transport Endpoints, Slave Groups, Slave Nodes, Client Groups, Credentials
- **Support:** Knowledgebase
- **Context switch:** Tenant Area

Tenant navigation uses consistent language and task order:

- Overview
- Services and Forwards
- Clients
- Plans and Billing
- Transport Endpoints
- Slave Groups
- Account
- Knowledgebase

Context-only pages such as a Service detail or Forward creation step appear through breadcrumbs and local page navigation, not as permanent global tabs.

## 9. Responsive and Accessibility Rules

- Desktop uses a fixed-width navigation rail and fluid work area.
- Below the approved breakpoint, the rail becomes a menu without overlaying page content.
- Metric strips wrap into stable equal tracks.
- Tables either scroll in an explicit bounded region or switch to labelled rows; cells must not overlap.
- Forms use one column on narrow screens and retain complete labels and validation.
- Touch targets are at least 40 px on mobile surfaces.
- Focus state is visible on every control.
- Semantic color pairs meet WCAG AA contrast and include a textual state.
- Dialogs trap focus, have an explicit title, and close through a clear command.
- Long hostnames, tokens, commands, and translated text wrap or scroll inside bounded containers.

## 10. Implementation Boundaries

- Start from current `master`.
- Preserve current PHP server-rendered controller conventions unless the written plan proves a small shared renderer reduces risk.
- Centralize shared tokens and shell components instead of duplicating page-specific CSS.
- Keep current routes and backend behavior unless this specification explicitly changes them.
- Do not reintroduce deleted Services, Forwards, plans, users' service bindings, or unusable Slave nodes as sample data.
- Do not change the required `client -> slave -> sidecar` topology.
- Keep Slave ingress and bond ingress inside ports 7000-9000.

## 11. Verification

### 11.1 Strategy behavior

Automated coverage must prove:

- local default is `Latency Best`;
- local per-Forward persistence is isolated by panel/client/Forward identity;
- all three strategies consume the same published eligible Slave set;
- `Speed max` uses multiple productive TCP legs and degrades to one;
- `Latency Best` applies ranking and hysteresis without panel synchronization;
- `Random` chooses per new connection, excludes unhealthy Slaves, and remains pinned;
- strategy changes affect new connections while old connections drain;
- UDP never claims aggregation;
- one through eight distinct Slaves are handled; zero, duplicates, and oversized sets fail safely;
- no new API payload or database write carries strategy or client-measured latency.

Platform tests cover Windows/.NET, Android/Kotlin, Linux/Python, Rust bond scheduling, and PHP publication contracts as applicable.

### 11.2 Web rendering

Every visible admin and tenant route must be rendered and checked at representative desktop and mobile viewports.

Verification includes:

- no retired navigation tabs;
- correct active navigation and breadcrumbs;
- no overlapping or clipped text;
- stable table, toolbar, dialog, form, button, counter, and status dimensions;
- no horizontal page overflow except an intentional bounded table/command scroller;
- complete keyboard focus path;
- readable Simplified Chinese, Traditional Chinese, and English labels;
- no Web rendering of client strategy or client-measured Slave latency;
- screenshot comparison for the three shared page templates and representative complex pages;
- existing focused regressions plus the broad repository test sweep.

## 12. Acceptance Criteria

The work is complete only when:

1. The three client strategy names and behaviors match this specification on every supported client platform.
2. Strategy and client latency stay local and generate no additional panel synchronization.
3. Existing connections survive a local strategy switch while new connections use the new choice.
4. Every visible admin and tenant page uses the approved `Precision Grid` system and one of the shared templates.
5. Retired tabs remain absent and legacy backends remain unavailable as active product choices.
6. Automated tests and rendered desktop/mobile verification pass with fresh evidence.
7. The committed implementation and deployed artifacts remain reproducible from `master`.
