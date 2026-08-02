<?php
declare(strict_types=1);

namespace Leikwan\Master {
    final class Config
    {
        public function __construct(string $envPath) {}
        public function get(string $key, mixed $default = null): mixed { return $default; }
        public function getInt(string $key, int $default = 0): int { return $default; }
        public function getBool(string $key, bool $default = false): bool { return $default; }
    }
}

namespace Leikwan\Master\Support {
    class ApiException extends \RuntimeException
    {
        public function __construct(string $message, private int $statusCode = 400, private array $details = [])
        {
            parent::__construct($message);
        }
        public function statusCode(): int { return $this->statusCode; }
        public function details(): array { return $this->details; }
    }

    final class ProvisioningService
    {
        public array $calls = [];

        public function serviceSummary(int $serviceId): array
        {
            return [
                'id' => $serviceId,
                'name' => 'Tenant service',
                'status' => 'active',
                'plan_id' => 6,
                'plan_name' => 'Safe plan',
                'effective_features' => [
                    'allow_manage_forwards' => true,
                    'allow_delete_forwards' => true,
                ],
                'forwards' => [],
            ];
        }

        public function availableForwardRoutesForService(int $serviceId): array
        {
            return [[
                'id' => 14,
                'tenant_label' => 'Smart Route',
                'tenant_description' => 'Safe route description',
                'tenant_badge' => 'recommended',
                'availability' => [
                    'tcp' => ['available' => true, 'healthy_node_count' => 6, 'unavailable_reason' => null, 'last_error' => 'secret'],
                ],
                'forward_combination_id' => 11,
                'node_id' => 8,
                'agent_token' => 'secret',
            ]];
        }

        public function createForward(int $serviceId, array $payload, string $actor = 'user', ?string $idempotencyKeyHash = null, ?string $requestHash = null): array
        {
            $this->calls[] = ['create', $serviceId, $payload, $actor, $idempotencyKeyHash, $requestHash];
            return ['forward_id' => 123];
        }

        public function updateForward(int $forwardId, array $payload, string $actor = 'user', ?int $expectedRevision = null): array
        {
            $this->calls[] = ['update', $forwardId, $payload, $actor, $expectedRevision];
            return ['forward_id' => $forwardId];
        }

        public function updateForwardNonStructural(int $forwardId, array $payload, string $actor = 'user', ?int $expectedRevision = null): array
        {
            $this->calls[] = ['update_non_structural', $forwardId, $payload, $actor, $expectedRevision];
            return ['forward_id' => $forwardId];
        }

        public function deleteForward(int $forwardId, string $actor = 'user', ?int $expectedRevision = null): void
        {
            $this->calls[] = ['delete', $forwardId, $actor, $expectedRevision];
        }
    }
}

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, list<array<string, mixed>>> */
        private array $tables = [];

        /** @param list<array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void { $this->tables[$table] = array_values($rows); }

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM users WHERE api_token = :token')) {
                foreach ($this->tables['users'] ?? [] as $row) {
                    if ((string) ($row['api_token'] ?? '') === (string) ($params['token'] ?? '')
                        && (string) ($row['status'] ?? '') === (string) ($params['status'] ?? '')) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($sql, 'FROM services')) {
                foreach ($this->tables['services'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) !== (int) ($params['id'] ?? 0)) {
                        continue;
                    }
                    if (array_key_exists('user_id', $params) && (int) ($row['user_id'] ?? 0) !== (int) $params['user_id']) {
                        continue;
                    }
                    return $row;
                }
                return null;
            }
            if (str_contains($sql, 'FROM forwards') && str_contains($sql, 'f.id = :id')) {
                foreach ($this->tables['forwards'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) !== (int) ($params['id'] ?? 0)) {
                        continue;
                    }
                    if (array_key_exists('user_id', $params) && (int) ($row['user_id'] ?? 0) !== (int) $params['user_id']) {
                        continue;
                    }
                    if (array_key_exists('service_id', $params) && (int) ($row['service_id'] ?? 0) !== (int) $params['service_id']) {
                        continue;
                    }
                    return $row;
                }
                return null;
            }
            return null;
        }

        /** @param array<string, mixed> $params @return list<array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'COUNT(*) AS active_count')) {
                $count = count(array_filter($this->tables['forwards'] ?? [], static fn(array $row): bool => (int) ($row['service_id'] ?? 0) === (int) ($params['service_id'] ?? 0) && (int) ($row['enabled'] ?? 0) === 1));
                return [['active_count' => $count]];
            }
            if (str_contains($sql, 'FROM forwards')) {
                return array_values(array_filter($this->tables['forwards'] ?? [], static fn(array $row): bool => (int) ($row['service_id'] ?? 0) === (int) ($params['service_id'] ?? 0) && (int) ($row['enabled'] ?? 0) === 1));
            }
            return [];
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int { return 1; }
        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int { return 1; }
        public function transaction(callable $callback): mixed { return $callback($this); }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix)
            || $class === 'Leikwan\\Master\\Database\\Database'
            || $class === 'Leikwan\\Master\\Config'
            || $class === 'Leikwan\\Master\\Support\\ProvisioningService'
            || $class === 'Leikwan\\Master\\Support\\ApiException') {
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
    use Leikwan\Master\Controllers\ForwardLineApiController;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Http\Request;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function body(\Leikwan\Master\Http\Response $response): array
    {
        $decoded = json_decode($response->body(), true);
        return is_array($decoded) ? $decoded : [];
    }

    $db = new Database();
    $db->seed('users', [[
        'id' => 7,
        'api_token' => 'user-token',
        'role' => 'tenant',
        'status' => 'active',
    ]]);
    $db->seed('services', [[
        'id' => 42,
        'user_id' => 7,
        'plan_id' => 6,
        'name' => 'Tenant service',
        'status' => 'active',
    ]]);
    $db->seed('forwards', [[
        'id' => 123,
        'service_id' => 42,
        'user_id' => 7,
        'forward_route_id' => 14,
        'route_label' => 'Smart Route',
        'listen_port' => 11024,
        'transport_mode' => 'tcp',
        'target_source' => 'manual',
        'target_host' => '10.0.0.5',
        'target_port' => 443,
        'enabled' => 1,
        'api_revision' => 3,
        'created_at' => '2026-08-02 03:00:00',
        'updated_at' => '2026-08-02 03:05:00',
        'endpoints' => [['health_status' => 'active']],
        'client_token' => 'SECRET',
        'node_id' => 8,
        'forward_combination_id' => 11,
    ]]);

    $controller = new ForwardLineApiController(new Config(''), $db, new ProvisioningService());
    $headers = ['Authorization' => 'Bearer user-token'];
    $request = new Request('GET', '/api/v1/user/forwards/123', [], [], $headers, '');
    $response = $controller->show($request, ['id' => '123']);
    if ($response->status() !== 200) {
        fail('owned detail should return 200, got ' . $response->status() . ' ' . $response->body());
    }
    $detail = body($response);
    if (($detail['ok'] ?? false) !== true || !isset($detail['forward']['id'])) {
        fail('owned detail missing safe forward resource');
    }
    foreach (['client_token', 'node_id', 'forward_combination_id', 'SECRET'] as $forbidden) {
        if (str_contains($response->body(), $forbidden)) {
            fail('detail leaked internal field/value ' . $forbidden);
        }
    }
    foreach (['Cache-Control', 'X-Content-Type-Options', 'ETag'] as $header) {
        if (!array_key_exists($header, $response->headers())) {
            fail('detail missing response header ' . $header);
        }
    }

    $list = $controller->index(new Request('GET', '/api/v1/user/services/42/forwards', [], [], $headers, ''), ['id' => '42']);
    if ($list->status() !== 200 || !isset(body($list)['forwards'][0])) {
        fail('owned forward list should return safe resources');
    }

    $options = $controller->options(new Request('GET', '/api/v1/user/services/42/forward-options', [], [], $headers, ''), ['id' => '42']);
    if ($options->status() !== 200 || !isset(body($options)['routes'][0]['transports']['tcp'])) {
        fail('forward options should expose approved route transport fields');
    }
    foreach (['forward_combination_id', 'node_id', 'agent_token', 'last_error', 'secret'] as $forbidden) {
        if (str_contains($options->body(), $forbidden)) {
            fail('options leaked internal field/value ' . $forbidden);
        }
    }

    $missing = $controller->show(new Request('GET', '/api/v1/user/forwards/999', [], [], $headers, ''), ['id' => '999']);
    if ($missing->status() !== 404) {
        fail('missing forward should return 404');
    }

    echo "ok\n";
}
