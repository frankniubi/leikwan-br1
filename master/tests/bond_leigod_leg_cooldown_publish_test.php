<?php
declare(strict_types=1);

// STABILITY: the bond leg cooldown is 30 minutes (BOND_LEG_COOLDOWN_MS). That is appropriate for
// cf_mode, where a "bad leg" is usually a structurally-bad CF edge IP that should stay sidelined.
// But for leigod_mode the bond legs run over CPU-bound 2-vCPU slaves (gost/lguot UoT + WG crypto),
// where a stall is almost always a TRANSIENT CPU spike that clears in seconds. A 30-minute cooldown
// turns a ~2s stall into a half-hour capacity loss on a scarce 3-leg bond -- the documented
// combo-5 flap (apply_leg_cooldown is a FIXED duration in the Rust binary, no escalating backoff;
// the value is fully controlled by --leg-cooldown-ms, no rebuild needed). leg_cooldown_ms must be
// MODE-AWARE: a short, tunable cooldown for leigod_mode, the legacy 30 min for everything else.
// This relies on Part A having synced forwards.backend_mode to 'leigod_mode' so the mode check fires.

namespace Leikwan\Master\Database {
    final class Database {}
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

    function makeService(string $extraEnv): ProvisioningService
    {
        $envPath = tempnam(sys_get_temp_dir(), 'lw-leigod-cooldown-env-');
        if ($envPath === false) {
            fail('failed to create temp env file');
        }
        file_put_contents(
            $envPath,
            "DEFAULT_TLS_CERT_REF=default-edge-cert\n"
            . "BOND_TOKEN_SECRET=test-bond-secret\n"
            . $extraEnv
        );
        return new ProvisioningService(new Config($envPath), new Database());
    }

    $endpoints = [
        ['id' => 184, 'host' => '8.163.90.132', 'public_port' => 7001],
        ['id' => 185, 'host' => '8.163.90.235', 'public_port' => 7001],
    ];

    $forwardBase = [
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

    /** @return int */
    function publishedCooldown(ProvisioningService $service, array $forward, array $endpoints): int
    {
        $ref = new ReflectionMethod($service, 'buildBondClientConfig');
        $ref->setAccessible(true);
        $bonding = $ref->invoke($service, $forward, $endpoints);
        if (!array_key_exists('leg_cooldown_ms', $bonding)) {
            fail('buildBondClientConfig did not publish leg_cooldown_ms');
        }
        return (int) $bonding['leg_cooldown_ms'];
    }

    // 1. leigod_mode with no override -> short DEFAULT leigod cooldown (20s), NOT the 30-min legacy.
    $service = makeService('');
    $leigod = publishedCooldown($service, $forwardBase + ['backend_mode' => 'leigod_mode'], $endpoints);
    if ($leigod !== 20000) {
        fail('leigod_mode must publish the 20s default leg_cooldown_ms, got ' . $leigod);
    }

    // 2. cf_mode keeps the legacy 30-minute isolation (a bad CF edge IP should stay sidelined).
    $cf = publishedCooldown($service, $forwardBase + ['backend_mode' => 'cf_mode'], $endpoints);
    if ($cf !== 1800000) {
        fail('cf_mode must keep the 30-minute leg_cooldown_ms, got ' . $cf);
    }

    // 3. a forward with no backend_mode (legacy) also keeps the 30-minute default (back-compat).
    $legacy = publishedCooldown($service, $forwardBase, $endpoints);
    if ($legacy !== 1800000) {
        fail('legacy forward (no backend_mode) must keep 30-minute leg_cooldown_ms, got ' . $legacy);
    }

    // 4. the leigod cooldown is tunable via BOND_LEIGOD_LEG_COOLDOWN_MS.
    $tuned = makeService("BOND_LEIGOD_LEG_COOLDOWN_MS=15000\n");
    $leigodTuned = publishedCooldown($tuned, $forwardBase + ['backend_mode' => 'leigod_mode'], $endpoints);
    if ($leigodTuned !== 15000) {
        fail('BOND_LEIGOD_LEG_COOLDOWN_MS must override the leigod cooldown, got ' . $leigodTuned);
    }

    echo "ok\n";
}
