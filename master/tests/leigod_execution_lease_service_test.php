<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $lines = [];
        /** @var array<int, array<string, mixed>> */
        public array $leases = [];
        /** @var array<int, array{0:string,1:string,2:array<string,mixed>}> */
        public array $calls = [];
        public bool $inTransaction = false;

        public function transaction(callable $callback): mixed
        {
            if ($this->inTransaction) {
                throw new \RuntimeException('nested test transaction');
            }
            $this->calls[] = ['transaction', '', []];
            $this->inTransaction = true;
            try {
                return $callback($this);
            } finally {
                $this->inTransaction = false;
            }
        }

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $this->calls[] = ['fetchOne', $sql, $params];
            if (str_contains($sql, 'FROM leigod_lines')) {
                return $this->lines[(int) ($params['line_id'] ?? $params['id'] ?? 0)] ?? null;
            }
            if (str_contains($sql, 'FROM leigod_execution_leases')) {
                if (isset($params['line_id'])) {
                    return $this->leases[(int) $params['line_id']] ?? null;
                }
                $leaseId = (string) ($params['lease_id'] ?? '');
                foreach ($this->leases as $lease) {
                    if ((string) ($lease['lease_id'] ?? '') === $leaseId) {
                        return $lease;
                    }
                }
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            $this->calls[] = ['fetchAll', $sql, $params];
            if (str_contains($sql, 'FROM leigod_lines')) {
                $ids = array_values(array_filter(array_map(
                    static fn(string $key): int => str_starts_with($key, 'candidate_') ? (int) $params[$key] : 0,
                    array_keys($params)
                )));
                $rows = $ids === [] ? array_values($this->lines) : array_values(array_filter(
                    $this->lines,
                    static fn(array $line): bool => in_array((int) $line['id'], $ids, true)
                ));
                usort($rows, static fn(array $a, array $b): int => [
                    (int) ($a['sort_order'] ?? 100),
                    (int) $a['id'],
                ] <=> [
                    (int) ($b['sort_order'] ?? 100),
                    (int) $b['id'],
                ]);
                return $rows;
            }
            if (str_contains($sql, 'FROM leigod_execution_leases')) {
                $rows = array_values($this->leases);
                if (isset($params['owner_type'])) {
                    $rows = array_values(array_filter($rows, static function (array $row) use ($params): bool {
                        return (string) $row['owner_type'] === (string) $params['owner_type']
                            && (int) $row['owner_id'] === (int) $params['owner_id']
                            && (!array_key_exists('owner_device_id', $params)
                                || (string) ($row['owner_device_id'] ?? '') === (string) $params['owner_device_id']);
                    }));
                }
                return $rows;
            }
            return [];
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            if (!$this->inTransaction) {
                throw new \RuntimeException('lease write outside transaction');
            }
            $this->calls[] = ['execute', $sql, $params];
            if (str_starts_with(ltrim($sql), 'DELETE FROM leigod_execution_leases')) {
                $lineId = (int) ($params['line_id'] ?? 0);
                if (isset($this->leases[$lineId])) {
                    unset($this->leases[$lineId]);
                    return 1;
                }
                return 0;
            }
            $lineId = (int) ($params['line_id'] ?? 0);
            if ($lineId <= 0 || !isset($this->leases[$lineId])) {
                return 0;
            }
            $this->leases[$lineId] = array_replace($this->leases[$lineId], $params);
            return 1;
        }

        /** @param array<string, mixed> $row */
        public function insert(string $table, array $row): int
        {
            if (!$this->inTransaction || $table !== 'leigod_execution_leases') {
                throw new \RuntimeException('unexpected test insert');
            }
            $this->calls[] = ['insert', $table, $row];
            $lineId = (int) $row['leigod_line_id'];
            $this->leases[$lineId] = $row;
            return $lineId;
        }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\LeigodExecutionLeaseService;

    function failLease(string $message): never
    {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }

    function assertLeaseSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            failLease($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function assertLeaseTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            failLease($message);
        }
    }

    function expectLeaseApi(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (ApiException $exception) {
            assertLeaseSame($message, $exception->getMessage(), 'API exception message');
            return;
        }
        failLease('expected ApiException ' . $message);
    }

    function testLine(int $id, int $sortOrder, bool $allowNode = true, bool $allowClient = true, int $lanes = 2): array
    {
        return [
            'id' => $id,
            'line_id' => 400 + $id,
            'is_active' => 1,
            'allow_node_execution' => $allowNode ? 1 : 0,
            'allow_client_execution' => $allowClient ? 1 : 0,
            'max_parallel_lanes' => $lanes,
            'sort_order' => $sortOrder,
            'config_sha256' => str_repeat(dechex($id % 16), 64),
        ];
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-lease-env-');
    if ($envPath === false) {
        failLease('unable to create temp env');
    }
    file_put_contents($envPath, implode("\n", [
        'LEIGOD_EXECUTION_LEASE_PENDING_READY_SECONDS=45',
        'LEIGOD_EXECUTION_LEASE_ACTIVE_TTL_SECONDS=90',
        'LEIGOD_EXECUTION_LEASE_REVOKING_GRACE_SECONDS=15',
        'LEIGOD_EXECUTION_LEASE_MAX_CONSECUTIVE_FAILURES=3',
        '',
    ]));
    $config = new Config($envPath);
    $now = 1_786_000_000;
    $db = new Database();
    $db->lines = [
        10 => testLine(10, 20, lanes: 9),
        11 => testLine(11, 10),
        12 => testLine(12, 30, allowClient: false),
        13 => testLine(13, 40, allowNode: false),
        18 => testLine(18, 50),
        19 => testLine(19, 60),
    ];
    $service = new LeigodExecutionLeaseService($config, $db, static function () use (&$now): int {
        return $now;
    });

    $lease = $service->acquireForClientDevice(10, 20, 'linux-device-a');
    assertLeaseSame('client_device', $lease['owner_type'], 'client owner type');
    assertLeaseSame(20, $lease['owner_id'], 'client owner id');
    assertLeaseSame('linux-device-a', $lease['owner_device_id'], 'client device');
    assertLeaseSame(1, $lease['generation'], 'first generation');
    assertLeaseSame(4, $lease['max_parallel_lanes'], 'lane clamp');
    assertLeaseSame('pending', $lease['state'], 'new lease pending');
    assertLeaseTrue(preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', (string) $lease['lease_id']) === 1, 'lease UUID v4 shape');
    assertLeaseSame(gmdate('Y-m-d H:i:s', $now + 45), $lease['ready_deadline_at'], 'pending deadline');

    $same = $service->acquireForClientDevice(10, 20, 'linux-device-a');
    assertLeaseSame($lease['lease_id'], $same['lease_id'], 'sticky lease id');
    assertLeaseSame(1, $same['generation'], 'sticky generation');
    expectLeaseApi(fn() => $service->acquireForNode(10, 15), 'leigod_line_lease_conflict');

    $active = $service->acceptHealth(10, (string) $lease['lease_id'], 1, [
        'owner_type' => 'client_device',
        'owner_id' => 20,
        'owner_device_id' => 'linux-device-a',
        'config_sha256' => $db->lines[10]['config_sha256'],
        'healthy' => true,
    ]);
    assertLeaseSame('active', $active['state'], 'healthy pending becomes active');
    assertLeaseSame(gmdate('Y-m-d H:i:s', $now + 90), $active['expires_at'], 'active ttl');
    expectLeaseApi(
        fn() => $service->acceptHealth(10, (string) $lease['lease_id'], 0, [
            'owner_type' => 'client_device', 'owner_id' => 20, 'owner_device_id' => 'linux-device-a',
            'config_sha256' => $db->lines[10]['config_sha256'], 'healthy' => true,
        ]),
        'leigod_line_lease_fence_mismatch'
    );
    expectLeaseApi(
        fn() => $service->acceptHealth(10, (string) $lease['lease_id'], 1, [
            'owner_type' => 'client_device', 'owner_id' => 99, 'owner_device_id' => 'linux-device-a',
            'config_sha256' => $db->lines[10]['config_sha256'], 'healthy' => true,
        ]),
        'leigod_line_lease_owner_mismatch'
    );

    $now += 30;
    $renewed = $service->renew((string) $lease['lease_id'], 1, 'client_device', 20, 'linux-device-a');
    assertLeaseSame(gmdate('Y-m-d H:i:s', $now + 90), $renewed['expires_at'], 'renewed ttl');
    assertLeaseSame(1, $renewed['generation'], 'renew does not bump generation');

    expectLeaseApi(fn() => $service->acquireForClientDevice(12, 20, 'linux-device-a'), 'leigod_line_client_execution_forbidden');
    expectLeaseApi(fn() => $service->acquireForNode(13, 15), 'leigod_line_node_execution_forbidden');

    $allocated = $service->allocateForClientDevice(21, 'linux-device-b', [10, 11, 12], 2);
    assertLeaseSame([11], array_column($allocated, 'leigod_line_id'), 'allocator is stable and non-preemptive');
    $allocatedActive = $service->acceptHealth(11, (string) $allocated[0]['lease_id'], 1, [
        'owner_type' => 'client_device',
        'owner_id' => 21,
        'owner_device_id' => 'linux-device-b',
        'config_sha256' => $db->lines[11]['config_sha256'],
        'healthy' => true,
    ]);
    assertLeaseSame('active', $allocatedActive['state'], 'allocated lease becomes active');

    $mismatchNow = 1_786_050_000;
    $mismatchDb = new Database();
    $mismatchDb->lines = [18 => testLine(18, 50)];
    $mismatchService = new LeigodExecutionLeaseService($config, $mismatchDb, static function () use (&$mismatchNow): int {
        return $mismatchNow;
    });
    $mismatchLease = $mismatchService->acquireForNode(18, 42);
    $mismatchService->acceptHealth(18, (string) $mismatchLease['lease_id'], 1, [
        'owner_type' => 'node',
        'owner_id' => 42,
        'config_sha256' => $mismatchDb->lines[18]['config_sha256'],
        'healthy' => true,
    ]);
    $mismatchRevoking = $mismatchService->acceptHealth(18, (string) $mismatchLease['lease_id'], 1, [
        'owner_type' => 'node',
        'owner_id' => 42,
        'config_sha256' => str_repeat('f', 64),
        'healthy' => true,
    ]);
    assertLeaseSame('revoking', $mismatchRevoking['state'], 'config mismatch revokes active lease');
    assertLeaseSame(
        gmdate('Y-m-d H:i:s', $mismatchNow + 15),
        $mismatchRevoking['expires_at'],
        'config mismatch uses revocation grace'
    );
    expectLeaseApi(
        fn() => $mismatchService->renew((string) $mismatchLease['lease_id'], 1, 'node', 42, null),
        'leigod_line_lease_expired'
    );

    $failureNow = 1_786_060_000;
    $failureDb = new Database();
    $failureDb->lines = [19 => testLine(19, 60)];
    $failureService = new LeigodExecutionLeaseService($config, $failureDb, static function () use (&$failureNow): int {
        return $failureNow;
    });
    $failureLease = $failureService->acquireForClientDevice(19, 43, 'linux-device-failure');
    $transientFailure = $failureService->acceptHealth(19, (string) $failureLease['lease_id'], 1, [
        'owner_type' => 'client_device',
        'owner_id' => 43,
        'owner_device_id' => 'linux-device-failure',
        'config_sha256' => $failureDb->lines[19]['config_sha256'],
        'healthy' => false,
        'consecutive_failures' => 2,
    ]);
    assertLeaseSame('pending', $transientFailure['state'], 'transient health failure keeps lease pending');
    $repeatedFailure = $failureService->acceptHealth(19, (string) $failureLease['lease_id'], 1, [
        'owner_type' => 'client_device',
        'owner_id' => 43,
        'owner_device_id' => 'linux-device-failure',
        'config_sha256' => $failureDb->lines[19]['config_sha256'],
        'healthy' => false,
        'consecutive_failures' => 3,
    ]);
    assertLeaseSame('revoking', $repeatedFailure['state'], 'repeated health failure revokes lease');

    $stickyDb = new Database();
    $stickyDb->lines = [
        14 => testLine(14, 60),
        15 => testLine(15, 50),
    ];
    $stickyService = new LeigodExecutionLeaseService($config, $stickyDb, static function () use (&$now): int {
        return $now;
    });
    $stickyLater = $stickyService->acquireForClientDevice(14, 30, 'linux-device-c');
    $stickyAllocation = $stickyService->allocateForClientDevice(30, 'linux-device-c', [15, 14], 1);
    assertLeaseSame(
        [$stickyLater['lease_id']],
        array_column($stickyAllocation, 'lease_id'),
        'allocator keeps an existing matching lease before acquiring spare capacity'
    );

    $timeoutNow = 1_786_100_000;
    $timeoutDb = new Database();
    $timeoutDb->lines = [16 => testLine(16, 10, lanes: 0)];
    $timeoutService = new LeigodExecutionLeaseService($config, $timeoutDb, static function () use (&$timeoutNow): int {
        return $timeoutNow;
    });
    $pendingTimeout = $timeoutService->acquireForNode(16, 40);
    assertLeaseSame(1, $pendingTimeout['max_parallel_lanes'], 'lane clamp lower bound');
    $timeoutNow += 46;
    expectLeaseApi(
        fn() => $timeoutService->renew((string) $pendingTimeout['lease_id'], 1, 'node', 40, null),
        'leigod_line_lease_expired'
    );
    assertLeaseSame(1, $timeoutService->reapExpired(), 'pending lease is reaped after ready deadline');
    assertLeaseSame('revoking', $timeoutDb->leases[16]['state'], 'reap preserves a fencing tombstone');
    assertLeaseSame(1, $timeoutDb->leases[16]['generation'], 'reap preserves fencing generation');
    $timeoutNow = strtotime((string) $timeoutDb->leases[16]['expires_at']) + 1;
    $postReap = $timeoutService->acquireForClientDevice(16, 44, 'linux-device-post-reap');
    assertLeaseSame(2, $postReap['generation'], 'post-reap allocation increments generation');

    $timezoneNow = 1_786_200_000;
    $timezoneDb = new Database();
    $timezoneDb->lines = [17 => testLine(17, 10)];
    $timezoneService = new LeigodExecutionLeaseService($config, $timezoneDb, static function () use (&$timezoneNow): int {
        return $timezoneNow;
    });
    $originalTimezone = date_default_timezone_get();
    date_default_timezone_set('Asia/Taipei');
    $timezoneLease = $timezoneService->acquireForNode(17, 41);
    $timezoneSticky = $timezoneService->acquireForNode(17, 41);
    date_default_timezone_set($originalTimezone);
    assertLeaseSame($timezoneLease['lease_id'], $timezoneSticky['lease_id'], 'UTC lease timestamps ignore PHP default timezone');

    $now += 30;
    $service->renew((string) $allocatedActive['lease_id'], 1, 'client_device', 21, 'linux-device-b');

    $now = strtotime((string) $db->leases[10]['expires_at']) + 1;
    $taken = $service->acquireForNode(10, 15);
    assertLeaseSame('node', $taken['owner_type'], 'expired takeover owner');
    assertLeaseSame(2, $taken['generation'], 'expired takeover bumps generation');
    assertLeaseTrue($taken['lease_id'] !== $lease['lease_id'], 'expired takeover rotates lease id');
    expectLeaseApi(
        fn() => $service->acceptHealth(10, (string) $lease['lease_id'], 1, [
            'owner_type' => 'client_device',
            'owner_id' => 20,
            'owner_device_id' => 'linux-device-a',
            'config_sha256' => $db->lines[10]['config_sha256'],
            'healthy' => true,
        ]),
        'leigod_line_lease_fence_mismatch'
    );

    $nodeActive = $service->acceptHealth(10, (string) $taken['lease_id'], 2, [
        'owner_type' => 'node',
        'owner_id' => 15,
        'config_sha256' => $db->lines[10]['config_sha256'],
        'healthy' => true,
    ]);
    assertLeaseSame('active', $nodeActive['state'], 'node health activates takeover');
    assertLeaseSame([10], array_column($service->activeForNode(15), 'leigod_line_id'), 'active node projection');

    $revoking = $service->revoke((string) $taken['lease_id'], 2, 'test_cleanup');
    assertLeaseSame('revoking', $revoking['state'], 'revoke state');
    assertLeaseSame('test_cleanup', $revoking['revocation_reason'], 'revoke reason is returned');
    $now = strtotime((string) $revoking['expires_at']) + 1;
    assertLeaseSame(0, $service->reapExpired(), 'expired revoking tombstone needs no second transition');
    assertLeaseSame('revoking', $db->leases[10]['state'], 'expired revoking tombstone is retained');
    assertLeaseSame(2, $db->leases[10]['generation'], 'expired tombstone retains generation');

    $clientRows = $service->activeForClientDevice(21, 'linux-device-b');
    assertLeaseSame([11], array_column($clientRows, 'leigod_line_id'), 'active client projection');
    assertLeaseSame([], $service->activeForNode(15), 'no active node leases after reap');

    $joinedCalls = json_encode($db->calls, JSON_UNESCAPED_SLASHES);
    assertLeaseTrue(is_string($joinedCalls) && str_contains($joinedCalls, 'FOR UPDATE'), 'mutations lock lease rows');
    $leaseLocks = array_values(array_filter(
        array_merge($db->calls, $mismatchDb->calls, $failureDb->calls, $timeoutDb->calls),
        static fn(array $call): bool => $call[0] === 'fetchOne'
            && str_contains($call[1], 'FROM leigod_execution_leases')
            && str_contains($call[1], 'FOR UPDATE')
    ));
    assertLeaseTrue($leaseLocks !== [], 'lease mutators issue locking reads');
    foreach ($leaseLocks as $call) {
        assertLeaseTrue(
            str_contains($call[1], 'leigod_line_id = :line_id'),
            'every lease locking read is keyed by leigod_line_id'
        );
    }
    assertLeaseTrue(count(array_filter($db->calls, static fn(array $call): bool => $call[0] === 'transaction')) >= 8, 'mutations use transactions');

    @unlink($envPath);
    echo "ok\n";
}
