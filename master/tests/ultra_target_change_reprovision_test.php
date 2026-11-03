<?php
declare(strict_types=1);

/**
 * An Ultra forward whose target changes must be re-provisioned or the edit
 * refused - never left enabled with no lease.
 *
 * Regression sentinel: ultra_target_change_reprovisions_lease_plane
 *
 * Before the fix, ProvisioningService::updateForwardNonStructural() wrote the
 * new target, bumped api_revision and called only the ENDPOINT reconciler,
 * which is a deliberate no-op for Ultra (reconcileForwardEndpoints() sets
 * desiredCount = 0 for backend_mode=ultra). The Ultra plane is a LEASE plane and
 * the lease scope digest covers the target, so every lease the client held
 * became unhonourable while the forward still read as enabled: the client agent
 * then reported awaiting_policy and held no listener, which is a silent outage.
 *
 * Covered here:
 *   1. an enabled Ultra forward whose new scope cannot be leased is REFUSED with
 *      ultra_reprovision_unavailable, and nothing is written;
 *   2. the same condition on a forward the guard does not own (paused) is NOT
 *      reported as an Ultra re-provision failure;
 *   3. the lease-plane mechanism itself: a lease for the superseded target or
 *      generation is refused, and only the new scope issues;
 *   4. revokeForwardLeases() retires every live lease for the forward;
 *   5. the update path still calls the re-provision hook (source sentinel).
 */

namespace Leikwan\Master {

    final class Config
    {
        /** @var array<string, string> */
        private array $values = [];

        public function __construct(string $envPath)
        {
            foreach (@file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (!is_string($line) || $line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $this->values[trim($key)] = trim($value);
            }
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->values[$key] ?? $default;
        }

        public function getInt(string $key, int $default = 0): int
        {
            return (int) ($this->values[$key] ?? $default);
        }

        public function getBool(string $key, bool $default = false): bool
        {
            if (!array_key_exists($key, $this->values)) {
                return $default;
            }
            return in_array(strtolower((string) $this->values[$key]), ['1', 'true', 'yes', 'on'], true);
        }
    }
}

namespace Leikwan\Master\Support {

    class ApiException extends \RuntimeException
    {
        /** @param array<string, mixed> $context */
        public function __construct(string $message, int $code = 500, private array $context = [])
        {
            parent::__construct($message, $code);
        }

        /** @return array<string, mixed> */
        public function context(): array
        {
            return $this->context;
        }
    }
}

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public array $tables = [];

        /** @var list<string> */
        public array $statements = [];

        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /** @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            $this->statements[] = 'fetchAll ' . $this->normalizeSql($sql);
            return [];
        }

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = $this->normalizeSql($sql);
            $this->statements[] = 'fetchOne ' . $normalized;

            if (str_contains($normalized, 'SELECT * FROM forwards WHERE id = :id LIMIT 1')) {
                return $this->row('forwards', (int) ($params['id'] ?? 0));
            }

            if (str_contains($normalized, 'SELECT * FROM services WHERE id = :id LIMIT 1')) {
                return $this->row('services', (int) ($params['id'] ?? 0));
            }

            if (str_contains($normalized, 'SELECT status FROM services WHERE id = :id LIMIT 1')) {
                $service = $this->row('services', (int) ($params['id'] ?? 0));
                return $service === null ? null : ['status' => (string) ($service['status'] ?? '')];
            }

            return null;
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int
        {
            $this->statements[] = 'insert ' . $table;
            $id = count($this->tables[$table] ?? []) + 1;
            $data['id'] = $id;
            $this->tables[$table][] = $data;
            return $id;
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $normalized = $this->normalizeSql($sql);
            $this->statements[] = 'execute ' . $normalized;

            if (str_contains($normalized, 'UPDATE ultra_client_leases')
                && str_contains($normalized, 'revoked_at = :revoked_at')
                && str_contains($normalized, 'WHERE forward_id = :forward_id AND revoked_at IS NULL')) {
                $affected = 0;
                foreach ($this->tables['ultra_client_leases'] ?? [] as $index => $row) {
                    if ((int) ($row['forward_id'] ?? 0) !== (int) ($params['forward_id'] ?? 0)) {
                        continue;
                    }
                    if ((string) ($row['revoked_at'] ?? '') !== '') {
                        continue;
                    }
                    $this->tables['ultra_client_leases'][$index]['revoked_at'] = (string) $params['revoked_at'];
                    $this->tables['ultra_client_leases'][$index]['updated_at'] = (string) $params['updated_at'];
                    $affected++;
                }
                return $affected;
            }

            return 0;
        }

        /** @return mixed */
        public function transaction(callable $callback)
        {
            $snapshot = $this->tables;
            try {
                return $callback($this);
            } catch (\Throwable $throwable) {
                // A refused edit must leave the panel exactly as it was.
                $this->tables = $snapshot;
                throw $throwable;
            }
        }

        /** @return array<string, mixed>|null */
        private function row(string $table, int $id): ?array
        {
            foreach ($this->tables[$table] ?? [] as $row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    return $row;
                }
            }
            return null;
        }

        private function normalizeSql(string $sql): string
        {
            return preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
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
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', (string) $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;
    use Leikwan\Master\Support\UltraLeaseService;
    use Leikwan\Master\Support\UltraMode;

    function failUltraTargetChange(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertUltraTargetChange(bool $condition, string $message): void
    {
        if (!$condition) {
            failUltraTargetChange($message);
        }
    }

    function assertRefusalCode(callable $call, string $expected): \Leikwan\Master\Support\ApiException
    {
        try {
            $call();
        } catch (\Leikwan\Master\Support\ApiException $exception) {
            assertUltraTargetChange(
                $exception->getMessage() === $expected,
                'expected refusal ' . $expected . ', got ' . $exception->getMessage()
            );
            return $exception;
        } catch (\Throwable $throwable) {
            failUltraTargetChange('expected refusal ' . $expected . ', got ' . $throwable::class . ': ' . $throwable->getMessage());
        }
        failUltraTargetChange('expected refusal ' . $expected . ', nothing was thrown');
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-ultra-target-');
    if ($envPath === false) {
        failUltraTargetChange('failed to create temp env file');
    }
    file_put_contents($envPath, "ULTRA_TOKEN_KEY=test-key\n");
    $config = new Config($envPath);

    // ---------------------------------------------------------------- scenario 1
    // An ENABLED Ultra forward whose new scope cannot be leased must be refused
    // outright, and the refused edit must leave the forward untouched.
    $db = new Database();
    $db->seed('services', [[
        'id' => 30,
        'user_id' => 3,
        'plan_id' => 9,
        'status' => 'active',
        'client_token' => 'svc-token',
    ]]);
    $db->seed('forwards', [[
        'id' => 201,
        'service_id' => 30,
        'enabled' => 1,
        'backend_mode' => UltraMode::BACKEND_MODE,
        'forward_combination_id' => 8,
        'target_host' => '70.36.96.183',
        'target_port' => 62101,
        'transport_mode' => 'tcp+udp',
        'listen_port' => 60501,
        'api_revision' => 2,
        'endpoint_mode' => UltraMode::CONTROL_PLANE_GROUP,
        'node_group_key' => UltraMode::CONTROL_PLANE_GROUP,
    ]]);

    $service = new ProvisioningService($config, $db);
    $refusal = assertRefusalCode(
        static fn(): array => $service->updateForwardNonStructural(201, [
            'target_host' => '103.246.219.39',
            'target_port' => 62101,
            'listen_port' => 60501,
            'transport_mode' => 'tcp+udp',
        ], 'operator'),
        'ultra_reprovision_unavailable'
    );
    assertUltraTargetChange(
        ($refusal->context()['reason'] ?? '') === 'no_leaseable_line',
        'refusal must name the missing leaseable line, got ' . (string) ($refusal->context()['reason'] ?? '')
    );
    assertUltraTargetChange((int) $refusal->getCode() === 409, 'a refused re-provision must be a 409 conflict');

    $kept = $db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', ['id' => 201]);
    assertUltraTargetChange(
        (string) ($kept['target_host'] ?? '') === '70.36.96.183',
        'a refused target change must not be persisted'
    );
    assertUltraTargetChange((int) ($kept['api_revision'] ?? 0) === 2, 'a refused target change must not bump api_revision');
    assertUltraTargetChange(
        $db->tables['ultra_client_leases'] ?? [] === [],
        'a refused target change must not touch the lease plane'
    );
    foreach ($db->statements as $statement) {
        assertUltraTargetChange(
            !str_starts_with($statement, 'insert'),
            'a refused target change must not write rows: ' . $statement
        );
    }

    // ---------------------------------------------------------------- scenario 2
    // The guard owns enabled Ultra forwards only: a paused forward must not be
    // reported as an Ultra re-provision failure (its start path re-provisions).
    $pausedDb = new Database();
    $pausedDb->seed('services', [['id' => 30, 'user_id' => 3, 'plan_id' => 9, 'status' => 'active', 'client_token' => 'svc-token']]);
    $pausedDb->seed('forwards', [[
        'id' => 202,
        'service_id' => 30,
        'enabled' => 0,
        'backend_mode' => UltraMode::BACKEND_MODE,
        'forward_combination_id' => 8,
        'target_host' => '70.36.96.183',
        'target_port' => 62102,
        'transport_mode' => 'tcp+udp',
        'listen_port' => 60502,
        'api_revision' => 2,
    ]]);
    $pausedService = new ProvisioningService($config, $pausedDb);
    try {
        $pausedService->updateForwardNonStructural(202, [
            'target_host' => '103.246.219.39',
            'target_port' => 62102,
            'listen_port' => 60502,
            'transport_mode' => 'tcp+udp',
        ], 'operator');
    } catch (\Leikwan\Master\Support\ApiException $exception) {
        assertUltraTargetChange(
            $exception->getMessage() !== 'ultra_reprovision_unavailable',
            'the Ultra re-provision guard must not fire for a paused forward'
        );
    }

    // ---------------------------------------------------------------- scenario 3
    // The mechanism the guard exists for: the lease scope digest covers the
    // target, so a target change makes every lease the client holds refuseable.
    $now = 1_900_000_000;
    $newTarget = '103.246.219.39:62101';
    $oldTarget = '70.36.96.183:62101';
    $candidateCall = 0;
    $newGeneration = hash('sha256', 'generation-after-target-change');
    $leases = new UltraLeaseService(
        $config,
        null,
        null,
        static function (array $request, int $clientId, string $deviceId) use (&$candidateCall): array {
            $candidateCall++;
            return [
                'selection_ref' => str_repeat('r', 43),
                'ip' => '203.0.113.20',
                'port' => 5438,
                'user' => 'line-user',
                'credential' => 'line-password',
            ];
        },
        static fn(): int => $now,
        static function (int $clientId, string $deviceId, int $forwardId) use ($newGeneration, $newTarget): array {
            return [
                'forward_id' => 201,
                'device_hash' => hash('sha256', 'device-a'),
                'service_state' => 'active',
                'policy_generation' => $newGeneration,
                'target' => $newTarget,
                'transport' => 'tcp+udp',
                'rate_limit_mbps' => 0,
                'quota_remaining_bytes' => UltraMode::MAX_QUOTA_LEASE_BYTES,
                'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', 1_900_000_300),
            ];
        },
    );
    $baseRequest = [
        'forward_id' => 201,
        'session_ticket' => 'ticket-a',
        'policy_generation' => $newGeneration,
        'nonce' => 'abcdef0123456789abcdef0123456789',
        'target_digest' => hash('sha256', $newTarget),
        'transport' => 'tcp+udp',
    ];
    assertUltraTargetChange(
        ($leases->issueLease($baseRequest, 3, 'device-a')['ok'] ?? false) === true,
        'the re-provisioned scope must issue a lease'
    );
    assertUltraTargetChange(
        ($leases->issueLease(
            array_merge($baseRequest, [
                'nonce' => 'abcdef0123456789abcdef01234567a1',
                'target_digest' => hash('sha256', $oldTarget),
            ]),
            3,
            'device-a'
        )['error'] ?? '') === 'policy_scope_invalid',
        'a lease still bound to the superseded target must be refused'
    );
    assertUltraTargetChange(
        ($leases->issueLease(
            array_merge($baseRequest, [
                'nonce' => 'abcdef0123456789abcdef01234567a2',
                'policy_generation' => hash('sha256', 'generation-before-target-change'),
            ]),
            3,
            'device-a'
        )['error'] ?? '') === 'policy_generation_invalid',
        'a lease carrying the superseded generation must be refused'
    );

    // ---------------------------------------------------------------- scenario 4
    // Re-provisioning retires the superseded scope: every live lease for the
    // forward is revoked, already-revoked rows are left alone, and an unknown
    // forward is a no-op.
    $leaseDb = new Database();
    $leaseDb->seed('ultra_client_leases', [
        ['id' => 1, 'forward_id' => 201, 'policy_generation' => 'g-old', 'revoked_at' => null],
        ['id' => 2, 'forward_id' => 201, 'policy_generation' => 'g-old', 'revoked_at' => null],
        ['id' => 3, 'forward_id' => 201, 'policy_generation' => 'g-older', 'revoked_at' => '2026-09-21 00:00:00'],
        ['id' => 4, 'forward_id' => 202, 'policy_generation' => 'g-other', 'revoked_at' => null],
    ]);
    $leaseService = new UltraLeaseService($config, $leaseDb, null, null, static fn(): int => $now);
    assertUltraTargetChange(
        $leaseService->revokeForwardLeases(201) === 2,
        're-provisioning must revoke exactly the live leases of that forward'
    );
    assertUltraTargetChange(
        (string) ($leaseDb->tables['ultra_client_leases'][0]['revoked_at'] ?? '') !== ''
            && (string) ($leaseDb->tables['ultra_client_leases'][1]['revoked_at'] ?? '') !== '',
        'live leases must be revoked'
    );
    assertUltraTargetChange(
        (string) ($leaseDb->tables['ultra_client_leases'][2]['revoked_at'] ?? '') === '2026-09-21 00:00:00',
        'an already-revoked lease must keep its original revocation time'
    );
    assertUltraTargetChange(
        (string) ($leaseDb->tables['ultra_client_leases'][3]['revoked_at'] ?? '') === '',
        'another forward\'s lease must not be revoked'
    );
    assertUltraTargetChange($leaseService->revokeForwardLeases(0) === 0, 'forward 0 is not a lease scope');
    assertUltraTargetChange(
        (new UltraLeaseService($config, null))->revokeForwardLeases(201) === 0,
        'a DB-less service must not pretend to revoke leases'
    );

    // ---------------------------------------------------------------- scenario 5
    $provisioningSource = (string) file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
    foreach ([
        'if ($this->ultraLeaseReprovisionRequired($forward, $targetHost, $targetPort, $transportMode)) {',
        '$this->reprovisionUltraLeasePlane(',
        '->revokeForwardLeases($forwardId)',
        "throw new ApiException('ultra_reprovision_unavailable', 409",
    ] as $snippet) {
        assertUltraTargetChange(
            str_contains($provisioningSource, $snippet),
            'the target-change path must keep its Ultra re-provision hook: ' . $snippet
        );
    }

    echo "ok\n";
}
