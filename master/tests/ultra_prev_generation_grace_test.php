<?php
declare(strict_types=1);

/**
 * A line-material refresh moves a forward's policy_generation while the client
 * still leases against the generation it pulled before the refresh, which the
 * lease plane answered with policy_generation_invalid (409) until the next pull.
 * The broker now names the pre-refresh generation as leasable for a short window,
 * but only when the client's own ticket proves the line material was the only
 * thing that changed. Driven through the real broker and material service.
 */

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<int, array<string, mixed>> rows the revocation index returns */
        public static array $revokedRows = [];

        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'FROM ultra_client_leases')) {
                return self::$revokedRows;
            }
            return [];
        }

        public function insert(string $table, array $data): int
        {
            return 1;
        }

        public function execute(string $sql, array $params = []): int
        {
            return 0;
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
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', (string) $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\LeigodTokenBroker;
    use Leikwan\Master\Support\UltraMaterialService;
    use Leikwan\Master\Support\UltraMode;

    function assertGrace(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }
    }

    $start = 1_893_456_000;
    $clock = $start;
    $envPath = tempnam(sys_get_temp_dir(), 'lw-grace-env-');
    assertGrace($envPath !== false, 'failed to create test env');
    file_put_contents(
        $envPath,
        "ULTRA_TOKEN_KEY=token-key-for-tests\n"
        . "ULTRA_MAX_TTL_SECONDS=1800\n"
        . "ULTRA_REFRESH_SKEW_SECONDS=600\n"
        . "ULTRA_MATERIAL_MAX_TTL_SECONDS=2100\n"
        . "ULTRA_SOURCE_CREDENTIALS_JSON={\"source-a\":\"source-a-secret\"}\n"
    );

    $config = new Config($envPath);
    $now = static function () use (&$clock): int {
        return $clock;
    };
    $materials = new UltraMaterialService($config, new Database(), null, $now);
    $broker = new LeigodTokenBroker($config, null, $materials, $now);

    $upload = static function (string $generation, string $credential) use ($materials, &$clock): void {
        $ticket = $materials->issueUploadTicket('source-a', [453]);
        $accepted = $materials->acceptSourceUpload('source-a', $ticket, [[
            'line_id' => 453,
            'generation' => $generation,
            'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $clock),
            'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $clock + 1200),
            'user' => 'line-user',
            'credential' => $credential,
            'relays' => [['ip' => '203.0.113.20', 'port' => 5438]],
            'probe' => ['ok' => true],
        ]]);
        assertGrace($accepted === [453], 'material ' . $generation . ' was not accepted');
    };

    $forward = [
        'forward_id' => 42,
        'service_id' => 5,
        'service_status' => 'active',
        'backend_mode' => 'ultra',
        'target' => '198.51.100.10:443',
        'transport' => 'tcp+udp',
        'speed_limit_mbps' => 20,
        '_ultra_line_ids' => [453],
        '_ultra_lines' => [[
            'ultra_line_id' => 10,
            'line_id' => 453,
            'source_id' => 'source-a',
            'ingress_zone' => 'shanghai',
            'destination_zone' => 'tokyo',
            'sort_order' => 10,
        ]],
        '_ultra_line_direction' => 'Shanghai to Japan',
        '_ultra_destination_zone' => 'tokyo',
        '_ultra_allowed_ingress_zones' => ['shanghai', 'beijing'],
        '_ultra_fallback_policy' => 'detected_then_any',
    ];
    $presenting = static function (array $forward, array $grant): array {
        $forward['_ultra_presented'] = [
            'session_ticket' => (string) $grant['session_ticket'],
            'policy_generation' => (string) $grant['policy_generation'],
        ];
        return $forward;
    };
    $grantFor = static function (array $forward, string $device = 'device-a') use ($broker): array {
        $grants = $broker->grantsForClientForwards(7, $device, [$forward], true);
        $grant = $grants['42'] ?? null;
        assertGrace(is_array($grant), 'ultra forward did not receive a grant');
        return $grant;
    };

    // The grant the client holds before the refresh.
    Database::$revokedRows = [];
    $upload('material-generation-1', '1234567890123456');
    $before = $grantFor($forward);
    $oldGeneration = (string) $before['policy_generation'];
    $oldExpiry = strtotime((string) $before['expires_at']);
    assertGrace(!isset($before['previous_generation_grace']), 'a plain pull must not carry a grace');

    // The source refreshes the line a minute later: the forward's generation moves.
    $clock = $start + 60;
    $upload('material-generation-2', '6543210987654321');

    // 1. Inside the window, the pre-refresh generation is named as leasable.
    $after = $grantFor($presenting($forward, $before));
    assertGrace($after['policy_generation'] !== $oldGeneration, 'the refresh did not move the generation');
    $grace = $after['previous_generation_grace'] ?? null;
    assertGrace(is_array($grace), 'a material-only refresh inside the window must grant the previous generation');
    assertGrace($grace['policy_generation'] === $oldGeneration, 'the grace must name the presented generation');
    $graceUntil = strtotime((string) $grace['expires_at']);
    assertGrace($graceUntil !== false && $graceUntil <= $oldExpiry, 'the grace must not outlive the presented ticket');
    assertGrace($graceUntil <= strtotime((string) $after['expires_at']), 'the grace must not outlive the current grant');

    // 2. The grace is per request and never served from the grant cache.
    $plain = $grantFor($forward);
    assertGrace(!isset($plain['previous_generation_grace']), 'a cached grant leaked the grace to a pull that presented nothing');

    // 3. Presenting the current generation is not a grace case.
    $current = $grantFor($presenting($forward, $after));
    assertGrace(!isset($current['previous_generation_grace']), 'the current generation must not be granted a grace');

    // 4. Something other than the line material changed (the target): refused.
    $retargeted = $forward;
    $retargeted['target'] = '198.51.100.11:443';
    $moved = $grantFor($presenting($retargeted, $before));
    assertGrace(!isset($moved['previous_generation_grace']), 'a target change must not be covered by the grace');

    // 5. A rate change: refused.
    $rerated = $forward;
    $rerated['speed_limit_mbps'] = 50;
    $rated = $grantFor($presenting($rerated, $before));
    assertGrace(!isset($rated['previous_generation_grace']), 'a rate change must not be covered by the grace');

    // 6. A forged generation that does not match the presented ticket: refused.
    $forged = $presenting($forward, $before);
    $forged['_ultra_presented']['policy_generation'] = str_repeat('b', 64);
    $forgedGrant = $grantFor($forged);
    assertGrace(!isset($forgedGrant['previous_generation_grace']), 'a generation the ticket does not carry must be refused');

    // 7. Another device presenting this device's ticket: refused.
    $stolen = $grantFor($presenting($forward, $before), 'device-b');
    assertGrace(!isset($stolen['previous_generation_grace']), "another device's ticket must not earn a grace");

    // 8. The presented generation was revoked: refused.
    Database::$revokedRows = [[
        'forward_id' => 42,
        'policy_generation' => $oldGeneration,
        'epoch' => 901,
    ]];
    $revoked = $grantFor($presenting($forward, $before));
    assertGrace(!isset($revoked['previous_generation_grace']), 'a revoked generation must never be granted a grace');
    Database::$revokedRows = [];

    // 9. After the window: refused.
    $clock = $start + 60 + UltraMode::PREVIOUS_GENERATION_GRACE_SECONDS + 1;
    $late = $grantFor($presenting($forward, $before));
    assertGrace(!isset($late['previous_generation_grace']), 'the grace must end with its window');

    fwrite(STDOUT, 'ok' . PHP_EOL);
    exit(0);
}
