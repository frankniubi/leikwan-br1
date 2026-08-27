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
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', (string) $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Http\Request;
    use Leikwan\Master\Support\AccessPolicyService;
    use Leikwan\Master\Support\AccessPolicyStore;
    use Leikwan\Master\Support\ApiException;

    final class ConcurrentStore extends AccessPolicyStore
    {
        public array $users = [17 => ['id' => 17, 'status' => 'active']];
        public array $profiles = [17 => ['id' => 1, 'user_id' => 17, 'state' => 'bound', 'main_province_code' => 'SN']];
        public array $challenges = [];
        public array $observations = [];
        public array $events = [];
        public array $controls = [];
        public array $bans = [];
        public int $transactions = 0;
        public int $blockWrites = 0;
        private int $nextId = 10;

        public function __construct()
        {
            parent::__construct(new Database());
        }

        public function userById(int $id, bool $forUpdate = false): ?array { return $this->users[$id] ?? null; }
        public function geoLockEnabledForUser(int $id): bool { return $id === 17; }
        public function activeBlacklistRules(?string $entityType = null): array { return []; }
        public function hasActiveGeographicRules(): bool { return false; }
        public function controlFor(int $clientId, string $deviceUuid): ?array { return $this->controls[$clientId . ':' . $deviceUuid] ?? null; }
        public function profileForUser(int $id, bool $forUpdate = false): ?array { return $this->profiles[$id] ?? null; }
        public function createProfile(int $id, array $data): array { return $this->profiles[$id] = ['id' => ++$this->nextId, 'user_id' => $id] + $data; }
        public function updateProfile(int $id, array $data): void { $this->profiles[$id] = array_merge($this->profiles[$id] ?? ['user_id' => $id], $data); }
        public function insertChallenge(array $data): int { $id = ++$this->nextId; $this->challenges[$id] = ['id' => $id] + $data; return $id; }
        public function challengeByHash(string $hash, bool $forUpdate = false): ?array { foreach ($this->challenges as $row) if (hash_equals((string) $row['token_hash'], $hash)) return $row; return null; }
        public function consumeChallenge(int $id, string $at): bool { if (!isset($this->challenges[$id]) || $this->challenges[$id]['consumed_at'] !== null) return false; $this->challenges[$id]['consumed_at'] = $at; return true; }
        public function insertObservation(array $data): int { $id = ++$this->nextId; $this->observations[$id] = ['id' => $id] + $data; return $id; }
        public function recentObservations(int $u, string $d, string $s, string $cutoff): array { return array_values(array_filter($this->observations, fn(array $r): bool => (int) $r['user_id'] === $u && $r['device_uuid'] === $d && $r['source_ip'] === $s)); }
        public function recentUserObservations(int $u, string $cutoff): array { return []; }
        public function insertPolicyEvent(array $data): ?array { foreach ($this->events as $row) if ((int) $row['user_id'] === (int) $data['user_id'] && (string) ($row['attempt_id'] ?? '') === (string) ($data['attempt_id'] ?? '') && $row['event_type'] === $data['event_type']) return $row + ['_inserted' => false]; $id = ++$this->nextId; $this->events[$id] = ['id' => $id] + $data; return $this->events[$id] + ['_inserted' => true]; }
        public function geoViolationEvents(int $u, string $cutoff): array { return array_values(array_filter($this->events, fn(array $r): bool => (int) $r['user_id'] === $u && (int) $r['counts_as_geo_violation'] === 1)); }
        public function activeAccountBan(int $u, bool $forUpdate = false): ?array { foreach ($this->bans as $row) if ((int) $row['user_id'] === $u && ($row['released_at'] ?? null) === null) return $row; return null; }
        public function createAccountBan(array $data): int { $id = ++$this->nextId; $this->bans[$id] = ['id' => $id] + $data; return $id; }
        public function setUserStatus(int $id, string $status): void { $this->users[$id]['status'] = $status; }
        public function revokeApplicationSessions(int $id, string $at): void {}
        public function upsertTemporaryDeviceBlock(int $clientId, string $device, string $until, string $origin, string $release): void { $this->blockWrites++; $this->controls[$clientId . ':' . $device] = ['id' => ++$this->nextId, 'state' => 'active', 'blocked_until' => $until, 'block_origin' => $origin, 'release_policy' => $release]; }
        public function transaction(callable $callback): mixed { $this->transactions++; return $callback($this); }
    }

    function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    function assertSame(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
    function assertTrue(bool $value, string $message): void { if (!$value) fail($message); }
    function expectCode(string $code, callable $callback): void { try { $callback(); } catch (ApiException $e) { assertSame($code, $e->getMessage(), 'error code'); return; } fail('expected ' . $code); }
    function req(string $device, string $token, string $attempt): Request { return new Request('POST', '/api/client/location/report', [], ['device_uuid' => $device, 'challenge_token' => $token, 'attempt_id' => $attempt, 'observations' => [['collection_mode' => 'effective', 'provider' => 'bilibili_zone', 'public_ip' => '1.2.3.4', 'country_code' => '86', 'province' => '广东', 'city' => '广州', 'observed_at' => '2026-08-28 12:00:00']],], [], '', '203.0.113.10'); }

    $store = new ConcurrentStore();
    $service = new AccessPolicyService(new Database(), null, new \DateTimeImmutable('2026-08-28 12:00:00 UTC'), $store);
    $client = ['id' => 21, 'owner_user_id' => 17];
    $first = $service->issueChallenge($client, 'race-device', 'race-1', 'windows');
    $second = $service->issueChallenge($client, 'race-device', 'race-1', 'windows');
    expectCode('geo_lock_violation', fn() => $service->recordLocationReport($client, req('race-device', $first['token'], 'race-1')));
    expectCode('geo_lock_violation', fn() => $service->recordLocationReport($client, req('race-device', $second['token'], 'race-1')));
    assertSame(1, count($store->geoViolationEvents(17, '2026-08-27 12:00:00')), 'same attempt is counted once');
    assertSame(1, count($store->controls), 'one temporary policy block is created');
    assertSame(1, $store->blockWrites, 'duplicate attempt does not extend temporary block');

    foreach (['race-2' => 'race-device-2', 'race-3' => 'race-device-3'] as $attempt => $device) {
        $challenge = $service->issueChallenge($client, $device, $attempt, 'macos');
        expectCode($attempt === 'race-3' ? 'account_banned' : 'geo_lock_violation', fn() => $service->recordLocationReport($client, req($device, $challenge['token'], $attempt)));
    }
    assertSame(3, count($store->geoViolationEvents(17, '2026-08-27 12:00:00')), 'three independent attempts are retained');
    assertSame('banned', $store->users[17]['status'] ?? null, 'third attempt atomically bans account');
    assertSame(4, $store->transactions, 'each report is evaluated in a transaction');

    $storeSource = file_get_contents(__DIR__ . '/../src/Support/AccessPolicyStore.php') ?: '';
    $serviceSource = file_get_contents(__DIR__ . '/../src/Support/AccessPolicyService.php') ?: '';
    assertTrue(str_contains($storeSource, 'INSERT IGNORE INTO access_policy_events'), 'production store must enforce attempt uniqueness at insert');
    assertTrue(str_contains($storeSource, "SELECT * FROM users WHERE id = :id LIMIT 1"), 'production store exposes the user locking boundary');
    assertTrue(str_contains($serviceSource, "userById(\$context['user_id'], true)"), 'violation transaction locks the account before counting');
    echo "ok\n";
}
