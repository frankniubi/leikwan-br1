<?php
declare(strict_types=1);

/**
 * Keep the standalone external UoT sidecar hosts in step with the panel.
 *
 *   php master/cli/external-uot-standalone-sync.php [--hosts-file FILE] [--state-dir DIR]
 *       [--max-age SECONDS] [--force] [--dry-run]
 *
 * The standalone hosts (not sidecar points, not pool nodes) used to get their
 * /etc/leikwan-external-uot.json by hand, so every forward created after the
 * last hand push was refused with "forward is not allowed". This renders each
 * host's forwards (ExternalUotConfigController::renderForHost) and pipes
 * {"forwards": ..., "remove": [...]} over SSH to
 * sidecar-agent/external_uot_forward_merge.py, which the host's
 * authorized_keys forces for this key. The merge adds or updates
 * forwards.<id> entries, deletes the listed non-live forwards and hot-reloads
 * the sidecar.
 *
 * Every run also calls the same forced command with "usage"
 * (SSH_ORIGINAL_COMMAND=usage), which prints the sidecar's per-forward UDP
 * byte counters; they are recorded as source "standalone:<host>". A failed
 * pull is only logged.
 *
 * A host is pushed when its rendered document changed since the last good push,
 * or the last good push is older than --max-age (default 3600 s), or --force.
 * Runs from root's crontab every minute; prints one line per push or failure.
 *
 * Hosts file (JSON list):
 *   [{"host": "103.246.219.39", "ssh_port": 27789, "ssh_user": "root"}]
 * plus optional "ssh_key" and "known_hosts" per host; defaults come from the
 * EXTERNAL_UOT_STANDALONE_SSH_KEY / EXTERNAL_UOT_STANDALONE_KNOWN_HOSTS env.
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
use Leikwan\Master\Controllers\ExternalUotConfigController;
use Leikwan\Master\Support\CliOptions;

function standaloneSyncLog(string $message): void
{
    fwrite(STDOUT, gmdate('Y-m-d\TH:i:s\Z') . ' ' . $message . "\n");
}

/**
 * @param array<string, mixed> $entry
 * @return list<string>
 */
function standaloneSshCommand(array $entry, string $host, string $defaultKey, string $defaultKnownHosts, string $remoteCommand): array
{
    $port = (int) ($entry['ssh_port'] ?? 22);
    $user = (string) ($entry['ssh_user'] ?? 'root');

    return [
        'ssh', '-i', (string) ($entry['ssh_key'] ?? $defaultKey),
        '-p', (string) ($port > 0 && $port < 65536 ? $port : 22),
        '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', '-o', 'ServerAliveInterval=10',
        '-o', 'StrictHostKeyChecking=yes',
        '-o', 'UserKnownHostsFile=' . (string) ($entry['known_hosts'] ?? $defaultKnownHosts),
        '-o', 'IdentitiesOnly=yes',
        $user . '@' . $host,
        $remoteCommand,
    ];
}

/**
 * @param list<string> $command
 * @return array{0: int, 1: string, 2: string}|null exit code, stdout, stderr; null when ssh could not start
 */
function standaloneSshRun(array $command, string $stdin): ?array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return null;
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

try {
    $options = CliOptions::parse(
        array_slice($argv, 1),
        ['hosts-file', 'state-dir', 'max-age'],
        ['force', 'dry-run', 'help']
    );
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(2);
}
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php master/cli/external-uot-standalone-sync.php [--hosts-file FILE] [--state-dir DIR] [--max-age SECONDS] [--force] [--dry-run]\n");
    exit(0);
}

$envPath = getenv('LEIKWAN_ENV_PATH') ?: $baseDir . '/.env';
if (!is_file($envPath)) {
    fwrite(STDERR, "panel .env not found\n");
    exit(2);
}
$config = new Config($envPath);

$hostsFile = (string) ($options['hosts-file'] ?? $config->get('EXTERNAL_UOT_STANDALONE_HOSTS_FILE', '/etc/leikwan-uot-standalone-hosts.json'));
$stateDir = rtrim((string) ($options['state-dir'] ?? $config->get('EXTERNAL_UOT_STANDALONE_STATE_DIR', '/var/lib/leikwan-uot-standalone-sync')), '/');
$maxAge = max(60, (int) ($options['max-age'] ?? 3600));
$force = isset($options['force']);
$dryRun = isset($options['dry-run']);
$defaultKey = (string) $config->get('EXTERNAL_UOT_STANDALONE_SSH_KEY', '/root/.ssh/lk_uot_sync_ed25519');
$defaultKnownHosts = (string) $config->get('EXTERNAL_UOT_STANDALONE_KNOWN_HOSTS', '/root/.ssh/lk_uot_sync_known_hosts');

$hosts = json_decode((string) @file_get_contents($hostsFile), true);
if (!is_array($hosts) || !array_is_list($hosts) || $hosts === []) {
    fwrite(STDERR, "hosts file missing or not a JSON list: $hostsFile\n");
    exit(2);
}
if (!is_dir($stateDir) && !mkdir($stateDir, 0700, true) && !is_dir($stateDir)) {
    fwrite(STDERR, "cannot create state dir $stateDir\n");
    exit(2);
}
$lock = fopen($stateDir . '/sync.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // the previous run is still pushing
}

$controller = new ExternalUotConfigController($config);
$failures = 0;
foreach ($hosts as $entry) {
    $host = trim((string) ($entry['host'] ?? ''));
    if ($host === '' || preg_match('/^[A-Za-z0-9.:_-]{1,253}$/', $host) !== 1) {
        standaloneSyncLog('skip invalid host entry');
        $failures++;
        continue;
    }

    if (!$dryRun) {
        $pulled = standaloneSshRun(standaloneSshCommand($entry, $host, $defaultKey, $defaultKnownHosts, 'usage'), '');
        $usage = $pulled !== null && $pulled[0] === 0 ? json_decode(trim($pulled[1]), true) : null;
        if (is_array($usage)) {
            $controller->ingestUsage('standalone:' . $host, $usage);
        } else {
            $detail = $pulled === null ? 'ssh_spawn_failed' : sprintf('exit=%d %s', $pulled[0], trim(substr($pulled[2], 0, 300)));
            standaloneSyncLog(sprintf('%s usage_pull_failed %s', $host, str_replace("\n", ' ', $detail)));
        }
    }

    try {
        $document = $controller->renderForHost($host);
    } catch (Throwable $exception) {
        standaloneSyncLog("$host render_failed " . $exception->getMessage());
        $failures++;
        continue;
    }
    // buildPayload() returns forwards as an object so an empty set stays a JSON map.
    $forwards = $document['forwards'] ?? [];
    $forwards = is_object($forwards) ? get_object_vars($forwards) : $forwards;
    $remove = array_values(array_map('intval', (array) ($document['remove'] ?? [])));
    if (!is_array($forwards) || ($forwards === [] && $remove === [])) {
        continue; // nothing targets this host; never push an empty set
    }
    ksort($forwards, SORT_NUMERIC);
    $payload = json_encode(['forwards' => (object) $forwards, 'remove' => $remove], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $digest = hash('sha256', $payload);
    $statePath = $stateDir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $host) . '.json';
    $state = json_decode((string) @file_get_contents($statePath), true);
    $fresh = is_array($state) && ($state['digest'] ?? '') === $digest
        && time() - (int) ($state['at'] ?? 0) < $maxAge;
    if ($fresh && !$force) {
        continue;
    }
    if ($dryRun) {
        standaloneSyncLog(sprintf('%s would_push forwards=%d remove=%d digest=%s', $host, count($forwards), count($remove), substr($digest, 0, 12)));
        continue;
    }

    $pushed = standaloneSshRun(standaloneSshCommand($entry, $host, $defaultKey, $defaultKnownHosts, 'external-uot-forward-merge'), $payload);
    if ($pushed === null) {
        standaloneSyncLog("$host ssh_spawn_failed");
        $failures++;
        continue;
    }
    [$exit, $stdout, $stderr] = $pushed;

    $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout)), static fn ($line) => $line !== ''));
    $result = json_decode((string) end($lines), true);
    if ($exit !== 0 || !is_array($result) || ($result['ok'] ?? false) !== true) {
        $detail = is_array($result) ? (string) ($result['error'] ?? '') : trim(substr($stderr, 0, 300));
        standaloneSyncLog(sprintf('%s push_failed exit=%d %s', $host, $exit, str_replace("\n", ' ', $detail)));
        $failures++;
        continue;
    }
    file_put_contents($statePath, json_encode(['digest' => $digest, 'at' => time()]), LOCK_EX);
    if (($result['changed'] ?? false) === true) {
        standaloneSyncLog(sprintf(
            '%s pushed added=[%s] updated=[%s] removed=[%s] forwards=%d',
            $host,
            implode(',', (array) ($result['added'] ?? [])),
            implode(',', (array) ($result['updated'] ?? [])),
            implode(',', (array) ($result['removed'] ?? [])),
            (int) ($result['forwards'] ?? 0)
        ));
    }
}

exit($failures > 0 ? 1 : 0);
