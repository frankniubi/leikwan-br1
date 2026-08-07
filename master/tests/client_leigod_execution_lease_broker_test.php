<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $lines = [];
        /** @var array<int, array<string, mixed>> */
        public array $leases = [];
        /** @var array<int, int> */
        public array $revisions = [];
        /** @var array<int, int> */
        public array $lockedMembershipIds = [];
        /** @var array<string, mixed>|null */
        public ?array $egressProfile = null;
        public bool $lockedMembershipCheckedInTransaction = false;
        public bool $inTransaction = false;

        public function transaction(callable $callback): mixed
        {
            if ($this->inTransaction) {
                throw new \RuntimeException('unexpected nested transaction');
            }
            $this->inTransaction = true;
            try {
                return $callback($this);
            } finally {
                $this->inTransaction = false;
            }
        }

        /** @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM client_config_revisions')) {
                $clientId = (int) ($params['client_id'] ?? 0);
                return isset($this->revisions[$clientId]) ? ['revision' => $this->revisions[$clientId]] : null;
            }
            if (str_contains($sql, 'FROM leigod_lines')) {
                return $this->lines[(int) ($params['line_id'] ?? $params['id'] ?? 0)] ?? null;
            }
            if (str_contains($sql, 'leigod_egress_profiles')) {
                return $this->egressProfile;
            }
            if (str_contains($sql, 'FROM forward_routes')) {
                return ['id' => (int) ($params['id'] ?? 80), 'is_active' => 1, 'race_enabled' => 0];
            }
            if (str_contains($sql, 'FROM leigod_execution_leases')) {
                if (isset($params['line_id'])) {
                    return $this->leases[(int) $params['line_id']] ?? null;
                }
                foreach ($this->leases as $lease) {
                    if ((string) ($lease['lease_id'] ?? '') === (string) ($params['lease_id'] ?? '')) {
                        return $lease;
                    }
                }
            }
            return null;
        }

        /** @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'hybrid_local_leases')) {
                $approvedIds = [];
                foreach ($params as $key => $value) {
                    if (str_starts_with((string) $key, 'hybrid_catalog_')) {
                        $approvedIds[] = (int) $value;
                    }
                }
                $rows = [];
                foreach ($this->leases as $catalogId => $lease) {
                    $line = $this->lines[$catalogId] ?? null;
                    if (!is_array($line)
                        || !in_array((int) $catalogId, $approvedIds, true)
                        || (string) ($lease['state'] ?? '') !== 'active') {
                        continue;
                    }
                    $rows[] = [
                        'catalog_id' => (int) $catalogId,
                        'physical_line_id' => (int) $line['line_id'],
                        'catalog_sort_order' => (int) ($line['sort_order'] ?? 100),
                        'config_sha256' => (string) $line['config_sha256'],
                        'lease_id' => (string) $lease['lease_id'],
                        'lease_generation' => (int) $lease['generation'],
                        'lease_state' => (string) $lease['state'],
                        'max_parallel_lanes' => (int) $lease['max_parallel_lanes'],
                        'lease_expires_at' => (string) $lease['expires_at'],
                    ];
                }
                return $rows;
            }
            if (str_contains($sql, 'hybrid_client_catalog_ids')
                && !str_contains($sql, 'hybrid_client_catalog_ids_locked')) {
                return array_values(array_map(
                    static fn(array $line): array => [
                        'catalog_id' => (int) $line['id'],
                        'line_id' => (int) $line['line_id'],
                        'config_sha256' => (string) $line['config_sha256'],
                        'is_active' => (int) $line['is_active'],
                        'allow_client_execution' => (int) $line['allow_client_execution'],
                        'catalog_sort_order' => (int) ($line['sort_order'] ?? 100),
                    ],
                    array_filter(
                        $this->lines,
                        static fn(array $line): bool => (int) ($line['is_active'] ?? 0) === 1
                            && (int) ($line['allow_client_execution'] ?? 0) === 1
                    )
                ));
            }
            if (str_contains($sql, 'hybrid_local_parallel_catalog_ids')) {
                $ids = array_map('intval', array_values($params));
                return array_values(array_map(
                    static fn(array $line): array => ['catalog_id' => (int) $line['id']],
                    array_filter(
                        $this->lines,
                        static fn(array $line): bool => in_array((int) $line['id'], $ids, true)
                            && (int) ($line['is_active'] ?? 0) === 1
                            && (int) ($line['allow_client_execution'] ?? 0) === 1
                            && (int) ($line['max_parallel_lanes'] ?? 0) >= 2
                            && (int) ($line['max_parallel_lanes'] ?? 0) <= 4
                    )
                ));
            }
            if (str_contains($sql, 'hybrid_client_catalog_ids_locked')) {
                $this->lockedMembershipCheckedInTransaction = $this->inTransaction;
                $lockedIds = [];
                foreach ($params as $key => $value) {
                    if (str_starts_with((string) $key, 'locked_catalog_')) {
                        $lockedIds[] = (int) $value;
                    }
                }
                return array_map(
                    static fn(int $catalogId): array => ['catalog_id' => $catalogId],
                    array_values(array_intersect($lockedIds, $this->lockedMembershipIds))
                );
            }
            if (str_contains($sql, 'FROM leigod_execution_leases lel')) {
                $rows = [];
                $candidateIds = [];
                foreach ($params as $key => $value) {
                    if (str_starts_with((string) $key, 'candidate_')) {
                        $candidateIds[] = (int) $value;
                    }
                }
                foreach ($this->leases as $lineId => $lease) {
                    $line = $this->lines[$lineId] ?? null;
                    if (!is_array($line)
                        || ($candidateIds !== [] && !in_array((int) $lineId, $candidateIds, true))
                        || (string) ($lease['owner_type'] ?? '') !== (string) ($params['owner_type'] ?? '')
                        || (int) ($lease['owner_id'] ?? 0) !== (int) ($params['owner_id'] ?? 0)
                        || (string) ($lease['owner_device_id'] ?? '') !== (string) ($params['owner_device_id'] ?? '')
                        || !in_array((string) ($lease['state'] ?? ''), ['pending', 'active'], true)
                        || strtotime((string) ($lease['expires_at'] ?? '')) <= time()
                        || (int) ($line['is_active'] ?? 0) !== 1
                        || (int) ($line['allow_client_execution'] ?? 0) !== 1) {
                        continue;
                    }
                    $rows[] = [
                        'catalog_id' => (int) $line['id'],
                        'line_id' => (int) $line['line_id'],
                        'config_sha256' => (string) $line['config_sha256'],
                        'is_active' => (int) $line['is_active'],
                        'allow_client_execution' => (int) $line['allow_client_execution'],
                        'lease_id' => (string) $lease['lease_id'],
                        'lease_generation' => (int) $lease['generation'],
                        'lease_state' => (string) $lease['state'],
                        'lease_owner_type' => (string) $lease['owner_type'],
                        'lease_owner_id' => (int) $lease['owner_id'],
                        'lease_owner_device_id' => (string) $lease['owner_device_id'],
                        'max_parallel_lanes' => (int) $lease['max_parallel_lanes'],
                        'lease_ready_deadline_at' => (string) $lease['ready_deadline_at'],
                        'lease_expires_at' => (string) $lease['expires_at'],
                        'sort_order' => (int) ($line['sort_order'] ?? 100),
                    ];
                }
                usort($rows, static fn(array $a, array $b): int => [
                    (int) $a['sort_order'],
                    (int) $a['catalog_id'],
                ] <=> [
                    (int) $b['sort_order'],
                    (int) $b['catalog_id'],
                ]);
                return $rows;
            }
            if (str_contains($sql, 'FROM leigod_lines')) {
                $candidateIds = [];
                foreach ($params as $key => $value) {
                    if (str_starts_with((string) $key, 'candidate_')) {
                        $candidateIds[] = (int) $value;
                    }
                }
                $rows = array_values(array_filter($this->lines, static function (array $line) use ($candidateIds): bool {
                    return ($candidateIds === [] || in_array((int) $line['id'], $candidateIds, true))
                        && (int) ($line['is_active'] ?? 0) === 1
                        && (int) ($line['allow_client_execution'] ?? 0) === 1;
                }));
                usort($rows, static fn(array $a, array $b): int => [
                    (int) ($a['sort_order'] ?? 100),
                    (int) $a['id'],
                ] <=> [
                    (int) ($b['sort_order'] ?? 100),
                    (int) $b['id'],
                ]);
                return $rows;
            }
            return [];
        }

        public function execute(string $sql, array $params = []): int
        {
            if (!$this->inTransaction) {
                throw new \RuntimeException('broker write outside transaction');
            }
            if (str_contains($sql, 'client_config_revisions')) {
                $clientId = (int) ($params['client_id'] ?? 0);
                $this->revisions[$clientId] = ($this->revisions[$clientId] ?? 0) + 1;
                return 1;
            }
            $lineId = (int) ($params['line_id'] ?? 0);
            if (!isset($this->leases[$lineId])) {
                return 0;
            }
            $this->leases[$lineId] = array_replace($this->leases[$lineId], $params);
            return 1;
        }

        public function insert(string $table, array $row): int
        {
            if (!$this->inTransaction || $table !== 'leigod_execution_leases') {
                throw new \RuntimeException('unexpected broker insert');
            }
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
    use Leikwan\Master\Support\LeigodExecutionLeaseService;
    use Leikwan\Master\Support\LeigodLineCatalogService;
    use Leikwan\Master\Support\ProvisioningService;

    function failClientLeaseBroker(string $message): never
    {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }

    function assertClientLeaseBrokerSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            failClientLeaseBroker($message . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true));
        }
    }

    $toml10 = "line = 510\n";
    $toml11 = "line = 511\n";
    $toml12 = "line = 512\n";
    $envPath = tempnam(sys_get_temp_dir(), 'lw-client-lease-broker-');
    $offEnvPath = tempnam(sys_get_temp_dir(), 'lw-client-lease-broker-off-');
    $hybridOffEnvPath = tempnam(sys_get_temp_dir(), 'lw-client-lease-hybrid-off-');
    if ($envPath === false || $offEnvPath === false || $hybridOffEnvPath === false) {
        failClientLeaseBroker('unable to create temp env');
    }
    file_put_contents($envPath, implode("\n", [
        'LEIGOD_EXECUTION_LEASES_ENABLED=1',
        'LEIGOD_LINUX_HYBRID_ENABLED=1',
        'LEIGOD_CLIENT_EXECUTION_BROKER_ENABLED=1',
        'LEIGOD_CLIENT_EXECUTION_DESIRED_LINES=1',
        'LEIGOD_EGRESS_TEST_SECRET=egress-secret',
        'LEIGOD_LINE_TOML_510=' . base64_encode($toml10),
        'LEIGOD_LINE_TOML_511=' . base64_encode($toml11),
        'LEIGOD_LINE_TOML_512=' . base64_encode($toml12),
        'LEIGOD_SESSION_JSON_B64=' . base64_encode('{"session":"local-only"}'),
        '',
    ]));
    file_put_contents($offEnvPath, implode("\n", [
        'LEIGOD_EXECUTION_LEASES_ENABLED=1',
        'LEIGOD_LINE_TOML_511=' . base64_encode($toml11),
        '',
    ]));
    file_put_contents($hybridOffEnvPath, implode("\n", [
        'LEIGOD_EXECUTION_LEASES_ENABLED=1',
        'LEIGOD_CLIENT_EXECUTION_BROKER_ENABLED=1',
        'LEIGOD_LINE_TOML_511=' . base64_encode($toml11),
        '',
    ]));

    $db = new Database();
    $db->lines = [
        10 => [
            'id' => 10, 'line_id' => 510, 'config_sha256' => hash('sha256', $toml10),
            'is_active' => 1, 'allow_client_execution' => 1, 'max_parallel_lanes' => 2, 'sort_order' => 1,
        ],
        11 => [
            'id' => 11, 'line_id' => 511, 'config_sha256' => hash('sha256', $toml11),
            'is_active' => 1, 'allow_client_execution' => 0, 'max_parallel_lanes' => 2, 'sort_order' => 2,
        ],
        12 => [
            'id' => 12, 'line_id' => 512, 'config_sha256' => hash('sha256', $toml12),
            'is_active' => 1, 'allow_client_execution' => 1, 'max_parallel_lanes' => 4, 'sort_order' => 3,
        ],
    ];
    $config = new Config($envPath);
    $provisioning = new ProvisioningService($config, $db);

    $db->egressProfile = [
        'id' => 5,
        'profile_key' => 'hk-egress',
        'region' => 'hk',
        'host' => '198.51.100.50',
        'tcp_port' => 7443,
        'secret_ref' => 'LEIGOD_EGRESS_TEST_SECRET',
        'is_enabled' => 1,
    ];
    $db->lines[10]['allow_client_execution'] = 0;
    $db->lockedMembershipIds = [12];
    $catalog = new LeigodLineCatalogService($config, $db);
    $hybrid = new \Leikwan\Master\Support\LeigodHybridRuntimeService(
        $config,
        $db,
        static function (array $forward, string $secret, string $protocol): string {
            $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $payload = (string) json_encode([
                'target_host' => (string) ($forward['target_host'] ?? ''),
                'target_port' => (int) ($forward['target_port'] ?? 0),
                'target_protocol' => $protocol,
                'forward_id' => (int) ($forward['id'] ?? $forward['forward_id'] ?? 0),
            ], JSON_UNESCAPED_SLASHES);
            return $encode($payload) . '.' . $encode(hash_hmac('sha256', $payload, $secret, true));
        },
        ['version' => '0.2.0', 'sha256' => str_repeat('b', 64)],
        $catalog
    );
    $coldProvisioning = new ProvisioningService($config, $db, $hybrid);
    $coldForward = [
        'id' => 70,
        'forward_id' => 70,
        'service_id' => 9,
        'service_status' => 'active',
        'backend_mode' => 'leigod_mode',
        'client_mode' => 'bond',
        'mode' => 'bond',
        'transport_mode' => 'tcp',
        'transport' => 'tcp',
        'target_host' => 'v4-hkt-b.mcbar.cc',
        'target_port' => 5201,
        'forward_route_id' => 80,
        'forward_combination_id' => 100,
        'bond_sidecar_point_id' => 99,
        'endpoints' => [],
    ];
    $coldClient = ['id' => 20, 'runtime_platform_scope' => 'linux'];
    $coldSession = [
        'id' => 30,
        'client_id' => 20,
        'device_id' => 'linux-device-a',
        'platform' => 'linux-amd64',
        'pathd_capability' => ['version' => '0.2.0', 'sha256' => str_repeat('b', 64)],
    ];
    $coldCatalogIds = [];
    $coldScopes = [];
    assertClientLeaseBrokerSame([], $db->leases, 'cold local-only pull begins without a lease');
    assertClientLeaseBrokerSame(
        true,
        $coldProvisioning->hasApprovedLinuxHybridAssignment(
            $coldClient,
            $coldSession,
            [$coldForward],
            $coldCatalogIds,
            $coldScopes
        ),
        'endpointless local-only forward is eligible for cold allocation'
    );
    assertClientLeaseBrokerSame([12], $coldCatalogIds, 'cold allocation is scoped to the repeated-lane catalog');
    assertClientLeaseBrokerSame([[
        'route_id' => 80,
        'combination_id' => 100,
        'catalog_ids' => [12],
    ]], $coldScopes, 'cold allocation carries its locked forward scope');
    $coldProjection = $coldProvisioning->desiredLeigodLineManagementForClientDevice(
        20,
        'linux-device-a',
        $coldCatalogIds,
        $coldScopes
    );
    assertClientLeaseBrokerSame([512], array_column($coldProjection['lines'] ?? [], 'line_id'), 'cold pull allocates and projects the repeated line');
    assertClientLeaseBrokerSame('pending', $coldProjection['lines'][0]['lease_state'] ?? null, 'cold pull issues a pending lease');
    assertClientLeaseBrokerSame(true, $db->lockedMembershipCheckedInTransaction, 'cold pull revalidates membership under allocator locks');
    $coldLeaseId = (string) ($coldProjection['lines'][0]['lease_id'] ?? '');
    $coldGeneration = (int) ($coldProjection['lines'][0]['lease_generation'] ?? 0);
    (new LeigodExecutionLeaseService($config, $db))->acceptHealth(12, $coldLeaseId, $coldGeneration, [
        'owner_type' => 'client_device',
        'owner_id' => 20,
        'owner_device_id' => 'linux-device-a',
        'config_sha256' => hash('sha256', $toml12),
        'healthy' => true,
    ]);
    assertClientLeaseBrokerSame('active', $db->leases[12]['state'] ?? null, 'fenced health activates the cold lease');
    assertClientLeaseBrokerSame(
        [12],
        $hybrid->preflightClientCatalogIds($coldForward, $coldClient, $coldSession, []),
        'second pull retains local-only preflight authorization'
    );
    $secondPullRuntime = $hybrid->forForward($coldForward, $coldClient, $coldSession, []);
    assertClientLeaseBrokerSame(true, is_array($secondPullRuntime), 'second pull builds a local-only runtime');
    assertClientLeaseBrokerSame(
        [512, 1_005_122, 1_005_123, 1_005_124],
        array_column($secondPullRuntime['local_leases'][0]['routes'] ?? [], 'route_id'),
        'second pull publishes every lane after the fenced lease becomes active'
    );
    assertClientLeaseBrokerSame([], $secondPullRuntime['slave_legs'] ?? null, 'second pull remains local-only');

    $db->leases = [];
    $db->revisions = [];
    $db->lines[10]['allow_client_execution'] = 1;
    $db->lockedMembershipCheckedInTransaction = false;

    $unapproved = $provisioning->desiredLeigodLineManagementForClientDevice(20, 'linux-device-a');
    assertClientLeaseBrokerSame(['lines' => []], $unapproved, 'unapproved pull receives no provider material');
    assertClientLeaseBrokerSame([], $db->leases, 'unapproved pull cannot claim the shared line pool');
    assertClientLeaseBrokerSame(0, $db->revisions[20] ?? 0, 'unapproved pull does not bump revision');

    $scope10 = [['route_id' => 80, 'combination_id' => 100, 'catalog_ids' => [10]]];
    $staleMembership = $provisioning->desiredLeigodLineManagementForClientDevice(
        19,
        'linux-device-stale',
        [10],
        $scope10
    );
    assertClientLeaseBrokerSame([], $staleMembership['lines'] ?? [], 'locked membership removal blocks allocation');
    assertClientLeaseBrokerSame(false, isset($db->leases[10]), 'stale approved ID cannot escape locked revalidation');

    $db->lockedMembershipIds = [12];
    $scope12 = [['route_id' => 80, 'combination_id' => 100, 'catalog_ids' => [12]]];

    $first = $provisioning->desiredLeigodLineManagementForClientDevice(
        20,
        'linux-device-a',
        [12],
        $scope12
    );
    assertClientLeaseBrokerSame([512], array_column($first['lines'] ?? [], 'line_id'), 'clean DB gets one ready allowed line');
    assertClientLeaseBrokerSame(false, isset($db->leases[10]), 'ready global line outside approved membership is never leased');
    assertClientLeaseBrokerSame('pending', $first['lines'][0]['lease_state'] ?? null, 'broker issues pending lease');
    assertClientLeaseBrokerSame(1, $first['lines'][0]['lease_generation'] ?? null, 'broker issues first fence generation');
    assertClientLeaseBrokerSame(4, $first['lines'][0]['max_parallel_lanes'] ?? null, 'broker preserves line lane policy');
    assertClientLeaseBrokerSame(1, $db->revisions[20] ?? 0, 'new broker lease bumps client revision atomically');
    assertClientLeaseBrokerSame(true, $db->lockedMembershipCheckedInTransaction, 'membership is revalidated in allocation transaction');
    $leaseId = (string) ($first['lines'][0]['lease_id'] ?? '');

    $second = $provisioning->desiredLeigodLineManagementForClientDevice(20, 'linux-device-a', [12]);
    assertClientLeaseBrokerSame($leaseId, $second['lines'][0]['lease_id'] ?? null, 'repeated pull keeps lease id');
    assertClientLeaseBrokerSame(1, $second['lines'][0]['lease_generation'] ?? null, 'repeated pull keeps generation');
    assertClientLeaseBrokerSame(1, $db->revisions[20] ?? 0, 'idempotent broker pull does not bump revision');

    $leaseService = new LeigodExecutionLeaseService($config, $db);
    $active = $leaseService->acceptHealth(12, $leaseId, 1, [
        'owner_type' => 'client_device',
        'owner_id' => 20,
        'owner_device_id' => 'linux-device-a',
        'config_sha256' => hash('sha256', $toml12),
        'healthy' => true,
    ]);
    assertClientLeaseBrokerSame('active', $active['state'] ?? null, 'fenced health activates broker lease');
    $activePull = $provisioning->desiredLeigodLineManagementForClientDevice(20, 'linux-device-a', [12]);
    assertClientLeaseBrokerSame($leaseId, $activePull['lines'][0]['lease_id'] ?? null, 'active fenced lease remains sticky');
    assertClientLeaseBrokerSame('active', $activePull['lines'][0]['lease_state'] ?? null, 'active fenced state is projected');
    assertClientLeaseBrokerSame([], $provisioning->desiredLeigodLineManagementForClientDevice(21, 'linux-device-b', [12])['lines'] ?? [], 'broker never preempts live lease');

    $healthResult = (new LeigodLineCatalogService($config, $db))->ingestClientHealth(
        20,
        'linux-device-a',
        [[
            'catalog_id' => 12,
            'line_id' => 512,
            'lease_id' => $leaseId,
            'lease_generation' => 1,
            'config_sha256' => hash('sha256', $toml12),
            'service_state' => 'failed',
            'tcp_status' => 'failed',
            'consecutive_failures' => 3,
        ]]
    );
    assertClientLeaseBrokerSame(['accepted' => 1, 'rejected' => 0], $healthResult, 'fenced client failure is accepted');
    assertClientLeaseBrokerSame('revoking', $db->leases[12]['state'] ?? null, 'reported consecutive failure threshold revokes lease');

    $offDb = new Database();
    $offDb->lines = [11 => array_replace($db->lines[11], ['allow_client_execution' => 1])];
    $offProjection = (new ProvisioningService(new Config($offEnvPath), $offDb))
        ->desiredLeigodLineManagementForClientDevice(30, 'linux-device-off', [11]);
    assertClientLeaseBrokerSame(['lines' => []], $offProjection, 'broker gate defaults to no allocation');
    assertClientLeaseBrokerSame([], $offDb->leases, 'broker gate off leaves clean DB unchanged');

    $hybridOffDb = new Database();
    $hybridOffDb->lines = [11 => array_replace($db->lines[11], ['allow_client_execution' => 1])];
    $hybridOffProjection = (new ProvisioningService(new Config($hybridOffEnvPath), $hybridOffDb))
        ->desiredLeigodLineManagementForClientDevice(31, 'linux-device-hybrid-off', [11]);
    assertClientLeaseBrokerSame(['lines' => []], $hybridOffProjection, 'hybrid rollout gate blocks broker allocation');
    assertClientLeaseBrokerSame([], $hybridOffDb->leases, 'hybrid gate off leaves clean DB unchanged');

    $controllerSource = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php') ?: '';
    $pullStart = strpos($controllerSource, 'function pullConfigs(');
    $watchStart = strpos($controllerSource, 'function watchConfigs(');
    $pullSource = $pullStart !== false && $watchStart !== false
        ? substr($controllerSource, $pullStart, $watchStart - $pullStart)
        : '';
    $projectionPosition = strpos($pullSource, 'desiredLeigodLineManagementForClientDevice(');
    $approvalPosition = strpos($pullSource, 'hasApprovedLinuxHybridAssignment(');
    $revisionPosition = strpos($pullSource, 'new ClientConfigRevisionService(');
    if ($approvalPosition === false
        || $projectionPosition === false
        || $revisionPosition === false
        || $approvalPosition >= $projectionPosition
        || $projectionPosition >= $revisionPosition) {
        failClientLeaseBroker('pull must allocate/project leases before reading response revision and ETag');
    }

    @unlink($envPath);
    @unlink($offEnvPath);
    @unlink($hybridOffEnvPath);
    echo "ok\n";
}
