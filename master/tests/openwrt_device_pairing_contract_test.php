<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $clients = [];
        /** @var array<int, array<string, mixed>> */
        public array $codes = [];
        /** @var array<int, array<string, mixed>> */
        public array $sessions = [];
        public string $lastExecuteSql = '';
        private int $nextCodeId = 1;
        private int $nextSessionId = 1;

        /** @param array<string, mixed> $data */
        public function seedClient(int $id, string $name, string $agentToken, string $status = 'active'): void
        {
            $this->clients[$id] = [
                'id' => $id,
                'name' => $name,
                'agent_token' => $agentToken,
                'status' => $status,
                'owner_user_id' => 7,
            ];
        }

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

            if (str_contains($normalized, 'FROM openwrt_pairing_codes')) {
                foreach ($this->codes as $row) {
                    if ((string) ($row['code_hash'] ?? '') === (string) ($params['code_hash'] ?? '')) {
                        return $row;
                    }
                }
                return null;
            }

            if (str_contains($normalized, 'FROM clients WHERE id = :id AND status = :status')) {
                $client = $this->clients[(int) ($params['id'] ?? 0)] ?? null;
                if ($client === null || (string) ($client['status'] ?? '') !== (string) ($params['status'] ?? '')) {
                    return null;
                }
                return $client;
            }

            if (str_contains($normalized, 'FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id')) {
                foreach ($this->sessions as $row) {
                    if ((int) ($row['client_id'] ?? 0) === (int) ($params['client_id'] ?? 0)
                        && (string) ($row['device_id'] ?? '') === (string) ($params['device_id'] ?? '')) {
                        return $row;
                    }
                }
                return null;
            }

            return null;
        }

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            return [];
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int
        {
            if ($table === 'openwrt_pairing_codes') {
                $id = $this->nextCodeId++;
                $this->codes[$id] = ['id' => $id] + $data;
                return $id;
            }
            if ($table === 'client_device_sessions') {
                $id = $this->nextSessionId++;
                $this->sessions[$id] = ['id' => $id] + $data;
                return $id;
            }
            return 1;
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            $this->lastExecuteSql = $normalized;

            if (str_contains($normalized, 'UPDATE openwrt_pairing_codes')) {
                $id = (int) ($params['id'] ?? 0);
                if (!isset($this->codes[$id]) || $this->codes[$id]['used_at'] !== null) {
                    return 0;
                }
                $this->codes[$id]['used_at'] = $params['used_at'] ?? null;
                return 1;
            }

            if (str_contains($normalized, 'UPDATE client_device_sessions')) {
                foreach ($this->sessions as $id => $row) {
                    if ((int) ($row['id'] ?? 0) !== (int) ($params['id'] ?? 0)) {
                        continue;
                    }
                    foreach ($params as $key => $value) {
                        if ($key !== 'id') {
                            $this->sessions[$id][$key] = $value;
                        }
                    }
                    return 1;
                }
                return 0;
            }

            return 0;
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
    use Leikwan\Master\Support\OpenWrtPairingCodeService;
    use Leikwan\Master\Support\ProvisioningService;

    /** Records the policy boundary the pairing endpoint must clear. */
    final class PairingPolicyProbe extends AccessPolicyService
    {
        /** @var array<int, array<string, mixed>> */
        public array $calls = [];

        public function __construct(Database $db)
        {
            parent::__construct($db);
        }

        public function authorizeClientRequest(array $client, Request $request, string $touchType = 'request', ?string $deviceUuid = null): array
        {
            $this->calls[] = [
                'client_id' => (int) ($client['id'] ?? 0),
                'touch_type' => $touchType,
                'device_uuid' => $deviceUuid,
                'platform' => strtolower(trim((string) ($request->header('x-device-platform') ?? $request->input('platform', '')))),
            ];
            return ['decision' => 'allow', 'touch_type' => $touchType];
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

    /**
     * @param array<string, mixed> $overrides body keys (null removes) or "header:name" keys
     */
    function pairRequest(string $code, string $deviceId, array $overrides = []): Request
    {
        $body = [
            'pairing_code' => $code,
            'device_id' => $deviceId,
            'device_name' => 'Leikwan Bridge on router',
            'platform' => 'openwrt',
            'architecture' => 'arm64',
        ];
        $headers = [
            'x-device-id' => $deviceId,
            'x-device-name' => 'Leikwan Bridge on router',
            'x-device-platform' => 'openwrt',
            'x-device-form-factor' => 'router',
            'x-device-architecture' => 'arm64',
            'user-agent' => 'Leikwan-Client-Agent/0.9.0 (OpenWrt)',
        ];
        foreach ($overrides as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, 'header:')) {
                $name = substr($key, 7);
                if ($value === null) {
                    unset($headers[$name]);
                } else {
                    $headers[$name] = (string) $value;
                }
                continue;
            }
            if ($value === null) {
                unset($body[$key]);
            } else {
                $body[$key] = $value;
            }
        }
        return new Request(
            'POST',
            '/api/client/devices/openwrt/pair',
            [],
            $body,
            $headers,
            (string) json_encode($body, JSON_THROW_ON_ERROR),
            '203.0.113.10'
        );
    }

    /** @return array<string, mixed>|null */
    function sessionFor(Database $db, string $deviceId): ?array
    {
        foreach ($db->sessions as $row) {
            if ((string) ($row['device_id'] ?? '') === $deviceId) {
                return $row;
            }
        }
        return null;
    }

    function expireCode(Database $db, string $rawCode): void
    {
        $hash = hash('sha256', str_replace('-', '', $rawCode));
        foreach ($db->codes as $id => $row) {
            if ((string) ($row['code_hash'] ?? '') === $hash) {
                $db->codes[$id]['expires_at'] = gmdate('Y-m-d H:i:s', time() - 5);
                return;
            }
        }
        fail('failed to locate the pairing code row to expire');
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-openwrt-pair-');
    if ($envPath === false) {
        fail('could not create fixture configuration');
    }
    file_put_contents($envPath, "OPENWRT_PAIRING_ENABLED=true\n");
    $disabledEnvPath = tempnam(sys_get_temp_dir(), 'lw-openwrt-off-');
    if ($disabledEnvPath === false) {
        fail('could not create fixture configuration');
    }
    file_put_contents($disabledEnvPath, "OPENWRT_PAIRING_ENABLED=false\n");

    $db = new Database();
    $db->seedClient(12, 'Home Router', 'tok-12');
    $db->seedClient(13, 'Someone Else', 'tok-13');
    $db->seedClient(14, 'Disabled Client', 'tok-14', 'disabled');

    $config = new Config($envPath);
    $policy = new PairingPolicyProbe($db);
    $controller = new ClientAgentController($config, $db, new ProvisioningService($config, $db), accessPolicy: $policy);
    $pairingCodes = new OpenWrtPairingCodeService($db, $config);

    // 1. A valid pairing code returns the agent token and binds the device.
    $issued = $pairingCodes->issue(12, 1);
    $code = (string) $issued['code'];
    $response = $controller->pairOpenWrtDevice(pairRequest($code, 'openwrt-aaaa1111'), []);
    $body = payload($response);
    assertSame(200, $response->status(), 'pairing must answer 200');
    assertSame(['ok', 'client_id', 'client_name', 'agent_token'], array_keys($body), 'pairing response shape');
    assertSame(true, $body['ok'], 'pairing ok flag');
    assertSame(12, $body['client_id'], 'pairing must bind the code client');
    assertSame('Home Router', $body['client_name'], 'pairing must return the client name');
    assertSame('tok-12', $body['agent_token'], 'pairing must return the stored agent token');
    assertSame('no-store, no-cache, must-revalidate', $response->headers()['Cache-Control'] ?? null, 'pairing responses must not be cached');
    $session = sessionFor($db, 'openwrt-aaaa1111');
    assertTrue($session !== null, 'pairing must bind the device to a client session');
    assertSame(12, (int) ($session['client_id'] ?? 0), 'device session must belong to the code client');
    assertSame('openwrt', (string) ($session['platform'] ?? ''), 'device session must record the router platform');
    assertSame('Leikwan Bridge on router', (string) ($session['device_name'] ?? ''), 'device session must record the router name');
    assertSame(1, count($policy->calls), 'pairing must clear the access policy once');
    assertSame('enrollment', (string) ($policy->calls[0]['touch_type'] ?? ''), 'pairing must use the enrollment touch type');
    assertSame('openwrt-aaaa1111', (string) ($policy->calls[0]['device_uuid'] ?? ''), 'pairing must authorize the requested device');
    assertSame('openwrt', (string) ($policy->calls[0]['platform'] ?? ''), 'pairing must report the router platform to policy');
    assertSame(null, $pairingCodes->findActive($code), 'a redeemed code must be consumed');

    // 2. The same code cannot be redeemed twice.
    expectApi('openwrt_pairing_code_invalid', 403, static fn() => $controller->pairOpenWrtDevice(pairRequest($code, 'openwrt-bbbb2222'), []));
    assertTrue(sessionFor($db, 'openwrt-bbbb2222') === null, 'a replay must not bind a second device');
    assertSame(1, count($db->sessions), 'a replay must not create a second session row');

    // 3. An expired code is rejected and stays unused.
    $expired = $pairingCodes->issue(12, 1);
    $expiredCode = (string) $expired['code'];
    expireCode($db, $expiredCode);
    expectApi('openwrt_pairing_code_expired', 403, static fn() => $controller->pairOpenWrtDevice(pairRequest($expiredCode, 'openwrt-cccc3333'), []));
    assertTrue(sessionFor($db, 'openwrt-cccc3333') === null, 'an expired code must not bind a device');
    assertTrue($pairingCodes->findByCode($expiredCode) !== null, 'an expired code row stays for audit');

    // 4. A code scoped to client A can never produce a session for client B.
    $scoped = $pairingCodes->issue(12, 1);
    $scopedCode = (string) $scoped['code'];
    expectApi('client_id_mismatch', 403, static fn() => $controller->pairOpenWrtDevice(pairRequest($scopedCode, 'openwrt-dddd4444', ['client_id' => 13]), []));
    assertTrue(sessionFor($db, 'openwrt-dddd4444') === null, 'a mismatched client id must not bind a device');
    $scopedBody = payload($controller->pairOpenWrtDevice(pairRequest($scopedCode, 'openwrt-eeee5555', ['client_id' => 12]), []));
    assertSame(12, $scopedBody['client_id'] ?? null, 'the pairing code client is authoritative');
    foreach ($db->sessions as $row) {
        assertTrue((int) ($row['client_id'] ?? 0) !== 13, 'client B must never receive a session from client A code');
    }
    $otherClient = $pairingCodes->issue(13, 1);
    $otherBody = payload($controller->pairOpenWrtDevice(pairRequest((string) $otherClient['code'], 'openwrt-ffff6666'), []));
    assertSame(13, $otherBody['client_id'] ?? null, 'a code issued for another client binds that client only');
    assertSame(13, (int) (sessionFor($db, 'openwrt-ffff6666')['client_id'] ?? 0), 'session binding follows the code client');

    // 5. An invalid device identity is rejected before the code is consumed.
    $deviceCode = (string) $pairingCodes->issue(12, 1)['code'];
    expectApi('openwrt_pairing_device_invalid', 422, static fn() => $controller->pairOpenWrtDevice(pairRequest($deviceCode, '', ['device_id' => null, 'header:x-device-id' => null]), []));
    expectApi('openwrt_pairing_device_invalid', 422, static fn() => $controller->pairOpenWrtDevice(pairRequest($deviceCode, 'openwrt bad id'), []));
    expectApi('openwrt_pairing_device_invalid', 422, static fn() => $controller->pairOpenWrtDevice(pairRequest($deviceCode, str_repeat('d', 192)), []));
    expectApi('openwrt_pairing_device_invalid', 422, static fn() => $controller->pairOpenWrtDevice(pairRequest($deviceCode, 'openwrt-gggg7777', ['header:x-device-id' => 'openwrt-hhhh8888']), []));
    assertTrue($pairingCodes->findActive($deviceCode) !== null, 'a rejected device identity must not consume the code');
    $recovered = payload($controller->pairOpenWrtDevice(pairRequest($deviceCode, 'openwrt-iiii9999'), []));
    assertSame(true, $recovered['ok'] ?? null, 'the still-valid code must remain redeemable');
    assertSame(12, $recovered['client_id'] ?? null, 'the recovered redemption binds the code client');
    assertSame(12, (int) (sessionFor($db, 'openwrt-iiii9999')['client_id'] ?? 0), 'the recovered redemption records the code client');

    // 6. A disabled feature answers openwrt_pairing_unavailable and consumes nothing.
    $unavailableCode = (string) $pairingCodes->issue(12, 1)['code'];
    $disabledConfig = new Config($disabledEnvPath);
    $disabledController = new ClientAgentController($disabledConfig, $db, new ProvisioningService($disabledConfig, $db), accessPolicy: new PairingPolicyProbe($db));
    expectApi('openwrt_pairing_unavailable', 503, static fn() => $disabledController->pairOpenWrtDevice(pairRequest($unavailableCode, 'openwrt-jjjj0000'), []));
    assertTrue((new OpenWrtPairingCodeService($db, $disabledConfig))->findActive($unavailableCode) !== null, 'a disabled feature must not consume codes');

    // 7. A disabled client record cannot be paired.
    $disabledClientCode = (string) $pairingCodes->issue(14, 1)['code'];
    expectApi('openwrt_pairing_code_invalid', 403, static fn() => $controller->pairOpenWrtDevice(pairRequest($disabledClientCode, 'openwrt-kkkk1111'), []));

    // 8. Static contract.
    $bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');
    assertTrue(
        str_contains($bootstrap, "\$this->router->add('POST', '/api/client/devices/openwrt/pair', [\$clientAgent, 'pairOpenWrtDevice']);"),
        'the router pairing route must be registered'
    );
    assertTrue(str_contains($bootstrap, "\$this->router->add('POST', '/admin/clients/{id}/openwrt-pairing-code', [\$admin, 'createOpenWrtPairingCode']);"), 'the admin pairing route must be registered');
    foreach (["'/api/client/configs/pull'", "'/api/client/heartbeat'", 'UltraMode::LEASE_ENDPOINT'] as $untouched) {
        assertTrue(str_contains($bootstrap, $untouched), 'existing client route must stay registered: ' . $untouched);
    }

    $controllerSource = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
    $start = strpos($controllerSource, 'public function pairOpenWrtDevice(');
    $end = strpos($controllerSource, 'public function resolveUltraMaterials(', $start === false ? 0 : $start);
    if ($start === false || $end === false) {
        fail('could not isolate pairOpenWrtDevice');
    }
    $pairingMethod = substr($controllerSource, $start, $end - $start);
    foreach ([
        'openwrt_pairing_code_invalid',
        'openwrt_pairing_code_expired',
        'enforceFromRequest(',
        'transaction(',
        "requestDeviceIdentity(\$request, true, 'openwrt_pairing_device_invalid')",
        'authorizeClientRequest(',
    ] as $required) {
        assertTrue(str_contains($pairingMethod, $required), 'pairing endpoint is missing ' . $required);
    }
    foreach (['requireAndroidRequest', 'enrollmentCodes', 'attestation'] as $forbidden) {
        assertTrue(!str_contains($pairingMethod, $forbidden), 'pairing endpoint must not reuse Android controls: ' . $forbidden);
    }

    $publicIndex = (string) file_get_contents(__DIR__ . '/../public/index.php');
    foreach ([
        'openwrt_pairing_unavailable',
        'openwrt_pairing_code_invalid',
        'openwrt_pairing_code_expired',
        'openwrt_pairing_device_invalid',
    ] as $codeName) {
        assertTrue(str_contains($publicIndex, $codeName), 'public error envelope is missing ' . $codeName);
    }

    $admin = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
    assertTrue(str_contains($admin, 'public function createOpenWrtPairingCode('), 'admin pairing action is missing');
    assertTrue(str_contains($admin, "/admin/clients/' . \$clientId . '/openwrt-pairing-code\""), 'admin pairing form is missing');

    $migration = (string) file_get_contents(__DIR__ . '/../migrations/20260911_add_openwrt_pairing_codes.sql');
    foreach (['openwrt_pairing_codes', 'client_id', 'code_hash', 'expires_at', 'used_at', 'created_by_user_id'] as $column) {
        assertTrue(str_contains($migration, $column), 'migration is missing ' . $column);
    }
    assertTrue(!str_contains($migration, 'code VARCHAR'), 'migration must not store the plaintext code');
    $schema = (string) file_get_contents(__DIR__ . '/../migrations/schema.sql');
    assertTrue(str_contains($schema, 'CREATE TABLE IF NOT EXISTS openwrt_pairing_codes'), 'fresh schema must include the pairing table');

    $policySource = (string) file_get_contents(__DIR__ . '/../src/Support/AccessPolicyService.php');
    assertTrue(str_contains($policySource, 'public static function isRouterPlatform(string $platform): bool'), 'policy must expose the shared router classifier');
    assertTrue(str_contains($policySource, "str_starts_with(\$platform, 'openwrt') || \$platform === 'router'"), 'policy must map the openwrt distribution and the router form factor');
    assertTrue(str_contains($policySource, "self::isRouterPlatform(\$platform) => 'openwrt'"), 'clientContext must reuse the shared router classifier');
    assertTrue(str_contains($policySource, "'linux', 'openwrt', 'unknown'"), 'policy must allow the openwrt platform');
    assertTrue(str_contains($controllerSource, 'AccessPolicyService::isRouterPlatform($platform)'), 'the location gate must reuse the shared router classifier');

    $android = (string) file_get_contents(__DIR__ . '/../src/Support/AndroidEnrollmentCodeService.php');
    assertTrue(!str_contains($android, 'openwrt'), 'Android enrolment codes must stay untouched by router pairing');

    @unlink($envPath);
    @unlink($disabledEnvPath);
    echo "ok\n";
}
