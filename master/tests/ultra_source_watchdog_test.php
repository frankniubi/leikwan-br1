<?php
declare(strict_types=1);

/**
 * Ultra source watchdog: a source that stops uploading material is reported
 * once, reminded on the repeat interval, and announced when it recovers; an
 * idle source (no upload for a day) is not watched.
 *
 *   php -d extension=pdo_sqlite master/tests/ultra_source_watchdog_test.php
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\UltraSourceWatchdog;

$failures = 0;
$checks = 0;
function check(bool $condition, string $label): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL $label\n");
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE ultra_sources (source_id TEXT, is_active INTEGER, last_seen_at TEXT, last_material_upload_at TEXT, last_error_code TEXT)');
$pdo->exec('CREATE TABLE ultra_lines (id INTEGER, last_material_expires_at TEXT)');
$pdo->exec("CREATE TABLE users (email TEXT, role TEXT, status TEXT)");
$pdo->exec("INSERT INTO users VALUES ('ops@example.test', 'admin', 'active'), ('old@example.test', 'admin', 'disabled')");

$t0 = 1_900_000_000;
$at = static fn(int $t): string => gmdate('Y-m-d H:i:s', $t);
$pdo->exec("INSERT INTO ultra_sources VALUES ('primary', 1, '" . $at($t0) . "', '" . $at($t0) . "', NULL)");
$pdo->exec("INSERT INTO ultra_sources VALUES ('idle', 1, '" . $at($t0) . "', '" . $at($t0 - 40 * 86400) . "', NULL)");
$pdo->exec("INSERT INTO ultra_lines VALUES (1, '" . $at($t0 + 1800) . "')");

putenv('ULTRA_SOURCE_ALERT_EMAILS');
$config = new Config(__DIR__ . '/does-not-exist.env');
$db = new Database($config);
$db->setPdo($pdo);
$state = tempnam(sys_get_temp_dir(), 'lk-watchdog-');
@unlink($state);
$sent = [];
$now = $t0;
$watchdog = new UltraSourceWatchdog($config, $db, $state, static function (string $to, string $subject, string $text) use (&$sent): void {
    $sent[] = [$to, $subject, $text];
}, static function () use (&$now): int {
    return $now;
});

// Healthy: nothing sent; the idle source is not watched.
$summary = $watchdog->run();
check($summary['watched'] === ['primary'], 'only the recently refreshing source is watched');
check($sent === [], 'healthy source sends nothing');

// 11 minutes without an upload, host still heartbeating: one alert to the active admin.
$now = $t0 + 11 * 60;
$pdo->exec("UPDATE ultra_sources SET last_seen_at = '" . $at($now) . "' WHERE source_id = 'primary'");
$summary = $watchdog->run();
check($summary['stale'] === ['primary'] && $summary['alerted'] === ['primary'], 'stale source is alerted');
check(count($sent) === 1 && $sent[0][0] === 'ops@example.test', 'alert goes to the active admin only');
check(str_contains($sent[0][1], 'primary') && str_contains($sent[0][2], '拿不到新凭据'), 'alert says the host is up but not getting material');

// Still stale a few minutes later: no repeat yet.
$now = $t0 + 20 * 60;
$watchdog->run();
check(count($sent) === 1, 'no repeat inside the reminder interval');

// Host gone and an hour later: reminder that names the lost host.
$now = $t0 + 72 * 60;
$watchdog->run();
check(count($sent) === 2 && str_contains($sent[1][2], '提醒') && str_contains($sent[1][2], '失联'), 'hourly reminder while stale, host reported lost');

// Recovery: one notice, then quiet.
$pdo->exec("UPDATE ultra_sources SET last_material_upload_at = '" . $at($now) . "', last_seen_at = '" . $at($now) . "' WHERE source_id = 'primary'");
$summary = $watchdog->run();
check($summary['recovered'] === ['primary'] && count($sent) === 3 && str_contains($sent[2][1], '恢复'), 'recovery notice sent once');
$now += 60;
$watchdog->run();
check(count($sent) === 3, 'quiet after recovery');

// Explicit recipients win; a named source is watched even when idle.
putenv('ULTRA_SOURCE_ALERT_EMAILS=a@example.test, not-an-email, b@example.test');
putenv('ULTRA_SOURCE_ALERT_SOURCES=idle');
$summary = $watchdog->run();
check(in_array('idle', $summary['watched'], true) && in_array('idle', $summary['alerted'], true), 'named source is watched and alerted');
check(array_column(array_slice($sent, 3), 0) === ['a@example.test', 'b@example.test'], 'configured recipients replace the admin fallback, invalid ones dropped');
putenv('ULTRA_SOURCE_ALERT_EMAILS');
putenv('ULTRA_SOURCE_ALERT_SOURCES');

// A mail failure never breaks the check.
$broken = new UltraSourceWatchdog($config, $db, $state . '.2', static function (): void {
    throw new RuntimeException('smtp down');
}, static fn(): int => $t0 + 86400);
$summary = $broken->run();
check($summary['stale'] === ['primary'], 'mail failure is swallowed and the check still reports');

@unlink($state);
@unlink($state . '.2');
if ($failures > 0) {
    fwrite(STDERR, "ultra_source_watchdog_test: $failures of $checks checks failed\n");
    exit(1);
}
echo "ultra_source_watchdog_test: $checks checks passed\n";
