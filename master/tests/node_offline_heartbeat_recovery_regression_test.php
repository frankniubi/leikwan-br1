<?php
declare(strict_types=1);

// A stale-node cleanup demotes nodes to offline. Their next authenticated
// heartbeat must be able to promote them again without opening task APIs to
// offline or administratively inactive nodes.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        private array $nodes = [];

        /** @var array<int, array{table:string,data:array<string,mixed>}> */
        public array $inserts = [];

        public function __construct(\Leikwan\Master\Config $config) {}

        /** @param array<string, mixed> $node */
        public function seedNode(array $node): void
        {
            $this->nodes[(int) $node['id']] = $node;
        }

        /** @return array<string, mixed>|null */
        public function node(int $id): ?array
        {
            return $this->nodes[$id] ?? null;
        }

        /** @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($normalized, 'FROM nodes WHERE api_token = :token')) {
                $allowedStatuses = array_values(array_intersect_key($params, array_flip([
                    'active',
                    'degraded',
                    'offline',
                ])));
                foreach ($this->nodes as $node) {
                    if ((string) ($node['api_token'] ?? '') === (string) ($params['token'] ?? '')
                        && in_array((string) ($node['status'] ?? ''), $allowedStatuses, true)) {
                        return $node;
                    }
                }
                return null;
            }

            if (str_contains($normalized, 'SELECT * FROM nodes WHERE id = :id LIMIT 1')) {
                return $this->nodes[(int) ($params['id'] ?? 0)] ?? null;
            }

            return null;
        }

        /** @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            return [];
        }

        public function execute(string $sql, array $params = []): int
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (!str_contains($normalized, 'UPDATE nodes SET')) {
                return 0;
            }

            $id = (int) ($params['id'] ?? 0);
            if (!isset($this->nodes[$id])) {
                return 0;
            }
            foreach ($params as $key => $value) {
                if ($key !== 'id') {
                    $this->nodes[$id][$key] = $value;
                }
            }
            return 1;
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int
        {
            $this->inserts[] = ['table' => $table, 'data' => $data];
            return count($this->inserts);
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
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Controllers\NodeController;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Http\Request;
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\Auth;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    /** @param array<string, mixed> $body */
    function heartbeatRequest(string $token, array $body = []): Request
    {
        return new Request(
            'POST',
            '/api/node/heartbeat',
            [],
            $body,
            ['authorization' => 'Bearer ' . $token],
            (string) json_encode($body)
        );
    }

    /** @return array<string, mixed> */
    function nodeFixture(int $id, string $token, string $status): array
    {
        return [
            'id' => $id,
            'api_token' => $token,
            'group_id' => 5,
            'name' => 'edge-' . $id,
            'region' => 'shanghai',
            'status' => $status,
            'public_ip' => '203.0.113.' . $id,
            'private_ip' => '10.0.0.' . $id,
            'machine_id' => 'machine-' . $id,
            'agent_version' => '2026.06.05.4',
            'supports_udp' => 1,
            'supports_filtering' => 0,
            'agent_config_json' => null,
        ];
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-offline-hb-env-');
    if ($envPath === false) {
        fail('failed to create temp env');
    }
    file_put_contents($envPath, "NODE_REPUBLISH_FORCE_INTERVAL_SECONDS=600\n");
    $config = new Config($envPath);

    $db = new Database($config);
    $db->seedNode(nodeFixture(11, 'offline-token', 'offline'));

    $ordinaryApiRejected = false;
    try {
        Auth::requireNode(heartbeatRequest('offline-token'), $db);
    } catch (ApiException $exception) {
        $ordinaryApiRejected = $exception->statusCode() === 401;
    }
    if (!$ordinaryApiRejected) {
        fail('offline nodes must remain fenced from ordinary node APIs');
    }

    $controller = new NodeController($config, $db, new ProvisioningService($config, $db));
    try {
        $response = $controller->heartbeat(heartbeatRequest('offline-token', [
            'status' => 'active',
            'agent_version' => '2026.06.05.4',
        ]), []);
    } catch (ApiException $exception) {
        fail('REGRESSION: an offline node with its valid token cannot recover by heartbeat: ' . $exception->getMessage());
    }

    if ($response->status() !== 200 || !str_contains($response->body(), '"ok": true')) {
        fail('offline recovery heartbeat did not return the normal success response');
    }
    if (($db->node(11)['status'] ?? null) !== 'active') {
        fail('offline recovery heartbeat did not promote the node to active');
    }

    $inactiveDb = new Database($config);
    $inactiveDb->seedNode(nodeFixture(12, 'inactive-token', 'inactive'));
    $inactiveController = new NodeController($config, $inactiveDb, new ProvisioningService($config, $inactiveDb));
    $inactiveRejected = false;
    try {
        $inactiveController->heartbeat(heartbeatRequest('inactive-token', ['status' => 'active']), []);
    } catch (ApiException $exception) {
        $inactiveRejected = $exception->statusCode() === 401;
    }
    if (!$inactiveRejected || ($inactiveDb->node(12)['status'] ?? null) !== 'inactive') {
        fail('administratively inactive nodes must not self-reactivate by heartbeat');
    }

    @unlink($envPath);
    echo "ok\n";
}
