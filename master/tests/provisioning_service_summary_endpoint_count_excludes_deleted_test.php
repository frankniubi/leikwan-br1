<?php
declare(strict_types=1);

// Regression: ProvisioningService::serviceSummary() builds $forward['endpoints'],
// which the tenant UI renders as "Total: N". That query MUST exclude deleted/deleting
// tombstones, otherwise the panel shows inflated counts (observed: fwd65=40, fwd66=22
// while only 3 endpoints are real). buildClientConfig already filters these; the
// serviceSummary display query must match.

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$src = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if (!is_string($src) || $src === '') {
    fail('cannot read ProvisioningService.php');
}

$start = strpos($src, 'public function serviceSummary(');
if ($start === false) {
    fail('serviceSummary() not found');
}
$end = strpos($src, 'public function ', $start + 30);
$method = substr($src, $start, $end !== false ? $end - $start : strlen($src) - $start);

$qpos = strpos($method, "\$forward['endpoints'] = ");
if ($qpos === false) {
    fail("serviceSummary() no longer assigns \$forward['endpoints']");
}
$queryRegion = substr($method, $qpos, 800);

if (strpos($queryRegion, 'FROM service_endpoints e') === false) {
    fail('serviceSummary endpoints query shape changed unexpectedly');
}
if (
    strpos($queryRegion, 'health_status <> :deleted') === false
    || strpos($queryRegion, 'health_status <> :deleting') === false
) {
    fail('serviceSummary endpoints query MUST exclude deleted/deleting (else Total counts tombstones: 40/22 vs real 3)');
}

echo "ok: serviceSummary endpoints query excludes deleted/deleting\n";
