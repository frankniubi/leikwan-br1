<?php
declare(strict_types=1);

namespace Leikwan\Master {
    final class Config
    {
        /** @var array<string, string> */
        private array $values;
        /** @param array<string, string> $values */
        public function __construct(array $values = []) { $this->values = $values; }
        public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
        public function getInt(string $key, int $default = 0): int { return (int) ($this->values[$key] ?? $default); }
        public function getBool(string $key, bool $default = false): bool { return in_array(strtolower((string) ($this->values[$key] ?? ($default ? 'true' : 'false'))), ['1', 'true', 'yes', 'on'], true); }
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
        public ?ApiException $nextException = null;
        public function __construct(private object $db) {}

        public function effectiveFeatureFlagsForService(array $service): array
        {
            return $this->db->features;
        }

        public function availableForwardRoutesForService(int $serviceId): array { return []; }

        public function forwardLimitForService(int $serviceId): int { return 64; }

        public function createForward(int $serviceId, array $payload, string $actor = 'user', ?string $idempotencyKeyHash = null, ?string $requestHash = null, ?int $activeForwardLimit = null, ?array $mutationPolicy = null): array
        {
            if ($this->nextException instanceof ApiException) {
                throw $this->nextException;
            }
            $this->calls[] = ['create', $serviceId, $payload, $actor, $idempotencyKeyHash, $requestHash, $activeForwardLimit, $mutationPolicy];
            $this->db->createForwardRow($serviceId, $payload, $idempotencyKeyHash, $requestHash);
            return ['forward_id' => 123];
        }

        public function updateForward(int $forwardId, array $payload, string $actor = 'user', ?int $expectedRevision = null, ?array $mutationPolicy = null): array
        {
            $this->calls[] = ['update', $forwardId, $payload, $actor, $expectedRevision, $mutationPolicy];
            $this->db->updateForwardRow($forwardId, $payload);
            return ['forward_id' => $forwardId];
        }

        public function updateForwardNonStructural(int $forwardId, array $payload, string $actor = 'user', ?int $expectedRevision = null, ?array $mutationPolicy = null): array
        {
            $this->calls[] = ['update_non_structural', $forwardId, $payload, $actor, $expectedRevision, $mutationPolicy];
            $this->db->updateForwardRow($forwardId, $payload);
            return ['forward_id' => $forwardId];
        }

        public function deleteForward(int $forwardId, string $actor = 'user', ?int $expectedRevision = null, ?array $mutationPolicy = null): void
        {
            $this->calls[] = ['delete', $forwardId, $actor, $expectedRevision, $mutationPolicy];
            $this->db->deleteForwardRow($forwardId);
        }
    }
}

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, list<array<string, mixed>>> */
        private array $tables = [];
        /** @var array<string, bool> */
        public array $features = [
            'allow_manage_forwards' => true,
            'allow_delete_forwards' => true,
        ];

        /** @param list<array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void { $this->tables[$table] = array_values($rows); }
        /** @return list<array<string, mixed>> */
        public function rows(string $table): array { return $this->tables[$table] ?? []; }

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
            if (str_contains($sql, 'api_idempotency_key_hash = :key_hash')) {
                foreach ($this->tables['forwards'] ?? [] as $row) {
                    if ((int) ($row['service_id'] ?? 0) === (int) ($params['service_id'] ?? 0)
                        && (string) ($row['api_idempotency_key_hash'] ?? '') === (string) ($params['key_hash'] ?? '')) {
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
                    if (str_contains($sql, 'f.enabled = 1') && (int) ($row['enabled'] ?? 0) !== 1) {
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

        /** @param array<string, mixed> $payload */
        public function createForwardRow(int $serviceId, array $payload, ?string $keyHash, ?string $requestHash): void
        {
            $this->tables['forwards'][] = [
                'id' => 123,
                'service_id' => $serviceId,
                'user_id' => 7,
                'forward_route_id' => (int) ($payload['forward_route_id'] ?? 0),
                'route_label' => 'Smart Route',
                'listen_port' => (int) ($payload['listen_port'] ?? 0),
                'transport_mode' => (string) ($payload['transport_mode'] ?? 'tcp'),
                'target_source' => (string) ($payload['target_source'] ?? 'manual'),
                'target_host' => $payload['target_host'] ?? null,
                'target_port' => (int) ($payload['target_port'] ?? 0),
                'enabled' => 1,
                'api_revision' => 1,
                'api_idempotency_key_hash' => $keyHash,
                'api_request_hash' => $requestHash,
                'created_at' => '2026-08-02 03:00:00',
                'updated_at' => '2026-08-02 03:00:00',
            ];
        }

        /** @param array<string, mixed> $payload */
        public function updateForwardRow(int $forwardId, array $payload): void
        {
            foreach ($this->tables['forwards'] as &$row) {
                if ((int) ($row['id'] ?? 0) !== $forwardId) {
                    continue;
                }
                foreach ($payload as $key => $value) {
                    if ($key === 'forward_route_id') {
                        $row['forward_route_id'] = $value;
                    } elseif ($key === 'target_host') {
                        $row['target_host'] = $value;
                    } elseif ($key === 'target_port') {
                        $row['target_port'] = $value;
                    } elseif ($key === 'transport_mode') {
                        $row['transport_mode'] = $value;
                    } elseif ($key === 'listen_port') {
                        $row['listen_port'] = $value;
                    }
                }
                $row['api_revision'] = (int) ($row['api_revision'] ?? 1) + 1;
            }
        }

        public function deleteForwardRow(int $forwardId): void
        {
            foreach ($this->tables['forwards'] as &$row) {
                if ((int) ($row['id'] ?? 0) === $forwardId) {
                    $row['enabled'] = 0;
                    $row['api_revision'] = (int) ($row['api_revision'] ?? 1) + 1;
                }
            }
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

    function responseBody(\Leikwan\Master\Http\Response $response): array
    {
        $decoded = json_decode($response->body(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{controller:ForwardLineApiController,db:Database,provisioning:ProvisioningService,headers:array<string,string>} */
    function fixture(bool $withForward = true, int $activeCount = 0): array
    {
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
        $rows = [];
        for ($i = 0; $i < $activeCount; $i++) {
            $rows[] = ['id' => 200 + $i, 'service_id' => 42, 'user_id' => 7, 'enabled' => 1, 'api_revision' => 1];
        }
        if ($withForward) {
            $rows[] = [
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
                'api_request_hash' => 'old-request-hash',
                'created_at' => '2026-08-02 03:00:00',
                'updated_at' => '2026-08-02 03:00:00',
            ];
        }
        $db->seed('forwards', $rows);
        $provisioning = new ProvisioningService($db);
        $controller = new ForwardLineApiController(new Config(['USER_FORWARD_LIMIT_PER_SERVICE' => '64']), $db, $provisioning);
        return ['controller' => $controller, 'db' => $db, 'provisioning' => $provisioning, 'headers' => ['Authorization' => 'Bearer user-token']];
    }

    function jsonRequest(string $method, string $path, array $headers, array $body, string $rawBody = ''): Request
    {
        return new Request($method, $path, [], $body, $headers + ['Content-Type' => 'application/json'], $rawBody !== '' ? $rawBody : json_encode($body));
    }

    $fixture = fixture(false);
    $response = $fixture['controller']->create(new Request('POST', '/api/v1/user/services/42/forwards', [], [], $fixture['headers'] + ['Content-Type' => 'text/plain'], '{}'), ['id' => '42']);
    if ($response->status() !== 415) {
        fail('non-JSON create must return 415');
    }

    $fixture = fixture(false);
    $response = $fixture['controller']->create(new Request('POST', '/api/v1/user/services/42/forwards', [], [], $fixture['headers'] + ['Content-Type' => 'application/json', 'Idempotency-Key' => 'valid-key-1'], str_repeat('x', 16385)), ['id' => '42']);
    if ($response->status() !== 413) {
        fail('oversized create must return 413');
    }

    $payload = [
        'route_id' => 14,
        'listen_port' => null,
        'transport' => 'tcp',
        'target' => ['source' => 'manual', 'host' => '10.0.0.5', 'port' => 443],
    ];

    $fixture = fixture(false);
    $fixture['db']->seed('services', [[
        'id' => 42,
        'user_id' => 7,
        'plan_id' => 6,
        'name' => 'Tenant service',
        'status' => 'active',
    ], [
        'id' => 43,
        'user_id' => 8,
        'plan_id' => 6,
        'name' => 'Other tenant service',
        'status' => 'active',
    ]]);
    $fixture['db']->seed('forwards', [[
        'id' => 124,
        'service_id' => 43,
        'user_id' => 8,
        'enabled' => 1,
        'api_revision' => 1,
    ]]);
    $crossCreate = $fixture['controller']->create(jsonRequest('POST', '/api/v1/user/services/43/forwards', $fixture['headers'] + ['Idempotency-Key' => 'cross-key-1'], $payload), ['id' => '43']);
    $crossPatch = $fixture['controller']->patch(jsonRequest('PATCH', '/api/v1/user/forwards/124', $fixture['headers'] + ['If-Match' => '"forward-124-r1"'], ['target' => ['port' => 8443]]), ['id' => '124']);
    $crossDelete = $fixture['controller']->delete(new Request('DELETE', '/api/v1/user/forwards/124', [], [], $fixture['headers'] + ['If-Match' => '"forward-124-r1"'], ''), ['id' => '124']);
    foreach (['create' => $crossCreate, 'patch' => $crossPatch, 'delete' => $crossDelete] as $operation => $crossResponse) {
        if ($crossResponse->status() !== 404 || $fixture['provisioning']->calls !== []) {
            fail('cross-tenant ' . $operation . ' must return 404 without provisioning');
        }
    }

    $fixture = fixture(false);
    $response = $fixture['controller']->create(jsonRequest('POST', '/api/v1/user/services/42/forwards', $fixture['headers'], $payload), ['id' => '42']);
    if ($response->status() !== 428) {
        fail('create without Idempotency-Key must return 428');
    }

    $fixture = fixture(true);
    $response = $fixture['controller']->patch(jsonRequest('PATCH', '/api/v1/user/forwards/123', $fixture['headers'], ['target' => ['port' => 8443]]), ['id' => '123']);
    if ($response->status() !== 428) {
        fail('patch without If-Match must return 428');
    }
    $response = $fixture['controller']->delete(new Request('DELETE', '/api/v1/user/forwards/123', [], [], $fixture['headers'], ''), ['id' => '123']);
    if ($response->status() !== 428) {
        fail('delete without If-Match must return 428');
    }

    $fixture = fixture(true);
    $key = 'valid-key-1';
    $keyHash = hash('sha256', $key);
    $fixture['db']->seed('forwards', [[
        'id' => 123,
        'service_id' => 42,
        'user_id' => 7,
        'forward_route_id' => 14,
        'listen_port' => 11024,
        'transport_mode' => 'tcp',
        'target_source' => 'manual',
        'target_host' => '10.0.0.5',
        'target_port' => 443,
        'enabled' => 1,
        'api_revision' => 3,
        'api_idempotency_key_hash' => $keyHash,
        'api_request_hash' => 'different-request',
    ]]);
    $response = $fixture['controller']->create(jsonRequest('POST', '/api/v1/user/services/42/forwards', $fixture['headers'] + ['Idempotency-Key' => $key], $payload), ['id' => '42']);
    if ($response->status() !== 409 || (responseBody($response)['error']['code'] ?? '') !== 'idempotency_conflict') {
        fail('same idempotency key with changed request must return 409 idempotency_conflict');
    }

    $fixture = fixture(true);
    $fixture['db']->seed('forwards', [[
        'id' => 123,
        'service_id' => 42,
        'user_id' => 7,
        'enabled' => 0,
        'api_revision' => 4,
        'api_idempotency_key_hash' => $keyHash,
        'api_request_hash' => \Leikwan\Master\Support\ForwardLineApiContract::requestHash(
            \Leikwan\Master\Support\ForwardLineApiContract::normalizeCreate($payload)
        ),
    ]]);
    $response = $fixture['controller']->create(jsonRequest('POST', '/api/v1/user/services/42/forwards', $fixture['headers'] + ['Idempotency-Key' => $key], $payload), ['id' => '42']);
    if ($response->status() !== 409 || (responseBody($response)['error']['code'] ?? '') !== 'idempotency_resource_inactive') {
        fail('idempotent replay of an inactive resource must return 409 idempotency_resource_inactive');
    }

    $fixture = fixture(true);
    $before = $fixture['db']->rows('forwards');
    $response = $fixture['controller']->patch(jsonRequest('PATCH', '/api/v1/user/forwards/123', $fixture['headers'] + ['If-Match' => '"forward-123-r2"'], ['target' => ['port' => 8443]]), ['id' => '123']);
    if ($response->status() !== 412 || $fixture['provisioning']->calls !== [] || $fixture['db']->rows('forwards') !== $before) {
        fail('stale ETag must return 412 without mutation');
    }

    $fixture = fixture(false, 64);
    $response = $fixture['controller']->create(jsonRequest('POST', '/api/v1/user/services/42/forwards', $fixture['headers'] + ['Idempotency-Key' => 'valid-key-1'], $payload), ['id' => '42']);
    if ($response->status() !== 409 || (responseBody($response)['error']['code'] ?? '') !== 'forward_quota_exceeded') {
        fail('forward quota must return 409');
    }

    $fixture = fixture(false);
    $fixture['db']->features['allow_manage_forwards'] = false;
    $response = $fixture['controller']->create(jsonRequest('POST', '/api/v1/user/services/42/forwards', $fixture['headers'] + ['Idempotency-Key' => 'valid-key-1'], $payload), ['id' => '42']);
    if ($response->status() !== 403) {
        fail('manage feature policy must return 403 for create');
    }

    $fixture = fixture(true);
    $fixture['db']->features['allow_manage_forwards'] = false;
    $response = $fixture['controller']->patch(jsonRequest('PATCH', '/api/v1/user/forwards/123', $fixture['headers'] + ['If-Match' => '"forward-123-r3"'], ['target' => ['port' => 8443]]), ['id' => '123']);
    if ($response->status() !== 403) {
        fail('manage feature policy must return 403 for patch');
    }

    $fixture = fixture(true);
    $fixture['db']->features['allow_delete_forwards'] = false;
    $response = $fixture['controller']->delete(new Request('DELETE', '/api/v1/user/forwards/123', [], [], $fixture['headers'] + ['If-Match' => '"forward-123-r3"'], ''), ['id' => '123']);
    if ($response->status() !== 403) {
        fail('delete feature policy must return 403');
    }

    $fixture = fixture(false);
    $response = $fixture['controller']->create(jsonRequest('POST', '/api/v1/user/services/42/forwards', $fixture['headers'] + ['Idempotency-Key' => 'valid-key-1'], $payload), ['id' => '42']);
    $createPolicy = $fixture['provisioning']->calls[0][7] ?? [];
    if ($response->status() !== 201
        || count($fixture['provisioning']->calls) !== 1
        || ($fixture['provisioning']->calls[0][6] ?? null) !== 64
        || ($createPolicy['user_id'] ?? null) !== 7
        || ($createPolicy['feature'] ?? null) !== 'allow_manage_forwards') {
        fail('valid create must return 201 and call provisioning once');
    }
    $replay = $fixture['controller']->create(jsonRequest('POST', '/api/v1/user/services/42/forwards', $fixture['headers'] + ['Idempotency-Key' => 'valid-key-1'], $payload), ['id' => '42']);
    if ($replay->status() !== 200
        || array_key_exists('Location', $replay->headers())
        || ($replay->headers()['Content-Location'] ?? '') !== '/api/v1/user/forwards/123'
        || count($fixture['provisioning']->calls) !== 1) {
        fail('same idempotency key/request must replay without provisioning');
    }
    $changed = $payload;
    $changed['target']['port'] = 8443;
    $conflict = $fixture['controller']->create(jsonRequest('POST', '/api/v1/user/services/42/forwards', $fixture['headers'] + ['Idempotency-Key' => 'valid-key-1'], $changed), ['id' => '42']);
    if ($conflict->status() !== 409) {
        fail('changed request under same idempotency key must return 409');
    }

    $fixture = fixture(false);
    $fixture['provisioning']->nextException = new \Leikwan\Master\Support\ApiException(
        'leigod_route_no_healthy_candidate',
        409,
        ['node_id' => 88, 'provider' => 'internal']
    );
    $response = $fixture['controller']->create(jsonRequest('POST', '/api/v1/user/services/42/forwards', $fixture['headers'] + ['Idempotency-Key' => 'valid-key-1'], $payload), ['id' => '42']);
    if ($response->status() !== 409 || (responseBody($response)['error']['code'] ?? '') !== 'route_unavailable') {
        fail('provider availability failure must map to stable route_unavailable');
    }
    foreach (['leigod', 'node_id', 'provider', 'internal'] as $forbidden) {
        if (str_contains($response->body(), $forbidden)) {
            fail('provider availability error leaked internal detail ' . $forbidden);
        }
    }

    foreach ([
        'target_service_ip_missing' => ['target.service_id' => 'no_public_ip'],
        'target_service_not_allowed' => ['target.service_id' => 'not_allowed'],
        'invalid_forward_target_source' => ['target.source' => 'invalid'],
    ] as $internalCode => $expectedFields) {
        $fixture = fixture(false);
        $fixture['provisioning']->nextException = new \Leikwan\Master\Support\ApiException(
            $internalCode,
            422,
            ['target_service_id' => 40]
        );
        $response = $fixture['controller']->create(jsonRequest('POST', '/api/v1/user/services/42/forwards', $fixture['headers'] + ['Idempotency-Key' => 'valid-key-1'], $payload), ['id' => '42']);
        $error = responseBody($response)['error'] ?? [];
        if ($response->status() !== 422 || ($error['code'] ?? '') !== 'validation_failed' || ($error['fields'] ?? null) !== $expectedFields) {
            fail($internalCode . ' must surface as validation_failed with fields, got ' . $response->body());
        }
    }

    $fixture = fixture(false);
    $fixture['provisioning']->nextException = new \Leikwan\Master\Support\ApiException(
        'invalid_forward_payload',
        422,
        ['fields' => ['target.port' => 'out_of_range'], 'payload' => ['target_host' => 'SECRET_PAYLOAD']]
    );
    $response = $fixture['controller']->create(jsonRequest('POST', '/api/v1/user/services/42/forwards', $fixture['headers'] + ['Idempotency-Key' => 'valid-key-1'], $payload), ['id' => '42']);
    if ((responseBody($response)['error']['fields'] ?? null) !== ['target.port' => 'out_of_range'] || str_contains($response->body(), 'SECRET_PAYLOAD')) {
        fail('provisioning field reasons must pass through without the raw payload, got ' . $response->body());
    }

    $fixture = fixture(false);
    $fixture['provisioning']->nextException = new \Leikwan\Master\Support\ApiException(
        'secret_internal_failure_code',
        422,
        ['fields' => ['secret_internal_field' => 'secret_internal_reason']]
    );
    $response = $fixture['controller']->create(jsonRequest('POST', '/api/v1/user/services/42/forwards', $fixture['headers'] + ['Idempotency-Key' => 'valid-key-1'], $payload), ['id' => '42']);
    if ($response->status() !== 500 || (responseBody($response)['error']['code'] ?? '') !== 'operation_failed') {
        fail('unknown provisioning error must map to generic operation_failed');
    }
    if (str_contains($response->body(), 'secret_internal')) {
        fail('unknown provisioning error leaked internal code or fields');
    }

    $fixture = fixture(true);
    $response = $fixture['controller']->patch(jsonRequest('PATCH', '/api/v1/user/forwards/123', $fixture['headers'] + ['If-Match' => '"forward-123-r3"'], ['route_id' => 18]), ['id' => '123']);
    if ($response->status() !== 200 || ($fixture['provisioning']->calls[0][0] ?? '') !== 'update') {
        fail('route patch must use structural update');
    }
    $fixture = fixture(true);
    $response = $fixture['controller']->patch(jsonRequest('PATCH', '/api/v1/user/forwards/123', $fixture['headers'] + ['If-Match' => '"forward-123-r3"'], ['target' => ['port' => 8443]]), ['id' => '123']);
    if ($response->status() !== 200 || ($fixture['provisioning']->calls[0][0] ?? '') !== 'update_non_structural') {
        fail('target-only patch must use non-structural update');
    }
    $patchPolicy = $fixture['provisioning']->calls[0][5] ?? [];
    if (($patchPolicy['user_id'] ?? null) !== 7 || ($patchPolicy['feature'] ?? null) !== 'allow_manage_forwards') {
        fail('patch must recheck tenant ownership and manage feature inside the transaction');
    }
    $fixture = fixture(true);
    $response = $fixture['controller']->patch(jsonRequest('PATCH', '/api/v1/user/forwards/123', $fixture['headers'] + ['If-Match' => '"forward-123-r3"'], ['transport' => 'udp']), ['id' => '123']);
    if ($response->status() !== 200 || ($fixture['provisioning']->calls[0][0] ?? '') !== 'update') {
        fail('transport patch must use structural update so route availability is validated atomically');
    }
    $fixture = fixture(true);
    $response = $fixture['controller']->patch(jsonRequest('PATCH', '/api/v1/user/forwards/123', $fixture['headers'] + ['If-Match' => '"forward-123-r3"'], ['listen_port' => null]), ['id' => '123']);
    if ($response->status() !== 200 || ($fixture['provisioning']->calls[0][0] ?? '') !== 'update') {
        fail('null listen port must use structural update so provisioning can allocate a new port');
    }
    $fixture = fixture(true);
    $response = $fixture['controller']->patch(jsonRequest('PATCH', '/api/v1/user/forwards/123', $fixture['headers'] + ['If-Match' => '"forward-123-r3"'], ['target' => ['service_id' => 99]]), ['id' => '123']);
    if ($response->status() !== 422 || $fixture['provisioning']->calls !== []) {
        fail('manual target must reject service id without an explicit source switch');
    }
    $fixture = fixture(true);
    $response = $fixture['controller']->patch(jsonRequest('PATCH', '/api/v1/user/forwards/123', $fixture['headers'] + ['If-Match' => '"forward-123-r3"'], [
        'target' => ['source' => 'service_ip', 'service_id' => 99],
    ]), ['id' => '123']);
    $structuralPayload = $fixture['provisioning']->calls[0][2] ?? [];
    if ($response->status() !== 200
        || ($fixture['provisioning']->calls[0][0] ?? '') !== 'update'
        || ($structuralPayload['target_host'] ?? null) !== ''
        || ($structuralPayload['target_service_id'] ?? null) !== 99) {
        fail('complete service target switch must use structural update without stale manual host');
    }
    $fixture = fixture(true);
    $fixture['db']->seed('forwards', [[
        'id' => 123,
        'service_id' => 42,
        'user_id' => 7,
        'forward_route_id' => 14,
        'listen_port' => 11024,
        'transport_mode' => 'tcp',
        'target_source' => 'service_ip',
        'target_host' => '192.0.2.44',
        'target_port' => 443,
        'target_service_id' => 99,
        'enabled' => 1,
        'api_revision' => 3,
    ]]);
    $response = $fixture['controller']->patch(jsonRequest('PATCH', '/api/v1/user/forwards/123', $fixture['headers'] + ['If-Match' => '"forward-123-r3"'], [
        'target' => ['host' => 'backend.example.com'],
    ]), ['id' => '123']);
    if ($response->status() !== 422 || $fixture['provisioning']->calls !== []) {
        fail('service target must reject caller-controlled host changes');
    }

    $fixture = fixture(true);
    $response = $fixture['controller']->delete(new Request('DELETE', '/api/v1/user/forwards/123', [], [], $fixture['headers'] + ['If-Match' => '"forward-123-r3"'], ''), ['id' => '123']);
    $deletePolicy = $fixture['provisioning']->calls[0][4] ?? [];
    if ($response->status() !== 204
        || ($fixture['provisioning']->calls[0][0] ?? '') !== 'delete'
        || ($deletePolicy['user_id'] ?? null) !== 7
        || ($deletePolicy['feature'] ?? null) !== 'allow_delete_forwards') {
        fail('current ETag delete must return 204 and call provisioning');
    }

    echo "ok\n";
}
