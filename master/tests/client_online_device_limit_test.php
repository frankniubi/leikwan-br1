<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $clients = [];

        /** @var array<int, array<string, mixed>> */
        public array $sessions = [];

        /** @var array<int, array<string, mixed>> */
        public array $users = [];

        public int $nextSessionId = 1;

        /**
         * @param array<string, mixed> $params
         * @return array<string, mixed>|null
         */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

            if (str_contains($normalized, 'SELECT * FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id LIMIT 1')) {
                foreach ($this->sessions as $row) {
                    if ((int) ($row['client_id'] ?? 0) === (int) ($params['client_id'] ?? 0)
                        && (string) ($row['device_id'] ?? '') === (string) ($params['device_id'] ?? '')) {
                        return $row;
                    }
                }
                return null;
            }

            if (str_contains($normalized, 'SELECT COUNT(*) AS total FROM client_device_sessions')) {
                $clientId = (int) ($params['client_id'] ?? 0);
                $cutoff = (string) ($params['cutoff'] ?? '');
                $excludeDevice = array_key_exists('device_id', $params) ? (string) $params['device_id'] : null;
                $excludeLegacy = array_key_exists('legacy_prefix', $params)
                    ? rtrim((string) $params['legacy_prefix'], '%')
                    : null;
                $total = 0;
                foreach ($this->sessions as $row) {
                    $deviceId = (string) ($row['device_id'] ?? '');
                    if ((int) ($row['client_id'] ?? 0) !== $clientId
                        || (string) ($row['last_seen_at'] ?? '') < $cutoff
                        || ($excludeDevice !== null && $deviceId === $excludeDevice)
                        || ($excludeLegacy !== null && str_starts_with($deviceId, $excludeLegacy))) {
                        continue;
                    }
                    $total++;
                }
                return ['total' => $total];
            }

            if (str_contains($normalized, 'SELECT online_device_limit FROM users WHERE id = :id')) {
                $userId = (int) ($params['id'] ?? 0);
                if (isset($this->users[$userId])) {
                    return ['online_device_limit' => $this->users[$userId]['online_device_limit'] ?? null];
                }
                return null;
            }

            return null;
        }

        /**
         * @param array<string, mixed> $params
         * @return array<int, array<string, mixed>>
         */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($normalized, 'SELECT * FROM client_device_sessions WHERE client_id = :client_id')) {
                $clientId = (int) ($params['client_id'] ?? 0);
                return array_values(array_filter(
                    $this->sessions,
                    static fn(array $row): bool => (int) ($row['client_id'] ?? 0) === $clientId
                ));
            }
            return [];
        }

        /**
         * @param array<string, mixed> $data
         */
        public function insert(string $table, array $data): int
        {
            if ($table === 'client_device_sessions') {
                $id = $this->nextSessionId++;
                $this->sessions[$id] = ['id' => $id] + $data;
                return $id;
            }

            if ($table === 'clients') {
                $id = (int) ($data['id'] ?? (count($this->clients) + 1));
                $this->clients[$id] = ['id' => $id] + $data;
                return $id;
            }

            if ($table === 'users') {
                $id = (int) ($data['id'] ?? (count($this->users) + 1));
                $this->users[$id] = ['id' => $id] + $data;
                return $id;
            }

            return 1;
        }

        /**
         * @param array<string, mixed> $params
         */
        public function execute(string $sql, array $params = []): int
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

            if (str_contains($normalized, 'UPDATE client_device_sessions SET')) {
                foreach ($this->sessions as $id => $row) {
                    if ((int) ($row['id'] ?? 0) !== (int) ($params['id'] ?? 0)) {
                        continue;
                    }
                    foreach ($params as $key => $value) {
                        if ($key !== 'id') {
                            $this->sessions[$id][$key] = $value;
                        }
                    }
                    return 1;
                }
                return 0;
            }

            if (str_contains($normalized, 'DELETE FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id')) {
                foreach ($this->sessions as $id => $row) {
                    if ((int) ($row['client_id'] ?? 0) === (int) ($params['client_id'] ?? 0)
                        && (string) ($row['device_id'] ?? '') === (string) ($params['device_id'] ?? '')) {
                        unset($this->sessions[$id]);
                        return 1;
                    }
                }
                return 0;
            }

            return 0;
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
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\ClientOnlineSessionService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    /** @return ApiException|null */
    function refusal(callable $call): ?ApiException
    {
        try {
            $call();
        } catch (ApiException $exception) {
            return $exception;
        }
        return null;
    }

    function ageSession(Database $db, string $deviceId): void
    {
        foreach ($db->sessions as $id => $row) {
            if ((string) ($row['device_id'] ?? '') === $deviceId) {
                $db->sessions[$id]['last_seen_at'] = gmdate('Y-m-d H:i:s', time() - (ClientOnlineSessionService::ACTIVE_WINDOW_SECONDS + 30));
            }
        }
    }

    $db = new Database();
    $db->insert('clients', [
        'id' => 1,
        'agent_token' => 'tok-1',
        'online_device_limit' => 1,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);

    $service = new ClientOnlineSessionService($db);
    $client = $db->clients[1] ?? null;
    if (!is_array($client)) {
        fail('failed to seed client');
    }
    $windows = ['device_name' => 'Windows A', 'platform' => 'windows-desktop', 'user_agent' => 'Leikwan-Windows-GUI/test'];
    $android = ['device_name' => 'Android B', 'platform' => 'android', 'user_agent' => 'okhttp/test'];

    $service->recordAndEnforce($client, 'device-a', $windows, 'pull');
    if ($service->activeCountForClient(1) !== 1) {
        fail('expected one active device after first session');
    }

    // The online device keeps working: heartbeats and pulls are never refused.
    $service->recordAndEnforce($client, 'device-a', $windows, 'heartbeat');
    $service->assertCanComeOnline($client, 'device-a');

    // A second device is refused with a stable, client-readable error.
    $refused = refusal(static fn() => $service->recordAndEnforce($client, 'device-b', $android, 'pull'));
    if ($refused === null) {
        fail('expected second device to be refused while the limit is full');
    }
    if ($refused->getMessage() !== ClientOnlineSessionService::LIMIT_REACHED_ERROR || $refused->statusCode() !== 429) {
        fail('expected client_online_limit_reached/429, got ' . $refused->getMessage() . '/' . $refused->statusCode());
    }
    $safe = $refused->safeDetails();
    if (($safe['online_device_limit'] ?? null) !== 1 || ($safe['active_online_devices'] ?? null) !== 1) {
        fail('expected limit and active count in the client-safe details');
    }
    if ($service->activeCountForClient(1) !== 1) {
        fail('refused device must not be recorded as online');
    }
    foreach ($db->sessions as $row) {
        if ((string) ($row['device_id'] ?? '') === 'device-b') {
            fail('refused device must not get a session row');
        }
    }
    if (refusal(static fn() => $service->assertCanComeOnline($client, 'device-b')) === null) {
        fail('expected sign-in check to refuse the second device too');
    }

    // Fallback ids derived from user-agent|ip neither count nor get refused.
    $service->recordAndEnforce($client, 'legacy-' . sha1('agent|1.2.3.4'), ['user_agent' => 'Leikwan-Client-Agent/test'], 'pull');
    $service->recordAndEnforce($client, 'legacy-unknown-device', [], 'pull');
    $service->recordAndEnforce($client, '', [], 'heartbeat');

    // Once the first device goes offline its slot frees up.
    ageSession($db, 'device-a');
    $service->recordAndEnforce($client, 'device-b', $android, 'pull');

    // A stale device coming back while the slot is taken is refused, not the
    // device that is currently online.
    if (refusal(static fn() => $service->recordAndEnforce($client, 'device-a', $windows, 'pull')) === null) {
        fail('expected a stale device returning to a full client to be refused');
    }
    $service->recordAndEnforce($client, 'device-b', $android, 'heartbeat');

    // Kicking the online device frees its slot immediately.
    $service->revokeDevice(1, 'device-b');
    $service->recordAndEnforce($client, 'device-a', $windows, 'pull');

    // Raising the limit admits more devices.
    $client['online_device_limit'] = 2;
    $service->recordAndEnforce($client, 'device-b', $android, 'pull');
    if (refusal(static fn() => $service->recordAndEnforce($client, 'device-c', $android, 'pull')) === null) {
        fail('expected a third device to be refused at limit 2');
    }

    // Lowering the limit never kicks devices that are already online.
    $client['online_device_limit'] = 1;
    $service->recordAndEnforce($client, 'device-a', $windows, 'heartbeat');
    $service->recordAndEnforce($client, 'device-b', $android, 'heartbeat');

    // Test user-level overrides: create a new client with an owner and a user override.
    $db->insert('users', [
        'id' => 10,
        'online_device_limit' => 5,
    ]);
    $db->insert('clients', [
        'id' => 2,
        'agent_token' => 'tok-2',
        'owner_user_id' => 10,
        'online_device_limit' => 1,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $client2 = $db->clients[2] ?? null;
    if (!is_array($client2)) {
        fail('failed to seed client 2 with user override');
    }

    // The user override of 5 should supersede the client limit of 1.
    // We can add up to 5 devices.
    for ($i = 1; $i <= 5; $i++) {
        $service->recordAndEnforce($client2, "override-device-$i", ['device_name' => "Device $i"], 'pull');
        if ($service->activeCountForClient(2) !== $i) {
            fail("expected $i active devices after recording device $i with user override");
        }
    }

    // The 6th device should be refused.
    $refused = refusal(static fn() => $service->recordAndEnforce($client2, 'override-device-6', ['device_name' => 'Device 6'], 'pull'));
    if ($refused === null) {
        fail('expected sixth device to be refused with user override limit of 5');
    }
    if (($refused->safeDetails()['online_device_limit'] ?? null) !== 5) {
        fail('expected limit 5 in the refused error details, got ' . (($refused->safeDetails()['online_device_limit'] ?? 'null')));
    }

    // Test override with NULL (should fall back to client limit).
    $db->insert('users', [
        'id' => 11,
        'online_device_limit' => null,
    ]);
    $db->insert('clients', [
        'id' => 3,
        'agent_token' => 'tok-3',
        'owner_user_id' => 11,
        'online_device_limit' => 3,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $client3 = $db->clients[3] ?? null;
    if (!is_array($client3)) {
        fail('failed to seed client 3 with null user override');
    }

    // With NULL override, should use client limit of 3.
    for ($i = 1; $i <= 3; $i++) {
        $service->recordAndEnforce($client3, "null-override-device-$i", ['device_name' => "Device $i"], 'pull');
    }
    $refused = refusal(static fn() => $service->recordAndEnforce($client3, 'null-override-device-4', ['device_name' => 'Device 4'], 'pull'));
    if ($refused === null) {
        fail('expected fourth device to be refused with null user override (fallback to client limit 3)');
    }

    // Test override with 0 (should clamp to 1).
    $db->insert('users', [
        'id' => 12,
        'online_device_limit' => 0,
    ]);
    $db->insert('clients', [
        'id' => 4,
        'agent_token' => 'tok-4',
        'owner_user_id' => 12,
        'online_device_limit' => 10,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $client4 = $db->clients[4] ?? null;
    if (!is_array($client4)) {
        fail('failed to seed client 4 with zero user override');
    }

    // With override 0, should clamp to 1.
    $service->recordAndEnforce($client4, 'zero-override-device-1', ['device_name' => 'Device 1'], 'pull');
    $refused = refusal(static fn() => $service->recordAndEnforce($client4, 'zero-override-device-2', ['device_name' => 'Device 2'], 'pull'));
    if ($refused === null) {
        fail('expected second device to be refused with zero user override (clamped to 1)');
    }

    echo "ok\n";
}
