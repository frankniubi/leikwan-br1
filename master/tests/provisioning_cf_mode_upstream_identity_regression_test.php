<?php
declare(strict_types=1);

namespace Leikwan\Master {
    final class Config
    {
        /** @var array<string, string> */
        private array $values = [];

        public function __construct(string $envPath)
        {
            if (!is_file($envPath)) {
                return;
            }
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $trimmed = trim((string) $line);
                if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $trimmed, 2);
                $this->values[trim($key)] = trim($value);
            }
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->values[$key] ?? $default;
        }
    }
}

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        /**
         * @param array<int, array<string, mixed>> $rows
         */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /**
         * @param array<string, mixed> $params
         * @return array<string, mixed>|null
         */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

            if (str_contains($normalized, 'SELECT * FROM cf_location_groups WHERE id = :id LIMIT 1')) {
                foreach ($this->tables['cf_location_groups'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        return $row;
                    }
                }
                return null;
            }

            if (str_contains($normalized, 'SELECT sidecar_host, listen_port, bond_listen_port, mptcp_listen_port, cf_listen_port, agent_config_json FROM sidecar_points WHERE id = :id LIMIT 1')) {
                foreach ($this->tables['sidecar_points'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        return $row;
                    }
                }
                return null;
            }

            if (str_contains($normalized, 'SELECT client_token FROM services WHERE id = :id LIMIT 1')) {
                foreach ($this->tables['services'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        return $row;
                    }
                }
                return null;
            }

            return null;
        }

        /**
         * @param array<string, mixed> $params
         * @return array<int, array<string, mixed>>
         */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

            if (str_contains($normalized, 'SELECT cidr, priority, is_active FROM cf_location_group_cidrs WHERE group_id = :group_id AND is_active = 1 ORDER BY priority ASC, id ASC')) {
                $rows = array_values(array_filter($this->tables['cf_location_group_cidrs'] ?? [], static function (array $row) use ($params): bool {
                    return (int) ($row['group_id'] ?? 0) === (int) ($params['group_id'] ?? 0)
                        && (int) ($row['is_active'] ?? 0) === 1;
                }));
                usort($rows, static fn(array $left, array $right): int => [((int) ($left['priority'] ?? 0)), ((int) ($left['id'] ?? 0))] <=> [((int) ($right['priority'] ?? 0)), ((int) ($right['id'] ?? 0))]);
                return $rows;
            }

            return [];
        }
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
        $ref = new \ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invoke($instance, ...$args);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-cf-sni-env-');
    if ($envPath === false) {
        fail('failed to create env file');
    }
    file_put_contents($envPath, "BOND_TOKEN_SECRET=test-bond-token\n");

    $db = new Database();
    $db->seed('cf_location_groups', [[
        'id' => 5,
        'name' => 'JP',
        'region_code' => 'JP',
        'probe_mode' => 'auto',
        'cache_ttl_seconds' => 120,
        'switch_improvement_ms' => 15,
        'max_candidates' => 32,
    ]]);
    $db->seed('cf_location_group_cidrs', [[
        'id' => 9,
        'group_id' => 5,
        'cidr' => '162.159.0.0/24',
        'priority' => 10,
        'is_active' => 1,
    ]]);
    $db->seed('sidecar_points', [[
        'id' => 4,
        'sidecar_host' => '149.104.3.71',
        'listen_port' => 443,
        'bond_listen_port' => 443,
        'mptcp_listen_port' => 443,
        'cf_listen_port' => 2053,
        'agent_config_json' => json_encode([
            'cf_sni_host' => 'cf.br1.nbwly.com',
            'cf_tls_cert_path' => '/etc/ssl/cf/fullchain.pem',
            'cf_tls_key_path' => '/etc/ssl/cf/privkey.pem',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]]);

    $service = new ProvisioningService(new Config($envPath), $db);

    $node = ['id' => 316];
    $forward = [
        'id' => 47,
        'service_id' => 2,
        'client_token' => 'svc-token',
        'bond_sidecar_point_id' => 4,
        'bond_remote_host' => '149.104.3.71',
        'cf_location_group_id' => 5,
        'path_prefix' => '07289d1f5ed25399',
        'sni_host' => 'br1.nbwly.com',
    ];

    $payload = call_private($service, 'buildCfModePayload', $forward, $node);
    if (($payload['sni_host'] ?? null) !== 'cf.br1.nbwly.com') {
        fail('expected cf_mode payload to prefer sidecar point cf_sni_host');
    }
    if (($payload['dial_port'] ?? null) !== 2053) {
        fail('expected cf_mode payload to use sidecar cf_listen_port');
    }
    if (($payload['route_key'] ?? null) !== '07289d1f5ed25399') {
        fail('expected cf_mode payload to preserve route_key');
    }

    $db->seed('sidecar_points', [[
        'id' => 4,
        'sidecar_host' => '149.104.3.71',
        'listen_port' => 443,
        'bond_listen_port' => 443,
        'mptcp_listen_port' => 443,
        'cf_listen_port' => 2053,
        'agent_config_json' => json_encode([
            'cf_tls_cert_path' => '/etc/ssl/cf/fullchain.pem',
            'cf_tls_key_path' => '/etc/ssl/cf/privkey.pem',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]]);
    $fallbackPayload = call_private($service, 'buildCfModePayload', $forward, $node);
    if (($fallbackPayload['sni_host'] ?? null) !== 'br1.nbwly.com') {
        fail('expected cf_mode payload to fall back to forward sni_host when sidecar point cf_sni_host is absent');
    }

    echo "ok\n";
}
