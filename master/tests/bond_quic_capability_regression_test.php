<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {

    final class Database
    {
    }
}

namespace {

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }

        $relative = substr($class, strlen($prefix));
        if ($relative === false) {
            return;
        }

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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-bond-quic-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $service = new ProvisioningService(new Config($envPath), new Database());

    $oldNodeConfig = json_encode([
        'supports_quic' => true,
        'bond_protocols' => ['tcp', 'quic'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $newNodeConfig = json_encode([
        'supports_quic' => true,
        'bond_protocols' => ['tcp', 'quic'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $missingProtocols = json_encode([
        'supports_quic' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tcpOuterTransport = json_encode([
        'supports_quic' => true,
        'bond_protocols' => ['tcp', 'quic'],
        'wireguard_outer_transport' => 'tcp',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $oldAllowed = (bool) call_private($service, 'nodeSupportsBondQuic', $oldNodeConfig, '2026.04.07.1');
    if ($oldAllowed) {
        fail('expected old slave agent capability to be rejected for bond QUIC');
    }

    $newAllowed = (bool) call_private($service, 'nodeSupportsBondQuic', $newNodeConfig, '2026.04.15.2');
    if (!$newAllowed) {
        fail('expected trusted slave agent capability to be accepted for bond QUIC');
    }

    $missingProtocolsAllowed = (bool) call_private($service, 'nodeSupportsBondQuic', $missingProtocols, '2026.04.15.2');
    if ($missingProtocolsAllowed) {
        fail('expected missing bond_protocols to disable bond QUIC');
    }

    $tcpOuterTransportAllowed = (bool) call_private($service, 'nodeSupportsBondQuic', $tcpOuterTransport, '2026.04.15.2');
    if ($tcpOuterTransportAllowed) {
        fail('expected TCP outer transport nodes to disable bond QUIC');
    }

    $forward = [
        'client_mode' => 'bond',
        'public_port' => 7000,
        'path_prefix' => 'dcfa28eea4bf6b3b',
        'sni_host' => 'br1.nbwly.com',
        'cdn_host' => 'br1.nbwly.com',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp+udp',
        'bond_remote_host' => '161.248.136.183',
        'bond_remote_port' => 14961,
    ];

    $oldNode = [
        'id' => 5,
        'agent_version' => '2026.04.07.1',
        'agent_config_json' => $oldNodeConfig,
    ];
    $oldPayload = call_private($service, 'publishTaskPayloadForForward', $forward, $oldNode, 169);
    if (($oldPayload['leg_relay_protocols'] ?? null) !== []) {
        fail('expected stale slave capability to produce no QUIC relay protocols');
    }

    $newNode = [
        'id' => 5,
        'agent_version' => '2026.04.15.2',
        'agent_config_json' => $newNodeConfig,
    ];
    $newPayload = call_private($service, 'publishTaskPayloadForForward', $forward, $newNode, 169);
    if (($newPayload['leg_relay_protocols'] ?? null) !== ['quic']) {
        fail('expected trusted slave capability to produce QUIC relay protocols');
    }

    $tcpTransportNode = [
        'id' => 5,
        'agent_version' => '2026.04.15.2',
        'agent_config_json' => $tcpOuterTransport,
    ];
    $tcpTransportPayload = call_private($service, 'publishTaskPayloadForForward', $forward, $tcpTransportNode, 169);
    if (($tcpTransportPayload['leg_relay_protocols'] ?? null) !== []) {
        fail('expected TCP outer transport nodes to publish no QUIC relay protocols');
    }

    $adaptiveJoinTimeout = call_private($service, 'adaptiveBondJoinTimeoutConfig', [
        [
            'node_agent_config_json' => $tcpOuterTransport,
            'node_agent_version' => '2026.04.15.2',
        ],
        [
            'node_agent_config_json' => $tcpOuterTransport,
            'node_agent_version' => '2026.04.15.2',
        ],
        [
            'node_agent_config_json' => $tcpOuterTransport,
            'node_agent_version' => '2026.04.15.2',
        ],
        [
            'node_agent_config_json' => $tcpOuterTransport,
            'node_agent_version' => '2026.04.15.2',
        ],
        [
            'node_agent_config_json' => $tcpOuterTransport,
            'node_agent_version' => '2026.04.15.2',
        ],
    ]);
    if (($adaptiveJoinTimeout['join_timeout_ms'] ?? null) !== 400) {
        fail('expected WSS-only bond legs to retain the configured join timeout');
    }

    $provisioningPath = __DIR__ . '/../src/Support/ProvisioningService.php';
    $provisioningSource = file_get_contents($provisioningPath);
    if (!is_string($provisioningSource) || $provisioningSource === '') {
        fail('failed to read ProvisioningService source');
    }
    if (!str_contains($provisioningSource, "n.agent_version AS node_agent_version")) {
        fail('expected buildClientConfig query to select node_agent_version');
    }
    if (!str_contains($provisioningSource, "'supports_quic_leg' => \$this->nodeSupportsBondQuic(")) {
        fail('expected supports_quic_leg to be derived from nodeSupportsBondQuic');
    }

    $slaveAgentPath = __DIR__ . '/../../slave-agent/slave_agent.py';
    $slaveAgentSource = file_get_contents($slaveAgentPath);
    if (!is_string($slaveAgentSource) || $slaveAgentSource === '') {
        fail('failed to read slave_agent.py source');
    }
    if (!preg_match('/AGENT_VERSION = "([^"]+)"/', $slaveAgentSource, $slaveVersionMatch)) {
        fail('failed to read slave_agent.py AGENT_VERSION');
    }
    if (!str_contains($slaveAgentSource, '"supports_quic": False')) {
        fail('expected slave-agent default supports_quic to be False');
    }
    if (!str_contains($slaveAgentSource, '"bond_protocols": ["tcp"]')) {
        fail('expected slave-agent default bond_protocols to be tcp-only');
    }

    $versionsPath = __DIR__ . '/../../artifacts/versions.json';
    $versions = json_decode((string) file_get_contents($versionsPath), true);
    if (!is_array($versions)) {
        fail('failed to parse artifacts/versions.json');
    }
    if (($versions['slave_agent']['version'] ?? null) !== $slaveVersionMatch[1]) {
        fail('expected artifacts/versions.json slave_agent.version to match slave_agent.py AGENT_VERSION');
    }

    echo "ok\n";
}
