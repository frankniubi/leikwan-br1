<?php
declare(strict_types=1);

/**
 * Contract test for GET /external-uot-config.
 *
 * The endpoint renders the per-host external UoT sidecar config that
 * install-external-uot.sh installs as /etc/leikwan-external-uot.json.  These
 * cases pin the parts that a real install depends on: which forwards a host is
 * told to accept, per-forward token wiring, knob carry-over, the listen-port
 * resolution order (request -> combination target -> default) and the fact that
 * an unmatched host still gets a valid, empty document instead of an error.
 */

namespace Leikwan\Master {
    final class Config
    {
        public function __construct()
        {
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

        public function __construct(...$ignored)
        {
        }

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $this->queries[] = $sql;

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
    function call(array $query, ?array $point, array $combinations, array $forwardRows, ?string $remoteAddress = null): array
    {
        $database = new Database();
        $database->pointRow = $point;
        $database->combinationRows = $combinations;
        $database->forwardRows = $forwardRows;

        $controller = new ExternalUotConfigController(new Config());
        $property = new ReflectionProperty(\Leikwan\Master\Controllers\BaseController::class, 'db');
        $property->setAccessible(true);
        $property->setValue($controller, $database);

        $request = new Request('GET', '/external-uot-config', $query, [], [], '', $remoteAddress);
        $response = $controller->sidecarConfig($request, []);
        $decoded = json_decode($response->body(), true);

        return [$response->status(), is_array($decoded) ? $decoded : [], $response->body()];
    }

    $tokyoPoint = [
        'id' => 5,
        'name' => 'tokyo-nrt1',
        'sidecar_host' => '185.220.238.143',
        'is_active' => 1,
        'last_status' => 'healthy',
    ];

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

    // 1. Host lookup merges every enabled combination on that host, lowest id first.
    [$status, $payload] = call(['host' => '185.220.238.143'], $tokyoPoint, $combinations, $forwardRows);
    checkSame(200, $status, 'host lookup status');
    checkSame('0.0.0.0', $payload['listen_host'], 'listen_host');
    checkSame(7443, $payload['listen_port'], 'listen_port from combination target');
    checkSame(['170', '171', '172'], array_map('strval', array_keys($payload['forwards'])), 'forwards keyed by forward id');
    checkSame(['token' => 'token-57', 'targets' => []], $payload['forwards']['170'], 'per-forward token');
    checkSame(['token' => 'token-58', 'targets' => []], $payload['forwards']['171'], 'second combination token');
    checkSame(128, $payload['queue_packets'], 'queue_packets from the lowest matching combination');
    checkSame(8, $payload['keepalive_seconds'], 'keepalive_seconds carried over');
    checkSame(20, $payload['session_grace_seconds'], 'reconnect grace maps to session_grace_seconds');
    checkSame(8192, $payload['frame_payload_max'], 'frame_payload_max carried over');
    checkSame(5, $payload['panel']['matched_point']['id'], 'matched point id');
    checkSame('185.220.238.143', $payload['panel']['match']['host'], 'matched host');
    checkSame('target', $payload['panel']['match']['listen_port_source'], 'listen port source');
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

    // 2. An explicit listen_port wins and drops combinations that listen elsewhere.
    [$status, $payload] = call(['host' => '185.220.238.143', 'listen_port' => '7444'], $tokyoPoint, $combinations, $forwardRows);
    checkSame(200, $status, 'override status');
    checkSame(7444, $payload['listen_port'], 'override listen_port honoured');
    checkSame('request', $payload['panel']['match']['listen_port_source'], 'override port source');
    checkSame([], $payload['forwards'], 'no forwards on the overridden port');
    checkSame(256, $payload['queue_packets'], 'knobs fall back to defaults without a match');
    check(
        count(array_filter($payload['panel']['skipped'], static fn (array $entry): bool => $entry['reason'] === 'port-mismatch')) === 4,
        'every host combination is reported as port-mismatch for the override'
    );

    // 3. An unknown host still receives a valid, empty document.
    [$status, $payload, $raw] = call(['host' => 'unknown.example'], null, $combinations, $forwardRows);
    checkSame(200, $status, 'unknown host status');
    checkSame(null, $payload['panel']['matched_point'], 'unknown host has no matched point');
    checkSame('unknown.example', $payload['panel']['match']['host'], 'requested host echoed');
    checkSame('default', $payload['panel']['match']['listen_port_source'], 'default port source');
    checkSame(7443, $payload['listen_port'], 'default listen port');
    check(preg_match('/"forwards":\s*\{\}/', $raw) === 1, 'empty forwards serialise as a JSON object');

    // 4. The agent token path matches like the host path and never echoes the token.
    [$status, $payload] = call(['agent_token' => 'point-agent-token'], $tokyoPoint, $combinations, $forwardRows);
    checkSame(200, $status, 'agent token status');
    checkSame(['170', '171', '172'], array_map('strval', array_keys($payload['forwards'])), 'agent token resolves the same forwards');
    checkSame('present', $payload['panel']['request']['agent_token'], 'agent token presence recorded');
    check(
        !str_contains(json_encode($payload), 'point-agent-token'),
        'agent token value is never echoed back'
    );

    // 5. No enabled combination at all: still a usable document.
    [$status, $payload] = call(['host' => '185.220.238.143'], $tokyoPoint, [combination(59, false, '185.220.238.143:7443', 'token-59')], []);
    checkSame(200, $status, 'no-enabled status');
    checkSame([], $payload['forwards'], 'no-enabled forwards empty');
    checkSame('default', $payload['panel']['match']['listen_port_source'], 'no-enabled port source');

    // 6. Several ports on one host: 7443 wins, the other port is reported.
    $multiPort = [
        combination(70, true, '185.220.238.143:7555', 'token-70'),
        combination(71, true, '185.220.238.143:7443', 'token-71'),
    ];
    [$status, $payload] = call(['host' => '185.220.238.143'], $tokyoPoint, $multiPort, [['id' => 175, 'forward_combination_id' => 71]]);
    checkSame(7443, $payload['listen_port'], 'default port prefers 7443');
    checkSame(['175'], array_map('strval', array_keys($payload['forwards'])), 'only the 7443 combination is exported');
    checkSame('port-mismatch', $payload['panel']['skipped'][0]['reason'] ?? null, 'other port reported');

    // 7. Bracketed IPv6 targets stay intact through matching.
    [$status, $payload] = call(['host' => '2001:db8::1'], ['id' => 9, 'name' => 'v6', 'sidecar_host' => '2001:db8::1'], $combinations, []);
    checkSame('2001:db8::1', $payload['panel']['match']['host'], 'ipv6 host normalised');
    checkSame([], $payload['forwards'], 'ipv6 case has no forwards fixture');

    // 8. The pure builder is usable without the database layer.
    $payload = ExternalUotConfigController::buildPayload($tokyoPoint, [combination(57, true, '185.220.238.143:7443', 'token-57')], [57 => [170]]);
    checkSame(['170'], array_map('strval', array_keys((array) $payload['forwards'])), 'builder exports forwards');
    checkSame('default', ExternalUotConfigController::buildPayload(null, [], [])['panel']['match']['listen_port_source'], 'builder defaults without a host');

    // 9. A bare config URL resolves against the requesting address, which is how
    //    a fresh sidecar install configures itself with no extra query args.
    [$status, $payload] = call([], $tokyoPoint, $combinations, $forwardRows, '185.220.238.143');
    checkSame(200, $status, 'remote-address status');
    checkSame(['170', '171', '172'], array_map('strval', array_keys($payload['forwards'])), 'remote address resolves the same forwards');
    checkSame('remote-address', $payload['panel']['request']['host_source'], 'remote address recorded as host source');
    checkSame('185.220.238.143', $payload['panel']['match']['host'], 'remote address becomes the match host');
    checkSame('185.220.238.143', $payload['panel']['request']['remote_address'], 'remote address echoed for the operator');

    // 10. No address at all: still a valid, empty document instead of an error.
    [$status, $payload] = call([], $tokyoPoint, $combinations, $forwardRows, null);
    checkSame(200, $status, 'no-address status');
    checkSame('none', $payload['panel']['request']['host_source'], 'no host source recorded');
    checkSame([], $payload['forwards'], 'no address means no forwards');
    checkSame(256, $payload['queue_packets'], 'no address falls back to default knobs');

    // 11. An explicit host still wins over the requesting address.
    [$status, $payload] = call(['host' => '70.36.96.183'], null, $combinations, $forwardRows, '185.220.238.143');
    checkSame('query-host', $payload['panel']['request']['host_source'], 'explicit host wins over remote address');
    checkSame(['190'], array_map('strval', array_keys($payload['forwards'])), 'explicit host selects its own combination');

    if ($failures > 0) {
        fwrite(STDERR, "external_uot_config_endpoint_test: $failures of $checks checks failed\n");
        exit(1);
    }
    echo "external_uot_config_endpoint_test: $checks checks passed\n";
}
