# Signal Chamber Homepage and Readable Core Web Redesign

**Date:** 2026-07-25  
**Status:** Approved for implementation planning  
**Repository:** `leikwan-br1`  
**Visual process:** Superpowers brainstorming visual companion

## Goal

Replace the current over-compressed Web presentation with two purpose-built experiences:

1. a distinctive, cinematic public homepage that communicates multi-Slave transport without becoming a dashboard; and
2. readable, task-focused Admin and Tenant workflows that prioritize scanning, comparison, and repeated operations.

The redesign must preserve the current application behavior, routes, authorization, CSRF protection, database contracts, provisioning behavior, and client-local strategy privacy.

## Approved Directions

### Public Homepage: C2, Signal Hero and Light Detail

The first viewport is a dark, immersive Signal Chamber. Multiple animated transport lanes converge into one flow to express aggregation. The section below changes to a bright technical canvas for readable routes, architecture, and product content.

Locked homepage decisions:

- The H1 is `利群會聚 LeiKwan Bridge`.
- The hero uses high-contrast black, white, signal red, healthy green, and route blue rather than a soft single-hue palette.
- The principal visual is a moving multi-lane transport field, not a decorative card illustration.
- The first viewport contains two commands: `Open Console` and `View Network`.
- The bottom edge of the first viewport reveals the beginning of the next section on desktop and mobile.
- The hero may show only truthful server-known signals, such as available Slave count, region count, or Sidecar state.
- The hero and Web panel do not show the selected client strategy, client-measured latency, or invented throughput claims.
- Below the hero, the page switches to a light background with readable route and architecture content.
- The page does not use a marketing-card matrix.
- Motion obeys `prefers-reduced-motion`; mobile reduces lane and particle count.

### Core Workflows: N2, Focused Views and Detail Pages

Core operational pages use a focused list as their default view. Installation, defaults, updates, editing, and advanced settings no longer appear as one long stack below the list.

The core redesign scope is exactly:

- Homepage
- Slave Nodes
- Services
- Clients
- Tenants
- Forwards
- Transport Endpoints

Authentication, registration, Knowledgebase, and other Admin/Tenant pages do not receive information-architecture rewrites in this pass.

## Core Page Structure

### Slave Nodes

`Slave Nodes` is the reference implementation for the new operational pattern.

It has four page-local views:

- `Nodes`: default searchable and filterable node list
- `Install`: manual installation command and identity guidance
- `Edge Defaults`: CDN/SNI, public-port, and path defaults
- `Updates`: selected-node and group rollout controls

Clicking a node opens a dedicated detail/edit surface. Advanced TLS, credential, agent interval, port-range, traffic price, and JSON settings live in grouped detail sections. The main list does not render those forms.

The Nodes table contains approximately six high-value columns:

- Node, with IP as secondary text
- Region or group
- Status
- Load
- Last heartbeat
- Row action menu

Usage history, agent version, credentials, certificates, and configuration appear in node detail rather than permanent list columns.

### Services, Clients, Tenants, Forwards, and Transport Endpoints

Each page follows the same boundary:

- list pages provide search, useful filters, status, key identifiers, and the principal creation command;
- row selection reveals bulk commands only when applicable;
- row menus contain secondary actions;
- edit and advanced configuration use a dedicated detail surface;
- destructive actions remain separated and require the existing confirmation flow;
- no existing permission or data behavior changes.

The exact fields remain domain-specific. The shared structure must not flatten different resources into an artificial generic model.

## Full-Site Sheet Standard

All existing Admin and Tenant tables receive the new Sheet visual standard, including pages outside the core information-architecture scope.

Locked Sheet rules:

- 14 px body data and at least 11 px table headers
- approximately 52-54 px desktop rows for primary lists
- five to seven visible high-value columns where a core page is restructured
- primary and muted secondary values may share one cell
- tabular numerals for IDs, load, traffic, prices, and durations
- restrained row dividers and a light hover state
- sticky headers inside bounded scrolling regions where useful
- predictable search and filter toolbar placement
- status communicates through text, symbol, and accessible color together
- row actions use one menu trigger rather than several permanent buttons
- selected-row bulk actions appear contextually
- mobile uses labelled structured rows for core lists instead of squeezing wide tables
- non-core tables may remain horizontally scrollable when transforming them would alter behavior
- empty, loading, error, offline, and permission states use high-contrast bounded regions

The Sheet standard replaces the current compressed serif-like appearance and undersized operational text. It does not introduce decorative cards around tables.

## Shared Visual System

The public site and product console share brand tokens but have different composition rules.

### Homepage Tokens

- Base: near-black and true white
- Signal: red
- Healthy path: green
- Alternate route: blue
- Maximum card radius: 6 px, used only for real controls or bounded repeated items
- Hero scene: full-width and unframed within the first viewport

### Console Tokens

- Warm-neutral page background
- White working surface
- Near-black primary text
- Clear gray secondary text with accessible contrast
- Green, amber, and red reserved for state
- 4-6 px control and sheet radius
- 14-16 px operational body text
- Comfortable line height and row height
- No negative letter spacing

The console remains quiet and work-focused. It does not inherit the homepage's cinematic black environment.

## Navigation and Interaction

- Existing global Admin and Tenant navigation remains.
- Core page-local views use tabs because they separate real operational modes, not obsolete feature categories.
- Direct links and browser refresh restore the selected view through a query parameter or an existing child route.
- `CF Location Groups`, `Forward Combinations`, `Forward Routes`, and `Chains` remain absent from global navigation.
- Familiar icons are used for row menus, search, filtering, refresh, and destructive actions where the enabled icon library provides them.
- Keyboard focus remains visible.
- Menus, dialogs, tabs, and form controls expose appropriate accessible names and states.

## Compatibility Boundaries

The redesign must not change:

- PHP route behavior
- form action targets
- CSRF handling
- authorization or role checks
- database queries or persistence contracts unless a small read-only projection is necessary for presentation
- provisioning or agent behavior
- Slave/Sidecar topology
- the `client -> slave -> sidecar` constraint
- client-local Forward strategy storage

Client strategy and client latency remain local. They are not added to panel forms, panel tables, panel config payloads, heartbeats, or Web diagnostics.

## Responsive Behavior

- Desktop retains the left console navigation.
- Mobile uses the existing accessible navigation toggle/drawer.
- Homepage hero motion is reduced on narrow viewports.
- Core tables become labelled structured rows where specified.
- Fixed-format controls use stable dimensions so loading, status, and action states do not shift layout.
- Every target viewport must have zero incoherent overlap and zero page-level horizontal overflow.

Target verification widths:

- 1440 px desktop
- 1024 px compact desktop/tablet landscape
- 768 px tablet
- 390 px mobile
- 360 px narrow mobile

## Error and Empty States

- Empty lists explain the missing resource and expose one relevant creation or installation command.
- Errors retain actionable server messages without exposing secrets.
- Offline and probation states remain distinct.
- Disabled or unavailable commands explain their state through accessible text or tooltip.
- Destructive operations remain visually separated from normal editing.
- Long command output and JSON remain in bounded, scrollable code regions.

## Verification

Implementation is not complete until all of the following are proven:

- focused PHP source and behavior regression tests
- existing authentication, authorization, CSRF, and controller regression tests
- strategy/latency privacy source guards
- table and page-template source guards
- PHP syntax checks
- desktop and mobile gstack screenshots for the homepage
- desktop and mobile gstack screenshots for Slave Nodes
- representative screenshots for every other core workflow
- sampled non-core pages proving the Sheet standard applies
- no console errors
- no page-level horizontal overflow at all target widths
- keyboard traversal and visible focus for tabs, filters, menus, dialogs, and forms
- `prefers-reduced-motion` behavior for the homepage scene
- production asset cache busting
- post-deploy public homepage and authenticated console checks

The known unrelated PHP baseline failures must be compared against the pre-change baseline rather than silently reported as new failures or ignored.

## Approved Visual Evidence

The interactive Superpowers comparison is stored under the ignored directory:

`/.superpowers/brainstorm/redesign-1784975825/content/`

Approved selections:

- `homepage-direction.html`: C, Signal Chamber
- `signal-chamber-depth.html`: C2, Signal Hero and Light Detail
- `slave-nodes-layout.html`: N2, Focused Views and Detail Page
- `sheet-standard.html`: approved full-site readable Sheet standard

These mockups are design evidence, not production markup.
