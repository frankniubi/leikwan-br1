<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
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

    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Http\Request;
    use Leikwan\Master\Support\AccessPolicyService;
    use Leikwan\Master\Support\AccessPolicyStore;

    /** Minimal store: no geo lock, no rules, no device controls. */
    final class OpenWrtPlatformPolicyStore extends AccessPolicyStore
    {
        public function __construct()
        {
            parent::__construct(new Database());
        }

        public function userById(int $userId, bool $forUpdate = false): ?array
        {
            return $userId > 0 ? ['id' => $userId, 'status' => 'active'] : null;
        }

        public function activeAccountBan(int $userId, bool $forUpdate = false): ?array
        {
            return null;
        }

        public function controlFor(int $clientId, string $deviceUuid): ?array
        {
            return null;
        }

        public function geoLockEnabledForUser(int $userId): bool
        {
            return false;
        }

        public function hasActiveGeographicRules(): bool
        {
            return false;
        }

        public function activeBlacklistRules(?string $entityType = null): array
        {
            return [];
        }

        public function profileForUser(int $userId, bool $forUpdate = false): ?array
        {
            return null;
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
        } catch (\Leikwan\Master\Support\ApiException $exception) {
            assertSame($code, $exception->getMessage(), 'unexpected machine code for ' . $code);
            assertSame($status, $exception->statusCode(), 'unexpected status for ' . $code);
            return;
        }
        fail('expected ApiException ' . $code . ' but no exception was thrown');
    }

    $store = new OpenWrtPlatformPolicyStore();
    $service = new AccessPolicyService(new Database(), null, null, $store);
    $clientContext = new ReflectionMethod($service, 'clientContext');
    $clientContext->setAccessible(true);

    /** @return array<string, mixed> */
    $context = static function (string $platform) use ($service, $clientContext): array {
        $result = $clientContext->invoke($service, ['id' => 11, 'owner_user_id' => 7], 'openwrt-device-1', 'attempt-1', $platform);
        if (!is_array($result)) {
            fail('clientContext must return an array for ' . $platform);
        }
        return $result;
    };

    // New router platform values, including the form-factor alias.
    foreach ([
        'openwrt' => 'openwrt',
        'OpenWRT' => 'openwrt',
        'openwrt-router' => 'openwrt',
        'openwrt-23.05.3' => 'openwrt',
        'router' => 'openwrt',
        'ROUTER' => 'openwrt',
    ] as $input => $expected) {
        assertSame($expected, $context((string) $input)['platform'] ?? null, 'platform normalization for ' . $input);
    }

    // Every pre-existing mapping must stay byte-identical.
    foreach ([
        'win' => 'windows',
        'windows' => 'windows',
        'windows-desktop' => 'windows',
        'mac' => 'macos',
        'osx' => 'macos',
        'macos' => 'macos',
        'darwin-arm64' => 'macos',
        'android' => 'android',
        'android-14' => 'android',
        'ios' => 'ios',
        'ios-17' => 'ios',
        'linux' => 'linux',
        'linux-amd64' => 'linux',
        'unknown' => 'unknown',
        '' => 'unknown',
    ] as $input => $expected) {
        assertSame($expected, $context((string) $input)['platform'] ?? null, 'legacy platform mapping for ' . $input);
    }

    // Anything else must still fail closed.
    foreach (['freebsd', 'routeros', 'openbsd-router', 'x86_64', str_repeat('p', 33)] as $invalid) {
        expectApi('location_platform_invalid', 422, static fn() => $context($invalid));
    }

    // The router must be able to clear the same authorizeClientRequest gate the
    // pull, heartbeat, and lease endpoints use.
    $authorize = static function (string $platform) use ($service): array {
        return $service->authorizeClientRequest(
            ['id' => 11, 'owner_user_id' => 7],
            new Request(
                'GET',
                '/api/client/configs/pull',
                [],
                [],
                ['x-device-id' => 'openwrt-router-1', 'x-device-platform' => $platform],
                '',
                '203.0.113.10'
            ),
            'pull'
        );
    };

    foreach (['openwrt', 'openwrt-router', 'router'] as $platform) {
        assertSame('allow', $authorize($platform)['decision'] ?? null, 'router platform must be authorized: ' . $platform);
    }
    assertSame('allow', $authorize('windows')['decision'] ?? null, 'windows client must stay authorized');
    expectApi('location_platform_invalid', 422, static fn() => $authorize('freebsd'));

    echo "ok\n";
}
