<?php
declare(strict_types=1);

// Regression: NodeController::heartbeat used to run reconcileActiveForwardsForGroup
// and republishActiveEndpointsForNode inline and UNGUARDED. A DB deadlock thrown
// inside those calls bubbled out as an HTTP 500, the agent skipped its task pull/ack
// cycle, and the whole fleet froze (3156 tasks stuck pending). The heartbeat must now
// isolate each inline provisioning step: a throw is logged via error_log and swallowed,
// and execution continues so the heartbeat still returns its normal response.

$baseDir = __DIR__ . '/..';

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $baseDir . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\NodeController;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ProvisioningService;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

// Construct the real (final) controller. Database connects lazily, so no real DB is
// touched; guardProvisioning only operates on its own arguments. We invoke the
// protected helper via reflection — the exact path heartbeat() uses for each inline
// reconcile/republish call.
$envPath = tempnam(sys_get_temp_dir(), 'lw-hb-guard-env-');
if ($envPath === false) {
    fail('failed to create temp env');
}
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n");
$config = new Config($envPath);
$db = new Database($config);
$provisioning = new ProvisioningService($config, $db);
$controller = new NodeController($config, $db, $provisioning);

$guard = new ReflectionMethod($controller, 'guardProvisioning');
$guard->setAccessible(true);

// Route error_log() into a temp file we can inspect.
$logFile = tempnam(sys_get_temp_dir(), 'lw-hb-guard-log-');
if ($logFile === false) {
    fail('failed to create temp log file');
}
$prevLog = ini_get('error_log');
$prevLogErrors = ini_get('log_errors');
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

// Step 1 throws (simulating the InnoDB deadlock from queueTask's collapse UPDATE).
$reconcileRan = false;
$guard->invoke($controller, 42, 'reconcileActiveForwardsForGroup', function () use (&$reconcileRan): void {
    $reconcileRan = true;
    throw new RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
});

// Step 2 must STILL run — proving the guard caught the throw and execution continued.
$republishRan = false;
$guard->invoke($controller, 42, 'republishActiveEndpointsForNode', function () use (&$republishRan): void {
    $republishRan = true;
});

// Restore ini.
ini_set('error_log', (string) $prevLog);
ini_set('log_errors', (string) $prevLogErrors);

if (!$reconcileRan) {
    fail('the guarded reconcile callback was never invoked');
}
if (!$republishRan) {
    fail('REGRESSION: a throwing reconcile aborted the heartbeat — the second step never ran');
}

$logContents = (string) file_get_contents($logFile);
@unlink($logFile);

if (!str_contains($logContents, '[node-heartbeat]')) {
    fail('expected the guard to error_log a node-heartbeat message; the throw was swallowed silently');
}
if (!str_contains($logContents, 'node=42')) {
    fail('expected the logged message to identify the node id');
}
if (!str_contains($logContents, 'reconcileActiveForwardsForGroup')) {
    fail('expected the logged message to identify the failing step');
}
if (!str_contains($logContents, 'Deadlock found')) {
    fail('expected the logged message to include the underlying exception message');
}

echo "ok\n";
