<?php
declare(strict_types=1);

// Aggregation (bond/MPTCP/CF) still requires an explicit, visible, active overseas sidecar. It must not
// silently fall back to BOND_AGGREGATOR_HOST/BOND_AGGREGATOR_PORT, because dedicated LeiGod is the only
// sidecar-less path.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        /** @var array<string, int> */
        private array $autoIds = ['forwards' => 100, 'service_endpoints' => 200, 'provision_tasks' => 300, 'audit_logs' => 400];

        /** @param array<int, array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $n = $this->normalize($sql);
            if (str_contains($n, 'SELECT * FROM services WHERE id = :id LIMIT 1')) {
                return $this->findById('services', (int) ($params['id'] ?? 0));
            }
            if (str_contains($n, 'SELECT * FROM node_groups WHERE group_key = :group_key LIMIT 1')) {
                foreach ($this->tables['node_groups'] ?? [] as $row) {
                    if ((string) ($row['group_key'] ?? '') === (string) ($params['group_key'] ?? '')) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($n, 'SELECT sp.*, u.email AS owner_email, u.name AS owner_name FROM sidecar_points sp')) {
                $row = $this->findById('sidecar_points', (int) ($params['id'] ?? 0));
                if ($row === null) {
                    return null;
                }
                $owner = $row['owner_user_id'] ?? null;
                if ($owner !== null && (int) $owner !== (int) ($params['user_id'] ?? 0)) {
                    return null;
                }
                return $row + ['owner_email' => null, 'owner_name' => null];
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            $n = $this->normalize($sql);
            if (str_contains($n, 'FROM node_groups g')) {
                $rows = [];
                foreach ($this->tables['node_groups'] ?? [] as $group) {
                    $rows[] = $group + ['node_count' => 1];
                }
                return $rows;
            }
            if (str_contains($n, 'FROM clients c')) {
                return [];
            }
            if (str_contains($n, 'FROM client_groups g')) {
                return [];
            }
            return [];
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int { return 0; }

        /** @param array<string, mixed> $row */
        public function insert(string $table, array $row): int
        {
            $id = $this->autoIds[$table] ?? 1;
            $row['id'] = $id;
            $this->tables[$table][] = $row;
            $this->autoIds[$table] = $id + 1;
            return $id;
        }

        public function transaction(callable $callback)
        {
            return $callback($this);
        }

        private function findById(string $table, int $id): ?array
        {
            foreach ($this->tables[$table] ?? [] as $row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    return $row;
                }
            }
            return null;
        }

        private function normalize(string $sql): string
        {
            return preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
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

    function assertApiException(callable $call, string $expectedMessage): void
    {
        try {
            $call();
            fail('expected ' . $expectedMessage);
        } catch (ApiException $e) {
            if ($e->getMessage() !== $expectedMessage) {
                fail('expected ' . $expectedMessage . ', got ' . $e->getMessage());
            }
            if ($e->statusCode() !== 422) {
                fail('expected status 422 for ' . $expectedMessage . ', got ' . $e->statusCode());
            }
        }
    }

    function makeService(Database $db): ProvisioningService
    {
        $envPath = tempnam(sys_get_temp_dir(), 'lw-aggregation-sidecar-guard-env-');
        if ($envPath === false) {
            fail('failed to create temp env file');
        }
        file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\nBOND_AGGREGATOR_HOST=legacy-sidecar.invalid\nBOND_AGGREGATOR_PORT=9999\nBOND_TOKEN_SECRET=test-token\n");
        return new ProvisioningService(new Config($envPath), $db);
    }

    $basePayload = [
        'listen_port' => 18080,
        'public_port' => 443,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
        'node_group' => 'sha-tencent',
        'endpoint_mode' => 'slave_group',
        'backend_mode' => 'leigod_mode',
        'client_mode' => 'bond',
        'bond_ingress_mode' => 'plain_tcp_mux',
        'transport_mode' => 'tcp+udp',
    ];

    // Even with legacy env fallbacks configured, aggregation without an explicit sidecar is invalid.
    $db = new Database();
    $db->seed('services', [['id' => 2, 'user_id' => 7, 'plan_id' => 0, 'status' => 'active', 'client_token' => 'svc-token', 'name' => 'svc']]);
    $db->seed('node_groups', [['id' => 1, 'group_key' => 'sha-tencent', 'name' => 'Shanghai Tencent']]);
    $service = makeService($db);
    assertApiException(
        static fn() => $service->createForward(2, $basePayload + ['bond_sidecar_point_id' => 0]),
        'bond_sidecar_point_required_for_aggregation'
    );

    // A selected sidecar that is domestic/mainland is also invalid for speed aggregation.
    $db = new Database();
    $db->seed('services', [['id' => 2, 'user_id' => 7, 'plan_id' => 0, 'status' => 'active', 'client_token' => 'svc-token', 'name' => 'svc']]);
    $db->seed('node_groups', [['id' => 1, 'group_key' => 'sha-tencent', 'name' => 'Shanghai Tencent']]);
    $db->seed('sidecar_points', [[
        'id' => 5,
        'owner_user_id' => null,
        'is_active' => 1,
        'sidecar_host' => '198.51.100.5',
        'listen_port' => 8899,
        'bond_listen_port' => 8899,
        'mptcp_listen_port' => 8898,
        'agent_config_json' => json_encode(['aggregation_region_scope' => 'domestic', 'country_code' => 'CN'], JSON_UNESCAPED_SLASHES),
    ]]);
    $service = makeService($db);
    assertApiException(
        static fn() => $service->createForward(2, $basePayload + ['bond_sidecar_point_id' => 5]),
        'sidecar_must_be_overseas_for_aggregation'
    );

    echo "ok\n";
}
