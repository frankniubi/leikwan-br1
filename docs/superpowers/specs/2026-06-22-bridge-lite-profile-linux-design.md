# Bridge Lite Profile-First Linux Design

## Goal

Evolve `bridge-lite` from its current target/sidecar-first skeleton into a **profile-first** product that supports multiple client profiles per user, lets each profile aggregate multiple purchased VMs plus multiple forwards, automatically derives forward destination IPs from the user's purchased server IPs in WHMCS, keeps the client install entry unchanged, and only changes the **Linux** client first.

The resulting design must satisfy these explicit requirements:

1. New forward destination IPs are **auto-derived**, not manually entered.
2. The auto-derived destination IP must be the **user's purchased server IP**, with **WHMCS as the authority** and local bridge-lite data as a cache.
3. A user may own **multiple client profiles**.
4. A single client profile may aggregate **multiple VMs / servers** and each VM may carry **multiple forwards**.
5. A profile starts **as a whole**: when Linux starts a profile, every VM + forward inside that profile is activated together.
6. Multi-line / single-line selection must live in the **client profile**, not as a sidecar-facing surface.
7. The default line strategy is **free single-line**.
8. The Linux client must **ping candidate entries** and automatically choose the lowest-latency allowed entry.
9. The Linux client must expose **profile-level entry policy**: automatic entry selection, **carrier filters**, and **whether paid lines are allowed**.
10. The client install entry and main install flow should remain as stable as possible.
11. **Linux is the only client changed now**; Windows and Android remain untouched for this phase.
12. The sidecar UI must become **completely unusable** in both client and admin surfaces; sidecars remain backend-only runtime objects.

## Current problems to fix

- The current bridge-lite client flow still centers on `targets`, `nodes`, `sidecars`, and exposure allocation, but it does not have a first-class `client_profile` model.
- Forward destination IPs are still shaped like runtime exposure inputs rather than being derived from the user's purchased server inventory.
- The current target model does not yet formally cache WHMCS-owned server IP / hostname state as the source of forward destinations.
- Multi-line and single-line choices are not yet modeled as a client-facing profile policy with carrier filters and paid/free selection rules.
- Sidecars are still exposed in admin/client UI even though the requested future UX wants `profile / VM / forward / entry line` as the front-door vocabulary.
- Existing Windows and Android clients still depend on the current shape, so a breaking server-side replacement would create unnecessary risk.

## What this is not

- Not a full simultaneous rewrite of Linux, Windows, and Android.
- Not a direct topology change away from `client -> slave -> sidecar`.
- Not a new manual sidecar onboarding UI.
- Not a design where every forward gets a newly allocated public IP.
- Not a design where destination IP is keyed from operator input when WHMCS already owns that server identity.
- Not a design where sidecar becomes a user-visible primary object.

## Approaches considered

1. **Compatibility-first profile layer on top of the current bridge-lite skeleton**
   - Add `client_profiles` and VM/forward mapping on top of the existing target/runtime skeleton while keeping old client paths alive for Windows/Android. **Chosen.**
   - Best fit for "Linux first, install entry unchanged, sidecar UI hidden, old clients untouched".

2. **Hard rewrite to a completely new profile/VM/forward-only backend**
   - Cleanest model in theory, but too risky for a first pass because Windows/Android would either break or force simultaneous protocol work.

3. **UI-only relabeling while keeping the backend semantics unchanged**
   - Faster short-term, but it would leave the real product model inconsistent and make future Linux runtime work harder.

## High-level architecture

The approved design has four layers:

1. **WHMCS-backed server inventory authority**
   - WHMCS remains the source of truth for the purchased service, server hostname, and server IP.
   - Bridge-lite mirrors that data into local `targets` / VM cache records.

2. **Profile-first bridge-lite control plane**
   - Users own multiple `client_profiles`.
   - Each profile links to multiple cached VMs and multiple profile-scoped forwards.
   - Each profile carries its own line policy: carrier filters, auto-entry behavior, free/paid allowance.

3. **Linux profile runtime contract**
   - Linux logs in normally, lists profiles, chooses one profile, fetches the whole runtime payload, pings allowed entries, then starts the entire profile in one shot.
   - Runtime status reports include profile-specific metadata.

4. **Backend-only sidecar runtime layer**
   - Sidecars remain internal runtime objects for orchestration, heartbeat, and legacy runtime compatibility.
   - Neither admin nor client uses sidecar as a front-door management concept anymore.

## Core design rules

- WHMCS remains the **authority** for purchased server IP and hostname data.
- Bridge-lite stores a **local cache** of VM/server information for runtime and resilience.
- New forwards must not require manual destination IP entry when the attached VM already has a cached purchased server IP.
- A **user** may own multiple `client_profiles`.
- A **client_profile** may aggregate multiple VMs and multiple forwards.
- Profile startup is **whole-profile activation**, not one-VM-at-a-time or one-forward-at-a-time.
- Profile line policy is **profile-scoped**, not VM-scoped and not forward-scoped.
- Default profile line behavior is **free single-line only**.
- The Linux client chooses the actual entry by **pinging the allowed candidate set** and selecting the best one.
- The user may narrow the allowed candidate set with **carrier filters** and an **allow paid lines** toggle.
- Install entry, download location, and installer shell should remain as stable as possible.
- Windows and Android must continue using the current path for this phase.
- Sidecars remain operationally present but **not directly user/admin manageable in the UI**.
- If WHMCS temporarily fails to provide server IP data, local cached VM data must be preserved rather than wiped.

## Product model

### Top-level objects

The new user-facing model becomes:

- **User**
- **Client Profile**
- **VM / Server**
- **Forward**
- **Entry Line**

### Client profile

Each `client_profile` is a Linux-first runtime grouping that answers two questions:

1. Which VMs and forwards should run together?
2. What line-selection policy is allowed for this group?

Each profile should track:

- `profile_id`
- `user_id`
- `name`
- `is_default`
- `auto_select_entry` (fixed `true` in v1; retained as an explicit policy field)
- `selected_carriers`
- `allow_paid_lines`
- `status`
- `revision`
- `created_at`
- `updated_at`

### VM / server cache object

The current `targets` object is extended and re-centered as a cached VM/server mirror.

Each VM/server cache record should track:

- `target_id`
- `account_id`
- `whmcs_service_id`
- `vm_label`
- `server_hostname`
- `server_ip`
- `ip_state` (`fresh`, `stale`, `missing`)
- `vm_kind`
- `order_id`
- `package_id`
- `carrier_preference`
- `speed_cap_mbps`
- `default_protocols`
- `sidecar_mode`
- `status`
- `last_synced_at`

### Forward object

A forward becomes a profile-scoped declaration linked to a VM.

Each forward should track:

- `forward_id`
- `profile_id`
- `target_id`
- `name`
- `target_ip` (materialized from cached VM IP, not manually authored as the primary workflow)
- `target_port`
- `protocol`
- `enabled`
- `public_port` or `listener_port` if the listener remains explicit
- `created_at`
- `updated_at`

### Entry line object

The current `nodes` fleet becomes **Entry Lines** in the front-door product model.

Each line should track:

- `entry_line_id`
- `node_class`
- `carrier`
- `pricing_class` (`free`, `paid`)
- `priority`
- `host`
- `ddns_label`
- `supports_tcp`
- `supports_udp`
- `status`
- `eligible_for_profile_runtime`

## Destination IP derivation model

### Source of truth

The forward destination IP is derived from the cached VM/server record.

Priority order:

1. Current WHMCS service/server IP
2. Last successfully synced local cache in `targets.server_ip`
3. No runtime issuance if neither exists

### Sync behavior

On any of these actions:

- user login
- admin manual sync
- scheduled sync

bridge-lite refreshes VM cache data from WHMCS.

For each purchased server / service, it should mirror:

- `whmcs_service_id`
- `server_hostname`
- `server_ip`
- `status`
- `last_synced_at`

### Drift handling

- If WHMCS returns a new server IP, the cached VM/server record is updated.
- Forwards attached to that VM automatically inherit the new destination IP on the next runtime config generation.
- If WHMCS temporarily cannot return the IP, the cached local IP is retained and the VM is marked `ip_state=stale`.
- The system must **not clear** a previously valid cached IP merely because WHMCS had a transient failure.

### Eligibility rule

- A VM with `ip_state=missing` cannot enable forwards.
- A VM with `ip_state=stale` may be visible in admin/client, but new runtime issuance should be blocked until data is refreshed or explicitly repaired.

## Client experience

### Install entry

The install entry should remain stable:

- download URLs remain in place
- installer entry remains in place
- the main install shell stays as close as possible to the current flow

The first meaningful change happens **after Linux login succeeds**.

### Linux profile-first flow

Linux client behavior becomes:

1. User installs Linux client from the existing entry.
2. User logs in through the existing auth entry.
3. Client calls `GET /api/client/profiles`.
4. User chooses a profile.
5. Client calls `POST /api/client/profiles/{id}/runtime-config`.
6. Client receives the entire profile payload:
   - profile policy
   - all linked VMs
   - all linked forwards
   - allowed candidate entry lines
7. Client pings the allowed candidate entry lines.
8. Client selects the lowest-latency allowed line.
9. Client starts the entire profile together.
10. Client reports runtime status with profile-specific metadata.

### Profile policy UX

The Linux client should expose these profile-level controls:

- **Auto-entry mode** (the active runtime strategy in this phase)
- **Carrier filters** (multi-select)
- **Allow paid lines** (boolean)

Behavior:

- v1 remains **auto-entry only**; there is no manual entry/node picker in this phase
- default mode is free single-line only
- carrier filters shrink the candidate set
- if `allow_paid_lines=false`, paid lines are excluded from the candidate set
- if `allow_paid_lines=true`, paid lines may join the candidate set
- line selection is still automatic within the allowed set

### Profile startup semantics

The chosen startup semantic is **whole-profile startup**.

That means:

- one chosen profile starts all linked VMs + forwards together
- the Linux runtime does not stop after selecting a single VM
- the Linux runtime does not require per-forward manual launch toggles in this phase

## Admin experience

### New admin front-door vocabulary

The admin surface should speak in these areas:

- **Profiles**
- **VMs / Servers**
- **Forwards**
- **Entry Lines**
- **WHMCS Sync**
- **Audit**

### Renames and removals

- Current `Targets` page becomes **VMs / Servers**
- Current `Nodes / Sidecar Points` page becomes **Entry Lines**
- Current `Sidecars` page disappears from admin UI
- Sidecar-specific direct management disappears from client UI

### Admin actions

#### Profiles

Admin can:

- create / edit / disable profiles
- assign multiple VMs to a profile
- assign multiple forwards to a profile
- set profile defaults
- manage carrier filters
- toggle paid-line eligibility

#### VMs / Servers

Admin can:

- inspect cached WHMCS service/server information
- see `server_ip`, `server_hostname`, `ip_state`, `last_synced_at`
- confirm package / validity / service identity
- repair mapping if a WHMCS service relationship needs correction

#### Forwards

Admin can:

- create / edit / disable forwards under a profile + VM
- set destination port and protocol
- view the auto-derived destination IP as read-only runtime data

#### Entry Lines

Admin can:

- manage free vs paid line classification
- set carrier labels
- set priority and availability
- mark whether an entry line is eligible for profile runtime selection

## Sidecar hidden-runtime model

The sidecar layer is not removed from the runtime immediately. Instead:

- sidecar records remain in the backend
- sidecar heartbeats remain in the backend
- sidecar tokens / compatibility surfaces may remain internally
- new Linux profile flows do not expose sidecars in admin/client UI

This satisfies "sidecar interface cannot be used" without forcing a high-risk runtime deletion before the profile model is stable.

Operationally, sidecar becomes:

- a backend runtime object
- an orchestration detail
- a compatibility layer
- an audit subject if needed internally

But not:

- a first-class admin object
- a first-class client object
- a visible step in the new Linux user journey

## API design

### New profile APIs

#### `GET /api/client/profiles`

Returns the current user's available client profiles.

Each summary should include:

- `profile_id`
- `name`
- `is_default`
- `vm_count`
- `forward_count`
- `selected_carriers`
- `allow_paid_lines`
- `status`
- `revision`

#### `GET /api/client/profiles/{id}`

Returns full profile detail.

Payload should include:

- profile metadata
- linked VMs
- cached server hostname/IP for each VM
- forwards grouped under each VM
- line policy summary

#### `POST /api/client/profiles/{id}/runtime-config`

Primary Linux runtime contract.

Returns:

- profile metadata
- profile `revision`
- all linked VMs
- all linked forwards
- destination IP already derived from each VM cache record
- filtered candidate entry lines after applying:
  - free/paid policy
  - carrier filters
  - protocol constraints

### Existing runtime status API

`POST /api/client/runtime/status` remains, but Linux should now send additional fields:

- `profile_id`
- `profile_revision`
- `selected_entry`
- `selected_carrier`
- `used_paid_line`

### Compatibility rule

Old runtime APIs remain alive for Windows and Android during this phase.

Server-side routing should branch by client capability / platform so that:

- Linux can adopt the new profile contract
- Windows / Android remain on the legacy contract

## Data model changes

### Extend `targets`

Add:

- `whmcs_service_id`
- `server_hostname`
- `server_ip`
- `ip_state`
- `last_synced_at`

This formalizes `targets` as the VM/server cache mirror.

### Add `client_profiles`

Columns:

- `id`
- `user_id`
- `name`
- `is_default`
- `auto_select_entry` (stored explicitly even though v1 keeps it `true`)
- `selected_carriers` JSON
- `allow_paid_lines`
- `status`
- `revision`
- `created_at`
- `updated_at`

### Add `client_profile_targets`

Columns:

- `profile_id`
- `target_id`
- `sort_order`
- `enabled`

Purpose:

- link one profile to many VMs
- preserve explicit ordering / enable flags

### Add `client_profile_forwards`

Columns:

- `id`
- `profile_id`
- `target_id`
- `name`
- `target_port`
- `protocol`
- `enabled`
- `public_port` or `listener_port`
- `created_at`
- `updated_at`

Purpose:

- store the forward declarations owned by the profile and attached VM
- avoid requiring destination IP manual input in the primary workflow

### Extend `nodes`

Add fields that let admin express profile runtime policy, including:

- `pricing_class`
- `carrier`
- `eligible_for_profile_runtime`

The exact column names can be implementation-specific, but the data model must distinguish:

- free vs paid
- carrier identity
- runtime eligibility

## Migration and rollout plan

### Phase 1 — schema first

Add the new profile tables and extend the VM cache schema without changing existing client behavior.

### Phase 2 — admin information architecture

Move admin vocabulary to:

- Profiles
- VMs / Servers
- Forwards
- Entry Lines

Hide sidecar UI from admin and client.

### Phase 3 — WHMCS-backed VM caching

Start syncing purchased server IP / hostname into the extended VM cache.

### Phase 4 — profile-first APIs

Ship the new profile APIs while keeping old APIs alive.

### Phase 5 — Linux-only client adoption

Update Linux to:

- list profiles
- choose a profile
- fetch profile runtime config
- ping candidate entry lines
- auto-select best allowed line
- start the entire profile

### Phase 6 — observe and stabilize

Use runtime status, revisions, and audit to confirm Linux profile runtime is stable before touching Windows / Android.

## Error handling and failure behavior

- If WHMCS does not return current server IP data, preserve the cached VM record and mark it stale.
- If a VM has no usable cached server IP, do not issue new runtime config for forwards attached to that VM.
- If a profile has carrier filters that eliminate every line, return a clear configuration error.
- If `allow_paid_lines=false`, no paid candidate should appear in Linux runtime config.
- If Linux profile startup fails for one VM/forward subset, the runtime status must report partial failure explicitly rather than silently pretending the whole profile is active.
- If old Windows/Android clients call legacy routes, they must continue to receive the current compatible shape.
- If sidecar backend health fails, the user-facing error should remain in profile/VM/runtime vocabulary rather than exposing sidecar management UI again.

## Auditing and observability

Audit should cover:

- profile created / updated / deleted
- profile VM membership changed
- profile forward changed
- carrier filters changed
- paid-line allowance changed
- WHMCS VM/server cache refreshed
- server IP drift detected
- Linux runtime selected entry line
- Linux runtime used paid line or free line
- profile runtime partial failure / full success

Admin observability should make it easy to answer:

- which purchased server IP was cached for a VM
- when it was last synced from WHMCS
- which profile revision Linux consumed
- which carrier/entry Linux selected
- whether a paid line was used

## Testing strategy

The design is not complete unless implementation can prove these behaviors:

- a user can own multiple client profiles
- one profile can link multiple VMs
- one profile can link multiple forwards across those VMs
- destination IP is automatically derived from cached purchased server IP
- WHMCS sync refreshes VM cache data
- a temporary WHMCS miss does not wipe a previously cached IP
- VMs with missing IP cannot issue new forwards/runtime
- profile line policy filters candidate entry lines correctly
- paid lines remain excluded unless explicitly allowed
- Linux can fetch profiles and one profile runtime payload
- Linux chooses the lowest-latency allowed entry based on ping
- Linux starts the entire profile together
- Linux runtime status includes profile metadata, selected carrier, and paid/free usage
- sidecar navigation and direct sidecar management disappear from admin/client UI
- Windows/Android legacy paths still receive compatible responses during this phase

## Success criteria

This redesign is successful only when:

- bridge-lite exposes **client profiles** as the main Linux runtime unit
- a single profile can aggregate multiple purchased VMs and multiple forwards
- forward destination IP is automatically derived from the user's purchased server IP
- WHMCS is the authority for server IP while bridge-lite caches it locally
- Linux can choose an entry automatically by ping among allowed candidates
- default behavior remains free single-line
- users can restrict by carrier and optionally allow paid lines
- install entry remains stable
- Linux is the only client changed in this phase
- Windows and Android continue to work without forced migration
- sidecar is no longer a directly usable admin/client UI concept
- the backend still preserves sidecar/runtime compatibility where needed
