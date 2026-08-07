<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, int> */
        public array $revisions = [];
        public int $transactions = 0;

        public function transaction(callable $callback): mixed
        {
            $this->transactions++;
            return $callback($this);
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'client_config_revisions')) {
                $clientId = (int) ($params['client_id'] ?? 0);
                return isset($this->revisions[$clientId])
                    ? ['client_id' => $clientId, 'revision' => $this->revisions[$clientId]]
                    : null;
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            return [];
        }

        public function execute(string $sql, array $params = []): int
        {
            if (str_contains($sql, 'client_config_revisions')) {
                $clientId = (int) ($params['client_id'] ?? 0);
                $this->revisions[$clientId] = ($this->revisions[$clientId] ?? 0) + 1;
                return 1;
            }
            return 0;
        }

        public function insert(string $table, array $row): int
        {
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

    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Config;
    use Leikwan\Master\Support\ClientConfigRevisionService;
    use Leikwan\Master\Support\ProvisioningService;

    function failRevision(string $message): never
    {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }

    function assertRevisionSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            failRevision($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    $db = new Database();
    $now = 100.0;
    $sleeps = [];
    $service = new ClientConfigRevisionService(
        $db,
        static function () use (&$now): float { return $now; },
        static function (int $microseconds) use (&$now, &$sleeps, $db): void {
            $sleeps[] = $microseconds;
            $now += $microseconds / 1_000_000;
            if (count($sleeps) === 1) {
                $db->revisions[20] = 3;
            }
        }
    );
    assertRevisionSame(0, $service->current(20), 'missing revision');
    assertRevisionSame(1, $service->bump(20), 'first bump');
    assertRevisionSame(2, $service->bump(20), 'second bump');
    assertRevisionSame(2, $service->current(20), 'current revision');
    assertRevisionSame([20 => 3, 21 => 1], $service->bumpMany([20, 20, 21, 0]), 'bumpMany deduplicates clients');

    $db->revisions[20] = 2;
    $watched = $service->watch(20, 2, 25);
    assertRevisionSame(['changed' => true, 'revision' => 3], $watched, 'watch observes later revision');
    assertRevisionSame([250000], $sleeps, 'watch uses bounded 250ms polling');

    $timeoutNow = 200.0;
    $timeoutSleeps = [];
    $timeoutService = new ClientConfigRevisionService(
        $db,
        static function () use (&$timeoutNow): float { return $timeoutNow; },
        static function (int $microseconds) use (&$timeoutNow, &$timeoutSleeps): void {
            $timeoutSleeps[] = $microseconds;
            $timeoutNow += 1.0;
        }
    );
    $db->revisions[20] = 3;
    assertRevisionSame(
        ['changed' => false, 'revision' => 3],
        $timeoutService->watch(20, 3, 1),
        'watch timeout returns current revision'
    );
    assertRevisionSame(1, count($timeoutSleeps), 'watch stops at deadline');

    $envPath = tempnam(sys_get_temp_dir(), 'lw-revision-helper-');
    if ($envPath === false) {
        failRevision('unable to create helper env');
    }
    file_put_contents($envPath, '');
    $provisioning = new ProvisioningService(new Config($envPath), $db);
    assertRevisionSame(1, $provisioning->bumpClientConfigRevision(22), 'provisioning revision helper');
    @unlink($envPath);

    echo "ok\n";
}
