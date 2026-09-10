# Tenant Forward Combination Cleanup + CF Best IP Test Plan

## Goal

Prove two things before claiming this work is done:

1. tenant forward creation is combination-only everywhere
2. Cloudflare `cf_mode` selection is either honestly narrowed or actually upgraded beyond the current RTT-only cache

## Tenant UI Coverage

### Service detail page

- Assert the create surface contains `Forward Combination`
- Assert it does **not** contain:
  - `Line Client Groups`
  - `Route Mode`
  - `Forward Chain`
  - `Connection Mode`
  - `Sidecar Point`
  - `Slave Group`
- Assert copy uses "route package" / "forward combination" language

### Quick create page

- Assert the same field contract as the service detail page
- Assert the empty-state message for missing combinations still renders

### Legacy route

- Assert `/tenant/services/{id}/forward-wizard` redirects to `/tenant/services/{id}/create-forward`

### Tenant POST guard

- Assert tenant create handling rejects missing `forward_combination_id`
- Assert the rejection message is combination-specific, not generic

## Provisioning Coverage

- Assert `ProvisioningService` still resolves a visible combination into:
  - `backend_mode`
  - `node_group_key`
  - `sidecar_point_id`
  - optional `cf_location_group_id`
- Assert `buildClientConfig()` / service summary still expose `available_forward_combinations`

## Cloudflare Selector Coverage

### Current required unit coverage

- TTL reuse keeps the cached winner
- repeated probes compute non-placeholder jitter
- `switch_improvement_ms` blocks small noisy flips
- repeated failures can move selection to a backup IP
- unsupported probe modes are rejected or normalized honestly

### Verdict rule

- If the selector still uses one TCP connect sample with `jitter_ms = 0.0`, verdict is **not fully functional**
- If `switch_improvement_ms`, cooldown, and backup behavior still do not affect selection, verdict is **not fully functional**

## Verification Commands

```bash
php master/tests/provisioning_forward_combination_regression_test.php
php master/tests/admin_forward_combination_page_test.php
php master/tests/tenant_forward_combination_regression_test.php
php master/tests/tenant_controller_regressions_test.php
php master/tests/admin_cf_location_observability_test.php
python -m unittest slave-agent.tests.test_slave_cf_location_group_selection
php -l master/src/Controllers/TenantController.php
php -l master/src/Support/ProvisioningService.php
python -m py_compile slave-agent/slave_agent.py
```

## Live Smoke Matrix

Run only if the user wants a deploy-level answer:

- one `current_mode` combination
- one `cf_mode + single + tcp`
- one `cf_mode + bond + tcp+udp`

Pass only if:

- tenant can create the forward through the combination-only UI
- slave reports CF selection stats
- sidecar `cf_runtime` comes up
- real traffic reaches the backend through the selected CF IP

