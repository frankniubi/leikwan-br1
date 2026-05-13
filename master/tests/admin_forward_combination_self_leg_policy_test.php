<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
foreach ([
    'name="runtime_preference"',
    'name="allow_private_self_leg"',
    'name="self_leg_scope"',
    'name="self_leg_role"',
    'name="helper_group_cap"',
    'name="helper_escalation_policy"',
    'helper_node_group_keys_text',
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing admin snippet: ' . $snippet);
    }
}

echo "ok\n";
