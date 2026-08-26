<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $controls = [];
        /** @var array<int, array<string, mixed>> */
        public array $challenges = [];
        /** @var array<int, array<string, mixed>> */
        public array $sessions = [];
        /** @var array<int, array<string, mixed>> */
        public array $audits = [];

        private int $nextId = 1;

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = preg_replace('/\\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($normalized, 'FROM client_device_controls')) {
                return $this->find($this->controls, $params);
            }
            if (str_contains($normalized, 'FROM android_device_challenges')) {
                return $this->find($this->challenges, $params);
            }
            if (str_contains($normalized, 'FROM android_device_sessions')) {
                return $this->find($this->sessions, $params);
            }
            return null;
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int
        {
            $id = $this->nextId++;
            $data = ['id' => $id] + $data;
            match ($table) {
                'client_device_controls' => $this->controls[$id] = $data,
                'android_device_challenges' => $this->challenges[$id] = $data,
                'android_device_sessions' => $this->sessions[$id] = $data,
                'client_device_control_audits' => $this->audits[$id] = $data,
                default => null,
            };
            return $id;
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $normalized = preg_replace('/\\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($normalized, 'UPDATE client_device_controls')) {
                return $this->update($this->controls, $params);
            }
            if (str_contains($normalized, 'UPDATE android_device_challenges')) {
                return $this->update($this->challenges, $params);
            }
            if (str_contains($normalized, 'UPDATE android_device_sessions')) {
                return $this->update($this->sessions, $params);
            }
            return 0;
        }

        public function transaction(callable $callback): mixed
        {
            return $callback($this);
        }

        /** @param array<int, array<string, mixed>> $rows @param array<string, mixed> $params */
        private function find(array $rows, array $params): ?array
        {
            foreach ($rows as $row) {
                foreach ($params as $key => $value) {
                    if (array_key_exists($key, $row) && (string) $row[$key] !== (string) $value) {
                        continue 2;
                    }
                }
                return $row;
            }
            return null;
        }

        /** @param array<int, array<string, mixed>> $rows @param array<string, mixed> $params */
        private function update(array &$rows, array $params): int
        {
            $id = (int) ($params['id'] ?? 0);
            if (!isset($rows[$id])) {
                return 0;
            }
            if (array_key_exists('consumed_at', $rows[$id]) && $rows[$id]['consumed_at'] !== null
                && array_key_exists('consumed_at', $params)) {
                return 0;
            }
            foreach ($params as $key => $value) {
                if ($key !== 'id' && !str_starts_with($key, 'expected_') && !str_starts_with($key, 'previous_')) {
                    $rows[$id][$key] = $value;
                }
            }
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

        $relative = substr($class, strlen($prefix));
        if ($relative === false) {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Http\Request;
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\AttestationVerificationResult;
    use Leikwan\Master\Support\DeviceAttestationVerifier;
    use Leikwan\Master\Support\DeviceControlService;

    final class FixtureVerifier implements DeviceAttestationVerifier
    {
        public function verify(array $proof, string $nonce, int $clientId, string $deviceId): AttestationVerificationResult
        {
            return AttestationVerificationResult::trusted(str_repeat('a', 64), str_repeat('b', 64));
        }
    }

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            fail("{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function assertTrue(bool $value, string $message): void
    {
        if (!$value) {
            fail($message);
        }
    }

    function expectApiException(string $expectedCode, int $expectedStatus, callable $callback): ApiException
    {
        try {
            $callback();
        } catch (ApiException $exception) {
            assertSame($expectedCode, $exception->getMessage(), 'error code');
            assertSame($expectedStatus, $exception->statusCode(), 'error status');
            return $exception;
        }
        fail("expected {$expectedCode} exception");
    }

    $db = new Database();
    $service = new DeviceControlService($db, new FixtureVerifier());
    $client = ['id' => 17];
    $deviceId = 'android-client-17-aabbccddeeff00112233445566778899';

    $challenge = $service->issueChallenge($client, $deviceId);
    assertTrue(strlen((string) ($challenge['nonce'] ?? '')) >= 32, 'challenge must have a high-entropy nonce');
    assertTrue($db->challenges !== [], 'challenge must be persisted');
    assertTrue(!str_contains(json_encode($db->challenges, JSON_THROW_ON_ERROR), (string) $challenge['nonce']), 'raw challenge nonce must not be persisted');

    $enrollment = $service->enroll($client, $deviceId, (string) $challenge['nonce'], ['fixture' => true]);
    $sessionToken = (string) ($enrollment['device_session'] ?? '');
    assertTrue($sessionToken !== '', 'enrollment must return a device session token');
    assertTrue(!str_contains(json_encode($db->sessions, JSON_THROW_ON_ERROR), $sessionToken), 'raw device session must not be persisted');
    expectApiException(
        'device_challenge_invalid',
        403,
        static fn() => $service->enroll($client, $deviceId, (string) $challenge['nonce'], ['fixture' => true])
    );

    $request = new Request('GET', '/api/client/configs/pull', [], [], [
        'x-device-id' => $deviceId,
        'x-device-session' => $sessionToken,
    ], '');
    $active = $service->requireActive($client, $request);
    assertSame('active', $active['state'] ?? null, 'newly enrolled device should be active');

    $kick = $service->setState(17, $deviceId, 'kicked', 'tenant_kick', 42);
    assertSame(1, $kick['command_version'] ?? null, 'kick should increment command version');
    $kicked = expectApiException('device_kicked', 409, static fn() => $service->requireActive($client, $request));
    assertSame('kick', $kicked->details()['command']['action'] ?? null, 'kick command action');

    $reenrollmentChallenge = $service->issueChallenge($client, $deviceId);
    $reenrollment = $service->enroll($client, $deviceId, (string) $reenrollmentChallenge['nonce'], ['fixture' => true]);
    assertTrue((string) ($reenrollment['device_session'] ?? '') !== $sessionToken, 'explicit reenrollment should rotate the session');

    $ban = $service->setState(17, $deviceId, 'banned', 'tenant_ban', 42);
    assertSame(2, $ban['command_version'] ?? null, 'ban should increment command version');
    $banned = expectApiException('device_banned', 403, static fn() => $service->requireActive($client, $request));
    assertSame('ban', $banned->details()['command']['action'] ?? null, 'ban command action');

    $banChallenge = expectApiException('device_banned', 403, static fn() => $service->issueChallenge($client, $deviceId));
    assertSame('ban', $banChallenge->details()['command']['action'] ?? null, 'ban challenge action');
    expectApiException('device_banned', 403, static fn() => $service->enroll($client, $deviceId, 'unused', ['fixture' => true]));

    $service->acknowledge($client, $deviceId, 999);
    $stillBanned = expectApiException('device_banned', 403, static fn() => $service->requireActive($client, $request));
    assertSame('ban', $stillBanned->details()['command']['action'] ?? null, 'acknowledgement must not reactivate a banned device');

    echo "ok\n";
}
