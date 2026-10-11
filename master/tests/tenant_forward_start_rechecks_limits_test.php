<?php
declare(strict_types=1);

/**
 * Starting a paused forward must pass the limits a create would.
 *
 * setForwardEnabled(true) used to flip enabled = 1 with no checks, so a tenant
 * could pause N forwards, create N more and start the paused ones (beyond
 * forward_limit), restart a balance-suspended forward at zero balance, and
 * keep using a route the plan no longer grants. Expired services also slipped
 * through because the lock compared the raw status column, which never
 * becomes 'expired'.
 */

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<string, mixed> */
        public static array $state = [];

        /** @var array<int, string> */
        public array $executed = [];

        public function fetchOne(string $sql, array $params = []): ?array
        {
            $s = self::$state;
            if (str_contains($sql, 'COUNT(*) AS active_count')) {
                return ['active_count' => $s['active_count']];
            }
            if (str_contains($sql, 'max_forward_rule_quantity')) {
                return ['max_forward_rule_quantity' => $s['limit']];
            }
            if (str_contains($sql, 'FROM forwards')) {
                return [
                    'id' => 170,
                    'service_id' => 30,
                    'enabled' => 0,
                    'api_revision' => 7,
                    'user_deleted_at' => null,
                    'balance_suspended_at' => $s['balance_suspended_at'],
                    'forward_route_id' => 0,
                    'forward_combination_id' => null,
                    'native_outbound_enabled' => 0,
                    'native_direction_key' => null,
                    'target_host' => '203.0.113.10',
                ];
            }
            if (str_contains($sql, 'FROM services')) {
                return ['id' => 30, 'user_id' => 5, 'status' => 'active', 'expire_at' => $s['expire_at'], 'plan_id' => 0, 'billing_mode' => $s['billing_mode'] ?? 'monthly_bundle'];
            }
            if (str_contains($sql, 'FROM users')) {
                return ['id' => 5, 'role' => 'tenant', 'status' => 'active', 'wallet_balance' => $s['wallet'], 'feature_flags_json' => ''];
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            return [];
        }

        public function insert(string $table, array $data): int
        {
            return 1;
        }

        public function execute(string $sql, array $params = []): int
        {
            $this->executed[] = $sql;
            return 1;
        }

        public function transaction(callable $callback)
        {
            return $callback($this);
        }
    }
}

namespace {

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    // The stub rows are deliberately minimal; buildClientConfig's missing-key
    // warnings past the guards are irrelevant here.
    error_reporting(E_ALL & ~E_WARNING);
    $envPath = tempnam(sys_get_temp_dir(), 'lk-start-');
    file_put_contents($envPath, "AGENT_API_URL=https://panel.example.com\n");
    $policy = ['user_id' => 5, 'feature' => 'allow_manage_forwards'];
    $base = ['active_count' => 1, 'limit' => 2, 'balance_suspended_at' => null, 'wallet' => '10.00', 'expire_at' => '2099-01-01 00:00:00'];

    $refusal = static function (array $state) use ($envPath, $policy): ?string {
        Database::$state = $state;
        $db = new Database();
        try {
            (new ProvisioningService(new Config($envPath), $db))->setForwardEnabled(170, true, 'tenant:5', 7, $policy);
        } catch (ApiException $exception) {
            foreach ($db->executed as $sql) {
                if (str_contains($sql, 'SET enabled = 1')) {
                    fail('a refused start still executed the enable update');
                }
            }
            return $exception->getMessage();
        } catch (\Throwable $throwable) {
            // Past the guards, the stub cannot build a client config; that is
            // the "allowed" outcome for this test.
            return null;
        }
        return null;
    };

    $code = $refusal(['active_count' => 2] + $base);
    if ($code !== 'forward_quota_exceeded') {
        fail('starting a paused forward at the forward limit must be refused, got ' . var_export($code, true));
    }
    $code = $refusal(['balance_suspended_at' => '2026-10-01 00:00:00', 'wallet' => '0.00'] + $base);
    if ($code !== 'insufficient_balance') {
        fail('a balance-suspended forward must not restart at zero balance, got ' . var_export($code, true));
    }
    $code = $refusal(['expire_at' => '2020-01-01 00:00:00'] + $base);
    if ($code !== 'service_inactive') {
        fail('an expired service must not start forwards, got ' . var_export($code, true));
    }
    $code = $refusal(['billing_mode' => 'metered', 'wallet' => '0.00'] + $base);
    if ($code !== 'insufficient_balance') {
        fail('a metered service must not start forwards at zero balance, got ' . var_export($code, true));
    }
    $code = $refusal(['wallet' => '0.00'] + $base);
    if ($code !== null) {
        fail('a prepaid monthly bundle with an empty wallet must still start forwards, got ' . var_export($code, true));
    }
    $code = $refusal($base);
    if ($code !== null) {
        fail('a start within limits must pass the guards, got ' . var_export($code, true));
    }

    // Tenant create: private manual targets are refused; native is exempt.
    $create = static function (array $payload, array $state) use ($envPath, $policy): ?array {
        Database::$state = $state;
        try {
            (new ProvisioningService(new Config($envPath), new Database()))->createForward(30, $payload, 'user_api:5', null, null, 64, $policy);
        } catch (ApiException $exception) {
            return [$exception->getMessage(), $exception->details()['fields'] ?? null];
        } catch (\Throwable $throwable) {
            return null;
        }
        return null;
    };
    foreach (['10.0.0.5', '172.17.0.1', '192.168.1.1', 'fd00::1'] as $privateHost) {
        $result = $create(['target_source' => 'manual', 'target_host' => $privateHost, 'target_port' => 443], $base);
        if ($result !== ['validation_failed', ['target.host' => 'private_range']]) {
            fail('tenant create to ' . $privateHost . ' must be refused as private_range, got ' . json_encode($result));
        }
    }
    $result = $create(['target_source' => 'manual', 'target_host' => '10.0.0.5', 'target_port' => 443, 'native_outbound' => true], $base);
    if (($result[1] ?? null) === ['target.host' => 'private_range']) {
        fail('native outbound must be exempt from the private-range block');
    }
    $result = $create(['target_source' => 'manual', 'target_host' => '203.0.113.10', 'target_port' => 443], ['billing_mode' => 'metered', 'wallet' => '0.00'] + $base);
    if (($result[0] ?? null) !== 'insufficient_balance') {
        fail('a metered service must not create forwards at zero balance, got ' . json_encode($result));
    }

    @unlink($envPath);
    echo "ok start rechecks limits\n";
}
