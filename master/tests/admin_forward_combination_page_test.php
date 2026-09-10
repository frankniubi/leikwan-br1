<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');

foreach ([
    '/admin/cf-location-groups',
    '/admin/forward-combinations',
    'cfLocationGroupsPage',
    'saveCfLocationGroup',
    'forwardCombinationsPage',
    'saveForwardCombination',
] as $snippet) {
    if (!str_contains($bootstrap . $admin, $snippet)) {
        fail('missing admin routing snippet: ' . $snippet);
    }
}

foreach ([
    'Cloudflare Location Groups',
    'Forward Combinations',
    'name="cf_listen_port"',
    'name="backend_mode"',
    'name="cf_location_group_id"',
    'value="current_mode"',
    'value="cf_mode"',
    'value="single"',
    'value="bond"',
] as $snippet) {
    if (!str_contains($admin, $snippet)) {
        fail('missing admin page snippet: ' . $snippet);
    }
}

echo "ok\n";
