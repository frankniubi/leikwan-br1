<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $codes = [];
        /** @var array<string, mixed> */
        public array $lastExecuteParams = [];
        public string $lastExecuteSql = '';
        private int $nextId = 1;

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int
        {
            if ($table !== 'android_enrollment_codes') {
                return 1;
            }
            $id = $this->nextId++;
            $this->codes[$id] = ['id' => $id] + $data;
            return $id;
        }

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            foreach ($this->codes as $row) {
                if ((string) ($row['code_hash'] ?? '') === (string) ($params['code_hash'] ?? '')) {
                    return $row;
                }
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $this->lastExecuteSql = preg_replace('/\\s+/', ' ', trim($sql)) ?? trim($sql);
            $this->lastExecuteParams = $params;
            if (!str_contains($this->lastExecuteSql, 'UPDATE android_enrollment_codes')) {
                return 1;
            }
            $id = (int) ($params['id'] ?? 0);
            if (!isset($this->codes[$id]) || $this->codes[$id]['consumed_at'] !== null) {
                return 0;
            }
            $this->codes[$id]['consumed_at'] = $params['consumed_at'] ?? null;
            return 1;
        }

        public function transaction(callable $callback): mixed
        {
            return $callback($this);
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
    use Leikwan\Master\Support\AndroidEnrollmentCodeService;
    use Leikwan\Master\Support\UltraLeaseService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            fail($message);
        }
    }

    $db = new Database();
    $codes = new AndroidEnrollmentCodeService($db);
    $issued = $codes->issue(17, 42);
    $rawCode = (string) ($issued['code'] ?? '');
    assertTrue(strlen($rawCode) >= 32, 'enrollment code must have sufficient entropy');
    assertTrue(!str_contains(json_encode($db->codes, JSON_THROW_ON_ERROR), $rawCode), 'raw enrollment code must not be persisted');
    assertTrue(($codes->findActive($rawCode)['client_id'] ?? null) === 17, 'active code must resolve its client');
    assertTrue($codes->consume($rawCode) !== null, 'first enrollment code consumption must succeed');
    assertTrue($codes->consume($rawCode) === null, 'enrollment code replay must fail');

    $envPath = tempnam(sys_get_temp_dir(), 'lw-android-code-');
    if ($envPath === false) {
        fail('could not create fixture configuration');
    }
    file_put_contents($envPath, "ULTRA_TOKEN_KEY=test-key\n");
    $ultra = new UltraLeaseService(new Config($envPath), $db);
    $deviceId = 'android-client-17-aabbccddeeff00112233445566778899';
    assertTrue($ultra->revokeDevice(17, $deviceId) === 1, 'Ultra device revocation should execute once');
    assertTrue(str_contains($db->lastExecuteSql, 'client_id = :client_id'), 'Ultra revocation must be client scoped');
    assertTrue(str_contains($db->lastExecuteSql, 'device_hash = :device_hash'), 'Ultra revocation must be device scoped');
    assertTrue(($db->lastExecuteParams['device_hash'] ?? '') === hash('sha256', $deviceId), 'Ultra revocation must hash the stable device ID');

    @unlink($envPath);
    echo "ok\n";
}
