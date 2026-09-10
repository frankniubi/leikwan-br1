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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-bond-latency-first-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }

    file_put_contents(
        $envPath,
        "DEFAULT_TLS_CERT_REF=default-edge-cert\n"
        . "BOND_TOKEN_SECRET=test-bond-secret\n"
        . "BOND_POLICY_MODE=latency_first\n"
        . "BOND_UDP_PRIMARY_ONLY=true\n"
        . "BOND_PRIMARY_RESELECT_ON_FAILURE_ONLY=true\n"
        . "BOND_CF_PRIMARY_SELECTION_MODE=real_path\n"
    );

    $service = new ProvisioningService(new Config($envPath), new Database());

    $forward = [
        'client_mode' => 'bond',
        'public_port' => 7000,
        'path_prefix' => '07289d1f5ed25399',
        'sni_host' => 'nrt1.nbwly.com',
        'cdn_host' => 'nrt1.nbwly.com',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp+udp',
        'backend_mode' => 'cf_mode',
        'bond_remote_host' => '185.220.238.143',
        'bond_remote_port' => 2053,
        'target_host' => '185.220.238.143',
        'target_port' => 5201,
        'service_id' => 2,
        'id' => 47,
        'line_client_name' => 'sh36',
        'bond_ingress_mode' => 'plain_tcp_mux',
    ];

    $endpoints = [
        ['id' => 316, 'host' => '47.116.11.144', 'public_port' => 7000],
        ['id' => 317, 'host' => '47.116.104.15', 'public_port' => 7000],
    ];

    $bonding = call_private($service, 'buildBondClientConfig', $forward, $endpoints);

    if (($bonding['policy_mode'] ?? null) !== 'latency_first') {
        fail('expected policy_mode latency_first');
    }
    if (($bonding['udp_primary_only'] ?? null) !== true) {
        fail('expected udp_primary_only true');
    }
    if (($bonding['primary_reselect_on_failure_only'] ?? null) !== true) {
        fail('expected primary_reselect_on_failure_only true');
    }
    if (($bonding['cf_primary_selection_mode'] ?? null) !== 'real_path') {
        fail('expected cf_primary_selection_mode real_path');
    }

    echo "ok\n";
}
