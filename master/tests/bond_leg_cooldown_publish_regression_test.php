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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-bond-cooldown-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents(
        $envPath,
        "DEFAULT_TLS_CERT_REF=default-edge-cert\n"
        . "BOND_TOKEN_SECRET=test-bond-secret\n"
        . "BOND_WARMUP_TRIGGER_BYTES=65536\n"
        . "BOND_WARMUP_SLICE_BYTES=65536\n"
        . "BOND_WARMUP_LEG_DELAY_MS=20\n"
        . "BOND_BULK_PROMOTE_BYTES=262144\n"
        . "BOND_LEG_COOLDOWN_MS=1800000\n"
    );

    $service = new ProvisioningService(new Config($envPath), new Database());

    $forward = [
        'client_mode' => 'bond',
        'public_port' => 7000,
        'path_prefix' => 'a43547b6350f72cd',
        'sni_host' => 'br1.nbwly.com',
        'cdn_host' => 'br1.nbwly.com',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp+udp',
        'bond_remote_host' => '161.248.136.183',
        'bond_remote_port' => 14961,
        'target_host' => '193.227.109.82',
        'target_port' => 5201,
        'service_id' => 1,
        'id' => 26,
        'line_client_name' => 'sh36',
        'bond_ingress_mode' => 'plain_tcp_mux',
    ];

    $endpoints = [
        ['id' => 184, 'host' => '8.163.90.132', 'public_port' => 7001],
        ['id' => 185, 'host' => '8.163.90.235', 'public_port' => 7001],
    ];

    $bonding = call_private($service, 'buildBondClientConfig', $forward, $endpoints);

    if (($bonding['warmup_trigger_bytes'] ?? null) !== 65536) {
        fail('expected warmup_trigger_bytes to be published');
    }
    if (($bonding['warmup_slice_bytes'] ?? null) !== 65536) {
        fail('expected warmup_slice_bytes to be published');
    }
    if (($bonding['warmup_leg_delay_ms'] ?? null) !== 20) {
        fail('expected warmup_leg_delay_ms to be published');
    }
    if (($bonding['bulk_promote_bytes'] ?? null) !== 262144) {
        fail('expected bulk_promote_bytes to be published');
    }
    if (($bonding['leg_cooldown_ms'] ?? null) !== 1800000) {
        fail('expected leg_cooldown_ms to publish 30-minute isolation');
    }

    echo "ok\n";
}
