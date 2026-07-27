<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $combinations = [];

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM forward_combinations')) {
                return $this->combinations[(int) ($params['id'] ?? 0)] ?? null;
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            return [];
        }

        public function execute(string $sql, array $params = []): int
        {
            return 0;
        }

        public function insert(string $table, array $row): int
        {
            return 7;
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

    function failCardinality(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertApiError(string $expected, callable $callback): void
    {
        try {
            $callback();
            failCardinality('expected ' . $expected);
        } catch (ApiException $exception) {
            if ($exception->getMessage() !== $expected) {
                failCardinality('expected ' . $expected . ', got ' . $exception->getMessage());
            }
        }
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-route-cardinality-');
    if ($envPath === false) {
        failCardinality('unable to create temp env');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $db = new Database();
    $ingress = [
        'backend_mode' => 'leigod_mode',
        'node_group_key' => 'shanghai-alive',
        'client_mode' => 'single',
        'bond_ingress_mode' => 'plain_tcp_mux',
        'sidecar_point_id' => null,
    ];
    $db->combinations = [10 => $ingress, 11 => $ingress];
    $service = new ProvisioningService(new Config($envPath), $db);

    $save = static fn(bool $race, array $ids): array => $service->saveForwardRoute([
        'name' => $race ? 'race-route' : 'single-route',
        'race_enabled' => $race ? '1' : '0',
        'combinations' => array_map(
            static fn(int $id): array => ['forward_combination_id' => $id, 'enabled' => 1],
            $ids
        ),
    ]);

    assertApiError('forward_route_single_requires_one_combination', static fn(): array => $save(false, []));
    assertApiError('forward_route_single_requires_one_combination', static fn(): array => $save(false, [10, 11]));
    assertApiError('forward_route_race_requires_two_combinations', static fn(): array => $save(true, [10]));
    if ((int) ($save(false, [10])['id'] ?? 0) !== 7) {
        failCardinality('single route with one combination was not saved');
    }
    if ((int) ($save(true, [10, 11])['id'] ?? 0) !== 7) {
        failCardinality('race route with two combinations was not saved');
    }

    @unlink($envPath);
    echo "ok\n";
}
