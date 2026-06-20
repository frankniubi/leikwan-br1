# LeiKwan Bridge Lite Design

## Goal

Build a separate `leikwan-bridge-lite` control plane and sidecar family that fixes the current admin exposure, moves the client experience to a VM-first workflow, adds auditable usage and ingress-IP tracking, and introduces a bridge-lite-specific sidecar agent with public-IP auto-reporting.

The resulting product must satisfy these explicit requirements:

1. `/admin` must not publicly redirect or expose the admin console.
2. Sidecar records must support `name`, `internet access IP` or `auto`, `port list`, `carrier`, and `speed cap`.
3. The sidecar agent must be a new bridge-lite-specific version, separate from legacy `leikwan-bridge`.
4. Admin must be able to inspect each user's usage and ingress IP history.
5. Client exposure flow must choose a concrete VM or order target, not start from a generic port list.
6. New exposures must default to `TCP + UDP`.
7. "User's Own Sidecar" must have a real install gate and registration flow.

## Current problems to fix

- The current bridge-lite bootstrap publicly serves `GET /admin`, which exposes the operator console shell to unauthenticated visitors.
- Exposure management is too port-list-first, which is wrong for users who think in terms of concrete VMs or servers.
- Sidecar state is too loose. It needs first-class fields, stronger policy enforcement, and auditability.
- The current design draft is too generic. It does not yet lock the bridge-lite-specific sidecar protocol boundary or the required audit surfaces.

## What this is not

- Not a trimmed copy of the old panel.
- Not a shared database with the legacy system.
- Not direct client-to-sidecar connectivity outside the bridge-lite control model.
- Not a public proxy product.
- Not a user-facing topology change away from the internal `client -> slave -> sidecar` rule set.

## Approaches considered

1. **Fork the old panel and patch features in place**
   - Fastest short-term path, but keeps legacy coupling and increases confusion around admin access, exposure flow, and sidecar identity.
2. **Build a separate bridge-lite control plane and bridge-lite sidecar family, reusing only low-level primitives where useful**
   - Best balance of isolation, security, and delivery risk. **Chosen.**
3. **Rewrite everything including every runtime primitive**
   - Cleanest in theory, but too much churn for the first bridge-lite release.

## High-level architecture

The approved design has three layers:

1. **Bridge-lite control plane**
   - Admin login and operator console
   - Client UI and API
   - WHMCS reconciliation
   - Exposure policy, target inventory, and sidecar inventory
   - Audit collection and reporting

2. **Client-facing bridge-lite runtime contract**
   - VM-first target selection
   - Exposure creation with default `TCP + UDP`
   - Managed-sidecar and own-sidecar onboarding
   - Per-target policy sync

3. **Bridge-lite sidecar agent**
   - Separate product identity from legacy `leikwan-bridge`
   - Registration against bridge-lite targets
   - Heartbeat plus public-IP reporting
   - Enforcement that only assigned ports can become services

## Core design rules

- Customer sign-in remains **WHMCS-only**.
- Admin sign-in is a separate local-only bootstrap path, with `/frankniubi` as the default configured path.
- Unauthenticated `GET /admin` must return **404**. It must not redirect to the bootstrap path.
- The bootstrap path serves the **login page only**, not the admin shell.
- Successful admin login creates a session that can access `/admin`.
- The first operator admin account is seeded locally and stored hashed only.
- The client UX is **VM/server first**. The user starts from a target machine, not a free-form port list.
- Each target VM is linked back to an entitlement source such as order or package.
- New exposures default to **TCP + UDP**.
- Sidecar services may use **only** ports from the assigned sidecar port list.
- Sidecar `auto` IP mode means the agent reports public IP periodically and the control plane updates the stored active IP on change.
- Audit data must include both usage history and ingress-IP history.
- The sidecar agent family for bridge-lite must have distinct naming, versioning, config, and update identity from legacy `leikwan-bridge`.

## Product model

### Runtime modes

Bridge-lite keeps two user-facing modes:

1. **Simple mode**
   - User runs the client on a machine they own.
   - The machine exposes public listener ports normally.
   - Speed bonding may be enabled here if the account/package allows it and credits are available.

2. **Self-owned mode**
   - User runs the bridge-lite-controlled runtime on a self-owned VM, usually with a stable IPv6 path.
   - This mode can use `fakesip` and `fakehttp` transport profiles when required.
   - In v1, this mode does not get the same bonding entitlement as Simple mode.

### Target-first model

The primary user object is a **target VM/server**. An exposure always belongs to a concrete target record.

Each target should track:

- `target_id`
- `account_id`
- `vm_label`
- `vm_kind` such as `managed_vm`, `user_vm`, or `user_sidecar_vm`
- `order_id`
- `package_id`
- `carrier_preference`
- `speed_cap_mbps`
- `default_protocols`
- `sidecar_mode`
- `status`

This keeps the UI VM-first while still allowing entitlement enforcement through orders and packages.

## Admin access model

### Bootstrap path

- `GET /frankniubi` or the configured bootstrap path renders the admin login page only.
- `POST /frankniubi/login` validates local operator credentials and creates the admin session.
- `GET /admin` renders the admin shell only when the request already has a valid admin session.
- `GET /admin` without a valid session returns `404`.
- The system must not reveal the bootstrap path through automatic redirects from `/admin`.

### Admin console areas

The operator console should have these main areas:

- Users
- Targets / VMs
- Sidecars
- Exposures
- Audit
- WHMCS Sync

This separates account management, machine inventory, live exposure state, and compliance reporting.

## Sidecar model

Each sidecar is a first-class record attached to a target or target family.

Required fields:

- `name`
- `internet_access_ip`
- `ip_mode` where `manual` means operator-controlled and `auto` means agent-reported
- `port_list`
- `carrier`
- `speed_cap`
- `last_reported_ip`
- `last_heartbeat_at`
- `agent_version`
- `status`

### Port list rule

Only ports in the sidecar `port_list` may be activated as services. Both the control plane and the sidecar agent must reject exposure activation outside this list.

### Carrier and speed cap

- Users can choose a carrier from the set allowed by their package or admin policy.
- The effective sidecar speed cap is visible in both client and admin surfaces.

## Bridge-lite sidecar agent

### Product identity

The sidecar agent must be a separate bridge-lite family, not a relabeled legacy agent.

It should have:

- separate binary or package name
- separate version string namespace
- separate config file identity
- separate registration endpoints
- separate update or install channel

Example family naming:

- `leikwan-bridge-lite-sidecar`
- `bridge-lite-sidecar-agent`

The exact package name can be chosen during implementation, but it must remain operationally distinct from legacy `leikwan-bridge`.

### Registration flow

The bridge-lite sidecar agent registers through a dedicated endpoint such as:

- `POST /api/sidecar/register`

Agent sends:

- install token
- `target_id`
- platform
- hostname or local node name
- agent version

Server returns:

- `sidecar_id`
- sidecar name
- allowed ports
- carrier
- speed cap
- heartbeat interval
- IP mode
- config revision

### Heartbeat and public-IP reporting

The agent should report on a roughly 60-second interval.

Heartbeat payload includes:

- `sidecar_id`
- agent version
- current detected public IP
- health state
- currently bound ports
- lightweight usage counters

Control-plane behavior:

- if `ip_mode = auto` and the reported public IP changed, update the active sidecar IP and record an audit event
- if the IP did not change, refresh `last_heartbeat_at` without spamming duplicate change records
- if `ip_mode = manual`, keep the configured active IP unchanged even if the agent reports something different, but the report may still be retained as telemetry or operator evidence

## Client experience

### VM-first home

The client home should center on **My Targets / VMs**.

Each target row or card shows:

- VM name
- linked order or package
- sidecar mode: managed or own
- current public IP or reported IP
- carrier
- speed cap
- exposure count
- health / last heartbeat

### Exposure creation flow

The approved exposure flow is:

1. Choose VM/server
2. Choose service target on that VM
3. Confirm protocols, defaulting to `TCP + UDP`
4. Choose path: managed sidecar or own sidecar
5. Review the assigned public service ports from the allowed port list
6. Create the exposure

The user should never start from a free-form port list page without first selecting the concrete target machine.

### Default protocols

For every new exposure:

- `tcp = true`
- `udp = true`

The user can narrow the protocol set later, but the creation default must be both enabled.

### User's Own Sidecar install gate

For each eligible VM, the UI must expose a real **Install Own Sidecar** path.

Eligibility requires:

- self-owned mode permitted by package or admin policy
- target VM marked eligible
- self-owned sidecars not disabled for the account

The install gate provides:

- platform choice such as Linux or Windows
- one-time or revocable install token
- install command or package download
- prefilled sidecar name
- expected port list
- expected carrier
- expected speed cap
- control-plane endpoint for registration

After installation:

1. agent boots with install token
2. agent registers against the intended `target_id`
3. target enters `pending` until first successful heartbeat
4. target moves to `active` after registration plus first valid report

### UX states

Each target should clearly surface:

- no sidecar installed
- pending first heartbeat
- active
- IP changed, awaiting reconciliation
- heartbeat stale
- disabled by admin
- package or order invalid

## Auditing and compliance model

### Audit data classes

Bridge-lite must keep three audit classes:

1. **Usage rollups**
   - per account, per target, and per exposure
   - bytes in
   - bytes out
   - connection count
   - last active time

2. **Ingress IP observations**
   - account
   - target
   - exposure
   - observed ingress IP
   - first seen
   - last seen
   - connection count

3. **Control-plane audit events**
   - admin login success and failure
   - sidecar registered
   - sidecar public IP changed
   - heartbeat stale or restored
   - port list changed
   - carrier changed
   - speed cap changed
   - exposure created or deleted
   - own-sidecar install token issued or revoked

### Audit views

Admin UI should include:

- **User audit summary**
  - usage totals
  - active targets
  - recent ingress IPs
  - recent config changes

- **Exposure audit detail**
  - target VM
  - sidecar mode
  - assigned ports
  - recent ingress IP timeline
  - recent traffic totals

- **Sidecar audit detail**
  - current public IP
  - last heartbeat
  - IP change history
  - assigned port list
  - version and health state

- **Compliance export**
  - CSV or JSON export by account and time range

### Audit APIs

Minimum admin endpoints:

- `GET /api/admin/audit/users`
- `GET /api/admin/audit/users/{id}`
- `GET /api/admin/audit/exposures/{id}`
- `GET /api/admin/audit/ingress`
- `GET /api/admin/audit/sidecars/{id}`
- `GET /api/admin/audit/export`

## WHMCS and entitlement model

- WHMCS remains the billing system of record.
- Orders and packages remain the entitlement source for validity, limits, and permissions.
- VM-first target selection does not remove entitlement checks. It only changes what the user sees first.
- Purchase and renewal events reconcile into the bridge-lite account state.
- Manual sync can recover local state when WHMCS comes back after an outage.
- Empty or partial sync responses must never wipe valid local entitlement state.

## Error handling and failure behavior

- If a sidecar heartbeat stops, the sidecar becomes stale and the target state reflects that.
- If a sidecar public IP changes in `auto` mode, record the change and update the active value.
- If a sidecar reports an IP change in `manual` mode, retain the report without replacing the configured value.
- If an exposure requests a port outside the assigned `port_list`, reject the request.
- If a target loses entitlement, disable new exposure changes and surface the invalid state to the user.
- If a node or carrier path becomes unhealthy, follow the existing bridge-lite path-selection logic without weakening auditability.
- If credits run out, any premium bonding behavior must stop before ordinary exposure is removed.

## Testing strategy

The design is not complete unless implementation can prove these behaviors:

- `/admin` returns `404` without a valid admin session
- bootstrap path renders login only
- successful admin login reaches `/admin`
- target selection is VM-first for exposure creation
- new exposures default to `tcp=true` and `udp=true`
- own-sidecar install token binds to exactly the intended target
- sidecar heartbeat updates `last_heartbeat_at`
- sidecar public-IP change creates audit history
- unchanged public-IP report does not create duplicate change spam
- exposure activation rejects ports outside the assigned `port_list`
- audit APIs return per-user usage plus ingress-IP records

## Success criteria

This bridge-lite redesign is successful only when:

- unauthenticated visitors cannot use `/admin` and receive `404`
- admin login exists only through the configured bootstrap path
- targets are modeled as first-class VMs or servers
- exposure creation is VM-first
- default exposure protocol is `TCP + UDP`
- sidecars carry the required fields: name, IP/manual-or-auto, port list, carrier, speed cap
- only assigned sidecar ports can become services
- bridge-lite sidecar agent is distinct from legacy `leikwan-bridge`
- the control plane records per-user usage and ingress-IP evidence
- own-sidecar installation is a real guided flow, not a hidden configuration detail
