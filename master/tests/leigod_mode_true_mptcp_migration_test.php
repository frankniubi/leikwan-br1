<?php
declare(strict_types=1);

// Source-grep guard for the Phase 2 leigod_mode true-MPTCP data migration.
// The migration must normalize leigod_mode combinations and the combo-backed forwards that read
// denormalized forwards.client_mode at publish/runtime time.

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$path = __DIR__ . '/../migrations/20260604_leigod_mode_true_mptcp.sql';
$sql = is_file($path) ? (file_get_contents($path) ?: '') : '';
if ($sql === '') {
    fail('20260604_leigod_mode_true_mptcp.sql is missing or empty');
}

foreach ([
    'UPDATE forward_combinations',
    'UPDATE forwards f',
    'JOIN forward_combinations fc',
    "client_mode NOT IN ('mptcp', 'bond')",
    "runtime_preference = 'mptcp_preferred_bond_fallback'",
    'f.client_mode = fc.client_mode',
    'f.backend_mode = fc.backend_mode',
    'f.cf_location_group_id = NULL',
] as $snippet) {
    if (!str_contains($sql, $snippet)) {
        fail('migration missing required snippet: ' . $snippet);
    }
}

echo "OK\n";
exit(0);
