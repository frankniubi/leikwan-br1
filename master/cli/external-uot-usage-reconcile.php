<?php
declare(strict_types=1);

/**
 * Bill sidecar-measured external UoT usage the client did not report.
 *
 *   php master/cli/external-uot-usage-reconcile.php [--date YYYY-MM-DD] [--dry-run]
 *
 * For every external_uot_usage_daily row whose UTC day ended at least two
 * hours ago and that is not reconciled yet: client_bytes is the forward's
 * ultra_usage_events total for that day, and
 * max(0, sidecar_bytes - client_bytes - max(1 MiB, 2 % of sidecar_bytes)) is
 * added to the service through the same path as recordUltraUsage (billing
 * cycle roll, traffic counters, quota suspension). Rows are claimed before
 * they are billed, so reruns never bill twice. Runs hourly from cron.
 */

$baseDir = __DIR__ . '/..';

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $baseDir . '/src/' . str_replace('\\', '/', (string) $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\CliOptions;
use Leikwan\Master\Support\ExternalUotUsageLedger;
use Leikwan\Master\Support\ProvisioningService;

try {
    $options = CliOptions::parse(array_slice($argv, 1), ['date'], ['dry-run', 'help']);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(2);
}
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php master/cli/external-uot-usage-reconcile.php [--date YYYY-MM-DD] [--dry-run]\n");
    exit(0);
}
$date = isset($options['date']) ? (string) $options['date'] : null;
if ($date !== null) {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
        fwrite(STDERR, "--date must be YYYY-MM-DD\n");
        exit(2);
    }
}
$dryRun = isset($options['dry-run']);

$envPath = getenv('LEIKWAN_ENV_PATH') ?: $baseDir . '/.env';
if (!is_file($envPath)) {
    fwrite(STDERR, "panel .env not found\n");
    exit(2);
}
$config = new Config($envPath);
$db = new Database($config);
$ledger = new ExternalUotUsageLedger($db, new ProvisioningService($config, $db));

try {
    $results = $ledger->reconcile($date, $dryRun);
} catch (Throwable $exception) {
    fwrite(STDERR, gmdate('Y-m-d\TH:i:s\Z') . ' reconcile_failed ' . $exception->getMessage() . "\n");
    exit(1);
}

$billedRows = 0;
$billedBytes = 0;
foreach ($results as $result) {
    if ((int) $result['extra_bytes'] <= 0) {
        continue;
    }
    $billedRows++;
    $billedBytes += (int) $result['extra_bytes'];
    fwrite(STDOUT, sprintf(
        "%s %s forward=%d date=%s service=%d sidecar=%d client=%d extra=%d%s\n",
        gmdate('Y-m-d\TH:i:s\Z'),
        $dryRun ? 'would_bill' : 'billed',
        (int) $result['forward_id'],
        (string) $result['usage_date'],
        (int) $result['service_id'],
        (int) $result['sidecar_bytes'],
        (int) $result['client_bytes'],
        (int) $result['extra_bytes'],
        ($result['suspended'] ?? false) === true ? ' suspended=traffic_limit' : ''
    ));
}
fwrite(STDOUT, sprintf(
    "%s %s rows=%d extra_rows=%d extra_bytes=%d\n",
    gmdate('Y-m-d\TH:i:s\Z'),
    $dryRun ? 'dry_run' : 'reconciled',
    count($results),
    $billedRows,
    $billedBytes
));
exit(0);
