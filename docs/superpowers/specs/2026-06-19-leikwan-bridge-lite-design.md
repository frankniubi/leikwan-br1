# LeiKwan Bridge Lite Design

## Goal

Build a brand-new, separate `leikwan-bridge-lite` web app with its own database for user, package, node, credit, and WHMCS sync management. The product should expose only two user-facing runtime modes: **Simple mode** and **Self-owned mode**. External users connect to the customer's own exposed ports on the customer's machine or VM; only the customer client's connection to LeiKwan is authenticated.

## What this is not

- Not a trimmed copy of the old panel.
- Not a public proxy product.
- Not direct client-to-sidecar connectivity.
- Not a shared database with the legacy system.
- Not a user-facing `client -> slave -> sidecar` topology.

## Approaches considered

1. **Fork the current panel and prune features**
   - Fastest start, but keeps legacy coupling and confusing topology.
2. **New bridge-lite app with a new DB, reusing existing runtime primitives where useful**
   - Best balance of isolation and delivery risk. **Chosen.**
3. **Full rewrite of control plane and client runtime**
   - Cleanest on paper, but too much churn for the first version.

## Product shape

The new app has three layers:

1. **Control plane web app**
   - Admin and client UI
   - API for client enrollment and config sync
   - WHMCS adapter
   - Credits and package accounting
   - Node inventory and priority management

2. **Client gateway runtime**
   - Installed on the user's Windows, Linux, or Android device
   - Also runs on a self-owned VM in Self-owned mode
   - Exposes the user's chosen ports on that machine/VM
   - Uses one authenticated LeiKwan session to fetch policy and carry control/data-plane metadata

3. **LeiKwan-managed relay/network side**
   - Internal only
   - Carries customer traffic and control state
   - Remains hidden from end users
   - Still obeys the existing slave-hop rule internally

## Core design rules

- Only the client-to-LeiKwan path is authenticated.
- External users connect normally to the customer's own exposed ports.
- The product should not look like a public proxy service.
- The port allocator should use as few ports as possible.
- Freed ports should be reused before new ports are opened.
- BGP bonding is premium and credit-based.
- Speed bonding is only available in Simple mode and only when the account has credits to spend; Self-owned mode has no bonding in v1.
- WHMCS remains the billing/validity source of truth.
- Node selection should follow admin-set priority and speed caps.
- DDNS should keep LeiKwan-managed addresses stable when the underlying IP changes.

## User-facing modes

### 1) Simple mode

Simple mode is the default customer-facing mode for a machine the user already owns.

- The client app runs on the user's computer or server.
- The app exposes the ports the user chooses.
- Those exposed ports are reachable by normal external traffic.
- The app keeps one authenticated control session to LeiKwan.
- The control session is used to sync routes, node choice, speed policy, and credits.
- The control session can carry many exposure records and route definitions, but it must not change how external users connect to the customer's own ports.
- Port use should be compact: one session may manage many local exposures, but the runtime should still open the minimum practical public ports.

### 2) Self-owned mode

Self-owned mode is for a user-controlled VM, typically with a stable IPv6 path.

- The same client runtime is installed on the user-owned VM.
- The user can use their own IPv6 for transport.
- It is still controlled through the LeiKwan app and account.
- This mode is better for stable high-volume workloads.
- It must support camouflage transport profiles such as `fakesip` and `fakehttp` when needed.

## Admin capabilities

### Users

Admin can manage:

- name
- password
- bound WHMCS email
- credits
- packages
- valid period

### Packages

Admin can add, edit, and delete packages.

A package should capture at least:

- display name
- WHMCS product mapping
- validity period
- included credits
- base speed limit
- allowed node class
- allowed mode(s)
- bonding entitlement
- maximum active exposures

### Nodes

Admin can insert and manage:

- **BGP intra nodes**
- **single-line intra nodes**

Node records should include:

- node class
- public host / DDNS label
- region
- current status
- speed limit
- priority
- protocol support flags

Admin can also delete nodes and change their priority per user or package tier.

### WHMCS sync

Admin can force a sync so each user's validity matches the WHMCS products bound to that user.

## Client capabilities

Client can:

- buy packages
- renew packages
- see package details
- select local ports to expose
- install on Windows, Linux, and Android
- pay to use BGP nodes
- view remaining credits and speed state

## Port model

The port model should stay compact and predictable.

- Each exposure gets a managed listener assignment on the customer side.
- The allocator should pack listeners into the smallest practical range.
- Reuse lower free ports first.
- If multiple logical routes can safely share a session or compact range, do that.
- Do not spread listeners across wide, proxy-looking port blocks unless there is no alternative.

This keeps the product from resembling a public proxy service while still allowing multiple TCP/UDP destinations.

## Transport model

### Simple mode transport

- The client app opens the public listener(s) on the user's machine/VM.
- External users connect to those ports normally.
- The client app uses an authenticated LeiKwan session to obtain config and policy.
- The session can carry multiple route definitions and telemetry streams.
- Speed bonding is only available here, and only when the account has paid credits.

### Self-owned transport

- Same runtime contract as Simple mode.
- Better suited to stable IPv6 or LeiKwan-hosted VM placement.
- Supports `fakesip` / `fakehttp` profiles for harder network environments.
- No bonding entitlement in v1.

## WHMCS integration model

WHMCS should remain the billing system of record.

- Purchase/renew events from WHMCS update the bridge-lite account state.
- Validity should be mirrored into the local database.
- If WHMCS is temporarily unavailable, the last known valid state should remain visible but marked stale.
- Manual admin sync should reconcile the local state back to WHMCS.

## Data model boundaries

The new database should keep these concepts separate:

- **Account**: login, password, WHMCS email binding, credits, validity.
- **Package**: quota, speed, mode, node class, bonding rights.
- **Node**: address, DDNS label, class, priority, speed cap, status.
- **Exposure**: local port, protocol, destination, mode, allocator result.
- **Credit ledger**: purchases, deductions, bonding charges, admin adjustments.
- **Sync job**: WHMCS reconciliation history.
- **Device enrollment**: per-client runtime identity and auth material.

## Error handling

- If a node goes unhealthy, the next priority node should be selected.
- If a client disconnects, the public exposure should fail closed rather than silently drifting.
- If credits run out, bonding must stop before ordinary exposure does.
- If DDNS updates lag, the node should be marked degraded until the name resolves correctly again.
- If WHMCS sync fails, local billing state should not be overwritten by empty or partial data.

## Success criteria

- Admin can manage users, packages, nodes, node priority, and speed limits.
- WHMCS validity sync matches product state.
- Clients can buy/renew and see package details.
- Customer-installed runtime exposes normal ports on the customer's own machine or VM.
- Only the client-to-LeiKwan path is authenticated.
- Port allocation stays compact and does not look like a public proxy deployment.
- Simple mode bonding works only with paid credits.
- Self-owned mode supports `fakesip` and `fakehttp` transport profiles.
- Windows, Linux, and Android clients can all speak the same control-plane contract.

## Session contract

The exact wire protocol for the authenticated client session is implementation-defined in v1, but the contract is fixed:

- authenticate the client
- fetch policy and node selection
- register and update exposure records
- report health and usage
- keep listener allocation compact and reusable
