<?php
declare(strict_types=1);

// Task A2: the leigod basis runs a gost UoT SOCKS server on the sidecar. Its listen port
// must be deterministic (stable across republish), collision-free, and sit in a band that
// is DISJOINT from every other allocator band already in use on a sidecar/node:
//   - 20000-40000  backend ports
//   - 26000-26999  (sub-band carved inside backend range)
//   - 43000-44999
//   - 45000-45999  (selector / local-udp adjacency)
// The leigod gost SOCKS port lives in 47000-47999.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @param array<int, array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void {}

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array { return null; }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array { return []; }
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

    function call_private(object $instance, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invoke($instance, ...$args);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-leigod-gost-port-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $service = new ProvisioningService(new Config($envPath), new Database());

    $portA = (int) call_private($service, 'stableSidecarGostSocksPort', 101, 7);
    $portARepeat = (int) call_private($service, 'stableSidecarGostSocksPort', 101, 7);

    // Determinism / idempotency: republish must reproduce the same port.
    if ($portA !== $portARepeat) {
        fail("stableSidecarGostSocksPort not deterministic: $portA then $portARepeat");
    }

    // In the dedicated 47000-47999 band.
    if ($portA < 47000 || $portA > 47999) {
        fail("stableSidecarGostSocksPort out of expected 47000-47999 band: $portA");
    }

    // Disjoint from every other allocator band in use elsewhere.
    foreach ([
        [20000, 40000],
        [26000, 26999],
        [43000, 44999],
        [45000, 45999],
    ] as [$lo, $hi]) {
        if ($portA >= $lo && $portA <= $hi) {
            fail("stableSidecarGostSocksPort $portA collided with reserved band $lo-$hi");
        }
    }

    // Different (combination, node) keys should generally produce distinct ports (collision-free intent).
    $portB = (int) call_private($service, 'stableSidecarGostSocksPort', 202, 9);
    if ($portB < 47000 || $portB > 47999) {
        fail("stableSidecarGostSocksPort(202,9) out of band: $portB");
    }

    echo "ok\n";
}
