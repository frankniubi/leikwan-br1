<?php
declare(strict_types=1);

// Behavioral regression (Appendix C.6f): the sidecar heartbeat carries wireguard_peer_state.
// Storing it is not enough — the panel must CONSUME it and flag a one-way return-path
// failure: a peer that is transmitting (tx_bytes>0) but receiving nothing (rx_bytes==0)
// while its handshake is FRESH (handshake_age_seconds < FRESH_THRESHOLD) is sending into a
// black hole (the WG return path is dead). This test drives the REAL
// SidecarAgentController::evaluateWireguardPeerStates() and asserts:
//   - the one-way peer is flagged degraded with reason 'one_way_return_path',
//   - a healthy bidirectional peer is NOT flagged,
//   - a peer with a STALE handshake is NOT flagged one-way (no fresh evidence),
//   - the cross-agent field contract keys are honoured exactly.

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
    use Leikwan\Master\Controllers\SidecarAgentController;
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-wgpeer-env-');
    file_put_contents($envPath, "SIDECAR_WG_PEER_FRESH_HANDSHAKE_SECONDS=180\n");
    $config = new Config($envPath);
    $db = new Database($config);
    $provisioning = new ProvisioningService($config, $db);
    $controller = new SidecarAgentController($config, $db, $provisioning);

    // Cross-agent field contract: interface_name, peer_public_key, rx_bytes, tx_bytes, handshake_age_seconds
    $peerStates = [
        // (A) one-way: tx>0, rx==0, fresh handshake -> MUST be flagged degraded.
        [
            'interface_name' => 'lws4c2n19p',
            'peer_public_key' => 'oneway-peer-key',
            'rx_bytes' => 0,
            'tx_bytes' => 4_500_000,
            'handshake_age_seconds' => 12,
        ],
        // (B) healthy bidirectional -> MUST NOT be flagged.
        [
            'interface_name' => 'lws4c2n19b',
            'peer_public_key' => 'healthy-peer-key',
            'rx_bytes' => 9_900_000,
            'tx_bytes' => 4_500_000,
            'handshake_age_seconds' => 8,
        ],
        // (C) tx>0, rx==0 but STALE handshake -> NOT one-way (no fresh handshake evidence).
        [
            'interface_name' => 'lws9c1n3p',
            'peer_public_key' => 'stale-peer-key',
            'rx_bytes' => 0,
            'tx_bytes' => 1_000_000,
            'handshake_age_seconds' => 999,
        ],
        // (D) tx>0, rx==0 but handshake_age_seconds null -> NOT flagged (no handshake at all).
        [
            'interface_name' => 'lws9c1n4p',
            'peer_public_key' => 'nohandshake-peer-key',
            'rx_bytes' => 0,
            'tx_bytes' => 1_000_000,
            'handshake_age_seconds' => null,
        ],
    ];

    $result = call_private($controller, 'evaluateWireguardPeerStates', $peerStates);
    if (!is_array($result)) {
        fail('evaluateWireguardPeerStates must return an array');
    }

    // Index the result by peer_public_key for assertions.
    $byKey = [];
    foreach ($result as $row) {
        $byKey[(string) ($row['peer_public_key'] ?? '')] = $row;
    }

    $a = $byKey['oneway-peer-key'] ?? null;
    if (!is_array($a)) {
        fail('one-way peer missing from evaluation result');
    }
    if (($a['healthy'] ?? true) !== false) {
        fail('one-way peer (tx>0,rx==0,fresh) must be marked NOT healthy');
    }
    if (($a['degraded'] ?? false) !== true) {
        fail('one-way peer must be marked degraded');
    }
    if (($a['reason'] ?? '') !== 'one_way_return_path') {
        fail("one-way peer reason must be 'one_way_return_path', got " . var_export($a['reason'] ?? null, true));
    }
    if (($a['interface_name'] ?? '') !== 'lws4c2n19p') {
        fail('one-way peer must carry through its interface_name');
    }

    $b = $byKey['healthy-peer-key'] ?? null;
    if (!is_array($b) || ($b['degraded'] ?? false) !== false || ($b['healthy'] ?? false) !== true) {
        fail('healthy bidirectional peer must NOT be flagged');
    }

    $c = $byKey['stale-peer-key'] ?? null;
    if (!is_array($c) || ($c['degraded'] ?? false) !== false) {
        fail('peer with stale handshake must NOT be flagged one-way (no fresh evidence)');
    }

    $d = $byKey['nohandshake-peer-key'] ?? null;
    if (!is_array($d) || ($d['degraded'] ?? false) !== false) {
        fail('peer with null handshake_age_seconds must NOT be flagged one-way');
    }

    // There must be exactly ONE degraded one-way peer overall.
    $degradedCount = 0;
    foreach ($result as $row) {
        if (($row['degraded'] ?? false) === true) {
            $degradedCount++;
        }
    }
    if ($degradedCount !== 1) {
        fail('expected exactly 1 degraded one-way peer, got ' . $degradedCount);
    }

    echo "ok\n";
}
