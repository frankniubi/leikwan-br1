<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($source === false) {
    fail('failed to read ProvisioningService.php');
}

// Locate the UPDATE forward_combinations block.
if (!preg_match('/UPDATE forward_combinations\s+SET(.*?)WHERE id = :id/s', $source, $m)) {
    fail('UPDATE forward_combinations block not found');
}
$updateBlock = $m[1];

foreach ([
    'runtime_preference = :runtime_preference',
    'allow_private_self_leg = :allow_private_self_leg',
    'self_leg_scope = :self_leg_scope',
    'self_leg_role = :self_leg_role',
    'helper_group_cap = :helper_group_cap',
    'helper_escalation_policy = :helper_escalation_policy',
] as $snippet) {
    if (strpos($updateBlock, $snippet) === false) {
        fail('UPDATE statement missing column: ' . $snippet);
    }
}

echo "ok\n";