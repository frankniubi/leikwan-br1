<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        public array $client = [
            'id' => 11,
            'owner_user_id' => 7,
            'name' => 'fixture',
            'status' => 'active',
            'agent_token' => 'fixture-agent-token',
        ];

        public function fetchOne(string $sql, array $params = []): ?array
        {
            return str_contains($sql, 'FROM clients WHERE agent_token')
                && (string) ($params['token'] ?? '') === 'fixture-agent-token'
                ? $this->client
                : null;
        }
        public function fetchAll(string $sql, array $params = []): array { return []; }
        public function execute(string $sql, array $params = []): int { return 0; }
        public function insert(string $table, array $data): int { return 1; }
        public function transaction(callable $callback): mixed { return $callback($this); }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') return;
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', (string) substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require_once $path;
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Http\Request;
    use Leikwan\Master\Support\AccessPolicyService;
    use Leikwan\Master\Support\ProvisioningService;
    use Leikwan\Master\Controllers\ClientAgentController;

    final class ApiPolicyStub extends AccessPolicyService
    {
        public array $platforms = [];
        public function __construct(Database $db) { parent::__construct($db); }
        public function issueLocationChallenge(array $client, string $device, string $attempt, string $platform = 'unknown', ?Request $request = null): array
        {
            $this->platforms[] = $platform;
            return ['protocol_version' => 1, 'token' => 'opaque-challenge-token', 'attempt_id' => $attempt, 'platform' => $platform, 'expires_at' => '2026-08-28T12:05:00Z', 'poll_interval_seconds' => 1800, 'poll_jitter_seconds' => 300];
        }
        public function recordLocationReport(array $client, Request $request): array
        {
            return ['decision' => 'allow', 'geo_lock_enabled' => true, 'main_province_code' => 'SN', 'next_location_check_at' => '2026-08-28 12:31:00'];
        }
        public function policyStatus(array $client, Request $request, ?string $deviceUuid = null): array
        {
            return ['protocol_version' => 1, 'geo_lock_enabled' => true, 'location_required' => true, 'main_province_code' => 'SN', 'next_location_check_at' => '2026-08-28T12:31:00Z'];
        }
    }

    function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    function assertSame(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
    function assertTrue(bool $value, string $message): void { if (!$value) fail($message); }
    function payload(\Leikwan\Master\Http\Response $response): array { $value = json_decode($response->body(), true); return is_array($value) ? $value : []; }
    function apiRequest(string $path, string $platform): Request
    {
        $body = ['token' => 'fixture-agent-token', 'protocol_version' => 1, 'device_uuid' => 'device-' . $platform, 'attempt_id' => 'attempt-' . $platform, 'platform' => $platform];
        return new Request($path === '/api/client/access/policy' ? 'GET' : 'POST', $path, [], $body, ['x-device-platform' => $platform, 'x-device-uuid' => 'device-' . $platform], json_encode($body), '203.0.113.10');
    }

    $db = new Database();
    $config = new Config(__DIR__ . '/missing.env');
    $policy = new ApiPolicyStub($db);
    $controller = new ClientAgentController($config, $db, new ProvisioningService($config, $db), accessPolicy: $policy);
    $challengeKeys = null;
    foreach (['android', 'windows', 'macos', 'ios'] as $platform) {
        $challenge = payload($controller->locationChallenge(apiRequest('/api/client/access/location/challenge', $platform), []));
        assertSame(true, $challenge['ok'] ?? null, $platform . ' challenge ok');
        assertSame(1, $challenge['protocol_version'] ?? null, $platform . ' protocol version');
        assertSame('opaque-challenge-token', $challenge['challenge_token'] ?? null, $platform . ' challenge token');
        $keys = array_keys($challenge);
        sort($keys);
        $challengeKeys ??= $keys;
        assertSame($challengeKeys, $keys, $platform . ' challenge response shape');

        $report = payload($controller->locationReport(apiRequest('/api/client/access/location/report', $platform), []));
        assertSame('allow', $report['decision'] ?? null, $platform . ' report decision');
        $status = payload($controller->accessPolicyStatus(apiRequest('/api/client/access/policy', $platform), []));
        assertSame('SN', $status['main_province_code'] ?? null, $platform . ' policy status');

        $encoded = json_encode([$challenge, $report, $status], JSON_THROW_ON_ERROR);
        foreach (['admin_note', 'rule_id', 'agent_token', 'provider_payload', 'other-device'] as $forbidden) {
            assertTrue(!str_contains($encoded, $forbidden), $platform . ' response leaked ' . $forbidden);
        }
    }
    assertSame(['android', 'windows', 'macos', 'ios'], $policy->platforms, 'all distributions use the same policy service');

    $bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');
    foreach (['/api/client/access/location/challenge', '/api/client/access/location/report', '/api/client/access/policy'] as $route) {
        assertTrue(str_contains($bootstrap, $route), 'missing route ' . $route);
    }
    $publicIndex = (string) file_get_contents(__DIR__ . '/../public/index.php');
    foreach (['location_check_required', 'direct_location_check_required', 'location_report_invalid', 'location_check_unavailable', 'access_blacklisted', 'geo_lock_violation', 'device_temporarily_banned', 'account_banned'] as $code) {
        assertTrue(str_contains($publicIndex, $code), 'public error redaction missing ' . $code);
    }
    echo "ok\n";
}
