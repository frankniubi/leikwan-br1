<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $lines = [];
        /** @var array<int, int> */
        public array $membershipCounts = [];
        /** @var array<int, array<string, mixed>> */
        public array $healthRows = [];
        private int $nextId = 1;

        /** @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'FROM node_leigod_line_health')) {
                foreach (['nllh.config_sha256 = ll.config_sha256', 'nllh.checked_at >= :health_cutoff', 'nllh.checked_at <= :health_future_limit'] as $guard) {
                    if (!str_contains($sql, $guard)) {
                        throw new \RuntimeException('line health aggregate missing guard: ' . $guard);
                    }
                }
                return $this->healthRows;
            }
            if (str_contains($sql, 'FROM leigod_lines')) {
                $rows = array_values($this->lines);
                usort($rows, static fn(array $a, array $b): int => [(int) $a['sort_order'], (int) $a['id']] <=> [(int) $b['sort_order'], (int) $b['id']]);
                return $rows;
            }
            return [];
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'COUNT(*) AS membership_count')) {
                return ['membership_count' => $this->membershipCounts[(int) ($params['id'] ?? 0)] ?? 0];
            }
            if (str_contains($sql, 'FROM leigod_lines') && isset($params['id'])) {
                return $this->lines[(int) $params['id']] ?? null;
            }
            if (str_contains($sql, 'FROM leigod_lines') && isset($params['line_id'])) {
                foreach ($this->lines as $row) {
                    if ((int) $row['line_id'] === (int) $params['line_id']) {
                        return $row;
                    }
                }
            }
            return null;
        }

        public function insert(string $table, array $row): int
        {
            if ($table !== 'leigod_lines') {
                throw new \RuntimeException('unexpected insert table ' . $table);
            }
            $id = $this->nextId++;
            $this->lines[$id] = ['id' => $id] + $row;
            return $id;
        }

        public function execute(string $sql, array $params = []): int
        {
            if (str_starts_with(trim($sql), 'UPDATE leigod_lines')) {
                $id = (int) ($params['id'] ?? 0);
                if (!isset($this->lines[$id])) {
                    return 0;
                }
                $this->lines[$id] = array_replace($this->lines[$id], $params);
                return 1;
            }
            if (str_starts_with(trim($sql), 'DELETE FROM leigod_lines')) {
                $id = (int) ($params['id'] ?? 0);
                if (!isset($this->lines[$id])) {
                    return 0;
                }
                unset($this->lines[$id]);
                return 1;
            }
            return 0;
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
    use Leikwan\Master\Support\LeigodLineCatalogService;

    function failCatalog(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertCatalogSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            failCatalog($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function assertCatalogError(string $expected, callable $callback): void
    {
        try {
            $callback();
            failCatalog('expected ApiException ' . $expected);
        } catch (ApiException $exception) {
            assertCatalogSame($expected, $exception->getMessage(), 'ApiException message');
        }
    }

    $toml = "server = 'example'\n";
    $envPath = tempnam(sys_get_temp_dir(), 'lw-line-env-');
    if ($envPath === false) {
        failCatalog('unable to create temp env');
    }
    file_put_contents($envPath, implode("\n", [
        'LEIGOD_LINE_TOML_9001=' . base64_encode($toml),
        'LEIGOD_LINE_TOML_9003=not-valid-base64!',
        '',
    ]));

    $db = new Database();
    $service = new LeigodLineCatalogService(new Config($envPath), $db);
    $hash = hash('sha256', $toml);

    $ready = $service->configurationForLine(['line_id' => 9001, 'config_sha256' => $hash]);
    assertCatalogSame('ready', $ready['state'] ?? null, 'ready state');
    assertCatalogSame($toml, $ready['toml'] ?? null, 'ready TOML');
    assertCatalogSame($hash, $ready['actual_sha256'] ?? null, 'ready actual hash');
    assertCatalogSame('missing', $service->configurationForLine(['line_id' => 9002, 'config_sha256' => str_repeat('0', 64)])['state'] ?? null, 'missing state');
    assertCatalogSame('missing', $service->configurationForLine(['line_id' => 9003, 'config_sha256' => str_repeat('0', 64)])['state'] ?? null, 'invalid base64 state');
    assertCatalogSame('hash_mismatch', $service->configurationForLine(['line_id' => 9001, 'config_sha256' => str_repeat('f', 64)])['state'] ?? null, 'hash mismatch state');

    $savedReady = $service->saveLine([
        'line_id' => 9001,
        'label' => 'Flexible A',
        'tcp_probe_host' => '162.245.221.78',
        'tcp_probe_port' => 80,
        'config_sha256' => $hash,
        'is_active' => 1,
        'sort_order' => 10,
    ]);
    assertCatalogSame(1, (int) ($savedReady['is_active'] ?? 0), 'ready row remains active');
    assertCatalogSame('ready', $savedReady['configuration_state'] ?? null, 'saved ready state');

    $savedMismatch = $service->saveLine([
        'id' => (int) $savedReady['id'],
        'line_id' => 9001,
        'label' => 'Flexible A',
        'tcp_probe_host' => '162.245.221.78',
        'tcp_probe_port' => 80,
        'config_sha256' => str_repeat('f', 64),
        'is_active' => 1,
        'sort_order' => 10,
    ]);
    assertCatalogSame(0, (int) ($savedMismatch['is_active'] ?? 1), 'hash mismatch forced inactive');
    assertCatalogSame('hash_mismatch', $savedMismatch['configuration_state'] ?? null, 'saved hash mismatch state');
    $savedReady = $service->saveLine([
        'id' => (int) $savedReady['id'],
        'line_id' => 9001,
        'label' => 'Flexible A',
        'tcp_probe_host' => '162.245.221.78',
        'tcp_probe_port' => 80,
        'config_sha256' => $hash,
        'is_active' => 1,
        'sort_order' => 10,
    ]);

    $savedMissing = $service->saveLine([
        'line_id' => 9002,
        'label' => 'Flexible B',
        'tcp_probe_host' => '162.245.221.78',
        'tcp_probe_port' => 80,
        'config_sha256' => str_repeat('0', 64),
        'is_active' => 1,
    ]);
    assertCatalogSame(0, (int) ($savedMissing['is_active'] ?? 1), 'missing config forced inactive');
    assertCatalogSame('missing', $savedMissing['configuration_state'] ?? null, 'saved missing state');

    assertCatalogError('invalid_leigod_line_id', fn() => $service->saveLine([
        'line_id' => 45536,
        'label' => 'Too high',
        'tcp_probe_host' => '162.245.221.78',
        'tcp_probe_port' => 80,
        'config_sha256' => str_repeat('0', 64),
    ]));
    assertCatalogError('invalid_leigod_line_config_sha256', fn() => $service->saveLine([
        'line_id' => 9004,
        'label' => 'Bad hash',
        'tcp_probe_host' => '162.245.221.78',
        'tcp_probe_port' => 80,
        'config_sha256' => 'bad',
    ]));
    assertCatalogError('invalid_leigod_line_tcp_probe', fn() => $service->saveLine([
        'line_id' => 9004,
        'label' => 'Bad host',
        'tcp_probe_host' => '',
        'tcp_probe_port' => 80,
        'config_sha256' => str_repeat('0', 64),
    ]));
    assertCatalogError('invalid_leigod_line_tcp_probe', fn() => $service->saveLine([
        'line_id' => 9004,
        'label' => 'Malformed host',
        'tcp_probe_host' => 'bad host',
        'tcp_probe_port' => 80,
        'config_sha256' => str_repeat('0', 64),
    ]));
    assertCatalogError('invalid_leigod_line_tcp_probe', fn() => $service->saveLine([
        'line_id' => 9004,
        'label' => 'Bad port',
        'tcp_probe_host' => '162.245.221.78',
        'tcp_probe_port' => 65536,
        'config_sha256' => str_repeat('0', 64),
    ]));
    assertCatalogError('invalid_leigod_line_udp_probe_method', fn() => $service->saveLine([
        'line_id' => 9004,
        'label' => 'Bad UDP',
        'tcp_probe_host' => '162.245.221.78',
        'tcp_probe_port' => 80,
        'udp_probe_host' => '223.5.5.5',
        'udp_probe_port' => 53,
        'udp_probe_method' => 'guess',
        'config_sha256' => str_repeat('0', 64),
    ]));

    $db->membershipCounts[(int) $savedReady['id']] = 1;
    assertCatalogError('leigod_line_in_use', fn() => $service->deleteLine((int) $savedReady['id']));
    $db->membershipCounts[(int) $savedMissing['id']] = 0;
    $service->deleteLine((int) $savedMissing['id']);
    assertCatalogSame(null, $service->lineById((int) $savedMissing['id']), 'unreferenced line deleted');

    $db->healthRows = [[
        'leigod_line_id' => (int) $savedReady['id'],
        'tcp_healthy_nodes' => 2,
        'udp_healthy_nodes' => 1,
        'last_checked_at' => gmdate('Y-m-d H:i:s'),
        'last_error' => null,
    ]];
    $listed = $service->listLines();
    assertCatalogSame(1, count($listed), 'list line count');
    assertCatalogSame('ready', $listed[0]['configuration_state'] ?? null, 'list includes readiness');
    assertCatalogSame(2, $listed[0]['tcp_healthy_nodes'] ?? null, 'list includes fresh hash-matched TCP health');
    assertCatalogSame(1, $listed[0]['udp_healthy_nodes'] ?? null, 'list includes fresh hash-matched UDP health');
    if (array_key_exists('toml', $listed[0])) {
        failCatalog('listLines must not expose TOML bytes');
    }

    @unlink($envPath);
    echo "ok\n";
}
