<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, mixed> */
        public array $client = [
            'id' => 12,
            'name' => 'Home Router',
            'agent_token' => 'tok-12',
            'status' => 'active',
            'owner_user_id' => 7,
        ];

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            return str_contains($sql, 'FROM clients WHERE agent_token')
                && (string) ($params['token'] ?? '') === 'tok-12'
                ? $this->client
                : null;
        }

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            return [];
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            return 0;
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int
        {
            return 1;
        }

        public function transaction(callable $callback): mixed
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
        if ($relative === false) {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Controllers\ClientAgentController;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Http\Request;
    use Leikwan\Master\Http\Response;
    use Leikwan\Master\Support\AccessPolicyService;
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\ProvisioningService;

    /** Records what the location endpoints hand to the policy layer. */
    final class LocationPlatformPolicyProbe extends AccessPolicyService
    {
        /** @var array<int, array<string, mixed>> */
        public array $calls = [];

        public function __construct(Database $db)
        {
            parent::__construct($db);
        }

        public function issueLocationChallenge(array $client, string $deviceUuid, string $attemptId, string $platform = 'unknown', ?Request $request = null): array
        {
            $this->calls[] = ['entry' => 'challenge', 'platform' => $platform];
            return [
                'protocol_version' => 1,
                'token' => 'opaque-challenge-token',
                'attempt_id' => $attemptId,
                'platform' => $platform,
                'expires_at' => '2026-09-11T12:05:00Z',
                'poll_interval_seconds' => 1800,
                'poll_jitter_seconds' => 300,
            ];
        }

        public function policyStatus(array $client, Request $request, ?string $deviceUuid = null): array
        {
            $this->calls[] = ['entry' => 'status', 'platform' => strtolower(trim((string) ($request->header('x-device-platform') ?? $request->input('platform', ''))))];
            return [
                'protocol_version' => 1,
                'geo_lock_enabled' => true,
                'location_required' => true,
                'main_province_code' => 'SN',
                'next_location_check_at' => '2026-09-11T12:31:00Z',
            ];
        }
    }

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            fail($message);
        }
    }

    function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function expectApi(string $code, int $status, callable $callback): void
    {
        try {
            $callback();
        } catch (ApiException $exception) {
            assertSame($code, $exception->getMessage(), 'unexpected machine code for ' . $code);
            assertSame($status, $exception->statusCode(), 'unexpected status for ' . $code);
            return;
        }
        fail('expected ApiException ' . $code . ' but no exception was thrown');
    }

    /** @return array<string, mixed> */
    function payload(Response $response): array
    {
        $decoded = json_decode($response->body(), true);
        return is_array($decoded) ? $decoded : [];
    }

    function platformRequest(string $path, string $platform): Request
    {
        $headers = [
            'authorization' => 'Bearer tok-12',
            'x-device-platform' => $platform,
            'x-device-uuid' => 'router-device-1',
            'x-attempt-id' => 'attempt-router-1',
        ];
        return new Request($path === '/api/client/access/policy' ? 'GET' : 'POST', $path, [], ['protocol_version' => 1], $headers, '', '203.0.113.10');
    }

    $db = new Database();
    $config = new Config(__DIR__ . '/missing.env');
    $probe = new LocationPlatformPolicyProbe($db);
    $controller = new ClientAgentController($config, $db, new ProvisioningService($config, $db), accessPolicy: $probe);
    $gate = new ReflectionMethod($controller, 'accessPolicyPlatform');
    $gate->setAccessible(true);

    // 1. The controller gate accepts the router distribution and form factor,
    //    and keeps the four existing platforms byte-identical.
    foreach ([
        'openwrt' => 'openwrt',
        'OpenWRT' => 'openwrt',
        ' openwrt ' => 'openwrt',
        'openwrt-router' => 'openwrt-router',
        'openwrt-23.05.3' => 'openwrt-23.05.3',
        'router' => 'router',
        'ROUTER' => 'router',
        'android' => 'android',
        'windows' => 'windows',
        'macos' => 'macos',
        'ios' => 'ios',
    ] as $sent => $returned) {
        assertSame(
            $returned,
            $gate->invoke($controller, platformRequest('/api/client/access/location/challenge', (string) $sent)),
            'accessPolicyPlatform must accept ' . $sent
        );
    }

    // 2. Everything else still fails closed, before the policy is consulted.
    foreach (['freebsd', 'routeros', 'linux', 'linux-amd64', 'windows-desktop', ''] as $rejected) {
        expectApi(
            'location_platform_invalid',
            422,
            static fn() => $gate->invoke($controller, platformRequest('/api/client/access/location/challenge', (string) $rejected))
        );
    }
    assertSame([], $probe->calls, 'a rejected platform must not reach the policy layer');

    // 3. Every value the gate accepts is classified as openwrt by the shared
    //    policy classifier for router inputs (and unchanged for the rest).
    $policy = new AccessPolicyService($db);
    $clientContext = new ReflectionMethod($policy, 'clientContext');
    $clientContext->setAccessible(true);
    foreach (['openwrt', 'OpenWRT', 'openwrt-router', 'openwrt-23.05.3', 'router', 'ROUTER'] as $routerValue) {
        $context = $clientContext->invoke($policy, ['id' => 12, 'owner_user_id' => 7], 'router-device-1', 'attempt-1', $routerValue);
        assertSame('openwrt', $context['platform'] ?? null, 'the gate and the policy must agree on ' . $routerValue);
    }
    foreach (['android', 'windows', 'macos', 'ios'] as $existing) {
        $context = $clientContext->invoke($policy, ['id' => 12, 'owner_user_id' => 7], 'device-1', 'attempt-1', $existing);
        assertSame($existing, $context['platform'] ?? null, 'existing platform classification must not move: ' . $existing);
    }

    // 4. End to end: a router now reaches the challenge and status endpoints
    //    instead of stopping at location_platform_invalid.
    foreach (['openwrt', 'openwrt-router', 'router'] as $routerValue) {
        $challenge = payload($controller->locationChallenge(platformRequest('/api/client/access/location/challenge', $routerValue), []));
        assertSame(true, $challenge['ok'] ?? null, 'router challenge ok for ' . $routerValue);
        assertSame($routerValue, $challenge['platform'] ?? null, 'the challenge must carry the router platform: ' . $routerValue);
        assertSame('opaque-challenge-token', $challenge['challenge_token'] ?? null, 'router challenge token for ' . $routerValue);

        $status = payload($controller->accessPolicyStatus(platformRequest('/api/client/access/policy', $routerValue), []));
        assertSame(true, $status['ok'] ?? null, 'router policy status ok for ' . $routerValue);
    }
    assertSame(6, count($probe->calls), 'three routers must reach both policy entry points');
    assertSame(['challenge', 'status', 'challenge', 'status', 'challenge', 'status'], array_column($probe->calls, 'entry'), 'policy entry order');

    $before = count($probe->calls);
    expectApi('location_platform_invalid', 422, static fn() => $controller->locationChallenge(platformRequest('/api/client/access/location/challenge', 'freebsd'), []));
    expectApi('location_platform_invalid', 422, static fn() => $controller->accessPolicyStatus(platformRequest('/api/client/access/policy', 'freebsd'), []));
    assertSame($before, count($probe->calls), 'unknown platforms must stay blocked before the policy layer');

    // 5. The shared classifier stays the single source of truth.
    foreach (['openwrt', 'OpenWRT', 'openwrt-router', 'openwrt-23.05.3', 'router', 'ROUTER'] as $routerValue) {
        assertTrue(AccessPolicyService::isRouterPlatform($routerValue), 'isRouterPlatform must accept ' . $routerValue);
    }
    foreach (['freebsd', 'routeros', 'linux', 'android', 'windows', 'macos', 'ios', ''] as $notRouter) {
        assertTrue(!AccessPolicyService::isRouterPlatform($notRouter), 'isRouterPlatform must reject ' . $notRouter);
    }

    echo "ok\n";
}
