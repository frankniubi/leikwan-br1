<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
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
    use Leikwan\Master\Support\AccessPolicyService;
    use Leikwan\Master\Support\AccessPolicyStore;

    final class ResetStore extends AccessPolicyStore
    {
        public array $users = [7 => ['id' => 7, 'status' => 'active']];
        public array $profiles = [7 => ['id' => 1, 'user_id' => 7, 'state' => 'bound', 'main_province_code' => 'SN', 'previous_province_code' => null, 'reset_generation' => 0]];
        public array $knownDevices = [
            ['client_id' => 11, 'device_uuid' => 'old-device-a'],
            ['client_id' => 12, 'device_uuid' => 'old-device-b'],
            ['client_id' => 12, 'device_uuid' => 'old-device-a'],
        ];
        public array $resetGenerations = [];
        public array $resetDevices = [];
        public array $challenges = [];
        public array $observations = [];
        public array $controls = [];
        public array $events = [];
        public int $revocations = 0;
        private int $nextId = 10;

        public function __construct() { parent::__construct(new Database()); }
        public function userById(int $id, bool $lock = false): ?array { return $this->users[$id] ?? null; }
        public function geoLockEnabledForUser(int $id): bool { return $id === 7; }
        public function activeBlacklistRules(?string $type = null): array { return []; }
        public function hasActiveGeographicRules(): bool { return false; }
        public function controlFor(int $client, string $device): ?array { return $this->controls[$client . ':' . $device] ?? null; }
        public function profileForUser(int $id, bool $lock = false): ?array { return $this->profiles[$id] ?? null; }
        public function createProfile(int $id, array $data): array { return $this->profiles[$id] = ['id' => ++$this->nextId, 'user_id' => $id] + $data; }
        public function updateProfile(int $id, array $data): void { $this->profiles[$id] = array_merge($this->profiles[$id] ?? ['user_id' => $id], $data); }
        public function knownDevicesForUser(int $id): array { return $id === 7 ? $this->knownDevices : []; }
        public function activeResetGeneration(int $id, bool $lock = false): ?array { foreach ($this->resetGenerations as $row) if ((int) $row['user_id'] === $id && $row['state'] === 'pending') return $row; return null; }
        public function createResetGeneration(array $data): array { $id = ++$this->nextId; return $this->resetGenerations[$id] = ['id' => $id] + $data; }
        public function insertResetDevice(array $data): int { $id = ++$this->nextId; $this->resetDevices[$id] = ['id' => $id] + $data; return $id; }
        public function resetDevicesForGeneration(int $id): array { return array_values(array_filter($this->resetDevices, fn(array $row): bool => (int) $row['reset_generation_id'] === $id)); }
        public function completeResetGeneration(int $id, string $at): void { $this->resetGenerations[$id]['state'] = 'completed'; $this->resetGenerations[$id]['completed_at'] = $at; }
        public function markResetDeviceBlocked(int $id, string $until, string $at): void { $this->resetDevices[$id]['blocked_until'] = $until; $this->resetDevices[$id]['block_created_at'] = $at; }
        public function revokeApplicationSessions(int $id, string $at): int { $this->revocations += 4; return 4; }
        public function upsertTemporaryDeviceBlock(int $client, string $device, string $until, string $origin, string $release): void { $this->controls[$client . ':' . $device] = ['client_id' => $client, 'device_id' => $device, 'state' => 'active', 'blocked_until' => $until, 'block_origin' => $origin, 'release_policy' => $release]; }
        public function insertChallenge(array $data): int { $id = ++$this->nextId; $this->challenges[$id] = ['id' => $id] + $data; return $id; }
        public function challengeByHash(string $hash, bool $lock = false): ?array { foreach ($this->challenges as $row) if (hash_equals((string) $row['token_hash'], $hash)) return $row; return null; }
        public function consumeChallenge(int $id, string $at): bool { if (!isset($this->challenges[$id]) || $this->challenges[$id]['consumed_at'] !== null) return false; $this->challenges[$id]['consumed_at'] = $at; return true; }
        public function insertObservation(array $data): int { $id = ++$this->nextId; $this->observations[$id] = ['id' => $id] + $data; return $id; }
        public function recentObservations(int $u, string $d, string $s, string $cutoff): array
        {
            return array_values(array_filter($this->observations, static fn(array $row): bool =>
                (int) ($row['user_id'] ?? 0) === $u
                && (string) ($row['device_uuid'] ?? '') === $d
                && (string) ($row['source_ip'] ?? '') === $s
                && (string) ($row['received_at'] ?? '') >= $cutoff
            ));
        }
        public function recentUserObservations(int $u, string $cutoff): array { return array_values(array_filter($this->observations, fn(array $row): bool => (int) ($row['user_id'] ?? 0) === $u && (string) ($row['received_at'] ?? '') >= $cutoff)); }
        public function insertPolicyEvent(array $data): ?array { $id = ++$this->nextId; $this->events[$id] = ['id' => $id] + $data; return $this->events[$id] + ['_inserted' => true]; }
        public function geoViolationEvents(int $id, string $cutoff): array { return []; }
        public function activeAccountBan(int $id, bool $lock = false): ?array { return null; }
        public function transaction(callable $callback): mixed { return $callback($this); }
    }

    function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    function assertSame(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
    function assertTrue(bool $value, string $message): void { if (!$value) fail($message); }
    function expectApi(string $code, int $status, callable $callback): void
    {
        try {
            $callback();
        } catch (\Leikwan\Master\Support\ApiException $exception) {
            assertSame($code, $exception->getMessage(), 'API error code');
            assertSame($status, $exception->statusCode(), 'API error status');
            return;
        }
        fail('expected ' . $code);
    }
    function report(string $token, string $attempt, string $province): Request { return new Request('POST', '/api/client/location/report', [], ['protocol_version' => 1, 'platform' => 'windows', 'device_uuid' => 'new-device', 'challenge_token' => $token, 'attempt_id' => $attempt, 'observations' => [['collection_mode' => 'effective', 'provider' => 'bilibili_zone', 'public_ip' => '1.2.3.4', 'country' => '中国', 'country_code' => '86', 'province' => $province, 'city' => '测试市', 'observed_at' => '2026-08-28 12:00:00']]], [], '', '203.0.113.10'); }

    $store = new ResetStore();
    $store->observations[1] = [
        'id' => 1,
        'user_id' => 7,
        'client_id' => 11,
        'device_uuid' => 'old-device-a',
        'attempt_id' => 'before-reset',
        'collection_mode' => 'effective',
        'country_code' => 'CN',
        'province_code' => 'SN',
        'public_ip' => '1.2.3.4',
        'source_ip' => '203.0.113.10',
        'received_at' => '2026-08-28 11:55:00',
    ];
    $service = new AccessPolicyService(new Database(), null, new \DateTimeImmutable('2026-08-28 12:00:00 UTC'), $store);
    $client = ['id' => 11, 'owner_user_id' => 7];

    $first = $service->beginAllDeviceReset(7, 7);
    assertSame(1, $first['reset_generation'] ?? null, 'first reset generation');
    assertSame(3, $first['snapshot_count'] ?? null, 'known client/UUID snapshot count');
    assertSame(4, $first['revoked_count'] ?? null, 'application session revoke count');
    assertSame('reset_pending', $store->profiles[7]['state'] ?? null, 'profile enters reset pending');
    assertSame('SN', $store->profiles[7]['previous_province_code'] ?? null, 'old province is preserved');
    expectApi('main_province_reset_pending', 409, fn() => $service->changeMainProvince(7, 'GD', 7, false));

    expectApi('location_check_required', 428, fn() => $service->authorizeClientRequest(
        $client,
        new Request('GET', '/api/client/configs/pull', [], [], ['x-device-platform' => 'windows'], '', '203.0.113.10'),
        'pull',
        'old-device-a'
    ));

    $retry = $service->beginAllDeviceReset(7, 7);
    assertSame(1, $retry['reset_generation'] ?? null, 'pending reset is idempotent');
    assertSame(4, count($store->resetGenerations) + count($store->resetDevices), 'retry creates no extra reset rows');

    $sameChallenge = $service->issueChallenge($client, 'new-device', 'same-province', 'windows');
    $same = $service->recordLocationReport($client, report($sameChallenge['token'], 'same-province', '陕西'));
    assertSame('SN', $same['main_province_code'] ?? null, 'same province is rebound');
    assertSame([], $store->controls, 'same province creates no old-UUID blocks');
    $selfChange = $service->changeMainProvince(7, 'SN', 7, false);
    assertSame('SN', $selfChange['main_province_code'] ?? null, 'tenant can change to a freshly observed province');
    assertTrue((bool) array_filter($store->events, static fn(array $event): bool => ($event['event_type'] ?? '') === 'province_change'), 'province changes are audited');

    $store->profiles[7]['self_change_available_at'] = '2026-08-29 12:00:00';
    $second = $service->beginAllDeviceReset(7, 7);
    assertSame(2, $second['reset_generation'] ?? null, 'second completed reset advances generation');
    $changedChallenge = $service->issueChallenge($client, 'new-device', 'changed-province', 'windows');
    $changed = $service->recordLocationReport($client, report($changedChallenge['token'], 'changed-province', '广东'));
    assertSame('GD', $changed['main_province_code'] ?? null, 'changed province becomes main province');
    assertSame(3, count($store->controls), 'all snapshot client/UUID pairs receive a block after province change');
    foreach ($store->controls as $control) {
        assertSame('province_reset', $control['block_origin'] ?? null, 'reset block origin');
        assertSame('admin_only', $control['release_policy'] ?? null, 'reset block release policy');
        assertTrue((string) $control['blocked_until'] === '2026-08-28 13:00:00', 'reset block lasts exactly one hour');
    }
    assertSame('completed', end($store->resetGenerations)['state'] ?? null, 'reset generation completed');
    assertSame(null, $store->profiles[7]['self_change_available_at'] ?? null, 'completed reset clears the prior self-change cooldown');
    echo "ok\n";
}
