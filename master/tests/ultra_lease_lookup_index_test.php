<?php
declare(strict_types=1);

/**
 * The Ultra grant table must stay a small, index-servable table.
 *
 * On 2026-09-13 the production panel's MariaDB was pinned at 90.9% CPU with
 * 12.7% iowait. ultra_client_leases held 364,300 rows and exactly 198 live
 * grants; every lease issue ran three shapes that no index could answer from
 * the index alone, so the planner full-scanned 135 MB of table + 193 MB of
 * indexes on a 128 MB buffer pool:
 *
 *   liveDeviceHashes()  0.69-1.23 s, 248,662 rows examined, "Using temporary"
 *   liveLeaseCount()    0.19-0.25 s, 364,442 rows examined
 *   recentIssueCount()  same shape on issued_at
 *
 * Two independent defects made that state reachable and both are pinned here:
 * the retention window was days for five-minute nonces, and the lookup shapes
 * had no covering index. Either one alone re-creates the outage.
 */

$root = dirname(__DIR__);
$failures = [];
$checks = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures[] = $label . ($detail !== '' ? ' -- ' . $detail : '');
    }
}

// 1. The hot shapes must be answerable from a covering index.
$migration = (string) file_get_contents($root . '/migrations/20260913_add_ultra_lease_lookup_indexes.sql');
$indexes = [
    // client_id + time range: liveDeviceHashes()
    'idx_ultra_lease_client_live' => '(client_id, expires_at, revoked_at, device_hash)',
    // client_id + device + time range: liveLeaseCount()
    'idx_ultra_lease_client_device_expiry' => '(client_id, device_hash, expires_at)',
    // client_id + device + issue window: recentIssueCount()
    'idx_ultra_lease_client_device_issued' => '(client_id, device_hash, issued_at)',
];
foreach ($indexes as $name => $columns) {
    check(
        'migration creates ' . $name,
        str_contains($migration, 'CREATE INDEX IF NOT EXISTS ' . $name)
            && str_contains($migration, $columns),
        $columns
    );
}

// A re-runnable migration matters: the indexes were hot-patched onto the live
// panel before the file existed, so a plain CREATE INDEX would fail there.
check(
    'migration is re-runnable (IF NOT EXISTS)',
    preg_match_all('/^CREATE INDEX IF NOT EXISTS/m', $migration) === 3
);

// 2. Fresh installs must not have a full-scan window at all.
$base = (string) file_get_contents($root . '/migrations/20260825_add_ultra_policy_leases.sql');
foreach ($indexes as $name => $columns) {
    check('base lease migration declares ' . $name, str_contains($base, $name . ' ' . $columns));
}

// 3. The retention window must be hours for a five-minute nonce.
$cleanup = (string) file_get_contents($root . '/cli/cleanup-tasks.php');
check(
    'cleanup defaults Ultra grant retention to hours',
    str_contains($cleanup, "\$args['ultra-lease-retention-hours'] ?? 6")
);
check(
    'cleanup derives the grant cutoff in hours',
    str_contains($cleanup, 'time() - $ultraLeaseRetentionHours * 3600')
);
check(
    'cleanup no longer derives the grant cutoff in days',
    !str_contains($cleanup, '$ultraLeaseRetentionDays * 86400')
);
check(
    'legacy --ultra-lease-retention-days still means days',
    str_contains($cleanup, "(int) \$args['ultra-lease-retention-days'] * 24")
);

// 4. The deployed unit must actually pass the hours knob.
$unit = (string) file_get_contents(dirname($root) . '/deploy/systemd/leikwan-cleanup.service');
check(
    'systemd unit passes --ultra-lease-retention-hours 6',
    str_contains($unit, '--ultra-lease-retention-hours 6')
);

// 5. The two COUNT shapes still filter on client_id + device, i.e. the indexes
//    above stay usable. A refactor that drops the device predicate would make
//    every index here useless while still passing a naive "table is small" test.
$service = (string) file_get_contents($root . '/src/Support/UltraLeaseService.php');
check(
    'liveLeaseCount filters client_id + device_hash + expires_at',
    str_contains($service, 'AND device_hash = :device_hash AND expires_at > :now')
        || str_contains($service, 'device_hash = :device_hash')
);
check(
    'liveDeviceHashes selects distinct device_hash for one client',
    str_contains($service, 'SELECT DISTINCT device_hash FROM ultra_client_leases')
);

if ($failures !== []) {
    fwrite(STDERR, "[ultra-lease-lookup-index] FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo "[ultra-lease-lookup-index] ok ({$checks} checks)\n";
