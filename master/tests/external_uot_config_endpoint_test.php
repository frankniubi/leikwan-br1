<?php
declare(strict_types=1);

/**
 * Contract test for GET /external-uot-config.
 *
 * The endpoint renders the per-host external UoT sidecar config that
 * install-external-uot.sh installs as /etc/leikwan-external-uot.json.  These
 * cases pin the parts that a real install depends on: the document is only
 * rendered for a sidecar point that authenticates with its own agent_token
 * (never by host, ip, point_id or the requesting address), which forwards a
 * host is told to accept, per-forward token and target binding, knob
 * carry-over and the listen-port resolution order (request -> combination
 * target -> default).
 */

namespace Leikwan\Master {
    final class Config
    {
        public function __construct()
        {
        }
        public function get(string $key, ?string $default = null): ?string
        {
            $value = getenv($key);
            return $value === false ? $default : $value;
        }
        public function getInt(string $key, int $default = 0): int
        {
            return (int) $this->get($key, (string) $default);
        }
    }
}

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var list<string> */
        public array $queries = [];
        /** @var array<string, mixed>|null */
        public ?array $pointRow = null;
        /** @var list<array<string, mixed>> */
        public array $combinationRows = [];
        /** @var list<array<string, mixed>> */
        public array $forwardRows = [];
        /** @var list<array<string, mixed>> */
        public array $targetRows = [];
        /** @var list<array<string, mixed>> */
        public array $params = [];

        public function __construct(...$ignored)
        {
        }

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $this->queries[] = $sql;
            $this->params[] = $params;
            // Mirrors "WHERE agent_token = ?": only the point's own token finds it.
            if ($this->pointRow === null || ($params[0] ?? null) !== ($this->pointRow['agent_token'] ?? null)) {
                return null;
            }

            return $this->pointRow;
        }

        /**
         * @param array<string, mixed> $params
         * @return list<array<string, mixed>>
         */
        public function fetchAll(string $sql, array $params = []): array
        {
            $this->queries[] = $sql;
            if (str_contains($sql, 'FROM forward_combinations')) {
                return $this->combinationRows;
            }
            if (str_contains($sql, 'target_host')) {
                return $this->targetRows;
            }
            if (str_contains($sql, 'FROM forwards')) {
                return $this->forwardRows;
            }

            return [];
        }
    }
}

namespace {
    require_once __DIR__ . '/../src/Http/Request.php';
    require_once __DIR__ . '/../src/Http/Response.php';
    require_once __DIR__ . '/../src/Controllers/BaseController.php';
    require_once __DIR__ . '/../src/Controllers/ExternalUotConfigController.php';
    require_once __DIR__ . '/../src/Support/ExternalUoTPool.php';

    use Leikwan\Master\Config;
    use Leikwan\Master\Controllers\ExternalUotConfigController;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Http\Request;

    $failures = 0;
    $checks = 0;

    function check(bool $condition, string $label): void
    {
        global $failures, $checks;
        $checks++;
        if ($condition) {
            return;
        }
        $failures++;
        fwrite(STDERR, "FAIL $label\n");
    }

    function checkSame(mixed $expected, mixed $actual, string $label): void
    {
        check(
            $expected === $actual,
            $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
        );
    }

    function combination(int $id, bool $enabled, string $target, string $token, int $queue = 0, int $keepalive = 0, int $grace = 0, int $frame = 0): array
    {
        return [
            'id' => $id,
            'external_uot_enabled' => $enabled ? 1 : 0,
            'external_uot_sidecar_target' => $target,
            'external_uot_token' => $token,
            'external_uot_queue_packets' => $queue,
            'external_uot_keepalive_seconds' => $keepalive,
            'external_uot_reconnect_grace_seconds' => $grace,
            'external_uot_frame_payload_max' => $frame,
        ];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    function call(array $query, ?array $point, array $combinations, array $forwardRows, ?string $remoteAddress = null, array $headers = [], ?array $targetRows = null): array
    {
        global $defaultTargetRows;
        $database = new Database();
        $database->pointRow = $point;
        $database->combinationRows = $combinations;
        $database->forwardRows = $forwardRows;
        $database->targetRows = $targetRows ?? $defaultTargetRows;

        $controller = new ExternalUotConfigController(new Config());
        $property = new ReflectionProperty(\Leikwan\Master\Controllers\BaseController::class, 'db');
        $property->setAccessible(true);
        $property->setValue($controller, $database);

        $request = new Request('GET', '/external-uot-config', $query, [], $headers, '', $remoteAddress);
        $response = $controller->sidecarConfig($request, []);
        $decoded = json_decode($response->body(), true);

        return [$response->status(), is_array($decoded) ? $decoded : [], $response->body()];
    }

    $tokyoPoint = [
        'id' => 5,
        'name' => 'tokyo-nrt1',
        'sidecar_host' => '185.220.238.143',
        'agent_token' => 'point-agent-token',
        'is_active' => 1,
        'last_status' => 'healthy',
    ];
    $bearer = ['Authorization' => 'Bearer point-agent-token'];

    $combinations = [
        combination(51, true, '70.36.96.183:7443', 'token-51', 512, 15, 45, 4096),
        combination(57, true, '185.220.238.143:7443', 'token-57', 128, 8, 20, 8192),
        combination(58, true, '185.220.238.143:7443', 'token-58'),
        combination(59, false, '185.220.238.143:7443', 'token-59'),
        combination(60, true, '185.220.238.143', 'token-60'),
        combination(62, true, '185.220.238.143:7443', 'token-62'),
        combination(63, true, '', 'token-63'),
        combination(64, true, '185.220.238.143:7443', ''),
        combination(65, true, '2001:db8::1:7443', 'token-65'),
    ];

    $forwardRows = [
        ['id' => 170, 'forward_combination_id' => 57],
        ['id' => 171, 'forward_combination_id' => 58],
        ['id' => 172, 'forward_combination_id' => 58],
        ['id' => 171, 'forward_combination_id' => 58],
        ['id' => 190, 'forward_combination_id' => 51],
        ['id' => 200, 'forward_combination_id' => 64],
    ];
    $defaultTargetRows = [
        ['id' => 170, 'target_host' => '203.0.113.10', 'target_port' => 443],
        ['id' => 171, 'target_host' => 'Game.Example.COM', 'target_port' => 27015],
        ['id' => 172, 'target_host' => '2001:DB8:0:0::5', 'target_port' => 9000],
        ['id' => 190, 'target_host' => '198.51.100.20', 'target_port' => 53],
    ];

    // 0. Anonymous, wrong-token and host/ip/point_id/remote-address selection all fail closed.
    foreach ([
        'anonymous' => [[], [], null],
        'host query' => [['host' => '185.220.238.143'], [], null],
        'ip query' => [['ip' => '185.220.238.143'], [], null],
        'point id' => [['point_id' => '5'], [], null],
        'remote address' => [[], [], '185.220.238.143'],
        'wrong bearer' => [[], ['Authorization' => 'Bearer not-the-token'], '185.220.238.143'],
        'wrong query token' => [['agent_token' => 'not-the-token', 'host' => '185.220.238.143'], [], null],
    ] as $label => [$query, $headers, $remote]) {
        [$status, $payload, $raw] = call($query, $tokyoPoint, $combinations, $forwardRows, $remote, $headers);
        checkSame(401, $status, $label . ' is unauthorized');
        checkSame(['error' => 'unauthorized'], $payload, $label . ' body');
        check(!str_contains($raw, 'token-57'), $label . ' leaks no forward token');
    }

    // 1. The point's own bearer token merges every enabled combination on that host, lowest id first.
    [$status, $payload] = call([], $tokyoPoint, $combinations, $forwardRows, null, $bearer);
    checkSame(200, $status, 'bearer status');
    checkSame('0.0.0.0', $payload['listen_host'], 'listen_host');
    checkSame(7443, $payload['listen_port'], 'listen_port from combination target');
    checkSame(['170', '171', '172'], array_map('strval', array_keys($payload['forwards'])), 'forwards keyed by forward id');
    checkSame(['token' => 'token-57', 'targets' => ['203.0.113.10:443']], $payload['forwards']['170'], 'per-forward token bound to its target');
    checkSame(['token' => 'token-58', 'targets' => ['game.example.com:27015']], $payload['forwards']['171'], 'hostname target lower-cased');
    checkSame(['token' => 'token-58', 'targets' => ['[2001:db8::5]:9000']], $payload['forwards']['172'], 'ipv6 target canonical and bracketed');
    checkSame(128, $payload['queue_packets'], 'queue_packets from the lowest matching combination');
    checkSame(8, $payload['keepalive_seconds'], 'keepalive_seconds carried over');
    checkSame(20, $payload['session_grace_seconds'], 'reconnect grace maps to session_grace_seconds');
    checkSame(8192, $payload['frame_payload_max'], 'frame_payload_max carried over');
    checkSame(5, $payload['panel']['matched_point']['id'], 'matched point id');
    checkSame('185.220.238.143', $payload['panel']['match']['host'], 'matched host');
    checkSame('target', $payload['panel']['match']['listen_port_source'], 'listen port source');
    checkSame('bearer', $payload['panel']['request']['agent_token'], 'token source recorded');
    check(!str_contains(json_encode($payload), 'point-agent-token'), 'agent token value is never echoed back');
    checkSame(
        [
            ['id' => 57, 'target' => '185.220.238.143:7443', 'forwards' => [170]],
            ['id' => 58, 'target' => '185.220.238.143:7443', 'forwards' => [171, 172]],
        ],
        $payload['panel']['combinations'],
        'combination summary is ordered and deduplicated'
    );
    $skippedReasons = [];
    foreach ($payload['panel']['skipped'] as $entry) {
        $skippedReasons[$entry['id']] = $entry['reason'];
    }
    checkSame('unparsable-target', $skippedReasons[60] ?? null, 'target without a port is reported, not guessed');
    checkSame('unparsable-target', $skippedReasons[63] ?? null, 'empty target is reported');
    checkSame('no-forwards', $skippedReasons[62] ?? null, 'combination without forwards is skipped');
    checkSame('empty-token', $skippedReasons[64] ?? null, 'combination without a token is skipped');
    check(!isset($skippedReasons[59]), 'disabled combination is ignored entirely');
    check(!isset($skippedReasons[51]), 'combination on another host is not reported as skipped');
    check(str_contains(json_encode($payload), '"forwards":{"170"'), 'forwards serialises as a JSON object');

    // 2. ?agent_token= works for operators; a host query cannot redirect it to another host.
    [$status, $payload] = call(['agent_token' => 'point-agent-token', 'host' => '70.36.96.183'], $tokyoPoint, $combinations, $forwardRows);
    checkSame(200, $status, 'query token status');
    checkSame('query', $payload['panel']['request']['agent_token'], 'query token source recorded');
    checkSame(['170', '171', '172'], array_map('strval', array_keys($payload['forwards'])), 'host query cannot pick another host');

    // 3. An explicit listen_port wins and drops combinations that listen elsewhere.
    [$status, $payload] = call(['listen_port' => '7444'], $tokyoPoint, $combinations, $forwardRows, null, $bearer);
    checkSame(200, $status, 'override status');
    checkSame(7444, $payload['listen_port'], 'override listen_port honoured');
    checkSame('request', $payload['panel']['match']['listen_port_source'], 'override port source');
    checkSame([], $payload['forwards'], 'no forwards on the overridden port');
    checkSame(256, $payload['queue_packets'], 'knobs fall back to defaults without a match');
    check(
        count(array_filter($payload['panel']['skipped'], static fn (array $entry): bool => $entry['reason'] === 'port-mismatch')) === 4,
        'every host combination is reported as port-mismatch for the override'
    );

    // 4. A forward without a usable target is skipped, never published allow-any.
    [$status, $payload] = call([], $tokyoPoint, $combinations, $forwardRows, null, $bearer, [
        ['id' => 170, 'target_host' => '203.0.113.10', 'target_port' => 443],
    ]);
    checkSame(['170'], array_map('strval', array_keys($payload['forwards'])), 'untargeted forwards are dropped');
    $noTarget = array_values(array_filter($payload['panel']['skipped'], static fn (array $entry): bool => $entry['reason'] === 'no-target'));
    checkSame([171, 172], array_map(static fn (array $entry): int => $entry['forward_id'], $noTarget), 'untargeted forwards reported');

    // 5. No enabled combination at all: still a usable document.
    [$status, $payload, $raw] = call([], $tokyoPoint, [combination(59, false, '185.220.238.143:7443', 'token-59')], [], null, $bearer);
    checkSame(200, $status, 'no-enabled status');
    checkSame([], $payload['forwards'], 'no-enabled forwards empty');
    checkSame('default', $payload['panel']['match']['listen_port_source'], 'no-enabled port source');
    check(preg_match('/"forwards":\s*\{\}/', $raw) === 1, 'empty forwards serialise as a JSON object');

    // 6. Several ports on one host: 7443 wins, the other port is reported.
    $multiPort = [
        combination(70, true, '185.220.238.143:7555', 'token-70'),
        combination(71, true, '185.220.238.143:7443', 'token-71'),
    ];
    [$status, $payload] = call([], $tokyoPoint, $multiPort, [['id' => 175, 'forward_combination_id' => 71]], null, $bearer, [
        ['id' => 175, 'target_host' => '203.0.113.11', 'target_port' => 80],
    ]);
    checkSame(7443, $payload['listen_port'], 'default port prefers 7443');
    checkSame(['175'], array_map('strval', array_keys($payload['forwards'])), 'only the 7443 combination is exported');
    checkSame('port-mismatch', $payload['panel']['skipped'][0]['reason'] ?? null, 'other port reported');

    // 7. The pure builder is usable without the database layer; without targets it keeps the old shape.
    $payload = ExternalUotConfigController::buildPayload($tokyoPoint, [combination(57, true, '185.220.238.143:7443', 'token-57')], [57 => [170]]);
    checkSame(['170'], array_map('strval', array_keys((array) $payload['forwards'])), 'builder exports forwards');
    checkSame('default', ExternalUotConfigController::buildPayload(null, [], [])['panel']['match']['listen_port_source'], 'builder defaults without a host');
    checkSame('203.0.113.10:443', ExternalUotConfigController::normalizeForwardTarget(' 203.0.113.10:443 '), 'normalizer trims');
    checkSame(null, ExternalUotConfigController::normalizeForwardTarget('203.0.113.10'), 'normalizer refuses a missing port');

    // Pool endpoint: host selection never substitutes for bearer authentication.
    putenv('EXTERNAL_UOT_POOL_JSON=' . json_encode([[
        'id' => 'la1', 'target' => 'la1.test:7443', 'secret' => str_repeat('s', 32),
        'origins' => ['185.220.238.143:7443'],
    ]]));
    $poolController = new ExternalUotConfigController(new Config());
    $dbProperty = new ReflectionProperty(\Leikwan\Master\Controllers\BaseController::class, 'db');
    $poolDb = $dbProperty->getValue($poolController);
    $poolDb->combinationRows = $combinations;
    $poolDb->forwardRows = $forwardRows;
    $poolDb->targetRows = $defaultTargetRows;
    $poolRequest = static fn(string $method, array $body = [], string $token = '') => new Request(
        $method, '/api/external-uot/pool/la1/config', [], $body,
        $token === '' ? [] : ['Authorization' => 'Bearer ' . $token], ''
    );
    checkSame(401, $poolController->poolNode($poolRequest('GET'), ['id' => 'la1'])->status(), 'pool denies anonymous');
    $poolResponse = $poolController->poolNode($poolRequest('GET', [], str_repeat('s', 32)), ['id' => 'la1']);
    checkSame(200, $poolResponse->status(), 'pool authenticated config');
    $poolPayload = json_decode($poolResponse->body(), true);
    checkSame(['170', '171', '172'], array_map('strval', array_keys($poolPayload['config']['forwards'])), 'pool standby has matching bindings');
    checkSame(['203.0.113.10:443', '1.1.1.1:53'], $poolPayload['config']['forwards']['170']['targets'], 'pool bindings carry their target plus the health probe');
    checkSame(64, strlen($poolPayload['config_hash']), 'pool config digest');
    checkSame(409, $poolController->poolNode($poolRequest('POST', ['healthy' => true, 'config_hash' => str_repeat('0', 64)], str_repeat('s', 32)), ['id' => 'la1'])->status(), 'pool rejects stale config heartbeat');
    checkSame(401, $poolController->poolNode($poolRequest('POST', [], str_repeat('s', 32)), ['id' => 'la2'])->status(), 'pool token scoped to node');
    putenv('EXTERNAL_UOT_POOL_JSON');

    if ($failures > 0) {
        fwrite(STDERR, "external_uot_config_endpoint_test: $failures of $checks checks failed\n");
        exit(1);
    }
    echo "external_uot_config_endpoint_test: $checks checks passed\n";
}
