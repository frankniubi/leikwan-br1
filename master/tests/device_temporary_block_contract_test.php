<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        public array $controls = [];
        public array $challenges = [];
        public array $sessions = [];
        public array $audits = [];
        public array $onlineSessions = [];
        private int $nextId = 1;

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'client_device_controls')) return $this->find($this->controls, $params);
            if (str_contains($sql, 'android_device_challenges')) return $this->find($this->challenges, $params);
            if (str_contains($sql, 'android_device_sessions')) return $this->find($this->sessions, $params);
            return null;
        }

        public function insert(string $table, array $data): int
        {
            $id = $this->nextId++;
            $row = ['id' => $id] + $data;
            match ($table) {
                'client_device_controls' => $this->controls[$id] = $row,
                'android_device_challenges' => $this->challenges[$id] = $row,
                'android_device_sessions' => $this->sessions[$id] = $row,
                'client_device_control_audits' => $this->audits[$id] = $row,
                default => null,
            };
            return $id;
        }

        public function execute(string $sql, array $params = []): int
        {
            if (str_contains($sql, 'UPDATE client_device_controls')) return $this->update($this->controls, $params);
            if (str_contains($sql, 'UPDATE android_device_challenges')) return $this->update($this->challenges, $params);
            if (str_contains($sql, 'UPDATE android_device_sessions')) {
                $count = 0;
                foreach ($this->sessions as &$row) {
                    if ((int) ($row['client_id'] ?? 0) === (int) ($params['client_id'] ?? 0)
                        && (string) ($row['device_id'] ?? '') === (string) ($params['device_id'] ?? '')
                        && ($row['revoked_at'] ?? null) === null) {
                        $row['revoked_at'] = $params['revoked_at'] ?? null;
                        $count++;
                    }
                }
                return $count;
            }
            if (str_contains($sql, 'DELETE FROM client_device_sessions')) {
                $before = count($this->onlineSessions);
                $this->onlineSessions = array_values(array_filter($this->onlineSessions, static fn(array $row): bool =>
                    (int) ($row['client_id'] ?? 0) !== (int) ($params['client_id'] ?? 0)
                    || (string) ($row['device_id'] ?? '') !== (string) ($params['device_id'] ?? '')
                ));
                return $before - count($this->onlineSessions);
            }
            return 0;
        }

        public function transaction(callable $callback): mixed { return $callback($this); }

        private function find(array $rows, array $params): ?array
        {
            foreach ($rows as $row) {
                foreach ($params as $key => $value) {
                    if (array_key_exists($key, $row) && (string) $row[$key] !== (string) $value) continue 2;
                }
                return $row;
            }
            return null;
        }

        private function update(array &$rows, array $params): int
        {
            $id = (int) ($params['id'] ?? 0);
            if (!isset($rows[$id])) return 0;
            $expected = $params['expected_command_version'] ?? $params['previous_command_version'] ?? null;
            if ($expected !== null && (int) ($rows[$id]['command_version'] ?? 0) !== (int) $expected) return 0;
            if (array_key_exists('consumed_at', $rows[$id]) && $rows[$id]['consumed_at'] !== null && array_key_exists('consumed_at', $params)) return 0;
            foreach ($params as $key => $value) {
                if ($key !== 'id' && !str_starts_with($key, 'expected_') && !str_starts_with($key, 'previous_')) $rows[$id][$key] = $value;
            }
            return 1;
        }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') return;
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', (string) substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require_once $path;
    });

    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Http\Request;
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\AttestationVerificationResult;
    use Leikwan\Master\Support\DeviceAttestationVerifier;
    use Leikwan\Master\Support\DeviceControlService;

    final class TrustedVerifier implements DeviceAttestationVerifier
    {
        public function verify(array $proof, string $nonce, int $clientId, string $deviceId): AttestationVerificationResult
        {
            return AttestationVerificationResult::trusted(str_repeat('a', 64), str_repeat('b', 64));
        }
    }

    function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    function assertSame(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
    function assertTrue(bool $value, string $message): void { if (!$value) fail($message); }
    function expectApi(string $code, int $status, callable $callback): ApiException { try { $callback(); } catch (ApiException $e) { assertSame($code, $e->getMessage(), 'error code'); assertSame($status, $e->statusCode(), 'error status'); return $e; } fail('expected ' . $code); }

    $db = new Database();
    $service = new DeviceControlService($db, new TrustedVerifier());
    $client = ['id' => 17];
    $device = 'android-client-17-aabbccddeeff00112233445566778899';
    $challenge = $service->issueChallenge($client, $device);
    $enrollment = $service->enroll($client, $device, (string) $challenge['nonce'], []);
    $request = new Request('GET', '/api/client/configs/pull', [], [], [
        'x-device-id' => $device,
        'x-device-session' => $enrollment['device_session'],
        'x-access-policy-version' => '1',
    ], '');

    $policy = $service->setTemporaryBlock(17, $device, 'policy', 1800, 42);
    assertSame('policy', $policy['block_origin'] ?? null, 'policy block origin');
    assertSame('admin_only', $policy['release_policy'] ?? null, 'policy release policy');
    assertTrue((string) ($policy['blocked_until'] ?? '') > gmdate('Y-m-d H:i:s'), 'policy block expiry');
    expectApi('device_block_release_forbidden', 403, fn() => $service->setTemporaryBlock(17, $device, 'manual_tenant', 900, 7));
    $blocked = expectApi('device_temporarily_banned', 423, fn() => $service->requireActive($client, $request));
    assertTrue((int) ($blocked->details()['retry_after_seconds'] ?? 0) > 0, 'temporary block retry timing');
    expectApi('device_temporarily_banned', 423, fn() => $service->issueChallenge($client, $device, 1));
    expectApi('device_temporarily_banned', 423, fn() => $service->enroll($client, $device, 'unused', ['protocol_version' => 1]));
    expectApi('device_kicked', 409, fn() => $service->issueChallenge($client, $device));
    expectApi('device_block_release_forbidden', 403, fn() => $service->releaseTemporaryBlock(17, $device, 7, false));

    $controlId = (int) array_key_first($db->controls);
    $db->controls[$controlId]['blocked_until'] = gmdate('Y-m-d H:i:s', time() - 1);
    assertSame(null, $service->temporaryBlockFor(17, $device), 'expired block lazily clears');
    $service->issueChallenge($client, $device);

    $manual = $service->setTemporaryBlock(17, $device, 'manual_tenant', 900, 7);
    assertSame('tenant_or_admin', $manual['release_policy'] ?? null, 'tenant block release policy');
    $db->controls[$controlId]['state'] = 'kicked';
    $released = $service->releaseTemporaryBlock(17, $device, 7, false);
    assertSame(null, $released['blocked_until'] ?? null, 'tenant can release own temporary block');
    assertSame('active', $released['state'] ?? null, 'releasing a legacy kicked temporary row restores active state');
    assertSame((int) $manual['command_version'] + 1, (int) ($released['command_version'] ?? 0), 'release increments command version');

    $db->controls[$controlId]['state'] = 'kicked';
    $db->controls[$controlId]['blocked_until'] = null;
    $db->controls[$controlId]['block_origin'] = null;
    $db->controls[$controlId]['release_policy'] = null;
    expectApi('device_block_release_forbidden', 403, fn() => $service->releaseTemporaryBlock(17, $device, 7, false));

    $service->setState(17, $device, 'banned', 'permanent', 42);
    expectApi('device_banned', 403, fn() => $service->issueChallenge($client, $device));
    echo "ok\n";
}
