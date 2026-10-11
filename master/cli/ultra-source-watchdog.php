<?php
declare(strict_types=1);

/**
 * Cron entry (every minute): alert when an Ultra source stops refreshing line
 * material. See Leikwan\Master\Support\UltraSourceWatchdog.
 *
 *   php master/cli/ultra-source-watchdog.php              one check
 *   php master/cli/ultra-source-watchdog.php --test-email send a test mail to the recipients
 */

$baseDir = __DIR__ . '/..';

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $baseDir . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\SmtpMailer;
use Leikwan\Master\Support\UltraSourceWatchdog;

$envPath = getenv('LEIKWAN_ENV_PATH') ?: $baseDir . '/.env';
if (!is_file($envPath)) {
    fwrite(STDERR, "panel .env not found\n");
    exit(2);
}
$config = new Config($envPath);
$mailer = new SmtpMailer($config);
$send = static function (string $to, string $subject, string $text) use ($mailer): void {
    $mailer->send($to, '', $subject, nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')), $text);
};
$statePath = getenv('ULTRA_SOURCE_WATCHDOG_STATE') ?: $baseDir . '/storage/ultra-source-watchdog.json';
$watchdog = new UltraSourceWatchdog($config, new Database($config), $statePath, $send);

if (in_array('--test-email', $argv, true)) {
    echo 'test mail sent to ' . count($watchdog->sendTest()) . " recipient(s)\n";
    exit(0);
}

$summary = $watchdog->run();
echo gmdate('Y-m-d H:i:s') . ' watched=' . implode(',', $summary['watched'])
    . ' stale=' . implode(',', $summary['stale'])
    . ' alerted=' . implode(',', $summary['alerted'])
    . ' recovered=' . implode(',', $summary['recovered']) . "\n";
