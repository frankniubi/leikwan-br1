# LeiGod-Only Control Plane Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every ordinary control-plane path accept, select, publish, and display only the locked LeiGod standalone-bond contract while preserving legacy persisted values for later retirement and audit.

**Architecture:** Add one dependency-free `ForwardModePolicy` that separately normalizes new/edit requests and validates raw persisted Forward/combination pairs. `ProvisioningService` keeps its existing two-argument construction contract through an optional policy argument, applies query-level filtering for ordinary lists, and revalidates at every mutation, reconciliation, publication, and desired-state boundary. Database defaults change only for future rows; no migration rewrites historical modes, and the retirement ledger, runtime cleanup, bond scheduler, supervisor, telemetry, normalization command, and rollout remain in their dedicated plans.

**Tech Stack:** PHP 8 strict types, existing `Config`/`Database`/`ApiException` support classes, MySQL SQL migrations with explicit SQLite normalization, standalone PHP regression scripts.

---

## Scope and locked contracts

This plan establishes the policy and active-path boundary used by all later plans. It does **not** create retirement tombstones, normalize existing LeiGod rows, change agent artifacts, implement Rust scheduling, allocate local ports, ingest telemetry, activate cleanup, or deploy anything. It removes the repository's obsolete donation-worker script and systemd source definitions only after every ordinary donation activation path is unreachable; it does not stop, disable, remove, or otherwise touch an already installed production unit.

The only active contract is:

```text
backend_mode = leigod_mode
client_mode = bond
runtime_preference = single
bond_ingress_mode = plain_tcp_mux
```

The stable request and persisted-row errors are:

```text
current_mode or cf_mode anywhere authoritative
  -> deprecated_forward_combination_backend_mode (HTTP 422)

unknown backend value
  -> invalid_forward_combination_backend_mode (HTTP 422)

missing combination, Forward/combination mismatch, or any conflicting
client/runtime/ingress/CF/self-leg contract
  -> unsupported_leigod_runtime_contract (HTTP 422)
```

Ordinary list methods omit ineligible rows rather than returning them with disabled controls. Explicit operations on an ineligible ID fail with the errors above. Bulk reconciliation methods continue processing independent eligible Forwards but report each rejected Forward in their existing `errors` result; scoped single-Forward operations throw before any write.

The authoritative persisted predicate always reads both raw values. It never substitutes `current_mode` for a missing value and never treats an omitted edit field as permission to convert a historical row.

---

### Task 1: Introduce the central Forward mode policy

**Files:**
- Create: `master/src/Support/ForwardModePolicy.php`
- Create: `master/tests/forward_mode_policy_test.php`
- Modify: `master/src/Support/ProvisioningService.php:12-51`

- [ ] **Step 1: Write the failing table-driven policy test**

Create `master/tests/forward_mode_policy_test.php`. Load the normal autoloader pattern used by the standalone tests, add helpers that assert an `ApiException` message/status/details, and cover these exact cases:

```php
$lockedCombination = [
    'id' => 7,
    'is_active' => 1,
    'backend_mode' => 'leigod_mode',
    'client_mode' => 'bond',
    'runtime_preference' => 'single',
    'bond_ingress_mode' => 'plain_tcp_mux',
    'helper_group_cap' => 8,
    'sidecar_point_id' => 4,
    'leigod_line_id' => 453,
    'cf_location_group_id' => null,
    'allow_private_self_leg' => 0,
    'allow_public_self_leg_donation' => 0,
    'self_leg_scope' => 'none',
    'self_leg_role' => 'none',
    'self_leg_cf_transport' => 'cf_direct',
    'self_leg_outer_transport' => 'leigod',
];

$lockedForward = [
    'id' => 91,
    'forward_combination_id' => 7,
    'active_combination_id' => 7,
    'backend_mode' => 'leigod_mode',
    'client_mode' => 'bond',
    'bond_ingress_mode' => 'plain_tcp_mux',
    'bond_sidecar_point_id' => 4,
    'cf_location_group_id' => null,
];
```

The table must assert:

1. A new request omitting all four mode fields returns all four locked values.
2. Explicit locked values pass unchanged.
3. Explicit `current_mode` and `cf_mode` each throw `deprecated_forward_combination_backend_mode`, status 422.
4. Explicit `bogus_mode` and an explicit empty backend each throw `invalid_forward_combination_backend_mode`, status 422.
5. Explicit `client_mode=single|mptcp`, `runtime_preference=mptcp_preferred_bond_fallback`, or `bond_ingress_mode=wss_path_mux` each throws `unsupported_leigod_runtime_contract`, status 422.
6. Editing a persisted `current_mode` or `cf_mode` combination throws the deprecation error even when the request omits `backend_mode`.
7. Editing a persisted LeiGod/MPTCP or LeiGod/WSS combination throws `unsupported_leigod_runtime_contract` rather than silently upgrading it.
8. A fully locked persisted combination is selectable; an inactive, legacy, unknown, or runtime-conflicting combination is not selectable.
9. Explicit `cf_location_group_id>0`, `allow_private_self_leg=1`, `allow_public_self_leg_donation=1`, non-`none` self-leg scope/role, `self_leg_cf_transport=cf_wg`, or non-`leigod` outer transport throws `unsupported_leigod_runtime_contract` with `reason=request_contract_conflict`; none is silently cleared.
10. A persisted combination with `allow_public_self_leg_donation=1` is non-selectable and `assertCombinationContract()` rejects it with `unsupported_leigod_runtime_contract`; a zero value remains inert and historical donation timing/measurement columns are not erased.
11. A new request omitting `helper_group_cap` receives eight; explicit values 1, 4, and 8 pass; explicit 0 or 9 throws `unsupported_leigod_runtime_contract` with `reason=helper_group_cap_out_of_range`; an edit omitting the field preserves the existing legal value.
12. A locked Forward plus locked combination passes persisted validation.
13. A legacy value on either side takes deprecation precedence and includes both raw values in details.
14. A missing combination, inactive combination, different combination ID, missing or different `active_combination_id`, sidecar-point mismatch, or active-mode runtime disagreement throws `unsupported_leigod_runtime_contract` with deterministic `combination_missing`, `combination_inactive`, `combination_id_mismatch`, `active_combination_id_mismatch`, `sidecar_point_mismatch`, or contract-conflict `reason` detail. Test both `active_combination_id=null` and a positive ID different from `forward_combination_id`; ordinary active publication accepts neither.
15. An unknown persisted backend on either side throws `invalid_forward_combination_backend_mode`.

Use the exact public interface defined in Step 3:

```php
$policy = new ForwardModePolicy();
$normalized = $policy->normalizeCombinationInput($payload, $existingOrNull);
$policy->assertCombinationContract($combination);
$selectable = $policy->isSelectableCombination($combination);
$policy->assertPersistedForwardContract($forward, $combinationOrNull);
```

End with `echo "ok\n";`.

- [ ] **Step 2: Run the policy test and verify the missing-class failure**

Run:

```bash
php master/tests/forward_mode_policy_test.php
```

Expected: FAIL because `Leikwan\Master\Support\ForwardModePolicy` does not exist.

- [ ] **Step 3: Implement `ForwardModePolicy` with no database dependency**

Create `master/src/Support/ForwardModePolicy.php` with this complete public contract and private validation flow:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class ForwardModePolicy
{
    public const BACKEND_MODE = 'leigod_mode';
    public const CLIENT_MODE = 'bond';
    public const RUNTIME_PREFERENCE = 'single';
    public const BOND_INGRESS_MODE = 'plain_tcp_mux';
    public const MAX_SELECTED_SLAVES = 8;

    /** @var array<int, string> */
    private const DEPRECATED_BACKEND_MODES = ['current_mode', 'cf_mode'];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    public function normalizeCombinationInput(array $payload, ?array $existing): array
    {
        if ($existing !== null) {
            $this->assertCombinationContract($existing);
        }

        $backendMode = array_key_exists('backend_mode', $payload)
            ? trim((string) $payload['backend_mode'])
            : self::BACKEND_MODE;
        $this->assertKnownActiveBackend($backendMode, 'request');

        $requestedContract = [
            'client_mode' => self::CLIENT_MODE,
            'runtime_preference' => self::RUNTIME_PREFERENCE,
            'bond_ingress_mode' => self::BOND_INGRESS_MODE,
        ];
        foreach ($requestedContract as $field => $expected) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $actual = strtolower(trim((string) $payload[$field]));
            if ($actual !== $expected) {
                throw new ApiException('unsupported_leigod_runtime_contract', 422, [
                    'reason' => 'request_contract_conflict',
                    'field' => $field,
                    'actual' => $actual,
                    'expected' => $expected,
                ]);
            }
        }

        $structuralInput = [
            'cf_location_group_id' => null,
            'allow_private_self_leg' => 0,
            'allow_public_self_leg_donation' => 0,
            'self_leg_scope' => 'none',
            'self_leg_role' => 'none',
            'self_leg_cf_transport' => 'cf_direct',
            'self_leg_outer_transport' => 'leigod',
        ];
        foreach ($structuralInput as $field => $expected) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $actual = $field === 'cf_location_group_id'
                ? ((int) ($payload[$field] ?? 0) === 0 ? null : (int) $payload[$field])
                : $payload[$field];
            $valid = match ($field) {
                'cf_location_group_id' => $actual === null,
                'allow_private_self_leg',
                'allow_public_self_leg_donation' => (int) $actual === 0,
                default => strtolower(trim((string) $actual)) === (string) $expected,
            };
            if (!$valid) {
                throw new ApiException('unsupported_leigod_runtime_contract', 422, [
                    'reason' => 'request_contract_conflict',
                    'field' => $field,
                    'actual' => $actual,
                    'expected' => $expected,
                ]);
            }
        }

        $existingCap = $existing === null
            ? self::MAX_SELECTED_SLAVES
            : (int) ($existing['helper_group_cap'] ?? 0);
        $helperGroupCap = array_key_exists('helper_group_cap', $payload)
            ? (int) $payload['helper_group_cap']
            : $existingCap;
        if ($helperGroupCap < 1 || $helperGroupCap > self::MAX_SELECTED_SLAVES) {
            throw new ApiException('unsupported_leigod_runtime_contract', 422, [
                'reason' => 'helper_group_cap_out_of_range',
                'field' => 'helper_group_cap',
                'actual' => $helperGroupCap,
                'minimum' => 1,
                'maximum' => self::MAX_SELECTED_SLAVES,
            ]);
        }

        return array_replace($payload, [
            'backend_mode' => self::BACKEND_MODE,
            'client_mode' => self::CLIENT_MODE,
            'runtime_preference' => self::RUNTIME_PREFERENCE,
            'bond_ingress_mode' => self::BOND_INGRESS_MODE,
            'cf_location_group_id' => null,
            'allow_private_self_leg' => 0,
            'allow_public_self_leg_donation' => 0,
            'self_leg_scope' => 'none',
            'self_leg_role' => 'none',
            'self_leg_cf_transport' => 'cf_direct',
            'self_leg_outer_transport' => 'leigod',
            'helper_group_cap' => $helperGroupCap,
        ]);
    }

    /** @param array<string, mixed> $combination */
    public function isSelectableCombination(array $combination): bool
    {
        if ((int) ($combination['is_active'] ?? 0) !== 1) {
            return false;
        }
        try {
            $this->assertCombinationContract($combination);
            return true;
        } catch (ApiException) {
            return false;
        }
    }

    /** @param array<string, mixed> $combination */
    public function assertCombinationContract(array $combination): void
    {
        $backendMode = trim((string) ($combination['backend_mode'] ?? ''));
        $this->assertKnownActiveBackend($backendMode, 'combination');

        $this->assertRuntimeFields([
            'client_mode' => $combination['client_mode'] ?? null,
            'runtime_preference' => $combination['runtime_preference'] ?? null,
            'bond_ingress_mode' => $combination['bond_ingress_mode'] ?? null,
        ], 'combination_contract_conflict');

        $structuralChecks = [
            'sidecar_point_id' => (int) ($combination['sidecar_point_id'] ?? 0) > 0,
            'leigod_line_id' => (int) ($combination['leigod_line_id'] ?? 0) > 0,
            'cf_location_group_id' => (int) ($combination['cf_location_group_id'] ?? 0) === 0,
            'allow_private_self_leg' => (int) ($combination['allow_private_self_leg'] ?? 0) === 0,
            'allow_public_self_leg_donation' => (int) ($combination['allow_public_self_leg_donation'] ?? 0) === 0,
            'self_leg_scope' => (string) ($combination['self_leg_scope'] ?? '') === 'none',
            'self_leg_role' => (string) ($combination['self_leg_role'] ?? '') === 'none',
            'self_leg_cf_transport' => (string) ($combination['self_leg_cf_transport'] ?? '') === 'cf_direct',
            'self_leg_outer_transport' => (string) ($combination['self_leg_outer_transport'] ?? '') === 'leigod',
            'helper_group_cap' => (int) ($combination['helper_group_cap'] ?? 0) >= 1
                && (int) ($combination['helper_group_cap'] ?? 0) <= self::MAX_SELECTED_SLAVES,
        ];
        foreach ($structuralChecks as $field => $valid) {
            if (!$valid) {
                throw new ApiException('unsupported_leigod_runtime_contract', 422, [
                    'reason' => 'combination_contract_conflict',
                    'field' => $field,
                    'actual' => $combination[$field] ?? null,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $forward
     * @param array<string, mixed>|null $combination
     */
    public function assertPersistedForwardContract(array $forward, ?array $combination): void
    {
        $forwardMode = trim((string) ($forward['backend_mode'] ?? ''));
        $combinationMode = $combination === null
            ? null
            : trim((string) ($combination['backend_mode'] ?? ''));

        // Deprecation wins across the persisted pair. Scan both raw values before
        // reporting an unknown value, a missing combination, or any mismatch.
        foreach (['forward' => $forwardMode, 'combination' => $combinationMode] as $source => $mode) {
            if ($mode !== null && in_array($mode, self::DEPRECATED_BACKEND_MODES, true)) {
                throw new ApiException('deprecated_forward_combination_backend_mode', 422, [
                    'source' => $source,
                    'backend_mode' => $mode,
                    'forward_backend_mode' => $forwardMode,
                    'combination_backend_mode' => $combinationMode,
                ]);
            }
        }

        $this->assertKnownActiveBackend($forwardMode, 'forward', $forwardMode, $combinationMode);
        if ($combination !== null) {
            $this->assertKnownActiveBackend((string) $combinationMode, 'combination', $forwardMode, $combinationMode);
        }
        if ($combination === null) {
            $this->unsupportedPersisted('combination_missing', $forward, null);
        }
        $forwardCombinationId = (int) ($forward['forward_combination_id'] ?? 0);
        if ($forwardCombinationId <= 0
            || $forwardCombinationId !== (int) ($combination['id'] ?? 0)) {
            $this->unsupportedPersisted('combination_id_mismatch', $forward, $combination);
        }
        if ((int) ($combination['is_active'] ?? 0) !== 1) {
            $this->unsupportedPersisted('combination_inactive', $forward, $combination);
        }
        if ((int) ($forward['active_combination_id'] ?? 0) !== $forwardCombinationId) {
            $this->unsupportedPersisted('active_combination_id_mismatch', $forward, $combination);
        }
        if ($forwardMode !== $combinationMode) {
            $this->unsupportedPersisted('backend_mode_mismatch', $forward, $combination);
        }

        $this->assertCombinationContract($combination);
        $this->assertRuntimeFields([
            'client_mode' => $forward['client_mode'] ?? null,
            'runtime_preference' => $combination['runtime_preference'] ?? null,
            'bond_ingress_mode' => $forward['bond_ingress_mode'] ?? null,
        ], 'forward_contract_conflict', $forward, $combination);

        if ((string) ($forward['client_mode'] ?? '') !== (string) ($combination['client_mode'] ?? '')
            || (string) ($forward['bond_ingress_mode'] ?? '') !== (string) ($combination['bond_ingress_mode'] ?? '')
            || (int) ($forward['cf_location_group_id'] ?? 0) !== 0) {
            $this->unsupportedPersisted('forward_combination_contract_mismatch', $forward, $combination);
        }
        if ((int) ($forward['bond_sidecar_point_id'] ?? 0)
            !== (int) ($combination['sidecar_point_id'] ?? 0)) {
            $this->unsupportedPersisted('sidecar_point_mismatch', $forward, $combination);
        }
    }

    private function assertKnownActiveBackend(
        string $mode,
        string $source,
        ?string $forwardMode = null,
        ?string $combinationMode = null,
    ): void {
        if (in_array($mode, self::DEPRECATED_BACKEND_MODES, true)) {
            throw new ApiException('deprecated_forward_combination_backend_mode', 422, [
                'source' => $source,
                'backend_mode' => $mode,
                'forward_backend_mode' => $forwardMode,
                'combination_backend_mode' => $combinationMode,
            ]);
        }
        if ($mode !== self::BACKEND_MODE) {
            throw new ApiException('invalid_forward_combination_backend_mode', 422, [
                'source' => $source,
                'backend_mode' => $mode,
                'forward_backend_mode' => $forwardMode,
                'combination_backend_mode' => $combinationMode,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $forward
     * @param array<string, mixed>|null $combination
     */
    private function assertRuntimeFields(
        array $values,
        string $reason,
        ?array $forward = null,
        ?array $combination = null,
    ): void {
        $expected = [
            'client_mode' => self::CLIENT_MODE,
            'runtime_preference' => self::RUNTIME_PREFERENCE,
            'bond_ingress_mode' => self::BOND_INGRESS_MODE,
        ];
        foreach ($expected as $field => $expectedValue) {
            $actual = strtolower(trim((string) ($values[$field] ?? '')));
            if ($actual !== $expectedValue) {
                throw new ApiException('unsupported_leigod_runtime_contract', 422, [
                    'reason' => $reason,
                    'field' => $field,
                    'actual' => $actual,
                    'expected' => $expectedValue,
                    'forward_id' => (int) ($forward['id'] ?? 0),
                    'forward_backend_mode' => $forward['backend_mode'] ?? null,
                    'combination_backend_mode' => $combination['backend_mode'] ?? null,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $forward
     * @param array<string, mixed>|null $combination
     */
    private function unsupportedPersisted(string $reason, array $forward, ?array $combination): never
    {
        throw new ApiException('unsupported_leigod_runtime_contract', 422, [
            'reason' => $reason,
            'forward_id' => (int) ($forward['id'] ?? 0),
            'forward_combination_id' => (int) ($forward['forward_combination_id'] ?? 0),
            'active_combination_id' => (int) ($forward['active_combination_id'] ?? 0),
            'combination_id' => (int) ($combination['id'] ?? 0),
            'forward_backend_mode' => $forward['backend_mode'] ?? null,
            'combination_backend_mode' => $combination['backend_mode'] ?? null,
            'forward_sidecar_point_id' => (int) (
                $forward['bond_sidecar_point_id'] ?? 0
            ),
            'combination_sidecar_point_id' => (int) (
                $combination['sidecar_point_id'] ?? 0
            ),
        ]);
    }
}
```

Do not add database lookups, mutable state, environment flags, or fallback aliases to this class. `leigod_uot_impl` is deliberately absent from this policy: it remains the independent LeiGod carrier choice (`gost|lguot`) and is not one of the four locked product/runtime mode fields. `helper_group_cap` is not a fifth mode field, but it is part of active eligibility because it is the actual selection cap: locked combinations must persist an integer from one through `MAX_SELECTED_SLAVES`, with eight as the new-row default. This policy rejects explicit CF/self-leg coupling and `allow_public_self_leg_donation=1` before replacing omitted fields; it never sanitizes a conflicting explicit request into a successful save. A zero public-donation flag is inert. Historical donation timing, measurement, donor, and audit columns remain persisted evidence and are not cleared merely because ordinary activation is removed.

- [ ] **Step 4: Preserve two-argument `ProvisioningService` construction**

Replace constructor property promotion in `master/src/Support/ProvisioningService.php` with explicit properties and an optional third parameter:

```php
private Config $config;
private Database $db;
private ForwardModePolicy $forwardModePolicy;

public function __construct(
    Config $config,
    Database $db,
    ?ForwardModePolicy $forwardModePolicy = null,
) {
    $this->config = $config;
    $this->db = $db;
    $this->forwardModePolicy = $forwardModePolicy ?? new ForwardModePolicy();
}
```

Do not change `Bootstrap` or the 43 direct two-argument test constructions. The optional third argument is for focused policy injection only.

- [ ] **Step 5: Run the focused tests**

Run:

```bash
php master/tests/forward_mode_policy_test.php
php master/tests/saveforwardcombination_leigod_guard_test.php
```

Expected: both print `ok`.

- [ ] **Step 6: Commit the policy boundary**

```bash
git add master/src/Support/ForwardModePolicy.php master/src/Support/ProvisioningService.php master/tests/forward_mode_policy_test.php
git commit -m "feat(master): centralize leigod-only mode policy" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Change future-row defaults without rewriting history

**Files:**
- Create: `master/migrations/20260718_leigod_only_forward_defaults.sql`
- Create: `master/tests/leigod_only_persistence_defaults_test.php`
- Modify: `master/migrations/schema.sql:141-172,381-406`
- Modify: `master/src/Support/MigrationRunner.php:204-215`

- [ ] **Step 1: Write the failing migration/default test**

Create `master/tests/leigod_only_persistence_defaults_test.php` with three executable checks:

1. Normalize whitespace in `schema.sql` and assert `forward_combinations` defaults to `leigod_mode`, `bond`, `single`, and `plain_tcp_mux`; assert `forwards` defaults to `leigod_mode`, `bond`, and `plain_tcp_mux`.
2. Read `20260718_leigod_only_forward_defaults.sql`, assert it contains only `ALTER TABLE ... ALTER COLUMN ... SET DEFAULT` statements and contains no `UPDATE`, `DELETE`, or `INSERT` token.
3. Instantiate `MigrationRunner` with `DB_DRIVER=sqlite`, use reflection to invoke `normalizeStatement()` on each migration statement, and assert every default-only `ALTER COLUMN` statement normalizes to an empty string. Also assert an unrelated SQLite `CREATE TABLE` statement remains non-empty.

Use token matching that ignores SQL comments so the explanatory comment may state that rows are not rewritten. End with `echo "ok\n";`.

- [ ] **Step 2: Run the test and verify it fails on legacy defaults/missing migration**

Run:

```bash
php master/tests/leigod_only_persistence_defaults_test.php
```

Expected: FAIL because the new migration is absent and `schema.sql` still defaults new rows to legacy modes.

- [ ] **Step 3: Update fresh-install defaults in `schema.sql`**

Change only column defaults and the descriptive comment:

```sql
-- Active Forward contract: LeiGod backend + standalone bond + plain TCP mux.
-- Deprecated mode values remain valid historical data and are not rewritten.
backend_mode VARCHAR(32) NOT NULL DEFAULT 'leigod_mode',
client_mode VARCHAR(32) NOT NULL DEFAULT 'bond',
runtime_preference VARCHAR(64) NOT NULL DEFAULT 'single',
bond_ingress_mode VARCHAR(32) NOT NULL DEFAULT 'plain_tcp_mux',
```

For `forwards`, use:

```sql
bond_ingress_mode VARCHAR(32) NOT NULL DEFAULT 'plain_tcp_mux',
backend_mode VARCHAR(32) NOT NULL DEFAULT 'leigod_mode',
client_mode VARCHAR(32) NOT NULL DEFAULT 'bond',
```

Do not change nullability, enum/history vocabulary, existing migrations, or persisted rows.

- [ ] **Step 4: Add the MySQL default-only migration**

Create `master/migrations/20260718_leigod_only_forward_defaults.sql`:

```sql
-- Defaults apply only to future rows. Historical Forward and combination values
-- remain unchanged so retirement and audit use the original persisted evidence.
ALTER TABLE forward_combinations
  ALTER COLUMN backend_mode SET DEFAULT 'leigod_mode',
  ALTER COLUMN client_mode SET DEFAULT 'bond',
  ALTER COLUMN runtime_preference SET DEFAULT 'single',
  ALTER COLUMN bond_ingress_mode SET DEFAULT 'plain_tcp_mux';

ALTER TABLE forwards
  ALTER COLUMN backend_mode SET DEFAULT 'leigod_mode',
  ALTER COLUMN client_mode SET DEFAULT 'bond',
  ALTER COLUMN bond_ingress_mode SET DEFAULT 'plain_tcp_mux';
```

There must be no data-changing statement in this migration.

- [ ] **Step 5: Explicitly skip unsupported default alteration on SQLite**

Inside the existing SQLite branch in `MigrationRunner::normalizeStatement()`, add this condition after enum normalization and before the foreign-key constraint condition:

```php
if (preg_match(
    '/^\s*ALTER\s+TABLE\b.*\bALTER\s+COLUMN\b.*\bSET\s+DEFAULT\b/si',
    $normalized ?? $statement
) === 1) {
    return '';
}
```

This is an explicit portability rule, not an indication that SQLite historical rows were rewritten. Fresh SQLite databases obtain the locked defaults from the normalized `schema.sql` definition.

- [ ] **Step 6: Run migration and historical-shape tests**

Run:

```bash
php master/tests/leigod_only_persistence_defaults_test.php
php master/tests/leigod_mode_migration_shape_test.php
php master/tests/leigod_mode_true_mptcp_migration_test.php
```

Expected: all print `ok`. The historical migration tests remain unchanged and continue describing what those old migrations did.

- [ ] **Step 7: Commit default-only persistence changes**

```bash
git add master/migrations/schema.sql master/migrations/20260718_leigod_only_forward_defaults.sql master/src/Support/MigrationRunner.php master/tests/leigod_only_persistence_defaults_test.php
git commit -m "feat(master): default new forwards to leigod bond" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Make combination saves LeiGod-only and non-converting

**Files:**
- Modify: `master/src/Support/ProvisioningService.php:432-710,734-843,964-1029,5387-5412`
- Modify: `master/src/Controllers/AdminController.php:2230-2250`
- Modify: `master/tests/saveforwardcombination_leigod_guard_test.php`
- Modify: `master/tests/saveforwardcombination_leigod_mode_test.php`
- Modify: `master/tests/saveforwardcombination_syncs_forwards_ingress_test.php`
- Modify: `master/tests/forward_combination_default_max_legs_regression_test.php`
- Modify: `master/tests/admin_combination_multi_slave_picker_regression_test.php`
- Create: `master/tests/leigod_only_combination_transaction_test.php`
- Create: `master/tests/leigod_only_combination_list_test.php`

- [ ] **Step 1: Expand failing save tests around omitted fields and write ordering**

Update `saveforwardcombination_leigod_guard_test.php` so its fake `Database` records every `insert()` and `execute()` call. Seed an existing combination when the SQL asks for `SELECT * FROM forward_combinations WHERE id = :id`.

Add this matrix:

```php
[
    'new omitted mode fields' => [null, [], null],
    'new explicit current' => [null, ['backend_mode' => 'current_mode'], 'deprecated_forward_combination_backend_mode'],
    'new explicit cf' => [null, ['backend_mode' => 'cf_mode'], 'deprecated_forward_combination_backend_mode'],
    'new unknown' => [null, ['backend_mode' => 'bogus_mode'], 'invalid_forward_combination_backend_mode'],
    'new mptcp' => [null, ['client_mode' => 'mptcp'], 'unsupported_leigod_runtime_contract'],
    'legacy edit omitted modes' => [$legacyCombination, ['id' => 11], 'deprecated_forward_combination_backend_mode'],
    'old leigod mptcp edit omitted modes' => [$mptcpCombination, ['id' => 12], 'unsupported_leigod_runtime_contract'],
];
```

For every error case, assert the write log is empty. For the successful omitted-field new save, assert the inserted row contains the locked contract, cleared CF/self-leg fields, and `helper_group_cap=8`. Add explicit cap cases: 1, 4, and 8 persist unchanged; 0 and 9 throw `unsupported_leigod_runtime_contract` with `reason=helper_group_cap_out_of_range` and no writes; an edit that omits the cap preserves its existing legal value. Add explicit CF/self-leg field conflicts and assert they fail rather than being silently cleared.

Create `master/tests/leigod_only_combination_transaction_test.php` with a snapshotting fake `Database` that rejects nested transactions. Cover new and edit saves with `combination_slaves` present, pin replacement failure, and linked-Forward reconciliation failure. Assert each public save opens exactly one transaction and that combination, helper metadata, pin rows, denormalized Forward columns, endpoints, publish/destroy tasks, audits, lifecycle rows, and fingerprints commit or roll back together. Add an edit that omits the `combination_slaves` key and prove existing active `admin_pin` rows are preserved; add an explicit empty array and prove only that request clears the pins. Fault-inject `forwardCombinationById()` after commit and prove the committed snapshot remains intact even though response construction fails: read-back occurs after the transaction and is not part of the atomic write set.

Update `saveforwardcombination_leigod_mode_test.php` so successful cases use `client_mode=bond`; invert successful `current_mode`/`cf_mode` assertions to exact deprecation errors.

Rewrite `forward_combination_default_max_legs_regression_test.php` as an executable persistence-policy test rather than a source-string sentinel. It must save a new locked combination with the cap omitted and observe eight, preserve an existing legal cap of four on an omitted-field edit, accept explicit one and eight, and reject zero and nine with no writes. Assert the saved value is read directly from normalized policy output and that `DEFAULT_MAX_LEGS_USED=4` plus `defaultMaxLegsUsedForCombination()` are gone. Do not invoke a candidate selector in this Task 3 test: Task 5's explicit-selected-source and Speed Plus preflight tests own the complete raw pin set, fifth-through-eighth delivery, duplicate rejection, and over-cap rejection. Remove assertions for `mptcp_preferred_bond_fallback`, `cf_mode`, and native MPTCP.

- [ ] **Step 2: Run the save tests and verify they fail against coercion/current legacy support**

Run:

```bash
php master/tests/saveforwardcombination_leigod_guard_test.php
php master/tests/saveforwardcombination_leigod_mode_test.php
php master/tests/saveforwardcombination_syncs_forwards_ingress_test.php
php master/tests/forward_combination_default_max_legs_regression_test.php
```

Expected: FAIL because `saveForwardCombination()` still defaults to `current_mode`, coerces LeiGod to MPTCP, accepts deprecated modes, and caps locked bond combinations at four.

- [ ] **Step 3: Normalize before reading mutable fields or writing**

Make the public method the single transaction owner and move the current persistence body into a transaction-free helper:

```php
public function saveForwardCombination(array $payload): array
{
    $combinationId = $this->db->transaction(
        fn(): int => $this->saveForwardCombinationLocked($payload)
    );

    return $this->forwardCombinationById($combinationId) ?? [];
}

private function saveForwardCombinationLocked(array $payload): int
{
    $id = (int) ($payload['id'] ?? 0);
    $existing = null;
    if ($id > 0) {
        $existing = $this->lockForwardCombinationById($id);
        if ($existing === null) {
            throw new ApiException('forward_combination_not_found', 404, [
                'forward_combination_id' => $id,
            ]);
        }
    }
    $payload = $this->forwardModePolicy->normalizeCombinationInput(
        $payload,
        $existing
    );

    // Continue with validation and writes shown below, then return $id.
}
```

`lockForwardCombinationById()` uses `SELECT * ... FOR UPDATE` on MySQL and the same query without the unsupported suffix in SQLite tests. It is a raw lookup: it must not filter, normalize, or default the existing row before `normalizeCombinationInput()` validates it. `forwardCombinationById()` read-back stays after commit.

Then read the four mode fields only from normalized payload values:

```php
$backendMode = (string) $payload['backend_mode'];
$clientMode = (string) $payload['client_mode'];
$runtimePreference = (string) $payload['runtime_preference'];
$bondIngressMode = (string) $payload['bond_ingress_mode'];
```

Delete the branches that coerce `single` to `mptcp`, permit `mptcp_preferred_bond_fallback`, derive `current_mode`/`cf_mode`, or require a CF location group. Keep the existing sidecar, line, stable credential, helper group, and LeiGod carrier validation. The normalized row must persist:

```php
'backend_mode' => ForwardModePolicy::BACKEND_MODE,
'client_mode' => ForwardModePolicy::CLIENT_MODE,
'runtime_preference' => ForwardModePolicy::RUNTIME_PREFERENCE,
'bond_ingress_mode' => ForwardModePolicy::BOND_INGRESS_MODE,
'cf_location_group_id' => null,
'allow_private_self_leg' => 0,
'allow_public_self_leg_donation' => 0,
'self_leg_scope' => 'none',
'self_leg_role' => 'none',
'self_leg_cf_transport' => 'cf_direct',
'self_leg_outer_transport' => 'leigod',
'leigod_uot_impl' => $leigodUotImpl,
```

Validate `$leigodUotImpl` as exactly `gost` or `lguot` using the existing LeiGod carrier field. Preserve the existing persisted carrier when an edit omits it; for a new row, keep the current application default rather than making carrier choice part of `ForwardModePolicy`.

Delete `DEFAULT_MAX_LEGS_USED=4` and the mode-branching `defaultMaxLegsUsedForCombination()`. Read `helper_group_cap` only from the normalized payload. The central policy supplies eight for a new omitted value, preserves an existing legal one-through-eight value on edit, and rejects zero or values above eight. Do not use `max(0, ...)` or `<=0` fallback logic after normalization because that would turn an explicitly invalid cap into a successful save.

Before any update/insert, call:

```php
$this->forwardModePolicy->assertCombinationContract(array_replace(
    $payload,
    [
        'id' => $id,
        'is_active' => $isActive,
    ]
));
```

Do not infer missing edit values from new defaults before validating the existing persisted row.

- [ ] **Step 4: Keep denormalized Forward synchronization locked**

The post-save update of linked Forwards must set exact constants and clear CF coupling:

```php
$this->db->execute(
    'UPDATE forwards
     SET backend_mode = :backend_mode,
         client_mode = :client_mode,
         bond_ingress_mode = :bond_ingress_mode,
         cf_location_group_id = NULL,
         updated_at = :updated_at
     WHERE forward_combination_id = :forward_combination_id',
    [
        'backend_mode' => ForwardModePolicy::BACKEND_MODE,
        'client_mode' => ForwardModePolicy::CLIENT_MODE,
        'bond_ingress_mode' => ForwardModePolicy::BOND_INGRESS_MODE,
        'updated_at' => $this->now(),
        'forward_combination_id' => $id,
    ]
);
```

This path is reachable only for an already locked combination or a new combination. Existing legacy and old LeiGod/MPTCP combinations fail before this update; their separately gated normalization belongs to the activation plan.

- [ ] **Step 5: Separate ordinary lists from raw historical lookup**

Keep private `forwardCombinationById()` as an unfiltered raw lookup for validation and later retirement evidence. Filter all ordinary list results through the policy:

```php
private function selectableForwardCombinations(array $rows): array
{
    return array_values(array_filter(
        $rows,
        fn(array $row): bool => $this->forwardModePolicy->isSelectableCombination($row)
    ));
}
```

Apply it after helper-group attachment in:

- `allowedForwardCombinationsForPlan()`
- `availableForwardCombinationsForService()`
- `listForwardCombinations()`
- `staticSlaveSidecarCombinations()` and its point/node variants

`listForwardCombinations()` remains the ordinary admin list and therefore excludes historical rows. A raw operations-only retirement query will be added in the retirement plan; do not add an editable legacy list here.

Create `leigod_only_combination_list_test.php` with a fake database returning one locked, one legacy, one old LeiGod/MPTCP, and one inactive combination. Assert every ordinary list method returns only the locked row while direct save of each rejected row still returns the specific error rather than `not_found`.

- [ ] **Step 6: Persist explicit pins and reconcile linked Forwards in the same transaction**

Delete public `saveCombinationAdminPinnedSlaves()`. `saveForwardCombinationLocked()` owns the optional `combination_slaves` field as part of the same aggregate mutation:

```php
if (array_key_exists('combination_slaves', $payload)) {
    $rawNodeIds = is_array($payload['combination_slaves'])
        ? $payload['combination_slaves']
        : throw new ApiException('invalid_speed_plus_leg_set', 422, [
            'reason' => 'invalid_selection_payload',
        ]);
    $nodeIds = array_map(static fn(mixed $value): int => (int) $value, $rawNodeIds);
    if (in_array(0, $nodeIds, true)) {
        throw new ApiException('invalid_speed_plus_leg_set', 422, [
            'reason' => 'invalid_selected_node_id',
        ]);
    }
    if (count(array_unique($nodeIds)) !== count($nodeIds)) {
        throw new ApiException('invalid_speed_plus_leg_set', 422, [
            'reason' => 'duplicate_selected_node_id',
        ]);
    }
    $cap = (int) $payload['helper_group_cap'];
    if (count($nodeIds) > $cap
        || count($nodeIds) > ForwardModePolicy::MAX_SELECTED_SLAVES) {
        throw new ApiException('invalid_speed_plus_leg_set', 422, [
            'reason' => 'selected_leg_count_exceeds_cap',
            'selected_count' => count($nodeIds),
            'combination_cap' => $cap,
            'maximum' => ForwardModePolicy::MAX_SELECTED_SLAVES,
        ]);
    }
    $this->replaceCombinationAdminPinsLocked($id, $nodeIds);
}

$this->reconcileActiveForwardsForCombinationLocked($id);
return $id;
```

`replaceCombinationAdminPinsLocked()` contains the existing deactivate/upsert body but performs no normalization, transaction, republish, or read-back. An omitted key preserves existing pins; an explicit empty array deactivates them. Validate the complete request list before deactivating a single row. The database's unique combination/node/kind identity remains defense in depth.

Add `reconcileActiveForwardsForCombinationLocked()` as a private deterministic query over enabled active-service Forward IDs bound to the combination. In Task 3 it calls the currently transaction-free `reconcileForwardEndpoints()` body for every ID and lets every exception escape; it must not catch/log and continue. Task 4 renames that internal call to `reconcileForwardEndpointsLocked()` when it creates the public transaction-owning wrapper. This makes combination row, helper metadata, pins, denormalized Forward fields, endpoint membership, durable tasks, audits, lifecycle, and fingerprints one rollback unit.

Change `AdminController::saveForwardCombination()` to one call only:

```php
$this->provisioning?->saveForwardCombination(
    is_array($request->body()) ? $request->body() : []
);
```

It must not call a second pin mutation. Rewrite `admin_combination_multi_slave_picker_regression_test.php` to require the multi-select and the single aggregate call, forbid `saveCombinationAdminPinnedSlaves`, and assert the service consumes `combination_slaves` inside `saveForwardCombinationLocked()`.

- [ ] **Step 7: Run focused combination tests**

Run:

```bash
php master/tests/forward_mode_policy_test.php
php master/tests/saveforwardcombination_leigod_guard_test.php
php master/tests/saveforwardcombination_leigod_mode_test.php
php master/tests/saveforwardcombination_syncs_forwards_ingress_test.php
php master/tests/forward_combination_default_max_legs_regression_test.php
php master/tests/admin_combination_multi_slave_picker_regression_test.php
php master/tests/leigod_only_combination_transaction_test.php
php master/tests/leigod_only_combination_list_test.php
```

Expected: all print `ok`.

- [ ] **Step 8: Commit LeiGod-only combination persistence**

```bash
git add master/src/Support/ProvisioningService.php master/src/Controllers/AdminController.php master/tests/saveforwardcombination_leigod_guard_test.php master/tests/saveforwardcombination_leigod_mode_test.php master/tests/saveforwardcombination_syncs_forwards_ingress_test.php master/tests/forward_combination_default_max_legs_regression_test.php master/tests/admin_combination_multi_slave_picker_regression_test.php master/tests/leigod_only_combination_transaction_test.php master/tests/leigod_only_combination_list_test.php
git commit -m "feat(master): reject deprecated forward combinations" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Guard routes, Forward selection, edits, and reactivation before mutation

**Files:**
- Modify: `master/src/Support/ProvisioningService.php:756-827,1068-1081,3561-3625,4068-4140,4331-4818,5414-5546`
- Modify: `master/src/Controllers/TenantController.php` at `serviceDetails`, `editForward`, `saveForwardEdit`, and `reactivateForwards`
- Modify: `master/tests/tenant_forward_route_selector_test.php`
- Modify: `master/tests/saveforwardroute_ingress_guard_test.php`
- Modify: `master/tests/provisioning_forward_non_structural_update_test.php`
- Modify: `master/tests/tenant_forward_edit_regression_test.php`
- Create: `master/tests/leigod_only_whmcs_forward_transaction_test.php`
- Create: `master/tests/leigod_only_route_transaction_test.php`
- Create: `master/tests/leigod_only_forward_operation_guard_test.php`
- Create: `master/tests/leigod_only_copy_clone_inventory_test.php`
- Create: `master/tests/tenant_leigod_only_reactivation_test.php`

- [ ] **Step 1: Write the failing operation matrix**

Create `leigod_only_forward_operation_guard_test.php` with a fake database that:

- returns a Forward and referenced combination independently,
- logs `execute`, `insert`, audit insert, endpoint revocation update, and provision-task insert calls,
- exposes wrappers around private boundaries through public methods rather than reflection where possible.

For each operation below, run three fixtures: legacy combination, legacy Forward, and mismatched/unsupported Forward-combination contract:

```text
saveForwardCombination edit
primaryCombinationIdForRoute selection
createForward with combination
updateForward
updateForwardNonStructural
saveForwardRoute
reactivateEligibleForwardsForUser
buildClientConfig
```

Assert every fixture returns its expected stable error and the write log is empty. Specifically assert `updateForward()` does not revoke endpoints before rejection and `updateForwardNonStructural()` does not update the Forward before rejection.

Add positive locked-contract cases so the tests do not pass by rejecting everything.

- [ ] **Step 2: Run the operation test and verify destructive-order failures**

Run:

```bash
php master/tests/leigod_only_forward_operation_guard_test.php
php master/tests/provisioning_forward_non_structural_update_test.php
```

Expected: FAIL because endpoint revocation/update currently occurs before persisted contract validation.

- [ ] **Step 3: Add one service helper that loads and validates both persisted rows**

Add these methods to `ProvisioningService`:

```php
/**
 * @param array<string, mixed> $forward
 * @return array<string, mixed>
 */
private function assertForwardActiveContract(array $forward): array
{
    $combinationId = (int) ($forward['forward_combination_id'] ?? 0);
    $combination = $combinationId > 0
        ? $this->forwardCombinationById($combinationId)
        : null;
    $this->forwardModePolicy->assertPersistedForwardContract($forward, $combination);
    return $combination ?? [];
}

/** @return array<string, mixed> */
private function loadForwardForActiveOperation(int $forwardId): array
{
    $forward = $this->db->fetchOne(
        'SELECT * FROM forwards WHERE id = :id LIMIT 1',
        ['id' => $forwardId]
    );
    if ($forward === null) {
        throw new ApiException('forward_not_found', 404);
    }
    $this->assertForwardActiveContract($forward);
    return $forward;
}
```

No helper may default a missing mode or catch the policy error.

Create `master/tests/leigod_only_whmcs_forward_transaction_test.php` with a snapshotting fake `Database` that fails on a nested `transaction()` call. Execute `createOrUpdateServiceFromWhmcs()` with an active service payload containing one fully locked Forward and prove the path opens exactly one outer transaction, calls the transaction-free Forward/reconciliation helpers, and commits service, Forward, endpoint, publish task, audit, lifecycle, and fingerprint state together. Inject a publication-preflight failure after the Forward insert and assert all of those tables return to the exact pre-call snapshot. Add an update case with an already-existing service and Forward so service-scoped republish also stays inside the existing outer owner instead of opening a nested transaction.

Create `master/tests/leigod_only_route_transaction_test.php` with the same nested-transaction-rejecting snapshot fake. Save a route with two enabled, fully selectable LeiGod combinations, then assert the route row, all membership rows, and reconciled state for every linked Forward commit under exactly one transaction. Inject failure while validating the second combination and prove the write log is empty. Inject failure while reconciling the second linked Forward and prove route/membership changes plus all first-Forward endpoint/task/audit/lifecycle/fingerprint changes roll back. Seed a historical route with a deprecated, unsupported, inactive, missing, or duplicate member and prove an edit fails before writes even when the submitted membership omits that row; ordinary save cannot erase or convert historical route relationships via replace-all semantics. Assert an explicit ineligible member fails whether its requested membership is enabled or disabled.

- [ ] **Step 4: Give every Forward mutation one outer transaction**

`Database::transaction()` has no savepoints and is not nested-transaction-aware. Make each public mutation entry point own exactly one transaction; no method it calls may open another transaction.

Use this shape for create and structural update:

```php
public function createForward(
    int $serviceId,
    array $payload,
    string $actor = 'user'
): array {
    $forwardId = $this->db->transaction(function () use (
        $serviceId,
        $payload,
        $actor
    ): int {
        return $this->saveForwardLocked(
            $serviceId,
            null,
            $payload,
            $actor
        );
    });

    return $this->buildClientConfig($forwardId);
}

public function updateForward(
    int $forwardId,
    array $payload,
    string $actor = 'user'
): array {
    $updatedForwardId = $this->db->transaction(function () use (
        $forwardId,
        $payload,
        $actor
    ): int {
        $forward = $this->loadForwardForActiveOperationLocked($forwardId);
        return $this->saveForwardLocked(
            (int) $forward['service_id'],
            $forwardId,
            $payload,
            $actor
        );
    });

    return $this->buildClientConfig($updatedForwardId);
}
```

Rename the current transaction-opening/multi-purpose `saveForward()` body to `saveForwardLocked()` and make it return only the assigned Forward ID. It assumes the caller owns the transaction. For an update it receives an already locked and validated Forward; for a create it may insert the Forward to obtain its ID. Any exception must roll back that insert and every endpoint, task, audit, lifecycle, and fingerprint mutation.

Do not call `revokeForwardEndpoints()` before `saveForwardLocked()`. During this Task 4 commit, keep the two existing initial endpoint/task branches inside `saveForwardLocked()` so the commit remains runnable, but they now execute within the public entry point's outer transaction. Task 5 deletes both branches and replaces them with the hardened shared reconciliation path.

`createOrUpdateServiceFromWhmcs()` already owns one transaction around service persistence, its Forward loop, and service-scoped republish. It must not call the new transaction-owning `createForward()` or `republishActiveForwardsForService()` from that closure. Replace each nested `createForward()` call with `saveForwardLocked($serviceId, null, $forwardPayload, 'whmcs')`, retain the returned Forward ID, and reconcile through transaction-free locked helpers before the closure returns. For pre-existing enabled Forwards of the service, query their IDs in deterministic order and call `reconcileForwardEndpointsLocked()` directly under the same owner. Do not build client config inside the webhook transaction. Any policy or publication failure rolls back service, Forward, endpoint, task, audit, lifecycle, and fingerprint changes together; do not catch it merely to preserve a partial service update.

Split the current transaction-free `reconcileForwardEndpoints()` body now so Task 4 callers do not depend on code introduced only in Task 5:

```php
private function reconcileForwardEndpoints(
    int $forwardId,
    array $forceEndpointIds = [],
): void {
    $this->db->transaction(function () use (
        $forwardId,
        $forceEndpointIds
    ): void {
        $this->reconcileForwardEndpointsLocked(
            $forwardId,
            $forceEndpointIds
        );
    });
}

```

Rename only the existing declaration at `ProvisioningService::reconcileForwardEndpoints()` to:

```php
private function reconcileForwardEndpointsLocked(
    int $forwardId,
    array $forceEndpointIds = [],
): void
```

Retain its existing body byte-for-byte in this commit; do not add begin/commit calls or catch errors there. The wrapper is for callers that do not already own a transaction. The locked helper never opens or commits one. Task 5 replaces that retained body with the complete fixed-order algorithm in its Step 7, consumes the force set, and routes every publication path through it.

Use the same outer-owner shape in `updateForwardNonStructural()`:

```php
public function updateForwardNonStructural(
    int $forwardId,
    array $payload,
    string $actor = 'user'
): array {
    $updatedForwardId = $this->db->transaction(function () use (
        $forwardId,
        $payload,
        $actor
    ): int {
        $forward = $this->loadForwardForActiveOperationLocked($forwardId);
        $normalized = $this->normalizeForwardNonStructuralUpdate(
            $forward,
            $payload
        );
        $this->updateForwardNonStructuralLocked(
            $forward,
            $normalized,
            $actor
        );
        $this->reconcileForwardEndpointsLocked($forwardId);
        return $forwardId;
    });

    return $this->buildClientConfig($updatedForwardId);
}
```

`normalizeForwardNonStructuralUpdate()` performs all listen/target/transport validation without writes. `updateForwardNonStructuralLocked()` performs only the Forward update and audit insert. It must not revoke, reconcile, open a transaction, or build client config.

Add `loadForwardForActiveOperationLocked()` using `SELECT ... FOR UPDATE` on MySQL and the same `SELECT` without the unsupported lock suffix in SQLite tests. It calls `assertForwardActiveContract()` before returning. When creating/selecting, load the raw selected combination and call `assertCombinationContract()` even if the ID came from an ordinary filtered list. Set only the Forward's three persisted contract fields (`backend_mode`, `client_mode`, and `bond_ingress_mode`) to locked constants and clear `cf_location_group_id`; `runtime_preference` remains combination-owned. Remove legacy defaults and accepted `single|mptcp` client branches.

This task establishes transaction ownership and the wrapper/locked-helper split. Task 5 hardens `reconcileForwardEndpointsLocked()` and removes the remaining publication bypasses.

- [ ] **Step 5: Make route lookup and route save policy-aware and atomic**

Add one raw loader that returns every persisted membership row, including orphaned and disabled rows, joined with all combination columns:

```php
/** @return array<int, array<string, mixed>> */
private function rawForwardRouteMembers(int $routeId, bool $forUpdate): array
```

Use `LEFT JOIN forward_combinations` so an orphan remains visible. Order by `frc.sort_order ASC, frc.id ASC`; do not deduplicate or filter first. When `$forUpdate` is true, lock the route and membership rows on MySQL; omit unsupported lock syntax in SQLite tests. For ordinary active operations validate the full raw set before returning a primary member:

- positive membership ID and combination ID,
- joined combination ID exactly equals the persisted member ID,
- no duplicate combination ID,
- every member passes `assertCombinationContract()` and is active, regardless of `frc.enabled`,
- at least one member has `frc.enabled=1`,
- enabled members retain the existing common-ingress consistency contract,
- the lowest `sort_order,id` enabled member is the deterministic primary.

A deprecated member takes deprecation precedence. Missing, inactive, unsupported, duplicate, or zero-enabled sets throw `unsupported_leigod_runtime_contract` with `reason=route_member_missing|route_member_inactive|route_member_contract_conflict|duplicate_route_member|route_has_no_enabled_member`. Do not skip a bad row to select the next one.

`primaryCombinationIdForRoute()` calls this validator and returns the deterministic primary ID. `availableForwardRoutesForService()` and ordinary Admin route lists return only routes whose complete raw membership set validates; do not expose a route merely because one member is selectable or `forward_routes.is_active=1`.

Make `saveForwardRoute()` the single transaction owner:

```php
public function saveForwardRoute(array $payload): array
{
    $routeId = $this->db->transaction(
        fn(): int => $this->saveForwardRouteLocked($payload)
    );
    return ['id' => $routeId];
}
```

`saveForwardRouteLocked()` first locks and validates every existing member when editing, before normalizing requested members or writing. It then validates every requested member—including disabled entries—using raw `SELECT *`, rejects invalid IDs and duplicates rather than dropping them, requires at least one enabled member, and retains the common-ingress guard. Only then may it insert/update the route and replace memberships. Finish with `reconcileActiveForwardsForRouteLocked($routeId)`, a deterministic transaction-free loop that calls `reconcileForwardEndpointsLocked()` for every enabled active-service Forward and lets failures escape. Delete catch-and-log behavior from the route mutation path; public bulk republish wrappers may continue independent per-Forward error reporting only when they are not part of a route save transaction.

When a Forward is created or updated with `forward_route_id>0`, validate the entire route and require `forward_combination_id`/`active_combination_id` to equal the route's deterministic primary enabled member. A direct combination-only Forward keeps `forward_route_id=null`. Add `route_primary_mismatch` and `route_membership_invalid` to the operation matrix; neither path may overwrite the submitted/historical relationship to make it pass.

Update `tenant_forward_route_selector_test.php`, `saveforwardroute_ingress_guard_test.php`, and `leigod_only_route_transaction_test.php` to execute locked multi-member, deprecated, disabled-deprecated, inactive, orphan, duplicate, zero-enabled, common-ingress mismatch, route-primary mismatch, rollback, and positive cases instead of relying only on source strings.

- [ ] **Step 6: Filter ordinary service and tenant Forward lists**

In `serviceSummary()`, join `forward_combinations combo` and include only rows for which both persisted sides satisfy the locked contract. Revalidate each returned row with `assertPersistedForwardContract()` before adding it to the ordinary `forwards` result. Historical rows remain in the database but do not appear in tenant service details.

In `TenantController::editForward()` and `saveForwardEdit()`, use a policy-aware `ProvisioningService` method instead of directly rendering/mutating an arbitrary row. A legacy ID must be treated as unavailable to the ordinary editor and must never render editable fields.

- [ ] **Step 7: Lock the copy/clone capability inventory**

Create `master/tests/leigod_only_copy_clone_inventory_test.php`. Read `master/src/Bootstrap.php`, `master/src/Controllers/AdminController.php`, `master/src/Controllers/TenantController.php`, `master/src/Controllers/ServiceController.php`, and `master/src/Support/ProvisioningService.php`. Assert there is no registered route or declared method whose Forward/combination operation name contains `copy` or `clone`, case-insensitively. Check both word orders (`copyForward`, `forwardClone`, `copy-forward`, `forward/clone`, and their combination equivalents).

This test records the current capability boundary; do not invent a copy/clone API. Add this rule to the test failure message and implementation note: any future copy/clone entry point must call the same protected `createForward()` or `saveForwardCombination()` public boundary and must inherit raw persisted validation, locked defaults, publication preflight, and transaction semantics. It may not duplicate rows directly.

Run:

```bash
php master/tests/leigod_only_copy_clone_inventory_test.php
```

Expected: prints `ok` on the current baseline.

- [ ] **Step 8: Replace bulk SQL reactivation with per-Forward atomic reactivation**

Add to `ProvisioningService`:

```php
/**
 * @return array{
 *   reactivated:int,
 *   skipped:array<int, array{forward_id:int,error:string}>
 * }
 */
public function reactivateEligibleForwardsForUser(int $userId): array
{
    $rows = $this->db->fetchAll(
        'SELECT f.id
         FROM forwards f
         INNER JOIN services s ON s.id = f.service_id
         WHERE s.user_id = :user_id AND s.status = :status
         ORDER BY f.id ASC',
        ['user_id' => $userId, 'status' => 'active']
    );
    $reactivated = 0;
    $skipped = [];

    foreach ($rows as $row) {
        $forwardId = (int) ($row['id'] ?? 0);
        try {
            $this->db->transaction(function () use ($forwardId): void {
                $this->loadForwardForActiveOperationLocked($forwardId);
                $this->db->execute(
                    'UPDATE forwards
                     SET enabled = 1, updated_at = :updated_at
                     WHERE id = :id',
                    ['id' => $forwardId, 'updated_at' => $this->now()]
                );
                $this->reconcileForwardEndpointsLocked($forwardId);
            });
            $reactivated++;
        } catch (ApiException $exception) {
            $skipped[] = [
                'forward_id' => $forwardId,
                'error' => $exception->getMessage(),
            ];
        }
    }

    return ['reactivated' => $reactivated, 'skipped' => $skipped];
}
```

Each Forward gets one outer transaction so a topology/auth/publication failure rolls its `enabled=1`, endpoint, task, audit, and fingerprint changes back without blocking other eligible Forwards. No inner helper may open a transaction. Do not call `buildClientConfig()` inside these transactions.

Change `TenantController::reactivateForwards()` to call this method after its existing account/service checks. Flash an explicit count such as `Reactivated 2 LeiGod forwards; skipped 3 deprecated or unsupported forwards.` Never run the current unqualified bulk `UPDATE forwards SET enabled = 1`.

Create `tenant_leigod_only_reactivation_test.php` with one locked, one `current_mode`, one `cf_mode`, one mismatched row, and one locked row whose Task 5 preflight is fault-injected. Assert only the first locked row is enabled/reconciled; the faulted locked row returns to `enabled=0`; endpoint/task/audit/fingerprint snapshots are unchanged for every skipped row; skipped IDs/errors are exact.

- [ ] **Step 9: Run route, edit, list, and reactivation tests**

Run:

```bash
php master/tests/leigod_only_forward_operation_guard_test.php
php master/tests/leigod_only_whmcs_forward_transaction_test.php
php master/tests/leigod_only_route_transaction_test.php
php master/tests/tenant_forward_route_selector_test.php
php master/tests/saveforwardroute_ingress_guard_test.php
php master/tests/provisioning_forward_non_structural_update_test.php
php master/tests/tenant_forward_edit_regression_test.php
php master/tests/leigod_only_copy_clone_inventory_test.php
php master/tests/tenant_leigod_only_reactivation_test.php
```

Expected: all print `ok`.

- [ ] **Step 10: Commit policy-aware atomic mutations**

```bash
git add master/src/Support/ProvisioningService.php master/src/Controllers/TenantController.php master/tests/leigod_only_forward_operation_guard_test.php master/tests/leigod_only_whmcs_forward_transaction_test.php master/tests/leigod_only_route_transaction_test.php master/tests/leigod_only_copy_clone_inventory_test.php master/tests/tenant_forward_route_selector_test.php master/tests/saveforwardroute_ingress_guard_test.php master/tests/provisioning_forward_non_structural_update_test.php master/tests/tenant_forward_edit_regression_test.php master/tests/tenant_leigod_only_reactivation_test.php
git commit -m "feat(master): guard forward selection and reactivation" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Fail closed at reconciliation, publication, and desired-state boundaries

**Files:**
- Modify: `master/src/Support/ProvisioningService.php:2895-3190,3663-4064,5122-5915,6150-6777,7058-7145,8951-9165,9247-9276`
- Modify: `master/src/Controllers/ClientAgentController.php:24-81,244-315`
- Modify: `master/src/Controllers/NodeController.php:116-252`
- Modify: `master/src/Controllers/SidecarAgentController.php:22-317`
- Modify: `master/tests/provisioning_republish_wrappers_regression_test.php`
- Modify: `master/tests/agent_udp_uot_sidepath_management_test.php`
- Modify: `master/tests/provisioning_pick_fastest_slaves_for_combination_regression_test.php`
- Modify: `master/tests/provisioning_forward_combination_helper_groups_regression_test.php`
- Modify: `master/tests/provisioning_active_endpoint_drift_republish_regression_test.php`
- Modify: `master/tests/provisioning_node_republish_endpoint_id_regression_test.php`
- Modify: `master/tests/provisioning_selector_port_collision_free_regression_test.php`
- Modify: `master/tests/provisioning_static_sidecar_selector_port_regression_test.php`
- Modify: `master/tests/forward_combination_static_sidecar_wg_regression_test.php`
- Modify: `master/tests/bond_latency_first_publish_regression_test.php`
- Modify: `master/tests/bond_leg_cooldown_publish_regression_test.php`
- Modify: `master/tests/bond_leigod_leg_cooldown_publish_test.php`
- Modify: `master/tests/provisioning_forward_non_structural_update_test.php`
- Modify: `master/tests/provisioning_sidecar_republish_test.php`
- Delete: `master/tests/provisioning_donation_policy_payload_regression_test.php`
- Delete: `master/tests/client_cfwg_latency_watchdog_default_all_active_regression_test.php`
- Delete: `master/tests/node_heartbeat_donation_self_check_ingest_regression_test.php`
- Delete: `master/tests/sidecar_donation_reverse_probe_payload_regression_test.php`
- Delete: `master/tests/sidecar_mptcp_combination_runtime_regression_test.php`
- Delete: `master/tests/sidecar_cf_runtime_target_regression_test.php`
- Delete: `master/tests/sidecar_cf_direct_backend_management_contract_test.php`
- Delete: `master/tests/sidecar_cf_mode_loopback_target_regression_test.php`
- Delete: `master/tests/sidecar_cfwg_route_publishes_wg_listen_port_regression_test.php`
- Create: `master/tests/leigod_only_client_agent_protocol_test.php`
- Create: `master/tests/leigod_only_node_agent_protocol_test.php`
- Create: `master/tests/leigod_only_sidecar_agent_protocol_test.php`
- Create: `master/tests/leigod_only_desired_state_guard_test.php`
- Create: `master/tests/leigod_only_sidecar_desired_state_test.php`
- Create: `master/tests/leigod_only_reconcile_publish_guard_test.php`
- Create: `master/tests/leigod_only_speed_plus_preflight_test.php`
- Create: `master/tests/leigod_only_reconcile_atomicity_test.php`

- [ ] **Step 1: Write failing desired-state and publication matrix tests**

Create `leigod_only_desired_state_guard_test.php` and `leigod_only_reconcile_publish_guard_test.php` using fake databases with one locked positive fixture and the three negative fixtures from Task 4. The locked fixture explicitly sets `allow_public_self_leg_donation=0`; add a persisted public-donation-enabled fixture and assert it is rejected before output or writes.

Create `leigod_only_client_agent_protocol_test.php` as an authenticated executable `ClientAgentController::pullConfigs()` test. Use the existing client-controller fake database/session pattern, make `artifactMetadata()` record requested artifact keys, and decode response JSON. Assert the pull requests `client_agent`, `client_agent_installer`, and `leikwan_bond_linux_amd64`, but never requests `mptcp_client_runtime` or `cfwg_latency_watchdog`. Assert the client response has no `desired_mptcp_runtime_version`, `desired_mptcp_runtime_sha256`, `desired_cfwg_watchdog_version`, or `desired_cfwg_watchdog_sha256`; `agent_config_json` has no `cfwg_latency_watchdog` or `cfwg_edge_pins`; and valid bond artifact/config fields remain. Seed stale stored watchdog and edge-pin blocks and prove authoritative output drops rather than forwards them.

Create `leigod_only_node_agent_protocol_test.php` as an authenticated executable `NodeController::heartbeat()` test. Send a syntactically valid `donation_self_check` payload, record provisioning calls and donor-table writes, and assert heartbeat still returns `ok=true` while neither `recordDonationSelfCheck()` nor any `forward_combination_self_leg_donors` insert/update occurs. Ordinary capability/identity heartbeat writes and unrelated guarded reconciliation remain permitted.

Create `leigod_only_sidecar_agent_protocol_test.php` as an authenticated executable `SidecarAgentController::heartbeat()` test. Send a valid `donation_reverse_probe`, record artifact lookups/provisioning calls, and decode JSON. Assert no `recordDonationReverseProbe()` call, no donor-probe write, no `mptcp_sidecar_runtime` artifact lookup, no `desired_sidecar_runtime_version` or `desired_sidecar_runtime_sha256`, and no `donation_reverse_probe_targets`. Assert `desired_bond_version`, `desired_bond_sha256`, and normal point-scoped management remain.

Create `leigod_only_sidecar_desired_state_test.php` as an executable controller heartbeat test with an authenticated sidecar point and these enabled active-service rows on that point: one fully locked Forward/combination pair, one `current_mode`, one `cf_mode`, one LeiGod/native-MPTCP combination, one locked Forward pointing at a mismatched combination, and one disabled locked Forward. Stub artifact metadata and unrelated management reads using the existing sidecar-controller fake-database pattern, then decode the `Response` JSON rather than checking source strings. Assert:

```text
management.mode = bond
management.listen_port = configured bond listen port
management.bond_runtime.forward_ids = [locked_forward_id]
management.bond_runtime contains no legacy/mismatched/disabled ID
management.quic_enabled = false
management contains no quic_listen, quic_server_name, or hybrid_mode key
management contains no mptcp_runtime key
management contains no cf_runtime key
management contains no self_leg_client_peers key
management contains no donation_reverse_probe_targets key
```

Also assert the controller never calls `mptcpSidecarManagementForPoint()`, `mptcpRuntimeRowsForSidecarPoint()`, `selfLegClientPeersForPoint()`, or `donationReverseProbeTargetsForPoint()` while assembling ordinary active desired state. An absent legacy runtime section is not cleanup authorization; the retirement plan later adds a separate explicit `runtime_retirements` channel.

Delete the five named SidecarAgentController source-string active-success tests after this executable replacement exists. Their old assertions—active MPTCP rows, CF target routing, direct CF backend, `cf-forward-*` services, and CFWG listen-port publication—must not survive as ordinary product contracts. Delete the three named CFWG/donation protocol source-string success tests only after the executable client, node, and sidecar protocol tests above pass; each old success contract has exactly one named negative replacement. Delete `provisioning_donation_policy_payload_regression_test.php` only after `leigod_only_desired_state_guard_test.php` proves ordinary output omits `donation_policy`; its old success assertion is not moved into Task 6.

Convert `provisioning_pick_fastest_slaves_for_combination_regression_test.php` and `provisioning_forward_combination_helper_groups_regression_test.php` into executable selected-source tests. Seed two explicit active `forward_combination_slaves` rows with `selection_kind=admin_pin`, a third active helper-group node, a fourth node reachable only through `forward_combinations.node_group_key`, an active donor row, and historical active `forward_combination_helper_groups` rows. Assert the raw ordinary selection contains exactly the two explicit pin rows in persisted `sort_order, node_id` order. Helper-group and `node_group_key` rows remain readable historical/admin metadata but cannot inject, auto-expand, replace, or provide a fallback candidate; donor rows cannot enter the ordinary pool. A combination with no explicit pins yields `invalid_speed_plus_leg_set` with `reason=no_usable_legs` at publication preflight and zero writes—it does not scan groups. Also seed duplicate active pins for one node and prove the complete raw row set reaches preflight and fails with `reason=duplicate_node_id` before any deduplication. Seed nine distinct pins with cap eight and prove it fails `selection_exceeds_combination_cap`; no helper may slice the raw rows before validation.

Exercise these boundaries:

```text
assignedForwardConfigsForClient
buildClientConfig
udpUotSidepathConfigForNode
udpUotSidepathConfigForSidecarPoint
slaveSidecarUplinkManagementForNode
slaveSidecarUplinkManagementForPoint
reconcileActiveForwardsForGroup
sweepNonConvergentEndpoints
republishActiveEndpointsForNode
republishActiveEndpoint
republishActiveForwardsForSidecarPoint
republishActiveForwardsForCombination
republishActiveForwardsForRoute
republishActiveForwardsForClient
republishActiveForwardsForNode
republishActiveForwardsForService
republishForwardsForServiceWithResult
republishForwardsForNodeWithResult
republishForwardsForClientWithResult
republishAllForwardsWithResult
reconcileForwardEndpoints
publishTaskPayloadForForward through reconciliation
```

Assertions:

- Ordinary multi-Forward desired-state methods omit negative fixtures and still return the locked fixture.
- An explicit single-Forward operation throws before endpoint insert/update, provision-task insert, or payload construction.
- Bulk result wrappers count only successfully reconciled locked Forwards and include `Forward <id>: <stable error>` for rejected enabled rows.
- No negative fixture emits CF, WSS, QUIC, self-leg, native MPTCP, or `mptcp_preferred_bond_fallback` payload.
- Positive client candidates advertise exactly `runtime_capabilities=['bond']`, contain only selected ordinary Slave endpoint legs, and contain neither self-leg nor donated-self-leg candidates.
- The candidate pool and desired-node selection return five through eight distinct eligible Slave legs without silent truncation; a persisted cap from one through eight is respected, and no ninth leg passes an eight cap.
- Positive payload contains exact locked fields.

Create `leigod_only_speed_plus_preflight_test.php` as an executable fake-database test around one public test seam that calls the planned private preflight. Cover these exact candidate inputs and results:

```text
[]                                      -> invalid_speed_plus_leg_set / reason=no_usable_legs
[node 11]                               -> one canonical candidate; classification=single_leg_only
[node 11, node 12]                      -> two canonical candidates; classification=speed_plus_eligible
[node 11, duplicate node 11]            -> invalid_speed_plus_leg_set / reason=duplicate_node_id
[nodes 11..15, combination cap=4]       -> invalid_speed_plus_leg_set / reason=selection_exceeds_combination_cap
[nodes 11..19]                          -> invalid_speed_plus_leg_set / reason=too_many_legs
[node_id=0]                              -> invalid_speed_plus_leg_set / reason=invalid_node_id
[node 11 with empty resolved host]       -> invalid_speed_plus_leg_set / reason=unresolved_public_host
```

For every rejected case, assert there is no endpoint, provision-task, endpoint-status, or fingerprint write. The positive fixtures must also prove, before any write, that:

- the combination has a positive sidecar point and LeiGod line,
- the referenced sidecar point is active and resolves to a non-empty host plus positive bond port,
- `path_prefix` is a non-empty route key,
- target host/port form a valid TCP bond target,
- `bondTokenSecret()` resolves a non-empty secret without an implicit default,
- a TCP session token can be built and contains one dot separator,
- each selected node has exactly one primary LeiGod static sidecar uplink whose relay target is non-empty.

Use existing stable prerequisite errors (`bond_not_configured`, `bond_remote_target_required`, `bond_token_generation_failed`) where those helpers already define them. Add `bond_route_key_required` for an empty route key and `leigod_static_uplink_required` for a selected node without an attributable primary LeiGod uplink. Only cardinality, duplicate identity, invalid node identity, and unresolved selected-node host use `invalid_speed_plus_leg_set`.

Create `leigod_only_reconcile_atomicity_test.php` with an in-file transactional fake database that snapshots all in-memory tables on `transaction()`. Inject a failure after each of these stages:

```text
new endpoint insert
per-endpoint ingress HMAC generation
publish payload construction
publish task insertion
removed-leg destroy task insertion
removed endpoint deleting update
surviving endpoint fingerprint invalidation
```

For each injected failure, assert endpoint rows, provision tasks, status changes, collapsed pending-task state, and fingerprints exactly match the pre-call snapshot. Then run one success case and assert all endpoint/task/fingerprint changes commit together. The generated positive payload must have a non-empty `route_key`, a non-empty `ingress_auth_token`, and the locked active-family fields.

- [ ] **Step 2: Run the new boundary and preflight tests and verify legacy/topology rows leak today**

Run:

```bash
php master/tests/leigod_only_client_agent_protocol_test.php
php master/tests/leigod_only_node_agent_protocol_test.php
php master/tests/leigod_only_sidecar_agent_protocol_test.php
php master/tests/leigod_only_desired_state_guard_test.php
php master/tests/leigod_only_sidecar_desired_state_test.php
php master/tests/leigod_only_reconcile_publish_guard_test.php
php master/tests/leigod_only_speed_plus_preflight_test.php
php master/tests/leigod_only_reconcile_atomicity_test.php
php master/tests/provisioning_republish_wrappers_regression_test.php
php master/tests/provisioning_pick_fastest_slaves_for_combination_regression_test.php
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
```

Expected: FAIL because artifact/config and donation-ingest side channels are still active, enabled rows are selected without joining/validating their combinations, node/helper groups and donors can still inject ordinary candidates, duplicate/empty/oversized explicit selections are collapsed, skipped, or sliced rather than rejected, and endpoint/task mutations are not one transaction.

- [ ] **Step 3: Add query-level filtering and policy revalidation to client configs**

Change the initial query in `assignedForwardConfigsForClient()` to join combinations and require both persisted backend modes plus the locked runtime fields. Still call `assertForwardActiveContract()` for each fully loaded row before `buildClientConfig()`.

In `buildClientConfig()`, alias raw combination fields:

```sql
combo.id AS persisted_combination_id,
combo.backend_mode AS persisted_combination_backend_mode,
combo.client_mode AS persisted_combination_client_mode,
combo.runtime_preference AS persisted_combination_runtime_preference,
combo.bond_ingress_mode AS persisted_combination_bond_ingress_mode,
combo.cf_location_group_id AS persisted_combination_cf_location_group_id,
combo.sidecar_point_id AS persisted_combination_sidecar_point_id,
combo.leigod_line_id AS persisted_combination_leigod_line_id,
combo.allow_private_self_leg AS persisted_combination_allow_private_self_leg,
combo.allow_public_self_leg_donation AS persisted_combination_allow_public_self_leg_donation,
combo.self_leg_scope AS persisted_combination_self_leg_scope,
combo.self_leg_role AS persisted_combination_self_leg_role,
combo.self_leg_cf_transport AS persisted_combination_self_leg_cf_transport,
combo.self_leg_outer_transport AS persisted_combination_self_leg_outer_transport,
combo.helper_group_cap AS persisted_combination_helper_group_cap,
combo.is_active AS persisted_combination_is_active
```

Build a combination array from those raw aliases and call `assertPersistedForwardContract()` immediately after the not-found check, before token, endpoint, candidate, MPTCP, bond, or UDP payload construction. The Forward side of every such projection must be `f.*` or explicitly include `id`, `forward_combination_id`, `active_combination_id`, `backend_mode`, `client_mode`, `bond_ingress_mode`, `bond_sidecar_point_id`, and `cf_location_group_id`; omitting `active_combination_id` is not equivalent to a valid seeded primary.

Set active output constants directly:

```php
'backend_mode' => ForwardModePolicy::BACKEND_MODE,
'client_mode' => ForwardModePolicy::CLIENT_MODE,
'runtime_preference' => ForwardModePolicy::RUNTIME_PREFERENCE,
'bond_ingress_mode' => ForwardModePolicy::BOND_INGRESS_MODE,
'mptcp' => null,
```

Do not retain a runtime-family fallback branch in the active LeiGod output.

After persisted validation, resolve the trimmed `path_prefix` once as `routeKey` and call `bondTokenSecret($forward)` once as `tokenSecret`. Reject an empty route key with `bond_route_key_required`. Change the client builder signature to receive both values rather than rediscovering state:

```php
private function buildBondClientConfig(
    array $forward,
    array $endpoints,
    string $routeKey,
    string $tokenSecret,
): array
```

Use `routeKey` in the emitted bond config and use `tokenSecret` for its TCP session token. In the endpoint mapping closure, call the hardened `bondIngressAuthToken((int) $endpoint['id'], $routeKey, $tokenSecret)`. Resolve neither value again inside the closure or `buildBondClientConfig()`.

Treat the persisted `helper_group_cap` as the one active candidate limit. `assertCombinationContract()` has already proved it is within 1..8; do not normalize it again with `max(0, ...)`. Load the complete raw explicit selection through `selectedSlavesForCombination()` without passing a cap. Pass the validated cap to publication preflight and, only after raw row validation succeeds, to delivery-pool shaping. Remove mode-dependent `max_legs_used`, delete the old self-leg-preserving `applyMaxLegsCapToPool()` partition logic, and never slice candidate rows before preflight. Remove the active `buildSelfLegCandidate()` and donated `self_leg_donor` append path from `buildForwardCandidatePool()`. Every ordinary endpoint candidate emits exactly:

```php
'runtime_capabilities' => [ForwardModePolicy::CLIENT_MODE],
```

Replace `pickFastestSlavesForCombination(int $combinationId, int $cap)` with an explicit-selection reader whose return value is not sliced:

```php
/** @return array<int, array<string, mixed>> */
private function selectedSlavesForCombination(
    int $combinationId
): array
```

Its SQL reads only active `forward_combination_slaves` rows with `selection_kind=admin_pin`, `LEFT JOIN`s the referenced node so an orphan/invalid selection remains visible to validation, aliases `fcs.id AS selection_id`, `fcs.node_id AS selected_node_id`, and orders by `fcs.sort_order ASC, fcs.id ASC`. It does not inspect `forward_combinations.node_group_key`, `forward_combination_helper_groups`, node-group membership, or donor tables. It does not deduplicate, discard an invalid/duplicate node identity, filter by node health/freshness, or apply `helper_group_cap`; those are preflight decisions and must see the complete selected set. Keep the existing regression-test filename as a historical name, but make its executable behavior test `selectedSlavesForCombination()` rather than the deleted fastest-group selector.

Delete the group-scan fallback from `staticRentedSlavesForCombination()`. It returns only the explicit selected rows and never calls `forwardCombinationById()` or a fastest-group picker when no pins exist. `candidateNodesForCombination()` and reconciliation use the same explicit source. `helper_group_cap` is only the validated numeric ceiling for those selected rows; `node_group_key`, `helper_node_group_keys`, and `forward_combination_helper_groups` remain historical/admin metadata and never authorize active candidate sourcing.

After preflight validates the complete raw selected set, the pool is deterministic and contains only ordinary selected Slave endpoint legs. Add executable cases for persisted caps 1, 5, and 8 and for eight distinct eligible nodes; prove the fifth through eighth nodes reach both the client candidate pool and reconciliation input. A ninth selected row against cap eight is rejected before writes rather than silently excluded. For healthy explicit selections that are within cap, later delivery ranking may order the validated candidates by measured latency and stable node identity, but it cannot substitute an unselected node.

Update the three tests that directly invoke this private helper—`bond_latency_first_publish_regression_test.php`, `bond_leg_cooldown_publish_regression_test.php`, and `bond_leigod_leg_cooldown_publish_test.php`—to pass a non-empty route key and secret explicitly. Their positive fixture backend/runtime fields must be the locked contract; remove their CF-success comparison.

- [ ] **Step 4: Guard node and sidecar desired-state helpers**

For `udpUotSidepathConfigForNode()` and `udpUotSidepathConfigForSidecarPoint()`, join the referenced combination in the initial Forward query and filter exact locked fields. Revalidate each loaded Forward/combination pair before appending UDP compatibility state.

For `staticSlaveSidecarCombinations()` and its point/node variants, use Task 3's selectable policy filter. This preserves active LeiGod static uplinks and suppresses legacy CF/CNIX desired state without deleting the low-level legacy builders needed by retirement recognition.

Add this public read-only boundary to `ProvisioningService`:

```php
/** @return array<int, int> */
public function activeLeigodBondForwardIdsForSidecarPoint(int $pointId): array
```

It returns `[]` for a non-positive point ID. For a valid ID, query raw Forward plus combination columns with this complete positive predicate:

```sql
f.bond_sidecar_point_id = :point_id
f.enabled = 1
s.status = :service_status
f.forward_combination_id = combo.id
f.backend_mode = :backend_mode
combo.backend_mode = :backend_mode
f.client_mode = :client_mode
combo.client_mode = :client_mode
combo.runtime_preference = :runtime_preference
f.bond_ingress_mode = :bond_ingress_mode
combo.bond_ingress_mode = :bond_ingress_mode
combo.is_active = 1
combo.cf_location_group_id IS NULL
combo.allow_private_self_leg = 0
combo.allow_public_self_leg_donation = 0
combo.self_leg_scope = 'none'
combo.self_leg_role = 'none'
combo.self_leg_cf_transport = 'cf_direct'
combo.self_leg_outer_transport = 'leigod'
combo.sidecar_point_id = :point_id
combo.leigod_line_id > 0
combo.helper_group_cap BETWEEN 1 AND :maximum_selected_slaves
```

Alias enough raw columns to reconstruct both arrays, call `assertPersistedForwardContract()` on every returned pair, and append the positive Forward ID only after validation. Sort by Forward ID and return unique integers. Query filtering is only an optimization; policy revalidation is the correctness boundary. `SidecarAgentController::heartbeat()` deletes its direct `forwards` query and calls only this method for `bond_runtime.forward_ids`.

Delete active assembly of `$cfRows`, `$cfRuntime`, `$mptcpRuntimeRows`, `$mptcpManagement`, `$hasMptcpRuntime`, direct-CF backend IDs, CFWG routes, self-leg peers, and donation reverse-probe targets. Ordinary response output is bond-only:

```php
'listen_port' => $bondListenPort,
'mode' => ForwardModePolicy::CLIENT_MODE,
'quic_enabled' => false,
'bond_runtime' => $bondForwardIds === [] ? null : [
    'bind_host' => (string) ($point['bind_host'] ?? '0.0.0.0'),
    'listen_port' => $bondListenPort,
    'token_secret' => (string) ($point['token_secret'] ?? ''),
    'forward_ids' => $bondForwardIds,
    'total_speed_limit_mbps' => ($point['total_speed_limit_mbps'] ?? null) !== null
        ? (int) $point['total_speed_limit_mbps']
        : null,
],
```

Do not include `quic_listen`, `quic_server_name`, `hybrid_mode`, `mptcp_runtime`, `cf_runtime`, `self_leg_client_peers`, or `donation_reverse_probe_targets` keys. Do not call the old active runtime builders while composing this response. Keep low-level WireGuard heartbeat observability and LeiGod `sidecar_uplinks`; they are shared infrastructure and are not active CF/MPTCP Forward selection. A missing field never authorizes deletion. The retirement plan supplies explicit cleanup under a separate authenticated `runtime_retirements` field.

Delete `ProvisioningService::mptcpRuntimeRowsForSidecarPoint()` and its active-success test in this task. It is an ordinary runtime-enablement query, not an immutable historical migration or ownership proof. If the retirement plan needs legacy rows, it will add a purpose-built raw ownership-snapshot query that cannot be called by ordinary desired-state assembly.

- [ ] **Step 5: Remove independent legacy artifact, config, and donation protocol activation**

In `ClientAgentController::pullConfigs()`, delete both artifact lookups:

```php
$this->artifactMetadata('mptcp_client_runtime');
$this->artifactMetadata('cfwg_latency_watchdog');
```

Delete their four `desired_*` version/hash response fields. In `authoritativeAgentConfig()`, remove `cfwg_latency_watchdog` and `cfwg_edge_pins` from `$managedKeys`, remove the unconditional `mergeCfwgLatencyWatchdogDefaults()` call, and ensure stale stored values cannot re-enter the authoritative snapshot. Preserve bond artifact metadata and ordinary agent/runtime-refresh keys.

Do not remove passive client heartbeat collection of `supports_mptcp`, WireGuard identity/listen-port observations, or a reported `cfwg_watchdog_sha256`. These observations cannot install, select, publish, or configure a legacy runtime; they remain retirement inventory evidence only. No ordinary decision or desired-state method in this plan may read them to make a Forward active.

In `SidecarAgentController::heartbeat()`, delete the `mptcp_sidecar_runtime` artifact lookup and `desired_sidecar_runtime_version`/`desired_sidecar_runtime_sha256` output. Preserve the standalone bond artifact/update-token flow, including `desired_bond_version` and `desired_bond_sha256`.

In `NodeController::heartbeat()`, delete the entire `donation_self_check` branch. Do not call `recordDonationSelfCheck()` and do not create donor rows from any ordinary heartbeat body. The unrecognized request key is ignored; it is not a 500 and is not echoed as desired state.

In `SidecarAgentController::heartbeat()`, delete the `donation_reverse_probe` ingest branch and the desired target output. Do not call `recordDonationReverseProbe()` or `donationReverseProbeTargetsForPoint()` in the ordinary sidecar protocol.

In `ProvisioningService`, delete `donationPolicyForForward()` after removing `donation_policy` from ordinary Forward payload assembly. Delete `recordDonationSelfCheck()`, its private `findOrCreateSelfLegDonorFromSelfCheck()` helper, `recordDonationReverseProbe()`, and `donationReverseProbeTargetsForPoint()` only after repository-wide zero-call verification. Historical donor rows/tables and passive ownership columns remain; no generic row deletion occurs. Task 6 separately removes worker/UI/state-machine methods.

Run the three executable protocol replacements before deleting their old source-string success tests:

```bash
php master/tests/leigod_only_client_agent_protocol_test.php
php master/tests/leigod_only_node_agent_protocol_test.php
php master/tests/leigod_only_sidecar_agent_protocol_test.php
```

Expected: all print `ok`; artifact lookup logs and donation mutation logs satisfy the negative assertions.

- [ ] **Step 6: Add rollback-safe publication preflight for one-to-eight distinct Slaves**

Keep `ForwardModePolicy` database-free. Add the database-aware preflight to `ProvisioningService` with this exact private interface:

```php
/**
 * @param array<string, mixed> $forward
 * @param array<string, mixed> $combination
 * @param array<int, array<string, mixed>> $desiredNodes
 * @return array{
 *   candidates:array<int, array<string, mixed>>,
 *   classification:string,
 *   route_key:string,
 *   token_secret:string,
 *   sidecar_target:array{host:string,port:int}
 * }
 */
private function preflightLeigodBondPublication(
    array $forward,
    array $combination,
    array $desiredNodes,
): array
```

Its order is fixed:

1. Call `assertPersistedForwardContract($forward, $combination)`.
2. Revalidate positive `sidecar_point_id` and `leigod_line_id`, then load the raw `sidecar_points` row and require `is_active=1`.
3. Resolve the sidecar bond target with `resolveSidecarDirectTarget($forward, 'bond')`; require non-empty host and positive port.
4. Trim `path_prefix`; throw `bond_route_key_required` when empty.
5. Require non-empty `target_host` and positive `target_port` through the existing bond target/session-token helpers.
6. Resolve one non-empty token secret through `bondTokenSecret($forward)`. Remove the `bond-default` fallback from `bondIngressAuthToken()` and make it receive this validated secret explicitly.
7. Build one TCP bond session token as a prerequisite proof and require exactly one non-empty dot separator (`payload.signature`).
8. Inspect the complete raw active `admin_pin` rows before keying, deduplicating, health filtering, or slicing. Require raw count 1..`ForwardModePolicy::MAX_SELECTED_SLAVES`; each row must have positive `selection_id` and `selected_node_id`, a joined node whose `id` exactly matches `selected_node_id`, and no duplicate selected node ID. Also require the raw count to be at most `(int) $combination['helper_group_cap']`; exceeding the persisted valid selection cap throws `invalid_speed_plus_leg_set` with `reason=selection_exceeds_combination_cap`.
9. Apply the existing node active/degraded freshness eligibility only after raw identity/cardinality validation. Do not replace an ineligible selected node from any node/helper group. Resolve a non-empty public host and one primary LeiGod static sidecar uplink with non-empty `relay_target` for each remaining usable selected node. If no usable node remains, throw `invalid_speed_plus_leg_set` with `reason=no_usable_legs`.
10. Return usable candidates sorted deterministically by persisted selection order and then selected node ID, each with `resolved_public_host`; return `single_leg_only` for one usable candidate and `speed_plus_eligible` for two through eight.

The candidate-loop skeleton must preserve duplicate detection rather than silently collapsing the input:

```php
$count = count($desiredNodes);
$combinationCap = (int) ($combination['helper_group_cap'] ?? 0);
if ($count < 1 || $count > ForwardModePolicy::MAX_SELECTED_SLAVES) {
    throw new ApiException('invalid_speed_plus_leg_set', 422, [
        'reason' => $count < 1 ? 'no_usable_legs' : 'too_many_legs',
        'selected_count' => $count,
        'maximum' => ForwardModePolicy::MAX_SELECTED_SLAVES,
    ]);
}
if ($count > $combinationCap) {
    throw new ApiException('invalid_speed_plus_leg_set', 422, [
        'reason' => 'selection_exceeds_combination_cap',
        'selected_count' => $count,
        'combination_cap' => $combinationCap,
    ]);
}

$seenNodeIds = [];
$candidates = [];
foreach ($desiredNodes as $selectionOrder => $node) {
    $selectionId = (int) ($node['selection_id'] ?? 0);
    $selectedNodeId = (int) ($node['selected_node_id'] ?? 0);
    $joinedNodeId = (int) ($node['id'] ?? 0);
    if ($selectionId <= 0 || $selectedNodeId <= 0
        || $joinedNodeId !== $selectedNodeId) {
        throw new ApiException('invalid_speed_plus_leg_set', 422, [
            'reason' => 'invalid_node_id',
            'selection_order' => $selectionOrder,
            'selection_id' => $selectionId,
            'selected_node_id' => $selectedNodeId,
            'joined_node_id' => $joinedNodeId,
        ]);
    }
    if (isset($seenNodeIds[$selectedNodeId])) {
        throw new ApiException('invalid_speed_plus_leg_set', 422, [
            'reason' => 'duplicate_node_id',
            'node_id' => $selectedNodeId,
        ]);
    }
    $seenNodeIds[$selectedNodeId] = true;
}

foreach ($desiredNodes as $selectionOrder => $node) {
    if (!$this->isNodeEligibleForForwardSelection($node)) {
        continue;
    }
    $nodeId = (int) $node['selected_node_id'];
    $publicHost = $this->resolveNodePublicHost($node);
    if ($publicHost === '') {
        throw new ApiException('invalid_speed_plus_leg_set', 422, [
            'reason' => 'unresolved_public_host',
            'node_id' => $nodeId,
        ]);
    }
    $node['resolved_public_host'] = $publicHost;
    $node['selection_order'] = $selectionOrder;
    $candidates[] = $node;
}
if ($candidates === []) {
    throw new ApiException('invalid_speed_plus_leg_set', 422, [
        'reason' => 'no_usable_legs',
    ]);
}
```

Define the eligibility helper used above with no database writes and no new threshold:

```php
/** @param array<string, mixed> $node */
private function isNodeEligibleForForwardSelection(array $node): bool
{
    $status = strtolower(trim((string) ($node['status'] ?? '')));
    if (!in_array($status, ['active', 'degraded'], true)) {
        return false;
    }

    $maxAgeSeconds = $this->config->getInt(
        'NODE_SELECTION_MAX_HEARTBEAT_AGE_SECONDS',
        1800
    );
    if ($maxAgeSeconds <= 0) {
        return true;
    }

    $lastHeartbeatAt = trim((string) (
        $node['last_heartbeat_at'] ?? ''
    ));
    if ($lastHeartbeatAt === '') {
        return false;
    }
    $timestamp = strtotime($lastHeartbeatAt . ' UTC');
    return $timestamp !== false
        && $timestamp >= time() - $maxAgeSeconds;
}
```

This mirrors `nodeSelectionFreshnessSql()` exactly. Use it only after raw selection identity, duplicate, and cap validation. `candidateNodesForCombination()` may remain an admin-side discovery list that helps an operator find nodes to pin, but its group/helper results are never active input. A node becomes an ordinary runtime candidate only after the aggregate `saveForwardCombination()` transaction writes its explicit active `admin_pin` row.

This task owns topology and publication-prerequisite validation. The later bond data-plane plan owns scheduler internals and the `leigod_bond_v1` fields, not the one-to-eight gate.

“Preflight before writes” means no external publication side effect and no committed partial state. A new Forward may be inserted inside the caller-owned transaction to obtain the Forward ID needed by token/HMAC context. If preflight or reconciliation then fails, that Forward insert and every audit/endpoint/task/fingerprint change must roll back to the exact pre-call snapshot. Existing Forwards should still perform all prerequisite reads before membership mutation whenever possible.

- [ ] **Step 7: Harden the transaction-free locked reconciliation helper**

Task 4 already split `reconcileForwardEndpoints()` into a transaction-owning wrapper and `reconcileForwardEndpointsLocked()`. Do not add a transaction inside the locked helper. Public create, structural update, non-structural update, and reactivation call the locked helper from their existing outer transaction; standalone reconcile and ordinary republish wrappers call the transaction-owning wrapper.

Replace the body of `reconcileForwardEndpointsLocked()` with this fixed order:

1. Require a positive Forward ID. Reload and lock the raw Forward with `SELECT ... FOR UPDATE` on MySQL and the same `SELECT` without the unsupported lock suffix in SQLite tests.
2. Load the raw referenced combination; call `assertPersistedForwardContract()` before candidate, endpoint, task, HMAC, or fingerprint work.
3. Revalidate `enabled=1` and active service state. Disabled/inactive rows return without publication and never authorize cleanup.
4. Select raw candidate rows without associative-key deduplication and call `preflightLeigodBondPublication()`.
5. Compute a deterministic selected-node identity from the ordered preflight node IDs. Because selection and mutation are in the same outer transaction and the Forward is locked, a concurrent caller cannot commit a different membership first. If a caller supplied a pre-transaction candidate fingerprint, compare and abort on drift before writes.
6. Load current endpoints and validate every `forceEndpointIds` entry: it must be a positive actual `service_endpoints.id` belonging to this Forward and still present in the current membership. Reject foreign, missing, duplicate, node-ID, and Forward-ID values with `invalid_endpoint_republish_identity` before task/fingerprint mutation.
7. For each new endpoint, insert it, derive ingress HMAC from the inserted endpoint ID plus the validated route key/secret, build/validate the full payload, queue publish, and store desired fingerprint.
8. For each retained endpoint, queue publish only when its desired fingerprint changed or its actual endpoint ID is in `forceEndpointIds`. Build/validate payload before task collapse/insertion, then write task and fingerprint together.
9. For each removed endpoint, queue destroy, mark deleting, and invalidate its fingerprint.
10. Let every exception escape. The one outer owner rolls endpoint inserts, status changes, pending-task collapse/insertion, audits, and fingerprints back together.

Delete both direct endpoint/task loops from `saveForwardLocked()` and replace them with exactly one `reconcileForwardEndpointsLocked($forwardId)` call after the Forward row and audit state have been written. This is the point at which initial create/update stops bypassing preflight.

Do not publish runtime desired state outside the transaction: `queueTask()` performs durable database collapse/insert only and is safe within the outer owner. Do not hand-roll begin/commit calls.

`reconcileForwardEndpointsLocked()` validates the raw Forward/combination while holding the lock. At the first line of `publishTaskPayloadForForward()`, retain a defense-in-depth `assertForwardActiveContract($forward)` call. Accept the validated preflight route key and token secret as explicit parameters so payload construction cannot rediscover weaker fallbacks:

```php
private function publishTaskPayloadForForward(
    array $forward,
    array $node,
    int $endpointId,
    string $routeKey,
    string $tokenSecret,
): array
```

Use constants:

```php
$clientMode = ForwardModePolicy::CLIENT_MODE;
$bondIngressMode = ForwardModePolicy::BOND_INGRESS_MODE;
$runtimePreference = ForwardModePolicy::RUNTIME_PREFERENCE;
$backendMode = ForwardModePolicy::BACKEND_MODE;
$publishesBondRuntime = true;
$plainTcpIngress = true;
```

The returned active payload must set and validate:

```php
'backend_mode' => ForwardModePolicy::BACKEND_MODE,
'publish_bond_runtime' => true,
'cf_mode' => null,
'route_key' => $routeKey,
'ingress_protocol' => ForwardModePolicy::BOND_INGRESS_MODE,
'ingress_auth_token' => $this->bondIngressAuthToken(
    $endpointId,
    $routeKey,
    $tokenSecret,
),
'mptcp_underlay' => null,
```

Refactor the helper to remove all implicit secret lookup:

```php
private function bondIngressAuthToken(
    int $endpointId,
    string $routeKey,
    string $tokenSecret,
): string {
    if ($endpointId <= 0 || $routeKey === '' || $tokenSecret === '') {
        throw new ApiException('bond_ingress_auth_invalid', 422, [
            'endpoint_id' => $endpointId,
            'route_key_present' => $routeKey !== '',
            'token_secret_present' => $tokenSecret !== '',
        ]);
    }
    return hash_hmac(
        'sha256',
        'bond-ingress:' . $endpointId . ':' . $routeKey,
        $tokenSecret
    );
}
```

There are exactly three current `bondIngressAuthToken()` call expressions to remove or convert:

1. `buildClientConfig()` endpoint mapping: convert to actual endpoint ID plus the one resolved route key/secret.
2. `buildCfModePayload()`: remove the call by making this builder unreachable from every active path; do not repair or retain it as supported publication.
3. `publishTaskPayloadForForward()`: convert to actual endpoint ID plus preflight route key/secret.

No active call may resolve an implicit secret, pass the whole Forward to the HMAC helper, or substitute node ID for endpoint ID.

- [ ] **Step 8: Route all seven publication call expressions through reconciliation**

Remove direct `publishTaskPayloadForForward()` calls from these exact seven sites:

1. `saveForwardLocked()` specific-user-node branch.
2. `saveForwardLocked()` general selected-node loop.
3. `sweepNonConvergentEndpoints()`.
4. `republishActiveEndpointsForNode()`.
5. `republishActiveEndpoint()`.
6. `reconcileForwardEndpointsLocked()` new-endpoint branch.
7. `reconcileForwardEndpointsLocked()` retained-endpoint branch.

After the refactor, only sites 6 and 7 remain as call expressions, and both pass the actual endpoint ID plus the same preflight route key/secret. Sites 1 and 2 are deleted. Sites 3 through 5 group work by Forward and invoke shared reconciliation:

```php
$this->reconcileForwardEndpoints(
    $forwardId,
    $actualEndpointIds
);
```

`sweepNonConvergentEndpoints()` collects stale actual endpoint IDs per Forward, then calls once per Forward. `republishActiveEndpointsForNode()` groups actual endpoint IDs by their Forward. `republishActiveEndpoint()` passes exactly the requested actual endpoint ID and returns `true` only when reconciliation accepts and queues that endpoint. Never pass a node ID or Forward ID in the force set. Preserve `provisioning_node_republish_endpoint_id_regression_test.php`'s invariant that endpoint 173—not Forward 23 and not a node ID—is queued.

Convert `provisioning_republish_wrappers_regression_test.php` from source-string assertions to an executable fake-database test. Prove drift, node-scoped, and endpoint-scoped republish all traverse preflight, reject legacy/mismatched rows with zero writes, force only the requested actual endpoint IDs, and update task plus fingerprint atomically.

For every other republish/reconcile method listed in Step 1:

1. Add query-level joins/filters where it enumerates candidate Forwards.
2. Call `assertForwardActiveContract()` immediately after loading each row.
3. For scoped single-Forward calls, let the stable exception propagate.
4. For existing `WithResult` bulk calls, catch per Forward, append `Forward <id>: <error>`, and continue.
5. Count only successful calls to `reconcileForwardEndpoints()`.

Do not copy a simplified backend-only predicate into each method. Query filtering is an optimization; `ForwardModePolicy` remains the correctness boundary.

- [ ] **Step 9: Update signature-sensitive executable tests**

Convert these fixtures to the locked Forward/combination contract and pass explicit endpoint ID, route key, and token secret where their private-helper seam now requires them:

```text
provisioning_selector_port_collision_free_regression_test.php
provisioning_static_sidecar_selector_port_regression_test.php
forward_combination_static_sidecar_wg_regression_test.php
bond_latency_first_publish_regression_test.php
bond_leg_cooldown_publish_regression_test.php
bond_leigod_leg_cooldown_publish_test.php
```

For `forward_combination_static_sidecar_wg_regression_test.php`, retain only the LeiGod static-uplink assertion relevant to the active path; move CF/current/MPTCP success expectations to negative policy/unreachability coverage. Do not keep a fixture publishable merely to satisfy a private signature.

Update `provisioning_forward_non_structural_update_test.php` to use a snapshotting fake transaction, a locked Forward plus locked combination, active sidecar point, usable LeiGod static uplink, route key, and token secret. Assert client config is built after commit. Inject failure during reconciliation and prove the Forward row, audit, endpoint, task, lifecycle, and fingerprint snapshots all roll back.

Update `provisioning_active_endpoint_drift_republish_regression_test.php` with the same locked prerequisites and transaction snapshot support. Preserve its behavioral purpose: changed desired state forces the actual stale endpoint through shared reconciliation.

- [ ] **Step 10: Run desired-state, publish, UDP, preflight, atomicity, and republish tests**

Run:

```bash
php master/tests/leigod_only_client_agent_protocol_test.php
php master/tests/leigod_only_node_agent_protocol_test.php
php master/tests/leigod_only_sidecar_agent_protocol_test.php
php master/tests/leigod_only_desired_state_guard_test.php
php master/tests/leigod_only_sidecar_desired_state_test.php
php master/tests/leigod_only_reconcile_publish_guard_test.php
php master/tests/leigod_only_speed_plus_preflight_test.php
php master/tests/leigod_only_reconcile_atomicity_test.php
php master/tests/agent_udp_uot_sidepath_management_test.php
php master/tests/provisioning_pick_fastest_slaves_for_combination_regression_test.php
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
php master/tests/provisioning_republish_wrappers_regression_test.php
php master/tests/provisioning_active_endpoint_drift_republish_regression_test.php
php master/tests/provisioning_node_republish_endpoint_id_regression_test.php
php master/tests/provisioning_selector_port_collision_free_regression_test.php
php master/tests/provisioning_static_sidecar_selector_port_regression_test.php
php master/tests/forward_combination_static_sidecar_wg_regression_test.php
php master/tests/bond_latency_first_publish_regression_test.php
php master/tests/bond_leg_cooldown_publish_regression_test.php
php master/tests/bond_leigod_leg_cooldown_publish_test.php
php master/tests/provisioning_forward_non_structural_update_test.php
php master/tests/provisioning_sidecar_republish_test.php
```

Expected: all print `ok`.

- [ ] **Step 11: Commit fail-closed atomic publication boundaries**

```bash
git add master/src/Support/ProvisioningService.php master/src/Controllers/ClientAgentController.php master/src/Controllers/NodeController.php master/src/Controllers/SidecarAgentController.php master/tests/leigod_only_client_agent_protocol_test.php master/tests/leigod_only_node_agent_protocol_test.php master/tests/leigod_only_sidecar_agent_protocol_test.php master/tests/leigod_only_desired_state_guard_test.php master/tests/leigod_only_sidecar_desired_state_test.php master/tests/leigod_only_reconcile_publish_guard_test.php master/tests/leigod_only_speed_plus_preflight_test.php master/tests/leigod_only_reconcile_atomicity_test.php master/tests/agent_udp_uot_sidepath_management_test.php master/tests/provisioning_pick_fastest_slaves_for_combination_regression_test.php master/tests/provisioning_forward_combination_helper_groups_regression_test.php master/tests/provisioning_republish_wrappers_regression_test.php master/tests/provisioning_active_endpoint_drift_republish_regression_test.php master/tests/provisioning_node_republish_endpoint_id_regression_test.php master/tests/provisioning_selector_port_collision_free_regression_test.php master/tests/provisioning_static_sidecar_selector_port_regression_test.php master/tests/forward_combination_static_sidecar_wg_regression_test.php master/tests/bond_latency_first_publish_regression_test.php master/tests/bond_leg_cooldown_publish_regression_test.php master/tests/bond_leigod_leg_cooldown_publish_test.php master/tests/provisioning_forward_non_structural_update_test.php master/tests/provisioning_sidecar_republish_test.php
git rm master/tests/provisioning_donation_policy_payload_regression_test.php master/tests/client_cfwg_latency_watchdog_default_all_active_regression_test.php master/tests/node_heartbeat_donation_self_check_ingest_regression_test.php master/tests/sidecar_donation_reverse_probe_payload_regression_test.php master/tests/sidecar_mptcp_combination_runtime_regression_test.php master/tests/sidecar_cf_runtime_target_regression_test.php master/tests/sidecar_cf_direct_backend_management_contract_test.php master/tests/sidecar_cf_mode_loopback_target_regression_test.php master/tests/sidecar_cfwg_route_publishes_wg_listen_port_regression_test.php
git commit -m "feat(master): block legacy desired state and publication" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Remove ordinary CF/donation controls and make activation code unreachable

**Files:**
- Modify: `master/src/Controllers/BaseController.php` at `adminNavigationItems`
- Modify: `master/src/Controllers/AdminController.php` at `cfLocationGroupsPage`, `saveCfLocationGroup`, `forwardCombinationsPage`, `saveForwardCombination`, `forwardRoutesPage`, and `revokeDonation`
- Modify: `master/src/Bootstrap.php` at the CF page/write and donation-revoke registrations
- Modify: `master/src/Support/ProvisioningService.php` at CF location-group access, combination persistence, donor listing/revoke, and donation evaluation/state transition
- Delete: `master/scripts/evaluate_donations.php`
- Delete: `deploy/systemd/leikwan-donation-worker.service`
- Delete: `deploy/systemd/leikwan-donation-worker.timer`
- Modify: `master/tests/admin_forward_combination_page_test.php`
- Modify: `master/tests/admin_forward_combination_self_leg_policy_test.php`
- Modify: `master/tests/admin_forward_combination_leigod_field_test.php`
- Modify: `master/tests/managed_tyo_transport_label_test.php`
- Modify: `master/tests/admin_nav_consistency_regression_test.php`
- Modify: `master/tests/admin_cf_location_observability_test.php`
- Modify: `master/tests/admin_combination_multi_slave_picker_regression_test.php`
- Modify: `master/tests/forward_combination_donation_columns_persistence_regression_test.php`
- Modify: `master/tests/provisioning_forward_combination_regression_test.php`
- Delete: `master/tests/donation_worker_qualify_to_active_regression_test.php`
- Delete: `master/tests/donation_worker_revoke_paths_regression_test.php`
- Create: `master/tests/admin_cf_location_group_deprecation_test.php`
- Create: `master/tests/leigod_only_donation_activation_unreachable_test.php`

- [ ] **Step 1: Invert all ordinary Admin surface contracts**

Update `admin_forward_combination_page_test.php`, `admin_forward_combination_self_leg_policy_test.php`, `admin_forward_combination_leigod_field_test.php`, `managed_tyo_transport_label_test.php`, `admin_nav_consistency_regression_test.php`, `admin_cf_location_observability_test.php`, and `admin_combination_multi_slave_picker_regression_test.php` as one explicit surface matrix.

The tests must forbid these controls, labels, links, and ordinary observability tokens in `AdminController`, `BaseController`, and the registered GET routes:

```text
/admin/cf-location-groups
CF Location Groups
Cloudflare Location Groups
Current Best CF IP
Live donations
name="backend_mode"
name="cf_location_group_id"
name="client_mode"
name="runtime_preference"
name="bond_ingress_mode"
name="allow_private_self_leg"
name="allow_public_self_leg_donation"
name="self_leg_scope"
name="self_leg_role"
name="self_leg_cf_transport"
name="donation_qualify_window_seconds"
name="donation_revoke_window_seconds"
name="donation_min_open_ports"
value="current_mode"
value="cf_mode"
value="mptcp"
value="mptcp_preferred_bond_fallback"
cf_location_stats
```

The same tests must retain these exact ordinary controls and shared-navigation contracts:

```text
/admin/forward-combinations
/admin/user-groups
/admin-transport-endpoints
Forward Combinations
User Groups
<select name="combination_slaves[]" multiple
name="sidecar_point_id"
name="leigod_line_id"
name="leigod_uot_impl"
name="name"
name="tenant_label"
name="is_active"
name="combination_slaves[]"
forward_combination_slaves
selection_kind = admin_pin
saveForwardCombinationLocked(array $payload): int
```

`admin_cf_location_observability_test.php` must read Admin and Tenant sources separately. It forbids active CF observability only in ordinary Admin/Tenant presentation. It explicitly keeps `forward_combination_name` as a generic label and permits low-level Slave telemetry such as `cf_location_stats`, `last_latency_ms`, and `last_jitter_ms` to remain in `slave-agent/slave_agent.py` as passive historical inventory. It must not require or modify that Python file.

`managed_tyo_transport_label_test.php` keeps the repository's neutral visible name for `leigod_mode`, but removes the comparison text `like current_mode, no Cloudflare`. It must not reintroduce provider credentials or make `lguot` mandatory; the visible carrier selector continues to offer the supported `gost|lguot` choices.

- [ ] **Step 2: Write the failing HTTP tombstone and donation-unreachability tests**

Create `admin_cf_location_group_deprecation_test.php` with two authenticated-controller cases and one route-registration check:

```text
unauthenticated POST -> redirect to /login
admin POST with invalid CSRF -> existing CSRF rejection
admin POST with valid CSRF -> HTTP 410 JSON error=deprecated_cf_location_group_feature
```

The valid tombstone case injects a provisioning spy and a database write log. Assert no `saveCfLocationGroup()` service call and no write. Assert `Bootstrap` retains only:

```php
$this->router->add(
    'POST',
    '/admin/cf-location-groups',
    [$admin, 'saveCfLocationGroup']
);
```

and has no GET registration for that path.

Create `leigod_only_donation_activation_unreachable_test.php`. It must execute a new and an edit `saveForwardCombination()` case with `allow_public_self_leg_donation=1`, assert `unsupported_leigod_runtime_contract`, `reason=request_contract_conflict`, and an empty write log. Then perform bounded repository assertions that all of these active declarations/call expressions are absent after implementation:

```text
ProvisioningService::activeDonorsForCombination
ProvisioningService::revokeDonationManually
ProvisioningService::evaluateDonationStateForCombination
ProvisioningService::evaluateDonationRow
ProvisioningService::donorSessionHealthy
ProvisioningService::decodedOpenPorts
ProvisioningService::lastReverseProbeOk
ProvisioningService::transitionDonationState
ProvisioningService::DONATION_GATE_RATIO
AdminController::revokeDonation
/admin/forward-combinations/{id}/donations/{donationId}/revoke
scripts/evaluate_donations.php
leikwan-donation-worker.service
leikwan-donation-worker.timer
```

The same test must prove these historical artifacts remain:

```text
schema.sql contains allow_public_self_leg_donation
schema.sql contains forward_combination_self_leg_donors
20260518 donation/donor migrations still exist
forward_combination_self_leg_donors_table_schema_regression_test.php still exists
forward_combination_donation_columns_schema_regression_test.php still exists
```

Finally, assert `ProvisioningService::nullableFloat()` still exists because ordinary candidate and measured-delivery logic shares it. Do not use this source-boundary test as a substitute for Task 1's behavioral policy test or Task 5's executable heartbeat tests.

- [ ] **Step 3: Rewrite the donation persistence test around immutable history**

Convert `forward_combination_donation_columns_persistence_regression_test.php` from a source-string success test into an executable fake-database test with these cases:

1. A raw historical combination with `allow_public_self_leg_donation=1` and non-default timing/measurement values is loaded for edit with all donation fields omitted. The save fails before writes and every seeded column remains byte-for-byte unchanged.
2. A locked combination with `allow_public_self_leg_donation=0` is edited with donation timing fields omitted. The ordinary update does not include any donation timing/state column assignment and leaves existing inert values unchanged.
3. A new locked combination omitting donation fields stores `allow_public_self_leg_donation=0`; database defaults supply timing columns.
4. An explicit active-donation request fails before writes. No omitted or explicit field path converts historical `1` to `0`.

Update `provisioning_forward_combination_regression_test.php` so it continues to assert the historical CF schemas/tables and ordinary plan/combination linkage, but forbids `ProvisioningService::saveCfLocationGroup()` and removes expectations for a `current_mode` default or successful `cf_mode` payload. The active save assertion names `ForwardModePolicy` and the locked constants instead.

- [ ] **Step 4: Run the new tests and verify the old activation chain still exists**

Run:

```bash
php master/tests/admin_forward_combination_page_test.php
php master/tests/admin_nav_consistency_regression_test.php
php master/tests/admin_cf_location_observability_test.php
php master/tests/admin_combination_multi_slave_picker_regression_test.php
php master/tests/admin_cf_location_group_deprecation_test.php
php master/tests/forward_combination_donation_columns_persistence_regression_test.php
php master/tests/leigod_only_donation_activation_unreachable_test.php
```

Expected: FAIL because ordinary CF/donation UI, service methods, worker sources, and donation state transitions still exist.

- [ ] **Step 5: Remove ordinary CF navigation, pages, observability, and selectors**

In `BaseController::adminNavigationItems()`, delete only the `/admin/cf-location-groups` item. Keep every shared non-CF item and keep `AuthController` consuming the shared helper.

In `Bootstrap`, remove:

```php
GET /admin/cf-location-groups
POST /admin/forward-combinations/{id}/donations/{donationId}/revoke
```

Retain the authenticated POST `/admin/cf-location-groups` tombstone so stale clients receive a stable 410 instead of a route-dependent 404.

Delete `AdminController::cfLocationGroupsPage()` and `AdminController::revokeDonation()`. Replace `saveCfLocationGroup()` with the complete non-writing tombstone:

```php
public function saveCfLocationGroup(
    Request $request,
    array $params
): Response {
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::redirect('/login');
    }
    SessionAuth::validateCsrf($request);

    return $this->json([
        'ok' => false,
        'error' => 'deprecated_cf_location_group_feature',
    ], 410);
}
```

In `forwardCombinationsPage()`:

- use `ProvisioningService::listForwardCombinations()` as the only ordinary list,
- reject rather than render an ineligible `?edit=<id>`,
- stop loading CF groups, donor rows, and `cf_location_stats`,
- remove every forbidden control from Step 1 without hidden replacements,
- retain sidecar point, LeiGod line, `gost|lguot` carrier, labels, ordering, notes, active state, and the distinct multi-Slave picker,
- label one selected Slave as ordinary LeiGod and two through eight as Speed Plus eligible without promising aggregation until later capability gates pass.

`AdminController::saveForwardCombination()` keeps the single aggregate call introduced in Task 3:

```php
$this->provisioning?->saveForwardCombination(
    is_array($request->body()) ? $request->body() : []
);
```

The service transaction validates the combination fields and complete `combination_slaves` list before mutation, persists both, and reconciles linked Forwards atomically. Do not reintroduce a second pin-save call or add mode values as hidden inputs.

In `forwardRoutesPage()`, replace direct active-combination SQL with `ProvisioningService::listForwardCombinations()`. Historical route names may be rendered as inert context, but an ineligible combination never appears as a selectable checkbox and cannot be resaved.

- [ ] **Step 6: Delete service-level CF writes and donation state transitions**

After the controller changes, perform repository-wide zero-call checks and delete these unused public CF methods from `ProvisioningService`:

```text
listCfLocationGroups
saveCfLocationGroup
```

Do not delete or change `cf_location_groups`, `cf_location_group_cidrs`, old migrations, raw Forward columns, or low-level passive `cf_location_stats` telemetry. The retirement plan will add a purpose-built operations-only read model instead of reusing an ordinary editable method.

Task 5 has already removed donation heartbeat ingestion, desired donation policy, reverse-probe targets, and donated candidates. Now delete the remaining ordinary donation state machine:

```text
activeDonorsForCombination
revokeDonationManually
evaluateDonationStateForCombination
evaluateDonationRow
donorSessionHealthy
decodedOpenPorts
lastReverseProbeOk
transitionDonationState
DONATION_GATE_RATIO
```

Remove donation timing/measurement fields from ordinary combination request normalization and update assignments. New rows explicitly store `allow_public_self_leg_donation=0` and rely on existing database defaults for inert timing columns; edits of locked rows do not rewrite those columns. Existing rows with value `1` fail raw persisted validation before any update. Keep the donor table, all historical rows, ownership columns, state timestamps, and audit evidence unchanged. Keep `nullableFloat()` and all non-donation callers of `republishActiveForwardsForCombination()`.

Run:

```bash
rg -n "activeDonorsForCombination|revokeDonationManually|evaluateDonationStateForCombination|evaluateDonationRow|donorSessionHealthy|decodedOpenPorts|lastReverseProbeOk|transitionDonationState|DONATION_GATE_RATIO|saveCfLocationGroup\(|listCfLocationGroups\(" master/src master/scripts deploy/systemd
```

Expected: the only `saveCfLocationGroup` result is the Controller tombstone declaration/Bootstrap route; every other named activation symbol has zero results.

- [ ] **Step 7: Remove obsolete worker source definitions only**

Only after Steps 5 and 6 pass, delete the repository files:

```bash
git rm master/scripts/evaluate_donations.php
git rm deploy/systemd/leikwan-donation-worker.service
git rm deploy/systemd/leikwan-donation-worker.timer
```

This is source removal, not an operational rollout. Do **not** run `systemctl`, do not stop or disable an installed timer/service, do not delete `/etc/systemd/system/*`, do not SSH to any host, and do not alter production. Already installed production donation-worker units are outside this changeset and require separate explicit authorization in the activation/rollout plan.

Delete `donation_worker_qualify_to_active_regression_test.php` and `donation_worker_revoke_paths_regression_test.php` only after `leigod_only_donation_activation_unreachable_test.php` passes. Their old success contracts—qualifying a donor, transitioning it active/cooldown/revoked, manual revoke, and donation-triggered republish—must not be copied into any replacement.

- [ ] **Step 8: Run the complete UI and donation-removal gate**

Run:

```bash
php master/tests/admin_forward_combination_page_test.php
php master/tests/admin_forward_combination_self_leg_policy_test.php
php master/tests/admin_forward_combination_leigod_field_test.php
php master/tests/managed_tyo_transport_label_test.php
php master/tests/admin_nav_consistency_regression_test.php
php master/tests/admin_cf_location_observability_test.php
php master/tests/admin_combination_multi_slave_picker_regression_test.php
php master/tests/admin_cf_location_group_deprecation_test.php
php master/tests/forward_combination_donation_columns_persistence_regression_test.php
php master/tests/forward_combination_self_leg_donors_table_schema_regression_test.php
php master/tests/forward_combination_donation_columns_schema_regression_test.php
php master/tests/provisioning_forward_combination_regression_test.php
php master/tests/leigod_only_donation_activation_unreachable_test.php
php master/tests/leigod_only_node_agent_protocol_test.php
php master/tests/leigod_only_sidecar_agent_protocol_test.php
php master/tests/saveforwardroute_ingress_guard_test.php
```

Expected: all print `ok` (or the existing uppercase `OK`) and exit 0. Historical schema tests pass unchanged; multi-Slave persistence remains active; no test requires donation activation.

- [ ] **Step 9: Commit ordinary activation-surface removal with exact paths**

```bash
git add master/src/Controllers/BaseController.php master/src/Controllers/AdminController.php master/src/Bootstrap.php master/src/Support/ProvisioningService.php master/tests/admin_forward_combination_page_test.php master/tests/admin_forward_combination_self_leg_policy_test.php master/tests/admin_forward_combination_leigod_field_test.php master/tests/managed_tyo_transport_label_test.php master/tests/admin_nav_consistency_regression_test.php master/tests/admin_cf_location_observability_test.php master/tests/admin_combination_multi_slave_picker_regression_test.php master/tests/forward_combination_donation_columns_persistence_regression_test.php master/tests/provisioning_forward_combination_regression_test.php master/tests/admin_cf_location_group_deprecation_test.php master/tests/leigod_only_donation_activation_unreachable_test.php
git rm master/scripts/evaluate_donations.php deploy/systemd/leikwan-donation-worker.service deploy/systemd/leikwan-donation-worker.timer master/tests/donation_worker_qualify_to_active_regression_test.php master/tests/donation_worker_revoke_paths_regression_test.php
git commit -m "feat(master): remove legacy control-plane activation" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

Do not stage any production unit path outside the three repository source definitions, and do not stage the unrelated Bridge Lite plan.

---

### Task 7: Migrate remaining test fixtures and run the control-plane gate

This task is test-only. Tasks 1–6 own every product-code and migration change. Do not modify `master/src`, `master/migrations`, agent artifacts, Rust, Python, systemd, or deployment files in this task.

**Files — convert to the locked ordinary fixture:**
- Modify: `master/tests/forward_combination_save_update_persistence_regression_test.php`
- Modify: `master/tests/forward_combination_self_leg_cf_transport_persistence_regression_test.php`
- Modify: `master/tests/node_heartbeat_republish_gate_regression_test.php`
- Modify: `master/tests/provisioning_idempotent_publish_requeue_regression_test.php`
- Modify: `master/tests/provisioning_leigod_mode_direct_ingress_test.php`
- Modify: `master/tests/provisioning_leigod_uot_impl_test.php`
- Modify: `master/tests/provisioning_non_convergence_sweep_alarm_regression_test.php`
- Modify: `master/tests/provisioning_selector_teardown_on_membership_change_regression_test.php`

**Files — preserve byte-for-byte as historical, schema, passive-visibility, or text evidence:**
- Preserve: `master/tests/forward_combination_self_leg_schema_regression_test.php`
- Preserve: `master/tests/forward_combination_slaves_table_schema_regression_test.php`
- Preserve: `master/tests/i18n_locale_regression_test.php`
- Preserve: `master/tests/knowledgebase_reader_regression_test.php`
- Preserve: `master/tests/tenant_client_page_simplification_regression_test.php`
- Preserve: `master/tests/tenant_forward_combination_self_leg_visibility_test.php`
- Preserve: `master/tests/forward_combination_self_leg_donors_table_schema_regression_test.php`
- Preserve: `master/tests/forward_combination_donation_columns_schema_regression_test.php`
- Preserve: `master/tests/leigod_mode_true_mptcp_migration_test.php`
- Preserve: `master/tests/leigod_mode_migration_shape_test.php`

**Files — delete after their named fail-closed replacements pass:**
- Delete: `master/tests/bond_quic_capability_regression_test.php`
- Delete: `master/tests/provisioning_active_endpoint_cfwg_transport_regression_test.php`
- Delete: `master/tests/provisioning_bond_quic_gate_regression_test.php`
- Delete: `master/tests/provisioning_cf_mode_bond_token_target_regression_test.php`
- Delete: `master/tests/provisioning_cf_mode_upstream_identity_regression_test.php`
- Delete: `master/tests/provisioning_leigod_no_preconnect_test.php`
- Delete: `master/tests/provisioning_leigod_overlay_mtu_test.php`
- Delete: `master/tests/provisioning_leigod_overlay_routing_test.php`
- Delete: `master/tests/provisioning_leigod_self_leg_transport_regression_test.php`
- Delete: `master/tests/provisioning_leigod_slave_preconnect_reuse_test.php`
- Delete: `master/tests/provisioning_mptcp_local_uot_port_regression_test.php`
- Delete: `master/tests/provisioning_mptcp_preferred_bond_payload_regression_test.php`
- Delete: `master/tests/provisioning_mptcp_preferred_bond_slave_payload_regression_test.php`
- Delete: `master/tests/provisioning_mptcp_sidecar_wireguard_port_regression_test.php`
- Delete: `master/tests/provisioning_mptcp_slave_port_range_regression_test.php`
- Delete: `master/tests/provisioning_runtime_preference_regression_test.php`
- Delete: `master/tests/provisioning_self_leg_best_ip_contract_regression_test.php`
- Delete: `master/tests/provisioning_self_leg_candidate_regression_test.php`
- Delete: `master/tests/provisioning_self_leg_cf_bond_uses_cf_ip_regression_test.php`
- Delete: `master/tests/provisioning_self_leg_cf_wg_transport_regression_test.php`
- Delete: `master/tests/provisioning_self_leg_mptcp_solo_regression_test.php`
- Delete: `master/tests/provisioning_self_leg_payload_regression_test.php`
- Delete: `master/tests/provisioning_self_leg_wg_contract_regression_test.php`
- Delete: `master/tests/provisioning_sidecar_dual_port_regression_test.php`
- Delete: `master/tests/provisioning_slave_mptcp_desired_state_regression_test.php`
- Delete: `master/tests/publish_route_race_candidates_test.php`
- Delete: `master/tests/saveforwardcombination_leigod_mode_true_mptcp_test.php`
- Delete: `master/tests/sidecar_downlink_regressions_test.php`

**Files — bounded architecture assertion:**
- Create: `master/tests/leigod_only_legacy_vocabulary_test.php`

`master/tests/provisioning_forward_combination_helper_groups_regression_test.php` is deliberately absent from this task. Task 5 owns its conversion to explicit-selected-Slave-only candidate sourcing.

- [ ] **Step 1: Prove all replacement contracts pass before deleting old success tests**

Run the behavioral replacements first:

```bash
php master/tests/forward_mode_policy_test.php
php master/tests/saveforwardcombination_leigod_guard_test.php
php master/tests/saveforwardcombination_leigod_mode_test.php
php master/tests/leigod_only_combination_transaction_test.php
php master/tests/leigod_only_forward_operation_guard_test.php
php master/tests/leigod_only_whmcs_forward_transaction_test.php
php master/tests/leigod_only_route_transaction_test.php
php master/tests/tenant_forward_route_selector_test.php
php master/tests/saveforwardroute_ingress_guard_test.php
php master/tests/leigod_only_client_agent_protocol_test.php
php master/tests/leigod_only_node_agent_protocol_test.php
php master/tests/leigod_only_sidecar_agent_protocol_test.php
php master/tests/leigod_only_desired_state_guard_test.php
php master/tests/leigod_only_sidecar_desired_state_test.php
php master/tests/leigod_only_reconcile_publish_guard_test.php
php master/tests/leigod_only_speed_plus_preflight_test.php
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
php master/tests/forward_combination_static_sidecar_wg_regression_test.php
php master/tests/agent_udp_uot_sidepath_management_test.php
```

Expected: every script prints `ok` and exits 0. Stop this task if any replacement fails. Do not delete a positive legacy test while its named policy, operation, route, desired-state, selected-source, static-uplink, or protocol replacement is failing.

- [ ] **Step 2: Record and protect the preserve set**

Run:

```bash
git diff --exit-code -- master/tests/forward_combination_self_leg_schema_regression_test.php master/tests/forward_combination_slaves_table_schema_regression_test.php master/tests/i18n_locale_regression_test.php master/tests/knowledgebase_reader_regression_test.php master/tests/tenant_client_page_simplification_regression_test.php master/tests/tenant_forward_combination_self_leg_visibility_test.php master/tests/forward_combination_self_leg_donors_table_schema_regression_test.php master/tests/forward_combination_donation_columns_schema_regression_test.php master/tests/leigod_mode_true_mptcp_migration_test.php master/tests/leigod_mode_migration_shape_test.php
```

Expected: exit 0 and no output. Old vocabulary in this preserve set is evidence, not active runtime support. Do not normalize historical migration assertions, remove persisted self-leg/donation columns, or rewrite passive visibility text merely to make a keyword scan empty.

- [ ] **Step 3: Convert the eight general-behavior fixtures**

Use one complete positive fixture everywhere an ordinary active Forward is required:

```php
$combination = [
    'id' => 7,
    'backend_mode' => 'leigod_mode',
    'client_mode' => 'bond',
    'runtime_preference' => 'single',
    'bond_ingress_mode' => 'plain_tcp_mux',
    'cf_location_group_id' => null,
    'sidecar_point_id' => 3,
    'leigod_line_id' => 5,
    'helper_group_cap' => 8,
    'allow_private_self_leg' => 0,
    'allow_public_self_leg_donation' => 0,
    'self_leg_scope' => 'none',
    'self_leg_role' => 'none',
    'self_leg_cf_transport' => 'cf_direct',
    'self_leg_outer_transport' => 'leigod',
    'leigod_uot_impl' => 'gost',
    'is_active' => 1,
];
$forward = [
    'id' => 23,
    'service_id' => 1,
    'forward_combination_id' => 7,
    'active_combination_id' => 7,
    'backend_mode' => 'leigod_mode',
    'client_mode' => 'bond',
    'bond_ingress_mode' => 'plain_tcp_mux',
    'bond_sidecar_point_id' => 3,
    'path_prefix' => 'forward-23-route',
    'target_host' => '198.51.100.20',
    'target_port' => 443,
    'enabled' => 1,
];
```

When the behavior reaches publication, also seed an active service, active sidecar point with non-empty host/bond port/token secret, LeiGod line 5, one or more explicit active `forward_combination_slaves` `admin_pin` rows, one attributable primary static LeiGod uplink per selected node, and actual `service_endpoints.id` values. Do not substitute a Forward ID or node ID for an endpoint ID. Set `leigod_uot_impl='lguot'` only in the carrier-specific branch that is explicitly testing the second supported carrier.

Apply these exact per-file conversions:

1. `forward_combination_save_update_persistence_regression_test.php`: keep create/update persistence and omission semantics. New rows default to cap eight and locked structural fields; an omitted legal existing cap/carrier remains unchanged. Do not assert conversion of a raw legacy row.
2. `forward_combination_self_leg_cf_transport_persistence_regression_test.php`: preserve round-trip storage of historical columns, but use inert ordinary values (`allow_* = 0`, scope/role `none`, CF transport `cf_direct`, outer transport `leigod`) for the successful active save. Explicit active self-leg or donation values are covered as rejection in Task 1.
3. `node_heartbeat_republish_gate_regression_test.php`: preserve first-heartbeat republish, identical-fingerprint suppression, and changed-capability republish. Replace QUIC capability input with bond-only capability/identity fields; do not treat passive `supports_mptcp` as activation input.
4. `provisioning_idempotent_publish_requeue_regression_test.php`: preserve first unconverged publish, fresh-pending suppression, future-lease suppression, and stale-pending requeue. Supply the full locked prerequisites and assert the queued payload uses the actual endpoint ID.
5. `provisioning_leigod_mode_direct_ingress_test.php`: assert the locked LeiGod publication uses direct `plain_tcp_mux`, a non-empty route key, and endpoint-ID HMAC. Assert no self-leg, CF, WSS, QUIC, or native-MPTCP section and do not force the carrier to `lguot`.
6. `provisioning_leigod_uot_impl_test.php`: retain schema/migration coverage, both `gost|lguot` carriers, matching Slave/sidecar carrier rendering, artifact route/manifest, and carrier-swap cleanup. Remove the requirement that `client_mode=mptcp` emits `mptcp_underlay`; both carrier fixtures remain under the locked standalone-bond contract.
7. `provisioning_non_convergence_sweep_alarm_regression_test.php`: preserve stale republish, threshold alarm persistence, and converged suppression. Use full prerequisites, group by Forward, and force actual stale endpoint IDs through shared reconciliation.
8. `provisioning_selector_teardown_on_membership_change_regression_test.php`: preserve destroy-on-removed-explicit-member and `selector_teardown` identity. Membership comes only from changed `forward_combination_slaves` pins; helper groups cannot add a replacement. The teardown payload carries the actual endpoint and service IDs.

Run each converted file immediately after editing it. Expected: `ok` and exit 0.

- [ ] **Step 4: Delete active CF, CFWG, WSS, and QUIC success tests**

Delete these files only after Step 1 passes:

```text
bond_quic_capability_regression_test.php
provisioning_active_endpoint_cfwg_transport_regression_test.php
provisioning_bond_quic_gate_regression_test.php
provisioning_cf_mode_bond_token_target_regression_test.php
provisioning_cf_mode_upstream_identity_regression_test.php
provisioning_sidecar_dual_port_regression_test.php
publish_route_race_candidates_test.php
sidecar_downlink_regressions_test.php
```

Replacement ownership is explicit:

- CF backend/token/upstream construction is replaced by `forward_mode_policy_test.php`, `leigod_only_forward_operation_guard_test.php`, and `leigod_only_reconcile_publish_guard_test.php`.
- CFWG artifact and endpoint activation is replaced by `leigod_only_client_agent_protocol_test.php` and `leigod_only_desired_state_guard_test.php`.
- QUIC/WSS/dual-port success is replaced by `leigod_only_sidecar_desired_state_test.php` and the exact locked payload assertions in `leigod_only_reconcile_publish_guard_test.php`.
- Cross-family route racing is replaced by `tenant_forward_route_selector_test.php`, `saveforwardroute_ingress_guard_test.php`, and `leigod_only_route_transaction_test.php`; an ordinary route may retain multiple members, but every persisted member, including disabled members, must satisfy the locked LeiGod combination contract, and the deterministic primary enabled member must match the Forward identity.

Do not transplant any old positive payload assertion into another file.

- [ ] **Step 5: Delete native-MPTCP and fallback active-success tests**

Delete:

```text
provisioning_mptcp_local_uot_port_regression_test.php
provisioning_mptcp_preferred_bond_payload_regression_test.php
provisioning_mptcp_preferred_bond_slave_payload_regression_test.php
provisioning_mptcp_sidecar_wireguard_port_regression_test.php
provisioning_mptcp_slave_port_range_regression_test.php
provisioning_runtime_preference_regression_test.php
provisioning_slave_mptcp_desired_state_regression_test.php
saveforwardcombination_leigod_mode_true_mptcp_test.php
```

`forward_mode_policy_test.php` and `saveforwardcombination_leigod_guard_test.php` own rejection of `client_mode=mptcp` and `mptcp_preferred_bond_fallback`. The three agent protocol tests own omission of native-MPTCP artifacts and desired state. The Speed Plus preflight and desired-state tests own the positive standalone-bond contract. Keep `leigod_mode_true_mptcp_migration_test.php` unchanged because it verifies immutable historical migration behavior.

- [ ] **Step 6: Delete active self-leg candidate and payload success tests**

Delete:

```text
provisioning_leigod_self_leg_transport_regression_test.php
provisioning_self_leg_best_ip_contract_regression_test.php
provisioning_self_leg_candidate_regression_test.php
provisioning_self_leg_cf_bond_uses_cf_ip_regression_test.php
provisioning_self_leg_cf_wg_transport_regression_test.php
provisioning_self_leg_mptcp_solo_regression_test.php
provisioning_self_leg_payload_regression_test.php
provisioning_self_leg_wg_contract_regression_test.php
```

`forward_mode_policy_test.php` owns structural rejection, `leigod_only_desired_state_guard_test.php` owns ordinary omission, and Task 5's two selected-source tests own proof that neither self-leg nor donated/helper candidates enter the active pool. Preserve the self-leg schema, donor-table schema, migration, i18n, knowledgebase, and tenant historical-visibility tests listed above.

- [ ] **Step 7: Delete obsolete overlay/preconnect active-success tests**

Delete:

```text
provisioning_leigod_no_preconnect_test.php
provisioning_leigod_overlay_mtu_test.php
provisioning_leigod_overlay_routing_test.php
provisioning_leigod_slave_preconnect_reuse_test.php
```

These files encode native-MPTCP overlay addresses/MTU/local UoT MPTCP ports/preconnect as active success. `forward_combination_static_sidecar_wg_regression_test.php`, `agent_udp_uot_sidepath_management_test.php`, `provisioning_leigod_uot_impl_test.php`, and `leigod_only_speed_plus_preflight_test.php` retain the valid static LeiGod uplink, carrier, compatibility sidepath, and attributable relay-target contracts. Do not remove shared static uplink support merely because obsolete overlay tests are deleted.

- [ ] **Step 8: Add a bounded legacy-vocabulary architecture test**

Create `leigod_only_legacy_vocabulary_test.php`. Read only these bounded sources: `ForwardModePolicy.php`, the ordinary admin/tenant controllers, `ProvisioningService.php`, the new default migration, and the named historical migration fixtures. Assert:

- `current_mode` and `cf_mode` remain in `ForwardModePolicy` deprecation recognition.
- Persisted historical migrations and negative policy/retirement fixtures may retain old values.
- Ordinary admin and tenant combination controls contain no selectable `current_mode`, `cf_mode`, self-leg, donation, native-MPTCP, WSS, or QUIC option.
- `mptcp_preferred_bond_fallback` is neither accepted by policy nor emitted by active client/publication builders.
- Active `buildClientConfig()` and `publishTaskPayloadForForward()` do not default or fall back to either deprecated backend.
- Ordinary candidate selection SQL is rooted in active explicit `forward_combination_slaves` `admin_pin` rows and contains no helper-group, node-group, or donor-table fallback.
- The test does not require old vocabulary to disappear from migration, schema, retirement, audit, passive inventory, i18n, or knowledgebase evidence.

Behavioral tests remain the correctness proof; this source assertion guards only high-level wiring regressions.

- [ ] **Step 9: Run the converted-test and deletion-replacement gate**

Run:

```bash
php master/tests/forward_combination_save_update_persistence_regression_test.php
php master/tests/forward_combination_self_leg_cf_transport_persistence_regression_test.php
php master/tests/node_heartbeat_republish_gate_regression_test.php
php master/tests/provisioning_idempotent_publish_requeue_regression_test.php
php master/tests/provisioning_leigod_mode_direct_ingress_test.php
php master/tests/provisioning_leigod_uot_impl_test.php
php master/tests/provisioning_non_convergence_sweep_alarm_regression_test.php
php master/tests/provisioning_selector_teardown_on_membership_change_regression_test.php
php master/tests/leigod_only_legacy_vocabulary_test.php
php master/tests/forward_mode_policy_test.php
php master/tests/leigod_only_forward_operation_guard_test.php
php master/tests/leigod_only_whmcs_forward_transaction_test.php
php master/tests/leigod_only_route_transaction_test.php
php master/tests/tenant_forward_route_selector_test.php
php master/tests/leigod_only_client_agent_protocol_test.php
php master/tests/leigod_only_node_agent_protocol_test.php
php master/tests/leigod_only_sidecar_agent_protocol_test.php
php master/tests/leigod_only_desired_state_guard_test.php
php master/tests/leigod_only_sidecar_desired_state_test.php
php master/tests/leigod_only_reconcile_publish_guard_test.php
php master/tests/leigod_only_speed_plus_preflight_test.php
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
php master/tests/forward_combination_static_sidecar_wg_regression_test.php
php master/tests/agent_udp_uot_sidepath_management_test.php
```

Expected: every command prints `ok` and exits 0.

- [ ] **Step 10: Run PHP syntax checks**

Run:

```bash
php -l master/src/Support/ForwardModePolicy.php
php -l master/src/Support/ProvisioningService.php
php -l master/src/Support/MigrationRunner.php
php -l master/src/Controllers/BaseController.php
php -l master/src/Controllers/AdminController.php
php -l master/src/Controllers/TenantController.php
php -l master/src/Controllers/ClientAgentController.php
php -l master/src/Controllers/NodeController.php
php -l master/src/Controllers/SidecarAgentController.php
php -l master/src/Bootstrap.php
```

Expected: each reports `No syntax errors detected`.

- [ ] **Step 11: Run the focused control-plane gate**

Run:

```bash
php master/tests/forward_mode_policy_test.php
php master/tests/leigod_only_persistence_defaults_test.php
php master/tests/saveforwardcombination_leigod_guard_test.php
php master/tests/saveforwardcombination_leigod_mode_test.php
php master/tests/leigod_only_combination_transaction_test.php
php master/tests/leigod_only_combination_list_test.php
php master/tests/leigod_only_forward_operation_guard_test.php
php master/tests/leigod_only_whmcs_forward_transaction_test.php
php master/tests/leigod_only_route_transaction_test.php
php master/tests/tenant_forward_route_selector_test.php
php master/tests/saveforwardroute_ingress_guard_test.php
php master/tests/provisioning_forward_non_structural_update_test.php
php master/tests/tenant_leigod_only_reactivation_test.php
php master/tests/leigod_only_client_agent_protocol_test.php
php master/tests/leigod_only_node_agent_protocol_test.php
php master/tests/leigod_only_sidecar_agent_protocol_test.php
php master/tests/leigod_only_desired_state_guard_test.php
php master/tests/leigod_only_sidecar_desired_state_test.php
php master/tests/leigod_only_reconcile_publish_guard_test.php
php master/tests/leigod_only_speed_plus_preflight_test.php
php master/tests/leigod_only_reconcile_atomicity_test.php
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
php master/tests/admin_forward_combination_page_test.php
php master/tests/admin_cf_location_group_deprecation_test.php
php master/tests/leigod_only_donation_activation_unreachable_test.php
php master/tests/leigod_only_legacy_vocabulary_test.php
```

Expected: every command prints `ok` and exits 0.

- [ ] **Step 12: Run every standalone PHP test explicitly**

Run from the repository root:

```bash
set -e
for test_file in master/tests/*.php; do
  printf '==> %s\n' "$test_file"
  php "$test_file"
done
```

Expected: exit 0 with no failed script. On Windows PowerShell, use the equivalent command without changing the test set:

```powershell
$ErrorActionPreference = 'Stop'; Get-ChildItem 'master/tests/*.php' | Sort-Object Name | ForEach-Object { Write-Host "==> $($_.FullName)"; php $_.FullName; if ($LASTEXITCODE -ne 0) { throw "failed: $($_.FullName)" } }
```

- [ ] **Step 13: Verify history, task ownership, and unrelated work are untouched**

Run:

```bash
git diff --exit-code -- docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md
git diff --exit-code -- master/tests/forward_combination_self_leg_schema_regression_test.php master/tests/forward_combination_slaves_table_schema_regression_test.php master/tests/i18n_locale_regression_test.php master/tests/knowledgebase_reader_regression_test.php master/tests/tenant_client_page_simplification_regression_test.php master/tests/tenant_forward_combination_self_leg_visibility_test.php master/tests/forward_combination_self_leg_donors_table_schema_regression_test.php master/tests/forward_combination_donation_columns_schema_regression_test.php master/tests/leigod_mode_true_mptcp_migration_test.php master/tests/leigod_mode_migration_shape_test.php
git status --short
```

Expected:

- no diff for the unrelated Bridge Lite plan; it remains untracked and unstaged,
- no diff for the preserve set,
- historical migrations are unchanged except Task 2's new default-only migration,
- Task 7 changes only the eight conversion files, the 28 deletion files, and `leigod_only_legacy_vocabulary_test.php`,
- no product source, agent artifact, Rust, Python, systemd, remote host, or deployment file is changed by Task 7.

- [ ] **Step 14: Stage only the Task 7 test matrix and commit it**

```bash
git add master/tests/forward_combination_save_update_persistence_regression_test.php master/tests/forward_combination_self_leg_cf_transport_persistence_regression_test.php master/tests/node_heartbeat_republish_gate_regression_test.php master/tests/provisioning_idempotent_publish_requeue_regression_test.php master/tests/provisioning_leigod_mode_direct_ingress_test.php master/tests/provisioning_leigod_uot_impl_test.php master/tests/provisioning_non_convergence_sweep_alarm_regression_test.php master/tests/provisioning_selector_teardown_on_membership_change_regression_test.php master/tests/leigod_only_legacy_vocabulary_test.php
git rm master/tests/bond_quic_capability_regression_test.php master/tests/provisioning_active_endpoint_cfwg_transport_regression_test.php master/tests/provisioning_bond_quic_gate_regression_test.php master/tests/provisioning_cf_mode_bond_token_target_regression_test.php master/tests/provisioning_cf_mode_upstream_identity_regression_test.php master/tests/provisioning_leigod_no_preconnect_test.php master/tests/provisioning_leigod_overlay_mtu_test.php master/tests/provisioning_leigod_overlay_routing_test.php master/tests/provisioning_leigod_self_leg_transport_regression_test.php master/tests/provisioning_leigod_slave_preconnect_reuse_test.php master/tests/provisioning_mptcp_local_uot_port_regression_test.php master/tests/provisioning_mptcp_preferred_bond_payload_regression_test.php master/tests/provisioning_mptcp_preferred_bond_slave_payload_regression_test.php master/tests/provisioning_mptcp_sidecar_wireguard_port_regression_test.php master/tests/provisioning_mptcp_slave_port_range_regression_test.php master/tests/provisioning_runtime_preference_regression_test.php master/tests/provisioning_self_leg_best_ip_contract_regression_test.php master/tests/provisioning_self_leg_candidate_regression_test.php master/tests/provisioning_self_leg_cf_bond_uses_cf_ip_regression_test.php master/tests/provisioning_self_leg_cf_wg_transport_regression_test.php master/tests/provisioning_self_leg_mptcp_solo_regression_test.php master/tests/provisioning_self_leg_payload_regression_test.php master/tests/provisioning_self_leg_wg_contract_regression_test.php master/tests/provisioning_sidecar_dual_port_regression_test.php master/tests/provisioning_slave_mptcp_desired_state_regression_test.php master/tests/publish_route_race_candidates_test.php master/tests/saveforwardcombination_leigod_mode_true_mptcp_test.php master/tests/sidecar_downlink_regressions_test.php
git diff --cached --name-only
git commit -m "test(master): enforce leigod-only control plane" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

Before committing, compare `git diff --cached --name-only` to the exact 37-path Task 7 matrix: eight modified tests, one new vocabulary test, and 28 deleted tests. Unstage any other path. In particular, do not stage the control-plane plan, the approved specification's status-only change, or `docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md`.

---

## Completion boundary

Completing this plan means ordinary control-plane behavior is LeiGod-only and fails closed, but it is **not independently deployable**. Existing legacy Forwards are only blocked/hidden here; durable retirement and exact remote cleanup come from `2026-07-17-forward-runtime-retirement.md`. Existing valid LeiGod rows that still use native MPTCP or another old contract are intentionally blocked rather than silently changed; preflighted normalization and staged activation come from `2026-07-17-leigod-only-activation-and-rollout.md` after the bond runtime, supervisor, and telemetry plans are complete.

No production deployment is authorized for this changeset.
