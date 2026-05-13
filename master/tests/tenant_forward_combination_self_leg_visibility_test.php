<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
foreach ([
    'available_forward_combinations',
    'forward_combination_id',
] as $required) {
    if (!str_contains($source, $required)) {
        fail('missing tenant combination snippet: ' . $required);
    }
}
foreach ([
    'allow_private_self_leg',
    'helper_group_cap',
    'helper_node_group_keys',
    'runtime_preference',
] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fail('tenant controller leaks internal field: ' . $forbidden);
    }
}

echo "ok\n";
