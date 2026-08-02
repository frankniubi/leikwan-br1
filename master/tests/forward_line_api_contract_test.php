<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
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

use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\ForwardLineApiContract;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function trueValue(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

/** @param callable(): mixed $callback */
function throwsCode(callable $callback, string $code, string $message): ApiException
{
    try {
        $callback();
    } catch (ApiException $exception) {
        same($code, $exception->getMessage(), $message . ' code');
        return $exception;
    } catch (Throwable $throwable) {
        fail($message . ' unexpected exception ' . $throwable::class . ': ' . $throwable->getMessage());
    }

    fail($message . ' did not throw');
}

$create = ForwardLineApiContract::normalizeCreate([
    'route_id' => 14,
    'listen_port' => null,
    'transport' => 'tcp',
    'target' => ['source' => 'manual', 'host' => 'backend.example.com', 'port' => 443],
]);
same(14, $create['forward_route_id'] ?? null, 'create route normalization');
same(0, $create['listen_port'] ?? null, 'null listen port allocates');
same('manual', $create['target_source'] ?? null, 'manual target normalization');
same('tcp', $create['transport_mode'] ?? null, 'transport normalization');
same('backend.example.com', $create['target_host'] ?? null, 'target host normalization');
same(443, $create['target_port'] ?? null, 'target port normalization');

$patch = ForwardLineApiContract::normalizePatch(['target' => ['port' => 8443]]);
same(['target_port' => 8443], $patch['changes'] ?? null, 'patch target normalization');

throwsCode(
    static fn(): array => ForwardLineApiContract::normalizeCreate([
        'route_id' => 14,
        'listen_port' => null,
        'transport' => 'tcp',
        'target' => ['source' => 'manual', 'host' => 'backend.example.com', 'port' => 443],
        'forward_combination_id' => 99,
    ]),
    'unknown_fields',
    'create rejects internal top-level field'
);

throwsCode(
    static fn(): array => ForwardLineApiContract::normalizeCreate([
        'route_id' => 14,
        'listen_port' => null,
        'transport' => 'tcp',
        'target' => ['source' => 'manual', 'host' => 'backend.example.com', 'port' => 443, 'node_id' => 7],
    ]),
    'unknown_fields',
    'create rejects internal nested field'
);

throwsCode(
    static fn(): array => ForwardLineApiContract::normalizeCreate([
        'route_id' => '14',
        'listen_port' => null,
        'transport' => 'tcp',
        'target' => ['source' => 'manual', 'host' => 'backend.example.com', 'port' => 443],
    ]),
    'validation_failed',
    'create rejects numeric string route id'
);

throwsCode(
    static fn(): array => ForwardLineApiContract::normalizeCreate([
        'route_id' => 14,
        'listen_port' => 65536,
        'transport' => 'tcp',
        'target' => ['source' => 'manual', 'host' => 'backend.example.com', 'port' => 443],
    ]),
    'validation_failed',
    'create rejects out-of-range listen port'
);

throwsCode(
    static fn(): array => ForwardLineApiContract::normalizeCreate([
        'route_id' => 14,
        'listen_port' => null,
        'transport' => 'tcp',
        'target' => ['source' => 'manual', 'host' => 'backend.example.com', 'port' => 0],
    ]),
    'validation_failed',
    'create rejects zero target port'
);

throwsCode(
    static fn(): array => ForwardLineApiContract::normalizeCreate([
        'route_id' => 14,
        'listen_port' => null,
        'transport' => 'tcp/udp',
        'target' => ['source' => 'manual', 'host' => 'backend.example.com', 'port' => 443],
    ]),
    'validation_failed',
    'create rejects unknown transport'
);

foreach ([
    "bad\rhost.example.com",
    "bad\nhost.example.com",
    "bad\0host.example.com",
    'http://backend.example.com',
    'backend.example.com/path',
    'user@backend.example.com',
    'backend.example.com%eth0',
    'backend.example.com;touch',
    'backend.example.com$(id)',
    'backend.example.com`id`',
    'bad label.example.com',
    '-bad.example.com',
    'bad-.example.com',
] as $unsafeHost) {
    throwsCode(
        static fn() => ForwardLineApiContract::validateTargetHost($unsafeHost),
        'validation_failed',
        'unsafe host rejected'
    );
}

same('10.0.0.5', ForwardLineApiContract::validateTargetHost('10.0.0.5'), 'private IPv4 remains valid');
same('2001:db8::5', ForwardLineApiContract::validateTargetHost('2001:db8::5'), 'IPv6 remains valid');
same('backend.example.com', ForwardLineApiContract::validateTargetHost('backend.example.com'), 'DNS host remains valid');

throwsCode(
    static fn(): array => ForwardLineApiContract::normalizePatch([]),
    'validation_failed',
    'empty patch rejected'
);

$requestHashA = ForwardLineApiContract::requestHash(['b' => 2, 'a' => ['y' => 2, 'x' => 1]]);
$requestHashB = ForwardLineApiContract::requestHash(['a' => ['x' => 1, 'y' => 2], 'b' => 2]);
same($requestHashA, $requestHashB, 'request hash is canonical');
same(64, strlen($requestHashA), 'request hash is sha256');

$forward = [
    'id' => 123,
    'service_id' => 42,
    'forward_route_id' => 14,
    'route_label' => 'Smart Route',
    'listen_port' => 11024,
    'transport_mode' => 'tcp',
    'target_source' => 'manual',
    'target_host' => '10.0.0.5',
    'target_port' => 443,
    'target_service_id' => null,
    'cdn_host' => 'edge.example.com',
    'public_port' => 443,
    'api_revision' => 3,
    'enabled' => 1,
    'created_at' => '2026-08-02 03:00:00',
    'updated_at' => '2026-08-02 03:05:00',
    'endpoints' => [
        ['health_status' => 'active'],
        ['health_status' => 'pending'],
    ],
    'client_token' => 'SECRET_CLIENT_TOKEN',
    'token_secret' => 'SECRET_TOKEN_SECRET',
    'agent_token' => 'SECRET_AGENT_TOKEN',
    'node_id' => 9001,
    'node_name' => 'INTERNAL_NODE_NAME',
    'node_public_ip' => 'INTERNAL_NODE_IP',
    'sidecar_point_id' => 8,
    'sidecar_host' => 'INTERNAL_SIDECAR_HOST',
    'forward_combination_id' => 11,
    'active_combination_id' => 11,
    'leigod_line_id' => 7,
    'config_json' => 'INTERNAL_CONFIG_JSON',
    'socks_port' => 47001,
    'path_prefix' => 'INTERNAL_PATH_PREFIX',
    'backend_mode' => 'INTERNAL_BACKEND_MODE',
];
$presented = ForwardLineApiContract::presentForward($forward);
same('"forward-123-r3"', ForwardLineApiContract::etag($forward), 'current forward etag');
trueValue(ForwardLineApiContract::matchesEtag('"forward-123-r3"', $forward), 'current etag matches');
trueValue(!ForwardLineApiContract::matchesEtag('"forward-123-r2"', $forward), 'stale etag does not match');
trueValue(!ForwardLineApiContract::matchesEtag(null, $forward), 'missing etag does not match');

$encoded = json_encode($presented, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)) {
    fail('failed to encode safe forward');
}
foreach ([
    'client_token', 'token_secret', 'agent_token', 'node_id', 'node_name', 'node_public_ip',
    'sidecar_point_id', 'sidecar_host', 'forward_combination_id', 'active_combination_id',
    'leigod_line_id', 'config_json', 'socks_port', 'path_prefix', 'backend_mode',
    'SECRET_CLIENT_TOKEN', 'INTERNAL_NODE_NAME', 'INTERNAL_SIDECAR_HOST', 'INTERNAL_CONFIG_JSON',
] as $forbidden) {
    trueValue(!str_contains($encoded, $forbidden), 'safe forward leaks ' . $forbidden);
}
same(2, $presented['endpoint_count'] ?? null, 'forward endpoint count');
same(1, $presented['active_endpoint_count'] ?? null, 'active endpoint count');

$route = ForwardLineApiContract::presentRoute([
    'id' => 14,
    'name' => 'internal route name',
    'tenant_label' => 'Smart Route',
    'tenant_description' => 'Tenant description',
    'tenant_badge' => 'recommended',
    'availability' => [
        'tcp' => ['available' => true, 'healthy_node_count' => 6, 'unavailable_reason' => null, 'last_error' => 'secret error'],
        'udp' => ['available' => false, 'healthy_node_count' => 0, 'unavailable_reason' => 'route_unavailable', 'node_id' => 8],
    ],
    'forward_combination_id' => 11,
    'provider_line_id' => 7,
    'node_id' => 8,
]);
$routeEncoded = json_encode($route, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($routeEncoded)) {
    fail('failed to encode safe route');
}
trueValue(str_contains($routeEncoded, 'Smart Route'), 'route label is presented');
foreach (['last_error', 'forward_combination_id', 'provider_line_id', 'node_id', 'secret error'] as $forbidden) {
    trueValue(!str_contains($routeEncoded, $forbidden), 'safe route leaks ' . $forbidden);
}

$service = ForwardLineApiContract::presentService([
    'id' => 42,
    'name' => 'Tenant service',
    'status' => 'active',
    'plan_id' => 6,
    'plan_name' => 'Smart plan',
    'effective_features' => ['allow_manage_forwards' => true, 'allow_delete_forwards' => false, 'secret' => 'nope'],
    'client_token' => 'SECRET_SERVICE_TOKEN',
    'server_public_ip' => 'INTERNAL_SERVER_IP',
    'provider_line_id' => 7,
]);
$serviceEncoded = json_encode($service, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($serviceEncoded)) {
    fail('failed to encode safe service');
}
foreach (['SECRET_SERVICE_TOKEN', 'INTERNAL_SERVER_IP', 'provider_line_id', 'secret', 'nope'] as $forbidden) {
    trueValue(!str_contains($serviceEncoded, $forbidden), 'safe service leaks ' . $forbidden);
}

$legacy = ForwardLineApiContract::normalizeLegacyCreate([
    'forward_route_id' => 14,
    'listen_port' => 11024,
    'target_host' => '10.0.0.5',
    'target_port' => 443,
    'transport_mode' => 'tcp',
]);
same(14, $legacy['forward_route_id'] ?? null, 'legacy route allowlist');
throwsCode(
    static fn(): array => ForwardLineApiContract::normalizeLegacyCreate([
        'forward_route_id' => 14,
        'listen_port' => 11024,
        'target_host' => '10.0.0.5',
        'target_port' => 443,
        'transport_mode' => 'tcp',
        'public_port' => 443,
    ]),
    'unknown_fields',
    'legacy create rejects placement field'
);
throwsCode(
    static fn(): array => ForwardLineApiContract::normalizeLegacyUpdate([
        'target_host' => '10.0.0.5',
        'target_port' => 443,
        'transport_mode' => 'sctp',
    ]),
    'validation_failed',
    'legacy update rejects unknown transport'
);

$ungatedRoute = ForwardLineApiContract::presentRoute([
    'id' => 15,
    'tenant_label' => 'Plain Route',
    'availability' => null,
]);
foreach (['tcp', 'udp', 'tcp+udp'] as $transport) {
    trueValue(($ungatedRoute['transports'][$transport]['available'] ?? false) === true, 'ungated route should be available for ' . $transport);
}

echo "ok\n";
