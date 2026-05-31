<?php
declare(strict_types=1);

// Behavioral regression (Appendix C.7c): the static sidecar uplink selector only emitted with
// >= 2 candidates (multi-lane failover). Single-lane forwards therefore got NO selector at all,
// so a dead-but-handshaking single lane (one-way return path) had no monitor to detect/restart it.
//
// Behind the flag STATIC_SIDECAR_SELECTOR_SINGLE_LANE_RESTART (default OFF) the panel now emits a
// MONITOR-ONLY selector for single-lane forwards: single_lane_monitor=true and exactly one
// candidate. With the flag OFF, behaviour is unchanged (no selector for a single lane).
//
// This drives the REAL ProvisioningService::staticSidecarUplinkSelectorPayload() with a single
// enabled uplink and asserts both flag states.

namespace Leikwan\Master\Database {
    final class Database
    {
        public function __construct(\Leikwan\Master\Config $config) {}
        public function fetchOne(string $sql, array $params = []): ?array { return null; }
        public function fetchAll(string $sql, array $params = []): array { return []; }
        public function insert(string $table, array $data): int { return 1; }
        public function execute(string $sql, array $params = []): int { return 0; }
        public function transaction(callable $callback) { return $callback($this); }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function call_private(object $instance, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invoke($instance, ...$args);
    }

    $singleUplink = [[
        'enabled' => true,
        'lane' => 'primary',
        'interface_name' => 'lws4c2n19p',
        'peer_address' => '10.240.5.1',
        'forward_combination_id' => 2,
        'sidecar_point_id' => 4,
        'node_id' => 19,
    ]];
    $sidecarServicePort = 9000;
    $forwardId = 59;
    $endpointId = 571;

    // (A) Flag OFF (default): a single lane must NOT produce a selector (unchanged behaviour).
    $envOff = tempnam(sys_get_temp_dir(), 'lw-single-lane-off-');
    file_put_contents($envOff, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");
    $serviceOff = new ProvisioningService(new Config($envOff), new Database(new Config($envOff)));
    $off = call_private($serviceOff, 'staticSidecarUplinkSelectorPayload', $singleUplink, $sidecarServicePort, $forwardId, $endpointId);
    if ($off !== []) {
        fail('with STATIC_SIDECAR_SELECTOR_SINGLE_LANE_RESTART off, a single-lane forward must NOT emit a selector');
    }

    // (B) Flag ON: emit a monitor-only selector with single_lane_monitor=true and exactly 1 candidate.
    $envOn = tempnam(sys_get_temp_dir(), 'lw-single-lane-on-');
    file_put_contents($envOn, "DEFAULT_TLS_CERT_REF=default-edge-cert\nSTATIC_SIDECAR_SELECTOR_SINGLE_LANE_RESTART=true\n");
    $serviceOn = new ProvisioningService(new Config($envOn), new Database(new Config($envOn)));
    $on = call_private($serviceOn, 'staticSidecarUplinkSelectorPayload', $singleUplink, $sidecarServicePort, $forwardId, $endpointId);
    if (!is_array($on) || $on === []) {
        fail('with the flag on, a single-lane forward must emit a monitor-only selector');
    }
    if (($on['enabled'] ?? false) !== true) {
        fail('single-lane monitor selector must be enabled');
    }
    if (($on['single_lane_monitor'] ?? false) !== true) {
        fail('single-lane selector must carry single_lane_monitor=true (cross-agent contract)');
    }
    $candidates = $on['candidates'] ?? null;
    if (!is_array($candidates) || count($candidates) !== 1) {
        fail('single-lane monitor selector must carry exactly 1 candidate, got ' . (is_array($candidates) ? count($candidates) : 'non-array'));
    }
    if (($candidates[0]['interface_name'] ?? '') !== 'lws4c2n19p') {
        fail('single-lane monitor candidate must be the single lane interface');
    }
    if ((int) ($on['selector_port'] ?? 0) <= 0) {
        fail('single-lane monitor selector must still allocate a selector_port');
    }

    // Multi-lane behaviour must be unaffected by the flag: 2 candidates -> full selector, no monitor flag.
    $twoUplinks = $singleUplink;
    $twoUplinks[] = [
        'enabled' => true,
        'lane' => 'backup',
        'interface_name' => 'lws4c2n19b',
        'peer_address' => '10.240.5.5',
        'forward_combination_id' => 2,
        'sidecar_point_id' => 4,
        'node_id' => 19,
    ];
    $multi = call_private($serviceOn, 'staticSidecarUplinkSelectorPayload', $twoUplinks, $sidecarServicePort, $forwardId, $endpointId);
    if (!is_array($multi) || ($multi['enabled'] ?? false) !== true) {
        fail('multi-lane selector must still be emitted');
    }
    if (($multi['single_lane_monitor'] ?? false) !== false) {
        fail('multi-lane selector must NOT be flagged single_lane_monitor');
    }
    if (count($multi['candidates'] ?? []) !== 2) {
        fail('multi-lane selector must carry both candidates');
    }

    echo "ok\n";
}
