# WHMCS-style storefront and JP-only aggregation design

## Goal

Improve the tenant package purchase experience so `/tenant/plans` follows the compact visual structure of the referenced WHMCS store while preserving the existing LeiKwan panel shell and purchase behavior. Separately, change live routing policy so multi-path aggregation is available only for directions backed by the current JP Sidecar.

## Scope

### Storefront

- Keep the current tenant header, navigation, authentication, CSRF handling, and purchase endpoint.
- Replace only the `/tenant/plans` content layout.
- Keep current services below the storefront with the existing service actions.
- Do not change pricing calculations, purchase persistence, billing, or database schema.

### Live aggregation policy

- Preserve the required `client -> slave -> sidecar` topology.
- Preserve slave MPTCP and bond ingress ports within `7000-9000`.
- Allow aggregation only for a direction whose resolved runtime points to the verified JP Sidecar.
- Convert every non-JP direction to its supported non-aggregate or single-path behavior.
- Do not identify JP solely from a display name. Verify the Sidecar record, address, direction mapping, published combination snapshot, and rendered slave runtime before changing policy.

## Storefront design

### Page structure

The existing panel shell remains unchanged. The plans page content is organized as:

1. A restrained page heading and short commercial summary.
2. A two-column store workspace with a compact category rail on the left and products on the right.
3. A two-column product grid on desktop and a single product column on mobile.
4. The existing current-services table below the store workspace.

The category rail uses the WHMCS reference as the visual model: bordered rows, a clear active row, compact typography, and minimal decoration. It must not reproduce the reference site's global navigation.

### Categories

Categories are derived at render time from current purchasable plans. The stable categories are:

- All Packages
- JP Aggregate, shown only when at least one purchasable plan is mapped to a verified JP aggregate offering
- Single Route, for non-aggregate offerings

If the current plan data does not expose a reliable aggregate/direction attribute, the page must render only All Packages rather than infer commercial behavior from arbitrary plan names. No schema field is added as part of this change.

Category filtering is presentation-only. It must not change plan eligibility or the server-side authorization performed during purchase.

### Product cards

Each card uses a compact WHMCS-style header and a two-part body:

- Left: plan name and escaped, line-separated plan features.
- Right: monthly price, billing period, included traffic, bandwidth, client/profile limits, traffic reset price, and the primary order action.

The order action is green and visually dominant. Sold-out semantics are not introduced because the local plan model has no inventory contract. Existing purchasable-plan filtering remains authoritative.

Clicking Order Now opens the existing inline form within the selected card. The form keeps the existing service-name and bandwidth inputs, CSRF token, submit endpoint, failure redirect, and cancel behavior. Expanding a card must not resize unrelated cards unpredictably or obscure later content.

### Responsive behavior

- Desktop: fixed-width category rail and two equal product columns.
- Tablet: narrower category rail with product cards allowed to collapse to one column when needed.
- Mobile: category rail becomes a compact horizontal or select-style filter above a single product column.
- Price and action remain visible without horizontal scrolling.
- The current-services table keeps its existing scroll wrapper.

### Styling boundary

New selectors are storefront-specific and live in the existing panel stylesheet. Generic `.card`, `.grid`, `.button`, and shell styles are not globally redefined. Inline styles currently embedded in `TenantController::plansPage()` are moved into scoped classes where they are part of the changed storefront.

## Live aggregation design

### Discovery

Use the credentials and environment data in `leikwan-v3.txt`, `cantons.txt`, and `shanghais.txt` locally without echoing sensitive values. Access the panel through WSL. Access a Sidecar only through a slave hop.

Before mutation, capture:

- current panel commit and migration state;
- relevant Sidecar, slave, direction, forward-combination, and service-plan records;
- the published runtime snapshots for affected combinations;
- rendered configuration and active services on representative slaves;
- current JP Sidecar reachability through the slave path.

### Policy mutation

Apply the narrowest supported control-plane change that produces this invariant:

```text
verified JP Sidecar direction -> aggregation allowed
all other directions          -> aggregation disabled / single path
```

Prefer existing admin/API or persisted forward-combination controls over manual edits to rendered files. If a non-JP combination has no valid single-path representation, disable its aggregate offering rather than publish a broken runtime.

Republish only affected combinations and services. Do not change unrelated routing priorities, ports, credentials, targets, or topology.

### Runtime verification

For JP, prove both that aggregation is configured and that multiple intended legs are active or eligible in the rendered runtime. For each non-JP direction, prove that no aggregate runtime or multi-leg bond configuration remains published or active.

Control-plane success and path quality are reported separately. A correctly applied JP-only policy is not described as healthy unless its active path is also usable.

## Deployment and rollback

### Local storefront deployment

Implementation starts from the current `master` branch in an isolated worktree. Before production sync:

- commit the reviewed source changes;
- run focused PHP and storefront regression tests;
- render desktop and mobile views through the local application;
- inspect screenshots for overflow, overlap, and broken inline purchase states.

Production sync uses a committed archive overlay. Back up the current deployed files and database before upload. Run the migration runner even though no new migration is expected, and prove zero pending migrations afterward. Verify tracked-file equality, PHP syntax, origin HTTP, and public HTTP through the requested local proxy.

### Aggregation rollback

Before changing live policy, export the affected database rows or API payloads and retain the prior rendered snapshots. Rollback restores only those records, republishes the affected combinations, and verifies the prior runtime state on slaves.

## Error handling

- If reference-page assets fail, retain the captured structural evidence and do not import external CSS or JavaScript.
- If a plan cannot be categorized reliably, show it under All Packages.
- If local authentication fixtures cannot render the purchase page, use a local database snapshot or narrowly scoped fixture rather than weakening authentication.
- If live records conflict with source assumptions, follow current runtime and persisted state.
- If JP identity or single-path fallback cannot be proven, stop before mutation and report the exact missing link.
- If deployment verification fails, restore the file backup; restore the database only if it was changed.

## Tests and acceptance criteria

### Storefront

- Existing purchase endpoint and CSRF flow remain unchanged.
- Purchasable plans render once each and unavailable plans remain absent.
- Feature text remains escaped and line-separated.
- All Packages always works; JP Aggregate and Single Route appear only when supported by reliable data.
- Inline order expansion preserves selected plan ID and form values.
- Empty-store and current-services states remain readable.
- Desktop and mobile screenshots match the selected WHMCS-faithful direction without overflow or overlap.
- Existing tenant storefront tests and PHP syntax checks pass.

### Aggregation

- The verified JP direction publishes an aggregate runtime through the expected slave-to-Sidecar path.
- No non-JP direction publishes or runs multi-path aggregation.
- All affected ingress ports remain within `7000-9000`.
- Representative JP and non-JP rendered configurations match the intended policy after republish.
- Active service status and path usability are recorded independently.
- Rollback inputs are complete and replayable.

## Non-goals

- Replacing the panel shell with WHMCS navigation.
- Copying WHMCS assets, templates, or JavaScript.
- Adding stock counters or inventory management.
- Changing billing calculations or purchase authorization.
- Adding a new plan-category schema.
- Adding new non-JP Sidecars.
- Allowing direct client-to-Sidecar connectivity.
