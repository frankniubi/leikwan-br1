<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql') ?: '';
$migrationPath = __DIR__ . '/../migrations/20260602_add_leigod_self_leg_transport.sql';
$migration = is_file($migrationPath) ? (file_get_contents($migrationPath) ?: '') : '';

foreach ([
    'self_leg_outer_transport',
    'leigod_line_id',
    'leigod_socks_user',
    'leigod_socks_pass',
] as $snippet) {
    if (!str_contains($schema, $snippet)) {
        fail('missing schema snippet: ' . $snippet);
    }
}

foreach ([
    'ALTER TABLE forward_combinations',
    'self_leg_outer_transport',
] as $snippet) {
    if (!str_contains($migration, $snippet)) {
        fail('missing migration snippet: ' . $snippet);
    }
}

echo "OK\n";
exit(0);
