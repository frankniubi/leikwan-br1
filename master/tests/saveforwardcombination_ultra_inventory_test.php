<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public static array $inserts = [];
        /** @var array<int, array{sql:string,params:array<string,mixed>}> */
        public static array $executes = [];
        /** @var array<int, array<string, mixed>> */
        public static array $ultraLines = [];

        /** @param callable(self): mixed $callback */
        public function transaction(callable $callback): mixed
        {
            return $callback($this);
        }

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM forward_combinations fc') && isset(self::$inserts['forward_combinations'][0])) {
                return ['id' => 42] + self::$inserts['forward_combinations'][0];
            }
            return null;
        }

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'FROM ultra_zones')) {
                $codes = ($params['zone_role'] ?? '') === 'ingress'
                    ? ['shanghai', 'beijing', 'guangzhou', 'unknown']
                    : ['tokyo', 'hong-kong', 'unknown'];
                return array_map(static fn(string $code): array => ['zone_code' => $code], $codes);
            }
            if (str_contains($sql, 'FROM ultra_lines')) {
                return self::$ultraLines;
            }
            return [];
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            self::$executes[] = ['sql' => $sql, 'params' => $params];
            return 1;
        }

        /** @param array<string, mixed> $row */
        public function insert(string $table, array $row): int
        {
            self::$inserts[$table][] = $row;
            return $table === 'forward_combinations' ? 42 : count(self::$inserts[$table]);
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
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\ProvisioningService;

    function failUltraCombination(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-ultra-combo-env-');
    if ($envPath === false) {
        failUltraCombination('failed to create temp env');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");
    $service = new ProvisioningService(new Config($envPath), new Database());

    Database::$ultraLines = [
        ['id' => 10, 'line_id' => 453, 'is_source_present' => 1, 'is_enabled' => 1, 'effective_ingress_zone' => 'shanghai', 'effective_destination_zone' => 'tokyo'],
        ['id' => 11, 'line_id' => 452, 'is_source_present' => 1, 'is_enabled' => 1, 'effective_ingress_zone' => 'beijing', 'effective_destination_zone' => 'tokyo'],
        ['id' => 12, 'line_id' => 689, 'is_source_present' => 0, 'is_enabled' => 1, 'effective_ingress_zone' => 'shanghai', 'effective_destination_zone' => 'tokyo'],
        ['id' => 13, 'line_id' => 690, 'is_source_present' => 1, 'is_enabled' => 0, 'effective_ingress_zone' => 'guangzhou', 'effective_destination_zone' => 'tokyo'],
        ['id' => 14, 'line_id' => 447, 'is_source_present' => 1, 'is_enabled' => 1, 'effective_ingress_zone' => 'guangzhou', 'effective_destination_zone' => 'hong-kong'],
    ];
    $base = [
        'name' => 'ultra-tokyo',
        'tenant_label' => 'Tokyo',
        'node_group_key' => '',
        'backend_mode' => 'ultra',
        'client_mode' => 'single',
        'runtime_preference' => 'single',
        'is_active' => 1,
        'ultra_destination_zone' => 'tokyo',
        'ultra_allowed_ingress_zones' => ['shanghai', 'beijing'],
        'ultra_fallback_policy' => 'detected_then_any',
        'external_uot_enabled' => 1,
        'external_uot_sidecar_target' => '185.220.238.153:7443',
        'external_uot_token' => 'external-uot-test-token',
        'external_uot_queue_packets' => 512,
        'external_uot_keepalive_seconds' => 15,
        'external_uot_reconnect_grace_seconds' => 45,
        'external_uot_frame_payload_max' => 4096,
        'ultra_lines' => [
            ['ultra_line_id' => 10, 'enabled' => 1, 'sort_order' => 10],
            ['ultra_line_id' => 11, 'enabled' => 1, 'sort_order' => 20],
            ['ultra_line_id' => 12, 'enabled' => 1, 'sort_order' => 30],
            ['ultra_line_id' => 13, 'enabled' => 1, 'sort_order' => 40],
            ['ultra_line_id' => 14, 'enabled' => 1, 'sort_order' => 50],
        ],
    ];

    Database::$inserts = [];
    Database::$executes = [];
    $service->saveForwardCombination($base);
    $combo = Database::$inserts['forward_combinations'][0] ?? [];
    if (($combo['ultra_destination_zone'] ?? '') !== 'tokyo'
        || json_decode((string) ($combo['ultra_allowed_ingress_zones_json'] ?? ''), true) !== ['shanghai', 'beijing']
        || ($combo['ultra_fallback_policy'] ?? '') !== 'detected_then_any') {
        failUltraCombination('Ultra combination policy was not normalized');
    }
    foreach ([
        'external_uot_enabled' => 1,
        'external_uot_sidecar_target' => '185.220.238.153:7443',
        'external_uot_token' => 'external-uot-test-token',
        'external_uot_queue_packets' => 512,
        'external_uot_keepalive_seconds' => 15,
        'external_uot_reconnect_grace_seconds' => 45,
        'external_uot_frame_payload_max' => 4096,
    ] as $key => $expected) {
        if (($combo[$key] ?? null) !== $expected) {
            fail('external UoT field was not persisted: ' . $key);
        }
    }
    if (!array_key_exists('leigod_line_id', $combo) || $combo['leigod_line_id'] !== null) {
        failUltraCombination('Ultra must not persist a legacy scalar line authority');
    }
    $memberships = Database::$inserts['forward_combination_ultra_lines'] ?? [];
    if (array_column($memberships, 'ultra_line_id') !== [10, 11]) {
        failUltraCombination('only eligible destination-matching Ultra lines may persist');
    }
    foreach (Database::$executes as $execute) {
        if (str_contains($execute['sql'], 'forward_combination_leigod_lines')) {
            failUltraCombination('Ultra save modified legacy line memberships');
        }
    }

    Database::$inserts = [];
    Database::$executes = [];
    $nonUltra = $base;
    $nonUltra['backend_mode'] = 'current_mode';
    $nonUltra['node_group_key'] = 'sha-tencent';
    unset($nonUltra['leigod_line_id']);
    $service->saveForwardCombination($nonUltra);
    $combo = Database::$inserts['forward_combinations'][0] ?? [];
    if (!array_key_exists('ultra_destination_zone', $combo) || $combo['ultra_destination_zone'] !== null
        || !array_key_exists('ultra_allowed_ingress_zones_json', $combo) || $combo['ultra_allowed_ingress_zones_json'] !== null
        || ($combo['ultra_fallback_policy'] ?? '') !== 'detected_then_any'
        || isset(Database::$inserts['forward_combination_ultra_lines'])) {
        failUltraCombination('non-Ultra mode persisted Ultra policy or memberships');
    }
    if (($combo['external_uot_enabled'] ?? 1) !== 0
        || !array_key_exists('external_uot_sidecar_target', $combo) || $combo['external_uot_sidecar_target'] !== null
        || !array_key_exists('external_uot_token', $combo) || $combo['external_uot_token'] !== null) {
        failUltraCombination('non-Ultra mode persisted external UoT settings');
    }

    $invalidFallback = $base;
    $invalidFallback['ultra_fallback_policy'] = 'random';
    try {
        $service->saveForwardCombination($invalidFallback);
        failUltraCombination('invalid Ultra fallback policy was accepted');
    } catch (ApiException $exception) {
        if ($exception->getCode() !== 422 && $exception->getMessage() !== 'invalid_ultra_fallback_policy') {
            failUltraCombination('unexpected invalid fallback error: ' . $exception->getMessage());
        }
    }

    $noEligible = $base;
    $noEligible['ultra_lines'] = [['ultra_line_id' => 14, 'enabled' => 1, 'sort_order' => 10]];
    try {
        $service->saveForwardCombination($noEligible);
        failUltraCombination('Ultra combination without eligible lines was accepted');
    } catch (ApiException $exception) {
        if ($exception->getMessage() !== 'ultra_line_required') {
            failUltraCombination('unexpected missing line error: ' . $exception->getMessage());
        }
    }

    @unlink($envPath);
    echo "ok\n";
}
