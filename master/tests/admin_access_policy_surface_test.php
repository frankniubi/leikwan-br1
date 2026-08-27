<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function assertContains(string $source, string $needle, string $message): void { if (!str_contains($source, $needle)) fail($message . ': ' . $needle); }

$controller = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$base = (string) file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');
$bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');

foreach ([
    'function accessControlPage(',
    'function saveAccessBlacklistRule(',
    'function toggleAccessBlacklistRule(',
    'function releaseDeviceBlock(',
    'function releaseAccountBan(',
    'function accessControlPage(',
    'AccessPolicyService',
    'SessionAuth::validateCsrf($request)',
    'access_blacklist_rules',
    'user_geo_lock_profiles',
    'access_policy_events',
    'account_access_bans',
    'Device Blocks',
    'Account Bans',
] as $needle) {
    assertContains($controller, $needle, 'admin access-control surface missing');
}
assertContains($bootstrap, "'/admin/access-control'", 'admin access-control route missing');
assertContains($base, "'/admin/access-control'", 'admin navigation item missing');

foreach (['saveAccessBlacklistRule', 'toggleAccessBlacklistRule', 'releaseDeviceBlock', 'releaseAccountBan'] as $method) {
    $start = strpos($controller, 'function ' . $method . '(');
    $body = $start === false ? '' : substr($controller, $start, 4000);
    assertContains($body, 'SessionAuth::validateCsrf($request)', $method . ' must validate CSRF');
}

echo "ok\n";
