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

    /** @return array<string, mixed> */
    function row(array $values): array
    {
        return $values;
    }

    final class AccessPolicyMemoryStore extends AccessPolicyStore
    {
        /** @var array<int, array<string, mixed>> */
        public array $users = [];
        /** @var array<int, bool> */
        public array $geoLockUsers = [];
        /** @var array<int, array<int, array<string, mixed>>> */
        public array $rules = [];
        /** @var array<int, array<string, mixed>> */
        public array $profiles = [];
        /** @var array<int, array<string, mixed>> */
        public array $challenges = [];
        /** @var array<int, array<string, mixed>> */
        public array $observations = [];
        /** @var array<int, array<string, mixed>> */
        public array $events = [];
        /** @var array<int, array<string, mixed>> */
        public array $bans = [];
        /** @var array<string, array<string, mixed>> */
        public array $controls = [];
        private int $nextId = 1;

        public function __construct()
        {
            parent::__construct(new Database());
        }

        public function userById(int $userId, bool $forUpdate = false): ?array
        {
            return $this->users[$userId] ?? null;
        }

        public function geoLockEnabledForUser(int $userId): bool
        {
            return $this->geoLockUsers[$userId] ?? false;
        }

        public function activeBlacklistRules(?string $entityType = null): array
        {
            $rules = [];
            foreach ($this->rules as $type => $items) {
                if ($entityType !== null && $type !== $entityType) {
                    continue;
                }
                foreach ($items as $item) {
                    if ((int) ($item['is_active'] ?? 0) === 1) {
                        $rules[] = $item;
                    }
                }
            }
            return $rules;
        }

        public function hasActiveGeographicRules(): bool
        {
            foreach (['province', 'city', 'country'] as $type) {
                if ($this->activeBlacklistRules($type) !== []) {
                    return true;
                }
            }
            return false;
        }

        public function controlFor(int $clientId, string $deviceUuid): ?array
        {
            return $this->controls[$clientId . ':' . $deviceUuid] ?? null;
        }

        public function profileForUser(int $userId, bool $forUpdate = false): ?array
        {
            return $this->profiles[$userId] ?? null;
        }

        public function createProfile(int $userId, array $data): array
        {
            $profile = ['id' => $this->nextId++, 'user_id' => $userId] + $data;
            $this->profiles[$userId] = $profile;
            return $profile;
        }

        public function updateProfile(int $userId, array $data): void
        {
            $this->profiles[$userId] = array_merge($this->profiles[$userId] ?? ['user_id' => $userId], $data);
        }

        public function insertChallenge(array $data): int
        {
            $id = $this->nextId++;
            $this->challenges[$id] = ['id' => $id] + $data;
            return $id;
        }

        public function challengeByHash(string $tokenHash, bool $forUpdate = false): ?array
        {
            foreach ($this->challenges as $challenge) {
                if (hash_equals((string) ($challenge['token_hash'] ?? ''), $tokenHash)) {
                    return $challenge;
                }
            }
            return null;
        }

        public function consumeChallenge(int $challengeId, string $consumedAt): bool
        {
            if (!isset($this->challenges[$challengeId]) || $this->challenges[$challengeId]['consumed_at'] !== null) {
                return false;
            }
            $this->challenges[$challengeId]['consumed_at'] = $consumedAt;
            return true;
        }

        public function insertObservation(array $data): int
        {
            $id = $this->nextId++;
            $this->observations[$id] = ['id' => $id] + $data;
            return $id;
        }

        public function recentObservations(int $userId, string $deviceUuid, string $sourceIp, string $cutoff): array
        {
            $rows = array_values(array_filter($this->observations, static function (array $row) use ($userId, $deviceUuid, $sourceIp, $cutoff): bool {
                return (int) ($row['user_id'] ?? 0) === $userId
                    && (string) ($row['device_uuid'] ?? '') === $deviceUuid
                    && (string) ($row['source_ip'] ?? '') === $sourceIp
                    && (string) ($row['received_at'] ?? '') >= $cutoff;
            }));
            usort($rows, static fn(array $left, array $right): int => strcmp((string) $right['received_at'], (string) $left['received_at']));
            return $rows;
        }

        public function insertPolicyEvent(array $data): ?array
        {
            foreach ($this->events as $event) {
                if ((int) ($event['user_id'] ?? 0) === (int) ($data['user_id'] ?? 0)
                    && (string) ($event['attempt_id'] ?? '') === (string) ($data['attempt_id'] ?? '')
                    && (string) ($event['event_type'] ?? '') === (string) ($data['event_type'] ?? '')) {
                    return $event + ['_inserted' => false];
                }
            }
            $id = $this->nextId++;
            $this->events[$id] = ['id' => $id] + $data;
            return $this->events[$id] + ['_inserted' => true];
        }

        public function geoViolationEvents(int $userId, string $cutoff): array
        {
            return array_values(array_filter($this->events, static function (array $event) use ($userId, $cutoff): bool {
                return (int) ($event['user_id'] ?? 0) === $userId
                    && (int) ($event['counts_as_geo_violation'] ?? 0) === 1
                    && (string) ($event['created_at'] ?? '') >= $cutoff;
            }));
        }

        public function createAccountBan(array $data): int
        {
            $id = $this->nextId++;
            $this->bans[$id] = ['id' => $id] + $data;
            return $id;
        }

        public function activeAccountBan(int $userId, bool $forUpdate = false): ?array
        {
            foreach ($this->bans as $ban) {
                if ((int) ($ban['user_id'] ?? 0) === $userId && ($ban['released_at'] ?? null) === null) {
                    return $ban;
                }
            }
            return null;
        }

        public function setUserStatus(int $userId, string $status): void
        {
            $this->users[$userId]['status'] = $status;
        }

        public function revokeApplicationSessions(int $userId, string $revokedAt): int
        {
            return 0;
        }

        public function upsertTemporaryDeviceBlock(int $clientId, string $deviceUuid, string $until, string $origin, string $releasePolicy): void
        {
            $key = $clientId . ':' . $deviceUuid;
            $this->controls[$key] = [
                'client_id' => $clientId,
                'device_id' => $deviceUuid,
                'state' => 'active',
                'blocked_until' => $until,
                'block_origin' => $origin,
                'release_policy' => $releasePolicy,
            ];
        }

        public function transaction(callable $callback): mixed
        {
            return $callback($this);
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
            fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function assertTrue(bool $value, string $message): void
    {
        if (!$value) {
            fail($message);
        }
    }

    function expectApi(string $code, int $status, callable $callback): ApiException
    {
        try {
            $callback();
        } catch (ApiException $exception) {
            assertSame($code, $exception->getMessage(), 'API error code');
            assertSame($status, $exception->statusCode(), 'API error status');
            return $exception;
        }
        fail('expected ' . $code);
    }

    function requestFor(array $body, string $ip = '203.0.113.10', string $device = 'device-a'): Request
    {
        $body['device_uuid'] ??= $device;
        return new Request('POST', '/api/client/location/report', [], $body, [], '', $ip);
    }

    function observation(string $province, string $ip = '1.2.3.4', string $mode = 'effective', string $country = '中国'): array
    {
        return [
            'collection_mode' => $mode,
            'provider' => 'bilibili_zone',
            'public_ip' => $ip,
            'country' => $country,
            'country_code' => $country === '中国' ? '86' : 'US',
            'province' => $province,
            'city' => '测试市',
            'observed_at' => '2026-08-28 12:00:00',
        ];
    }

    $store = new AccessPolicyMemoryStore();
    $store->users[7] = row(['id' => 7, 'status' => 'active']);
    $store->geoLockUsers[7] = true;
    $service = new AccessPolicyService(
        new Database(),
        null,
        new \DateTimeImmutable('2026-08-28 12:00:00 UTC'),
        $store
    );
    $client = ['id' => 11, 'owner_user_id' => 7];

    $challenge = $service->issueLocationChallenge($client, 'device-a', 'attempt-1', 'windows');
    assertTrue(strlen((string) ($challenge['token'] ?? '')) >= 32, 'challenge token must be high entropy');
    assertSame('device-a', $store->challenges[1]['device_uuid'] ?? null, 'challenge binding device');
    assertTrue(!str_contains(json_encode($store->challenges, JSON_THROW_ON_ERROR), (string) $challenge['token']), 'raw challenge token must not be persisted');
    assertTrue(preg_match('/^[a-f0-9]{64}$/', (string) ($store->challenges[1]['auth_context_hash'] ?? '')) === 1, 'challenge auth context hash');
    $bound = $service->recordLocationReport($client, requestFor([
        'challenge_token' => $challenge['token'],
        'attempt_id' => 'attempt-1',
        'observations' => [observation('SN', '1.2.3.4')],
    ]));
    assertSame('SN', $bound['main_province_code'] ?? null, 'first Mainland report binds province');
    assertSame('bound', $store->profiles[7]['state'] ?? null, 'profile bound state');
    expectApi('location_challenge_invalid', 403, fn() => $service->recordLocationReport($client, requestFor([
        'challenge_token' => $challenge['token'],
        'attempt_id' => 'attempt-1',
        'observations' => [observation('SN')],
    ])));

    $foreignChallenge = $service->issueLocationChallenge($client, 'device-a', 'foreign-1', 'android');
    expectApi('direct_location_check_required', 428, fn() => $service->recordLocationReport($client, requestFor([
        'challenge_token' => $foreignChallenge['token'],
        'attempt_id' => 'foreign-1',
        'observations' => [observation('', '198.51.100.9', 'effective', 'United States')],
    ])));
    $physical = $service->recordLocationReport($client, requestFor([
        'challenge_token' => $foreignChallenge['token'],
        'attempt_id' => 'foreign-1',
        'observations' => [
            observation('', '198.51.100.9', 'effective', 'United States'),
            observation('SN', '10.0.0.1', 'physical_no_proxy'),
        ],
    ]));
    assertSame('SN', $physical['main_province_code'] ?? null, 'physical no-proxy report is used when effective IP is foreign');

    $providerFailure = $service->issueLocationChallenge($client, 'provider-device', 'provider-fail', 'windows');
    expectApi('location_check_unavailable', 503, fn() => $service->recordLocationReport($client, requestFor([
        'device_uuid' => 'provider-device',
        'challenge_token' => $providerFailure['token'],
        'attempt_id' => 'provider-fail',
        'observations' => [[
            'collection_mode' => 'effective',
            'provider' => 'bilibili_zone',
            'provider_status' => 'timeout',
        ]],
    ], '203.0.113.10', 'provider-device')));
    assertSame(0, count($store->geoViolationEvents(7, '2026-08-27 12:00:00')), 'provider failures do not count as geo violations');

    $store->rules['province'][] = [
        'id' => 99,
        'entity_type' => 'province',
        'canonical_value' => 'GD',
        'is_active' => 1,
        'reason_code' => 'test',
    ];
    $blacklistChallenge = $service->issueLocationChallenge($client, 'device-a', 'blacklist-1', 'macos');
    $blacklist = expectApi('access_denied_blacklist', 403, fn() => $service->recordLocationReport($client, requestFor([
        'challenge_token' => $blacklistChallenge['token'],
        'attempt_id' => 'blacklist-1',
        'observations' => [observation('GD')],
    ])));
    assertSame(0, count($store->geoViolationEvents(7, '2026-08-27 12:00:00')), 'blacklist must not count as geo violation');
    assertSame('province', $blacklist->details()['matched_entity_type'] ?? null, 'blacklist match type');
    $store->rules['province'] = [];

    foreach (['bad-1' => 'device-a', 'bad-2' => 'device-b', 'bad-3' => 'device-c'] as $attempt => $device) {
        $badChallenge = $service->issueLocationChallenge($client, $device, $attempt, 'ios');
        $error = expectApi(
            $attempt === 'bad-3' ? 'account_banned' : 'geo_lock_violation',
            403,
            fn() => $service->recordLocationReport($client, requestFor([
                'challenge_token' => $badChallenge['token'],
                'attempt_id' => $attempt,
                'observations' => [observation('GD')],
            ], '203.0.113.10', $device))
        );
        assertTrue(($error->details()['violation_count'] ?? 0) >= 1, 'violation response includes rolling count');
        if ($attempt === 'bad-1') {
            expectApi('location_challenge_invalid', 403, fn() => $service->recordLocationReport($client, requestFor([
                'challenge_token' => $badChallenge['token'],
                'attempt_id' => $attempt,
                'observations' => [observation('GD')],
            ], '203.0.113.10', $device)));
            assertSame(1, count($store->geoViolationEvents(7, '2026-08-27 12:00:00')), 'retry does not duplicate first violation');
        }
    }
    assertSame('banned', $store->users[7]['status'] ?? null, 'third independent violation bans account');
    assertSame(3, count($store->geoViolationEvents(7, '2026-08-27 12:00:00')), 'exactly three geo violations counted');

    expectApi('account_banned', 403, fn() => $service->issueLocationChallenge($client, 'device-c', 'retry-after-ban', 'ios'));
    assertSame(3, count($store->geoViolationEvents(7, '2026-08-27 12:00:00')), 'retry does not increment violation count');

    echo "ok\n";
}
