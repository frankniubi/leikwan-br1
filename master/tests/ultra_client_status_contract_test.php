<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, mixed> */
        public static array $lastUpdate = [];
        public static int $now = 1_893_456_000;
        public static int $age = 100;

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'SELECT id, group_id FROM clients')) {
                return [['id' => 7, 'group_id' => 2]];
            }
            if (str_contains($sql, 'FROM forwards f') && str_contains($sql, 'ultra_destination_zone')) {
                return [[
                    'id' => 42,
                    'line_client_id' => 7,
                    'line_client_group_id' => null,
                    'line_client_group_ids_json' => null,
                    'target_host' => '8.8.8.8',
                    'target_port' => 53,
                    'name' => 'ultra-dns',
                    'tenant_label' => 'Tokyo DNS',
                    'ultra_destination_zone' => 'tokyo',
                ]];
            }
            if (str_contains($sql, 'FROM forward_combination_ultra_lines fcul')) {
                return [[
                    'forward_id' => 42,
                    'line_id' => 453,
                    'admin_label' => null,
                    'source_title' => 'Shanghai to Tokyo',
                    'relay_ips_json' => '["203.0.113.20","203.0.113.21"]',
                ]];
            }
            if (str_contains($sql, 'FROM client_device_sessions cds')) {
                return [[
                    'client_id' => 7,
                    'device_id' => 'device-a',
                    'device_name' => 'canary',
                    'platform' => 'linux',
                    'last_ip' => '198.51.100.7',
                    'last_seen_at' => gmdate('Y-m-d H:i:s', self::$now - self::$age),
                    'last_heartbeat_at' => gmdate('Y-m-d H:i:s', self::$now - self::$age),
                    'ultra_status_json' => (string) (self::$lastUpdate['ultra_status_json'] ?? '[]'),
                    'ultra_status_updated_at' => gmdate('Y-m-d H:i:s', self::$now),
                    'client_name' => 'client-seven',
                    'group_id' => 2,
                    'user_name' => 'User Seven',
                    'user_email' => 'user7@example.test',
                ]];
            }
            if (str_contains($sql, 'SELECT last_seen_at FROM client_device_sessions')) {
                return [['last_seen_at' => gmdate('Y-m-d H:i:s', self::$now - self::$age)]];
            }
            return [];
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            if (str_contains($sql, 'UPDATE client_device_sessions')) {
                self::$lastUpdate = $params;
            }
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
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\UltraClientStatusService;

    function failUltraStatus(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-ultra-status-env-');
    if ($envPath === false) {
        failUltraStatus('failed to create env');
    }
    file_put_contents($envPath, "APP_ENV=test\n");
    $service = new UltraClientStatusService(new Config($envPath), new Database(), static fn(): int => Database::$now);
    $status = [
        'forward_id' => 42,
        'state' => 'ready',
        'detected_ingress_zone' => 'shanghai',
        'latest_tcp' => [
            'entry_ref' => 'tcp-entry-reference-abcdefghijklmnopqrstuvwxyz',
            'relay_ip' => '203.0.113.20',
            'rtt_ms' => 18,
            'selected_at' => '2030-01-01T00:00:00Z',
            'token' => 'must-not-survive',
        ],
        'active_udp' => [
            'entry_ref' => 'udp-entry-reference-abcdefghijklmnopqrstuvwxyz',
            'relay_ip' => '203.0.113.21',
            'rtt_ms' => 20,
            'selected_at' => '2030-01-01T00:00:01Z',
        ],
        'updated_at' => '2030-01-01T00:00:02Z',
        'error_code' => '',
        'credential' => 'must-not-survive',
    ];
    $statuses = array_fill(0, 40, $status);
    $statuses[] = ['forward_id' => 99, 'state' => 'ready'];
    $accepted = $service->ingest(7, 'device-a', $statuses);
    if ($accepted !== 1) {
        failUltraStatus('expected one deduplicated assigned status, got ' . $accepted);
    }
    $encoded = (string) (Database::$lastUpdate['ultra_status_json'] ?? '');
    if (str_contains(strtolower($encoded), 'credential') || str_contains(strtolower($encoded), 'token')) {
        failUltraStatus('status persistence retained unknown material fields');
    }

    $encodedStatus = json_decode((string) (Database::$lastUpdate['ultra_status_json'] ?? '[]'), true);
    if (!is_array($encodedStatus)
        || ($encodedStatus[0]['latest_tcp']['selection_ref'] ?? '') !== $status['latest_tcp']['entry_ref']
        || array_key_exists('relay_ip', $encodedStatus[0]['latest_tcp'] ?? [])
        || array_key_exists('relay_ip', $encodedStatus[0]['active_udp'] ?? [])) {
        failUltraStatus('status persistence retained provider relay identity');
    }
    $service->ingest(7, 'device-a', [$status]);

    foreach ([100 => 'Online', 200 => 'Stale', 1000 => 'Offline'] as $age => $expectedState) {
        Database::$age = $age;
        $rows = $service->sessionsForAdmin([], 50, 0);
        if (count($rows) !== 1 || ($rows[0]['state'] ?? '') !== $expectedState) {
            failUltraStatus('session state mismatch at age ' . $age);
        }
        if (($rows[0]['destination'] ?? '') !== '8.8.8.8:53'
            || ($rows[0]['destination_zone'] ?? '') !== 'tokyo'
            || ($rows[0]['latest_tcp_selection_ref'] ?? '') !== $status['latest_tcp']['entry_ref']
            || ($rows[0]['active_udp_selection_ref'] ?? '') !== $status['active_udp']['entry_ref']
            || ($rows[0]['latest_tcp_ip'] ?? '') !== ''
            || ($rows[0]['active_udp_ip'] ?? '') !== '') {
            failUltraStatus('admin session projection is not server authoritative');
        }
    }

    $source = (string) file_get_contents(__DIR__ . '/../src/Support/UltraClientStatusService.php');
    foreach (['MAX_STATUSES = 32', 'MAX_STATUS_BYTES = 65536', 'sessionsForAdmin', 'statusCounts', 'ultra_status_json'] as $needle) {
        if (!str_contains($source, $needle)) {
            failUltraStatus('missing status bound: ' . $needle);
        }
    }
    @unlink($envPath);
    echo "ok\n";
}
