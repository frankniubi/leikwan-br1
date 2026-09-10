<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../cli/cleanup-tasks.php');
if (!is_string($source)) {
    fwrite(STDERR, "unable to read cleanup-tasks.php\n");
    exit(1);
}

foreach ([
    'ultra-lease-retention-days',
    'FROM ultra_client_leases',
    'revoked_at IS NULL',
    'expires_at < :now',
    'created_at < :cutoff',
    'ORDER BY id',
    'LIMIT 10000',
    'reclaimed {$reclaimedUltraLeases} expired unrevoked Ultra lease rows',
] as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "missing expired Ultra lease reclaim guard: {$needle}\n");
        exit(1);
    }
}

if (str_contains($source, 'SET revoked_at = :revoked_at')) {
    fwrite(STDERR, "cleanup must not mark expired rows revoked; revoked rows are generation tombstones\n");
    exit(1);
}

echo "ok\n";
