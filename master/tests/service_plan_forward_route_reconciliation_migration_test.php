<?php
declare(strict_types=1);

$path = __DIR__ . '/../migrations/20260802_reconcile_service_plan_forward_routes.sql';
$sql = is_file($path) ? file_get_contents($path) : false;
if (!is_string($sql)) {
    fwrite(STDERR, "missing service-plan route reconciliation migration\n");
    exit(1);
}

function failRouteReconciliation(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function requireRouteReconciliationFragment(string $fragment, string $sql): void
{
    if (!str_contains($sql, $fragment)) {
        failRouteReconciliation('route reconciliation migration missing: ' . $fragment);
    }
}

$statements = array_values(array_filter(array_map(
    static fn(string $statement): string => trim(preg_replace('/^\s*--.*$/m', '', $statement) ?? $statement),
    explode(';', $sql)
), static fn(string $statement): bool => $statement !== ''));

if (count($statements) !== 2) {
    failRouteReconciliation('reconciliation must use two independently retryable statements');
}
if (!str_starts_with($statements[0], 'DELETE spfr')) {
    failRouteReconciliation('stale authorizations must be removed first so partial execution fails closed');
}
if (!str_starts_with($statements[1], 'INSERT INTO service_plan_forward_routes')) {
    failRouteReconciliation('missing authorizations must be inserted by the second statement');
}

foreach ([$statements[0], $statements[1]] as $statement) {
    foreach ([
        'FROM service_plan_forward_combinations spfc',
        'INNER JOIN forward_combinations fc',
        'INNER JOIN forward_route_combinations frc',
        'INNER JOIN forward_routes fr',
        'fc.is_active = 1',
        'frc.enabled = 1',
        'fr.is_active = 1',
    ] as $fragment) {
        requireRouteReconciliationFragment($fragment, $statement);
    }
}

requireRouteReconciliationFragment('NOT EXISTS', $statements[0]);
requireRouteReconciliationFragment('spfc.plan_id = spfr.plan_id', $statements[0]);
requireRouteReconciliationFragment('frc.forward_route_id = spfr.forward_route_id', $statements[0]);
requireRouteReconciliationFragment('SELECT DISTINCT spfc.plan_id, frc.forward_route_id', $statements[1]);
requireRouteReconciliationFragment('NOT EXISTS', $statements[1]);

foreach (['INSERT IGNORE', 'ON DUPLICATE KEY', 'TEMPORARY TABLE'] as $unsafeFragment) {
    if (stripos($sql, $unsafeFragment) !== false) {
        failRouteReconciliation('migration must not depend on duplicate-error suppression or cross-statement state: ' . $unsafeFragment);
    }
}

echo "ok\n";
