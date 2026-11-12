<?php
declare(strict_types=1);

// Regression (2026-11-12): a line refresh replaces the credential inside an
// installed lease batch without moving the policy generation. Routers kept
// dialling the dead credential until the lease ran out (credential_rejected on
// every new stream). The heartbeat now names those forwards for a re-lease.

namespace Leikwan\Master {
    final class Config
    {
        public function __construct(string $envPath = '') {}
        public function get(string $key, mixed $default = null): mixed { return $default; }
        public function getInt(string $key, int $default = 0): int { return $default; }
        public function getBool(string $key, bool $default = false): bool { return $default; }
    }
}

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var list<array<string, mixed>> */
        public array $rows = [];
        /** @var array<string, mixed> */
        public array $lastParams = [];
        public bool $fail = false;

        /** @param array<string, mixed> $params @return list<array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            if ($this->fail) {
                throw new \RuntimeException('db down');
            }
            if (!str_contains($sql, 'FROM ultra_client_leases l') || !str_contains($sql, 'last_material_acquired_at')) {
                throw new \RuntimeException('unexpected query: ' . $sql);
            }
            $this->lastParams = $params;
            return $this->rows;
        }
    }
}

namespace {
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\UltraClientStatusService;

    require_once __DIR__ . '/../src/Support/UltraMode.php';
    require_once __DIR__ . '/../src/Support/UltraClientStatusService.php';

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    $now = 1_794_493_000;
    $at = static fn(int $offset): string => gmdate('Y-m-d H:i:s', $now + $offset);

    $db = new Database();
    $reflection = new ReflectionClass(UltraClientStatusService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    foreach (['db' => $db, 'clock' => static fn(): int => $now] as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($service, $value);
    }

    $db->rows = [
        // 408: line refreshed 98 s after the batch was issued -> stale.
        ['forward_id' => 408, 'issued_at' => $at(-240), 'acquired_at' => $at(-900)],
        ['forward_id' => 408, 'issued_at' => $at(-239), 'acquired_at' => $at(-141)],
        // 409: every line was acquired well before the batch -> current.
        ['forward_id' => 409, 'issued_at' => $at(-200), 'acquired_at' => $at(-600)],
        ['forward_id' => 409, 'issued_at' => $at(-199), 'acquired_at' => $at(-260)],
        // 428: an older batch saw a refresh, the newest batch is current.
        ['forward_id' => 428, 'issued_at' => $at(-280), 'acquired_at' => $at(-120)],
        ['forward_id' => 428, 'issued_at' => $at(-60), 'acquired_at' => $at(-120)],
        // 450: issued 5 s after the source acquired the line, before the upload.
        ['forward_id' => 450, 'issued_at' => $at(-100), 'acquired_at' => $at(-105)],
        // 451: candidate hash no longer resolves to a line.
        ['forward_id' => 451, 'issued_at' => $at(-100), 'acquired_at' => null],
        // 456: the newest batch is too old to still be installed.
        ['forward_id' => 456, 'issued_at' => $at(-310), 'acquired_at' => $at(-30)],
    ];

    $stale = $service->staleCredentialForwards(68, 'openwrt-44df6593151a');
    check($stale === [408, 450], 'only forwards whose newest batch predates a line refresh: ' . json_encode($stale));
    check($db->lastParams['client_id'] === 68, 'scoped to the client');
    check($db->lastParams['device_hash'] === hash('sha256', 'openwrt-44df6593151a'), 'scoped to the device');
    check($service->staleCredentialForwards(0, 'x') === [] && $service->staleCredentialForwards(68, ' ') === [], 'no scope, no result');

    $db->fail = true;
    check($service->staleCredentialForwards(68, 'openwrt-44df6593151a') === [], 'a failing lookup is not an error');

    $controller = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
    check(
        preg_match('/if \(\$this->isOpenwrtRequest\(\$request\)\) \{\s+try \{\s+foreach \(\$this->ultraClientStatus->staleCredentialForwards\(/', $controller) === 1,
        'the heartbeat asks only router agents to refresh'
    );
    check(str_contains($controller, "'action' => 'refresh'") && str_contains($controller, '$ultraActions[$staleForwardId] ??='), 'a stop action is never replaced by a refresh');

    echo "PASS\n";
}
