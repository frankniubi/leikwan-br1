<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {

    final class Database
    {
        /**
         * @param array<string, mixed> $params
         * @return array<int, array<string, mixed>>
         */
        public function fetchAll(string $sql, array $params = []): array
        {
            // collisionFreeSidecarSelectorPort over-approximation query: no peer endpoints.
            return [];
        }

        /**
         * @param array<string, mixed> $params
         * @return array<string, mixed>|null
         */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
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
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function makeService(?bool $selectorEnabled): ProvisioningService
    {
        $envPath = tempnam(sys_get_temp_dir(), 'lw-killswitch-env-');
        if ($envPath === false) {
            fail('failed to create temp env file');
        }
        $lines = "DEFAULT_TLS_CERT_REF=default-edge-cert\n";
        if ($selectorEnabled !== null) {
            $lines .= 'STATIC_SIDECAR_SELECTOR_ENABLED=' . ($selectorEnabled ? 'true' : 'false') . "\n";
        }
        file_put_contents($envPath, $lines);
        return new ProvisioningService(new Config($envPath), new Database());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    function twoLaneUplinks(): array
    {
        return [
            [
                'enabled' => true,
                'lane' => 'primary',
                'interface_name' => 'wgsel0',
                'peer_address' => '10.10.0.1',
                'forward_combination_id' => 5,
                'sidecar_point_id' => 7,
                'node_id' => 11,
            ],
            [
                'enabled' => true,
                'lane' => 'backup',
                'interface_name' => 'wgsel1',
                'peer_address' => '10.10.0.2',
                'forward_combination_id' => 5,
                'sidecar_point_id' => 7,
                'node_id' => 11,
            ],
        ];
    }

    function invokeSelector(ProvisioningService $service, array $uplinks): array
    {
        $ref = new \ReflectionMethod($service, 'staticSidecarUplinkSelectorPayload');
        $ref->setAccessible(true);
        /** @var array<string, mixed> $out */
        $out = $ref->invoke($service, $uplinks, 9001, 101, 55);
        return $out;
    }

    // Default (flag absent => TRUE): two lanes still yield a selector.
    $defaultPayload = invokeSelector(makeService(null), twoLaneUplinks());
    if (($defaultPayload['enabled'] ?? null) !== true) {
        fail('default selector payload must be emitted (enabled=true) for >=2 candidates');
    }
    if (count($defaultPayload['candidates'] ?? []) !== 2) {
        fail('default selector payload must keep both candidate lanes');
    }

    // Explicit TRUE: unchanged.
    $enabledPayload = invokeSelector(makeService(true), twoLaneUplinks());
    if (($enabledPayload['enabled'] ?? null) !== true) {
        fail('STATIC_SIDECAR_SELECTOR_ENABLED=true must emit selector for >=2 candidates');
    }

    // FALSE kill-switch: payload is [] even with >=2 candidates.
    $disabledPayload = invokeSelector(makeService(false), twoLaneUplinks());
    if ($disabledPayload !== []) {
        fail('STATIC_SIDECAR_SELECTOR_ENABLED=false must return [] (kill-switch), got ' . var_export($disabledPayload, true));
    }

    echo "ok\n";
}
