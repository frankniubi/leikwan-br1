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
        public int $transactions = 0;
        private int $nextId = 1;

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int
        {
            if ($table !== 'openwrt_pairing_codes') {
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
            $this->lastExecuteSql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            $this->lastExecuteParams = $params;
            if (!str_contains($this->lastExecuteSql, 'UPDATE openwrt_pairing_codes')) {
                return 1;
            }
            $id = (int) ($params['id'] ?? 0);
            if (!isset($this->codes[$id]) || $this->codes[$id]['used_at'] !== null) {
                return 0;
            }
            $this->codes[$id]['used_at'] = $params['used_at'] ?? null;
            return 1;
        }

        public function transaction(callable $callback): mixed
        {
            $this->transactions++;
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
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\OpenWrtPairingCodeService;

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

    function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function expectApi(string $code, int $status, callable $callback): void
    {
        try {
            $callback();
        } catch (ApiException $exception) {
            assertSame($code, $exception->getMessage(), 'unexpected machine code for ' . $code);
            assertSame($status, $exception->statusCode(), 'unexpected status for ' . $code);
            return;
        }
        fail('expected ApiException ' . $code . ' but no exception was thrown');
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-openwrt-pairing-');
    if ($envPath === false) {
        fail('could not create fixture configuration');
    }
    file_put_contents($envPath, "OPENWRT_PAIRING_ENABLED=true\n");

    $disabled = new OpenWrtPairingCodeService(new Database(), new Config(__DIR__ . '/missing.env'));
    assertTrue(!$disabled->enabled(), 'pairing must be off unless OPENWRT_PAIRING_ENABLED is set');
    expectApi('openwrt_pairing_unavailable', 503, static fn() => $disabled->assertAvailable());

    $db = new Database();
    $codes = new OpenWrtPairingCodeService($db, new Config($envPath));
    assertTrue($codes->enabled(), 'pairing must be enabled by OPENWRT_PAIRING_ENABLED=true');

    $issued = $codes->issue(17, 42);
    $rawCode = (string) ($issued['code'] ?? '');
    assertSame(17, $issued['client_id'] ?? null, 'issued code must be scoped to the requested client');
    assertSame(1, preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $rawCode), 'pairing code must be a readable grouped code, got ' . $rawCode);
    assertSame(0, preg_match('/[IO01]/', $rawCode), 'pairing code must avoid ambiguous glyphs');
    assertSame(900, strtotime((string) $issued['expires_at'] . ' UTC') - time(), 'pairing codes must default to a 15 minute expiry');

    $canonical = str_replace('-', '', $rawCode);
    $persisted = json_encode($db->codes, JSON_THROW_ON_ERROR);
    assertTrue(!str_contains($persisted, $rawCode), 'raw pairing code must never be persisted');
    assertTrue(!str_contains($persisted, $canonical), 'ungrouped pairing code must never be persisted');
    assertSame(hash('sha256', $canonical), (string) ($db->codes[1]['code_hash'] ?? ''), 'only the sha256 hash may be stored');
    assertSame(17, $codes->findActive($rawCode)['client_id'] ?? null, 'an active code must resolve its client');
    assertSame(17, $codes->findActive(strtolower($rawCode))['client_id'] ?? null, 'codes must be case insensitive');
    assertSame(17, $codes->findActive(' ' . $canonical . ' ')['client_id'] ?? null, 'codes must tolerate separators and padding');

    assertSame(null, $codes->findByCode(''), 'an empty code is a miss');
    assertSame(null, $codes->findByCode('!!!!-!!!!'), 'a malformed code is a miss');
    assertSame(null, $codes->findByCode(substr($canonical, 0, 4) . '-ZZZZ'), 'a prefix match must not redeem');
    assertSame(null, $codes->findByCode('IIII-IIII'), 'codes outside the alphabet are a miss');

    $transactionsBefore = $db->transactions;
    assertTrue($codes->consume($rawCode) !== null, 'first redemption must succeed');
    assertTrue($db->transactions > $transactionsBefore, 'redemption must run in a transaction');
    assertTrue($codes->consume($rawCode) === null, 'pairing code replay must fail');
    assertSame(null, $codes->findActive($rawCode), 'a redeemed code must stop resolving');
    assertTrue(str_contains($db->lastExecuteSql, 'UPDATE openwrt_pairing_codes'), 'redemption must consume the row');
    assertTrue(str_contains($db->lastExecuteSql, 'used_at IS NULL'), 'redemption must be a compare-and-swap');
    assertTrue(($db->lastExecuteParams['used_at'] ?? null) !== null, 'redemption must stamp used_at');

    $expired = $codes->issue(23, null);
    $expiredCode = (string) $expired['code'];
    $expiredHash = hash('sha256', str_replace('-', '', $expiredCode));
    $expiredId = null;
    foreach ($db->codes as $id => $row) {
        if ((string) ($row['code_hash'] ?? '') === $expiredHash) {
            $expiredId = (int) $id;
        }
    }
    if ($expiredId === null) {
        fail('failed to locate the issued code row');
    }
    assertTrue($codes->isExpired(['expires_at' => gmdate('Y-m-d H:i:s', time() - 5)]), 'a past expiry must be reported as expired');
    assertTrue(!$codes->isExpired(['expires_at' => gmdate('Y-m-d H:i:s', time() + 60)]), 'a future expiry must not be reported as expired');
    $db->codes[$expiredId]['expires_at'] = gmdate('Y-m-d H:i:s', time() - 5);
    assertSame(null, $codes->findActive($expiredCode), 'an expired code must not resolve');
    assertSame(null, $codes->consume($expiredCode), 'an expired code must not be redeemable');
    assertSame(null, $db->codes[$expiredId]['used_at'], 'a rejected expired code must stay unused');

    $other = $codes->issue(29, null);
    assertSame(29, $codes->findActive((string) $other['code'])['client_id'] ?? null, 'codes are scoped to their own client');

    $service = (string) file_get_contents(__DIR__ . '/../src/Support/OpenWrtPairingCodeService.php');
    foreach (['hash_equals(', 'code_hash', 'used_at', 'expires_at', 'random_int('] as $required) {
        assertTrue(str_contains($service, $required), 'pairing code service is missing ' . $required);
    }
    foreach (['AttestationVerifier', 'attestation_proof', 'DeviceControlService'] as $forbidden) {
        assertTrue(!str_contains($service, $forbidden), 'router pairing must not require key attestation: ' . $forbidden);
    }
    assertTrue(!str_contains($service, 'android_enrollment_codes'), 'router pairing must not reuse Android enrolment codes');

    @unlink($envPath);
    echo "ok\n";
}
