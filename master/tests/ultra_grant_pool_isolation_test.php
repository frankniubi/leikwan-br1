<?php
declare(strict_types=1);

/*
 * A forward whose external UoT origin is managed by the pool, with no healthy
 * pool node, must lose only its own grant. The pool used to be assigned for
 * the whole batch, so one late heartbeat withdrew every grant on the device
 * (2026-10-31 04:24 UTC: an 80 s heartbeat gap took the SHIXP carrier's grant
 * on a PVE host that does not use the pool at all).
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', (string) $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Support\ExternalUoTPool;
use Leikwan\Master\Support\LeigodTokenBroker;
use Leikwan\Master\Support\UltraMaterialService;
use Leikwan\Master\Support\UltraMode;

function failPoolIsolation(string $message): never
{
    fwrite(STDERR, 'FAIL ' . $message . PHP_EOL);
    exit(1);
}

function assertPoolIsolation(bool $condition, string $message): void
{
    if (!$condition) {
        failPoolIsolation($message);
    }
}

$now = time();
$stateDir = sys_get_temp_dir() . '/lw-pool-isolation-' . bin2hex(random_bytes(6));
putenv('EXTERNAL_UOT_POOL_STATE_DIR=' . $stateDir);
putenv('EXTERNAL_UOT_POOL_TTL_SECONDS=45');
putenv('EXTERNAL_UOT_POOL_JSON=' . json_encode([[
    'id' => 'tyo-test',
    'target' => 'pool-node.test:7443',
    'secret' => str_repeat('s', 32),
    'origins' => ['origin.test:7443'],
    'reliable' => true,
]]));
$envPath = tempnam(sys_get_temp_dir(), 'lw-pool-isolation-env-');
if ($envPath === false) {
    failPoolIsolation('failed to create env file');
}
file_put_contents(
    $envPath,
    "ULTRA_TOKEN_KEY=token-key-for-pool-isolation\n"
    . "ULTRA_SOURCE_CREDENTIALS_JSON={\"source-a\":\"source-a-secret\"}\n"
);
$logFile = $stateDir . '-errors.log';
ini_set('error_log', $logFile);

try {
    $config = new Config($envPath);
    $materials = new UltraMaterialService($config, null, null, static fn(): int => $now);
    $ticket = $materials->issueUploadTicket('source-a', [453]);
    $accepted = $materials->acceptSourceUpload('source-a', $ticket, [[
        'line_id' => 453,
        'generation' => 'material-generation-1',
        'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1200),
        'user' => 'line-user',
        'credential' => '1234567890123456',
        'relays' => [['ip' => '203.0.113.20', 'port' => 5438]],
        'probe' => ['ok' => true],
    ]]);
    assertPoolIsolation($accepted === [453], 'line material was not accepted');

    $forward = static function (int $id, ?array $externalUot): array {
        $forward = [
            'forward_id' => $id,
            'service_id' => 5,
            'service_status' => 'active',
            'backend_mode' => 'ultra',
            'target' => '198.51.100.' . $id . ':443',
            'transport' => 'tcp+udp',
            'speed_limit_mbps' => 0,
            '_ultra_line_ids' => [453],
            '_ultra_lines' => [[
                'line_id' => 453,
                'source_id' => 'source-a',
                'ingress_zone' => 'shanghai',
                'destination_zone' => 'tokyo',
            ]],
            '_ultra_line_direction' => 'Shanghai to Japan',
            '_ultra_destination_zone' => 'tokyo',
            '_ultra_allowed_ingress_zones' => ['shanghai'],
            '_ultra_fallback_policy' => 'detected_then_any',
        ];
        if ($externalUot !== null) {
            $forward['external_uot'] = $externalUot;
        }
        return $forward;
    };
    $managed = [
        'enabled' => true,
        'sidecar_target' => 'origin.test:7443',
        'token' => 'external-uot-test-token',
        'queue_packets' => 512,
        'keepalive_seconds' => 15,
        'reconnect_grace_seconds' => 45,
        'frame_payload_max' => 4096,
    ];
    $forwards = [$forward(25, $managed), $forward(26, null)];

    // No heartbeat yet: the pool has no healthy node for forward 25's origin.
    $broker = new LeigodTokenBroker($config, null, $materials, static fn(): int => $now);
    $grants = $broker->grantsForClientForwards(3, 'device-pool-isolation', $forwards, true);
    assertPoolIsolation(!isset($grants['25']), 'forward on an unavailable pool node must not be granted');
    $plain = $grants['26'] ?? null;
    assertPoolIsolation(is_array($plain), 'a forward that does not use the pool lost its grant to the pool outage');
    assertPoolIsolation(($plain['protocol'] ?? '') === UltraMode::POLICY_PROTOCOL
        && trim((string) ($plain['session_ticket'] ?? '')) !== '', 'surviving grant is incomplete');
    $log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
    assertPoolIsolation(str_contains($log, 'ultra_grant_withheld client=3 forward=25 reason=external_uot_pool_unavailable'),
        'withheld grant was not logged');

    // Healthy node: both forwards are granted again.
    (new ExternalUoTPool($config))->heartbeat('tyo-test', true, str_repeat('c', 64), $now);
    $grants = $broker->grantsForClientForwards(3, 'device-pool-isolation-2', $forwards, true);
    assertPoolIsolation(is_array($grants['25'] ?? null) && is_array($grants['26'] ?? null),
        'a healthy pool must grant both forwards');
} finally {
    foreach (glob($stateDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($stateDir);
    @unlink($logFile);
    @unlink($envPath);
}

echo "ultra grant pool isolation: ok\n";
