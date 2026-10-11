<?php
declare(strict_types=1);

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
use Leikwan\Master\Support\NativeSocks5Probe;

function nativeSocks5CliUsage(): void
{
    fwrite(STDOUT, "Usage: php master/cli/verify-native-socks5.php --source-id ID --line-id ID [--dry-run]\n");
    fwrite(STDOUT, "Credentials are read from NATIVE_SOCKS5_HOST, NATIVE_SOCKS5_PORT, NATIVE_SOCKS5_USER, and NATIVE_SOCKS5_PASSWORD.\n");
}

$options = getopt('', ['source-id:', 'line-id:', 'dry-run', 'help']);
if (isset($options['help'])) {
    nativeSocks5CliUsage();
    exit(0);
}

$sourceId = trim((string) ($options['source-id'] ?? ''));
$lineId = (int) ($options['line-id'] ?? 0);
$host = trim((string) (getenv('NATIVE_SOCKS5_HOST') ?: ''));
$port = (int) (getenv('NATIVE_SOCKS5_PORT') ?: 0);
$user = (string) (getenv('NATIVE_SOCKS5_USER') ?: '');
$password = (string) (getenv('NATIVE_SOCKS5_PASSWORD') ?: '');
$targetHost = trim((string) (getenv('NATIVE_SOCKS5_TARGET_HOST') ?: ''));
$targetPort = (int) (getenv('NATIVE_SOCKS5_TARGET_PORT') ?: 0);

if ($sourceId === '' || preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $sourceId) !== 1
    || $lineId < 1 || $lineId > 45535
    || $host === '' || $port < 1 || $port > 65535
    || $user === '' || $password === ''
    || $targetHost === '' || $targetPort < 1 || $targetPort > 65535) {
    nativeSocks5CliUsage();
    exit(2);
}

$envPath = getenv('LEIKWAN_ENV_PATH') ?: $baseDir . '/.env';
if (!is_file($envPath)) {
    fwrite(STDERR, "native SOCKS5 verification unavailable\n");
    exit(2);
}

try {
    $config = new Config($envPath);
    $db = new Database($config);
    $line = $db->fetchOne(
        'SELECT id, access_class, is_enabled FROM ultra_lines WHERE source_id = :source_id AND line_id = :line_id LIMIT 1',
        ['source_id' => $sourceId, 'line_id' => $lineId]
    );
    if (!is_array($line)) {
        fwrite(STDERR, "native SOCKS5 verification unavailable\n");
        exit(3);
    }

    $result = NativeSocks5Probe::verify($host, $port, $user, $password, $targetHost, $targetPort);
    $state = $result->succeeded ? 'verified' : 'failed';
    if (!isset($options['dry-run'])) {
        $db->execute(
            'UPDATE ultra_lines
             SET native_socks5_state = :state,
                 is_enabled = CASE WHEN :state_for_enable = :verified AND access_class = :native_udp THEN 1 ELSE is_enabled END,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            [
                'state' => $state,
                'state_for_enable' => $state,
                'verified' => 'verified',
                'native_udp' => 'native_udp',
                'id' => (int) $line['id'],
            ]
        );
    }
    echo json_encode([
        'line_id' => $lineId,
        'state' => $state,
        'tcp_succeeded' => $result->tcpSucceeded,
        'udp_succeeded' => $result->udpSucceeded,
        'error_code' => $result->errorCode,
        'dry_run' => isset($options['dry-run']),
    ], JSON_UNESCAPED_SLASHES) . "\n";
    exit($result->succeeded ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, "native SOCKS5 verification unavailable\n");
    exit(4);
}
