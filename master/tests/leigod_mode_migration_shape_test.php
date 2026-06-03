<?php
declare(strict_types=1);

// The leigod_mode promotion migration must (1) flip every leigod self-leg combination to
// backend_mode='leigod_mode', (2) strip its Cloudflare coupling, and (3) clear the stale per-forward
// cf_location_group_id (the critical landmine: forwardUsesCfMode keys off cf_location_group_id > 0,
// so a leftover value keeps the CF-ingress drag alive after the combo flips). Source-grep shape test.

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$path = __DIR__ . '/../migrations/20260603_promote_leigod_mode.sql';
$sql = is_file($path) ? (file_get_contents($path) ?: '') : '';
if ($sql === '') {
    fail('20260603_promote_leigod_mode.sql is missing or empty');
}

foreach ([
    "backend_mode = 'leigod_mode'",                 // flip the combos
    "cf_location_group_id = NULL",                  // strip CF coupling
    "self_leg_cf_transport = 'cf_direct'",          // no CF self-leg transport
    "leigod_uot_impl = 'lguot'",                    // default to the efficient carrier
    "WHERE self_leg_outer_transport = 'leigod'",    // scope: only leigod self-leg combos
    "UPDATE forwards f",                            // step 2: clear the per-forward CF group
] as $snippet) {
    if (!str_contains($sql, $snippet)) {
        fail('migration missing required snippet: ' . $snippet);
    }
}

// Idempotency guard: the combo UPDATE must be a no-op once already flipped.
if (!str_contains($sql, "backend_mode <> 'leigod_mode'")) {
    fail('migration combo UPDATE is not idempotent (missing backend_mode <> leigod_mode guard)');
}

echo "OK\n";
exit(0);
