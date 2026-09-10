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
    $privateKey = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    assertTrue($privateKey !== false, 'test identity key must be generated');
    $keyDetails = openssl_pkey_get_details($privateKey);
    assertTrue(is_array($keyDetails) && is_string($keyDetails['key'] ?? null), 'test identity public key must be available');
    $publicKeyPem = (string) $keyDetails['key'];
    $publicKeyDer = base64_decode((string) preg_replace(
        ['/-----BEGIN PUBLIC KEY-----/', '/-----END PUBLIC KEY-----/', '/\s+/'],
        '',
        $publicKeyPem
    ), true);
    assertTrue(is_string($publicKeyDer) && $publicKeyDer !== '', 'test identity SPKI must be decoded');
    $identityHash = hash('sha256', $publicKeyDer);
    $deviceId = 'android-client-17-' . substr($identityHash, 0, 32);

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

    $controlId = (int) array_key_first($db->controls);
    assertTrue($controlId > 0, 'enrollment must create a device control');
    $db->controls[$controlId]['identity_key_hash'] = $identityHash;
    $renewalChallenge = $service->issueRenewalChallenge($client, $deviceId);
    $renewalBinding = 'LEIKWAN_ANDROID_SESSION_RENEWAL_V1|' . $renewalChallenge['nonce']
        . '|17|' . $deviceId . '|' . $identityHash;
    $renewalSignature = '';
    assertTrue(openssl_sign($renewalBinding, $renewalSignature, $privateKey, OPENSSL_ALGO_SHA256), 'renewal binding must be signed');
    $renewed = $service->renew(
        $client,
        $deviceId,
        (string) $renewalChallenge['nonce'],
        base64_encode($publicKeyDer),
        base64_encode($renewalSignature),
    );
    assertTrue((string) ($renewed['device_session'] ?? '') !== '', 'renewal must return a new device session');
    assertTrue((string) ($renewed['device_session'] ?? '') !== $sessionToken, 'renewal must rotate the device session');
    assertSame('session_renewed', $db->audits[array_key_last($db->audits)]['event_type'] ?? null, 'renewal must be audited');

    $simpleRenewalChallenge = $service->issueRenewalChallenge($client, $deviceId);
    $simpleRenewed = $service->renew(
        $client,
        $deviceId,
        (string) $simpleRenewalChallenge['nonce'],
        '',
        '',
    );
    assertTrue((string) ($simpleRenewed['device_session'] ?? '') !== '', 'trusted session renewal must not require a second key signature');
    assertTrue((string) ($simpleRenewed['device_session'] ?? '') !== (string) ($renewed['device_session'] ?? ''), 'simple renewal must rotate the device session');
    expectApiException(
        'device_renewal_invalid',
        403,
        static fn() => $service->renew(
            $client,
            $deviceId,
            (string) $renewalChallenge['nonce'],
            base64_encode($publicKeyDer),
            base64_encode($renewalSignature),
        )
    );

    $badSignatureChallenge = $service->issueRenewalChallenge($client, $deviceId);
    expectApiException(
        'device_renewal_invalid',
        403,
        static fn() => $service->renew(
            $client,
            $deviceId,
            (string) $badSignatureChallenge['nonce'],
            base64_encode($publicKeyDer),
            base64_encode(str_repeat('invalid', 12)),
        )
    );

    $identityOnlyClient = ['id' => 18];
    $identityOnlyDeviceId = 'android-client-18-' . substr($identityHash, 0, 32);
    $identityOnlyChallenge = $service->issueChallenge($identityOnlyClient, $identityOnlyDeviceId);
    $identityOnlyEnrollment = $service->enrollIdentity(
        $identityOnlyClient,
        $identityOnlyDeviceId,
        (string) $identityOnlyChallenge['nonce'],
        base64_encode($publicKeyDer),
        1,
    );
    assertTrue((string) ($identityOnlyEnrollment['device_session'] ?? '') !== '', 'identity-only enrollment must return a device session');
    $identityOnlyControl = null;
    foreach ($db->controls as $candidate) {
        if ((int) ($candidate['client_id'] ?? 0) === 18) {
            $identityOnlyControl = $candidate;
            break;
        }
    }
    $identityOnlyControl = is_array($identityOnlyControl) ? $identityOnlyControl : [];
    assertSame('trusted', $identityOnlyControl['trust_state'] ?? null, 'identity-only enrollment must trust the persistent key');
    assertSame('identity_only', $identityOnlyControl['trust_reason'] ?? null, 'identity-only enrollment must record its trust reason');
    assertSame(null, $identityOnlyControl['attestation_key_hash'] ?? null, 'identity-only enrollment must not persist an attestation key');

    $identityOnlyReenrollmentChallenge = $service->issueChallenge($identityOnlyClient, $identityOnlyDeviceId);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        $identityOnlyReenrollment = $service->enrollIdentity(
            $identityOnlyClient,
            $identityOnlyDeviceId,
            (string) $identityOnlyReenrollmentChallenge['nonce'],
            base64_encode($publicKeyDer),
            1,
        );
    } finally {
        restore_error_handler();
    }
    assertTrue(
        (string) ($identityOnlyReenrollment['device_session'] ?? '') !== '',
        'repeat identity-only enrollment must not emit PHP warnings before the JSON response',
    );

    $deviceIdOnlyClient = ['id' => 19];
    $deviceIdOnly = 'android-client-19-' . str_repeat('c', 32);
    $deviceIdOnlyChallenge = $service->issueChallenge($deviceIdOnlyClient, $deviceIdOnly);
    $deviceIdOnlyEnrollment = $service->enrollIdentity(
        $deviceIdOnlyClient,
        $deviceIdOnly,
        (string) $deviceIdOnlyChallenge['nonce'],
        '',
        1,
    );
    assertTrue((string) ($deviceIdOnlyEnrollment['device_session'] ?? '') !== '', 'device-id-only enrollment must return a device session');
    $deviceIdOnlyControl = null;
    foreach ($db->controls as $candidate) {
        if ((int) ($candidate['client_id'] ?? 0) === 19) {
            $deviceIdOnlyControl = $candidate;
            break;
        }
    }
    $deviceIdOnlyControl = is_array($deviceIdOnlyControl) ? $deviceIdOnlyControl : [];
    assertSame('identity_only', $deviceIdOnlyControl['trust_reason'] ?? null, 'device-id-only enrollment must be marked identity-only');

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
