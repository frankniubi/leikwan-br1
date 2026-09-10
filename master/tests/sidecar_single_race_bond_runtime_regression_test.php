<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $forwardRows = [];

        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'FROM forwards f') && str_contains($sql, 'INNER JOIN services s')) {
                return $this->forwardRows;
            }
            return [];
        }

        public function insert(string $table, array $data): int { return 1; }
        public function execute(string $sql, array $params = []): int { return 0; }
        public function transaction(callable $callback): mixed { return $callback($this); }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Controllers\SidecarAgentController;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function call_private(object $instance, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($instance, ...$args);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-sidecar-frontend-env-');
    if ($envPath === false) {
        fail('failed to create temp env');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\nBOND_TOKEN_SECRET=test-secret\n");

    $config = new Config($envPath);
    $db = new Database();
    $db->forwardRows = [
        ['id' => 80, 'client_mode' => 'bond', 'backend_mode' => 'current_mode'],
        ['id' => 88, 'client_mode' => 'single', 'backend_mode' => 'current_mode'],
        ['id' => 89, 'client_mode' => 'race', 'backend_mode' => 'current_mode'],
        ['id' => 90, 'client_mode' => 'single', 'backend_mode' => 'leigod_mode'],
    ];
    $provisioning = new ProvisioningService($config, $db);
    $controller = new SidecarAgentController($config, $db, $provisioning);

    $enabled = call_private($controller, 'bondRuntimeForwardIdsForPoint', 10, [
        'single_race_via_sidecar' => true,
    ]);
    if ($enabled !== [80, 88, 89]) {
        fail('enabled sidecar point must include bond + current single/race IDs, got ' . json_encode($enabled));
    }

    $disabled = call_private($controller, 'bondRuntimeForwardIdsForPoint', 10, [
        'single_race_via_sidecar' => false,
    ]);
    if ($disabled !== [80]) {
        fail('disabled sidecar point must include only bond IDs, got ' . json_encode($disabled));
    }

    @unlink($envPath);
    echo "ok\n";
}
