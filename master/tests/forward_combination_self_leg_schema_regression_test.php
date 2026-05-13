<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
$migration = file_exists(__DIR__ . '/../migrations/20260514_add_forward_combination_helper_groups_and_self_leg_policy.sql')
    ? file_get_contents(__DIR__ . '/../migrations/20260514_add_forward_combination_helper_groups_and_self_leg_policy.sql')
    : '';

foreach ([
    'CREATE TABLE IF NOT EXISTS forward_combination_helper_groups',
    'ADD COLUMN runtime_preference',
    'ADD COLUMN allow_private_self_leg',
    'ADD COLUMN self_leg_scope',
    'ADD COLUMN self_leg_role',
    'ADD COLUMN helper_group_cap',
    'ADD COLUMN helper_escalation_policy',
] as $snippet) {
    if (!str_contains($schema, $snippet) && !str_contains($migration, $snippet)) {
        fail('missing schema snippet: ' . $snippet);
    }
}

echo "ok\n";