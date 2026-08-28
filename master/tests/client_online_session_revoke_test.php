<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $sessions = [];
        /** @var array<int, array<string, mixed>> */
        public array $clients = [];

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($normalized, 'SELECT COUNT(*) AS total FROM client_device_sessions')) {
                $clientId = (int) ($params['client_id'] ?? 0);
                $cutoff = (string) ($params['cutoff'] ?? '');
                $total = 0;
                foreach ($this->sessions as $row) {
                    if ((int) ($row['client_id'] ?? 0) === $clientId
                        && (string) ($row['last_seen_at'] ?? '') >= $cutoff) {
                        $total++;
                    }
                }
                return ['total' => $total];
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'SELECT id FROM clients WHERE owner_user_id')) {
                return array_values(array_filter(
                    $this->clients,
                    static fn(array $row): bool => (int) ($row['owner_user_id'] ?? 0) === (int) ($params['owner_user_id'] ?? 0)
                ));
            }
            return array_values($this->sessions);
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (!str_contains($normalized, 'DELETE FROM client_device_sessions WHERE client_id = :client_id')) {
                return 0;
            }
            $clientId = (int) ($params['client_id'] ?? 0);
            $before = count($this->sessions);
            $this->sessions = array_values(array_filter(
                $this->sessions,
                static fn(array $row): bool => (int) ($row['client_id'] ?? 0) !== $clientId
            ));
            return $before - count($this->sessions);
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
    use Leikwan\Master\Support\ClientOnlineSessionService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $db = new Database();
    $now = gmdate('Y-m-d H:i:s');
    $db->sessions = [
        ['id' => 1, 'client_id' => 1, 'device_id' => 'linux-a', 'last_seen_at' => $now],
        ['id' => 2, 'client_id' => 1, 'device_id' => 'windows-b', 'last_seen_at' => $now],
        ['id' => 3, 'client_id' => 2, 'device_id' => 'other', 'last_seen_at' => $now],
    ];
    $db->clients = [
        ['id' => 1, 'owner_user_id' => 8],
        ['id' => 2, 'owner_user_id' => 7],
    ];

    $service = new ClientOnlineSessionService($db);
    $service->revokeAllForClient(1);

    foreach ($db->sessions as $row) {
        if ((int) ($row['client_id'] ?? 0) === 1) {
            fail('expected every session for the reset client to be removed');
        }
    }
    if ($service->activeCountForClient(1) !== 0) {
        fail('expected revoked client to report zero active devices');
    }
    if (count($db->sessions) !== 1) {
        fail('expected sessions for other clients to remain intact');
    }
    if ($service->revokeAllForUser(7) !== 1 || $db->sessions !== []) {
        fail('expected user-wide revocation to remove sessions for every owned client');
    }

    $uuidHeader = new Request('GET', '/api/client/heartbeat', [], [], ['x-device-uuid' => 'cross-platform-uuid'], '', '203.0.113.10');
    if ($service->deviceIdFromRequest($uuidHeader) !== 'cross-platform-uuid') {
        fail('x-device-uuid must map to the shared device identity');
    }
    $uuidBody = new Request('GET', '/api/client/heartbeat', [], ['device_uuid' => 'body-cross-platform-uuid'], [], '', '203.0.113.10');
    if ($service->deviceIdFromRequest($uuidBody) !== 'body-cross-platform-uuid') {
        fail('device_uuid body field must map to the shared device identity');
    }
    $conflictingUuid = new Request('GET', '/api/client/heartbeat', [], ['device_uuid' => 'body-uuid'], [
        'x-device-id' => 'header-uuid',
    ], '', '203.0.113.10');
    try {
        $service->deviceIdFromRequest($conflictingUuid);
        fail('conflicting shared device identity fields must be rejected');
    } catch (ApiException $exception) {
        if ($exception->getMessage() !== 'device_id_invalid') {
            fail('unexpected conflicting device identity error: ' . $exception->getMessage());
        }
    }

    echo "ok\n";
}
