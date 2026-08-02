<?php
declare(strict_types=1);

function failForwardReactivation(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql') ?: '';
$migration = file_get_contents(__DIR__ . '/../migrations/20260802_add_forward_line_api_guards.sql') ?: '';
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php') ?: '';
$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
$billing = file_get_contents(__DIR__ . '/../cli/billing-deduct.php') ?: '';

foreach ([$schema, $migration] as $sql) {
    foreach (['user_deleted_at DATETIME NULL', 'balance_suspended_at DATETIME NULL'] as $column) {
        if (!str_contains($sql, $column)) {
            failForwardReactivation('forward schema must distinguish delete and balance-suspension state: ' . $column);
        }
    }
}
foreach (['forward.deleted', 'balance_suspended_at', 'user_deleted_at', 'Historical billing audits identify only a user'] as $required) {
    if (!str_contains($migration, $required)) {
        failForwardReactivation('migration must classify historical disabled forwards using audit evidence: ' . $required);
    }
}
if (substr_count($migration, 'UPDATE forwards') !== 1
    || !str_contains($migration, 'MAX(a.created_at)')
    || !str_contains($migration, 'a.created_at >= f.created_at')) {
    failForwardReactivation('migration must classify only explicit deletes using event time');
}
if (str_contains($migration, 'auto_suspend_insufficient_balance')
    || str_contains($migration, 'SET user_deleted_at = COALESCE(updated_at, NOW())')) {
    failForwardReactivation('migration must keep unknown historical disable reasons unclassified and non-reactivatable');
}

foreach (['balance_suspended_at', 'api_revision = GREATEST(1, api_revision) + 1', 'enabled = 1', 'user_deleted_at IS NULL'] as $required) {
    if (!str_contains($billing, $required)) {
        failForwardReactivation('billing suspension must persist an explicit versioned reason: ' . $required);
    }
}

$reactivateStart = strpos($tenant, 'public function reactivateForwards(');
$reactivateEnd = strpos($tenant, 'public function purchasePackage(', $reactivateStart === false ? 0 : $reactivateStart);
$reactivate = ($reactivateStart !== false && $reactivateEnd !== false)
    ? substr($tenant, $reactivateStart, $reactivateEnd - $reactivateStart)
    : '';
foreach ([
    'reactivateEligibleForwardsForUser(',
    'USER_FORWARD_LIMIT_PER_SERVICE',
] as $required) {
    if (!str_contains($reactivate, $required)) {
        failForwardReactivation('tenant reactivation missing guarded provisioning call: ' . $required);
    }
}

$suspendedStart = strpos($tenant, 'private function hasSuspendedForwards(');
$suspendedEnd = strpos($tenant, 'private function buildBillingUsageBreakdown(', $suspendedStart === false ? 0 : $suspendedStart);
$suspendedMethod = ($suspendedStart !== false && $suspendedEnd !== false)
    ? substr($tenant, $suspendedStart, $suspendedEnd - $suspendedStart)
    : '';
foreach (['user_deleted_at IS NULL', 'balance_suspended_at IS NOT NULL'] as $required) {
    if (!str_contains($suspendedMethod, $required)) {
        failForwardReactivation('reactivation prompt must count only explicit balance suspensions: ' . $required);
    }
}
foreach ([
    'UPDATE forwards SET enabled = 1',
    '$throwable->getMessage()',
] as $forbidden) {
    if (str_contains($reactivate, $forbidden)) {
        failForwardReactivation('tenant reactivation retains unsafe behavior: ' . $forbidden);
    }
}

foreach ([
    'public function reactivateEligibleForwardsForUser(',
    'user_deleted_at IS NULL',
    'balance_suspended_at IS NOT NULL',
    'balance_suspended_at = NULL',
    'allow_manage_forwards',
    'user_wallet_balance',
    'COUNT(*) AS active_count',
    'api_revision = GREATEST(1, api_revision) + 1',
    'user_deleted_at = :user_deleted_at',
    'balance_suspended_at = NULL',
] as $required) {
    if (!str_contains($provisioning, $required)) {
        failForwardReactivation('provisioning reactivation/delete boundary missing: ' . $required);
    }
}

echo "ok\n";
