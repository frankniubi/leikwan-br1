<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$tenantSource = file_get_contents($repoRoot . '/master/src/Controllers/TenantController.php');
$baseSource = file_get_contents($repoRoot . '/master/src/Controllers/BaseController.php');

if (!is_string($tenantSource) || $tenantSource === '') {
    fail('failed to read TenantController source');
}

if (!is_string($baseSource) || $baseSource === '') {
    fail('failed to read BaseController source');
}

if (!str_contains($tenantSource, 'scopedRowsForTenant')) {
    fail('expected TenantController to request tenant-scoped reputation rows');
}

if (!str_contains($baseSource, 'Leg Reputation')) {
    fail('expected diagnostics dialog to render a Leg Reputation section');
}

if (!str_contains($tenantSource . $baseSource, 'Reference only. Runtime already adapts automatically when a leg degrades. You usually do not need to take action unless the line itself is failing.')) {
    fail('expected calm tenant trust copy for reputation visibility');
}

echo "ok\n";
