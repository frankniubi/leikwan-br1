<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$tenantSource = file_get_contents($repoRoot . '/master/src/Controllers/TenantController.php');

if (!is_string($tenantSource) || $tenantSource === '') {
    fail('failed to read TenantController source');
}

$start = strpos($tenantSource, 'public function clientsPage(');
$end = strpos($tenantSource, 'public function sidecarsPage(', $start ?: 0);
if (!is_int($start) || !is_int($end) || $end <= $start) {
    fail('failed to isolate tenant clientsPage source');
}
$clientsPage = substr($tenantSource, $start, $end - $start);
foreach (['BondLegReputationService', 'scopedRowsForTenant', 'recentEvents', 'Leg Reputation'] as $forbidden) {
    if (str_contains($clientsPage, $forbidden)) {
        fail('tenant clientsPage still exposes reputation data: ' . $forbidden);
    }
}

echo "ok\n";
