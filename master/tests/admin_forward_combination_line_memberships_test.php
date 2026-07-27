<?php
declare(strict_types=1);

$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php') ?: '';
$failures = 0;

function needMembershipUi(string $needle, string $source, int &$failures): void
{
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, 'FAIL: AdminController.php missing ' . $needle . PHP_EOL);
        $failures++;
    }
}

foreach ([
    'listLeigodLines()',
    'leigod_lines[',
    '][leigod_line_id]',
    '][enabled]',
    '][sort_order]',
    'LeiGod line pool',
    'configuration_state',
    'data-leigod-memberships',
    'data-route-health-warning',
] as $needle) {
    needMembershipUi($needle, $admin, $failures);
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " FAIL\n");
    exit(1);
}
echo "ok\n";
