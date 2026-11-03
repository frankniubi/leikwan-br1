<?php
declare(strict_types=1);

/**
 * An ENABLED Ultra forward that has no lease, or whose runtime reports
 * awaiting_policy/degraded, must be surfaced as a HEALTH FAILURE.
 *
 * Regression sentinel: awaiting_policy_is_a_health_failure
 *
 * The panel used to derive a forward's status from its "enabled" DB flag alone
 * (TenantController::serviceDetail()) and, on the admin Ultra page, from
 * heartbeat recency alone (UltraClientStatusService::sessionState()), while the
 * ingested per-forward runtime error_code was dropped before it reached the
 * table. An operator therefore saw a green, enabled, "online" forward that was
 * carrying no traffic at all.
 */

namespace Leikwan\Master {

    final class Config
    {
        /** @var array<string, string> */
        private array $values = [];

        public function __construct(string $envPath)
        {
            foreach (@file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (!is_string($line) || $line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $this->values[trim($key)] = trim($value);
            }
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->values[$key] ?? $default;
        }

        public function getInt(string $key, int $default = 0): int
        {
            return (int) ($this->values[$key] ?? $default);
        }

        public function getBool(string $key, bool $default = false): bool
        {
            if (!array_key_exists($key, $this->values)) {
                return $default;
            }
            return in_array(strtolower((string) $this->values[$key]), ['1', 'true', 'yes', 'on'], true);
        }
    }
}

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public array $tables = [];

        /** @param array<int, array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /** @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

            if (str_contains($normalized, 'FROM ultra_client_leases')) {
                $forwardIds = $this->intList($normalized);
                $now = (string) ($params['now'] ?? '');
                $rows = [];
                $liveUntil = [];
                foreach ($this->tables['ultra_client_leases'] ?? [] as $row) {
                    $forwardId = (int) ($row['forward_id'] ?? 0);
                    if ($forwardIds !== [] && !in_array($forwardId, $forwardIds, true)) {
                        continue;
                    }
                    if ((string) ($row['revoked_at'] ?? '') !== '') {
                        continue;
                    }
                    if ((string) ($row['expires_at'] ?? '') <= $now) {
                        continue;
                    }
                    $liveUntil[$forwardId] = max((string) ($liveUntil[$forwardId] ?? ''), (string) $row['expires_at']);
                }
                foreach ($liveUntil as $forwardId => $until) {
                    $rows[] = ['forward_id' => $forwardId, 'live_until' => $until];
                }
                return $rows;
            }

            if (str_contains($normalized, 'FROM client_device_sessions')) {
                $rows = $this->tables['client_device_sessions'] ?? [];
                usort($rows, static fn(array $left, array $right): int => strcmp(
                    (string) ($right['last_seen_at'] ?? ''),
                    (string) ($left['last_seen_at'] ?? '')
                ));
                return $rows;
            }

            return [];
        }

        /** @return array<int, int> */
        private function intList(string $normalized): array
        {
            if (preg_match('/forward_id IN \(([0-9, ]+)\)/', $normalized, $matches) !== 1) {
                return [];
            }
            return array_values(array_filter(array_map('intval', explode(',', $matches[1]))));
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
    use Leikwan\Master\Support\UltraClientStatusService;

    function failUltraHealth(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertUltraHealth(bool $condition, string $message): void
    {
        if (!$condition) {
            failUltraHealth($message);
        }
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-ultra-health-');
    if ($envPath === false) {
        failUltraHealth('failed to create temp env file');
    }
    file_put_contents($envPath, "ULTRA_TOKEN_KEY=test-key\n");
    $config = new Config($envPath);

    // The wedge itself: the runtime reports awaiting_policy while the session is
    // still heartbeating, so heartbeat recency alone says "Online".
    $verdict = UltraClientStatusService::runtimeHealthVerdict('degraded', 'awaiting_policy', true);
    assertUltraHealth(($verdict['unhealthy'] ?? false) === true, 'awaiting_policy must be a health failure');
    assertUltraHealth(($verdict['health_reason'] ?? '') === 'awaiting_policy', 'the failure must name awaiting_policy');

    // Enabled, but the panel holds no unexpired lease for it: nothing to run.
    $verdict = UltraClientStatusService::runtimeHealthVerdict('ready', '', false);
    assertUltraHealth(($verdict['unhealthy'] ?? false) === true, 'an enabled forward with no live lease must be unhealthy');
    assertUltraHealth(($verdict['health_reason'] ?? '') === 'no_live_lease', 'the failure must name the missing lease');

    $verdict = UltraClientStatusService::runtimeHealthVerdict('ready', '', true);
    assertUltraHealth(($verdict['unhealthy'] ?? false) === false, 'a lease-holding ready runtime is healthy');
    assertUltraHealth(($verdict['health_reason'] ?? '') === 'ready', 'a healthy forward must report ready');

    $verdict = UltraClientStatusService::runtimeHealthVerdict('error', '', true);
    assertUltraHealth(($verdict['unhealthy'] ?? false) === true, 'a runtime error state must be unhealthy');
    assertUltraHealth(($verdict['health_reason'] ?? '') === 'runtime_error', 'the failure must name the runtime state');

    $verdict = UltraClientStatusService::runtimeHealthVerdict('', '', true);
    assertUltraHealth(($verdict['unhealthy'] ?? false) === true, 'a forward that never reported a runtime is not healthy');

    // --------------------------------------------------------- end-to-end lookup
    $now = 1_900_000_000;
    $db = new Database();
    $db->seed('ultra_client_leases', [
        // 201 is the wedged forward: its only lease was revoked by the target change.
        ['forward_id' => 201, 'revoked_at' => '2026-09-21 04:00:00', 'expires_at' => gmdate('Y-m-d H:i:s', $now + 300)],
        // 202 re-provisioned successfully and holds a live lease.
        ['forward_id' => 202, 'revoked_at' => null, 'expires_at' => gmdate('Y-m-d H:i:s', $now + 300)],
        // 203's lease lapsed.
        ['forward_id' => 203, 'revoked_at' => null, 'expires_at' => gmdate('Y-m-d H:i:s', $now - 1)],
    ]);
    $db->seed('client_device_sessions', [[
        'client_id' => 3,
        'device_id' => 'device-a',
        'last_seen_at' => gmdate('Y-m-d H:i:s', $now),
        'ultra_status_updated_at' => gmdate('Y-m-d H:i:s', $now),
        'ultra_status_json' => json_encode([
            ['forward_id' => 201, 'state' => 'degraded', 'error_code' => 'awaiting_policy'],
            ['forward_id' => 202, 'state' => 'ready', 'error_code' => ''],
            ['forward_id' => 203, 'state' => 'ready', 'error_code' => ''],
        ], JSON_UNESCAPED_SLASHES),
    ]]);

    $status = new UltraClientStatusService($config, $db, static fn(): int => $now);
    $health = $status->runtimeHealthForForwards([201, 202, 203]);

    assertUltraHealth(($health[201]['unhealthy'] ?? false) === true, 'forward 201 must be unhealthy');
    assertUltraHealth(
        ($health[201]['health_reason'] ?? '') === 'awaiting_policy',
        'forward 201 must report awaiting_policy, got ' . (string) ($health[201]['health_reason'] ?? '')
    );
    assertUltraHealth(($health[201]['has_live_lease'] ?? true) === false, 'forward 201 has no live lease');

    assertUltraHealth(($health[202]['unhealthy'] ?? true) === false, 'forward 202 is serving and must stay healthy');
    assertUltraHealth(($health[202]['has_live_lease'] ?? false) === true, 'forward 202 holds a live lease');

    assertUltraHealth(($health[203]['unhealthy'] ?? false) === true, 'a lapsed lease must be unhealthy');

    $live = $status->forwardsWithLiveLease([201, 202, 203]);
    assertUltraHealth(array_keys($live) === [202], 'only 202 holds an unexpired, unrevoked lease');
    assertUltraHealth($status->forwardsWithLiveLease([]) === [], 'no forwards means no lease lookup');

    // ------------------------------------------------------------- source guards
    $tenantSource = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
    foreach ([
        'runtimeHealthForForwards($ultraForwardIds)',
        '已启用但未运行',
    ] as $snippet) {
        assertUltraHealth(
            str_contains($tenantSource, $snippet),
            'the tenant forward list must surface Ultra health: ' . $snippet
        );
    }

    $adminSource = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
    assertUltraHealth(
        str_contains($adminSource, "\$session['unhealthy'] ?? false"),
        'the admin Ultra sessions table must render the derived health'
    );

    echo "ok\n";
}
