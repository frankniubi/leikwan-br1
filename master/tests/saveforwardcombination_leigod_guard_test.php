<?php
declare(strict_types=1);

// saveForwardCombination must reject malformed leigod_mode payloads BEFORE any DB write or
// republish. leigod_mode (a first-class Forward Mode) derives the leigod self-leg, so the two
// guards now fire under backend_mode='leigod_mode':
//   1. leigod_mode with leigod_line_id<=0   -> 'leigod_line_required'
//   2. leigod_mode with sidecar_point_id<=0 -> message contains 'sidecar'
// Both throw ApiException (422). Because the guards fire before any persistence, a no-op
// Database fake is sufficient.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @param array<int, array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void {}

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array { return null; }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array { return []; }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int { return 0; }

        /** @param array<string, mixed> $row */
        public function insert(string $table, array $row): int { return 1; }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $relative = substr($class, strlen($prefix));
        if ($relative === false) {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-leigod-guard-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $service = new ProvisioningService(new Config($envPath), new Database());

    // Base payload that would otherwise pass the generic validity gate.
    $base = [
        'name' => 'leigod-combo',
        'tenant_label' => 'Leigod',
        'node_group_key' => 'sha-tencent',
        'backend_mode' => 'leigod_mode',
        'sidecar_point_id' => 4,
        'client_mode' => 'single',
        'runtime_preference' => 'single',
        'is_active' => 1,
    ];

    // Guard 1: leigod with no line id.
    $payload1 = $base;
    $payload1['leigod_line_id'] = 0;
    try {
        $service->saveForwardCombination($payload1);
        fail('expected leigod_line_required for leigod_line_id=0');
    } catch (ApiException $e) {
        if ($e->getMessage() !== 'leigod_line_required') {
            fail('expected leigod_line_required, got ' . $e->getMessage());
        }
        if ($e->statusCode() !== 422) {
            fail('expected status 422 for leigod_line_required, got ' . $e->statusCode());
        }
    }

    // Guard 2: leigod with a valid line id but no sidecar point.
    $payload2 = $base;
    $payload2['leigod_line_id'] = 451;
    $payload2['sidecar_point_id'] = 0;
    try {
        $service->saveForwardCombination($payload2);
        fail('expected a sidecar-required failure when sidecar_point_id=0 for leigod');
    } catch (ApiException $e) {
        if (!str_contains($e->getMessage(), 'sidecar')) {
            fail('expected sidecar-related message, got ' . $e->getMessage());
        }
        if ($e->statusCode() !== 422) {
            fail('expected status 422 for sidecar guard, got ' . $e->statusCode());
        }
    }

    // An unknown Forward Mode must be rejected. (self_leg_outer_transport is now derived from the
    // mode, so the legacy invalid_self_leg_outer_transport path is unreachable here.)
    $payload3 = $base;
    $payload3['leigod_line_id'] = 451;
    $payload3['backend_mode'] = 'bogus_mode';
    try {
        $service->saveForwardCombination($payload3);
        fail('expected invalid_forward_combination_backend_mode for bogus mode');
    } catch (ApiException $e) {
        if ($e->getMessage() !== 'invalid_forward_combination_backend_mode') {
            fail('expected invalid_forward_combination_backend_mode, got ' . $e->getMessage());
        }
    }

    echo "ok\n";
}
