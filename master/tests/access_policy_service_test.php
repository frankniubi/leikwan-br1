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
        public bool $simulateDatabaseRollback = true;
        public int $transactions = 0;
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
            foreach (['province', 'city', 'country', 'cn_province', 'cn_city', 'country_region'] as $type) {
                if ($this->activeBlacklistRules($type) !== []) {
                    return true;
                }
            }
            return false;
        }

        public function saveBlacklistRule(array $data): array
        {
            $type = (string) ($data['entity_type'] ?? '');
            $id = $this->nextId++;
            $row = ['id' => $id] + $data;
            $this->rules[$type][] = $row;
            return $row;
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

        public function releasePermanentDeviceBlock(int $clientId, string $deviceUuid, int $actorId): ?array
        {
            $key = $clientId . ':' . $deviceUuid;
            if (!isset($this->controls[$key])) {
                return null;
            }
            $this->controls[$key]['state'] = 'active';
            $this->controls[$key]['reason_code'] = null;
            return $this->controls[$key];
        }

        public function clearExpiredTemporaryDeviceBlock(int $clientId, string $deviceUuid, string $clearedAt): ?array
        {
            $key = $clientId . ':' . $deviceUuid;
            if (!isset($this->controls[$key])) {
                return null;
            }
            $this->controls[$key] = array_merge($this->controls[$key], [
                'state' => 'active',
                'blocked_until' => null,
                'block_origin' => null,
                'release_policy' => null,
                'reason_code' => null,
            ]);
            return $this->controls[$key];
        }

        public function transaction(callable $callback): mixed
        {
            $this->transactions++;
            if (!$this->simulateDatabaseRollback) {
                return $callback($this);
            }
            $snapshot = [
                $this->users,
                $this->profiles,
                $this->challenges,
                $this->observations,
                $this->events,
                $this->bans,
                $this->controls,
                $this->nextId,
            ];
            try {
                return $callback($this);
            } catch (\Throwable $throwable) {
                [
                    $this->users,
                    $this->profiles,
                    $this->challenges,
                    $this->observations,
                    $this->events,
                    $this->bans,
                    $this->controls,
                    $this->nextId,
                ] = $snapshot;
                throw $throwable;
            }
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
            // The provider uses calling-code style values for many foreign
            // locations (for example United States => 1); the service must
            // reconcile that with the stable country display name.
            'country_code' => $country === '中国' ? '86' : '1',
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

    expectApi('blacklist_country_invalid', 422, fn() => $service->saveBlacklistRule([
        'entity_type' => 'country',
        'value' => '中国',
    ], 7));

    $qualifiedCityRule = $service->saveBlacklistRule([
        'entity_type' => 'cn_city',
        'value' => 'SN:weinan',
    ], 7);
    assertSame('city', $qualifiedCityRule['entity_type'] ?? null, 'qualified city rule canonical type');
    assertSame('SN:weinan', $qualifiedCityRule['canonical_value'] ?? null, 'qualified city rule canonical value');
    assertSame('SN', $qualifiedCityRule['province_code'] ?? null, 'qualified city rule parent province');
    $disabledCountryRule = $service->saveBlacklistRule([
        'entity_type' => 'country_region',
        'country_code' => 'US',
        'is_active' => '0',
    ], 7);
    assertSame('country', $disabledCountryRule['entity_type'] ?? null, 'country region alias canonical type');
    assertSame('US', $disabledCountryRule['canonical_value'] ?? null, 'country code field canonical value');
    assertSame(0, $disabledCountryRule['is_active'] ?? null, 'string zero disables blacklist rule');
    $store->rules['city'] = [];

    expectApi('device_uuid_invalid', 422, fn() => $service->issueLocationChallenge(
        $client,
        "invalid device\nidentity",
        'invalid-device-attempt',
        'windows'
    ));

    $challenge = $service->issueLocationChallenge($client, 'device-a', 'attempt-1', 'windows');
    assertTrue(strlen((string) ($challenge['token'] ?? '')) >= 32, 'challenge token must be high entropy');
    $challengeRow = $store->challenges[array_key_last($store->challenges)] ?? [];
    assertSame('device-a', $challengeRow['device_uuid'] ?? null, 'challenge binding device');
    assertTrue(!str_contains(json_encode($store->challenges, JSON_THROW_ON_ERROR), (string) $challenge['token']), 'raw challenge token must not be persisted');
    assertTrue(preg_match('/^[a-f0-9]{64}$/', (string) ($challengeRow['auth_context_hash'] ?? '')) === 1, 'challenge auth context hash');
    $bound = $service->recordLocationReport($client, requestFor([
        'challenge_token' => $challenge['token'],
        'attempt_id' => 'attempt-1',
        'observations' => [observation('SN', '1.2.3.4')],
    ]));
    assertSame('SN', $bound['main_province_code'] ?? null, 'first Mainland report binds province');
    $nextCheck = strtotime((string) ($bound['next_location_check_at'] ?? ''));
    assertTrue($nextCheck !== false && $nextCheck >= strtotime('2026-08-28 12:30:00 UTC') && $nextCheck <= strtotime('2026-08-28 12:35:00 UTC'), 'report returns 30 minute plus jitter schedule');
    assertSame('2026-08-28 12:00:00', end($store->observations)['received_at'] ?? null, 'Panel persists observation receive time');
    assertSame('bound', $store->profiles[7]['state'] ?? null, 'profile bound state');

    // A foreign effective observation must not borrow a Mainland physical
    // observation from a different attempt when selecting the cache.
    $store->observations[90] = [
        'id' => 90,
        'user_id' => 7,
        'client_id' => 11,
        'device_uuid' => 'cache-device',
        'attempt_id' => 'cache-foreign',
        'collection_mode' => 'effective',
        'public_ip' => '198.51.100.10',
        'source_ip' => '203.0.113.10',
        'country_code' => 'US',
        'province_code' => null,
        'city_code' => null,
        'received_at' => '2026-08-28 11:55:00',
    ];
    $store->observations[91] = [
        'id' => 91,
        'user_id' => 7,
        'client_id' => 11,
        'device_uuid' => 'cache-device',
        'attempt_id' => 'cache-old-mainland',
        'collection_mode' => 'physical_no_proxy',
        'public_ip' => '10.0.0.1',
        'source_ip' => '203.0.113.10',
        'country_code' => 'CN',
        'province_code' => 'SN',
        'city_code' => 'SN:test',
        'received_at' => '2026-08-28 11:54:00',
    ];
    $cacheTransactions = $store->transactions;
    expectApi('location_check_required', 428, fn() => $service->authorizeClientRequest(
        $client,
        new Request('GET', '/api/client/configs/pull', [], [], ['x-device-platform' => 'windows'], '', '203.0.113.10'),
        'pull',
        'cache-device'
    ));
    assertTrue($store->transactions === $cacheTransactions, 'cache mismatch should request a fresh challenge without evaluation transaction');
    $cacheStatus = $service->policyStatus(
        $client,
        new Request('GET', '/api/client/access/policy', [], [], ['x-device-platform' => 'windows'], '', '203.0.113.10'),
        'cache-device'
    );
    assertSame(true, $cacheStatus['location_check_due'] ?? null, 'status marks missing physical observation as immediately due');

    // Cached policy denials must still use the same transaction boundary as a
    // freshly submitted report so evidence and the third-attempt BAN commit.
    $store->users[8] = row(['id' => 8, 'status' => 'active']);
    $store->geoLockUsers[8] = true;
    $store->profiles[8] = row(['id' => 8, 'user_id' => 8, 'state' => 'bound', 'main_province_code' => 'SN']);
    $store->observations[92] = [
        'id' => 92,
        'user_id' => 8,
        'client_id' => 22,
        'device_uuid' => 'cached-bad-device',
        'attempt_id' => 'cached-bad',
        'collection_mode' => 'effective',
        'public_ip' => '1.2.3.4',
        'source_ip' => '203.0.113.10',
        'country_code' => 'CN',
        'province_code' => 'GD',
        'city_code' => 'GD:test',
        'received_at' => '2026-08-28 11:55:00',
    ];
    $cachedTransactions = $store->transactions;
    expectApi('geo_lock_violation', 403, fn() => $service->authorizeClientRequest(
        ['id' => 22, 'owner_user_id' => 8],
        new Request('GET', '/api/client/configs/pull', [], [], ['x-device-platform' => 'windows'], '', '203.0.113.10'),
        'pull',
        'cached-bad-device'
    ));
    assertTrue($store->transactions > $cachedTransactions, 'cached policy evaluation must open a transaction');
    assertTrue($store->controlFor(22, 'cached-bad-device') !== null, 'cached violation persists a temporary device block');
    $cachedBlocked = expectApi('device_temporarily_banned', 423, fn() => $service->authorizeClientRequest(
        ['id' => 22, 'owner_user_id' => 8],
        new Request('GET', '/api/client/configs/pull', [], [], ['x-device-platform' => 'windows'], '', '203.0.113.10'),
        'pull',
        'cached-bad-device'
    ));
    assertTrue((int) ($cachedBlocked->details()['retry_after_seconds'] ?? 0) > 0, 'temporary policy block exposes retry timing');

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

    $malformedProvider = $service->issueLocationChallenge($client, 'malformed-provider-device', 'malformed-provider', 'windows');
    expectApi('location_check_unavailable', 503, fn() => $service->recordLocationReport($client, requestFor([
        'device_uuid' => 'malformed-provider-device',
        'challenge_token' => $malformedProvider['token'],
        'attempt_id' => 'malformed-provider',
        'observations' => [[
            'collection_mode' => 'effective',
            'provider' => 'bilibili_zone',
            'code' => 'not-a-number',
            'public_ip' => '1.2.3.4',
            'country_code' => '86',
            'province' => 'SN',
            'observed_at' => '2026-08-28 12:00:00',
        ]],
    ], '203.0.113.10', 'malformed-provider-device')));

    $store->rules['province'][] = [
        'id' => 99,
        'entity_type' => 'province',
        'canonical_value' => 'GD',
        'is_active' => 1,
        'reason_code' => 'test',
    ];
    $blacklistChallenge = $service->issueLocationChallenge($client, 'device-a', 'blacklist-1', 'macos');
    $blacklist = expectApi('access_blacklisted', 403, fn() => $service->recordLocationReport($client, requestFor([
        'challenge_token' => $blacklistChallenge['token'],
        'attempt_id' => 'blacklist-1',
        'observations' => [observation('GD')],
    ])));
    assertSame(0, count($store->geoViolationEvents(7, '2026-08-27 12:00:00')), 'blacklist must not count as geo violation');
    assertSame('province', $blacklist->details()['matched_entity_type'] ?? null, 'blacklist match type');
    $store->rules['province'] = [];

    // Geographic deny rules require a location report, but they must not
    // turn into the account-wide adjacency policy when the user's active
    // plans have geographic lock disabled.
    $store->users[9] = row(['id' => 9, 'status' => 'active']);
    $store->geoLockUsers[9] = false;
    $store->rules['province'][] = [
        'id' => 100,
        'entity_type' => 'province',
        'canonical_value' => 'BJ',
        'is_active' => 1,
        'reason_code' => 'deny_beijing',
    ];
    $unlockedClient = ['id' => 33, 'owner_user_id' => 9];
    $unlockedChallenge = $service->issueLocationChallenge($unlockedClient, 'unlocked-device', 'unlocked-geo-rule', 'windows');
    $unlockedDecision = $service->recordLocationReport($unlockedClient, requestFor([
        'device_uuid' => 'unlocked-device',
        'challenge_token' => $unlockedChallenge['token'],
        'attempt_id' => 'unlocked-geo-rule',
        'observations' => [
            observation('', '198.51.100.20', 'effective', 'United States'),
            observation('', '198.51.100.21', 'physical_no_proxy', 'United States'),
        ],
    ]));
    assertSame('allow', $unlockedDecision['decision'] ?? null, 'geo blacklist without plan lock must not create adjacency violation');
    assertSame(0, count($store->geoViolationEvents(9, '2026-08-27 12:00:00')), 'unlocked geo account must not accrue violations');
    $store->rules['province'] = [];

    $conflictingCountryChallenge = $service->issueLocationChallenge($unlockedClient, 'conflicting-country-device', 'conflicting-country', 'windows');
    expectApi('direct_location_check_required', 428, fn() => $service->recordLocationReport($unlockedClient, requestFor([
        'device_uuid' => 'conflicting-country-device',
        'challenge_token' => $conflictingCountryChallenge['token'],
        'attempt_id' => 'conflicting-country',
        'observations' => [[
            'collection_mode' => 'effective',
            'provider' => 'bilibili_zone',
            'public_ip' => '198.51.100.23',
            'country' => '中国',
            'country_code' => 'US',
            'province' => 'SN',
            'observed_at' => '2026-08-28 12:00:00',
        ]],
    ], '203.0.113.10', 'conflicting-country-device')));

    // A matching country deny rule is authoritative on the effective result;
    // it must block without forcing an additional physical probe first.
    $store->rules['country'][] = [
        'id' => 101,
        'entity_type' => 'country',
        'canonical_value' => 'US',
        'is_active' => 1,
        'reason_code' => 'deny_us',
    ];
    $foreignBlacklistChallenge = $service->issueLocationChallenge($unlockedClient, 'foreign-denied-device', 'foreign-denied', 'windows');
    expectApi('access_blacklisted', 403, fn() => $service->recordLocationReport($unlockedClient, requestFor([
        'device_uuid' => 'foreign-denied-device',
        'challenge_token' => $foreignBlacklistChallenge['token'],
        'attempt_id' => 'foreign-denied',
        'observations' => [observation('', '198.51.100.22', 'effective', 'United States')],
    ])));
    assertSame(0, count($store->geoViolationEvents(9, '2026-08-27 12:00:00')), 'blacklisted foreign result must not accrue geo violations');

    // A valid cached geographic deny hit is authoritative even on a fresh
    // login attempt; it must not be hidden behind another location challenge.
    $store->observations[103] = [
        'id' => 103,
        'user_id' => 9,
        'client_id' => 33,
        'device_uuid' => 'cached-foreign-denied',
        'attempt_id' => 'cached-foreign-denied-attempt',
        'collection_mode' => 'effective',
        'public_ip' => '198.51.100.24',
        'source_ip' => '203.0.113.10',
        'country_code' => 'US',
        'province_code' => null,
        'city_code' => null,
        'received_at' => '2026-08-28 11:55:00',
    ];
    expectApi('access_blacklisted', 403, fn() => $service->authorizeClientRequest(
        $unlockedClient,
        new Request('GET', '/api/client/select', [], [], ['x-device-platform' => 'windows'], '', '203.0.113.10'),
        'login',
        'cached-foreign-denied'
    ));
    $store->rules['country'] = [];

    $store->controls['33:permanent-device'] = [
        'id' => 303,
        'client_id' => 33,
        'device_id' => 'permanent-device',
        'state' => 'banned',
        'blocked_until' => null,
        'reason_code' => 'manual_admin',
    ];
    expectApi('device_block_release_forbidden', 403, fn() => $service->releaseDeviceBlock(
        33,
        'permanent-device',
        9,
        false
    ));
    $releasedPermanent = $service->releaseDeviceBlock(33, 'permanent-device', 9, true);
    assertSame('active', $releasedPermanent['state'] ?? null, 'admin can release permanent device block');

    // Legacy/admin-created aliases may retain a localized display value;
    // matching must normalize the stored province/city/country fields too.
    $store->rules['cn_province'][] = [
        'id' => 102,
        'entity_type' => 'cn_province',
        'canonical_value' => '陕西省',
        'province_code' => null,
        'is_active' => 1,
        'reason_code' => 'legacy_shaanxi',
    ];
    $legacyRuleChallenge = $service->issueLocationChallenge($unlockedClient, 'legacy-rule-device', 'legacy-rule', 'windows');
    expectApi('access_blacklisted', 403, fn() => $service->recordLocationReport($unlockedClient, requestFor([
        'device_uuid' => 'legacy-rule-device',
        'challenge_token' => $legacyRuleChallenge['token'],
        'attempt_id' => 'legacy-rule',
        'observations' => [observation('SN')],
    ])));
    $store->rules['cn_province'] = [];

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
            expectApi('device_temporarily_banned', 423, fn() => $service->recordLocationReport($client, requestFor([
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

    $store->users[8] = row(['id' => 8, 'status' => 'active']);
    $store->geoLockUsers[8] = false;
    $legacyService = new AccessPolicyService(new Database(), null, new \DateTimeImmutable('2026-08-28 12:00:00 UTC'), $store);
    $store->controls['22:expired-legacy-kick'] = [
        'id' => 404,
        'client_id' => 22,
        'device_id' => 'expired-legacy-kick',
        'state' => 'kicked',
        'blocked_until' => '2026-08-28 11:59:59',
        'block_origin' => 'manual_tenant',
        'release_policy' => 'tenant_or_admin',
    ];
    $expiredDecision = $legacyService->authorizeClientRequest(
        ['id' => 22, 'owner_user_id' => 8],
        new Request('GET', '/api/client/configs/pull', [], [], ['x-device-platform' => 'windows'], '', '203.0.113.20'),
        'pull',
        'expired-legacy-kick'
    );
    assertSame('allow', $expiredDecision['decision'] ?? null, 'expired temporary block restores legacy kicked state');
    assertSame('active', $store->controls['22:expired-legacy-kick']['state'] ?? null, 'expired block cleanup persists active state');
    $legacyRequest = new Request('GET', '/api/client/configs/pull', [], [], [
        'x-device-id' => 'legacy-linux-device',
        'x-device-platform' => 'linux-amd64',
    ], '', '203.0.113.20');
    $legacy = $legacyService->authorizeClientRequest(['id' => 22, 'owner_user_id' => 8], $legacyRequest, 'pull');
    assertSame('allow', $legacy['decision'] ?? null, 'default-off legacy Linux client remains compatible');

    $aliasRequest = new Request('GET', '/api/client/configs/pull', [], ['device_uuid' => 'alias-device'], [
        'x-device-uuid' => '',
    ], '', '203.0.113.20');
    $aliasDecision = $legacyService->authorizeClientRequest(['id' => 22, 'owner_user_id' => 8], $aliasRequest, 'pull');
    assertSame('allow', $aliasDecision['decision'] ?? null, 'empty UUID header falls back to body identity');

    $conflictRequest = new Request('GET', '/api/client/configs/pull', [], ['device_uuid' => 'body-device'], [
        'x-device-uuid' => 'header-device',
    ], '', '203.0.113.20');
    expectApi('device_uuid_invalid', 422, fn() => $legacyService->authorizeClientRequest(
        ['id' => 22, 'owner_user_id' => 8],
        $conflictRequest,
        'pull'
    ));

    echo "ok\n";
}
