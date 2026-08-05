<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use DateTimeImmutable;
use DateTimeZone;
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ApiException;

final class ProvisioningService
{
    public const DEFAULT_MAX_LEGS_USED = 4;
    private const LEIGOD_PARALLEL_ROUTE_BASE = 1000000;
    private const LEIGOD_PARALLEL_ROUTE_MULTIPLIER = 10;
    // donation_qualify_gate_ratio
    public const DONATION_GATE_RATIO = 0.75;


    private ?bool $nodeGroupIngressColumnsAvailable = null;
    private ?bool $nodeGroupDescriptionColumnAvailable = null;
    private ?LeigodLineCatalogService $leigodLineCatalogService = null;
    private ?ClientConfigRevisionService $clientConfigRevisionService = null;

    /**
     * Per-(sidecar point) uniqueness-checked allocation registries. Keyed by point id,
     * each holds already-assigned uplink ports, leg ports and /30 subnets so that distinct
     * (point, combination, node, lane) tuples never alias onto the same slot the way pure
     * %rangeSize hashing did. Cached per tuple so a republish reproduces the same value.
     *
     * @var array<int, array<string, int>>
     */
    private array $staticSidecarUplinkPortRegistry = [];
    /** @var array<int, array<string, int>> */
    private array $staticSidecarLegPortRegistry = [];
    /** @var array<int, array<string, int>> */
    private array $staticSidecarSubnetRegistry = [];
    /** @var array<int, bool> */
    private array $sharedStaticSidecarSubnetRegistryPrimed = [];
    /** @var array<string, bool> */
    private array $staticSidecarUndersizeReported = [];
    /**
     * FIX B: memoized combination_id -> sidecar_point_id used by the per-node collision-free selector
     * port allocator to reproduce each non-target endpoint's deterministic hash bucket.
     *
     * @var array<int, int>
     */
    private array $sidecarPointIdByCombination = [];

    public function __construct(
        private Config $config,
        private Database $db,
    ) {
    }

    private function leigodLineCatalog(): LeigodLineCatalogService
    {
        return $this->leigodLineCatalogService ??= new LeigodLineCatalogService($this->config, $this->db);
    }

    private function clientConfigRevisions(): ClientConfigRevisionService
    {
        return $this->clientConfigRevisionService ??= new ClientConfigRevisionService($this->db);
    }

    public function bumpClientConfigRevision(int $clientId): int
    {
        return $this->clientConfigRevisions()->bump($clientId);
    }

    /**
     * @param array<int, int|string> $clientIds
     * @return array<int, int>
     */
    public function bumpClientConfigRevisions(array $clientIds): array
    {
        return $this->clientConfigRevisions()->bumpMany($clientIds);
    }

    /** @return array<int, array<string, mixed>> */
    public function listLeigodLines(): array
    {
        return $this->leigodLineCatalog()->listLines();
    }

    /** @return array<string, mixed> */
    public function saveLeigodLine(array $payload): array
    {
        return $this->leigodLineCatalog()->saveLine($payload);
    }

    public function deleteLeigodLine(int $id): void
    {
        $this->leigodLineCatalog()->deleteLine($id);
    }

    /** @return array{lines:array<int, array<string, mixed>>} */
    public function desiredLeigodLineManagementForNode(int $nodeId): array
    {
        return ['lines' => $this->leigodLineCatalog()->desiredLinesForNode($nodeId)];
    }

    /** @return array{lines:array<int, array<string, mixed>>,session_json?:string} */
    public function desiredLeigodLineManagementForClientDevice(int $clientId, string $deviceId): array
    {
        $lines = $this->leigodLineCatalog()->desiredLinesForClientDevice($clientId, $deviceId);
        if ($lines === []) {
            return ['lines' => []];
        }
        return [
            'lines' => $lines,
            'session_json' => $this->leigodSharedSessionJson(),
        ];
    }

    /**
     * @param array<int|string, mixed> $payload
     * @return array{accepted:int,rejected:int}
     */
    public function ingestNodeLeigodLineHealth(int $nodeId, array $payload): array
    {
        return $this->leigodLineCatalog()->ingestNodeHealth($nodeId, $payload);
    }

    /** @return array<string, mixed> */
    public function leigodRouteAvailability(int $routeId, string $transport): array
    {
        if (!$this->forwardRouteUsesDedicatedLeigod($routeId)) {
            return [
                'route_id' => $routeId,
                'health_gated' => false,
                'available' => true,
                'healthy_node_count' => null,
                'unavailable_reason' => null,
                'nodes' => [],
            ];
        }
        return ['health_gated' => true] + $this->leigodLineCatalog()->routeAvailability($routeId, $transport);
    }

    /** @return array<int, array{leigod_line_id:int,enabled:int,sort_order:int}> */
    private function legacyLeigodMembershipPayload(int $lineId): array
    {
        $line = $this->db->fetchOne(
            'SELECT * FROM leigod_lines WHERE line_id = :line_id LIMIT 1',
            ['line_id' => $lineId]
        );
        $catalogId = (int) ($line['id'] ?? 0);
        if ($catalogId <= 0) {
            $now = $this->now();
            $catalogId = $this->db->insert('leigod_lines', [
                'line_id' => $lineId,
                'label' => 'Legacy line ' . $lineId,
                'notes' => null,
                'tcp_probe_host' => '',
                'tcp_probe_port' => 0,
                'udp_probe_host' => null,
                'udp_probe_port' => null,
                'udp_probe_method' => null,
                'config_sha256' => str_repeat('0', 64),
                'is_active' => 0,
                'sort_order' => 100,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [[
            'leigod_line_id' => $catalogId,
            'enabled' => 1,
            'sort_order' => 100,
        ]];
    }

    private function transactionIfSupported(callable $callback): mixed
    {
        if (method_exists($this->db, 'transaction')) {
            return $this->db->transaction($callback);
        }
        return $callback($this->db);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createOrUpdateServiceFromWhmcs(array $payload): array
    {
        $userEmail = $this->requiredString($payload, 'user_email');
        $userName = (string) ($payload['user_name'] ?? $userEmail);
        $whmcsServiceId = (int) $this->requiredString($payload, 'whmcs_service_id');
        $explicitPlanId = (int) ($payload['plan_id'] ?? 0);
        $productCode = (string) ($payload['product_code'] ?? 'default');
        $serviceName = (string) ($payload['service_name'] ?? ('WHMCS Service #' . $whmcsServiceId));
        $expireAt = (string) ($payload['expire_at'] ?? gmdate('Y-m-d H:i:s', strtotime('+30 days')));
        $bandwidthLimit = (int) ($payload['bandwidth_limit_mbps'] ?? 100);
        $trafficLimit = (int) ($payload['traffic_limit_gb'] ?? 1000);
        $status = (string) ($payload['status'] ?? 'active');
        $forwards = is_array($payload['forwards'] ?? null) ? $payload['forwards'] : [];
        $productIdentity = null;
        $productPayload = is_array($payload['products'][0] ?? null) ? $payload['products'][0] : [];
        if ($productPayload !== []) {
            $productIdentity = (new WhmcsClient($this->config))->normalizePurchasedServerIdentity($productPayload);
            if (is_array($productIdentity) && (int) ($productIdentity['service_id'] ?? 0) > 0 && (int) ($productIdentity['service_id'] ?? 0) !== $whmcsServiceId) {
                $productIdentity = null;
            }
        }
        $serverHostname = is_array($productIdentity) ? $productIdentity['hostname'] : trim((string) ($payload['server_hostname'] ?? ''));
        $serverPublicIp = is_array($productIdentity) ? $productIdentity['server_ip'] : trim((string) ($payload['server_public_ip'] ?? ''));
        $serverIpState = $serverPublicIp !== '' ? 'fresh' : 'missing';

        return $this->db->transaction(function () use (
            $userEmail,
            $userName,
            $whmcsServiceId,
            $explicitPlanId,
            $productCode,
            $serviceName,
            $expireAt,
            $bandwidthLimit,
            $trafficLimit,
            $status,
            $forwards,
            $serverHostname,
            $serverPublicIp,
            $serverIpState
        ): array {
            $user = $this->ensureUser($userEmail, $userName);
            $plan = $explicitPlanId > 0
                ? ($this->db->fetchOne('SELECT * FROM service_plans WHERE id = :id LIMIT 1', ['id' => $explicitPlanId]) ?? $this->ensurePlan($productCode, $bandwidthLimit, $trafficLimit))
                : $this->ensurePlan($productCode, $bandwidthLimit, $trafficLimit);

            $service = $this->db->fetchOne('SELECT * FROM services WHERE whmcs_service_id = :id LIMIT 1', [
                'id' => $whmcsServiceId,
            ]);
            $billingSnapshot = $this->serviceBillingSnapshot($plan, $trafficLimit, $service);

            if ($service === null) {
                $serviceId = $this->db->insert('services', [
                    'user_id' => $user['id'],
                    'plan_id' => $plan['id'],
                    'name' => $serviceName,
                    'whmcs_service_id' => $whmcsServiceId,
                    'server_hostname' => $serverHostname !== '' ? $serverHostname : null,
                    'server_public_ip' => $serverPublicIp !== '' ? $serverPublicIp : null,
                    'server_ip_state' => $serverIpState,
                    'server_ip_last_synced_at' => $this->now(),
                    'status' => $status,
                    'expire_at' => $expireAt,
                    'bandwidth_limit_mbps' => $bandwidthLimit,
                    'traffic_limit_gb' => $trafficLimit,
                    'traffic_used_bytes' => 0,
                    'client_token' => bin2hex(random_bytes(24)),
                    'suspend_reason' => null,
                    'billing_mode' => $billingSnapshot['billing_mode'],
                    'billing_currency' => $billingSnapshot['billing_currency'],
                    'monthly_base_fee' => $billingSnapshot['monthly_base_fee'],
                    'monthly_included_traffic_gb' => $billingSnapshot['monthly_included_traffic_gb'],
                    'traffic_price_per_gb' => $billingSnapshot['traffic_price_per_gb'],
                    'billing_cycle_started_at' => $billingSnapshot['billing_cycle_started_at'],
                    'billing_cycle_traffic_used_bytes' => $billingSnapshot['billing_cycle_traffic_used_bytes'],
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id', ['id' => $serviceId]);
                $this->audit('service.created', [
                    'service_id' => $serviceId,
                    'whmcs_service_id' => $whmcsServiceId,
                    'source' => 'whmcs',
                ]);
            } else {
                $existingServerIp = trim((string) ($service['server_public_ip'] ?? ''));
                $effectiveServerIp = $serverPublicIp !== '' ? $serverPublicIp : $existingServerIp;
                $effectiveIpState = $serverPublicIp !== '' ? 'fresh' : ($effectiveServerIp !== '' ? 'stale' : 'missing');
                $this->db->execute(
                    'UPDATE services
                     SET user_id = :user_id,
                         plan_id = :plan_id,
                         name = :name,
                         server_hostname = :server_hostname,
                         server_public_ip = :server_public_ip,
                         server_ip_state = :server_ip_state,
                         server_ip_last_synced_at = :server_ip_last_synced_at,
                        status = :status,
                        expire_at = :expire_at,
                        bandwidth_limit_mbps = :bandwidth_limit_mbps,
                        traffic_limit_gb = :traffic_limit_gb,
                        billing_mode = :billing_mode,
                        billing_currency = :billing_currency,
                        monthly_base_fee = :monthly_base_fee,
                        monthly_included_traffic_gb = :monthly_included_traffic_gb,
                        traffic_price_per_gb = :traffic_price_per_gb,
                        billing_cycle_started_at = :billing_cycle_started_at,
                        billing_cycle_traffic_used_bytes = :billing_cycle_traffic_used_bytes,
                        updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => $service['id'],
                        'user_id' => $user['id'],
                        'plan_id' => $plan['id'],
                        'name' => $serviceName,
                        'server_hostname' => $serverHostname !== '' ? $serverHostname : ($service['server_hostname'] ?? null),
                        'server_public_ip' => $effectiveServerIp !== '' ? $effectiveServerIp : null,
                        'server_ip_state' => $effectiveIpState,
                        'server_ip_last_synced_at' => $this->now(),
                        'status' => $status,
                        'expire_at' => $expireAt,
                        'bandwidth_limit_mbps' => $bandwidthLimit,
                        'traffic_limit_gb' => $trafficLimit,
                        'billing_mode' => $billingSnapshot['billing_mode'],
                        'billing_currency' => $billingSnapshot['billing_currency'],
                        'monthly_base_fee' => $billingSnapshot['monthly_base_fee'],
                        'monthly_included_traffic_gb' => $billingSnapshot['monthly_included_traffic_gb'],
                        'traffic_price_per_gb' => $billingSnapshot['traffic_price_per_gb'],
                        'billing_cycle_started_at' => $billingSnapshot['billing_cycle_started_at'],
                        'billing_cycle_traffic_used_bytes' => $billingSnapshot['billing_cycle_traffic_used_bytes'],
                        'updated_at' => $this->now(),
                    ]
                );
            }

            $serviceId = (int) $service['id'];
            foreach ($forwards as $forwardPayload) {
                if (!is_array($forwardPayload)) {
                    continue;
                }
                $forwardPayload['target_service_id'] = (int) ($forwardPayload['target_service_id'] ?? 0) > 0
                    ? (int) $forwardPayload['target_service_id']
                    : $serviceId;
                $forwardPayload['target_source'] = trim((string) ($forwardPayload['target_source'] ?? '')) !== ''
                    ? (string) $forwardPayload['target_source']
                    : 'manual';
                $this->createForward($serviceId, $forwardPayload, 'whmcs');
            }

            $this->republishActiveForwardsForService($serviceId);

            return $this->serviceSummary($serviceId);
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function renewService(array $payload): array
    {
        $service = $this->getServiceByWhmcsId((int) $this->requiredString($payload, 'whmcs_service_id'));
        $this->db->execute(
            'UPDATE services
             SET status = :status,
                 expire_at = :expire_at,
                 suspend_reason = NULL,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $service['id'],
                'status' => 'active',
                'expire_at' => (string) ($payload['expire_at'] ?? gmdate('Y-m-d H:i:s', strtotime('+30 days'))),
                'updated_at' => $this->now(),
            ]
        );
        $this->queueLifecycleTasks((int) $service['id'], 'resume');
        $this->audit('service.renewed', ['service_id' => $service['id'], 'source' => 'whmcs']);
        return $this->serviceSummary((int) $service['id']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function suspendService(array $payload): array
    {
        $service = $this->getServiceByWhmcsId((int) $this->requiredString($payload, 'whmcs_service_id'));
        $reason = (string) ($payload['reason'] ?? 'billing');
        $this->db->execute(
            'UPDATE services
             SET status = :status,
                 suspend_reason = :reason,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $service['id'],
                'status' => 'suspended',
                'reason' => $reason,
                'updated_at' => $this->now(),
            ]
        );
        $this->queueLifecycleTasks((int) $service['id'], 'suspend');
        $this->audit('service.suspended', ['service_id' => $service['id'], 'reason' => $reason, 'source' => 'whmcs']);
        return $this->serviceSummary((int) $service['id']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function terminateService(array $payload): array
    {
        $service = $this->getServiceByWhmcsId((int) $this->requiredString($payload, 'whmcs_service_id'));
        $this->db->execute(
            'UPDATE services SET status = :status, updated_at = :updated_at WHERE id = :id',
            [
                'id' => $service['id'],
                'status' => 'terminated',
                'updated_at' => $this->now(),
            ]
        );
        $this->queueLifecycleTasks((int) $service['id'], 'destroy');
        $this->audit('service.terminated', ['service_id' => $service['id'], 'source' => 'whmcs']);
        return $this->serviceSummary((int) $service['id']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createForward(
        int $serviceId,
        array $payload,
        string $actor = 'user',
        ?string $idempotencyKeyHash = null,
        ?string $requestHash = null,
        ?int $activeForwardLimit = null,
        ?array $mutationPolicy = null
    ): array
    {
        return $this->db->transaction(function () use ($serviceId, $payload, $actor, $idempotencyKeyHash, $requestHash, $activeForwardLimit, $mutationPolicy): array {
            $this->lockForwardMutationService($serviceId, $mutationPolicy, 'service_not_found');

            if (is_string($idempotencyKeyHash) && $idempotencyKeyHash !== '') {
                $existing = $this->db->fetchOne(
                    'SELECT id, enabled, api_request_hash
                     FROM forwards
                     WHERE service_id = :service_id
                       AND api_idempotency_key_hash = :key_hash
                     LIMIT 1',
                    ['service_id' => $serviceId, 'key_hash' => $idempotencyKeyHash]
                );
                if ($existing !== null) {
                    if (!is_string($requestHash)
                        || !hash_equals((string) ($existing['api_request_hash'] ?? ''), $requestHash)) {
                        throw new ApiException('idempotency_conflict', 409);
                    }
                    if ((int) ($existing['enabled'] ?? 0) !== 1) {
                        throw new ApiException('idempotency_resource_inactive', 409);
                    }
                    return ['forward_id' => (int) $existing['id'], 'idempotent_replay' => true];
                }
            }

            if ($activeForwardLimit !== null) {
                $limit = max(1, min(100000, $activeForwardLimit));
                $count = $this->db->fetchOne(
                    'SELECT COUNT(*) AS active_count
                     FROM forwards
                     WHERE service_id = :service_id AND enabled = 1',
                    ['service_id' => $serviceId]
                );
                if ((int) ($count['active_count'] ?? 0) >= $limit) {
                    throw new ApiException('forward_quota_exceeded', 409);
                }
            }

            if ($mutationPolicy !== null) {
                $storedLimit = max(
                    1,
                    $activeForwardLimit ?? 0,
                    min(1000000, $this->config->getInt('USER_FORWARD_STORED_LIMIT_PER_SERVICE', 512))
                );
                $stored = $this->db->fetchOne(
                    'SELECT COUNT(*) AS stored_count
                     FROM forwards
                     WHERE service_id = :service_id',
                    ['service_id' => $serviceId]
                );
                if ((int) ($stored['stored_count'] ?? 0) >= $storedLimit) {
                    throw new ApiException('forward_storage_quota_exceeded', 409);
                }
            }

            return $this->saveForward($serviceId, null, $payload, $actor, $idempotencyKeyHash, $requestHash);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listNodeGroups(): array
    {
        $descriptionSelect = $this->nodeGroupDescriptionColumnAvailable()
            ? 'g.description'
            : 'NULL AS description';
        $ingressSelect = $this->nodeGroupIngressSelectSql();
        $groupBy = 'g.id, g.group_key, g.name, g.created_at, g.updated_at';
        if ($this->nodeGroupDescriptionColumnAvailable()) {
            $groupBy .= ', g.description';
        }
        if ($this->nodeGroupIngressColumnsAvailable()) {
            $groupBy .= ', g.ingress_cdn_host, g.ingress_sni_host, g.ingress_path_strategy, g.ingress_port_strategy, g.ingress_preferred_public_port';
        }

        return $this->db->fetchAll(
            'SELECT g.id, g.group_key, g.name, ' . $descriptionSelect . ',
                    ' . $ingressSelect . ',
                    g.created_at, g.updated_at, COUNT(n.id) AS node_count
             FROM node_groups g
             LEFT JOIN nodes n ON n.group_id = g.id AND (n.status = :active OR n.status = :degraded)
             GROUP BY ' . $groupBy . '
             ORDER BY g.name ASC, g.id ASC',
            [
                'active' => 'active',
                'degraded' => 'degraded',
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCfLocationGroups(): array
    {
        $groups = $this->db->fetchAll(
            'SELECT * FROM cf_location_groups ORDER BY region_code ASC, name ASC, id ASC'
        );
        foreach ($groups as &$group) {
            $group['cidrs'] = $this->db->fetchAll(
                'SELECT * FROM cf_location_group_cidrs WHERE group_id = :group_id ORDER BY priority ASC, id ASC',
                ['group_id' => (int) ($group['id'] ?? 0)]
            );
        }
        unset($group);
        return $groups;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveCfLocationGroup(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $regionCode = trim((string) ($payload['region_code'] ?? ''));
        $probeMode = strtolower(trim((string) ($payload['probe_mode'] ?? 'auto')));
        $cacheTtlSeconds = max(30, (int) ($payload['cache_ttl_seconds'] ?? 120));
        $switchImprovementMs = max(1, (int) ($payload['switch_improvement_ms'] ?? 15));
        $maxCandidates = max(1, min(256, (int) ($payload['max_candidates'] ?? 32)));
        $isActive = !empty($payload['is_active']) ? 1 : 0;
        $cidrLines = preg_split('/\r\n|\r|\n/', (string) ($payload['cidr_lines'] ?? '')) ?: [];
        $cidrs = array_values(array_unique(array_filter(array_map('trim', $cidrLines), static fn(string $line): bool => $line !== '')));

        if ($name === '' || $regionCode === '' || $cidrs === []) {
            throw new ApiException('invalid_cf_location_group_payload', 422, ['payload' => $payload]);
        }
        if (!in_array($probeMode, ['auto', 'tcp', 'icmp'], true)) {
            throw new ApiException('invalid_cf_location_group_probe_mode', 422, ['probe_mode' => $probeMode]);
        }

        return $this->db->transaction(function () use ($id, $name, $regionCode, $probeMode, $cacheTtlSeconds, $switchImprovementMs, $maxCandidates, $isActive, $cidrs): array {
            if ($id > 0) {
                $this->db->execute(
                    'UPDATE cf_location_groups
                     SET name = :name,
                         region_code = :region_code,
                         probe_mode = :probe_mode,
                         cache_ttl_seconds = :cache_ttl_seconds,
                         switch_improvement_ms = :switch_improvement_ms,
                         max_candidates = :max_candidates,
                         is_active = :is_active,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => $id,
                        'name' => $name,
                        'region_code' => $regionCode,
                        'probe_mode' => $probeMode,
                        'cache_ttl_seconds' => $cacheTtlSeconds,
                        'switch_improvement_ms' => $switchImprovementMs,
                        'max_candidates' => $maxCandidates,
                        'is_active' => $isActive,
                        'updated_at' => $this->now(),
                    ]
                );
            } else {
                $id = $this->db->insert('cf_location_groups', [
                    'name' => $name,
                    'region_code' => $regionCode,
                    'probe_mode' => $probeMode,
                    'cache_ttl_seconds' => $cacheTtlSeconds,
                    'switch_improvement_ms' => $switchImprovementMs,
                    'max_candidates' => $maxCandidates,
                    'is_active' => $isActive,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
            }

            $this->db->execute('DELETE FROM cf_location_group_cidrs WHERE group_id = :group_id', ['group_id' => $id]);
            $priority = 10;
            foreach ($cidrs as $cidr) {
                $this->db->insert('cf_location_group_cidrs', [
                    'group_id' => $id,
                    'cidr' => $cidr,
                    'priority' => $priority,
                    'is_active' => 1,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $priority += 10;
            }

            $row = $this->db->fetchOne('SELECT * FROM cf_location_groups WHERE id = :id LIMIT 1', ['id' => $id]) ?? [];
            $row['cidrs'] = $this->db->fetchAll(
                'SELECT * FROM cf_location_group_cidrs WHERE group_id = :group_id ORDER BY priority ASC, id ASC',
                ['group_id' => $id]
            );
            return $row;
        });
    }

    private function defaultMaxLegsUsedForCombination(string $clientMode, string $runtimePreference, string $backendMode): int
    {
        $hybrid = ['bond', 'mptcp_preferred_bond_fallback'];
        if (in_array($clientMode, ['bond'], true)) {
            return self::DEFAULT_MAX_LEGS_USED;
        }
        if (in_array($runtimePreference, $hybrid, true)) {
            return self::DEFAULT_MAX_LEGS_USED;
        }
        if ($backendMode === 'leigod_mode' && $clientMode === 'mptcp') {
            return self::DEFAULT_MAX_LEGS_USED;
        }
        if ($backendMode === 'cf_mode') {
            return self::DEFAULT_MAX_LEGS_USED;
        }
        return 0;
    }

    private function combinationUsesAggregation(string $clientMode, string $runtimePreference): bool
    {
        return in_array($this->normalizeClientMode($clientMode), ['bond', 'mptcp'], true)
            || trim($runtimePreference) === 'mptcp_preferred_bond_fallback';
    }

    private function sidecarRequiredForRuntime(string $backendMode, string $clientMode, string $runtimePreference): bool
    {
        return $backendMode === 'cf_mode' || $this->combinationUsesAggregation($clientMode, $runtimePreference);
    }

    /**
     * @param array<string, mixed> $forward
     */
    public function usesSingleRaceSidecarFrontend(array $forward): bool
    {
        $clientMode = $this->normalizeClientMode((string) ($forward['client_mode'] ?? 'single'));
        if (!in_array($clientMode, ['single', 'race'], true)) {
            return false;
        }
        if (strtolower(trim((string) ($forward['backend_mode'] ?? 'current_mode'))) !== 'current_mode') {
            return false;
        }
        $pointId = (int) ($forward['bond_sidecar_point_id'] ?? $forward['sidecar_point_id'] ?? 0);
        if ($pointId <= 0) {
            return false;
        }
        $pointConfigSource = $forward['bond_sidecar_point_agent_config_json']
            ?? $forward['sidecar_agent_config_json']
            ?? null;
        if ($pointConfigSource === null || $pointConfigSource === '') {
            $point = $this->sidecarPointById($pointId);
            $pointConfigSource = is_array($point) ? ($point['agent_config_json'] ?? null) : null;
        }
        $pointConfig = $this->decodeJsonAssoc($pointConfigSource);
        return $this->configValueEnabled($pointConfig['single_race_via_sidecar'] ?? false);
    }

    /**
     * @param array<string, mixed> $forward
     */
    private function usesFixedEndpointLeigodBond(array $forward): bool
    {
        if (strtolower(trim((string) ($forward['backend_mode'] ?? 'current_mode'))) !== 'leigod_mode') {
            return false;
        }
        if ($this->normalizeClientMode((string) ($forward['client_mode'] ?? 'single')) !== 'bond') {
            return false;
        }
        $pointId = (int) ($forward['bond_sidecar_point_id'] ?? $forward['sidecar_point_id'] ?? 0);
        if ($pointId <= 0) {
            return false;
        }
        $pointConfigSource = $forward['bond_sidecar_point_agent_config_json']
            ?? $forward['sidecar_agent_config_json']
            ?? null;
        if ($pointConfigSource === null || $pointConfigSource === '') {
            $point = $this->sidecarPointById($pointId);
            $pointConfigSource = is_array($point) ? ($point['agent_config_json'] ?? null) : null;
        }
        $pointConfig = $this->decodeJsonAssoc($pointConfigSource);
        if (!$this->configValueEnabled($pointConfig['fixed_endpoint_leigod_bond'] ?? false)) {
            return false;
        }
        return $this->specificNodeIdFromCustomEndpointIds($forward['custom_endpoint_ids_json'] ?? null) !== null;
    }

    /**
     * @param array<string, mixed> $forward
     */
    private function usesServerSideLeigodBond(array $forward): bool
    {
        if (!$this->usesFixedEndpointLeigodBond($forward)) {
            return false;
        }
        $pointConfigSource = $forward['bond_sidecar_point_agent_config_json']
            ?? $forward['sidecar_agent_config_json']
            ?? null;
        if ($pointConfigSource === null || $pointConfigSource === '') {
            $pointId = (int) ($forward['bond_sidecar_point_id'] ?? $forward['sidecar_point_id'] ?? 0);
            $point = $pointId > 0 ? $this->sidecarPointById($pointId) : null;
            $pointConfigSource = is_array($point) ? ($point['agent_config_json'] ?? null) : null;
        }
        $pointConfig = $this->decodeJsonAssoc($pointConfigSource);
        return $this->configValueEnabled($pointConfig['server_side_leigod_bond'] ?? false);
    }

    /** @return array<string, int|float|bool|string> */
    private function serverSideLeigodBondPolicy(): array
    {
        $floatConfig = function (string $key, float $default, float $minimum, float $maximum): float {
            $value = (float) ($this->config->get($key, (string) $default) ?? $default);
            if (!is_finite($value)) {
                return $default;
            }
            return max($minimum, min($maximum, $value));
        };
        return [
            'aggregation_placement' => 'slave_sidecar',
            'adaptive_line_admission' => true,
            'parallel_lanes_per_line' => max(1, min(4, $this->config->getInt('BOND_LEIGOD_PARALLEL_LANES_PER_LINE', 2))),
            'parallel_leg_limit' => max(2, min(8, $this->config->getInt('BOND_LEIGOD_PARALLEL_LEG_LIMIT', 8))),
            'throughput_min_mbps' => $floatConfig('BOND_LEIGOD_THROUGHPUT_MIN_MBPS', 10.0, 0.001, 10000.0),
            'throughput_relative_floor' => $floatConfig('BOND_LEIGOD_THROUGHPUT_RELATIVE_FLOOR', 0.35, 0.001, 1.0),
            'throughput_min_sample_bytes' => max(1, min(64 * 1024 * 1024, $this->config->getInt('BOND_LEIGOD_THROUGHPUT_MIN_SAMPLE_BYTES', 1048576))),
            'throughput_min_sample_ms' => max(1, min(300000, $this->config->getInt('BOND_LEIGOD_THROUGHPUT_MIN_SAMPLE_MS', 1000))),
            'throughput_bad_windows' => max(1, min(100, $this->config->getInt('BOND_LEIGOD_THROUGHPUT_BAD_WINDOWS', 3))),
            'throughput_recovery_windows' => max(1, min(100, $this->config->getInt('BOND_LEIGOD_THROUGHPUT_RECOVERY_WINDOWS', 2))),
            'throughput_quarantine_ms' => max(1, min(86400000, $this->config->getInt('BOND_LEIGOD_THROUGHPUT_QUARANTINE_MS', 60000))),
            'throughput_probe_interval_ms' => max(1, min(86400000, $this->config->getInt('BOND_LEIGOD_THROUGHPUT_PROBE_INTERVAL_MS', 30000))),
            'throughput_probe_bytes' => max(1, min(64 * 1024 * 1024, $this->config->getInt('BOND_LEIGOD_THROUGHPUT_PROBE_BYTES', 1048576))),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function serverSideLeigodParallelCandidates(array $candidates): array
    {
        $policy = $this->serverSideLeigodBondPolicy();
        $lanesPerLine = (int) ($policy['parallel_lanes_per_line'] ?? 1);
        $legLimit = (int) ($policy['parallel_leg_limit'] ?? 8);
        $expanded = array_values($candidates);
        if ($lanesPerLine <= 1 || count($expanded) >= $legLimit) {
            return array_slice($expanded, 0, $legLimit);
        }

        for ($lane = 2; $lane <= $lanesPerLine; $lane++) {
            foreach ($candidates as $candidate) {
                if (count($expanded) >= $legLimit) {
                    break 2;
                }
                $lineId = (int) ($candidate['line_id'] ?? 0);
                if ($lineId <= 0) {
                    continue;
                }
                $parallel = $candidate;
                $parallel['leg_route_id'] = self::LEIGOD_PARALLEL_ROUTE_BASE
                    + ($lineId * self::LEIGOD_PARALLEL_ROUTE_MULTIPLIER)
                    + $lane;
                $expanded[] = $parallel;
            }
        }
        return $expanded;
    }

    /** @return array<string, int|float|bool|string> */
    public function serverSideLeigodBondPolicyForPoint(int $pointId): array
    {
        $point = $pointId > 0 ? $this->sidecarPointById($pointId) : null;
        if (!is_array($point)) {
            return [];
        }
        $pointConfig = $this->decodeJsonAssoc($point['agent_config_json'] ?? null);
        if (
            !$this->configValueEnabled($pointConfig['fixed_endpoint_leigod_bond'] ?? false)
            || !$this->configValueEnabled($pointConfig['server_side_leigod_bond'] ?? false)
        ) {
            return [];
        }
        return $this->serverSideLeigodBondPolicy();
    }

    private function activeFamilyForRuntime(string $backendMode, string $clientMode, string $runtimePreference): string
    {
        if ($this->sidecarRequiredForRuntime($backendMode, $clientMode, $runtimePreference)) {
            return 'sidecar_aggregation';
        }
        if ($backendMode === 'leigod_mode') {
            return 'leigod_only';
        }
        return $this->normalizeClientMode($clientMode);
    }

    private function targetContractForRuntime(string $backendMode, string $clientMode, string $runtimePreference): string
    {
        if ($backendMode === 'leigod_mode' && !$this->combinationUsesAggregation($clientMode, $runtimePreference)) {
            return 'dedicated_forward_node';
        }
        if ($this->sidecarRequiredForRuntime($backendMode, $clientMode, $runtimePreference)) {
            return 'sidecar_aggregation';
        }
        return 'direct_forward_node';
    }

    private function sidecarPointOverseasEligible(array $point): bool
    {
        $config = $this->decodeJsonAssoc($point['agent_config_json'] ?? null);
        $scope = strtolower(trim((string) ($config['aggregation_region_scope'] ?? $config['region_scope'] ?? 'overseas')));
        if (in_array($scope, ['mainland', 'domestic', 'china', 'cn', 'local'], true)) {
            return false;
        }
        $countryCode = strtolower(trim((string) ($config['country_code'] ?? $config['country'] ?? '')));
        return !in_array($countryCode, ['cn', 'china', 'mainland'], true);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveForwardCombination(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $tenantLabel = trim((string) ($payload['tenant_label'] ?? ''));
        $tenantDescription = trim((string) ($payload['tenant_description'] ?? ''));
        $tenantBadge = trim((string) ($payload['tenant_badge'] ?? ''));
        $nodeGroupKey = trim((string) ($payload['node_group_key'] ?? ''));
        $backendMode = trim((string) ($payload['backend_mode'] ?? 'current_mode'));
        $sidecarPointId = (int) ($payload['sidecar_point_id'] ?? 0);
        $cfLocationGroupId = (int) ($payload['cf_location_group_id'] ?? 0);
        $clientMode = $this->normalizeClientMode((string) ($payload['client_mode'] ?? 'single'));
        $runtimePreference = trim((string) ($payload['runtime_preference'] ?? 'single'));
        if ($backendMode === 'leigod_mode' && $clientMode === 'mptcp') {
            $runtimePreference = 'single';
        }
        $allowPrivateSelfLeg = !in_array(strtolower(trim((string) ($payload['allow_private_self_leg'] ?? '0'))), ['0', 'false', 'no', 'off', ''], true);
        $selfLegScope = trim((string) ($payload['self_leg_scope'] ?? 'none')) ?: 'none';
        $selfLegRole = trim((string) ($payload['self_leg_role'] ?? 'none')) ?: 'none';
        $helperGroupCap = max(0, (int) ($payload['helper_group_cap'] ?? 0));
        if ($helperGroupCap <= 0) {
            $helperGroupCap = $this->defaultMaxLegsUsedForCombination($clientMode, $runtimePreference, $backendMode);
        }
        $helperEscalationPolicy = trim((string) ($payload['helper_escalation_policy'] ?? 'disabled')) ?: 'disabled';
        $selfLegCfTransport = strtolower(trim((string) ($payload['self_leg_cf_transport'] ?? 'cf_direct')));
        if (!in_array($selfLegCfTransport, ['cf_direct', 'cf_wg'], true)) {
            $selfLegCfTransport = 'cf_direct';
        }
        if ($backendMode !== 'cf_mode') {
            $selfLegCfTransport = 'cf_direct';
        }
        $desiredLatencyMs = max(0, (int) ($payload['desired_latency_ms'] ?? 0));
        $allowPublicSelfLegDonation = !in_array(strtolower(trim((string) ($payload['allow_public_self_leg_donation'] ?? '0'))), ['0', 'false', 'no', 'off', ''], true) ? 1 : 0;
        $donationQualifyWindowSeconds = max(5, (int) ($payload['donation_qualify_window_seconds'] ?? 60));
        $donationRevokeWindowSeconds = max(5, (int) ($payload['donation_revoke_window_seconds'] ?? 30));
        $donationMinOpenPorts = max(0, (int) ($payload['donation_min_open_ports'] ?? 1));
        if (!in_array($clientMode, ['bond', 'mptcp'], true)) {
            $allowPublicSelfLegDonation = 0;
        }
        $bondIngressMode = $this->normalizeBondIngressMode((string) ($payload['bond_ingress_mode'] ?? 'plain_tcp_mux'));
        $isActive = !empty($payload['is_active']) ? 1 : 0;
        $sortOrder = (int) ($payload['sort_order'] ?? 100);
        $notes = trim((string) ($payload['notes'] ?? ''));
        $helperNodeGroupKeys = $this->normalizeForwardCombinationHelperGroupKeys($payload);

        // LeiGod self-leg basis (slave<->sidecar WG outer transport). Validate BEFORE the generic
        // gate so a leigod combo missing its line/sidecar reports a leigod-specific 422 (and so the
        // sidecar-required message surfaces ahead of invalid_forward_combination_payload).
        // Forward Mode derives the slave<->sidecar self-leg basis; the standalone picker is retired.
        //   leigod_mode  -> leigod self-leg (DIRECT ingress like current_mode, all CF coupling cleared)
        //   cf_mode      -> cf   (a realization no-op vs cnix -- staticSidecarCombinationUsesCf keys off
        //                         backend_mode, not this column -- but keeps the stored column honest)
        //   current_mode -> cnix (the direct CNIX self-leg)
        // leigod_uot_impl was previously never persisted by saveForwardCombination; read+default it here
        // (gost) and let leigod_mode override to the CPU-efficient lguot carrier.
        $leigodUotImpl = strtolower(trim((string) ($payload['leigod_uot_impl'] ?? 'gost'))) === 'lguot' ? 'lguot' : 'gost';
        $selfLegOuterTransport = strtolower(trim((string) ($payload['self_leg_outer_transport'] ?? 'cnix'))) ?: 'cnix';
        if ($backendMode === 'leigod_mode') {
            $selfLegOuterTransport = 'leigod';
            $allowPrivateSelfLeg = false;       // suppress the self-leg CF primary leg (publishedPrivateSelfLegCandidates returns null)
            $cfLocationGroupId = 0;             // direct ingress, no CF
            $selfLegCfTransport = 'cf_direct';  // belt-and-suspenders (also coerced when backend_mode!=cf_mode)
            $leigodUotImpl = 'lguot';           // leigod_mode default carrier
        } elseif ($backendMode === 'cf_mode') {
            $selfLegOuterTransport = 'cf';
        } else {
            $selfLegOuterTransport = 'cnix';
        }
        if (!in_array($selfLegOuterTransport, ['cnix', 'cf', 'leigod'], true)) {
            throw new ApiException('invalid_self_leg_outer_transport', 422, ['self_leg_outer_transport' => $selfLegOuterTransport]);
        }
        $sidecarRequired = $this->sidecarRequiredForRuntime($backendMode, $clientMode, $runtimePreference);
        $leigodLineId = max(0, (int) ($payload['leigod_line_id'] ?? 0));
        $membershipPayloadProvided = array_key_exists('leigod_lines', $payload);
        if ($membershipPayloadProvided && !is_array($payload['leigod_lines'])) {
            throw new ApiException('invalid_leigod_line_memberships', 422);
        }
        $catalogMembershipsSupported = method_exists($this->db, 'transaction');
        $normalizedLeigodMemberships = null;
        $legacyScalarMembership = false;
        $membershipRequireReady = false;

        if ($selfLegOuterTransport === 'leigod') {
            if ($catalogMembershipsSupported && $membershipPayloadProvided) {
                $membershipRequireReady = $isActive === 1 && !$sidecarRequired;
                $normalizedLeigodMemberships = $this->leigodLineCatalog()->normalizeMemberships(
                    $payload['leigod_lines'],
                    $membershipRequireReady
                );
                $firstEnabledMembership = null;
                foreach ($normalizedLeigodMemberships as $membership) {
                    if ((int) ($membership['enabled'] ?? 0) === 1) {
                        $firstEnabledMembership = $membership;
                        break;
                    }
                }
                $leigodLineId = (int) ($firstEnabledMembership['line_id'] ?? 0);
            } elseif ($catalogMembershipsSupported && $leigodLineId > 0) {
                if ($leigodLineId > 45535) {
                    throw new ApiException('invalid_leigod_line_id', 422, ['line_id' => $leigodLineId]);
                }
                $legacyScalarMembership = true;
            }
            if ($leigodLineId <= 0) {
                throw new ApiException('leigod_line_required', 422, []);
            }
            if ($sidecarRequired && $sidecarPointId <= 0) {
                throw new ApiException('sidecar_required_for_leigod_aggregation', 422, []);
            }
        } else {
            $leigodLineId = 0;
            if ($catalogMembershipsSupported) {
                $normalizedLeigodMemberships = [];
            }
        }
        if ($sidecarRequired && $sidecarPointId <= 0) {
            throw new ApiException('sidecar_required_for_forward_aggregation', 422, ['client_mode' => $clientMode]);
        }
        if (!$sidecarRequired) {
            $sidecarPointId = 0;
        }

        if ($name === '' || $tenantLabel === '' || $nodeGroupKey === '') {
            throw new ApiException('invalid_forward_combination_payload', 422, ['payload' => $payload]);
        }
        if (!in_array($backendMode, ['current_mode', 'cf_mode', 'leigod_mode'], true)) {
            throw new ApiException('invalid_forward_combination_backend_mode', 422, ['backend_mode' => $backendMode]);
        }
        if (!in_array($runtimePreference, ['single', 'mptcp_preferred_bond_fallback'], true)) {
            throw new ApiException('invalid_forward_combination_runtime_preference', 422, ['runtime_preference' => $runtimePreference]);
        }
        if (!in_array($clientMode, ['single', 'bond', 'mptcp'], true)) {
            throw new ApiException('invalid_forward_combination_client_mode', 422, ['client_mode' => $clientMode]);
        }
        if ($backendMode === 'cf_mode' && $cfLocationGroupId <= 0) {
            throw new ApiException('cf_location_group_required', 422, ['payload' => $payload]);
        }
        if ($backendMode !== 'cf_mode') {
            $cfLocationGroupId = 0;
        }
        if ($sidecarRequired && $sidecarPointId > 0) {
            $sidecarPoint = $this->sidecarPointById($sidecarPointId);
            if ($sidecarPoint === null || (int) ($sidecarPoint['is_active'] ?? 0) !== 1) {
                throw new ApiException('sidecar_point_not_allowed_for_aggregation', 422, ['sidecar_point_id' => $sidecarPointId]);
            }
            if (!$this->sidecarPointOverseasEligible($sidecarPoint)) {
                throw new ApiException('sidecar_must_be_overseas_for_aggregation', 422, ['sidecar_point_id' => $sidecarPointId]);
            }
        }

        // Panel-minted gost SOCKS credentials shared by the sidecar gost server + slave gost client.
        // Mint once and persist so they stay stable across republishes (a rotating secret would churn
        // the gost units every reconcile). On edit, preserve the stored secret when the payload omits it.
        $leigodSocksUser = trim((string) ($payload['leigod_socks_user'] ?? ''));
        $leigodSocksPass = trim((string) ($payload['leigod_socks_pass'] ?? ''));
        if ($selfLegOuterTransport === 'leigod') {
            if ($id > 0 && ($leigodSocksUser === '' || $leigodSocksPass === '')) {
                $existingLeigod = $this->db->fetchOne(
                    'SELECT leigod_socks_user, leigod_socks_pass FROM forward_combinations WHERE id = :id LIMIT 1',
                    ['id' => $id]
                );
                if (is_array($existingLeigod)) {
                    if ($leigodSocksUser === '') {
                        $leigodSocksUser = trim((string) ($existingLeigod['leigod_socks_user'] ?? ''));
                    }
                    if ($leigodSocksPass === '') {
                        $leigodSocksPass = trim((string) ($existingLeigod['leigod_socks_pass'] ?? ''));
                    }
                }
            }
            if ($leigodSocksUser === '') {
                $leigodSocksUser = 'wguot';
            }
            if ($leigodSocksPass === '') {
                $leigodSocksPass = rtrim(strtr(base64_encode(random_bytes(21)), '+/', '-_'), '=');
            }
        } else {
            $leigodSocksUser = '';
            $leigodSocksPass = '';
        }

        $normalized = [
            'name' => $name,
            'tenant_label' => $tenantLabel,
            'tenant_description' => $tenantDescription !== '' ? $tenantDescription : null,
            'tenant_badge' => $tenantBadge !== '' ? $tenantBadge : null,
            'node_group_key' => $nodeGroupKey,
            'backend_mode' => $backendMode,
            'sidecar_point_id' => $sidecarPointId > 0 ? $sidecarPointId : null,
            'cf_location_group_id' => $cfLocationGroupId > 0 ? $cfLocationGroupId : null,
            'client_mode' => $clientMode,
            'runtime_preference' => $runtimePreference,
            'allow_private_self_leg' => $allowPrivateSelfLeg ? 1 : 0,
            'self_leg_scope' => $selfLegScope,
            'self_leg_role' => $selfLegRole,
            'helper_group_cap' => $helperGroupCap,
            'helper_escalation_policy' => $helperEscalationPolicy,
            'self_leg_cf_transport' => $selfLegCfTransport,
            'self_leg_outer_transport' => $selfLegOuterTransport,
            'leigod_line_id' => $leigodLineId > 0 ? $leigodLineId : null,
            'leigod_uot_impl' => $leigodUotImpl,
            'leigod_socks_user' => $leigodSocksUser !== '' ? $leigodSocksUser : null,
            'leigod_socks_pass' => $leigodSocksPass !== '' ? $leigodSocksPass : null,
            'desired_latency_ms' => $desiredLatencyMs,
            'allow_public_self_leg_donation' => $allowPublicSelfLegDonation,
            'donation_qualify_window_seconds' => $donationQualifyWindowSeconds,
            'donation_revoke_window_seconds' => $donationRevokeWindowSeconds,
            'donation_min_open_ports' => $donationMinOpenPorts,
            'bond_ingress_mode' => $bondIngressMode,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
            'notes' => $notes !== '' ? $notes : null,
            'updated_at' => $this->now(),
        ];

        $id = (int) $this->transactionIfSupported(function (mixed $_db = null) use (
            $id,
            $normalized,
            $helperNodeGroupKeys,
            $catalogMembershipsSupported,
            $legacyScalarMembership,
            $leigodLineId,
            $normalizedLeigodMemberships,
            $membershipRequireReady,
            $backendMode,
            $clientMode,
            $sidecarPointId,
            $cfLocationGroupId
        ): int {
            $savedId = $id;
            $membershipPayload = is_array($normalizedLeigodMemberships) ? $normalizedLeigodMemberships : [];
            if ($catalogMembershipsSupported && $legacyScalarMembership) {
                $membershipPayload = $this->legacyLeigodMembershipPayload($leigodLineId);
            }

            if ($savedId > 0) {
                $this->db->execute(
                    'UPDATE forward_combinations
                     SET name = :name,
                         tenant_label = :tenant_label,
                         tenant_description = :tenant_description,
                         tenant_badge = :tenant_badge,
                         node_group_key = :node_group_key,
                         backend_mode = :backend_mode,
                         sidecar_point_id = :sidecar_point_id,
                         cf_location_group_id = :cf_location_group_id,
                         client_mode = :client_mode,
                         runtime_preference = :runtime_preference,
                         allow_private_self_leg = :allow_private_self_leg,
                         self_leg_scope = :self_leg_scope,
                         self_leg_role = :self_leg_role,
                         helper_group_cap = :helper_group_cap,
                         helper_escalation_policy = :helper_escalation_policy,
                         self_leg_cf_transport = :self_leg_cf_transport,
                         self_leg_outer_transport = :self_leg_outer_transport,
                         leigod_line_id = :leigod_line_id,
                         leigod_uot_impl = :leigod_uot_impl,
                         leigod_socks_user = :leigod_socks_user,
                         leigod_socks_pass = :leigod_socks_pass,
                         desired_latency_ms = :desired_latency_ms,
                         allow_public_self_leg_donation = :allow_public_self_leg_donation,
                         donation_qualify_window_seconds = :donation_qualify_window_seconds,
                         donation_revoke_window_seconds = :donation_revoke_window_seconds,
                         donation_min_open_ports = :donation_min_open_ports,
                         bond_ingress_mode = :bond_ingress_mode,
                         is_active = :is_active,
                         sort_order = :sort_order,
                         notes = :notes,
                         updated_at = :updated_at
                     WHERE id = :id',
                    $normalized + ['id' => $savedId]
                );
            } else {
                $savedId = $this->db->insert('forward_combinations', $normalized + [
                    'created_at' => $this->now(),
                ]);
            }

            $this->db->execute('DELETE FROM forward_combination_helper_groups WHERE forward_combination_id = :forward_combination_id', [
                'forward_combination_id' => $savedId,
            ]);
            foreach ($helperNodeGroupKeys as $helperSortOrder => $helperNodeGroupKey) {
                $this->db->insert('forward_combination_helper_groups', [
                    'forward_combination_id' => $savedId,
                    'node_group_key' => $helperNodeGroupKey,
                    'sort_order' => $helperSortOrder,
                    'is_active' => 1,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
            }

            if ($catalogMembershipsSupported) {
                $this->leigodLineCatalog()->saveCombinationMemberships(
                    $savedId,
                    $membershipPayload,
                    $membershipRequireReady
                );
            }

            // Keep denormalized per-forward ingress state coherent with the combination before publish.
            $this->db->execute(
                'UPDATE forwards
                 SET backend_mode = :backend_mode,
                     client_mode = :client_mode,
                     bond_sidecar_point_id = :bond_sidecar_point_id,
                     cf_location_group_id = :cf_location_group_id,
                     updated_at = :updated_at
                 WHERE forward_combination_id = :forward_combination_id',
                [
                    'backend_mode' => $backendMode,
                    'client_mode' => $clientMode,
                    'bond_sidecar_point_id' => $sidecarPointId > 0 ? $sidecarPointId : null,
                    'cf_location_group_id' => $cfLocationGroupId > 0 ? $cfLocationGroupId : null,
                    'updated_at' => $this->now(),
                    'forward_combination_id' => $savedId,
                ]
            );

            return $savedId;
        });

        $this->republishActiveForwardsForCombination($id);

        return $this->forwardCombinationById($id) ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function planUsesForwardCombinations(int $planId): bool
    {
        if ($planId <= 0) {
            return false;
        }

        return $this->db->fetchOne(
            'SELECT id
             FROM service_plan_forward_combinations
             WHERE plan_id = :plan_id
             LIMIT 1',
            ['plan_id' => $planId]
        ) !== null;
    }

    private function planUsesForwardRoutes(int $planId): bool
    {
        if ($planId <= 0) {
            return false;
        }

        return $this->db->fetchOne(
            'SELECT id
             FROM service_plan_forward_routes
             WHERE plan_id = :plan_id
             LIMIT 1',
            ['plan_id' => $planId]
        ) !== null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allowedForwardRoutesForPlan(int $planId): array
    {
        if ($planId <= 0) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT fr.*
             FROM service_plan_forward_routes spfr
             INNER JOIN forward_routes fr ON fr.id = spfr.forward_route_id
             WHERE spfr.plan_id = :plan_id
             ORDER BY fr.sort_order ASC, fr.id ASC',
            ['plan_id' => $planId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allowedForwardCombinationsForPlan(int $planId): array
    {
        if ($planId <= 0) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT fc.*, sp.name AS sidecar_point_name, cg.name AS cf_location_group_name
             FROM service_plan_forward_combinations spfc
             INNER JOIN forward_combinations fc ON fc.id = spfc.forward_combination_id
             LEFT JOIN sidecar_points sp ON sp.id = fc.sidecar_point_id
             LEFT JOIN cf_location_groups cg ON cg.id = fc.cf_location_group_id
             WHERE spfc.plan_id = :plan_id
             ORDER BY fc.sort_order ASC, fc.id ASC',
            ['plan_id' => $planId]
        );
        $rows = $this->attachHelperNodeGroupKeysToForwardCombinations($rows);
        return $this->attachLeigodLinesToForwardCombinations($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function primaryCombinationIdForRoute(int $routeId): int
    {
        if ($routeId <= 0) {
            return 0;
        }
        $row = $this->db->fetchOne(
            'SELECT frc.forward_combination_id
             FROM forward_route_combinations frc
             INNER JOIN forward_combinations fc ON fc.id = frc.forward_combination_id
             WHERE frc.forward_route_id = :id
               AND frc.enabled = 1
               AND fc.is_active = 1
             ORDER BY frc.sort_order ASC, frc.id ASC LIMIT 1',
            ['id' => $routeId]
        );
        return is_array($row) ? (int) ($row['forward_combination_id'] ?? 0) : 0;
    }

    /**
     * Routes a service may select in the tenant add-forward UI. Route visibility is
     * allowlist-only: a plan without explicit service_plan_forward_routes rows has no routes.
     */
    public function availableForwardRoutesForService(int $serviceId): array
    {
        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id LIMIT 1', ['id' => $serviceId]);
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }
        $planId = (int) ($service['plan_id'] ?? 0);
        $gated = $this->db->fetchAll(
            'SELECT DISTINCT fr.* FROM forward_routes fr
             INNER JOIN service_plan_forward_routes spfr ON spfr.forward_route_id = fr.id
             INNER JOIN forward_route_combinations frc ON frc.forward_route_id = fr.id AND frc.enabled = 1
             INNER JOIN forward_combinations fc ON fc.id = frc.forward_combination_id AND fc.is_active = 1
             WHERE spfr.plan_id = :plan_id AND fr.is_active = 1
             ORDER BY fr.sort_order ASC, fr.id ASC',
            ['plan_id' => $planId]
        );
        $routes = array_values($gated);
        foreach ($routes as &$route) {
            $routeId = (int) ($route['id'] ?? 0);
            if (!$this->forwardRouteUsesDedicatedLeigod($routeId)) {
                $route['availability'] = null;
                $route['healthy_node_count'] = null;
                $route['unavailable_reason'] = null;
                continue;
            }
            $availability = [
                'tcp' => $this->leigodLineCatalog()->routeAvailability($routeId, 'tcp'),
                'udp' => $this->leigodLineCatalog()->routeAvailability($routeId, 'udp'),
                'both' => $this->leigodLineCatalog()->routeAvailability($routeId, 'both'),
            ];
            $route['availability'] = $availability;
            $route['healthy_node_count'] = (int) ($availability['tcp']['healthy_node_count'] ?? 0);
            $route['unavailable_reason'] = $availability['tcp']['unavailable_reason'] ?? null;
        }
        unset($route);
        return $routes;
    }

    /**
     * @return array<string, mixed>
     */
    private function visibleForwardRouteForService(int $routeId, int $serviceId): array
    {
        if ($routeId <= 0) {
            throw new ApiException('forward_route_not_allowed', 422, ['forward_route_id' => $routeId]);
        }
        foreach ($this->availableForwardRoutesForService($serviceId) as $route) {
            if ((int) ($route['id'] ?? 0) === $routeId) {
                return $route;
            }
        }
        throw new ApiException('forward_route_not_allowed', 422, ['forward_route_id' => $routeId]);
    }

    private function forwardRouteContainsCombination(int $routeId, int $combinationId): bool
    {
        if ($routeId <= 0 || $combinationId <= 0) {
            return false;
        }
        return $this->db->fetchOne(
            'SELECT id FROM forward_route_combinations
             WHERE forward_route_id = :forward_route_id
               AND forward_combination_id = :forward_combination_id
               AND enabled = 1
             LIMIT 1',
            [
                'forward_route_id' => $routeId,
                'forward_combination_id' => $combinationId,
            ]
        ) !== null;
    }

    public function availableForwardCombinationsForService(int $serviceId): array
    {
        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id LIMIT 1', ['id' => $serviceId]);
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }

        if ($this->planUsesForwardCombinations((int) ($service['plan_id'] ?? 0))) {
            return array_values(array_filter(
                $this->allowedForwardCombinationsForPlan((int) ($service['plan_id'] ?? 0)),
                static fn(array $row): bool => (int) ($row['is_active'] ?? 0) === 1
            ));
        }

        $allowedGroupKeys = array_map(static fn(array $group): string => (string) ($group['group_key'] ?? ''), $this->allowedNodeGroupsForPlan((int) ($service['plan_id'] ?? 0)));
        $allowedSidecarIds = array_map(static fn(array $point): int => (int) ($point['id'] ?? 0), $this->allowedSidecarPointsForPlan((int) ($service['plan_id'] ?? 0)));
        $rows = $this->db->fetchAll(
            'SELECT fc.*, sp.name AS sidecar_point_name, cg.name AS cf_location_group_name
             FROM forward_combinations fc
             LEFT JOIN sidecar_points sp ON sp.id = fc.sidecar_point_id
             LEFT JOIN cf_location_groups cg ON cg.id = fc.cf_location_group_id
             WHERE fc.is_active = 1
             ORDER BY fc.sort_order ASC, fc.id ASC'
        );
        $rows = $this->attachHelperNodeGroupKeysToForwardCombinations($rows);
        $rows = $this->attachLeigodLinesToForwardCombinations($rows);

        return array_values(array_filter($rows, static function (array $row) use ($allowedGroupKeys, $allowedSidecarIds): bool {
            $groupOk = $allowedGroupKeys === [] || in_array((string) ($row['node_group_key'] ?? ''), $allowedGroupKeys, true);
            $rowSidecarId = (int) ($row['sidecar_point_id'] ?? 0);
            $sidecarOk = $allowedSidecarIds === [] || $rowSidecarId <= 0 || in_array($rowSidecarId, $allowedSidecarIds, true);
            return $groupOk && $sidecarOk;
        }));
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForwardCombinations(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT fc.*, sp.name AS sidecar_point_name, cg.name AS cf_location_group_name
             FROM forward_combinations fc
             LEFT JOIN sidecar_points sp ON sp.id = fc.sidecar_point_id
             LEFT JOIN cf_location_groups cg ON cg.id = fc.cf_location_group_id
             ORDER BY fc.sort_order ASC, fc.id ASC'
        );
        $rows = $this->attachHelperNodeGroupKeysToForwardCombinations($rows);
        return $this->attachLeigodLinesToForwardCombinations($rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function forwardCombinationById(int $combinationId): ?array
    {
        if ($combinationId <= 0) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT fc.*, sp.name AS sidecar_point_name, cg.name AS cf_location_group_name
             FROM forward_combinations fc
             LEFT JOIN sidecar_points sp ON sp.id = fc.sidecar_point_id
             LEFT JOIN cf_location_groups cg ON cg.id = fc.cf_location_group_id
             WHERE fc.id = :id LIMIT 1',
            ['id' => $combinationId]
        );

        if (!is_array($row)) {
            return null;
        }
        $rows = $this->attachHelperNodeGroupKeysToForwardCombinations([$row]);
        $rows = $this->attachLeigodLinesToForwardCombinations($rows);
        return $rows[0] ?? null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function attachLeigodLinesToForwardCombinations(array $rows): array
    {
        foreach ($rows as &$row) {
            $combinationId = (int) ($row['id'] ?? 0);
            $row['leigod_lines'] = $combinationId > 0 && method_exists($this->db, 'transaction')
                ? $this->leigodLineCatalog()->membershipsForCombination($combinationId)
                : [];
        }
        unset($row);
        return $rows;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function normalizeForwardCombinationHelperGroupKeys(array $payload): array
    {
        $raw = $payload['helper_node_group_keys'] ?? null;
        if (!is_array($raw)) {
            $rawText = trim((string) ($payload['helper_node_group_keys_text'] ?? ''));
            $raw = $rawText !== '' ? preg_split('/\r\n|\r|\n/', $rawText) : [];
        }
        $keys = [];
        foreach ((array) $raw as $groupKey) {
            $normalized = trim((string) $groupKey);
            if ($normalized === '' || in_array($normalized, $keys, true)) {
                continue;
            }
            $keys[] = $normalized;
        }
        return $keys;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function attachHelperNodeGroupKeysToForwardCombinations(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['helper_node_group_keys'] = $this->forwardCombinationHelperNodeGroupKeys((int) ($row['id'] ?? 0));
        }
        unset($row);

        return $rows;
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    public function candidateNodesForCombination(int $combinationId): array
    {
        if ($combinationId <= 0) {
            return [];
        }
        $combination = $this->forwardCombinationById($combinationId) ?? [];
        $groupKeys = array_values(array_unique(array_filter(array_merge(
            [trim((string) ($combination['node_group_key'] ?? ''))],
            array_map(static fn(mixed $value): string => trim((string) $value), (array) ($combination['helper_node_group_keys'] ?? []))
        ), static fn(string $value): bool => $value !== '')));
        if ($groupKeys === []) {
            return [];
        }

        $params = ['active' => 'active', 'degraded' => 'degraded'];
        $placeholders = [];
        foreach ($groupKeys as $idx => $groupKey) {
            $key = 'group_key_' . $idx;
            $placeholders[] = ':' . $key;
            $params[$key] = $groupKey;
        }
        $freshnessClause = $this->nodeSelectionFreshnessSql($params);
        return $this->db->fetchAll(
            'SELECT n.*, g.group_key AS node_group_key
             FROM nodes n
             LEFT JOIN node_groups g ON g.id = n.group_id
             WHERE (n.status = :active OR n.status = :degraded)
               AND g.group_key IN (' . implode(', ', $placeholders) . ')' . $freshnessClause . '
             ORDER BY g.group_key ASC, n.current_load ASC, n.last_heartbeat_at DESC, n.id ASC',
            $params
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeDonorsForCombination(int $combinationId): array
    {
        if ($combinationId <= 0) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT d.*, c.name AS client_name
             FROM forward_combination_self_leg_donors d
             LEFT JOIN clients c ON c.id = d.client_id
             WHERE d.forward_combination_id = :forward_combination_id
             ORDER BY FIELD(d.state, \'active\', \'qualifying\', \'cooldown\', \'revoked\'), d.last_measured_latency_ms ASC, d.id ASC',
            ['forward_combination_id' => $combinationId]
        );
    }

    /**
     * @param array<int, mixed> $nodeIds
     */
    public function saveCombinationAdminPinnedSlaves(int $combinationId, array $nodeIds): void
    {
        if ($combinationId <= 0) {
            return;
        }
        $normalized = [];
        foreach ($nodeIds as $nodeId) {
            $id = (int) $nodeId;
            if ($id > 0 && !in_array($id, $normalized, true)) {
                $normalized[] = $id;
            }
        }
        $this->db->execute(
            'UPDATE forward_combination_slaves
             SET is_active = :inactive, updated_at = :updated_at
             WHERE forward_combination_id = :forward_combination_id
               AND selection_kind = :selection_kind',
            [
                'inactive' => 0,
                'updated_at' => $this->now(),
                'forward_combination_id' => $combinationId,
                'selection_kind' => 'admin_pin',
            ]
        );
        $sortOrder = 10;
        foreach ($normalized as $nodeId) {
            $existing = $this->db->fetchOne(
                'SELECT id FROM forward_combination_slaves
                 WHERE forward_combination_id = :forward_combination_id
                   AND node_id = :node_id
                   AND selection_kind = :selection_kind
                 LIMIT 1',
                [
                    'forward_combination_id' => $combinationId,
                    'node_id' => $nodeId,
                    'selection_kind' => 'admin_pin',
                ]
            );
            if (is_array($existing)) {
                $this->db->execute(
                    'UPDATE forward_combination_slaves
                     SET is_active = :active, sort_order = :sort_order, updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => (int) ($existing['id'] ?? 0),
                        'active' => 1,
                        'sort_order' => $sortOrder,
                        'updated_at' => $this->now(),
                    ]
                );
            } else {
                $this->db->insert('forward_combination_slaves', [
                    'forward_combination_id' => $combinationId,
                    'node_id' => $nodeId,
                    'selection_kind' => 'admin_pin',
                    'sort_order' => $sortOrder,
                    'is_active' => 1,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
            }
            $sortOrder += 10;
        }
        $this->republishActiveForwardsForCombination($combinationId);
    }

    public function revokeDonationManually(int $combinationId, int $donationId): void
    {
        if ($combinationId <= 0 || $donationId <= 0) {
            return;
        }
        $donor = $this->db->fetchOne(
            'SELECT * FROM forward_combination_self_leg_donors WHERE id = :id AND forward_combination_id = :forward_combination_id LIMIT 1',
            ['id' => $donationId, 'forward_combination_id' => $combinationId]
        );
        if (!is_array($donor)) {
            return;
        }
        $this->transitionDonationState($donor, 'revoked', 'admin_disabled');
    }

    /**
     * @return array<int, string>
     */
    private function forwardCombinationHelperNodeGroupKeys(int $forwardCombinationId): array
    {
        if ($forwardCombinationId <= 0) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn(array $item): string => trim((string) ($item['node_group_key'] ?? '')),
            $this->db->fetchAll(
                'SELECT node_group_key
                 FROM forward_combination_helper_groups
                 WHERE forward_combination_id = :forward_combination_id AND is_active = 1
                 ORDER BY sort_order ASC, id ASC',
                ['forward_combination_id' => $forwardCombinationId]
            )
        ), static fn(string $value): bool => $value !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function visibleForwardCombinationForService(int $combinationId, int $serviceId): array
    {
        $combination = $this->forwardCombinationById($combinationId);
        if ($combination === null || (int) ($combination['is_active'] ?? 0) !== 1) {
            throw new ApiException('forward_combination_not_found', 404, ['forward_combination_id' => $combinationId]);
        }

        $visibleIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $this->availableForwardCombinationsForService($serviceId));
        if (!in_array($combinationId, $visibleIds, true)) {
            throw new ApiException('forward_combination_not_allowed', 422, ['forward_combination_id' => $combinationId]);
        }

        return $combination;
    }

    public function saveNodeGroup(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $groupKey = trim((string) ($payload['group_key'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $ingressCdnHost = trim((string) ($payload['ingress_cdn_host'] ?? ''));
        $ingressSniHost = trim((string) ($payload['ingress_sni_host'] ?? ''));
        $ingressPathStrategy = trim((string) ($payload['ingress_path_strategy'] ?? 'random'));
        $ingressPortStrategy = trim((string) ($payload['ingress_port_strategy'] ?? 'shared_preferred_port'));
        $ingressPreferredPublicPort = (int) ($payload['ingress_preferred_public_port'] ?? 0);

        if ($groupKey === '' || $name === '') {
            throw new ApiException('invalid_node_group_payload', 422, ['payload' => $payload]);
        }
        if (!in_array($ingressPathStrategy, ['random', 'date-random'], true)) {
            throw new ApiException('invalid_node_group_ingress_path_strategy', 422, ['strategy' => $ingressPathStrategy]);
        }
        if (!in_array($ingressPortStrategy, ['shared_preferred_port', 'client_range_then_target', 'target_port_then_client_range'], true)) {
            throw new ApiException('invalid_node_group_ingress_port_strategy', 422, ['strategy' => $ingressPortStrategy]);
        }

        if ($id > 0) {
            $assignments = [
                'group_key = :group_key',
                'name = :name',
                'updated_at = :updated_at',
            ];
            $params = [
                'id' => $id,
                'group_key' => $groupKey,
                'name' => $name,
                'updated_at' => $this->now(),
            ];
            if ($this->nodeGroupDescriptionColumnAvailable()) {
                $assignments[] = 'description = :description';
                $params['description'] = $description !== '' ? $description : null;
            }
            if ($this->nodeGroupIngressColumnsAvailable()) {
                $assignments[] = 'ingress_cdn_host = :ingress_cdn_host';
                $assignments[] = 'ingress_sni_host = :ingress_sni_host';
                $assignments[] = 'ingress_path_strategy = :ingress_path_strategy';
                $assignments[] = 'ingress_port_strategy = :ingress_port_strategy';
                $assignments[] = 'ingress_preferred_public_port = :ingress_preferred_public_port';
                $params['ingress_cdn_host'] = $ingressCdnHost !== '' ? $ingressCdnHost : null;
                $params['ingress_sni_host'] = $ingressSniHost !== '' ? $ingressSniHost : null;
                $params['ingress_path_strategy'] = $ingressPathStrategy;
                $params['ingress_port_strategy'] = $ingressPortStrategy;
                $params['ingress_preferred_public_port'] = $ingressPreferredPublicPort > 0 ? $ingressPreferredPublicPort : null;
            }
            $this->db->execute(
                'UPDATE node_groups SET ' . implode(', ', $assignments) . ' WHERE id = :id',
                $params
            );
        } else {
            $insert = [
                'group_key' => $groupKey,
                'name' => $name,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ];
            if ($this->nodeGroupDescriptionColumnAvailable()) {
                $insert['description'] = $description !== '' ? $description : null;
            }
            if ($this->nodeGroupIngressColumnsAvailable()) {
                $insert['ingress_cdn_host'] = $ingressCdnHost !== '' ? $ingressCdnHost : null;
                $insert['ingress_sni_host'] = $ingressSniHost !== '' ? $ingressSniHost : null;
                $insert['ingress_path_strategy'] = $ingressPathStrategy;
                $insert['ingress_port_strategy'] = $ingressPortStrategy;
                $insert['ingress_preferred_public_port'] = $ingressPreferredPublicPort > 0 ? $ingressPreferredPublicPort : null;
            }
            $id = $this->db->insert('node_groups', $insert);
        }

        $row = $this->db->fetchOne('SELECT * FROM node_groups WHERE id = :id LIMIT 1', ['id' => $id]);
        return $row ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listClientGroups(): array
    {
        return $this->db->fetchAll(
            'SELECT g.*, COUNT(c.id) AS client_count
             FROM client_groups g
             LEFT JOIN clients c ON c.group_id = g.id
             GROUP BY g.id, g.group_key, g.name, g.description, g.created_at, g.updated_at
             ORDER BY g.name ASC, g.id ASC'
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveClientGroup(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $groupKey = trim((string) ($payload['group_key'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));

        if ($groupKey === '' || $name === '') {
            throw new ApiException('invalid_client_group_payload', 422, ['payload' => $payload]);
        }

        if ($id > 0) {
            $this->db->execute(
                'UPDATE client_groups
                 SET group_key = :group_key,
                     name = :name,
                     description = :description,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'id' => $id,
                    'group_key' => $groupKey,
                    'name' => $name,
                    'description' => $description !== '' ? $description : null,
                    'updated_at' => $this->now(),
                ]
            );
        } else {
            $id = $this->db->insert('client_groups', [
                'group_key' => $groupKey,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
        }

        return $this->db->fetchOne('SELECT * FROM client_groups WHERE id = :id LIMIT 1', ['id' => $id]) ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listClients(): array
    {
        return $this->db->fetchAll(
            'SELECT c.*, g.name AS group_name, g.group_key, fc.name AS default_forward_chain_name, u.email AS owner_email, u.name AS owner_name
             FROM clients c
             LEFT JOIN client_groups g ON g.id = c.group_id
             LEFT JOIN forward_chains fc ON fc.id = c.default_forward_chain_id
             LEFT JOIN users u ON u.id = c.owner_user_id
             ORDER BY c.updated_at DESC, c.id DESC'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listClientsForUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT c.*, g.name AS group_name, g.group_key, fc.name AS default_forward_chain_name
             FROM clients c
             LEFT JOIN client_groups g ON g.id = c.group_id
             LEFT JOIN forward_chains fc ON fc.id = c.default_forward_chain_id
             WHERE c.owner_user_id = :user_id
             ORDER BY c.updated_at DESC, c.id DESC',
            ['user_id' => $userId]
        );
    }

    public function maxClientProfilesForTenantOwnedClients(int $userId): int
    {
        $service = $this->db->fetchOne(
            'SELECT s.id, s.plan_id, p.max_client_profiles
             FROM services s
             LEFT JOIN service_plans p ON p.id = s.plan_id
             WHERE s.user_id = :user_id
             ORDER BY s.id ASC
             LIMIT 1',
            ['user_id' => $userId]
        );

        return max(1, (int) ($service['max_client_profiles'] ?? 1));
    }

    public function defaultClientOnlineDeviceLimitForTenant(int $userId): int
    {
        $service = $this->db->fetchOne(
            'SELECT p.default_client_online_device_limit
             FROM services s
             LEFT JOIN service_plans p ON p.id = s.plan_id
             WHERE s.user_id = :user_id
               AND s.status = :status
             ORDER BY s.id ASC
             LIMIT 1',
            [
                'user_id' => $userId,
                'status' => 'active',
            ]
        );

        return max(1, (int) ($service['default_client_online_device_limit'] ?? 1));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function defaultClientForUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT *
             FROM clients
             WHERE owner_user_id = :owner_user_id
               AND is_default_for_owner = 1
             ORDER BY CASE WHEN status = :active THEN 0 ELSE 1 END, id ASC
             LIMIT 1',
            [
                'owner_user_id' => $userId,
                'active' => 'active',
            ]
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureDefaultClientForUser(int $userId): array
    {
        $user = $this->tenantUserById($userId);
        $existingDefault = $this->defaultClientForUser($userId);
        if ($existingDefault !== null) {
            return $existingDefault;
        }

        $owned = $this->db->fetchAll(
            'SELECT *
             FROM clients
             WHERE owner_user_id = :owner_user_id
             ORDER BY CASE WHEN status = :active THEN 0 ELSE 1 END, id ASC',
            [
                'owner_user_id' => $userId,
                'active' => 'active',
            ]
        );

        if ($owned !== []) {
            $clientId = (int) ($owned[0]['id'] ?? 0);
            $this->markClientAsDefaultForUser($userId, $clientId);
            return $this->db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => $clientId]) ?? [];
        }

        return $this->createDefaultClientForUser($userId, $user);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function createDefaultClientForUser(int $userId, array $user): array
    {
        $email = trim((string) ($user['email'] ?? ''));
        $localPart = $email !== '' ? strstr($email, '@', true) : '';
        $localPart = is_string($localPart) ? $localPart : '';
        $displayHostBase = preg_replace('/[^a-z0-9]+/i', '-', $localPart);
        $displayHostBase = trim((string) $displayHostBase, '-');
        if ($displayHostBase === '') {
            $displayHostBase = 'tenant-' . $userId;
        }
        $displayHost = strtolower($displayHostBase . '-' . $userId . '.client.local');

        $clientId = $this->db->insert('clients', [
            'group_id' => null,
            'owner_user_id' => $userId,
            'is_default_for_owner' => 1,
            'agent_token' => bin2hex(random_bytes(24)),
            'name' => 'Default Client',
            'display_host' => $displayHost,
            'online_device_limit' => $this->defaultClientOnlineDeviceLimitForTenant($userId),
            'status' => 'active',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        return $this->db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => $clientId]) ?? [];
    }

    public function markClientAsDefaultForUser(int $userId, int $clientId): void
    {
        if ($userId <= 0 || $clientId <= 0) {
            throw new ApiException('invalid_default_client_target', 422, ['message' => 'Default client target is invalid.']);
        }

        $client = $this->db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => $clientId]);
        if ($client === null || (int) ($client['owner_user_id'] ?? 0) !== $userId) {
            throw new ApiException('default_client_not_owned', 422, ['message' => 'The selected client is not owned by this tenant.']);
        }

        $updatedAt = $this->now();
        $this->db->execute(
            'UPDATE clients
             SET is_default_for_owner = 0,
                 updated_at = :updated_at
             WHERE owner_user_id = :owner_user_id',
            [
                'owner_user_id' => $userId,
                'updated_at' => $updatedAt,
            ]
        );

        $this->db->execute(
            'UPDATE clients
             SET is_default_for_owner = 1,
                 updated_at = :updated_at
             WHERE id = :id
               AND owner_user_id = :owner_user_id',
            [
                'id' => $clientId,
                'owner_user_id' => $userId,
                'updated_at' => $updatedAt,
            ]
        );
    }

    /**
     * @return array{scanned:int,created:int,marked_existing:int,normalized_duplicates:int}
     */
    public function backfillDefaultClientsForUsers(?int $userId = null, bool $apply = false): array
    {
        $tenants = $userId !== null
            ? [$this->tenantUserById($userId)]
            : $this->db->fetchAll('SELECT * FROM users WHERE role = :role ORDER BY id ASC', ['role' => 'tenant']);

        $summary = [
            'scanned' => count($tenants),
            'created' => 0,
            'marked_existing' => 0,
            'normalized_duplicates' => 0,
        ];

        foreach ($tenants as $tenant) {
            $tenantId = (int) ($tenant['id'] ?? 0);
            $owned = $this->db->fetchAll(
                'SELECT *
                 FROM clients
                 WHERE owner_user_id = :owner_user_id
                 ORDER BY CASE WHEN status = :active THEN 0 ELSE 1 END, id ASC',
                [
                    'owner_user_id' => $tenantId,
                    'active' => 'active',
                ]
            );
            $defaults = array_values(array_filter($owned, static fn(array $client): bool => (int) ($client['is_default_for_owner'] ?? 0) === 1));

            if ($owned === []) {
                $summary['created']++;
                if ($apply) {
                    $this->createDefaultClientForUser($tenantId, $tenant);
                }
                continue;
            }

            if ($defaults === []) {
                $summary['marked_existing']++;
                if ($apply) {
                    $this->markClientAsDefaultForUser($tenantId, (int) ($owned[0]['id'] ?? 0));
                }
                continue;
            }

            if (count($defaults) > 1) {
                $summary['normalized_duplicates']++;
                if ($apply) {
                    $this->markClientAsDefaultForUser($tenantId, (int) ($owned[0]['id'] ?? 0));
                }
            }
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSidecarPoints(): array
    {
        return $this->db->fetchAll(
            'SELECT sp.*, u.email AS owner_email, u.name AS owner_name,
                    COUNT(f.id) AS forward_count
             FROM sidecar_points sp
             LEFT JOIN users u ON u.id = sp.owner_user_id
             LEFT JOIN forwards f ON f.bond_sidecar_point_id = sp.id AND f.enabled = 1
             GROUP BY sp.id, sp.owner_user_id, sp.name, sp.sidecar_host, sp.listen_port, sp.bond_listen_port, sp.mptcp_listen_port, sp.cf_listen_port, sp.bind_host, sp.total_speed_limit_mbps, sp.token_secret,
                      sp.agent_token, sp.agent_version, sp.agent_update_request_token, sp.agent_update_requested_at, sp.last_heartbeat_at, sp.last_status, sp.last_error, sp.is_active,
                      sp.created_at, sp.updated_at, u.email, u.name
             ORDER BY sp.updated_at DESC, sp.id DESC'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSidecarPointsForUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT sp.*, u.email AS owner_email, u.name AS owner_name,
                    COUNT(f.id) AS forward_count
             FROM sidecar_points sp
             LEFT JOIN users u ON u.id = sp.owner_user_id
             LEFT JOIN forwards f ON f.bond_sidecar_point_id = sp.id AND f.enabled = 1
             WHERE sp.owner_user_id = :user_id OR sp.owner_user_id IS NULL
             GROUP BY sp.id, sp.owner_user_id, sp.name, sp.sidecar_host, sp.listen_port, sp.bond_listen_port, sp.mptcp_listen_port, sp.cf_listen_port, sp.bind_host, sp.total_speed_limit_mbps, sp.token_secret,
                      sp.agent_token, sp.agent_version, sp.agent_update_request_token, sp.agent_update_requested_at, sp.last_heartbeat_at, sp.last_status, sp.last_error, sp.is_active,
                      sp.created_at, sp.updated_at, u.email, u.name
             ORDER BY sp.owner_user_id IS NULL DESC, sp.updated_at DESC, sp.id DESC',
            ['user_id' => $userId]
        );
    }

    /**
     * Resolve the traffic price for a resource. Node-level price takes priority; falls back to group-level.
     * Returns null if no pricing is configured (free / user-owned node).
     *
     * @return array{price_per_gb: string, currency: string}|null
     */
    public function resolveTrafficPrice(int $resourceId, string $resourceType): ?array
    {
        // 1. Check node-level price
        $nodePrice = $this->db->fetchOne(
            'SELECT price_per_gb, currency FROM traffic_prices
             WHERE node_id = :node_id AND resource_type = :resource_type LIMIT 1',
            ['node_id' => $resourceId, 'resource_type' => $resourceType]
        );
        if ($nodePrice !== null) {
            return $nodePrice;
        }

        // 2. For slaves, fall back to group-level price
        if ($resourceType === 'slave') {
            $node = $this->db->fetchOne(
                'SELECT group_id FROM nodes WHERE id = :id LIMIT 1',
                ['id' => $resourceId]
            );
            if ($node !== null && (int) ($node['group_id'] ?? 0) > 0) {
                $groupPrice = $this->db->fetchOne(
                    'SELECT price_per_gb, currency FROM traffic_prices
                     WHERE node_group_id = :group_id AND resource_type = :resource_type LIMIT 1',
                    ['group_id' => (int) $node['group_id'], 'resource_type' => $resourceType]
                );
                if ($groupPrice !== null) {
                    return $groupPrice;
                }
            }
        }

        // 3. No price configured
        return null;
    }

    /**
     * Insert or update a traffic price row. Enforces application-level uniqueness.
     */
    public function upsertTrafficPrice(string $resourceType, ?int $nodeId, ?int $nodeGroupId, string $pricePerGb, string $currency = 'CNY'): void
    {
        if ($nodeId !== null) {
            $existing = $this->db->fetchOne(
                'SELECT id FROM traffic_prices WHERE resource_type = :type AND node_id = :node_id LIMIT 1',
                ['type' => $resourceType, 'node_id' => $nodeId]
            );
        } else {
            $existing = $this->db->fetchOne(
                'SELECT id FROM traffic_prices WHERE resource_type = :type AND node_group_id = :group_id LIMIT 1',
                ['type' => $resourceType, 'group_id' => $nodeGroupId]
            );
        }

        $price = number_format((float) $pricePerGb, 4, '.', '');

        if ($existing !== null) {
            $this->db->execute(
                'UPDATE traffic_prices SET price_per_gb = :price, currency = :currency WHERE id = :id',
                ['price' => $price, 'currency' => $currency, 'id' => (int) $existing['id']]
            );
        } elseif ((float) $price > 0) {
            $this->db->insert('traffic_prices', [
                'resource_type' => $resourceType,
                'node_id' => $nodeId,
                'node_group_id' => $nodeGroupId,
                'price_per_gb' => $price,
                'currency' => $currency,
            ]);
        }
    }

    /**
     * Get the traffic price row for a specific node or group.
     * @return array{price_per_gb: string, currency: string}|null
     */
    public function getTrafficPriceFor(string $resourceType, ?int $nodeId, ?int $nodeGroupId): ?array
    {
        if ($nodeId !== null) {
            return $this->db->fetchOne(
                'SELECT price_per_gb, currency FROM traffic_prices
                 WHERE resource_type = :type AND node_id = :node_id LIMIT 1',
                ['type' => $resourceType, 'node_id' => $nodeId]
            );
        }
        if ($nodeGroupId !== null) {
            return $this->db->fetchOne(
                'SELECT price_per_gb, currency FROM traffic_prices
                 WHERE resource_type = :type AND node_group_id = :group_id LIMIT 1',
                ['type' => $resourceType, 'group_id' => $nodeGroupId]
            );
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function visibleSidecarPointForUser(int $pointId, int $userId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT sp.*, u.email AS owner_email, u.name AS owner_name
             FROM sidecar_points sp
             LEFT JOIN users u ON u.id = sp.owner_user_id
             WHERE sp.id = :id
               AND (sp.owner_user_id = :user_id OR sp.owner_user_id IS NULL)
             LIMIT 1',
            ['id' => $pointId, 'user_id' => $userId]
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sidecarPointById(int $pointId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT sp.*, u.email AS owner_email, u.name AS owner_name
             FROM sidecar_points sp
             LEFT JOIN users u ON u.id = sp.owner_user_id
             WHERE sp.id = :id LIMIT 1',
            ['id' => $pointId]
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $point
     */
    private function sidecarBondListenPort(array $point): int
    {
        $configured = (int) ($point['bond_listen_port'] ?? 0);
        if ($configured > 0) {
            return $configured;
        }
        return max(0, (int) ($point['listen_port'] ?? 0));
    }

    /**
     * @param array<string, mixed> $point
     * @param array<string, mixed> $pointConfig
     */
    private function sidecarMptcpListenPort(array $point, array $pointConfig = []): int
    {
        $configured = (int) ($point['mptcp_listen_port'] ?? 0);
        if ($configured > 0) {
            return $configured;
        }

        $wireguardListenPort = (int) ($pointConfig['wireguard_listen_port'] ?? 0);
        if ($wireguardListenPort > 0) {
            return $wireguardListenPort;
        }

        return $this->sidecarBondListenPort($point);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveSidecarPoint(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $ownerUserId = (int) ($payload['owner_user_id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $sidecarHost = trim((string) ($payload['sidecar_host'] ?? ''));
        $bindHost = trim((string) ($payload['bind_host'] ?? '0.0.0.0'));
        $totalSpeedLimitMbps = ($payload['total_speed_limit_mbps'] ?? '') !== '' ? max(1, (int) $payload['total_speed_limit_mbps']) : null;
        $isActive = !empty($payload['is_active']) ? 1 : 0;

        $existing = $id > 0 ? ($this->sidecarPointById($id) ?? []) : [];
        $tokenSecret = trim((string) ($payload['token_secret'] ?? ($existing['token_secret'] ?? '')));
        $agentToken = trim((string) ($payload['agent_token'] ?? ($existing['agent_token'] ?? '')));
        $agentConfigJson = $this->normalizeJsonField($payload['agent_config_json'] ?? ($existing['agent_config_json'] ?? null));
        $pointConfig = $this->decodeJsonAssoc($agentConfigJson);
        $legacyListenPort = max(0, (int) ($payload['listen_port'] ?? ($existing['listen_port'] ?? 0)));
        $bondListenPort = max(0, (int) ($payload['bond_listen_port'] ?? ($existing['bond_listen_port'] ?? $legacyListenPort)));
        $mptcpListenPort = max(0, (int) ($payload['mptcp_listen_port'] ?? ($existing['mptcp_listen_port'] ?? (((int) ($pointConfig['wireguard_listen_port'] ?? 0)) ?: $legacyListenPort))));
        $cfListenPort = max(0, (int) ($payload['cf_listen_port'] ?? ($existing['cf_listen_port'] ?? 443)));
        if ($cfListenPort <= 0) {
            $cfListenPort = 443;
        }
        $pointConfig['cf_tls_cert_path'] = trim((string) ($payload['cf_tls_cert_path'] ?? ($pointConfig['cf_tls_cert_path'] ?? '')));
        $pointConfig['cf_tls_key_path'] = trim((string) ($payload['cf_tls_key_path'] ?? ($pointConfig['cf_tls_key_path'] ?? '')));
        $agentConfigJson = $this->normalizeJsonField($pointConfig);

        if ($name === '' || $sidecarHost === '' || $bondListenPort <= 0 || $mptcpListenPort <= 0) {
            throw new ApiException('invalid_sidecar_point_payload', 422, ['payload' => $payload]);
        }

        if ($tokenSecret === '') {
            $tokenSecret = bin2hex(random_bytes(24));
        }
        if ($agentToken === '') {
            $agentToken = bin2hex(random_bytes(24));
        }

        $normalized = [
            'owner_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
            'name' => $name,
            'sidecar_host' => $sidecarHost,
            'listen_port' => $bondListenPort,
            'bond_listen_port' => $bondListenPort,
            'mptcp_listen_port' => $mptcpListenPort,
            'cf_listen_port' => $cfListenPort,
            'bind_host' => $bindHost !== '' ? $bindHost : '0.0.0.0',
            'total_speed_limit_mbps' => $totalSpeedLimitMbps,
            'token_secret' => $tokenSecret,
            'agent_token' => $agentToken,
            'agent_config_json' => $agentConfigJson,
            'is_active' => $isActive,
            'updated_at' => $this->now(),
        ];

        if ($id > 0) {
            $this->db->execute(
                'UPDATE sidecar_points
                 SET owner_user_id = :owner_user_id,
                     name = :name,
                     sidecar_host = :sidecar_host,
                     listen_port = :listen_port,
                     bond_listen_port = :bond_listen_port,
                     mptcp_listen_port = :mptcp_listen_port,
                     cf_listen_port = :cf_listen_port,
                     bind_host = :bind_host,
                     total_speed_limit_mbps = :total_speed_limit_mbps,
                     token_secret = :token_secret,
                     agent_token = :agent_token,
                     agent_config_json = :agent_config_json,
                     is_active = :is_active,
                     updated_at = :updated_at
                 WHERE id = :id',
                array_merge(['id' => $id], $normalized)
            );
        } else {
            $id = $this->db->insert('sidecar_points', array_merge($normalized, [
                'created_at' => $this->now(),
            ]));
        }

        $this->republishActiveForwardsForSidecarPoint($id);

        return $this->sidecarPointById($id) ?? [];
    }

    public function deleteSidecarPoint(int $pointId): void
    {
        $usage = (int) (($this->db->fetchOne(
            'SELECT COUNT(*) AS total FROM forwards WHERE bond_sidecar_point_id = :id AND enabled = 1',
            ['id' => $pointId]
        )['total'] ?? 0));
        if ($usage > 0) {
            throw new ApiException('sidecar_point_delete_blocked', 409, ['usage' => $usage]);
        }

        $this->db->execute(
            'DELETE FROM agent_usage_hourly
             WHERE entity_type = :entity_type
               AND entity_id = :entity_id',
            [
                'entity_type' => 'sidecar',
                'entity_id' => $pointId,
            ]
        );
        $this->db->execute('DELETE FROM sidecar_points WHERE id = :id', ['id' => $pointId]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveClient(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $existing = $id > 0 ? ($this->db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => $id]) ?? []) : [];
        $groupId = (int) ($payload['group_id'] ?? 0);
        $ownerUserId = (int) ($payload['owner_user_id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $displayHost = trim((string) ($payload['display_host'] ?? ''));
        $bindIp = trim((string) ($payload['bind_ip'] ?? ''));
        $nicName = trim((string) ($payload['nic_name'] ?? ''));
        $descriptionConfig = [
            'protocol_filter_json' => $payload['protocol_filter_json'] ?? null,
            'custom_config_json' => $payload['custom_config_json'] ?? null,
            'tags_json' => $payload['tags_json'] ?? null,
            'agent_config_json' => $payload['agent_config_json'] ?? null,
        ];

        if ($name === '' || $displayHost === '') {
            throw new ApiException('invalid_client_payload', 422, ['payload' => $payload]);
        }

        if (($descriptionConfig['agent_config_json'] ?? null) !== null && trim((string) $descriptionConfig['agent_config_json']) !== '') {
            json_decode((string) $descriptionConfig['agent_config_json'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ApiException('invalid_agent_config_json', 422, ['message' => json_last_error_msg()]);
            }
        }

        $normalized = [
            'group_id' => $groupId > 0 ? $groupId : null,
            'owner_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
            'agent_token' => (string) ($payload['agent_token'] ?? ''),
            'agent_version' => ($payload['agent_version'] ?? '') !== '' ? (string) $payload['agent_version'] : null,
            'last_sync_at' => ($payload['last_sync_at'] ?? '') !== '' ? (string) $payload['last_sync_at'] : null,
            'online_device_limit' => max(1, (int) ($payload['online_device_limit'] ?? ($existing['online_device_limit'] ?? 1))),
            'name' => $name,
            'display_host' => $displayHost,
            'bind_ip' => $bindIp !== '' ? $bindIp : null,
            'nic_name' => $nicName !== '' ? $nicName : null,
            'port_range_start' => ($payload['port_range_start'] ?? '') !== '' ? (int) $payload['port_range_start'] : null,
            'port_range_end' => ($payload['port_range_end'] ?? '') !== '' ? (int) $payload['port_range_end'] : null,
            'traffic_multiplier' => (float) ($payload['traffic_multiplier'] ?? 1.0),
            'allow_custom_endpoints' => !empty($payload['allow_custom_endpoints']) ? 1 : 0,
            'force_slave_mode' => !empty($payload['force_slave_mode']) ? 1 : 0,
            'allow_forward_chain' => !empty($payload['allow_forward_chain']) ? 1 : 0,
            'allow_latency_test' => !empty($payload['allow_latency_test']) ? 1 : 0,
            'allow_inbound_proxy' => !empty($payload['allow_inbound_proxy']) ? 1 : 0,
            'allow_paid_lines' => !empty($payload['allow_paid_lines']) ? 1 : 0,
            'selected_carriers_json' => $this->normalizeJsonField($payload['selected_carriers'] ?? []),
            'runtime_platform_scope' => trim((string) ($payload['runtime_platform_scope'] ?? ($existing['runtime_platform_scope'] ?? 'all'))) ?: 'all',
            'default_endpoint_mode' => (string) ($payload['default_endpoint_mode'] ?? 'slave_group'),
            'default_node_group_key' => ($payload['default_node_group_key'] ?? '') !== '' ? (string) $payload['default_node_group_key'] : null,
            'default_forward_chain_id' => ($payload['default_forward_chain_id'] ?? '') !== '' ? (int) $payload['default_forward_chain_id'] : null,
            'max_ip_count' => ($payload['max_ip_count'] ?? '') !== '' ? (int) $payload['max_ip_count'] : null,
            'max_conn_per_ip' => ($payload['max_conn_per_ip'] ?? '') !== '' ? (int) $payload['max_conn_per_ip'] : null,
            'protocol_filter_json' => $this->normalizeJsonField($descriptionConfig['protocol_filter_json']),
            'custom_config_json' => $this->normalizeJsonField($descriptionConfig['custom_config_json']),
            'agent_config_json' => $this->normalizeJsonField($descriptionConfig['agent_config_json']),
            'speed_limit_mbps' => ($payload['speed_limit_mbps'] ?? '') !== '' ? (int) $payload['speed_limit_mbps'] : null,
            'tags_json' => $this->normalizeJsonField($descriptionConfig['tags_json']),
            'status' => (string) ($payload['status'] ?? 'active'),
            'updated_at' => $this->now(),
        ];
        if ($normalized['agent_token'] === '') {
            $normalized['agent_token'] = $id > 0
                ? ((string) (($existing['agent_token'] ?? '')) ?: bin2hex(random_bytes(24)))
                : bin2hex(random_bytes(24));
        }

        if ($id > 0) {
            $this->db->execute(
                'UPDATE clients
                 SET group_id = :group_id,
                     owner_user_id = :owner_user_id,
                     agent_token = :agent_token,
                     agent_version = :agent_version,
                     last_sync_at = :last_sync_at,
                     online_device_limit = :online_device_limit,
                     name = :name,
                     display_host = :display_host,
                     bind_ip = :bind_ip,
                     nic_name = :nic_name,
                     port_range_start = :port_range_start,
                     port_range_end = :port_range_end,
                     traffic_multiplier = :traffic_multiplier,
                     allow_custom_endpoints = :allow_custom_endpoints,
                     force_slave_mode = :force_slave_mode,
                     allow_forward_chain = :allow_forward_chain,
                     allow_latency_test = :allow_latency_test,
                     allow_inbound_proxy = :allow_inbound_proxy,
                     allow_paid_lines = :allow_paid_lines,
                     selected_carriers_json = :selected_carriers_json,
                     runtime_platform_scope = :runtime_platform_scope,
                     default_endpoint_mode = :default_endpoint_mode,
                     default_node_group_key = :default_node_group_key,
                     default_forward_chain_id = :default_forward_chain_id,
                     max_ip_count = :max_ip_count,
                     max_conn_per_ip = :max_conn_per_ip,
                     protocol_filter_json = :protocol_filter_json,
                     custom_config_json = :custom_config_json,
                     agent_config_json = :agent_config_json,
                     speed_limit_mbps = :speed_limit_mbps,
                     tags_json = :tags_json,
                     status = :status,
                     updated_at = :updated_at
                 WHERE id = :id',
                $normalized + ['id' => $id]
            );
        } else {
            $id = $this->db->insert('clients', $normalized + [
                'created_at' => $this->now(),
            ]);
        }

        $this->republishActiveForwardsForClient($id);

        return $this->db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => $id]) ?? [];
    }

    /**
     * @param array<string, mixed> $client
     * @param array<int, array<string, mixed>> $forwards
     * @return array<string, mixed>
     */
    public function linuxProfileRuntimeForClient(array $client, array $forwards): array
    {
        $selectedCarriers = [];
        if (is_string($client['selected_carriers_json'] ?? null) && $client['selected_carriers_json'] !== '') {
            $decoded = json_decode((string) $client['selected_carriers_json'], true);
            if (is_array($decoded)) {
                $selectedCarriers = array_values(array_filter(array_map(static fn($value): string => strtolower(trim((string) $value)), $decoded), static fn(string $value): bool => $value !== ''));
            }
        }

        return [
            'profile_id' => (int) ($client['id'] ?? 0),
            'profile_name' => (string) ($client['name'] ?? ''),
            'allow_paid_lines' => (int) ($client['allow_paid_lines'] ?? 0) === 1,
            'selected_carriers' => $selectedCarriers,
            'runtime_platform_scope' => (string) ($client['runtime_platform_scope'] ?? 'all'),
            'selected_carrier' => null,
            'used_paid_line' => false,
            'forwards' => $forwards,
        ];
    }

    /**
     * @return array{detached_disabled_forwards:int}
     */
    public function deleteClient(int $clientId): array
    {
        $client = $this->db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => $clientId]);
        if ($client === null) {
            throw new ApiException('client_not_found', 404);
        }

        return $this->db->transaction(function (Database $db) use ($clientId): array {
            $activeUsage = (int) (($db->fetchOne(
                'SELECT COUNT(*) AS total FROM forwards WHERE line_client_id = :client_id AND enabled = 1',
                ['client_id' => $clientId]
            )['total'] ?? 0));
            if ($activeUsage > 0) {
                throw new ApiException('client_delete_blocked', 409, ['message' => 'Client is still referenced by active forwards.']);
            }

            $detachedDisabledForwards = $db->execute(
                'UPDATE forwards
                 SET line_client_id = NULL,
                     updated_at = :updated_at
                 WHERE line_client_id = :client_id
                   AND enabled = 0',
                [
                    'client_id' => $clientId,
                    'updated_at' => $this->now(),
                ]
            );

            $deleted = $db->execute('DELETE FROM clients WHERE id = :id', ['id' => $clientId]);
            if ($deleted !== 1) {
                throw new ApiException('client_delete_failed', 500, ['message' => 'Failed to delete client row.']);
            }
            $db->execute(
                'DELETE FROM agent_usage_hourly
                 WHERE entity_type = :entity_type
                   AND entity_id = :entity_id',
                [
                    'entity_type' => 'client',
                    'entity_id' => $clientId,
                ]
            );

            return [
                'detached_disabled_forwards' => $detachedDisabledForwards,
            ];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPlans(): array
    {
        $plans = $this->db->fetchAll(
            'SELECT *
             FROM service_plans
             ORDER BY is_active DESC, name ASC, id ASC'
        );

        foreach ($plans as &$plan) {
            $plan['uses_forward_combinations'] = $this->planUsesForwardCombinations((int) $plan['id']);
            $plan['uses_forward_routes'] = $this->planUsesForwardRoutes((int) $plan['id']);
            $plan['allowed_forward_combinations'] = $this->allowedForwardCombinationsForPlan((int) $plan['id']);
            $plan['allowed_forward_routes'] = $this->allowedForwardRoutesForPlan((int) $plan['id']);
            $plan['allowed_node_groups'] = $this->allowedNodeGroupsForPlan((int) $plan['id']);
            $plan['allowed_client_groups'] = $this->allowedClientGroupsForPlan((int) $plan['id']);
            $plan['allowed_sidecar_points'] = $this->allowedSidecarPointsForPlan((int) $plan['id']);
            $plan['forward_combination_count'] = count($plan['allowed_forward_combinations'] ?? []);
            $plan['forward_route_count'] = count($plan['allowed_forward_routes'] ?? []);
            $plan['feature_flags'] = $this->decodeFeatureFlags((string) ($plan['feature_flags_json'] ?? ''));
        }
        unset($plan);

        return $plans;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPurchasablePlans(): array
    {
        return array_values(array_filter(
            $this->listPlans(),
            static fn(array $plan): bool => (int) ($plan['is_active'] ?? 0) === 1 && (int) ($plan['is_tenant_visible'] ?? 1) === 1
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function purchaseService(int $userId, int $planId, string $serviceName = ''): array
    {
        return $this->purchaseFlexibleService(
            $userId,
            $planId,
            0,
            $serviceName
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function purchaseFlexibleService(int $userId, int $planId, int $requestedBandwidthMbps, string $serviceName = ''): array
    {
        $user = $this->tenantUserById($userId);
        $plan = $this->activePlanById($planId);
        $bandwidth = $this->normalizePurchasedBandwidth($plan, $requestedBandwidthMbps);
        $currency = strtoupper(trim((string) ($plan['billing_currency'] ?? 'CNY')));
        if ($currency === '') {
            $currency = 'CNY';
        }

        $trafficLimit = max(0, (int) ($plan['default_traffic_limit_gb'] ?? 0));
        $billingSnapshot = $this->serviceBillingSnapshot($plan, $trafficLimit, null);
        $servicePreview = $this->buildFlexibleBillingPreview($plan, $bandwidth, $trafficLimit, 0, $billingSnapshot);
        $overview = $this->billingOverviewFromService($servicePreview);
        $purchasePrice = (float) ($overview['estimated_total_amount'] ?? 0);
        $now = $this->now();
        $expireAt = $this->nextBillingCycleStart($this->asUtcDateTime($now))->format('Y-m-d H:i:s');
        $name = trim($serviceName) !== ''
            ? trim($serviceName)
            : trim((string) ($plan['name'] ?? 'Plan')) . ' ' . gmdate('Y-m-d H:i');

        return $this->db->transaction(function () use ($user, $plan, $currency, $purchasePrice, $bandwidth, $trafficLimit, $billingSnapshot, $now, $expireAt, $name, $overview): array {
            if ($purchasePrice > 0) {
                $this->chargeWallet(
                    (int) $user['id'],
                    $currency,
                    $purchasePrice,
                    'plan_purchase',
                    [
                        'plan_id' => (int) $plan['id'],
                        'plan_name' => (string) ($plan['name'] ?? ''),
                        'bandwidth_limit_mbps' => $bandwidth,
                        'charge_breakdown' => [
                            'base_fee' => $overview['base_fee'] ?? '0.00',
                            'bandwidth_addon' => $overview['bandwidth_addon_amount'] ?? '0.00',
                        ],
                    ]
                );
            }

            $serviceId = $this->db->insert('services', [
                'user_id' => $user['id'],
                'plan_id' => $plan['id'],
                'name' => $name,
                'whmcs_service_id' => $this->generateSyntheticWhmcsServiceId(),
                'status' => 'active',
                'expire_at' => $expireAt,
                'bandwidth_limit_mbps' => $bandwidth,
                'traffic_limit_gb' => $trafficLimit,
                'traffic_used_bytes' => 0,
                'client_token' => bin2hex(random_bytes(24)),
                'suspend_reason' => null,
                'billing_mode' => $billingSnapshot['billing_mode'],
                'billing_currency' => $billingSnapshot['billing_currency'],
                'monthly_base_fee' => $billingSnapshot['monthly_base_fee'],
                'monthly_included_traffic_gb' => $billingSnapshot['monthly_included_traffic_gb'],
                'traffic_price_per_gb' => $billingSnapshot['traffic_price_per_gb'],
                'billing_cycle_started_at' => $billingSnapshot['billing_cycle_started_at'],
                'billing_cycle_traffic_used_bytes' => $billingSnapshot['billing_cycle_traffic_used_bytes'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->audit('service.purchased', [
                'service_id' => $serviceId,
                'user_id' => (int) $user['id'],
                'plan_id' => (int) $plan['id'],
                'purchase_price' => $this->normalizeMoney($purchasePrice),
                'currency' => $currency,
                'bandwidth_limit_mbps' => $bandwidth,
                'source' => 'wallet',
            ]);

            return $this->serviceSummary($serviceId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function resetServiceTraffic(int $userId, int $serviceId): array
    {
        $service = $this->db->fetchOne(
            'SELECT s.*, p.traffic_reset_price AS plan_traffic_reset_price, p.billing_currency AS plan_billing_currency
             FROM services s
             INNER JOIN users u ON u.id = s.user_id
             LEFT JOIN service_plans p ON p.id = s.plan_id
             WHERE s.id = :service_id AND s.user_id = :user_id LIMIT 1',
            [
                'service_id' => $serviceId,
                'user_id' => $userId,
            ]
        );
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }
        if ((string) ($service['status'] ?? '') !== 'active') {
            throw new ApiException('service_not_active', 422, ['message' => 'Only active services can reset traffic right now.']);
        }

        $currency = strtoupper(trim((string) ($service['plan_billing_currency'] ?? $service['billing_currency'] ?? 'CNY')));
        if ($currency === '') {
            $currency = 'CNY';
        }
        $resetPrice = (float) ($service['plan_traffic_reset_price'] ?? 0);
        $usedBefore = max(0, (int) ($service['billing_cycle_traffic_used_bytes'] ?? 0));

        return $this->db->transaction(function () use ($userId, $serviceId, $service, $currency, $resetPrice, $usedBefore): array {
            if ($resetPrice > 0) {
                $this->chargeWallet(
                    $userId,
                    $currency,
                    $resetPrice,
                    'traffic_reset',
                    [
                        'service_id' => $serviceId,
                        'service_name' => (string) ($service['name'] ?? ''),
                        'used_bytes_before' => $usedBefore,
                    ]
                );
            }

            $this->db->execute(
                'UPDATE services
                 SET billing_cycle_traffic_used_bytes = 0,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'id' => $serviceId,
                    'updated_at' => $this->now(),
                ]
            );

            $this->audit('service.traffic_reset', [
                'service_id' => $serviceId,
                'user_id' => $userId,
                'reset_price' => $this->normalizeMoney($resetPrice),
                'currency' => $currency,
                'used_bytes_before' => $usedBefore,
                'source' => 'wallet',
            ]);

            return $this->serviceSummary($serviceId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function updateFlexibleServiceOptions(int $userId, int $serviceId, int $requestedBandwidthMbps): array
    {
        $service = $this->db->fetchOne(
            'SELECT s.*, p.default_bandwidth_limit_mbps AS plan_default_bandwidth_limit_mbps,
                    p.bandwidth_step_mbps AS plan_bandwidth_step_mbps,
                    p.bandwidth_step_price AS plan_bandwidth_step_price,
                    p.billing_currency AS plan_billing_currency
             FROM services s
             LEFT JOIN service_plans p ON p.id = s.plan_id
             WHERE s.id = :service_id AND s.user_id = :user_id LIMIT 1',
            [
                'service_id' => $serviceId,
                'user_id' => $userId,
            ]
        );
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }
        if ((string) ($service['status'] ?? '') !== 'active') {
            throw new ApiException('service_not_active', 422, ['message' => 'Only active services can change flexible package options right now.']);
        }

        $bandwidth = $this->normalizePurchasedBandwidth([
            'default_bandwidth_limit_mbps' => $service['plan_default_bandwidth_limit_mbps'] ?? $service['bandwidth_limit_mbps'],
            'bandwidth_step_mbps' => $service['plan_bandwidth_step_mbps'] ?? 0,
        ], $requestedBandwidthMbps);

        $this->db->execute(
            'UPDATE services
             SET bandwidth_limit_mbps = :bandwidth_limit_mbps,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $serviceId,
                'bandwidth_limit_mbps' => $bandwidth,
                'updated_at' => $this->now(),
            ]
        );

        $this->audit('service.flexible_options_updated', [
            'service_id' => $serviceId,
            'user_id' => $userId,
            'bandwidth_limit_mbps' => $bandwidth,
        ]);

        $this->republishActiveForwardsForService($serviceId);

        return $this->serviceSummary($serviceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function savePlan(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $productCode = trim((string) ($payload['product_code'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $bandwidth = max(1, (int) ($payload['default_bandwidth_limit_mbps'] ?? 100));
        $traffic = max(0, (int) ($payload['default_traffic_limit_gb'] ?? 1000));
        $billingMode = $this->normalizeBillingMode((string) ($payload['billing_mode'] ?? 'monthly_bundle'));
        $billingCurrency = strtoupper(trim((string) ($payload['billing_currency'] ?? 'CNY')));
        $monthlyBaseFee = $this->normalizeMoney($payload['monthly_base_fee'] ?? '0');
        $monthlyIncludedTraffic = max(0, (int) ($payload['monthly_included_traffic_gb'] ?? $traffic));
        $trafficPricePerGb = $this->normalizeRate($payload['traffic_price_per_gb'] ?? '0');
        $trafficResetPrice = $this->normalizeMoney($payload['traffic_reset_price'] ?? '0');
        $bandwidthStepMbps = max(0, (int) ($payload['bandwidth_step_mbps'] ?? 0));
        $bandwidthStepPrice = $this->normalizeMoney($payload['bandwidth_step_price'] ?? '0');
        $includedClientCount = max(0, (int) ($payload['included_client_count'] ?? 0));
        $extraClientPrice = $this->normalizeMoney($payload['extra_client_price'] ?? '0');
        $isActive = (int) (($payload['is_active'] ?? '1') ? 1 : 0);
        $featureFlagsJson = $this->normalizeJsonField($payload['feature_flags_json'] ?? $payload['feature_flags'] ?? null);
        $groupIds = [];
        foreach ((array) ($payload['allowed_node_group_ids'] ?? []) as $groupId) {
            $casted = (int) $groupId;
            if ($casted > 0) {
                $groupIds[] = $casted;
            }
        }
        $clientGroupIds = [];
        foreach ((array) ($payload['allowed_client_group_ids'] ?? []) as $groupId) {
            $casted = (int) $groupId;
            if ($casted > 0) {
                $clientGroupIds[] = $casted;
            }
        }
        $sidecarPointIds = [];
        foreach ((array) ($payload['allowed_sidecar_point_ids'] ?? []) as $sidecarId) {
            $casted = (int) $sidecarId;
            if ($casted > 0) {
                $sidecarPointIds[] = $casted;
            }
        }
        $forwardRouteIds = [];
        foreach ((array) ($payload['allowed_forward_route_ids'] ?? []) as $routeId) {
            $casted = (int) $routeId;
            if ($casted > 0) {
                $forwardRouteIds[] = $casted;
            }
        }
        $forwardRouteIds = array_values(array_unique($forwardRouteIds));

        if ($name === '' || $productCode === '') {
            throw new ApiException('invalid_plan_payload', 422, ['payload' => $payload]);
        }
        if ($forwardRouteIds === []) {
            throw new ApiException(
                'invalid_plan_forward_route_payload',
                422,
                ['message' => 'Select at least one active forward route before saving the plan.']
            );
        }

        if ($billingCurrency === '') {
            $billingCurrency = 'CNY';
        }
        if ($billingMode === 'monthly_bundle' && $monthlyIncludedTraffic <= 0) {
            $monthlyIncludedTraffic = $traffic;
        }

        $forwardRouteRows = $this->db->fetchAll(
            'SELECT id, is_active
             FROM forward_routes
             WHERE id IN (' . implode(',', array_map('intval', $forwardRouteIds)) . ')'
        );
        $validForwardRouteIds = [];
        foreach ($forwardRouteRows as $row) {
            if ((int) ($row['is_active'] ?? 0) === 1) {
                $validForwardRouteIds[] = (int) ($row['id'] ?? 0);
            }
        }
        sort($validForwardRouteIds);
        $expectedForwardRouteIds = $forwardRouteIds;
        sort($expectedForwardRouteIds);
        if ($validForwardRouteIds !== $expectedForwardRouteIds) {
            throw new ApiException(
                'invalid_plan_forward_route_payload',
                422,
                ['message' => 'Select at least one active forward route before saving the plan.']
            );
        }

        $forwardCombinationRows = $this->db->fetchAll(
            'SELECT DISTINCT frc.forward_route_id, frc.forward_combination_id AS id
             FROM forward_route_combinations frc
             INNER JOIN forward_combinations fc ON fc.id = frc.forward_combination_id
             WHERE frc.forward_route_id IN (' . implode(',', array_map('intval', $forwardRouteIds)) . ')
               AND frc.enabled = 1
               AND fc.is_active = 1
             ORDER BY frc.forward_route_id ASC, frc.forward_combination_id ASC'
        );
        $routesWithActiveCombinations = [];
        $forwardCombinationIds = [];
        foreach ($forwardCombinationRows as $row) {
            $routeId = (int) ($row['forward_route_id'] ?? 0);
            $combinationId = (int) ($row['id'] ?? 0);
            if ($routeId > 0 && $combinationId > 0) {
                $routesWithActiveCombinations[$routeId] = true;
                $forwardCombinationIds[$combinationId] = $combinationId;
            }
        }
        $forwardCombinationIds = array_values($forwardCombinationIds);
        if (count($routesWithActiveCombinations) !== count($forwardRouteIds)) {
            throw new ApiException(
                'invalid_plan_forward_route_payload',
                422,
                ['message' => 'Every selected route must contain an enabled active forward combination.']
            );
        }

        return $this->db->transaction(function () use ($id, $name, $productCode, $description, $bandwidth, $traffic, $billingMode, $billingCurrency, $monthlyBaseFee, $monthlyIncludedTraffic, $trafficPricePerGb, $trafficResetPrice, $bandwidthStepMbps, $bandwidthStepPrice, $includedClientCount, $extraClientPrice, $isActive, $featureFlagsJson, $groupIds, $clientGroupIds, $sidecarPointIds, $forwardCombinationIds, $forwardRouteIds, $payload): array {
            if ($id > 0) {
                $this->db->execute(
                    'UPDATE service_plans
                     SET name = :name,
                         product_code = :product_code,
                         description = :description,
                         default_bandwidth_limit_mbps = :bandwidth,
                         default_traffic_limit_gb = :traffic,
                         billing_mode = :billing_mode,
                         billing_currency = :billing_currency,
                         monthly_base_fee = :monthly_base_fee,
                         monthly_included_traffic_gb = :monthly_included_traffic_gb,
                         traffic_price_per_gb = :traffic_price_per_gb,
                         traffic_reset_price = :traffic_reset_price,
                         bandwidth_step_mbps = :bandwidth_step_mbps,
                         bandwidth_step_price = :bandwidth_step_price,
                         included_client_count = :included_client_count,
                         extra_client_price = :extra_client_price,
                         default_client_online_device_limit = :default_client_online_device_limit,
                         max_client_profiles = :max_client_profiles,
                         is_active = :is_active,
                         is_tenant_visible = :is_tenant_visible,
                         feature_flags_json = :feature_flags_json,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => $id,
                        'name' => $name,
                        'product_code' => $productCode,
                        'description' => $description !== '' ? $description : null,
                        'bandwidth' => $bandwidth,
                        'traffic' => $traffic,
                        'billing_mode' => $billingMode,
                        'billing_currency' => $billingCurrency,
                        'monthly_base_fee' => $monthlyBaseFee,
                        'monthly_included_traffic_gb' => $monthlyIncludedTraffic,
                        'traffic_price_per_gb' => $trafficPricePerGb,
                        'traffic_reset_price' => $trafficResetPrice,
                        'bandwidth_step_mbps' => $bandwidthStepMbps,
                        'bandwidth_step_price' => $bandwidthStepPrice,
                        'included_client_count' => $includedClientCount,
                        'extra_client_price' => $extraClientPrice,
                        'default_client_online_device_limit' => max(1, (int) ($payload['default_client_online_device_limit'] ?? 1)),
                        'max_client_profiles' => max(1, (int) ($payload['max_client_profiles'] ?? 1)),
                        'is_active' => $isActive,
                        'is_tenant_visible' => (int) (($payload['is_tenant_visible'] ?? '1') ? 1 : 0),
                        'feature_flags_json' => $featureFlagsJson,
                        'updated_at' => $this->now(),
                    ]
                );
            } else {
                $id = $this->db->insert('service_plans', [
                    'name' => $name,
                    'product_code' => $productCode,
                    'description' => $description !== '' ? $description : null,
                    'default_bandwidth_limit_mbps' => $bandwidth,
                    'default_traffic_limit_gb' => $traffic,
                    'billing_mode' => $billingMode,
                    'billing_currency' => $billingCurrency,
                    'monthly_base_fee' => $monthlyBaseFee,
                    'monthly_included_traffic_gb' => $monthlyIncludedTraffic,
                    'traffic_price_per_gb' => $trafficPricePerGb,
                    'traffic_reset_price' => $trafficResetPrice,
                    'bandwidth_step_mbps' => $bandwidthStepMbps,
                    'bandwidth_step_price' => $bandwidthStepPrice,
                    'included_client_count' => $includedClientCount,
                    'extra_client_price' => $extraClientPrice,
                    'default_client_online_device_limit' => max(1, (int) ($payload['default_client_online_device_limit'] ?? 1)),
                    'max_client_profiles' => max(1, (int) ($payload['max_client_profiles'] ?? 1)),
                    'is_active' => $isActive,
                    'is_tenant_visible' => (int) (($payload['is_tenant_visible'] ?? '1') ? 1 : 0),
                    'feature_flags_json' => $featureFlagsJson,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
            }

            $this->db->execute('DELETE FROM service_plan_node_groups WHERE plan_id = :plan_id', [
                'plan_id' => $id,
            ]);
            foreach (array_values(array_unique($groupIds)) as $groupId) {
                $this->db->insert('service_plan_node_groups', [
                    'plan_id' => $id,
                    'node_group_id' => $groupId,
                    'created_at' => $this->now(),
                ]);
            }

            $this->db->execute('DELETE FROM service_plan_client_groups WHERE plan_id = :plan_id', [
                'plan_id' => $id,
            ]);
            foreach (array_values(array_unique($clientGroupIds)) as $groupId) {
                $this->db->insert('service_plan_client_groups', [
                    'plan_id' => $id,
                    'client_group_id' => $groupId,
                    'created_at' => $this->now(),
                ]);
            }

            $this->db->execute('DELETE FROM service_plan_sidecar_points WHERE plan_id = :plan_id', [
                'plan_id' => $id,
            ]);
            foreach (array_values(array_unique($sidecarPointIds)) as $sidecarId) {
                $this->db->insert('service_plan_sidecar_points', [
                    'plan_id' => $id,
                    'sidecar_point_id' => $sidecarId,
                ]);
            }

            $this->db->execute('DELETE FROM service_plan_forward_combinations WHERE plan_id = :plan_id', [
                'plan_id' => $id,
            ]);
            foreach ($forwardCombinationIds as $combinationId) {
                $this->db->insert('service_plan_forward_combinations', [
                    'plan_id' => $id,
                    'forward_combination_id' => $combinationId,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
            }

            $this->db->execute('DELETE FROM service_plan_forward_routes WHERE plan_id = :plan_id', [
                'plan_id' => $id,
            ]);
            foreach ($forwardRouteIds as $routeId) {
                $this->db->insert('service_plan_forward_routes', [
                    'plan_id' => $id,
                    'forward_route_id' => $routeId,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
            }

            $plan = $this->db->fetchOne('SELECT * FROM service_plans WHERE id = :id LIMIT 1', ['id' => $id]) ?? [];
            $plan['uses_forward_combinations'] = $this->planUsesForwardCombinations($id);
            $plan['uses_forward_routes'] = $this->planUsesForwardRoutes($id);
            $plan['allowed_forward_combinations'] = $this->allowedForwardCombinationsForPlan($id);
            $plan['allowed_forward_routes'] = $this->allowedForwardRoutesForPlan($id);
            $plan['allowed_node_groups'] = $this->allowedNodeGroupsForPlan($id);
            $plan['allowed_client_groups'] = $this->allowedClientGroupsForPlan($id);
            $plan['allowed_sidecar_points'] = $this->allowedSidecarPointsForPlan($id);
            $plan['forward_combination_count'] = count($plan['allowed_forward_combinations'] ?? []);
            $plan['forward_route_count'] = count($plan['allowed_forward_routes'] ?? []);
            $plan['feature_flags'] = $this->decodeFeatureFlags((string) ($plan['feature_flags_json'] ?? ''));
            return $plan;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allowedNodeGroupsForPlan(int $planId): array
    {
        if ($planId <= 0) {
            return [];
        }

        if ($this->planUsesForwardCombinations($planId)) {
            $descriptionSelect = $this->nodeGroupDescriptionColumnAvailable()
                ? 'g.description'
                : 'NULL AS description';
            $ingressSelect = $this->nodeGroupIngressSelectSql();
            $groupBy = 'g.id, g.group_key, g.name, g.created_at, g.updated_at';
            if ($this->nodeGroupDescriptionColumnAvailable()) {
                $groupBy .= ', g.description';
            }
            if ($this->nodeGroupIngressColumnsAvailable()) {
                $groupBy .= ', g.ingress_cdn_host, g.ingress_sni_host, g.ingress_path_strategy, g.ingress_port_strategy, g.ingress_preferred_public_port';
            }

            return $this->db->fetchAll(
                'SELECT g.id, g.group_key, g.name, ' . $descriptionSelect . ',
                        ' . $ingressSelect . ',
                        g.created_at, g.updated_at, COUNT(n.id) AS node_count
                 FROM (
                     SELECT fc.node_group_key
                     FROM service_plan_forward_combinations spfc
                     INNER JOIN forward_combinations fc ON fc.id = spfc.forward_combination_id
                     WHERE spfc.plan_id = :plan_id_primary
                     UNION
                     SELECT fchg.node_group_key
                     FROM service_plan_forward_combinations spfc
                     INNER JOIN forward_combination_helper_groups fchg ON fchg.forward_combination_id = spfc.forward_combination_id
                     WHERE spfc.plan_id = :plan_id_helper AND fchg.is_active = 1
                     UNION
                     SELECT g2.group_key
                     FROM service_plan_forward_combinations spfc
                     INNER JOIN forward_combination_slaves fcs ON fcs.forward_combination_id = spfc.forward_combination_id
                     INNER JOIN nodes n2 ON n2.id = fcs.node_id
                     INNER JOIN node_groups g2 ON g2.id = n2.group_id
                     WHERE spfc.plan_id = :plan_id_pinned AND fcs.is_active = 1
                 ) allowed_groups
                 INNER JOIN node_groups g ON g.group_key = allowed_groups.node_group_key
                 LEFT JOIN nodes n ON n.group_id = g.id AND (n.status = :active OR n.status = :degraded)
                 GROUP BY ' . $groupBy . '
                 ORDER BY g.name ASC, g.id ASC',
                [
                    'plan_id_primary' => $planId,
                    'plan_id_helper' => $planId,
                    'plan_id_pinned' => $planId,
                    'active' => 'active',
                    'degraded' => 'degraded',
                ]
            );
        }

        $descriptionSelect = $this->nodeGroupDescriptionColumnAvailable()
            ? 'g.description'
            : 'NULL AS description';
        $ingressSelect = $this->nodeGroupIngressSelectSql();
        $groupBy = 'g.id, g.group_key, g.name, g.created_at, g.updated_at';
        if ($this->nodeGroupDescriptionColumnAvailable()) {
            $groupBy .= ', g.description';
        }
        if ($this->nodeGroupIngressColumnsAvailable()) {
            $groupBy .= ', g.ingress_cdn_host, g.ingress_sni_host, g.ingress_path_strategy, g.ingress_port_strategy, g.ingress_preferred_public_port';
        }

        return $this->db->fetchAll(
            'SELECT g.id, g.group_key, g.name, ' . $descriptionSelect . ',
                    ' . $ingressSelect . ',
                    g.created_at, g.updated_at, COUNT(n.id) AS node_count
             FROM service_plan_node_groups pg
             INNER JOIN node_groups g ON g.id = pg.node_group_id
             LEFT JOIN nodes n ON n.group_id = g.id AND (n.status = :active OR n.status = :degraded)
             WHERE pg.plan_id = :plan_id
             GROUP BY ' . $groupBy . '
             ORDER BY g.name ASC, g.id ASC',
            [
                'plan_id' => $planId,
                'active' => 'active',
                'degraded' => 'degraded',
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allowedClientGroupsForPlan(int $planId): array
    {
        if ($planId <= 0) {
            return [];
        }

        if ($this->planUsesForwardCombinations($planId)) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT g.*, COUNT(c.id) AS client_count
             FROM service_plan_client_groups pg
             INNER JOIN client_groups g ON g.id = pg.client_group_id
             LEFT JOIN clients c ON c.group_id = g.id AND c.status = :status
             WHERE pg.plan_id = :plan_id
             GROUP BY g.id, g.group_key, g.name, g.description, g.created_at, g.updated_at
             ORDER BY g.name ASC, g.id ASC',
            [
                'plan_id' => $planId,
                'status' => 'active',
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allowedSidecarPointsForPlan(int $planId): array
    {
        if ($planId <= 0) {
            return [];
        }

        if ($this->planUsesForwardCombinations($planId)) {
            return $this->db->fetchAll(
                'SELECT sp.id, sp.name, sp.sidecar_host, sp.listen_port, sp.bond_listen_port, sp.mptcp_listen_port, sp.cf_listen_port, sp.agent_config_json
                 FROM service_plan_forward_combinations spfc
                 INNER JOIN forward_combinations fc ON fc.id = spfc.forward_combination_id
                 INNER JOIN sidecar_points sp ON sp.id = fc.sidecar_point_id
                 WHERE spfc.plan_id = :plan_id
                 GROUP BY sp.id, sp.name, sp.sidecar_host, sp.listen_port, sp.bond_listen_port, sp.mptcp_listen_port, sp.cf_listen_port, sp.agent_config_json
                 ORDER BY sp.name ASC, sp.id ASC',
                ['plan_id' => $planId]
            );
        }

        return $this->db->fetchAll(
            'SELECT sp.id, sp.name, sp.sidecar_host, sp.listen_port, sp.bond_listen_port, sp.mptcp_listen_port, sp.cf_listen_port, sp.agent_config_json
             FROM service_plan_sidecar_points spsp
             INNER JOIN sidecar_points sp ON sp.id = spsp.sidecar_point_id
             WHERE spsp.plan_id = :plan_id
             ORDER BY sp.name ASC, sp.id ASC',
            ['plan_id' => $planId]
        );
    }

    public function createUserSlaveGroup(int $ownerUserId, string $name): array
    {
        $inviteCode = bin2hex(random_bytes(6)); // 12-char hex code
        $id = $this->db->insert('user_slave_groups', [
            'name' => trim($name),
            'owner_user_id' => $ownerUserId,
            'invite_code' => $inviteCode,
            'created_at' => $this->now(),
        ]);

        $this->db->insert('user_slave_group_members', [
            'group_id' => $id,
            'user_id' => $ownerUserId,
            'role' => 'owner',
            'joined_at' => $this->now(),
        ]);

        return $this->db->fetchOne('SELECT * FROM user_slave_groups WHERE id = :id LIMIT 1', ['id' => $id]) ?? [];
    }

    public function joinUserSlaveGroup(int $userId, string $inviteCode): array
    {
        $group = $this->db->fetchOne(
            'SELECT * FROM user_slave_groups WHERE invite_code = :code LIMIT 1',
            ['code' => trim($inviteCode)]
        );
        if ($group === null) {
            throw new ApiException('invalid_invite_code', 404);
        }

        $existing = $this->db->fetchOne(
            'SELECT id FROM user_slave_group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1',
            ['gid' => (int) $group['id'], 'uid' => $userId]
        );
        if ($existing !== null) {
            throw new ApiException('already_member', 409);
        }

        $this->db->insert('user_slave_group_members', [
            'group_id' => (int) $group['id'],
            'user_id' => $userId,
            'role' => 'member',
            'joined_at' => $this->now(),
        ]);

        return $group;
    }

    public function leaveUserSlaveGroup(int $userId, int $groupId): void
    {
        $member = $this->db->fetchOne(
            'SELECT role FROM user_slave_group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1',
            ['gid' => $groupId, 'uid' => $userId]
        );
        if ($member === null) {
            return;
        }
        if ((string) ($member['role'] ?? '') === 'owner') {
            throw new ApiException('owner_cannot_leave', 400);
        }

        $this->db->execute(
            'DELETE FROM user_slave_group_members WHERE group_id = :gid AND user_id = :uid',
            ['gid' => $groupId, 'uid' => $userId]
        );
    }

    public function kickUserSlaveGroupMember(int $ownerUserId, int $groupId, int $targetUserId): void
    {
        $group = $this->db->fetchOne(
            'SELECT owner_user_id FROM user_slave_groups WHERE id = :id LIMIT 1',
            ['id' => $groupId]
        );
        if ($group === null || (int) $group['owner_user_id'] !== $ownerUserId) {
            throw new ApiException('not_group_owner', 403);
        }

        $this->db->execute(
            'DELETE FROM user_slave_group_members WHERE group_id = :gid AND user_id = :uid AND role != :owner_role',
            ['gid' => $groupId, 'uid' => $targetUserId, 'owner_role' => 'owner']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUserSlaveGroups(int $userId): array
    {
        $groups = $this->db->fetchAll(
            'SELECT g.*, m.role AS my_role, u.email AS owner_email
             FROM user_slave_groups g
             INNER JOIN user_slave_group_members m ON m.group_id = g.id AND m.user_id = :uid
             LEFT JOIN users u ON u.id = g.owner_user_id
             ORDER BY g.name ASC, g.id ASC',
            ['uid' => $userId]
        );

        foreach ($groups as &$group) {
            $group['members'] = $this->db->fetchAll(
                'SELECT m.user_id, m.role, u.email
                 FROM user_slave_group_members m
                 INNER JOIN users u ON u.id = m.user_id
                 WHERE m.group_id = :gid
                 ORDER BY m.role ASC, u.email ASC',
                ['gid' => (int) $group['id']]
            );
            $group['shared_nodes'] = $this->db->fetchAll(
                'SELECT n.id, n.name, n.public_ip, n.status
                 FROM nodes n
                 WHERE n.sharing_mode = :shared AND n.user_slave_group_id = :gid
                 ORDER BY n.name ASC',
                ['shared' => 'group_shared', 'gid' => (int) $group['id']]
            );
        }
        unset($group);

        return $groups;
    }

    public function updateNodeSharing(int $nodeId, int $ownerUserId, string $sharingMode, ?int $userSlaveGroupId): void
    {
        $node = $this->db->fetchOne(
            'SELECT n.id FROM nodes n
             INNER JOIN services s ON s.id = n.owner_service_id
             WHERE n.id = :nid AND s.user_id = :uid LIMIT 1',
            ['nid' => $nodeId, 'uid' => $ownerUserId]
        );
        if ($node === null) {
            throw new ApiException('node_not_owned', 403);
        }

        $this->db->execute(
            'UPDATE nodes SET sharing_mode = :mode, user_slave_group_id = :gid WHERE id = :id',
            ['mode' => $sharingMode, 'gid' => $userSlaveGroupId, 'id' => $nodeId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForwardChains(): array
    {
        $chains = $this->db->fetchAll(
            'SELECT * FROM forward_chains ORDER BY updated_at DESC, id DESC'
        );

        foreach ($chains as &$chain) {
            $chain['steps'] = $this->db->fetchAll(
                'SELECT s.*, g.group_key, g.name AS group_name
                 FROM forward_chain_steps s
                 INNER JOIN node_groups g ON g.id = s.node_group_id
                 WHERE s.forward_chain_id = :chain_id
                 ORDER BY s.step_order ASC, s.id ASC',
                ['chain_id' => $chain['id']]
            );
        }
        unset($chain);

        return $chains;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveForwardChain(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $isActive = !empty($payload['is_active']) ? 1 : 0;
        $stepIds = [];
        foreach ((array) ($payload['node_group_ids'] ?? []) as $groupId) {
            $casted = (int) $groupId;
            if ($casted > 0) {
                $stepIds[] = $casted;
            }
        }

        if ($name === '' || $stepIds === []) {
            throw new ApiException('invalid_forward_chain_payload', 422, ['payload' => $payload]);
        }

        return $this->db->transaction(function () use ($id, $name, $description, $isActive, $stepIds): array {
            if ($id > 0) {
                $this->db->execute(
                    'UPDATE forward_chains
                     SET name = :name,
                         description = :description,
                         is_active = :is_active,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => $id,
                        'name' => $name,
                        'description' => $description !== '' ? $description : null,
                        'is_active' => $isActive,
                        'updated_at' => $this->now(),
                    ]
                );
                $chainId = $id;
            } else {
                $chainId = $this->db->insert('forward_chains', [
                    'name' => $name,
                    'description' => $description !== '' ? $description : null,
                    'is_active' => $isActive,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
            }

            $this->db->execute('DELETE FROM forward_chain_steps WHERE forward_chain_id = :chain_id', [
                'chain_id' => $chainId,
            ]);

            $order = 10;
            foreach ($stepIds as $nodeGroupId) {
                $this->db->insert('forward_chain_steps', [
                    'forward_chain_id' => $chainId,
                    'node_group_id' => $nodeGroupId,
                    'step_order' => $order,
                    'created_at' => $this->now(),
                ]);
                $order += 10;
            }

            $chain = $this->db->fetchOne('SELECT * FROM forward_chains WHERE id = :id LIMIT 1', ['id' => $chainId]) ?? [];
            $chain['steps'] = $this->db->fetchAll(
                'SELECT s.*, g.group_key, g.name AS group_name
                 FROM forward_chain_steps s
                 INNER JOIN node_groups g ON g.id = s.node_group_id
                 WHERE s.forward_chain_id = :chain_id
                 ORDER BY s.step_order ASC, s.id ASC',
                ['chain_id' => $chainId]
            );
            return $chain;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableNodeGroupsForService(int $serviceId): array
    {
        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id LIMIT 1', ['id' => $serviceId]);
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }

        $planId = (int) ($service['plan_id'] ?? 0);
        $groups = $this->listNodeGroups();
        if ($planId > 0) {
            $explicitGroups = $this->allowedNodeGroupsForPlan($planId);
            if ($explicitGroups !== []) {
                $groups = $explicitGroups;
            }
        }
        return array_values(array_filter($groups, static fn(array $group): bool => (int) ($group['node_count'] ?? 0) > 0));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableClientsForService(int $serviceId): array
    {
        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id LIMIT 1', ['id' => $serviceId]);
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }

        $clients = array_values(array_filter($this->listClients(), static function (array $client) use ($service): bool {
            $ownerUserId = (int) ($client['owner_user_id'] ?? 0);
            return $ownerUserId === 0 || $ownerUserId === (int) $service['user_id'];
        }));
        $planId = (int) ($service['plan_id'] ?? 0);
        if ($planId <= 0) {
            return array_values(array_filter($clients, static fn(array $client): bool => (string) $client['status'] === 'active'));
        }

        $allowedGroups = $this->allowedClientGroupsForPlan($planId);
        if ($allowedGroups === []) {
            return array_values(array_filter($clients, static fn(array $client): bool => (string) $client['status'] === 'active'));
        }

        $allowedGroupIds = array_map(static fn(array $group): int => (int) $group['id'], $allowedGroups);
        return array_values(array_filter($clients, static fn(array $client): bool => (string) $client['status'] === 'active' && in_array((int) ($client['group_id'] ?? 0), $allowedGroupIds, true)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableClientGroupsForUser(int $userId): array
    {
        $services = $this->db->fetchAll('SELECT id FROM services WHERE user_id = :user_id', ['user_id' => $userId]);
        $groups = [];
        foreach ($services as $service) {
            foreach ($this->availableClientGroupsForService((int) $service['id']) as $group) {
                $groups[(int) $group['id']] = $group;
            }
        }
        return array_values($groups);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function assignedForwardConfigsForClient(int $clientId, ?array $deviceSession = null): array
    {
        $client = $this->db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => $clientId]);
        if ($client === null) {
            throw new ApiException('client_not_found', 404);
        }

        $forwards = $this->db->fetchAll(
            'SELECT f.id, f.service_id, s.status AS service_status
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.enabled = 1
               AND s.status = :active_status
             ORDER BY f.id ASC',
            ['active_status' => 'active']
        );

        $matched = [];
        foreach ($forwards as $forward) {
            $forwardId = (int) $forward['id'];
            $row = $this->db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', ['id' => $forwardId]);
            if ($row === null) {
                continue;
            }

            $match = false;
            if ((int) ($row['line_client_id'] ?? 0) === $clientId) {
                $match = true;
            }

            $clientGroupId = (int) ($client['group_id'] ?? 0);
            if (!$match && $clientGroupId > 0 && (int) ($row['line_client_group_id'] ?? 0) === $clientGroupId) {
                $match = true;
            }

            if (!$match && $clientGroupId > 0 && is_string($row['line_client_group_ids_json'] ?? null) && $row['line_client_group_ids_json'] !== '') {
                $decoded = json_decode((string) $row['line_client_group_ids_json'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $groupId) {
                        if ((int) $groupId === $clientGroupId) {
                            $match = true;
                            break;
                        }
                    }
                }
            }

            if (!$match) {
                continue;
            }

            $config = $this->buildClientConfig($forwardId);
            $config['service_status'] = (string) $forward['service_status'];
            $config['runtime_preference'] = (string) ($config['forward_combination_runtime_preference'] ?? 'single');
            $config['self_leg_cf_transport'] = (string) ($config['forward_combination_self_leg_cf_transport'] ?? 'cf_direct');
            $maxLegs = max(0, (int) ($config['forward_combination_helper_group_cap'] ?? 0));
            $config['helper_group_cap'] = $maxLegs;
            $config['max_legs_used'] = $maxLegs;
            $config['candidate_pool'] = $this->applyMaxLegsCapToPool(
                $this->buildForwardCandidatePool($config, $client, $deviceSession),
                $maxLegs
            );
            $config['endpoints'] = array_values(array_filter(
                is_array($config['endpoints'] ?? null) ? $config['endpoints'] : [],
                static function (mixed $endpoint): bool {
                    if (!is_array($endpoint)) {
                        return false;
                    }

                    return trim((string) ($endpoint['host'] ?? '')) !== ''
                        && (int) ($endpoint['port'] ?? 0) > 0;
                }
            ));
            if ($config['endpoints'] === []) {
                continue;
            }
            $matched[] = $config;
        }

        return $matched;
    }

    /**
     * @param array<int, array<string, mixed>> $forwards
     * @param array<string, mixed> $existing
     * @return array<string, mixed>|null
     */
    public function udpUotSidepathConfigForAssignedClientForwards(array $forwards, array $existing = []): ?array
    {
        $forwardIds = [];
        foreach ($forwards as $forward) {
            if (!is_array($forward)) {
                continue;
            }
            $forwardId = (int) ($forward['forward_id'] ?? 0);
            if ($forwardId <= 0) {
                continue;
            }
            if ($this->normalizeClientMode((string) ($forward['mode'] ?? 'single')) !== 'bond') {
                continue;
            }
            $transport = strtolower(trim((string) ($forward['transport'] ?? 'tcp+udp')));
            $bonding = is_array($forward['bonding'] ?? null) ? $forward['bonding'] : [];
            $ingressMode = $this->normalizeBondIngressMode((string) ($bonding['ingress_mode'] ?? 'wss_path_mux'));
            if (!str_contains($transport, 'udp') || $ingressMode !== 'plain_tcp_mux') {
                continue;
            }
            $forwardIds[] = $forwardId;
        }

        return $this->buildUdpUotSidepathConfig($forwardIds, $existing);
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>|null
     */
    public function udpUotSidepathConfigForNode(int $nodeId, array $existing = []): ?array
    {
        if ($nodeId <= 0) {
            return null;
        }

        $rows = $this->db->fetchAll(
            'SELECT DISTINCT f.id
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             INNER JOIN service_endpoints se ON se.forward_id = f.id
             WHERE se.node_id = :node_id
               AND f.enabled = 1
               AND s.status = :service_status
               AND se.health_status <> :deleted
               AND se.health_status <> :deleting
               AND f.transport_mode LIKE :udp_transport
               AND f.bond_ingress_mode = :ingress_mode
               AND f.client_mode = :bond_mode
             ORDER BY f.id ASC',
            [
                'node_id' => $nodeId,
                'service_status' => 'active',
                'deleted' => 'deleted',
                'deleting' => 'deleting',
                'udp_transport' => '%udp%',
                'ingress_mode' => 'plain_tcp_mux',
                'bond_mode' => 'bond',
            ]
        );

        $forwardIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id'] ?? 0),
            $rows
        ), static fn(int $forwardId): bool => $forwardId > 0));

        return $this->buildUdpUotSidepathConfig($forwardIds, $existing);
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>|null
     */
    public function udpUotSidepathConfigForSidecarPoint(int $pointId, array $existing = []): ?array
    {
        if ($pointId <= 0) {
            return null;
        }

        $rows = $this->db->fetchAll(
            'SELECT DISTINCT f.id
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.bond_sidecar_point_id = :point_id
               AND f.enabled = 1
               AND s.status = :service_status
               AND f.transport_mode LIKE :udp_transport
               AND f.bond_ingress_mode = :ingress_mode
               AND f.client_mode = :bond_mode
             ORDER BY f.id ASC',
            [
                'point_id' => $pointId,
                'service_status' => 'active',
                'udp_transport' => '%udp%',
                'ingress_mode' => 'plain_tcp_mux',
                'bond_mode' => 'bond',
            ]
        );

        $forwardIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id'] ?? 0),
            $rows
        ), static fn(int $forwardId): bool => $forwardId > 0));

        return $this->buildUdpUotSidepathConfig($forwardIds, $existing);
    }

    /**
     * @param array<int, int> $forwardIds
     * @param array<string, mixed> $existing
     * @return array<string, mixed>|null
     */
    private function buildUdpUotSidepathConfig(array $forwardIds, array $existing = []): ?array
    {
        $normalizedForwardIds = array_values(array_unique(array_filter(array_map(
            static fn($value): int => (int) $value,
            $forwardIds
        ), static fn(int $forwardId): bool => $forwardId > 0)));

        sort($normalizedForwardIds);
        if ($normalizedForwardIds === []) {
            return null;
        }

        return [
            'enabled_forward_ids' => $normalizedForwardIds,
            'shadow_route_suffix' => trim((string) ($existing['shadow_route_suffix'] ?? '.uot')) ?: '.uot',
            'sidecar_port' => max(1, (int) ($existing['sidecar_port'] ?? 8999)),
            'reorder_window_packets' => max(1, (int) ($existing['reorder_window_packets'] ?? 4096)),
            'reorder_window_bytes' => max(1024, (int) ($existing['reorder_window_bytes'] ?? 16777216)),
            'reorder_timeout_ms' => max(1, (int) ($existing['reorder_timeout_ms'] ?? 25)),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $client
     * @param array<string, mixed>|null $deviceSession
     * @return array<int, array<string, mixed>>
     */
    private function buildForwardCandidatePool(array $config, array $client, ?array $deviceSession): array
    {
        // Regression sentinel: runtime_capabilities' => ['bond']
        $pool = [];
        foreach ((array) ($config['endpoints'] ?? []) as $endpoint) {
            if (!is_array($endpoint)) {
                continue;
            }
            $originGroupKey = trim((string) ($endpoint['origin_group_key'] ?? $endpoint['node_group_key'] ?? $endpoint['group_key'] ?? ''));
            $endpointRtt = null;
            foreach (['last_chain_latency_ms', 'one_leg_rtt_ms', 'latency_ewma_ms', 'last_latency_ms'] as $latencyKey) {
                if (array_key_exists($latencyKey, $endpoint)) {
                    $endpointRtt = $this->nullableFloat($endpoint[$latencyKey]);
                    if ($endpointRtt !== null) {
                        break;
                    }
                }
            }
            $pool[] = [
                'candidate_kind' => 'rented_group_leg',
                'endpoint_id' => (int) ($endpoint['endpoint_id'] ?? 0),
                'origin_group_key' => $originGroupKey,
                'origin_group_order' => $this->helperGroupOrderForConfig($config, $originGroupKey),
                'runtime_capabilities' => ['bond', 'mptcp'],
                'host' => (string) ($endpoint['host'] ?? ''),
                'port' => (int) ($endpoint['port'] ?? 0),
                'measured_rtt_ms' => $endpointRtt,
                'last_latency_ms' => $endpointRtt,
            ];
        }

        $selfLeg = $this->buildSelfLegCandidate($config, $client, $deviceSession);
        if ($selfLeg !== null) {
            array_unshift($pool, $selfLeg);
        }

        $combinationId = (int) ($config['forward_combination_id'] ?? 0);
        if ($combinationId > 0) {
            $cap = max(0, (int) ($config['max_legs_used'] ?? $config['helper_group_cap'] ?? 0));
            foreach ($this->pickFastestSlavesForCombination($combinationId, $cap) as $candidate) {
                if (($candidate['candidate_source'] ?? '') !== 'self_leg_donor') {
                    continue;
                }
                $pool[] = [
                    'candidate_kind' => 'donated_slave_leg',
                    'endpoint_id' => 900000 + (int) ($candidate['donor_id'] ?? 0),
                    'donor_id' => (int) ($candidate['donor_id'] ?? 0),
                    'donor_client_id' => (int) ($candidate['client_id'] ?? 0),
                    'runtime_capabilities' => ['bond', 'mptcp'],
                    'host' => (string) ($candidate['host'] ?? ''),
                    'port' => (int) ($candidate['port'] ?? 0),
                    'measured_rtt_ms' => $candidate['last_chain_latency_ms'] ?? null,
                    'last_latency_ms' => $candidate['last_chain_latency_ms'] ?? null,
                    'origin_group_key' => 'donated_self_leg',
                    'origin_group_order' => 500000,
                    'ready' => true,
                ];
            }
        }

        return $pool;
    }

    private function applyMaxLegsCapToPool(array $pool, int $cap): array
    {
        if ($cap <= 0) {
            return $pool;
        }
        $selfLegs = [];
        $rented = [];
        foreach ($pool as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['candidate_kind'] ?? '') === 'self_leg') {
                $selfLegs[] = $item;
            } else {
                $rented[] = $item;
            }
        }
        $rented = array_slice($rented, 0, $cap);
        return array_merge($selfLegs, $rented);
    }

    /**
     * @param array<string, mixed> $pointConfig
     * @return array<int, array<string, mixed>>
     */

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private function publishedSelfLegCfCandidates(array $config): array
    {
        $groupId = (int) ($config['cf_location_group_id'] ?? 0);
        if ($groupId <= 0) {
            return [];
        }

        $statsRows = $this->db->fetchAll(
            'SELECT nh.payload_json
             FROM node_heartbeats nh
             INNER JOIN (
                 SELECT node_id, MAX(id) AS max_id
                 FROM node_heartbeats
                 GROUP BY node_id
             ) latest ON latest.max_id = nh.id'
        );

        $byIp = [];
        foreach ($statsRows as $row) {
            $payload = is_string($row['payload_json'] ?? null) ? json_decode((string) $row['payload_json'], true) : [];
            if (!is_array($payload)) {
                continue;
            }
            foreach ((array) ($payload['cf_location_stats'] ?? []) as $stat) {
                if (!is_array($stat)) {
                    continue;
                }
                if ((int) ($stat['location_group_id'] ?? 0) !== $groupId) {
                    continue;
                }
                $selectedIp = trim((string) ($stat['selected_ip'] ?? ''));
                $latency = (float) ($stat['last_latency_ms'] ?? 0);
                if ($selectedIp === '' || $latency <= 0) {
                    continue;
                }
                $current = $byIp[$selectedIp] ?? null;
                if (!is_array($current) || $latency < (float) ($current['latency_ms'] ?? 999999.0)) {
                    $byIp[$selectedIp] = [
                        'ip' => $selectedIp,
                        'latency_ms' => $latency,
                    ];
                }
            }
        }

        usort($byIp, static fn(array $left, array $right): int => ((float) ($left['latency_ms'] ?? 999999.0) <=> (float) ($right['latency_ms'] ?? 999999.0)) ?: strcmp((string) ($left['ip'] ?? ''), (string) ($right['ip'] ?? '')));
        $ordered = [];
        foreach ($byIp as $item) {
            $value = trim((string) ($item['ip'] ?? ''));
            if ($value !== '' && !in_array($value, $ordered, true)) {
                $ordered[] = $value;
            }
        }
        return array_slice($ordered, 0, 8);
    }

    private function approvedCfUpstreamRealizations(array $pointConfig): array
    {
        $items = $pointConfig['approved_upstream_realizations'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $approved = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (strtolower(trim((string) ($item['mode'] ?? ''))) !== 'cf_mode') {
                continue;
            }
            $dialHost = trim((string) ($item['dial_host'] ?? ''));
            $dialPort = (int) ($item['dial_port'] ?? 0);
            if ($dialHost === '' || $dialPort <= 0) {
                continue;
            }
            $approved[] = [
                'mode' => 'cf_mode',
                'profile' => (string) ($item['profile'] ?? ''),
                'dial_host' => $dialHost,
                'dial_port' => $dialPort,
                'cf_sni_host' => trim((string) ($item['cf_sni_host'] ?? $item['sni_host'] ?? $dialHost)),
            ];
        }

        return $approved;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{dial_host:string,dial_port:int,cf_sni_host:string,target_sidecar_host:string,target_sidecar_port:int}|null
     */
    private function selfLegCfTarget(array $config): ?array
    {
        $approved = $config['bond_sidecar_approved_upstream_realizations'] ?? [];
        if (is_array($approved)) {
            foreach ($approved as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $dialHost = trim((string) ($item['dial_host'] ?? ''));
                $dialPort = (int) ($item['dial_port'] ?? 0);
                if ($dialHost === '' || $dialPort <= 0) {
                    continue;
                }
                $sniHost = trim((string) ($item['cf_sni_host'] ?? $item['sni_host'] ?? $dialHost));
                $websocketSni = trim((string) ($config['sni'] ?? $config['bond_sidecar_cf_sni_host'] ?? $sniHost));
                $websocketPort = (int) ($config['bond_sidecar_cf_listen_port'] ?? 0);
                if ($websocketPort <= 0) {
                    $websocketPort = (int) ($config['bond_sidecar_port'] ?? $config['bond_remote_port'] ?? $dialPort);
                }
                return [
                    'dial_host' => $dialHost,
                    'self_leg_cf_candidates' => $this->publishedSelfLegCfCandidates($config),
                    'dial_port' => $websocketPort,
                    'cf_sni_host' => $websocketSni !== '' ? $websocketSni : ($sniHost !== '' ? $sniHost : (string) ($config['sni'] ?? $dialHost)),
                    'target_sidecar_host' => trim((string) ($config['bond_sidecar_host'] ?? $config['bond_remote_host'] ?? '')),
                    'target_sidecar_port' => (int) ($config['bond_sidecar_port'] ?? $config['bond_remote_port'] ?? $dialPort),
                ];
            }
        }

        $dialHost = trim((string) ($config['bond_sidecar_host'] ?? ''));
        $dialPort = (int) ($config['bond_sidecar_cf_listen_port'] ?? 0);
        if ($dialHost === '' || $dialPort <= 0) {
            return null;
        }
        $sniHost = trim((string) ($config['bond_sidecar_cf_sni_host'] ?? $config['sni'] ?? $dialHost));
        $websocketSni = trim((string) ($config['sni'] ?? $sniHost));
        return [
            'dial_host' => $dialHost,
            'self_leg_cf_candidates' => $this->publishedSelfLegCfCandidates($config),
            'dial_port' => $dialPort,
            'cf_sni_host' => $websocketSni !== '' ? $websocketSni : ($sniHost !== '' ? $sniHost : $dialHost),
            'target_sidecar_host' => $dialHost,
            'target_sidecar_port' => (int) ($config['bond_sidecar_port'] ?? $dialPort),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $client
     * @param array<string, mixed>|null $deviceSession
     * @return array<string, mixed>|null
     */
    private function buildSelfLegCandidate(array $config, array $client, ?array $deviceSession): ?array
    {
        if ((int) ($config['forward_combination_allow_private_self_leg'] ?? 0) !== 1) {
            return null;
        }
        if ($deviceSession === null) {
            return null;
        }
        $deviceId = trim((string) ($deviceSession['device_id'] ?? ''));
        if ($deviceId === '') {
            return null;
        }
        $cfTarget = $this->selfLegCfTarget($config);
        if ($cfTarget === null) {
            return null;
        }

        // Regression sentinel: self_leg_cf_only_under_cf_bond
        if ((string) ($config['backend_mode'] ?? '') === 'cf_mode') {
            $cfBond = true;
        } else {
            $cfBond = false;
        }
        $cfTransport = strtolower((string) ($config['self_leg_cf_transport'] ?? 'cf_direct'));
        $wantCfWg = $cfBond && $cfTransport === 'cf_wg';
        if ($cfBond && !$wantCfWg) {
            $wgProfile = null;
        } else {
            $wgProfile = $this->buildSelfLegWireGuardProfile($config, $client, $deviceSession);
        }
        $cfWgPathPrefix = null;
        if ($wantCfWg && $wgProfile !== null) {
            $wgProfile['outer_transport'] = 'wstunnel_udp';
            $wgProfile['cf_dial_host'] = (string) ($cfTarget['dial_host'] ?? ($config['bond_sidecar_host'] ?? ''));
            $wgProfile['cf_dial_port'] = (int) ($cfTarget['dial_port'] ?? ($config['bond_sidecar_cf_listen_port'] ?? 443)) ?: 443;
            $wgProfile['cf_sni_host'] = (string) ($cfTarget['cf_sni_host'] ?? ($config['bond_sidecar_cf_sni_host'] ?? $config['sni'] ?? ''));
            // Regression sentinel: cf_wg_path_prefix_uses_cfwg_suffix
            // The bond `path_prefix` resolves on the sidecar to a TCP-only bond
            // backend; routing WG datagrams to it silently drops them.
            // The matching `<path>.cfwg` route published by SidecarAgentController
            // forwards WG UDP to the sidecar's wireguard_listen_port. CF routing
            // is identified by the panel's public CF port plus this WSS path.
            $cfBasePathPrefix = trim((string) ($config['path_prefix'] ?? '/'));
            $cfWgPathPrefix = str_ends_with($cfBasePathPrefix, '.cfwg') ? $cfBasePathPrefix : $cfBasePathPrefix . '.cfwg';
            $wgProfile['cf_path_prefix'] = $cfWgPathPrefix;
            $wgProfile['interface_name'] = 'lw-cfwg-' . (int) ($config['forward_id'] ?? 0);
        }
        $runtimeCaps = $wgProfile !== null ? ['bond', 'mptcp', 'wireguard'] : ['bond', 'mptcp'];
        $probeTransport = $wgProfile !== null ? 'private_wg' : 'cf_direct_wss';
        if ($wantCfWg && $wgProfile !== null) {
            $probeTransport = $wgProfile !== null ? 'cf_wg' : 'cf_direct_wss';
        }

        return [
            'candidate_kind' => 'self_leg',
            'session_id' => (int) ($deviceSession['id'] ?? 0),
            'device_id' => $deviceId,
            // Regression sentinel: 'runtime_capabilities' => ['bond', 'mptcp', 'wireguard']
            'runtime_capabilities' => $runtimeCaps,
            'self_leg_scope' => 'device_session',
            'self_leg_role' => 'latency_anchor',
            // Regression sentinel: 'probe_transport' => 'private_wg'
            'probe_transport' => $probeTransport,
            'dial_host' => $cfTarget['dial_host'],
            'dial_connect_ip' => (string) ($cfTarget['dial_connect_ip'] ?? ''),
            'cf_path_prefix' => $cfWgPathPrefix ?? null,
            'cf_sni_host' => (string) ($cfTarget['cf_sni_host'] ?? ''),
            'self_leg_cf_candidates' => array_values(array_filter(array_map(
                static fn(mixed $value): string => trim((string) $value),
                (array) ($cfTarget['self_leg_cf_candidates'] ?? [])
            ), static fn(string $value): bool => $value !== '')),
            'dial_port' => $cfTarget['dial_port'],
            'cf_sni_host' => $cfTarget['cf_sni_host'],
            'path_prefix' => (string) ($config['path_prefix'] ?? ''),
            'ingress_auth_token' => '',
            'target_sidecar_host' => $cfTarget['target_sidecar_host'],
            'target_sidecar_port' => $cfTarget['target_sidecar_port'],
            'target_sidecar_bond_port' => (int) ($config['bond_sidecar_port'] ?? $config['bond_remote_port'] ?? 0),
            'target_sidecar_mptcp_port' => (int) ($config['mptcp_sidecar_port'] ?? $config['bond_remote_port'] ?? 0),
            'self_leg_wg' => $wgProfile,
            'self_leg_mptcp_solo_allowed' => (string) ($config['runtime_preference'] ?? '') === 'mptcp_preferred_bond_fallback',
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $client
     * @param array<string, mixed>|null $deviceSession
     * @return array<string, mixed>|null
     */
    private function buildSelfLegWireGuardProfile(array $config, array $client, ?array $deviceSession): ?array
    {
        $clientConfig = $this->decodeJsonAssoc($client['agent_config_json'] ?? null);
        $sidecarConfig = $this->decodeJsonAssoc($config['bond_sidecar_point_agent_config_json'] ?? null);
        $clientPublicKey = trim((string) ($clientConfig['wireguard_public_key'] ?? ''));
        $sidecarPublicKey = trim((string) ($sidecarConfig['wireguard_public_key'] ?? ''));
        if ($clientPublicKey === '' || $sidecarPublicKey === '') {
            return null;
        }
        $forwardId = (int) ($config['forward_id'] ?? $config['id'] ?? 0);
        $clientId = (int) ($client['id'] ?? $config['line_client_id'] ?? 0);
        if ($forwardId <= 0 || $clientId <= 0) {
            return null;
        }
        $addresses = $this->selfLegWireGuardAddressing($forwardId, $clientId);
        $sidecarListenPort = (int) ($sidecarConfig['wireguard_listen_port'] ?? 0);
        if ($sidecarListenPort <= 0) {
            $sidecarListenPort = (int) ($config['mptcp_sidecar_port'] ?? $config['bond_remote_port'] ?? 0);
        }
        $endpointHost = trim((string) ($config['bond_sidecar_host'] ?? $config['bond_remote_host'] ?? ''));
        if ($endpointHost === '' || $sidecarListenPort <= 0) {
            return null;
        }
        $fallbackPort = (int) ($sidecarConfig['wireguard_fallback_tcp_port'] ?? 0);
        if ($fallbackPort <= 0 && $sidecarListenPort > 0) {
            $fallbackPort = $sidecarListenPort + 10000;
        }
        return [
            'enabled' => true,
            'mode' => 'client_self_leg',
            'interface_name' => 'lw-self-' . $forwardId,
            'forward_id' => $forwardId,
            'client_id' => $clientId,
            'session_id' => (int) ($deviceSession['id'] ?? 0),
            'client_address' => $addresses['client_address'],
            'sidecar_address' => $addresses['sidecar_address'],
            'client_public_key' => $clientPublicKey,
            'sidecar_public_key' => $sidecarPublicKey,
            'endpoint_host' => $endpointHost,
            'endpoint_port' => $sidecarListenPort,
            'fallback_tcp_port' => $fallbackPort,
            'persistent_keepalive' => max(0, (int) ($this->config->getInt('SELF_LEG_WG_KEEPALIVE_SECONDS', 25) ?? 25)),
            'subnet' => $addresses['subnet'],
        ];
    }

    /**
     * @return array{subnet:string,client_address:string,sidecar_address:string}
     */
    private function selfLegWireGuardAddressing(int $forwardId, int $clientId): array
    {
        $stableId = max(1, ($forwardId * 1024) + $clientId);
        $bucket = intdiv($stableId - 1, 60);
        $networkBase = (($stableId - 1) % 60) * 4;
        $octet3 = 1 + ($bucket % 250);
        return [
            'subnet' => '10.204.' . $octet3 . '.' . $networkBase . '/30',
            'client_address' => '10.204.' . $octet3 . '.' . ($networkBase + 1),
            'sidecar_address' => '10.204.' . $octet3 . '.' . ($networkBase + 2),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function helperGroupOrderForConfig(array $config, string $groupKey): int
    {
        if ($groupKey === '') {
            return 999999;
        }
        foreach ((array) ($config['forward_combination_helper_node_group_keys'] ?? []) as $index => $value) {
            if ($groupKey === trim((string) $value)) {
                return (int) $index;
            }
        }
        return 999999;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableClientGroupsForService(int $serviceId): array
    {
        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id LIMIT 1', ['id' => $serviceId]);
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }

        $planId = (int) ($service['plan_id'] ?? 0);
        $groups = $this->listClientGroups();
        if ($planId > 0) {
            $explicit = $this->allowedClientGroupsForPlan($planId);
            if ($explicit !== []) {
                $groups = $explicit;
            }
        }

        return array_values(array_filter($groups, static fn(array $group): bool => (int) ($group['client_count'] ?? 0) > 0));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableForwardChainsForService(int $serviceId): array
    {
        $chains = $this->listForwardChains();
        return array_values(array_filter($chains, static fn(array $chain): bool => (int) ($chain['is_active'] ?? 0) === 1));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateForward(int $forwardId, array $payload, string $actor = 'user', ?int $expectedRevision = null, ?array $mutationPolicy = null): array
    {
        return $this->db->transaction(function () use ($forwardId, $payload, $actor, $expectedRevision, $mutationPolicy): array {
            $forward = $this->db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $forwardId]);
            if ($forward === null) {
                throw new ApiException('forward_not_found', 404);
            }
            $this->lockForwardMutationService((int) $forward['service_id'], $mutationPolicy, 'forward_not_found');
            $this->assertExpectedForwardRevision($forward, $expectedRevision);
            $this->revokeForwardEndpoints($forwardId, 'update');
            return $this->saveForward((int) $forward['service_id'], $forwardId, $payload, $actor);
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateForwardNonStructural(int $forwardId, array $payload, string $actor = 'user', ?int $expectedRevision = null, ?array $mutationPolicy = null): array
    {
        return $this->db->transaction(function () use ($forwardId, $payload, $actor, $expectedRevision, $mutationPolicy): array {
            $forward = $this->db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $forwardId]);
            if ($forward === null) {
                throw new ApiException('forward_not_found', 404);
            }
            $this->lockForwardMutationService((int) $forward['service_id'], $mutationPolicy, 'forward_not_found');
            $this->assertExpectedForwardRevision($forward, $expectedRevision);

            $listenPort = max(0, (int) ($payload['listen_port'] ?? $forward['listen_port'] ?? 0));
            $targetHost = trim((string) ($payload['target_host'] ?? $forward['target_host'] ?? ''));
            $targetPort = max(0, (int) ($payload['target_port'] ?? $forward['target_port'] ?? 0));
            $transportMode = match (strtolower(trim((string) ($payload['transport_mode'] ?? $forward['transport_mode'] ?? 'tcp')))) {
                'udp' => 'udp',
                'tcp+udp', 'udp+tcp', 'both' => 'tcp+udp',
                default => 'tcp',
            };

            if ($listenPort <= 0 || $targetHost === '' || $targetPort <= 0) {
                throw new ApiException('invalid_forward_payload', 422, ['payload' => $payload]);
            }
            [$targetHost, $targetPort, $listenPort] = $this->validateForwardMutationInputs(
                $targetHost,
                $targetPort,
                $listenPort,
                false
            );

            $nextRevision = max(1, (int) ($forward['api_revision'] ?? 1)) + 1;
            $this->db->execute(
            'UPDATE forwards
             SET listen_port = :listen_port,
                 target_host = :target_host,
                 target_port = :target_port,
                 transport_mode = :transport_mode,
                 api_revision = :api_revision,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $forwardId,
                'listen_port' => $listenPort,
                'target_host' => $targetHost,
                'target_port' => $targetPort,
                'transport_mode' => $transportMode,
                'api_revision' => $nextRevision,
                'updated_at' => $this->now(),
            ]
            );

            $this->audit('forward.updated', [
                'forward_id' => $forwardId,
                'service_id' => (int) ($forward['service_id'] ?? 0),
                'actor' => $actor,
                'scope' => 'non_structural',
                'revision' => $nextRevision,
            ]);

            $this->revokeForwardEndpoints($forwardId, 'update');
            $this->reconcileForwardEndpoints($forwardId);
            if ((int) ($forward['line_client_id'] ?? 0) > 0) {
                $this->bumpClientConfigRevision((int) $forward['line_client_id']);
            }

            return $this->buildClientConfig($forwardId);
        });
    }

    public function deleteForward(int $forwardId, string $actor = 'user', ?int $expectedRevision = null, ?array $mutationPolicy = null): void
    {
        $this->db->transaction(function () use ($forwardId, $actor, $expectedRevision, $mutationPolicy): void {
            $forward = $this->db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $forwardId]);
            if ($forward === null) {
                throw new ApiException('forward_not_found', 404);
            }
            $this->lockForwardMutationService((int) $forward['service_id'], $mutationPolicy, 'forward_not_found');
            $this->assertExpectedForwardRevision($forward, $expectedRevision);
            $nextRevision = max(1, (int) ($forward['api_revision'] ?? 1)) + 1;

            $this->revokeForwardEndpoints($forwardId, 'delete');
            $now = $this->now();
            $this->db->execute(
                'UPDATE forwards
                 SET enabled = 0,
                     user_deleted_at = :user_deleted_at,
                     balance_suspended_at = NULL,
                     api_revision = :api_revision,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'id' => $forwardId,
                    'user_deleted_at' => $now,
                    'api_revision' => $nextRevision,
                    'updated_at' => $now,
                ]
            );
            $this->audit('forward.deleted', [
                'forward_id' => $forwardId,
                'service_id' => (int) ($forward['service_id'] ?? 0),
                'actor' => $actor,
                'revision' => $nextRevision,
            ]);
            if ((int) ($forward['line_client_id'] ?? 0) > 0) {
                $this->bumpClientConfigRevision((int) $forward['line_client_id']);
            }
        });
    }

    public function reactivateEligibleForwardsForUser(int $userId, int $activeForwardLimit): int
    {
        if ($userId <= 0) {
            throw new ApiException('user_auth_failed', 401);
        }
        $limit = max(1, min(100000, $activeForwardLimit));

        return $this->db->transaction(function () use ($userId, $limit): int {
            $user = $this->db->fetchOne(
                'SELECT id, role, status FROM users WHERE id = :id LIMIT 1',
                ['id' => $userId]
            );
            if ($user === null
                || (string) ($user['role'] ?? '') !== 'tenant'
                || (string) ($user['status'] ?? '') !== 'active') {
                throw new ApiException('user_auth_failed', 401);
            }

            $services = $this->db->fetchAll(
                'SELECT id
                 FROM services
                 WHERE user_id = :user_id AND status = :status
                 ORDER BY id ASC',
                ['user_id' => $userId, 'status' => 'active']
            );
            $reactivated = 0;
            foreach ($services as $serviceRow) {
                $serviceId = (int) ($serviceRow['id'] ?? 0);
                if ($serviceId <= 0) {
                    continue;
                }
                try {
                    $lockedService = $this->lockForwardMutationService(
                        $serviceId,
                        ['user_id' => $userId, 'feature' => 'allow_manage_forwards'],
                        'service_not_found'
                    );
                    if ((float) ($lockedService['user_wallet_balance'] ?? 0) <= 0) {
                        throw new ApiException('insufficient_balance', 409);
                    }
                } catch (ApiException $exception) {
                    if ($exception->getMessage() === 'feature_denied_manage_forwards') {
                        continue;
                    }
                    throw $exception;
                }

                $active = $this->db->fetchOne(
                    'SELECT COUNT(*) AS active_count
                     FROM forwards
                     WHERE service_id = :service_id AND enabled = 1',
                    ['service_id' => $serviceId]
                );
                $remaining = max(0, $limit - (int) ($active['active_count'] ?? 0));
                if ($remaining === 0) {
                    continue;
                }

                $candidates = $this->db->fetchAll(
                    'SELECT id, line_client_id
                     FROM forwards
                     WHERE service_id = :service_id
                       AND enabled = 0
                       AND user_deleted_at IS NULL
                       AND balance_suspended_at IS NOT NULL
                     ORDER BY id ASC
                     LIMIT ' . $remaining . ' FOR UPDATE',
                    ['service_id' => $serviceId]
                );
                foreach ($candidates as $candidate) {
                    $forwardId = (int) ($candidate['id'] ?? 0);
                    if ($forwardId <= 0) {
                        continue;
                    }
                    $updated = $this->db->execute(
                        'UPDATE forwards
                          SET enabled = 1,
                              balance_suspended_at = NULL,
                              api_revision = GREATEST(1, api_revision) + 1,
                             updated_at = :updated_at
                         WHERE id = :id
                            AND enabled = 0
                            AND user_deleted_at IS NULL
                            AND balance_suspended_at IS NOT NULL',
                        ['id' => $forwardId, 'updated_at' => $this->now()]
                    );
                    if ($updated <= 0) {
                        continue;
                    }
                    $this->audit('forward.reactivated', [
                        'forward_id' => $forwardId,
                        'service_id' => $serviceId,
                        'actor' => 'tenant:' . $userId,
                    ]);
                    $this->reconcileForwardEndpoints($forwardId);
                    if ((int) ($candidate['line_client_id'] ?? 0) > 0) {
                        $this->bumpClientConfigRevision((int) $candidate['line_client_id']);
                    }
                    $reactivated++;
                }
            }

            return $reactivated;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function regenerateClientToken(int $serviceId): array
    {
        $token = bin2hex(random_bytes(24));
        $this->db->execute('UPDATE services SET client_token = :token, updated_at = :updated_at WHERE id = :id', [
            'id' => $serviceId,
            'token' => $token,
            'updated_at' => $this->now(),
        ]);
        $this->audit('service.client_token_rotated', ['service_id' => $serviceId]);
        return [
            'service_id' => $serviceId,
            'client_token' => $token,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildClientConfig(int $forwardId): array
    {
        $forward = $this->db->fetchOne(
             'SELECT f.*, s.client_token, s.status AS service_status, s.name AS service_name,
                     s.bandwidth_limit_mbps AS service_bandwidth_limit_mbps,
                     c.name AS line_client_name, c.display_host AS line_client_host, c.speed_limit_mbps AS line_speed_limit_mbps,
                     c.agent_config_json AS line_client_agent_config_json,
                     cg.name AS line_client_group_name,
                     fc.name AS forward_chain_name,
                     sp.name AS bond_sidecar_point_name,
                     combo.name AS forward_combination_name,
                     combo.tenant_label AS forward_combination_label,
                     combo.runtime_preference AS forward_combination_runtime_preference,
                     combo.allow_private_self_leg AS forward_combination_allow_private_self_leg,
                     combo.self_leg_scope AS forward_combination_self_leg_scope,
                     combo.self_leg_role AS forward_combination_self_leg_role,
                     combo.helper_group_cap AS forward_combination_helper_group_cap,
                     combo.helper_escalation_policy AS forward_combination_helper_escalation_policy,
                     combo.self_leg_cf_transport AS forward_combination_self_leg_cf_transport,
                     combo.desired_latency_ms AS forward_combination_desired_latency_ms,
                     combo.allow_public_self_leg_donation AS forward_combination_allow_public_self_leg_donation,
                     combo.donation_qualify_window_seconds AS forward_combination_donation_qualify_window_seconds,
                     combo.donation_revoke_window_seconds AS forward_combination_donation_revoke_window_seconds,
                     combo.donation_min_open_ports AS forward_combination_donation_min_open_ports,
                     f.backend_mode,
                     f.forward_combination_id,
                     f.cf_location_group_id,
                     sp.sidecar_host AS bond_sidecar_host,
                     sp.listen_port AS bond_sidecar_legacy_port,
                     sp.bond_listen_port AS bond_sidecar_port,
                     sp.mptcp_listen_port AS mptcp_sidecar_port,
                     sp.cf_listen_port AS bond_sidecar_cf_listen_port,
                     sp.token_secret AS bond_sidecar_point_token_secret,
                     sp.agent_config_json AS bond_sidecar_point_agent_config_json
              FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             LEFT JOIN clients c ON c.id = f.line_client_id
             LEFT JOIN client_groups cg ON cg.id = f.line_client_group_id
             LEFT JOIN forward_chains fc ON fc.id = f.forward_chain_id
             LEFT JOIN sidecar_points sp ON sp.id = f.bond_sidecar_point_id
             LEFT JOIN forward_combinations combo ON combo.id = f.forward_combination_id
             WHERE f.id = :id
               AND f.enabled = 1
               AND s.status = :active_status
             LIMIT 1',
            ['id' => $forwardId, 'active_status' => 'active']
        );

        if ($forward === null) {
            throw new ApiException('forward_not_found', 404);
        }

        $selectedClientGroupIds = [];
        if (is_string($forward['line_client_group_ids_json'] ?? null) && $forward['line_client_group_ids_json'] !== '') {
            $decoded = json_decode((string) $forward['line_client_group_ids_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $value) {
                    $casted = (int) $value;
                    if ($casted > 0) {
                        $selectedClientGroupIds[] = $casted;
                    }
                }
            }
        }
        $selectedClientGroups = [];
        if ($selectedClientGroupIds !== []) {
            $selectedClientGroups = $this->db->fetchAll(
                'SELECT id, group_key, name
                 FROM client_groups
                 WHERE id IN (' . implode(',', array_map('intval', $selectedClientGroupIds)) . ')
                 ORDER BY name ASC, id ASC'
            );
        }

        $endpoints = $this->db->fetchAll(
            'SELECT e.*, n.name AS node_name, n.public_ip AS node_public_ip, n.agent_config_json AS node_agent_config_json, n.agent_version AS node_agent_version
             FROM service_endpoints e
             INNER JOIN nodes n ON n.id = e.node_id
             WHERE e.forward_id = :forward_id
               AND e.health_status <> :deleted
               AND e.health_status <> :deleting
             ORDER BY e.priority ASC, e.id ASC',
            [
                'forward_id' => $forwardId,
                'deleted' => 'deleted',
                'deleting' => 'deleting',
            ]
        );

        $clientMode = $this->normalizeClientMode((string) $forward['client_mode']);
        $bondIngressMode = $this->normalizeBondIngressMode((string) ($forward['bond_ingress_mode'] ?? 'wss_path_mux'));
        $runtimePreference = trim((string) ($forward['forward_combination_runtime_preference'] ?? 'single'));
        $backendMode = (string) ($forward['backend_mode'] ?? 'current_mode');
        $fixedEndpointLeigodBond = $this->usesFixedEndpointLeigodBond($forward);
        $serverSideLeigodBond = $this->usesServerSideLeigodBond($forward);
        $fixedEndpointCandidates = [];
        if ($fixedEndpointLeigodBond) {
            $specificNodeId = $this->specificNodeIdFromCustomEndpointIds($forward['custom_endpoint_ids_json'] ?? null);
            $endpoints = array_values(array_filter(
                $endpoints,
                static fn(array $endpoint): bool => (int) ($endpoint['node_id'] ?? 0) === $specificNodeId
            ));
            if (count($endpoints) !== 1) {
                throw new ApiException('leigod_fixed_endpoint_unavailable', 409, [
                    'node_id' => $specificNodeId,
                    'endpoint_count' => count($endpoints),
                ]);
            }
            $fixedEndpointCandidates = $this->leigodLineCatalog()->candidatesForForwardOnNode(
                $forward,
                (int) $specificNodeId
            );
            if (count($fixedEndpointCandidates) < 2 || count($fixedEndpointCandidates) > 8) {
                throw new ApiException('leigod_fixed_endpoint_candidate_count_invalid', 409, [
                    'node_id' => $specificNodeId,
                    'candidate_count' => count($fixedEndpointCandidates),
                ]);
            }
        }
        $aggregationEnabled = $this->combinationUsesAggregation($clientMode, $runtimePreference);
        $sidecarFrontend = $this->usesSingleRaceSidecarFrontend($forward);
        $sidecarRequired = $sidecarFrontend
            || $this->sidecarRequiredForRuntime($backendMode, $clientMode, $runtimePreference);
        $dedicatedLeigod = $backendMode === 'leigod_mode' && $clientMode === 'single' && !$sidecarRequired;
        $activeFamily = $sidecarFrontend
            ? 'sidecar_aggregation'
            : $this->activeFamilyForRuntime($backendMode, $clientMode, $runtimePreference);
        $targetContract = $sidecarFrontend
            ? 'sidecar_aggregation'
            : $this->targetContractForRuntime($backendMode, $clientMode, $runtimePreference);
        $publishesBondRuntime = $clientMode === 'bond' || $runtimePreference === 'mptcp_preferred_bond_fallback';
        $publishesBondRuntime = $aggregationEnabled && $publishesBondRuntime;
        $publishesBondRuntime = $publishesBondRuntime || $sidecarFrontend;
        $pointConfig = $this->decodeJsonAssoc($forward['bond_sidecar_point_agent_config_json'] ?? null);
        $pointPortSource = $sidecarRequired ? [
            'listen_port' => (int) ($forward['bond_sidecar_legacy_port'] ?? 0),
            'bond_listen_port' => (int) ($forward['bond_sidecar_port'] ?? 0),
            'mptcp_listen_port' => (int) ($forward['mptcp_sidecar_port'] ?? 0),
        ] : [
            'listen_port' => 0,
            'bond_listen_port' => 0,
            'mptcp_listen_port' => 0,
        ];
        $effectiveBondSidecarPort = $this->sidecarBondListenPort($pointPortSource);
        $effectiveMptcpSidecarPort = $this->sidecarMptcpListenPort($pointPortSource, $pointConfig);
        $effectiveSidecarTarget = $sidecarRequired && ($publishesBondRuntime || $clientMode === 'mptcp')
            ? $this->resolveSidecarDirectTarget(
                array_merge($forward, [
                    'bond_sidecar_port' => $effectiveBondSidecarPort,
                    'mptcp_sidecar_port' => $effectiveMptcpSidecarPort,
                ]),
                $publishesBondRuntime ? 'bond' : $clientMode
            )
            : [
                'host' => '',
                'port' => 0,
            ];
        $ingressGroup = $this->nodeGroupByKey((string) ($forward['node_group_key'] ?? ''));
        $resolvedSniHost = $this->resolveIngressSniHost((string) ($forward['sni_host'] ?? ''), $ingressGroup);
        $resolvedCdnHost = $this->resolveIngressCdnHost((string) ($forward['cdn_host'] ?? ''), $resolvedSniHost, $ingressGroup);
        $bondConfig = $publishesBondRuntime
            ? $this->buildBondClientConfig(
                array_merge($forward, [
                    'bond_ingress_mode' => $bondIngressMode,
                    'fixed_endpoint_leigod_candidates' => $fixedEndpointCandidates,
                ]),
                $endpoints
            )
            : null;
        if ($serverSideLeigodBond && is_array($bondConfig)) {
            $bondConfig = array_merge([
                'enabled' => true,
                'fixed_endpoint' => true,
                'fixed_endpoint_legs' => [],
                'ingress_mode' => 'plain_tcp_mux',
                'route_key' => (string) ($forward['path_prefix'] ?? ''),
            ], $this->serverSideLeigodBondPolicy());
        }
        $mptcpConfig = $sidecarRequired && $clientMode === 'mptcp' ? $this->buildMptcpClientConfig($forward, $endpoints) : null;
        $serviceBandwidthLimitMbps = max(0, (int) ($forward['service_bandwidth_limit_mbps'] ?? 0));
        $lineSpeedLimitMbps = max(0, (int) ($forward['line_speed_limit_mbps'] ?? 0));
        $limitCandidates = array_values(array_filter([$serviceBandwidthLimitMbps, $lineSpeedLimitMbps], static fn(int $value): bool => $value > 0));
        $effectiveSpeedLimitMbps = $limitCandidates !== [] ? min($limitCandidates) : null;

        return [
            'service_id' => (int) $forward['service_id'],
            'forward_id' => (int) $forward['id'],
            'client_token' => (string) $forward['client_token'],
            'service_name' => (string) $forward['service_name'],
            'listen_port' => (int) $forward['listen_port'],
            'target' => $forward['target_host'] . ':' . $forward['target_port'],
            'bond_sidecar_point_id' => $sidecarRequired && $forward['bond_sidecar_point_id'] !== null ? (int) $forward['bond_sidecar_point_id'] : null,
            'bond_sidecar_point_name' => $sidecarRequired ? (string) ($forward['bond_sidecar_point_name'] ?? '') : '',
            'forward_combination_id' => $forward['forward_combination_id'] !== null ? (int) $forward['forward_combination_id'] : null,
            'forward_combination_name' => (string) ($forward['forward_combination_name'] ?? ''),
            'forward_combination_label' => (string) ($forward['forward_combination_label'] ?? ''),
            'forward_combination_runtime_preference' => $runtimePreference,
            'forward_combination_allow_private_self_leg' => (int) ($forward['forward_combination_allow_private_self_leg'] ?? 0),
            'forward_combination_self_leg_scope' => (string) ($forward['forward_combination_self_leg_scope'] ?? 'none'),
            'forward_combination_self_leg_role' => (string) ($forward['forward_combination_self_leg_role'] ?? 'none'),
            'forward_combination_helper_group_cap' => (int) ($forward['forward_combination_helper_group_cap'] ?? 0),
            'forward_combination_helper_escalation_policy' => (string) ($forward['forward_combination_helper_escalation_policy'] ?? 'disabled'),
            'forward_combination_self_leg_cf_transport' => (string) ($forward['forward_combination_self_leg_cf_transport'] ?? 'cf_direct'),
            'desired_latency_ms' => (int) ($forward['forward_combination_desired_latency_ms'] ?? 0),
            'allow_public_self_leg_donation' => (int) ($forward['forward_combination_allow_public_self_leg_donation'] ?? 0),
            'donation_qualify_window_seconds' => (int) ($forward['forward_combination_donation_qualify_window_seconds'] ?? 60),
            'donation_revoke_window_seconds' => (int) ($forward['forward_combination_donation_revoke_window_seconds'] ?? 30),
            'donation_min_open_ports' => (int) ($forward['forward_combination_donation_min_open_ports'] ?? 1),
            'forward_combination_helper_node_group_keys' => $this->forwardCombinationHelperNodeGroupKeys((int) ($forward['forward_combination_id'] ?? 0)),
            'contract_version' => 1,
            'backend_mode' => $backendMode,
            'active_family' => $activeFamily,
            'aggregation_enabled' => $aggregationEnabled,
            'target_contract' => $targetContract,
            'sidecar_required' => $sidecarRequired,
            'cf_location_group_id' => $forward['cf_location_group_id'] !== null ? (int) $forward['cf_location_group_id'] : null,
            'bond_sidecar_host' => $sidecarRequired && !$serverSideLeigodBond ? (string) ($forward['bond_sidecar_host'] ?? '') : '',
            'bond_sidecar_port' => $sidecarRequired && !$serverSideLeigodBond && $effectiveBondSidecarPort > 0 ? $effectiveBondSidecarPort : null,
            'mptcp_sidecar_port' => $sidecarRequired && !$serverSideLeigodBond && $effectiveMptcpSidecarPort > 0 ? $effectiveMptcpSidecarPort : null,
            'bond_sidecar_cf_listen_port' => $sidecarRequired && !$serverSideLeigodBond && (int) ($forward['bond_sidecar_cf_listen_port'] ?? 0) > 0 ? (int) ($forward['bond_sidecar_cf_listen_port'] ?? 0) : null,
            'bond_sidecar_cf_sni_host' => $sidecarRequired && !$serverSideLeigodBond ? (string) ($pointConfig['cf_sni_host'] ?? $pointConfig['cf_sni'] ?? '') : '',
            'bond_sidecar_approved_upstream_realizations' => $sidecarRequired && !$serverSideLeigodBond ? $this->approvedCfUpstreamRealizations($pointConfig) : [],
            'bond_sidecar_point_agent_config_json' => $sidecarRequired && !$serverSideLeigodBond ? (string) ($forward['bond_sidecar_point_agent_config_json'] ?? '') : '',
            'line_client_agent_config_json' => (string) ($forward['line_client_agent_config_json'] ?? ''),
            'bond_remote_host' => $serverSideLeigodBond ? '' : (string) ($effectiveSidecarTarget['host'] ?? $forward['bond_remote_host'] ?? ''),
            'bond_remote_port' => !$serverSideLeigodBond && (int) ($effectiveSidecarTarget['port'] ?? $forward['bond_remote_port'] ?? 0) > 0 ? (int) ($effectiveSidecarTarget['port'] ?? $forward['bond_remote_port'] ?? 0) : null,
            'line_client_id' => $forward['line_client_id'] !== null ? (int) $forward['line_client_id'] : null,
            'line_client_name' => $forward['line_client_name'],
            'line_client_host' => $forward['line_client_host'],
            'line_client_group_id' => $forward['line_client_group_id'] !== null ? (int) $forward['line_client_group_id'] : null,
            'line_client_group_name' => $forward['line_client_group_name'],
            'line_group_mode' => (string) ($forward['line_group_mode'] ?? 'selected'),
            'line_client_group_ids' => array_values(array_map(static fn(array $group): int => (int) $group['id'], $selectedClientGroups)),
            'line_client_groups' => array_map(static fn(array $group): array => [
                'id' => (int) $group['id'],
                'group_key' => (string) $group['group_key'],
                'name' => (string) $group['name'],
            ], $selectedClientGroups),
            'endpoint_mode' => (string) $forward['endpoint_mode'],
            'forward_chain_id' => $forward['forward_chain_id'] !== null ? (int) $forward['forward_chain_id'] : null,
            'forward_chain_name' => $forward['forward_chain_name'],
            'sni' => $resolvedSniHost,
            'path_prefix' => (string) $forward['path_prefix'],
            'mode' => $clientMode,
            'transport' => $this->effectiveMptcpTransport($forward),
            'service_status' => (string) $forward['service_status'],
            'speed_limit_mbps' => $effectiveSpeedLimitMbps,
            'bonding' => $bondConfig,
            'mptcp' => $mptcpConfig,
            'connection_policy' => [
                'idle_pool' => ($clientMode === 'race' || $clientMode === 'bond' || $clientMode === 'mptcp') ? 1 : 5,
                'tcp_race' => $clientMode === 'race',
                'tcp_bond' => $clientMode === 'bond',
                'tcp_mptcp' => $clientMode === 'mptcp',
                'udp_fanout' => !in_array($clientMode, ['bond', 'mptcp'], true) && ((string) $forward['transport_mode']) === 'tcp+udp',
                'dup_repeat' => 1,
            ],
            'endpoints' => array_map(function (array $endpoint) use ($resolvedSniHost, $resolvedCdnHost, $ingressGroup, $clientMode, $bondIngressMode, $publishesBondRuntime, $dedicatedLeigod, $fixedEndpointLeigodBond, $serverSideLeigodBond, $forward): array {
                $nodeConfig = is_string($endpoint['node_agent_config_json'] ?? null) ? json_decode((string) $endpoint['node_agent_config_json'], true) : [];
                if (!is_array($nodeConfig)) {
                    $nodeConfig = [];
                }
                $item = [
                    'endpoint_id' => (int) $endpoint['id'],
                    'node' => (string) $endpoint['node_name'],
                    'host' => $this->resolveEndpointPublicHost($endpoint),
                    'port' => (int) $endpoint['public_port'],
                    'cdn_host' => $this->resolveIngressCdnHost((string) ($endpoint['cdn_host'] ?? ''), $resolvedSniHost, $ingressGroup) ?: $resolvedCdnHost,
                    'priority' => (int) $endpoint['priority'],
                    'weight' => (int) $endpoint['weight'],
                    'health_status' => (string) $endpoint['health_status'],
                    'supports_quic_leg' => !$fixedEndpointLeigodBond
                        && $this->nodeSupportsBondQuic($nodeConfig, (string) ($endpoint['node_agent_version'] ?? '')),
                    'origin_group_key' => (string) ($endpoint['origin_group_key'] ?? $endpoint['node_group_key'] ?? $endpoint['group_key'] ?? ''),
                    'last_chain_latency_ms' => $this->nullableFloat($endpoint['last_chain_latency_ms'] ?? $endpoint['best_latency_ms'] ?? null),
                    'delivered_ewma' => $this->nullableFloat($endpoint['delivered_ewma'] ?? null),
                ];
                if ($publishesBondRuntime && $bondIngressMode === 'plain_tcp_mux') {
                    $item['ingress_protocol'] = 'plain_tcp_mux';
                    $item['ingress_auth_token'] = $this->bondIngressAuthToken($forward, (int) $endpoint['id'], (string) ($forward['path_prefix'] ?? ''));
                }
                if (($dedicatedLeigod || $fixedEndpointLeigodBond) && !$serverSideLeigodBond) {
                    $item['runtime_target'] = '127.0.0.1:' . $this->stableLeigodRelayPort(
                        (int) ($forward['id'] ?? 0),
                        (int) ($endpoint['id'] ?? 0)
                    );
                }
                return $item;
            }, $endpoints),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    public function applyUsageReport(array $node, array $entries): array
    {
        $applied = [];
        $suspended = [];

        foreach ($entries as $entry) {
            $serviceId = (int) ($entry['service_id'] ?? 0);
            $endpointId = (int) ($entry['endpoint_id'] ?? 0);
            $bytesIn = max(0, (int) ($entry['bytes_in'] ?? 0));
            $bytesOut = max(0, (int) ($entry['bytes_out'] ?? 0));
            if ($serviceId <= 0 || $endpointId <= 0) {
                continue;
            }

            $endpoint = $this->db->fetchOne(
                'SELECT * FROM service_endpoints WHERE id = :id AND node_id = :node_id LIMIT 1',
                ['id' => $endpointId, 'node_id' => $node['id']]
            );
            if ($endpoint === null) {
                continue;
            }

            $periodStart = gmdate('Y-m-d H:00:00');
            $existing = $this->db->fetchOne(
                'SELECT * FROM traffic_usage_hourly WHERE service_id = :service_id AND endpoint_id = :endpoint_id AND period_start = :period_start LIMIT 1',
                [
                    'service_id' => $serviceId,
                    'endpoint_id' => $endpointId,
                    'period_start' => $periodStart,
                ]
            );

            if ($existing === null) {
                $this->db->insert('traffic_usage_hourly', [
                    'service_id' => $serviceId,
                    'endpoint_id' => $endpointId,
                    'node_id' => $node['id'],
                    'period_start' => $periodStart,
                    'bytes_in' => $bytesIn,
                    'bytes_out' => $bytesOut,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
            } else {
                $this->db->execute(
                    'UPDATE traffic_usage_hourly
                     SET bytes_in = bytes_in + :bytes_in,
                         bytes_out = bytes_out + :bytes_out,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => $existing['id'],
                        'bytes_in' => $bytesIn,
                        'bytes_out' => $bytesOut,
                        'updated_at' => $this->now(),
                    ]
                );
            }

            $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id LIMIT 1', ['id' => $serviceId]);
            if ($service === null) {
                continue;
            }

            $service = $this->syncServiceBillingCycle($service);
            $delta = $bytesIn + $bytesOut;

            $this->db->execute(
                'UPDATE services
                 SET traffic_used_bytes = traffic_used_bytes + :delta,
                     billing_cycle_traffic_used_bytes = billing_cycle_traffic_used_bytes + :delta,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'id' => $serviceId,
                    'delta' => $delta,
                    'updated_at' => $this->now(),
                ]
            );

            $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id LIMIT 1', ['id' => $serviceId]);
            if ($service !== null && $this->normalizeBillingMode((string) ($service['billing_mode'] ?? 'monthly_bundle')) === 'monthly_bundle') {
                $includedGb = max(0, (int) ($service['monthly_included_traffic_gb'] ?? $service['traffic_limit_gb'] ?? 0));
                if ($includedGb > 0) {
                    $limitBytes = $includedGb * 1024 * 1024 * 1024;
                    if ((int) ($service['billing_cycle_traffic_used_bytes'] ?? 0) >= $limitBytes && (string) $service['status'] === 'active') {
                        $this->db->execute(
                            'UPDATE services
                             SET status = :status,
                                 suspend_reason = :reason,
                                 updated_at = :updated_at
                             WHERE id = :id',
                            [
                                'id' => $serviceId,
                                'status' => 'traffic_suspended',
                                'reason' => 'traffic_limit',
                                'updated_at' => $this->now(),
                            ]
                        );
                        $this->queueLifecycleTasks($serviceId, 'suspend');
                        $suspended[] = $serviceId;
                    }
                }
            }

            $applied[] = [
                'service_id' => $serviceId,
                'endpoint_id' => $endpointId,
                'bytes_in' => $bytesIn,
                'bytes_out' => $bytesOut,
            ];
        }

        return [
            'applied' => $applied,
            'suspended_services' => array_values(array_unique($suspended)),
        ];
    }

    public function recordAgentUsage(string $entityType, int $entityId, int $bytesIn, int $bytesOut): void
    {
        $entityType = strtolower(trim($entityType));
        if (!in_array($entityType, ['client', 'sidecar'], true) || $entityId <= 0) {
            return;
        }

        $bytesIn = max(0, $bytesIn);
        $bytesOut = max(0, $bytesOut);
        if ($bytesIn === 0 && $bytesOut === 0) {
            return;
        }

        $periodStart = gmdate('Y-m-d H:00:00');
        $existing = $this->db->fetchOne(
            'SELECT id
             FROM agent_usage_hourly
             WHERE entity_type = :entity_type
               AND entity_id = :entity_id
               AND period_start = :period_start
             LIMIT 1',
            [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'period_start' => $periodStart,
            ]
        );

        if ($existing === null) {
            $this->db->insert('agent_usage_hourly', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'period_start' => $periodStart,
                'bytes_in' => $bytesIn,
                'bytes_out' => $bytesOut,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
            return;
        }

        $this->db->execute(
            'UPDATE agent_usage_hourly
             SET bytes_in = bytes_in + :bytes_in,
                 bytes_out = bytes_out + :bytes_out,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $existing['id'],
                'bytes_in' => $bytesIn,
                'bytes_out' => $bytesOut,
                'updated_at' => $this->now(),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function serviceSummary(int $serviceId): array
    {
        $service = $this->db->fetchOne(
            'SELECT s.*, u.email AS user_email, u.feature_flags_json AS user_feature_flags_json,
                    p.product_code, p.name AS plan_name, p.description AS plan_description, p.feature_flags_json AS plan_feature_flags_json,
                    p.default_bandwidth_limit_mbps AS plan_default_bandwidth_limit_mbps,
                    p.traffic_reset_price AS plan_traffic_reset_price, p.billing_currency AS plan_billing_currency,
                    p.bandwidth_step_mbps AS plan_bandwidth_step_mbps, p.bandwidth_step_price AS plan_bandwidth_step_price,
                    p.included_client_count AS plan_included_client_count, p.extra_client_price AS plan_extra_client_price,
                    (SELECT COUNT(DISTINCT f2.line_client_id)
                     FROM forwards f2
                     WHERE f2.service_id = s.id
                       AND f2.enabled = 1
                       AND f2.line_client_id IS NOT NULL) AS active_client_count
             FROM services s
             INNER JOIN users u ON u.id = s.user_id
             LEFT JOIN service_plans p ON p.id = s.plan_id
             WHERE s.id = :id LIMIT 1',
            ['id' => $serviceId]
        );
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }
        $service = $this->syncServiceBillingCycle($service);

        $forwards = $this->db->fetchAll(
            'SELECT f.*,
                    c.name AS line_client_name,
                    cg.name AS line_client_group_name,
                    fc.name AS forward_chain_name,
                    sp.name AS bond_sidecar_point_name,
                    combo.name AS forward_combination_name,
                    combo.tenant_label AS forward_combination_label
             FROM forwards f
             LEFT JOIN clients c ON c.id = f.line_client_id
             LEFT JOIN client_groups cg ON cg.id = f.line_client_group_id
             LEFT JOIN forward_chains fc ON fc.id = f.forward_chain_id
             LEFT JOIN sidecar_points sp ON sp.id = f.bond_sidecar_point_id
             LEFT JOIN forward_combinations combo ON combo.id = f.forward_combination_id
             WHERE f.service_id = :service_id
               AND f.enabled = 1
             ORDER BY f.id ASC',
            ['service_id' => $serviceId]
        );

        foreach ($forwards as &$forward) {
            $forward['endpoints'] = $this->db->fetchAll(
                'SELECT e.*, n.name AS node_name, n.public_ip AS node_public_ip, n.agent_config_json AS node_agent_config_json
                 FROM service_endpoints e
                 INNER JOIN nodes n ON n.id = e.node_id
                 WHERE e.forward_id = :forward_id
                   AND e.health_status <> :deleted
                   AND e.health_status <> :deleting
                 ORDER BY e.priority ASC, e.id ASC',
                [
                    'forward_id' => $forward['id'],
                    'deleted' => 'deleted',
                    'deleting' => 'deleting',
                ]
            );
            foreach ($forward['endpoints'] as &$endpoint) {
                $endpoint['public_host'] = $this->resolveEndpointPublicHost($endpoint);
            }
            unset($endpoint);
            $forward['line_client_groups'] = [];
            if (is_string($forward['line_client_group_ids_json'] ?? null) && $forward['line_client_group_ids_json'] !== '') {
                $decoded = json_decode((string) $forward['line_client_group_ids_json'], true);
                $groupIds = [];
                if (is_array($decoded)) {
                    foreach ($decoded as $value) {
                        $casted = (int) $value;
                        if ($casted > 0) {
                            $groupIds[] = $casted;
                        }
                    }
                }
                if ($groupIds !== []) {
                    $forward['line_client_groups'] = $this->db->fetchAll(
                        'SELECT id, group_key, name
                         FROM client_groups
                         WHERE id IN (' . implode(',', array_map('intval', array_values(array_unique($groupIds)))) . ')
                         ORDER BY name ASC, id ASC'
                    );
                }
            }
        }
        unset($forward);

        $service['allowed_node_groups'] = $this->availableNodeGroupsForService($serviceId);
        $service['allowed_client_groups'] = $this->availableClientGroupsForService($serviceId);
        $service['available_clients'] = $this->availableClientsForService($serviceId);
        $service['available_sidecar_points'] = $this->listSidecarPointsForUser((int) ($service['user_id'] ?? 0));
        $service['available_forward_combinations'] = $this->availableForwardCombinationsForService($serviceId);
        $service['available_forward_chains'] = $this->availableForwardChainsForService($serviceId);
        $service['effective_features'] = $this->effectiveFeatureFlagsForService($service);
        $service['billing'] = $this->billingOverviewFromService($service);
        $service['billing_history'] = $this->recentBillingCyclesForService($serviceId);
        $service['forwards'] = $forwards;
        return $service;
    }

    /**
     * @return array<string, mixed>
     */
    public function serviceBillingOverview(int $serviceId): array
    {
        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id LIMIT 1', ['id' => $serviceId]);
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }

        return $this->billingOverviewFromService($this->syncServiceBillingCycle($service));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentBillingCyclesForService(int $serviceId, int $limit = 6): array
    {
        $safeLimit = max(1, min(24, $limit));
        return $this->db->fetchAll(
            'SELECT *
             FROM service_billing_cycles
             WHERE service_id = :service_id
             ORDER BY cycle_started_at DESC, id DESC
             LIMIT ' . $safeLimit,
            ['service_id' => $serviceId]
        );
    }

    /**
     * @param array<int, int|string> $nodeIds
     * @return array<int, array<string, mixed>>
     */
    public function nodeUsageOverviewMap(array $nodeIds): array
    {
        return $this->usageOverviewMapFromTable('traffic_usage_hourly', 'node_id', $nodeIds);
    }

    /**
     * @param array<int, int|string> $clientIds
     * @return array<int, array<string, mixed>>
     */
    public function clientUsageOverviewMap(array $clientIds): array
    {
        return $this->usageOverviewMapFromTable('agent_usage_hourly', 'entity_id', $clientIds, 'client');
    }

    /**
     * @param array<int, int|string> $sidecarIds
     * @return array<int, array<string, mixed>>
     */
    public function sidecarUsageOverviewMap(array $sidecarIds): array
    {
        return $this->usageOverviewMapFromTable('agent_usage_hourly', 'entity_id', $sidecarIds, 'sidecar');
    }

    /**
     * @param array<int, int|string> $entityIds
     * @return array<int, array<string, mixed>>
     */
    private function usageOverviewMapFromTable(string $table, string $entityColumn, array $entityIds, ?string $entityType = null): array
    {
        $normalizedIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): int => max(0, (int) $value),
            $entityIds
        ), static fn(int $value): bool => $value > 0)));

        if ($normalizedIds === []) {
            return [];
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
        $monthEnd = $monthStart->modify('first day of next month');
        $dayStart = $now->setTime(0, 0, 0);
        $hourStart = $now->setTime((int) $now->format('H'), 0, 0);

        $overview = [];
        foreach ($normalizedIds as $entityId) {
            $overview[$entityId] = [
                'hour_bytes' => 0,
                'day_bytes' => 0,
                'month_bytes' => 0,
                'month_label' => $monthStart->format('F Y'),
                'month_start' => $monthStart->format('Y-m-d'),
                'month_end' => $monthEnd->format('Y-m-d'),
                'today' => $now->format('Y-m-d'),
                'daily_bytes' => [],
            ];
        }

        $params = [
            'hour_start' => $hourStart->format('Y-m-d H:i:s'),
            'day_start' => $dayStart->format('Y-m-d H:i:s'),
            'month_start' => $monthStart->format('Y-m-d H:i:s'),
            'month_end' => $monthEnd->format('Y-m-d H:i:s'),
        ];
        $inPlaceholders = [];
        foreach ($normalizedIds as $index => $entityId) {
            $key = 'entity_id_' . $index;
            $inPlaceholders[] = ':' . $key;
            $params[$key] = $entityId;
        }
        if ($entityType !== null) {
            $params['entity_type'] = $entityType;
        }
        $summaryParams = $params;
        unset($summaryParams['month_end']);

        $where = [];
        if ($entityType !== null) {
            $where[] = 'entity_type = :entity_type';
        }
        $where[] = $entityColumn . ' IN (' . implode(', ', $inPlaceholders) . ')';
        $where[] = 'period_start >= :month_start';

        $summaryRows = $this->db->fetchAll(
            'SELECT ' . $entityColumn . ' AS entity_id,
                    SUM(CASE WHEN period_start = :hour_start THEN bytes_in + bytes_out ELSE 0 END) AS hour_bytes,
                    SUM(CASE WHEN period_start >= :day_start THEN bytes_in + bytes_out ELSE 0 END) AS day_bytes,
                    SUM(bytes_in + bytes_out) AS month_bytes
             FROM ' . $table . '
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY ' . $entityColumn,
            $summaryParams
        );
        foreach ($summaryRows as $row) {
            $entityId = (int) ($row['entity_id'] ?? 0);
            if (!isset($overview[$entityId])) {
                continue;
            }
            $overview[$entityId]['hour_bytes'] = max(0, (int) ($row['hour_bytes'] ?? 0));
            $overview[$entityId]['day_bytes'] = max(0, (int) ($row['day_bytes'] ?? 0));
            $overview[$entityId]['month_bytes'] = max(0, (int) ($row['month_bytes'] ?? 0));
        }

        $dailyRows = $this->db->fetchAll(
            'SELECT ' . $entityColumn . ' AS entity_id,
                    DATE(period_start) AS day_key,
                    SUM(bytes_in + bytes_out) AS total_bytes
             FROM ' . $table . '
             WHERE ' . implode(' AND ', $where) . '
               AND period_start < :month_end
             GROUP BY ' . $entityColumn . ', DATE(period_start)',
            $params
        );
        foreach ($dailyRows as $row) {
            $entityId = (int) ($row['entity_id'] ?? 0);
            $dayKey = (string) ($row['day_key'] ?? '');
            if ($dayKey === '' || !isset($overview[$entityId])) {
                continue;
            }
            $overview[$entityId]['daily_bytes'][$dayKey] = max(0, (int) ($row['total_bytes'] ?? 0));
        }

        return $overview;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function saveForward(
        int $serviceId,
        ?int $forwardId,
        array $payload,
        string $actor,
        ?string $idempotencyKeyHash = null,
        ?string $requestHash = null
    ): array
    {
        unset($payload['endpoint_count']);

        $existingForward = null;
        if ($forwardId !== null) {
            $existingForward = $this->db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', ['id' => $forwardId]);
            if ($existingForward === null) {
                throw new ApiException('forward_not_found', 404);
            }
        }

        $hasForwardCombinationPayload = array_key_exists('forward_combination_id', $payload);
        $hasForwardRoutePayload = array_key_exists('forward_route_id', $payload);
        $catalogPlacementPayloadProvided = $hasForwardCombinationPayload || $hasForwardRoutePayload;
        $forwardCombinationId = $catalogPlacementPayloadProvided
            ? (int) ($payload['forward_combination_id'] ?? 0)
            : (int) ($existingForward['forward_combination_id'] ?? 0);
        $forwardRouteId = $catalogPlacementPayloadProvided
            ? (int) ($payload['forward_route_id'] ?? 0)
            : (int) ($existingForward['forward_route_id'] ?? 0);
        $catalogPlacementRequested = $forwardCombinationId > 0 || $forwardRouteId > 0;
        $catalogPlacementClearRequested = $catalogPlacementPayloadProvided
            && !$catalogPlacementRequested
            && ((int) ($existingForward['forward_combination_id'] ?? 0) > 0 || (int) ($existingForward['forward_route_id'] ?? 0) > 0);
        $backendMode = trim((string) ($payload['backend_mode'] ?? 'current_mode'));
        $cfLocationGroupId = (int) ($payload['cf_location_group_id'] ?? 0);
        $listenPort = (int) ($payload['listen_port'] ?? 0);
        $targetHost = (string) ($payload['target_host'] ?? '');
        $targetPort = (int) ($payload['target_port'] ?? 0);
        $bondRemoteHost = trim((string) ($payload['bond_remote_host'] ?? $payload['bond_sidecar_host'] ?? ''));
        $bondRemotePort = (int) ($payload['bond_remote_port'] ?? $payload['bond_sidecar_port'] ?? 0);
        $bondIngressMode = $this->normalizeBondIngressMode((string) ($payload['bond_ingress_mode'] ?? 'wss_path_mux'));
        $bondSidecarPointId = (int) ($payload['bond_sidecar_point_id'] ?? 0);
        $transportMode = (string) ($payload['transport_mode'] ?? $this->config->get('DEFAULT_TRANSPORT_MODE', 'tcp+udp'));
        $clientMode = $this->normalizeClientMode((string) ($payload['client_mode'] ?? $this->config->get('DEFAULT_CLIENT_MODE', 'single')));
        $forwardRuntimePreference = 'single';
        $lineClientId = (int) ($payload['line_client_id'] ?? 0);
        $lineClientGroupId = (int) ($payload['line_client_group_id'] ?? 0);
        $lineGroupMode = trim((string) ($payload['line_group_mode'] ?? 'selected'));
        $endpointMode = trim((string) ($payload['endpoint_mode'] ?? 'slave_group'));
        $forwardChainId = (int) ($payload['forward_chain_id'] ?? 0);
        $sniHost = trim((string) ($payload['sni_host'] ?? ''));
        $pathPrefix = trim((string) ($payload['path_prefix'] ?? ''));
        $nodeGroup = trim((string) ($payload['node_group'] ?? ($existingForward['node_group_key'] ?? $this->config->get('DEFAULT_NODE_GROUP', 'default'))));
        $specificNodeId = null;
        $specificNode = null;
        $eligibleLeigodNodes = null;
        $publicPort = (int) ($payload['public_port'] ?? 0);
        $cdnHost = trim((string) ($payload['cdn_host'] ?? ''));
        $filterProfile = (string) ($payload['filter_profile'] ?? 'off');
        $targetSource = (string) ($payload['target_source'] ?? 'manual');
        $targetSource = trim((string) ($payload['target_source'] ?? 'manual'));
        $targetServiceId = (int) ($payload['target_service_id'] ?? 0);
        if ($targetSource === '') {
            $targetSource = 'manual';
        }
        if (!in_array($targetSource, ['manual', 'service_ip'], true)) {
            throw new ApiException('invalid_forward_target_source', 422, ['target_source' => $targetSource]);
        }
        $customEndpointIdsJson = array_key_exists('custom_endpoint_ids_json', $payload)
            ? $this->normalizeJsonField($payload['custom_endpoint_ids_json'])
            : ($existingForward['custom_endpoint_ids_json'] ?? null);
        $selectedLineClientGroupIds = [];
        foreach ((array) ($payload['line_client_group_ids'] ?? $payload['line_client_group_ids_json'] ?? []) as $groupId) {
            $casted = (int) $groupId;
            if ($casted > 0) {
                $selectedLineClientGroupIds[] = $casted;
            }
        }
        $selectedLineClientGroupIds = array_values(array_unique($selectedLineClientGroupIds));
        if ($lineClientGroupId <= 0 && $selectedLineClientGroupIds !== []) {
            $lineClientGroupId = $selectedLineClientGroupIds[0];
        }

        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id LIMIT 1', ['id' => $serviceId]);
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }
        if ($targetSource === 'service_ip') {
            $targetServiceId = $targetServiceId > 0 ? $targetServiceId : $serviceId;
            $targetService = $this->db->fetchOne(
                'SELECT id, user_id, server_public_ip, server_ip_state FROM services WHERE id = :id LIMIT 1',
                ['id' => $targetServiceId]
            );
            if (!is_array($targetService)) {
                throw new ApiException('target_service_not_found', 404, ['target_service_id' => $targetServiceId]);
            }
            if ((int) ($targetService['user_id'] ?? 0) !== (int) ($service['user_id'] ?? 0)) {
                throw new ApiException('target_service_not_allowed', 422, ['target_service_id' => $targetServiceId]);
            }
            $serviceIp = trim((string) ($targetService['server_public_ip'] ?? ''));
            $serviceIpState = trim((string) ($targetService['server_ip_state'] ?? 'missing'));
            if ($serviceIp === '' || $serviceIpState === 'missing') {
                throw new ApiException('target_service_ip_missing', 422, ['target_service_id' => $targetServiceId]);
            }
            $targetHost = $serviceIp;
        }

        [$targetHost, $targetPort, $listenPort] = $this->validateForwardMutationInputs(
            $targetHost,
            $targetPort,
            $listenPort,
            true
        );

        if ($forwardRouteId > 0) {
            $this->visibleForwardRouteForService($forwardRouteId, $serviceId);
            if ($forwardCombinationId <= 0) {
                // Route selected: seed the forward from the route's primary (lowest sort_order enabled)
                // combination so the existing combo-based realization keeps working. The slave's basis
                // selector (Forward Route racing) may later promote a different enabled combo at runtime.
                $forwardCombinationId = $this->primaryCombinationIdForRoute($forwardRouteId);
                if ($forwardCombinationId <= 0) {
                    throw new ApiException('forward_route_has_no_enabled_combination', 422, ['forward_route_id' => $forwardRouteId]);
                }
            } elseif (!$this->forwardRouteContainsCombination($forwardRouteId, $forwardCombinationId)) {
                throw new ApiException('forward_route_combination_mismatch', 422, [
                    'forward_route_id' => $forwardRouteId,
                    'forward_combination_id' => $forwardCombinationId,
                ]);
            }
        }

        if ($forwardCombinationId > 0) {
            $combination = $this->visibleForwardCombinationForService($forwardCombinationId, $serviceId);
            $backendMode = (string) ($combination['backend_mode'] ?? 'current_mode');
            $combinationRuntimePreference = trim((string) ($combination['runtime_preference'] ?? 'single'));
            $clientMode = $this->normalizeClientMode((string) ($combination['client_mode'] ?? 'single'));
            if (!in_array($clientMode, ['single', 'bond', 'mptcp'], true)) {
                throw new ApiException('invalid_forward_combination_client_mode', 422, ['client_mode' => $clientMode]);
            }
            $requestedSpecificNodeId = null;
            $decodedCustomEndpointIds = is_string($customEndpointIdsJson)
                ? json_decode($customEndpointIdsJson, true)
                : null;
            if (is_array($decodedCustomEndpointIds) && array_key_exists('specific_node_id', $decodedCustomEndpointIds)) {
                $requestedSpecificNodeId = $this->specificNodeIdFromCustomEndpointIds($customEndpointIdsJson);
            }
            if ($requestedSpecificNodeId === null && preg_match('/^_user_node_\d+$/', $nodeGroup) === 1) {
                $requestedSpecificNodeId = $this->specificNodeIdFromNodeGroupSelector($nodeGroup);
            }
            $specificNode = null;
            $nodeGroup = (string) ($combination['node_group_key'] ?? '');
            $bondSidecarPointId = (int) ($combination['sidecar_point_id'] ?? 0);
            $forwardRuntimePreference = $combinationRuntimePreference !== '' ? $combinationRuntimePreference : 'single';
            $bondIngressMode = $this->normalizeBondIngressMode((string) ($combination['bond_ingress_mode'] ?? 'plain_tcp_mux'));
            $cfLocationGroupId = $backendMode === 'cf_mode' ? (int) ($combination['cf_location_group_id'] ?? 0) : 0;
            $fixedBondProbe = [
                'backend_mode' => $backendMode,
                'client_mode' => $clientMode,
                'bond_sidecar_point_id' => $bondSidecarPointId,
                'custom_endpoint_ids_json' => $requestedSpecificNodeId !== null
                    ? json_encode(['specific_node_id' => $requestedSpecificNodeId])
                    : null,
            ];
            $specificNodeId = $this->usesFixedEndpointLeigodBond($fixedBondProbe)
                ? $requestedSpecificNodeId
                : null;
        } else {
            if (array_key_exists('node_group', $payload) && !$catalogPlacementRequested) {
                $specificNodeId = $this->specificNodeIdFromNodeGroupSelector($nodeGroup);
            } elseif ($forwardId !== null && !$catalogPlacementRequested && !$catalogPlacementClearRequested) {
                $specificNodeId = $this->specificNodeIdFromCustomEndpointIds($customEndpointIdsJson);
            }
            if ($specificNodeId !== null) {
                $nodeGroup = '';
            }
        }

        $availableClients = $this->availableClientsForService($serviceId);
        $availableClientGroups = $this->availableClientGroupsForService($serviceId);
        if ($lineClientId <= 0 && $lineClientGroupId <= 0 && $selectedLineClientGroupIds === [] && $availableClients !== []) {
            $lineClientId = (int) $availableClients[0]['id'];
        }
        if ($lineGroupMode === 'all_allowed') {
            $selectedLineClientGroupIds = array_values(array_map(static fn(array $group): int => (int) $group['id'], $availableClientGroups));
        }
        if ($availableClientGroups !== [] && $lineClientGroupId > 0) {
            $allowedClientGroupIds = array_map(static fn(array $group): int => (int) $group['id'], $availableClientGroups);
            if (!in_array($lineClientGroupId, $allowedClientGroupIds, true)) {
                throw new ApiException('line_client_group_not_allowed', 422, [
                    'line_client_group_id' => $lineClientGroupId,
                    'allowed_line_client_group_ids' => $allowedClientGroupIds,
                ]);
            }
        }
        if ($availableClientGroups !== [] && $selectedLineClientGroupIds !== []) {
            $allowedClientGroupIds = array_map(static fn(array $group): int => (int) $group['id'], $availableClientGroups);
            foreach ($selectedLineClientGroupIds as $groupId) {
                if (!in_array($groupId, $allowedClientGroupIds, true)) {
                    throw new ApiException('line_client_group_not_allowed', 422, [
                        'line_client_group_id' => $groupId,
                        'allowed_line_client_group_ids' => $allowedClientGroupIds,
                    ]);
                }
            }
        }
        if (($availableClients !== [] || $availableClientGroups !== []) && $lineClientId <= 0 && $lineClientGroupId <= 0) {
            if ($selectedLineClientGroupIds === []) {
                throw new ApiException('line_client_required', 422, [
                    'allowed_line_client_ids' => array_map(static fn(array $client): int => (int) $client['id'], $availableClients),
                ]);
            }
        }
        if ($availableClients !== [] && $lineClientId > 0) {
            $allowedClientIds = array_map(static fn(array $client): int => (int) $client['id'], $availableClients);
            if (!in_array($lineClientId, $allowedClientIds, true)) {
                throw new ApiException('line_client_not_allowed', 422, [
                    'line_client_id' => $lineClientId,
                    'allowed_line_client_ids' => $allowedClientIds,
                ]);
            }
        }

        $selectedClient = null;
        if ($lineClientId > 0) {
            foreach ($availableClients as $client) {
                if ((int) $client['id'] === $lineClientId) {
                    $selectedClient = $client;
                    break;
                }
            }
        }

        $candidateClients = [];
        if ($lineClientId > 0 && $selectedClient !== null) {
            $candidateClients[] = $selectedClient;
        }
        if ($selectedLineClientGroupIds !== []) {
            foreach ($availableClients as $client) {
                $groupId = (int) ($client['group_id'] ?? 0);
                if ($groupId > 0 && in_array($groupId, $selectedLineClientGroupIds, true)) {
                    $candidateClients[(int) $client['id']] = $client;
                }
            }
        }
        $candidateClients = array_values($candidateClients);

        if ($selectedClient !== null) {
            if (($payload['endpoint_mode'] ?? '') === '' && !empty($selectedClient['default_endpoint_mode'])) {
                $endpointMode = (string) $selectedClient['default_endpoint_mode'];
            }
            if (($payload['node_group'] ?? '') === '' && !empty($selectedClient['default_node_group_key'])) {
                $nodeGroup = (string) $selectedClient['default_node_group_key'];
            }
            if ((int) ($payload['forward_chain_id'] ?? 0) <= 0 && !empty($selectedClient['default_forward_chain_id'])) {
                $forwardChainId = (int) $selectedClient['default_forward_chain_id'];
            }
        }

        $allowedGroups = $this->allowedNodeGroupsForPlan((int) ($service['plan_id'] ?? 0));
        $allowedGroupKeys = array_map(static fn(array $group): string => (string) ($group['group_key'] ?? ''), $allowedGroups);
        $endpointGroupKeys = [];

        if ($specificNodeId !== null) {
            $specificNode = $this->specificNodeForForward($specificNodeId, $serviceId);
            if ($specificNode === null) {
                throw new ApiException('specific_node_not_allowed', 404, ['node_id' => $specificNodeId]);
            }
            $nodeGroup = trim((string) ($specificNode['node_group_key'] ?? ''));
            if ($nodeGroup === '') {
                throw new ApiException('specific_node_no_group', 422, ['node_id' => $specificNodeId]);
            }
            $specificPublicHost = $this->resolveNodePublicHost($specificNode);
            if ($specificPublicHost === '') {
                throw new ApiException('specific_node_no_public_host', 409, ['node_id' => $specificNodeId]);
            }
            $specificNode['resolved_public_host'] = $specificPublicHost;
            $endpointMode = 'slave_group';
            $forwardChainId = 0;
            $endpointGroupKeys = [$nodeGroup];
        } elseif ($forwardChainId > 0) {
            $chain = $this->db->fetchOne('SELECT * FROM forward_chains WHERE id = :id LIMIT 1', ['id' => $forwardChainId]);
            if ($chain === null || (int) ($chain['is_active'] ?? 0) !== 1) {
                throw new ApiException('forward_chain_not_found', 404, ['forward_chain_id' => $forwardChainId]);
            }
            $chainSteps = $this->db->fetchAll(
                'SELECT s.*, g.group_key
                 FROM forward_chain_steps s
                 INNER JOIN node_groups g ON g.id = s.node_group_id
                 WHERE s.forward_chain_id = :chain_id
                 ORDER BY s.step_order ASC, s.id ASC',
                ['chain_id' => $forwardChainId]
            );
            if ($chainSteps === []) {
                throw new ApiException('forward_chain_empty', 422, ['forward_chain_id' => $forwardChainId]);
            }
            $endpointGroupKeys = array_values(array_unique(array_map(static fn(array $step): string => (string) $step['group_key'], $chainSteps)));
            $nodeGroup = $endpointGroupKeys[0];
            $endpointMode = 'forward_chain';
        } elseif ($nodeGroup !== '') {
            $endpointGroupKeys = [$nodeGroup];
        }

        if ($endpointGroupKeys === [] && $candidateClients !== []) {
            foreach ($candidateClients as $client) {
                $clientChainId = (int) ($client['default_forward_chain_id'] ?? 0);
                if ($clientChainId > 0) {
                    $chainSteps = $this->db->fetchAll(
                        'SELECT s.*, g.group_key
                         FROM forward_chain_steps s
                         INNER JOIN node_groups g ON g.id = s.node_group_id
                         WHERE s.forward_chain_id = :chain_id
                         ORDER BY s.step_order ASC, s.id ASC',
                        ['chain_id' => $clientChainId]
                    );
                    foreach ($chainSteps as $step) {
                        $endpointGroupKeys[] = (string) $step['group_key'];
                    }
                    if ($forwardChainId <= 0) {
                        $forwardChainId = $clientChainId;
                        $endpointMode = 'forward_chain';
                    }
                    continue;
                }

                $clientNodeGroup = trim((string) ($client['default_node_group_key'] ?? ''));
                if ($clientNodeGroup !== '') {
                    $endpointGroupKeys[] = $clientNodeGroup;
                    if ($nodeGroup === '') {
                        $nodeGroup = $clientNodeGroup;
                    }
                }
            }
            $endpointGroupKeys = array_values(array_unique(array_filter($endpointGroupKeys, static fn(string $value): bool => $value !== '')));
        }

        if ($allowedGroups !== [] && $endpointGroupKeys !== []) {
            if (($nodeGroup === '' || $nodeGroup === 'default') && $endpointMode !== 'forward_chain') {
                $nodeGroup = $allowedGroupKeys[0];
                $endpointGroupKeys = [$nodeGroup];
            }
            foreach ($endpointGroupKeys as $groupKey) {
                if (!in_array($groupKey, $allowedGroupKeys, true)) {
                    throw new ApiException('node_group_not_allowed', 422, [
                        'requested_node_group' => $groupKey,
                        'allowed_node_groups' => $allowedGroupKeys,
                    ]);
                }
            }
        }

        $defaultClient = $selectedClient ?? ($candidateClients[0] ?? null);
        $ingressGroupKey = $nodeGroup !== '' ? $nodeGroup : ($endpointGroupKeys[0] ?? '');
        $ingressGroup = null;
        if ($ingressGroupKey !== '') {
            $ingressGroup = $this->db->fetchOne('SELECT * FROM node_groups WHERE group_key = :group_key LIMIT 1', [
                'group_key' => $ingressGroupKey,
            ]);
        }
        $autoPort = 0;
        if ($listenPort <= 0 || $publicPort <= 0) {
            $autoPort = $this->pickClientPort(
                $defaultClient,
                $forwardId,
                $targetPort,
                'target_port_then_client_range'
            );
        }
        $preferredPublicPort = (int) (($ingressGroup['ingress_preferred_public_port'] ?? 0) ?: $this->config->getInt('DEFAULT_EDGE_PUBLIC_PORT', 443));
        $portStrategy = (string) (($ingressGroup['ingress_port_strategy'] ?? '') ?: $this->config->get('EDGE_PORT_STRATEGY', 'shared_preferred_port'));
        if ($listenPort <= 0) {
            $listenPort = $autoPort > 0 ? $autoPort : ($targetPort > 0 ? $targetPort : $preferredPublicPort);
        }
        if ($publicPort <= 0) {
            $publicPort = $this->pickPublicPort(
                $defaultClient,
                $forwardId,
                $preferredPublicPort,
                $targetPort,
                $portStrategy
            );
        }
        if ($pathPrefix === '') {
            $pathPrefix = $this->generatePathPrefix((string) (($ingressGroup['ingress_path_strategy'] ?? '') ?: $this->config->get('EDGE_PATH_STRATEGY', 'random')));
        }
        if ($sniHost === '') {
            $sniHost = $this->resolveIngressSniHost('', is_array($ingressGroup) ? $ingressGroup : null);
        }
        if ($cdnHost === '') {
            $cdnHost = $this->resolveIngressCdnHost('', $sniHost, is_array($ingressGroup) ? $ingressGroup : null);
        }

        if ($listenPort <= 0 || $publicPort <= 0 || $targetHost === '' || $targetPort <= 0) {
            throw new ApiException('invalid_forward_payload', 422, ['payload' => $payload]);
        }

        $sidecarFrontendRequested = $this->usesSingleRaceSidecarFrontend([
            'client_mode' => $clientMode,
            'backend_mode' => $backendMode,
            'bond_sidecar_point_id' => $bondSidecarPointId,
        ]);
        $sidecarRequired = $sidecarFrontendRequested
            || $this->sidecarRequiredForRuntime($backendMode, $clientMode, $forwardRuntimePreference);
        $sharedSidecarRequired = $sidecarRequired
            && strtolower(trim($backendMode)) === 'current_mode'
            && $this->staticSidecarUsesSharedWireguardPeers($bondSidecarPointId);
        if ($sharedSidecarRequired && $publicPort === 443) {
            throw new ApiException('shared_sidecar_public_port_forbidden', 422, [
                'bond_sidecar_point_id' => $bondSidecarPointId,
                'public_port' => $publicPort,
            ]);
        }
        if ($sidecarRequired) {
            if ($bondSidecarPointId <= 0) {
                throw new ApiException('bond_sidecar_point_required_for_aggregation', 422, ['client_mode' => $clientMode]);
            }
            $sidecarPoint = $this->visibleSidecarPointForUser($bondSidecarPointId, (int) ($service['user_id'] ?? 0));
            if ($sidecarPoint === null || (int) ($sidecarPoint['is_active'] ?? 0) !== 1) {
                throw new ApiException('bond_sidecar_point_not_allowed', 422, ['bond_sidecar_point_id' => $bondSidecarPointId]);
            }
            if (!$this->sidecarPointOverseasEligible($sidecarPoint)) {
                throw new ApiException('sidecar_must_be_overseas_for_aggregation', 422, ['bond_sidecar_point_id' => $bondSidecarPointId]);
            }
            if ($bondRemoteHost === '') {
                $bondRemoteHost = trim((string) ($sidecarPoint['sidecar_host'] ?? ''));
            }
            $selectedSidecarPort = $clientMode === 'mptcp'
                ? $this->sidecarMptcpListenPort($sidecarPoint, $this->decodeJsonAssoc($sidecarPoint['agent_config_json'] ?? null))
                : $this->sidecarBondListenPort($sidecarPoint);
            if ($selectedSidecarPort > 0) {
                $bondRemotePort = $selectedSidecarPort;
            }
            $bondRemote = $this->resolveBondRemoteTarget([
                'bond_remote_host' => $bondRemoteHost,
                'bond_remote_port' => $bondRemotePort,
            ]);
            $bondRemoteHost = $bondRemote['host'];
            $bondRemotePort = $bondRemote['port'];
            $this->bondTokenSecret($service);
        } else {
            $bondSidecarPointId = 0;
            $bondRemoteHost = '';
            $bondRemotePort = 0;
        }

        $fixedEndpointLeigodBond = $this->usesFixedEndpointLeigodBond([
            'backend_mode' => $backendMode,
            'client_mode' => $clientMode,
            'bond_sidecar_point_id' => $bondSidecarPointId,
            'custom_endpoint_ids_json' => $specificNodeId !== null
                ? json_encode(['specific_node_id' => $specificNodeId])
                : null,
        ]);
        if ($this->isDedicatedLeigodRuntime($backendMode, $clientMode, $forwardRuntimePreference) || $fixedEndpointLeigodBond) {
            $eligibilityTransport = $this->leigodEligibilityTransport($transportMode);
            $eligibleLeigodNodes = $forwardRouteId > 0
                ? $this->leigodLineCatalog()->eligibleNodesForRoute($forwardRouteId, $eligibilityTransport)
                : $this->leigodLineCatalog()->eligibleNodesForCombination($forwardCombinationId, $eligibilityTransport);
            if ($eligibleLeigodNodes === []) {
                throw new ApiException('leigod_route_no_healthy_candidate', 409, [
                    'forward_route_id' => $forwardRouteId,
                    'forward_combination_id' => $forwardCombinationId,
                    'transport' => $eligibilityTransport,
                ]);
            }
            if ($specificNodeId !== null) {
                $eligibleNodeIds = array_map(static fn(array $node): int => (int) ($node['id'] ?? 0), $eligibleLeigodNodes);
                if (!in_array($specificNodeId, $eligibleNodeIds, true)) {
                    throw new ApiException('leigod_route_no_healthy_candidate', 409, [
                        'node_id' => $specificNodeId,
                        'forward_route_id' => $forwardRouteId,
                        'forward_combination_id' => $forwardCombinationId,
                        'transport' => $eligibilityTransport,
                    ]);
                }
            }
            if ($fixedEndpointLeigodBond && $specificNodeId !== null) {
                $fixedCandidates = [];
                foreach ($eligibleLeigodNodes as $eligibleNode) {
                    if ((int) ($eligibleNode['id'] ?? 0) === $specificNodeId) {
                        $fixedCandidates = is_array($eligibleNode['candidates'] ?? null)
                            ? $eligibleNode['candidates']
                            : [];
                        break;
                    }
                }
                if (count($fixedCandidates) < 2 || count($fixedCandidates) > 8) {
                    throw new ApiException('leigod_fixed_endpoint_candidate_count_invalid', 409, [
                        'node_id' => $specificNodeId,
                        'candidate_count' => count($fixedCandidates),
                    ]);
                }
            }
        }

        if ($endpointMode === 'direct') {
            throw new ApiException('endpoint_mode_not_supported_yet', 422, [
                'endpoint_mode' => 'direct',
                'supported_modes' => ['slave_group', 'forward_chain'],
            ]);
        }

        if ($endpointMode === 'slave_group' && $endpointGroupKeys === [] && $specificNodeId === null) {
            throw new ApiException('node_group_required', 422, ['endpoint_mode' => 'slave_group']);
        }

        if ($endpointMode === 'forward_chain' && $forwardChainId <= 0) {
            throw new ApiException('forward_chain_required', 422, ['endpoint_mode' => 'forward_chain']);
        }

        if ($endpointMode !== 'forward_chain' && $nodeGroup !== '' && $endpointGroupKeys === []) {
            $endpointGroupKeys = [$nodeGroup];
        }

        if ($endpointMode === 'forward_chain' && $forwardChainId > 0 && $endpointGroupKeys === []) {
            throw new ApiException('forward_chain_empty', 422, ['forward_chain_id' => $forwardChainId]);
        }

        if ($endpointMode === 'forward_chain' && $forwardChainId > 0 && $nodeGroup === '') {
            $nodeGroup = $endpointGroupKeys[0];
        }

        if ($endpointMode === 'slave_group' && $nodeGroup === '') {
            $availableGroups = $this->availableNodeGroupsForService($serviceId);
            if ($availableGroups !== []) {
                $nodeGroup = (string) $availableGroups[0]['group_key'];
                $endpointGroupKeys = [$nodeGroup];
            }
        }

        if ($specificNodeId !== null) {
            $endpointGroupKeys = [$nodeGroup];
        }

        if ($allowedGroups !== [] && $nodeGroup !== '' && !in_array($nodeGroup, $allowedGroupKeys, true)) {
                throw new ApiException('node_group_not_allowed', 422, [
                    'requested_node_group' => $nodeGroup,
                    'allowed_node_groups' => $allowedGroupKeys,
                ]);
        }

        $endpointCount = $specificNodeId !== null ? 1 : $this->panelManagedEndpointCount($clientMode, $endpointGroupKeys, $serviceId);
        $customEndpointIdsJson = $this->customEndpointIdsJsonWithSpecificNode($customEndpointIdsJson, $specificNodeId);

        if ($forwardId === null) {
            $forwardId = $this->db->insert('forwards', [
                'service_id' => $serviceId,
                'api_revision' => 1,
                'api_idempotency_key_hash' => $idempotencyKeyHash,
                'api_request_hash' => $requestHash,
                'listen_port' => $listenPort,
                'target_host' => $targetHost,
                'target_port' => $targetPort,
                'bond_remote_host' => $bondRemoteHost !== '' ? $bondRemoteHost : null,
                'bond_remote_port' => $bondRemotePort > 0 ? $bondRemotePort : null,
                'bond_ingress_mode' => $bondIngressMode,
                'bond_sidecar_point_id' => $bondSidecarPointId > 0 ? $bondSidecarPointId : null,
                'forward_combination_id' => $forwardCombinationId > 0 ? $forwardCombinationId : null,
                'forward_route_id' => $forwardRouteId > 0 ? $forwardRouteId : null,
                'active_combination_id' => $forwardCombinationId > 0 ? $forwardCombinationId : null,
                'target_source' => $targetSource !== '' ? $targetSource : 'manual',
                'target_service_id' => $targetServiceId > 0 ? $targetServiceId : null,
                'backend_mode' => $backendMode,
                'cf_location_group_id' => $cfLocationGroupId > 0 ? $cfLocationGroupId : null,
                'line_client_id' => $lineClientId > 0 ? $lineClientId : null,
                'line_client_group_id' => $lineClientGroupId > 0 ? $lineClientGroupId : null,
                'line_group_mode' => $lineGroupMode !== '' ? $lineGroupMode : 'selected',
                'line_client_group_ids_json' => $this->normalizeJsonField($selectedLineClientGroupIds),
                'endpoint_mode' => $endpointMode,
                'forward_chain_id' => $forwardChainId > 0 ? $forwardChainId : null,
                'custom_endpoint_ids_json' => $customEndpointIdsJson,
                'transport_mode' => $transportMode,
                'client_mode' => $clientMode,
                'sni_host' => $sniHost,
                'path_prefix' => $pathPrefix,
                'node_group_key' => $nodeGroup,
                'endpoint_count' => $endpointCount,
                'public_port' => $publicPort > 0 ? $publicPort : $listenPort,
                'cdn_host' => $cdnHost,
                'filter_profile' => $filterProfile,
                'enabled' => 1,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
            $this->audit('forward.created', ['forward_id' => $forwardId, 'service_id' => $serviceId, 'actor' => $actor]);
        } else {
            $nextRevision = max(1, (int) ($existingForward['api_revision'] ?? 1)) + 1;
            $this->db->execute(
                'UPDATE forwards
                 SET listen_port = :listen_port,
                     target_host = :target_host,
                     target_port = :target_port,
                     bond_remote_host = :bond_remote_host,
                     bond_remote_port = :bond_remote_port,
                     bond_ingress_mode = :bond_ingress_mode,
                     bond_sidecar_point_id = :bond_sidecar_point_id,
                     forward_combination_id = :forward_combination_id,
                     forward_route_id = :forward_route_id,
                     active_combination_id = :active_combination_id,
                     target_source = :target_source,
                     target_service_id = :target_service_id,
                     backend_mode = :backend_mode,
                     cf_location_group_id = :cf_location_group_id,
                     line_client_id = :line_client_id,
                     line_client_group_id = :line_client_group_id,
                     line_group_mode = :line_group_mode,
                     line_client_group_ids_json = :line_client_group_ids_json,
                     endpoint_mode = :endpoint_mode,
                     forward_chain_id = :forward_chain_id,
                     custom_endpoint_ids_json = :custom_endpoint_ids_json,
                     transport_mode = :transport_mode,
                     client_mode = :client_mode,
                     sni_host = :sni_host,
                     path_prefix = :path_prefix,
                     node_group_key = :node_group_key,
                     endpoint_count = :endpoint_count,
                     public_port = :public_port,
                     cdn_host = :cdn_host,
                     filter_profile = :filter_profile,
                     enabled = 1,
                     api_revision = :api_revision,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'id' => $forwardId,
                    'listen_port' => $listenPort,
                    'target_host' => $targetHost,
                    'target_port' => $targetPort,
                    'bond_remote_host' => $bondRemoteHost !== '' ? $bondRemoteHost : null,
                    'bond_remote_port' => $bondRemotePort > 0 ? $bondRemotePort : null,
                    'bond_ingress_mode' => $bondIngressMode,
                    'bond_sidecar_point_id' => $bondSidecarPointId > 0 ? $bondSidecarPointId : null,
                    'forward_combination_id' => $forwardCombinationId > 0 ? $forwardCombinationId : null,
                    'forward_route_id' => $forwardRouteId > 0 ? $forwardRouteId : null,
                    'active_combination_id' => $forwardCombinationId > 0 ? $forwardCombinationId : null,
                    'target_source' => $targetSource !== '' ? $targetSource : 'manual',
                    'target_service_id' => $targetServiceId > 0 ? $targetServiceId : null,
                    'backend_mode' => $backendMode,
                    'cf_location_group_id' => $cfLocationGroupId > 0 ? $cfLocationGroupId : null,
                    'line_client_id' => $lineClientId > 0 ? $lineClientId : null,
                    'line_client_group_id' => $lineClientGroupId > 0 ? $lineClientGroupId : null,
                    'line_group_mode' => $lineGroupMode !== '' ? $lineGroupMode : 'selected',
                    'line_client_group_ids_json' => $this->normalizeJsonField($selectedLineClientGroupIds),
                    'endpoint_mode' => $endpointMode,
                    'forward_chain_id' => $forwardChainId > 0 ? $forwardChainId : null,
                    'custom_endpoint_ids_json' => $customEndpointIdsJson,
                    'transport_mode' => $transportMode,
                    'client_mode' => $clientMode,
                    'sni_host' => $sniHost,
                    'path_prefix' => $pathPrefix,
                    'node_group_key' => $nodeGroup,
                    'endpoint_count' => $endpointCount,
                    'public_port' => $publicPort > 0 ? $publicPort : $listenPort,
                    'cdn_host' => $cdnHost,
                     'filter_profile' => $filterProfile,
                     'api_revision' => $nextRevision,
                     'updated_at' => $this->now(),
                ]
            );
            $this->audit('forward.updated', ['forward_id' => $forwardId, 'service_id' => $serviceId, 'actor' => $actor]);
        }

        $forward = $this->db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', ['id' => $forwardId]);

        $desiredEndpointCount = max((int) $forward['endpoint_count'], count($endpointGroupKeys));
        $nodeSelectionCount = is_array($eligibleLeigodNodes)
            ? max($desiredEndpointCount, $this->availableNodeCountForGroups($endpointGroupKeys, $serviceId))
            : $desiredEndpointCount;
        $nodes = $specificNodeId !== null && is_array($specificNode)
            ? [$specificNode]
            : $this->pickNodesForGroups($endpointGroupKeys, $nodeSelectionCount, $serviceId);
        if (is_array($eligibleLeigodNodes)) {
            $eligibleNodeIds = array_fill_keys(array_map(
                static fn(array $node): int => (int) ($node['id'] ?? 0),
                $eligibleLeigodNodes
            ), true);
            $nodes = array_values(array_filter(
                $nodes,
                static fn(array $node): bool => isset($eligibleNodeIds[(int) ($node['id'] ?? 0)])
            ));
            $nodes = array_slice($nodes, 0, $desiredEndpointCount);
        }
        $reachableNodes = [];
        $skippedNodes = [];
        foreach ($nodes as $node) {
            $publicHost = $this->resolveNodePublicHost($node);
            if ($publicHost === '') {
                $skippedNodes[] = [
                    'id' => (int) ($node['id'] ?? 0),
                    'name' => (string) ($node['name'] ?? ''),
                ];
                continue;
            }
            $node['resolved_public_host'] = $publicHost;
            $reachableNodes[] = $node;
        }
        $nodes = $reachableNodes;
        if ($nodes === [] && is_array($eligibleLeigodNodes)) {
            throw new ApiException('leigod_route_no_healthy_candidate', 409, [
                'forward_route_id' => $forwardRouteId,
                'forward_combination_id' => $forwardCombinationId,
                'transport' => $this->leigodEligibilityTransport($transportMode),
            ]);
        }
        if ($nodes === []) {
            throw new ApiException('no_available_nodes', 409, [
                'requested_node_group' => $forward['node_group_key'],
                'allowed_node_groups' => array_map(static fn(array $group): string => (string) $group['group_key'], $allowedGroups),
                'available_node_groups' => array_map(static fn(array $group): string => (string) $group['group_key'], $this->availableNodeGroupsForService($serviceId)),
                'nodes_missing_public_host' => $skippedNodes,
            ]);
        }

        $sidecarFrontend = $this->usesSingleRaceSidecarFrontend($forward);
        $directSidecarMode = $sidecarFrontend ? 'bond' : $clientMode;
        $directSidecarTarget = in_array($directSidecarMode, ['bond', 'mptcp'], true)
            ? $this->resolveSidecarDirectTarget($forward, $directSidecarMode)
            : null;
        $publishTarget = $directSidecarTarget !== null
            ? ($directSidecarTarget['host'] . ':' . $directSidecarTarget['port'])
            : $forward['target_host'] . ':' . $forward['target_port'];

        $priority = 10;
        foreach ($nodes as $node) {
            $endpointId = $this->db->insert('service_endpoints', [
                'forward_id' => $forwardId,
                'node_id' => $node['id'],
                'public_host' => (string) ($node['resolved_public_host'] ?? $this->resolveNodePublicHost($node)),
                'public_ip' => $node['public_ip'],
                'public_port' => $forward['public_port'],
                'cdn_host' => $forward['cdn_host'],
                'priority' => $priority,
                'weight' => 100,
                'health_status' => 'pending',
                'last_error' => null,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);

            $this->queueTask((int) $node['id'], 'publish', $serviceId, $forwardId, $endpointId, $this->publishTaskPayloadForForward($forward, $node, $endpointId));
            $priority += 10;
        }

        $this->bumpClientConfigRevisions([
            (int) ($existingForward['line_client_id'] ?? 0),
            $lineClientId,
        ]);
        return $this->buildClientConfig($forwardId);
    }

    private function revokeForwardEndpoints(int $forwardId, string $reason): void
    {
        $forward = $this->db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', ['id' => $forwardId]);
        if ($forward === null) {
            return;
        }

        $existing = $this->db->fetchAll(
            'SELECT * FROM service_endpoints
             WHERE forward_id = :forward_id
               AND health_status <> :deleted
               AND health_status <> :deleting',
            [
                'forward_id' => $forwardId,
                'deleted' => 'deleted',
                'deleting' => 'deleting',
            ]
        );

        foreach ($existing as $endpoint) {
            $this->queueTask((int) $endpoint['node_id'], 'destroy', (int) $forward['service_id'], $forwardId, (int) $endpoint['id'], [
                'listen_port' => (int) $endpoint['public_port'],
                'path_prefix' => (string) $forward['path_prefix'],
                'reason' => $reason,
            ]);
            $this->db->execute('UPDATE service_endpoints SET health_status = :status, updated_at = :updated_at WHERE id = :id', [
                'id' => $endpoint['id'],
                'status' => 'deleting',
                'updated_at' => $this->now(),
            ]);
        }
    }

    private function queueLifecycleTasks(int $serviceId, string $action): void
    {
        $rows = $this->db->fetchAll(
            'SELECT e.*, f.path_prefix
             FROM service_endpoints e
             INNER JOIN forwards f ON f.id = e.forward_id
             WHERE f.service_id = :service_id
               AND e.health_status <> :deleted
               AND e.health_status <> :deleting',
            [
                'service_id' => $serviceId,
                'deleted' => 'deleted',
                'deleting' => 'deleting',
            ]
        );

        foreach ($rows as $row) {
            $this->queueTask((int) $row['node_id'], $action, $serviceId, (int) $row['forward_id'], (int) $row['id'], [
                'listen_port' => (int) $row['public_port'],
                'path_prefix' => (string) $row['path_prefix'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function queueTask(int $nodeId, string $action, int $serviceId, int $forwardId, int $endpointId, array $payload): void
    {
        $this->collapsePendingProvisionTasksForNode($nodeId, $action, $serviceId, $forwardId, $endpointId);
        $this->db->insert('provision_tasks', [
            'node_id' => $nodeId,
            'action' => $action,
            'service_id' => $serviceId,
            'forward_id' => $forwardId,
            'endpoint_id' => $endpointId,
            'status' => 'pending',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'lease_expires_at' => null,
            'attempt_count' => 0,
            'result_json' => null,
            'error_message' => null,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    private function collapsePendingProvisionTasksForNode(
        int $nodeId,
        string $action,
        int $serviceId,
        int $forwardId,
        int $endpointId
    ): void {
        if ($nodeId <= 0 || $action === '' || $serviceId <= 0 || $forwardId <= 0 || $endpointId <= 0) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $resultJson = json_encode([
            'note' => 'superseded_by_new_publish_task',
            'node_id' => $nodeId,
            'action' => $action,
            'service_id' => $serviceId,
            'forward_id' => $forwardId,
            'endpoint_id' => $endpointId,
            'collapsed_at' => $now,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->db->execute(
            'UPDATE provision_tasks
             SET status = :completed_status,
                 result_json = :result_json,
                 error_message = NULL,
                 lease_expires_at = NULL,
                 updated_at = :updated_at
             WHERE node_id = :node_id
               AND action = :action
               AND service_id = :service_id
               AND forward_id = :forward_id
               AND endpoint_id = :endpoint_id
               AND status = :pending_status',
            [
                'completed_status' => 'completed',
                'pending_status' => 'pending',
                'result_json' => $resultJson,
                'updated_at' => $now,
                'node_id' => $nodeId,
                'action' => $action,
                'service_id' => $serviceId,
                'forward_id' => $forwardId,
                'endpoint_id' => $endpointId,
            ]
        );
    }

    /**
     * FIX A: is there already a viable publish task the agent can pull for this
     * (node_id, action='publish', forward_id, endpoint_id)?
     *
     * Viable = a 'pending' row, OR a 'running' row whose lease is still in the future. A 'pending'
     * row that is OLDER than QUEUE_TASK_RESTALE_SECONDS (default 300s) is treated as NON-viable so a
     * genuinely stuck / never-pulled task is allowed to be re-queued (staleness escape). When a
     * viable task already exists we skip re-queueing an unconverged endpoint, so an unconverged
     * endpoint carries at most ONE stable queued publish task instead of a fresh-superseded task
     * every heartbeat.
     */
    private function hasViablePublishTask(int $nodeId, int $serviceId, int $forwardId, int $endpointId): bool
    {
        if ($nodeId <= 0 || $forwardId <= 0 || $endpointId <= 0) {
            return false;
        }

        $restaleSeconds = max(0, (int) ($this->config->getInt('QUEUE_TASK_RESTALE_SECONDS', 300) ?? 300));
        $now = $this->now();
        $staleCutoff = gmdate('Y-m-d H:i:s', time() - $restaleSeconds);

        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS viable
             FROM provision_tasks
             WHERE node_id = :node_id
               AND action = :action
               AND service_id = :service_id
               AND forward_id = :forward_id
               AND endpoint_id = :endpoint_id
               AND (
                     (status = :pending_status AND created_at > :stale_cutoff)
                  OR (status = :running_status AND lease_expires_at IS NOT NULL AND lease_expires_at > :now)
               )",
            [
                'node_id' => $nodeId,
                'action' => 'publish',
                'service_id' => $serviceId,
                'forward_id' => $forwardId,
                'endpoint_id' => $endpointId,
                'pending_status' => 'pending',
                'running_status' => 'running',
                'stale_cutoff' => $staleCutoff,
                'now' => $now,
            ]
        );

        return is_array($row) && (int) ($row['viable'] ?? 0) > 0;
    }

    private function normalizeMalformedRepublishFailure(int $nodeId, int $forwardId, int $serviceEndpointId): void
    {
        if ($nodeId <= 0 || $forwardId <= 0 || $serviceEndpointId <= 0 || $forwardId === $serviceEndpointId) {
            return;
        }

        $this->db->execute(
            'UPDATE provision_tasks
             SET status = :completed_status,
                 result_json = :result_json,
                 error_message = NULL,
                 lease_expires_at = NULL,
                 updated_at = :updated_at
             WHERE node_id = :node_id
               AND action = :action
               AND forward_id = :forward_id
               AND endpoint_id = :forward_id
               AND endpoint_id <> :service_endpoint_id
               AND status = :failed_status
               AND error_message = :expected_error_message',
            [
                'completed_status' => 'completed',
                'result_json' => json_encode([
                    'note' => 'purged malformed publish task after endpoint_id hotfix',
                    'normalized_by' => 'republishActiveEndpointsForNode',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $this->now(),
                'node_id' => $nodeId,
                'action' => 'publish',
                'forward_id' => $forwardId,
                'service_endpoint_id' => $serviceEndpointId,
                'failed_status' => 'failed',
                'expected_error_message' => 'purged malformed publish task after endpoint_id hotfix',
            ]
        );
    }

    public function reconcileActiveForwardsForGroup(string $groupKey): void
    {
        $normalizedGroup = trim($groupKey);
        if ($normalizedGroup === '') {
            return;
        }
        $forwards = $this->db->fetchAll(
            'SELECT f.*, s.status AS service_status
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.enabled = 1
               AND f.endpoint_mode = :endpoint_mode
               AND f.node_group_key = :group_key
               AND s.status = :service_status',
            [
                'endpoint_mode' => 'slave_group',
                'group_key' => $normalizedGroup,
                'service_status' => 'active',
            ]
        );
        foreach ($forwards as $forward) {
            $this->reconcileForwardEndpoints((int) ($forward['id'] ?? 0));
        }
    }

    /**
     * C.4b — periodic non-convergence sweep. Re-pushes active endpoints whose updated_at is stale
     * relative to the latest forward/combination updated_at (an edit landed but the endpoint never
     * re-converged), and escalates an alarm (log + persisted marker) after N consecutive sweeps in
     * which at least one endpoint remains non-convergent.
     *
     * Returns the number of endpoints found non-convergent this cycle.
     */
    public function sweepNonConvergentEndpoints(): int
    {
        $rows = $this->db->fetchAll(
            'SELECT e.id AS service_endpoint_id,
                    e.node_id AS endpoint_node_id,
                    e.updated_at AS endpoint_updated_at,
                    e.health_status AS endpoint_health_status,
                    f.*,
                    f.updated_at AS forward_updated_at,
                    fc.updated_at AS combination_updated_at,
                    s.status AS service_status
             FROM service_endpoints e
             INNER JOIN forwards f ON f.id = e.forward_id
             INNER JOIN services s ON s.id = f.service_id
             LEFT JOIN forward_combinations fc ON fc.id = f.forward_combination_id
             WHERE e.health_status = :active_status
               AND f.enabled = 1
               AND s.status = :service_status',
            [
                'active_status' => 'active',
                'service_status' => 'active',
            ]
        );

        $nonConvergent = 0;
        foreach ($rows as $row) {
            $endpointUpdatedAt = strtotime((string) ($row['endpoint_updated_at'] ?? '')) ?: 0;
            $forwardUpdatedAt = strtotime((string) ($row['forward_updated_at'] ?? '')) ?: 0;
            $combinationUpdatedAt = ($row['combination_updated_at'] ?? null) !== null
                ? (strtotime((string) $row['combination_updated_at']) ?: 0)
                : 0;
            $desiredAt = max($forwardUpdatedAt, $combinationUpdatedAt);
            if ($desiredAt <= 0 || $endpointUpdatedAt <= 0 || $endpointUpdatedAt >= $desiredAt) {
                continue; // converged (endpoint re-published at/after the latest edit)
            }
            $nonConvergent++;

            $nodeId = (int) ($row['endpoint_node_id'] ?? 0);
            $endpointId = (int) ($row['service_endpoint_id'] ?? 0);
            $forwardId = (int) ($row['forward_id'] ?? $row['id'] ?? 0);
            $node = $this->db->fetchOne('SELECT * FROM nodes WHERE id = :id LIMIT 1', ['id' => $nodeId]);
            if (!is_array($node)) {
                continue;
            }
            $publicHost = $this->resolveNodePublicHost($node);
            if ($publicHost === '') {
                continue;
            }
            $node['resolved_public_host'] = $publicHost;
            try {
                $payload = $this->publishTaskPayloadForForward($row, $node, $endpointId);
                $this->queueTask($nodeId, 'publish', (int) ($row['service_id'] ?? 0), $forwardId, $endpointId, $payload);
                $this->storeEndpointDesiredPayloadFingerprint(
                    $endpointId,
                    $this->desiredEndpointPayloadFingerprint($row, $node, $payload)
                );
            } catch (\Throwable $e) {
                error_log('sweepNonConvergentEndpoints re-push failed for endpoint ' . $endpointId . ': ' . $e->getMessage());
            }
        }

        $this->escalateNonConvergenceAlarm($nonConvergent);
        return $nonConvergent;
    }

    /**
     * Track consecutive non-convergent sweep cycles and raise (log + persist) an alarm once they
     * cross the configured threshold. A fully-convergent cycle clears the counter and the alarm.
     */
    private function escalateNonConvergenceAlarm(int $nonConvergentThisCycle): void
    {
        $threshold = max(1, (int) ($this->config->getInt('NON_CONVERGENCE_ALARM_CYCLES', 3) ?? 3));
        $counterKey = 'non_convergence_consecutive_cycles';
        if ($nonConvergentThisCycle <= 0) {
            $this->appSettingSet($counterKey, '0');
            // Only clear an alarm that was actually raised; never materialise an empty marker.
            if ($this->appSettingGet('non_convergence_alarm') !== null) {
                $this->appSettingSet('non_convergence_alarm', null);
            }
            return;
        }
        $current = (int) ($this->appSettingGet($counterKey) ?? '0') + 1;
        $this->appSettingSet($counterKey, (string) $current);
        if ($current >= $threshold) {
            $payload = json_encode([
                'consecutive_cycles' => $current,
                'threshold' => $threshold,
                'non_convergent_endpoints' => $nonConvergentThisCycle,
                'raised_at' => $this->now(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->appSettingSet('non_convergence_alarm', $payload !== false ? $payload : '');
            error_log(
                'non_convergence_alarm consecutive_cycles=' . $current
                . ' threshold=' . $threshold
                . ' non_convergent_endpoints=' . $nonConvergentThisCycle
            );
        }
    }

    public function republishActiveEndpointsForNode(int $nodeId): void
    {
        if ($nodeId <= 0) {
            return;
        }
        $rows = $this->db->fetchAll(
            'SELECT e.*,
                    f.*,
                    e.id AS service_endpoint_id,
                    s.status AS service_status
             FROM service_endpoints e
             INNER JOIN forwards f ON f.id = e.forward_id
             INNER JOIN services s ON s.id = f.service_id
             WHERE e.node_id = :node_id
               AND e.health_status <> :deleted
               AND e.health_status <> :deleting
               AND f.enabled = 1
               AND s.status = :service_status',
            [
                'node_id' => $nodeId,
                'deleted' => 'deleted',
                'deleting' => 'deleting',
                'service_status' => 'active',
            ]
        );
        foreach ($rows as $row) {
            $node = $this->db->fetchOne('SELECT * FROM nodes WHERE id = :id LIMIT 1', ['id' => $nodeId]);
            if (!is_array($node)) {
                continue;
            }
            $publicHost = $this->resolveNodePublicHost($node);
            if ($publicHost === '') {
                continue;
            }
            $node['resolved_public_host'] = $publicHost;
            $serviceEndpointId = (int) ($row['service_endpoint_id'] ?? 0);
            $this->normalizeMalformedRepublishFailure($nodeId, (int) ($row['forward_id'] ?? $row['id'] ?? 0), $serviceEndpointId);
            $payload = $this->publishTaskPayloadForForward($row, $node, $serviceEndpointId);
            $this->queueTask(
                $nodeId,
                'publish',
                (int) ($row['service_id'] ?? 0),
                (int) ($row['forward_id'] ?? $row['id'] ?? 0),
                $serviceEndpointId,
                $payload
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeEndpointPublishPayload(int $nodeId, int $forwardId, int $endpointId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT f.*, s.client_token,
                    combo.runtime_preference AS forward_combination_runtime_preference,
                    combo.allow_private_self_leg AS forward_combination_allow_private_self_leg,
                    combo.self_leg_cf_transport AS forward_combination_self_leg_cf_transport,
                    sp.mptcp_listen_port AS mptcp_sidecar_port,
                    sp.bond_listen_port AS sidecar_bond_listen_port,
                    sp.listen_port AS sidecar_listen_port,
                    sp.token_secret AS bond_sidecar_point_token_secret,
                    sp.agent_config_json AS bond_sidecar_point_agent_config_json,
                    e.id AS endpoint_id, e.node_id,
                    e.public_host AS endpoint_public_host,
                    e.public_port AS endpoint_public_port,
                    n.agent_config_json AS node_agent_config_json,
                    n.public_ip AS node_public_ip,
                    n.name AS node_name
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             INNER JOIN service_endpoints e ON e.forward_id = f.id
             INNER JOIN nodes n ON n.id = e.node_id
             LEFT JOIN forward_combinations combo ON combo.id = f.forward_combination_id
             LEFT JOIN sidecar_points sp ON sp.id = f.bond_sidecar_point_id
             WHERE n.id = :node_id AND f.id = :forward_id AND e.id = :endpoint_id
               AND f.enabled = 1
               AND e.health_status NOT IN (:deleted, :deleting)
             LIMIT 1',
            [
                'node_id' => $nodeId,
                'forward_id' => $forwardId,
                'endpoint_id' => $endpointId,
                'deleted' => 'deleted',
                'deleting' => 'deleting',
            ]
        );
        if ($row === null) {
            return null;
        }

        $node = [
            'id' => (int) ($row['node_id'] ?? 0),
            'name' => (string) ($row['node_name'] ?? ''),
            'public_ip' => (string) ($row['node_public_ip'] ?? ''),
            'agent_config_json' => $row['node_agent_config_json'] ?? null,
        ];

        return $this->publishTaskPayloadForForward($row, $node, $endpointId);
    }


    public function republishActiveEndpoint(int $nodeId, int $forwardId, int $endpointId): bool
    {
        if ($nodeId <= 0 || $forwardId <= 0 || $endpointId <= 0) {
            return false;
        }

        $row = $this->db->fetchOne(
            'SELECT e.*,
                    f.*,
                    e.id AS service_endpoint_id,
                    s.status AS service_status
             FROM service_endpoints e
             INNER JOIN forwards f ON f.id = e.forward_id
             INNER JOIN services s ON s.id = f.service_id
             WHERE e.id = :endpoint_id
               AND e.node_id = :node_id
               AND e.forward_id = :forward_id
               AND e.health_status <> :deleted
               AND e.health_status <> :deleting
               AND f.enabled = 1
               AND s.status = :service_status
             LIMIT 1',
            [
                'endpoint_id' => $endpointId,
                'node_id' => $nodeId,
                'forward_id' => $forwardId,
                'deleted' => 'deleted',
                'deleting' => 'deleting',
                'service_status' => 'active',
            ]
        );
        if (!is_array($row)) {
            return false;
        }

        $node = $this->db->fetchOne('SELECT * FROM nodes WHERE id = :id LIMIT 1', ['id' => $nodeId]);
        if (!is_array($node)) {
            return false;
        }

        $publicHost = $this->resolveNodePublicHost($node);
        if ($publicHost === '') {
            return false;
        }
        $node['resolved_public_host'] = $publicHost;
        $this->normalizeMalformedRepublishFailure($nodeId, $forwardId, $endpointId);
        $payload = $this->publishTaskPayloadForForward($row, $node, $endpointId);
        $this->queueTask(
            $nodeId,
            'publish',
            (int) ($row['service_id'] ?? 0),
            $forwardId,
            $endpointId,
            $payload
        );

        return true;
    }

    public function republishActiveForwardsForSidecarPoint(int $pointId): void
    {
        if ($pointId <= 0) {
            return;
        }
        $rows = $this->db->fetchAll(
            'SELECT f.id AS forward_id
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.enabled = 1
               AND f.client_mode = :client_mode
               AND f.bond_sidecar_point_id = :point_id
               AND s.status = :service_status',
            [
                'client_mode' => 'mptcp',
                'point_id' => $pointId,
                'service_status' => 'active',
            ]
        );
        foreach ($rows as $row) {
            $this->reconcileForwardEndpoints((int) ($row['forward_id'] ?? 0));
        }
    }

    public function republishActiveForwardsForCombination(int $combinationId): void
    {
        if ($combinationId <= 0) {
            return;
        }
        $forwards = $this->db->fetchAll(
            'SELECT f.id
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.forward_combination_id = :forward_combination_id
               AND f.enabled = 1
               AND s.status = "active"',
            ['forward_combination_id' => $combinationId]
        );
        foreach ($forwards as $row) {
            $forwardId = (int) ($row['id'] ?? 0);
            if ($forwardId <= 0) {
                continue;
            }
            try {
                $this->reconcileForwardEndpoints($forwardId);
            } catch (\Throwable $e) {
                error_log('republishActiveForwardsForCombination failed for forward ' . $forwardId . ': ' . $e->getMessage());
            }
        }
    }

    public function republishActiveForwardsForRoute(int $routeId): void
    {
        if ($routeId <= 0) {
            return;
        }
        $forwards = $this->db->fetchAll(
            'SELECT f.id
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.forward_route_id = :forward_route_id
               AND f.enabled = 1
               AND s.status = "active"',
            ['forward_route_id' => $routeId]
        );
        foreach ($forwards as $row) {
            $forwardId = (int) ($row['id'] ?? 0);
            if ($forwardId <= 0) {
                continue;
            }
            try {
                $this->reconcileForwardEndpoints($forwardId);
            } catch (\Throwable $e) {
                error_log('republishActiveForwardsForRoute failed for forward ' . $forwardId . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Persist a Forward Route (a bundle of forward combinations the ingress slave auto-races across).
     * Guard: all ENABLED combos must share the client->slave ingress realization (backend_mode +
     * node_group_key + client_mode + bond_ingress_mode) per B0 -- only the slave<->sidecar basis
     * (self_leg_outer_transport / sidecar_point / leigod_line_id) may differ.
     */
    public function saveForwardRoute(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('invalid_forward_route_payload', 422, ['payload' => $payload]);
        }
        $tenantLabel = trim((string) ($payload['tenant_label'] ?? ''));
        $tenantDescription = trim((string) ($payload['tenant_description'] ?? ''));
        $tenantBadge = trim((string) ($payload['tenant_badge'] ?? ''));
        $falsey = ['0', 'false', 'no', 'off', ''];
        $raceEnabled = !in_array(strtolower(trim((string) ($payload['race_enabled'] ?? '1'))), $falsey, true) ? 1 : 0;
        $isActive = !empty($payload['is_active']) ? 1 : 0;
        $sortOrder = (int) ($payload['sort_order'] ?? 100);

        $rawCombos = is_array($payload['combinations'] ?? null) ? $payload['combinations'] : [];
        $combos = [];
        foreach ($rawCombos as $row) {
            $cid = (int) (is_array($row) ? ($row['forward_combination_id'] ?? 0) : $row);
            if ($cid <= 0 || isset($combos[$cid])) {
                continue;
            }
            $enabled = is_array($row)
                ? (!in_array(strtolower(trim((string) ($row['enabled'] ?? '1'))), $falsey, true) ? 1 : 0)
                : 1;
            $combos[$cid] = [
                'forward_combination_id' => $cid,
                'enabled' => $enabled,
                'sort_order' => (int) (is_array($row) ? ($row['sort_order'] ?? 100) : 100),
            ];
        }

        $enabledCombinationCount = count(array_filter(
            $combos,
            static fn(array $row): bool => (int) ($row['enabled'] ?? 0) === 1
        ));
        if ($raceEnabled === 0 && $enabledCombinationCount !== 1) {
            throw new ApiException('forward_route_single_requires_one_combination', 422, [
                'enabled_count' => $enabledCombinationCount,
            ]);
        }
        if ($raceEnabled === 1 && $enabledCombinationCount < 2) {
            throw new ApiException('forward_route_race_requires_two_combinations', 422, [
                'enabled_count' => $enabledCombinationCount,
            ]);
        }

        $ingressKeys = [];
        foreach ($combos as $cid => $info) {
            if (!$info['enabled']) {
                continue;
            }
            $combo = $this->db->fetchOne(
                'SELECT backend_mode, node_group_key, client_mode, bond_ingress_mode, sidecar_point_id FROM forward_combinations WHERE id = :id LIMIT 1',
                ['id' => $cid]
            );
            if (!is_array($combo)) {
                throw new ApiException('forward_route_combination_not_found', 422, ['forward_combination_id' => $cid]);
            }
            // Same client->slave ingress (backend_mode + node group + client_mode + bond_ingress_mode)
            // AND same sidecar_point_id, so the slave selector's single sidecar_service_port + per-service
            // backend unit are shared across candidates (B0/R2). Only the slave<->sidecar basis differs.
            $ingressKeys[] = implode('|', [
                (string) ($combo['backend_mode'] ?? ''),
                (string) ($combo['node_group_key'] ?? ''),
                (string) ($combo['client_mode'] ?? ''),
                (string) ($combo['bond_ingress_mode'] ?? ''),
                (string) ($combo['sidecar_point_id'] ?? ''),
            ]);
        }
        if (count(array_unique($ingressKeys)) > 1) {
            throw new ApiException('forward_route_combos_ingress_mismatch', 422, [
                'ingress_keys' => array_values(array_unique($ingressKeys)),
            ]);
        }

        $normalized = [
            'name' => $name,
            'tenant_label' => $tenantLabel !== '' ? $tenantLabel : null,
            'tenant_description' => $tenantDescription !== '' ? $tenantDescription : null,
            'tenant_badge' => $tenantBadge !== '' ? $tenantBadge : null,
            'race_enabled' => $raceEnabled,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
            'updated_at' => $this->now(),
        ];
        if ($id > 0) {
            $this->db->execute(
                'UPDATE forward_routes
                 SET name = :name, tenant_label = :tenant_label, tenant_description = :tenant_description,
                     tenant_badge = :tenant_badge, race_enabled = :race_enabled, is_active = :is_active,
                     sort_order = :sort_order, updated_at = :updated_at
                 WHERE id = :id',
                $normalized + ['id' => $id]
            );
        } else {
            $id = $this->db->insert('forward_routes', $normalized + ['created_at' => $this->now()]);
        }

        $this->db->execute('DELETE FROM forward_route_combinations WHERE forward_route_id = :id', ['id' => $id]);
        foreach ($combos as $info) {
            $this->db->insert('forward_route_combinations', [
                'forward_route_id' => $id,
                'forward_combination_id' => $info['forward_combination_id'],
                'enabled' => $info['enabled'],
                'sort_order' => $info['sort_order'],
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
        }

        $this->republishActiveForwardsForRoute($id);
        return ['id' => $id];
    }

    public function republishActiveForwardsForClient(int $clientId): void
    {
        if ($clientId <= 0) {
            return;
        }
        $rows = $this->db->fetchAll(
            'SELECT f.id AS forward_id
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.enabled = 1
               AND f.line_client_id = :client_id
               AND s.status = :service_status',
            [
                'client_id' => $clientId,
                'service_status' => 'active',
            ]
        );
        foreach ($rows as $row) {
            $this->reconcileForwardEndpoints((int) ($row['forward_id'] ?? 0));
        }
    }

    public function republishActiveForwardsForNode(int $nodeId): void
    {
        if ($nodeId <= 0) {
            return;
        }
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT e.forward_id
             FROM service_endpoints e
             INNER JOIN forwards f ON f.id = e.forward_id
             INNER JOIN services s ON s.id = f.service_id
             WHERE e.node_id = :node_id
               AND e.health_status <> :deleted
               AND e.health_status <> :deleting
               AND f.enabled = 1
               AND s.status = :service_status',
            [
                'node_id' => $nodeId,
                'deleted' => 'deleted',
                'deleting' => 'deleting',
                'service_status' => 'active',
            ]
        );
        foreach ($rows as $row) {
            $this->reconcileForwardEndpoints((int) ($row['forward_id'] ?? 0));
        }
    }

    public function republishActiveForwardsForService(int $serviceId): void
    {
        if ($serviceId <= 0) {
            return;
        }
        $rows = $this->db->fetchAll(
            'SELECT f.id AS forward_id
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.enabled = 1
               AND f.service_id = :service_id
               AND s.status = :service_status',
            [
                'service_id' => $serviceId,
                'service_status' => 'active',
            ]
        );
        foreach ($rows as $row) {
            $this->reconcileForwardEndpoints((int) ($row['forward_id'] ?? 0));
        }
    }

    /**
     * @return array{republished:int, errors:string[]}
     */
    public function republishForwardsForServiceWithResult(int $serviceId): array
    {
        $errors = [];
        $count = 0;
        if ($serviceId <= 0) {
            return ['republished' => 0, 'errors' => $errors];
        }

        try {
            $this->republishActiveForwardsForService($serviceId);
            $rows = $this->db->fetchAll(
                'SELECT f.id
                 FROM forwards f
                 INNER JOIN services s ON s.id = f.service_id
                 WHERE f.enabled = 1
                   AND f.service_id = :service_id
                   AND s.status = :service_status',
                [
                    'service_id' => $serviceId,
                    'service_status' => 'active',
                ]
            );
            $count = count($rows);
        } catch (\Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }

        return ['republished' => $count, 'errors' => $errors];
    }

    /**
     * @return array{republished:int, errors:string[]}
     */
    public function republishForwardsForNodeWithResult(int $nodeId): array
    {
        $errors = [];
        $count = 0;
        if ($nodeId <= 0) {
            return ['republished' => 0, 'errors' => $errors];
        }

        try {
            $this->republishActiveForwardsForNode($nodeId);
            $rows = $this->db->fetchAll(
                'SELECT DISTINCT e.forward_id
                 FROM service_endpoints e
                 INNER JOIN forwards f ON f.id = e.forward_id
                 INNER JOIN services s ON s.id = f.service_id
                 WHERE e.node_id = :node_id
                   AND e.health_status <> :deleted
                   AND e.health_status <> :deleting
                   AND f.enabled = 1
                   AND s.status = :service_status',
                [
                    'node_id' => $nodeId,
                    'deleted' => 'deleted',
                    'deleting' => 'deleting',
                    'service_status' => 'active',
                ]
            );
            $count = count($rows);
        } catch (\Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }

        return ['republished' => $count, 'errors' => $errors];
    }

    /**
     * @return array{republished:int, errors:string[]}
     */
    public function republishForwardsForClientWithResult(int $clientId): array
    {
        $errors = [];
        $count = 0;
        if ($clientId <= 0) {
            return ['republished' => 0, 'errors' => $errors];
        }

        try {
            $this->republishActiveForwardsForClient($clientId);
            $rows = $this->db->fetchAll(
                'SELECT f.id
                 FROM forwards f
                 INNER JOIN services s ON s.id = f.service_id
                 WHERE f.enabled = 1
                   AND f.line_client_id = :client_id
                   AND s.status = :service_status',
                [
                    'client_id' => $clientId,
                    'service_status' => 'active',
                ]
            );
            $count = count($rows);
        } catch (\Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }

        return ['republished' => $count, 'errors' => $errors];
    }

    /**
     * @return array{republished:int, errors:string[]}
     */
    public function republishAllForwardsWithResult(): array
    {
        $errors = [];
        $count = 0;

        try {
            $rows = $this->db->fetchAll(
                'SELECT f.id
                 FROM forwards f
                 INNER JOIN services s ON s.id = f.service_id
                 WHERE f.enabled = 1
                   AND s.status = :service_status',
                ['service_status' => 'active']
            );
            foreach ($rows as $row) {
                $forwardId = (int) ($row['id'] ?? 0);
                if ($forwardId <= 0) {
                    continue;
                }
                try {
                    $this->reconcileForwardEndpoints($forwardId);
                    $count++;
                } catch (\Throwable $throwable) {
                    $errors[] = 'Forward ' . $forwardId . ': ' . $throwable->getMessage();
                }
            }
        } catch (\Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }

        return ['republished' => $count, 'errors' => $errors];
    }

    /**
     * Temporary compatibility shim for newer node agents that report
     * forward-chain remediation telemetry. The current panel baseline does not
     * yet act on these signals, but it must accept them so node heartbeats do
     * not fail and stall endpoint republish for active forwards.
     *
     * @param array<string, mixed> $agentConfig
     */
    public function applyForwardChainRemediationSignals(int $nodeId, array $agentConfig): void
    {
        if ($nodeId <= 0) {
            return;
        }
    }

    /**
     * @return array{uplinks: array<int, array<string, mixed>>, authoritative: bool}
     */
    public function slaveSidecarUplinkManagementForPoint(int $pointId): array
    {
        if ($pointId <= 0) {
            return ['uplinks' => [], 'authoritative' => false];
        }
        $uplinks = [];

        foreach ($this->staticSlaveSidecarCombinationsForPoint($pointId) as $combination) {
            foreach ($this->staticRentedSlavesForCombination((int) ($combination['id'] ?? 0)) as $node) {
                $pairs = $this->buildStaticSidecarUplinkPairForNode($combination, $node);
                if ($this->staticSidecarCombinationUsesSharedWireguardPeers($combination) && count($pairs) !== 1) {
                    return ['uplinks' => [], 'authoritative' => false];
                }
                foreach ($pairs as $lane) {
                    $uplinks[] = $lane['sidecar'];
                }
            }
        }

        return ['uplinks' => $this->deduplicateStaticSidecarManagementUplinks($uplinks), 'authoritative' => true];
    }

    /**
     * @return array{uplinks: array<int, array<string, mixed>>, authoritative: bool}
     */
    public function slaveSidecarUplinkManagementForNode(int $nodeId): array
    {
        if ($nodeId <= 0) {
            return ['uplinks' => [], 'authoritative' => false];
        }

        $uplinks = [];
        $node = $this->db->fetchOne('SELECT * FROM nodes WHERE id = :id LIMIT 1', ['id' => $nodeId]);
        if (!is_array($node)) {
            return ['uplinks' => [], 'authoritative' => false];
        }
        foreach ($this->staticSlaveSidecarCombinations() as $combination) {
            $selectedNodeIds = array_map(
                static fn(array $selected): int => (int) ($selected['id'] ?? $selected['node_id'] ?? 0),
                $this->staticRentedSlavesForCombination((int) ($combination['id'] ?? 0))
            );
            if (!in_array($nodeId, $selectedNodeIds, true)) {
                continue;
            }
            $pairs = $this->buildStaticSidecarUplinkPairForNode($combination, $node);
            if ($this->staticSidecarCombinationUsesSharedWireguardPeers($combination) && count($pairs) !== 1) {
                return ['uplinks' => [], 'authoritative' => false];
            }
            foreach ($pairs as $lane) {
                $uplinks[] = $lane['slave'];
            }
        }

        return ['uplinks' => $this->deduplicateStaticSidecarManagementUplinks($uplinks), 'authoritative' => true];
    }

    /**
     * @param array<int, array<string, mixed>> $uplinks
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateStaticSidecarManagementUplinks(array $uplinks): array
    {
        $deduped = [];
        foreach ($uplinks as $uplink) {
            if (!is_array($uplink)) {
                continue;
            }
            $shared = (string) ($uplink['wireguard_peer_mode'] ?? '') === 'shared';
            if ($shared) {
                $pointId = (int) ($uplink['sidecar_point_id'] ?? 0);
                $nodeId = (int) ($uplink['node_id'] ?? 0);
                if ($pointId <= 0 || $nodeId <= 0) {
                    continue;
                }
                $key = 'shared:' . $pointId . ':' . $nodeId;
            } else {
                $interfaceName = trim((string) ($uplink['interface_name'] ?? ''));
                if ($interfaceName === '') {
                    continue;
                }
                $key = 'legacy:' . $interfaceName;
            }
            if (!isset($deduped[$key])) {
                $deduped[$key] = $uplink;
            }
        }
        return array_values($deduped);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function staticSlaveSidecarCombinations(): array
    {
        return $this->db->fetchAll(
            'SELECT fc.*, sp.name AS sidecar_point_name, sp.sidecar_host,
                    sp.listen_port AS sidecar_listen_port,
                    sp.bond_listen_port AS sidecar_bond_listen_port,
                    sp.mptcp_listen_port AS sidecar_mptcp_listen_port,
                    sp.cf_listen_port AS sidecar_cf_listen_port,
                    sp.agent_config_json AS sidecar_agent_config_json
             FROM forward_combinations fc
             INNER JOIN sidecar_points sp ON sp.id = fc.sidecar_point_id
             WHERE fc.is_active = 1
             ORDER BY fc.sort_order ASC, fc.id ASC'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function staticSlaveSidecarCombinationsForPoint(int $pointId): array
    {
        if ($pointId <= 0) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT fc.*, sp.name AS sidecar_point_name, sp.sidecar_host,
                    sp.listen_port AS sidecar_listen_port,
                    sp.bond_listen_port AS sidecar_bond_listen_port,
                    sp.mptcp_listen_port AS sidecar_mptcp_listen_port,
                    sp.cf_listen_port AS sidecar_cf_listen_port,
                    sp.agent_config_json AS sidecar_agent_config_json
             FROM forward_combinations fc
             INNER JOIN sidecar_points sp ON sp.id = fc.sidecar_point_id
             WHERE fc.sidecar_point_id = :point_id
               AND fc.is_active = 1
             ORDER BY fc.sort_order ASC, fc.id ASC',
            ['point_id' => $pointId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function staticSlaveSidecarCombinationsForNode(int $nodeId): array
    {
        if ($nodeId <= 0) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT fc.*, sp.name AS sidecar_point_name, sp.sidecar_host,
                    sp.listen_port AS sidecar_listen_port,
                    sp.bond_listen_port AS sidecar_bond_listen_port,
                    sp.mptcp_listen_port AS sidecar_mptcp_listen_port,
                    sp.cf_listen_port AS sidecar_cf_listen_port,
                    sp.agent_config_json AS sidecar_agent_config_json
             FROM forward_combination_slaves fcs
             INNER JOIN forward_combinations fc ON fc.id = fcs.forward_combination_id
             INNER JOIN sidecar_points sp ON sp.id = fc.sidecar_point_id
             WHERE fcs.node_id = :node_id
               AND fcs.is_active = 1
               AND fc.is_active = 1
             ORDER BY fc.sort_order ASC, fc.id ASC',
            ['node_id' => $nodeId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function staticRentedSlavesForCombination(int $combinationId): array
    {
        if ($combinationId <= 0) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT n.*, g.group_key AS node_group_key, fcs.sort_order AS pin_sort_order
             FROM forward_combination_slaves fcs
             INNER JOIN nodes n ON n.id = fcs.node_id
             LEFT JOIN node_groups g ON g.id = n.group_id
             WHERE fcs.forward_combination_id = :forward_combination_id
               AND fcs.is_active = 1
               AND (n.status = :active OR n.status = :degraded)
             ORDER BY fcs.sort_order ASC, n.id ASC',
            [
                'forward_combination_id' => $combinationId,
                'active' => 'active',
                'degraded' => 'degraded',
            ]
        );
        if ($rows !== []) {
            return $rows;
        }

        $combination = $this->forwardCombinationById($combinationId);
        if (!is_array($combination)) {
            return [];
        }
        $cap = max(0, (int) ($combination['helper_group_cap'] ?? 0));
        $selected = $this->pickFastestSlavesForCombination($combinationId, $cap);
        return array_values(array_map(
            static fn(array $candidate): array => is_array($candidate['row'] ?? null) ? $candidate['row'] : [],
            array_filter($selected, static fn(array $candidate): bool => ($candidate['candidate_source'] ?? '') === 'rented_node')
        ));
    }

    /**
     * @return array<int, array{sidecar: array<string, mixed>, slave: array<string, mixed>}>
     */
    private function buildStaticSidecarUplinkPairForNode(array $combination, array $node): array
    {
        $combinationId = (int) ($combination['id'] ?? $combination['forward_combination_id'] ?? 0);
        $pointId = (int) ($combination['sidecar_point_id'] ?? 0);
        $nodeId = (int) ($node['id'] ?? $node['node_id'] ?? 0);
        $sharedPeerMode = $pointId > 0 && $this->staticSidecarCombinationUsesSharedWireguardPeers($combination);
        if (($combinationId <= 0 && !$sharedPeerMode) || $pointId <= 0 || $nodeId <= 0) {
            return [];
        }
        if ($this->staticSidecarUsesSharedWireguardPeers($pointId)) {
            $this->primeSharedStaticSidecarSubnetRegistryForPoint($pointId);
        }

        $sidecarConfig = $this->decodeJsonAssoc($combination['sidecar_agent_config_json'] ?? null);
        if ($sidecarConfig === [] && isset($combination['agent_config_json'])) {
            $sidecarConfig = $this->decodeJsonAssoc($combination['agent_config_json'] ?? null);
        }
        $nodeConfig = $this->decodeJsonAssoc($node['agent_config_json'] ?? null);
        $sidecarPublicKey = trim((string) ($sidecarConfig['wireguard_public_key'] ?? ''));
        $slavePublicKey = trim((string) ($nodeConfig['wireguard_public_key'] ?? ''));
        if ($sidecarPublicKey === '' || $slavePublicKey === '') {
            return [];
        }

        $sidecarHost = trim((string) ($combination['sidecar_host'] ?? ''));
        if ($sidecarHost === '' && $pointId > 0) {
            $point = $this->db->fetchOne('SELECT sidecar_host, listen_port, bond_listen_port, mptcp_listen_port, cf_listen_port, agent_config_json FROM sidecar_points WHERE id = :id LIMIT 1', ['id' => $pointId]);
            if (is_array($point)) {
                $sidecarHost = trim((string) ($point['sidecar_host'] ?? ''));
                if ($sidecarConfig === []) {
                    $sidecarConfig = $this->decodeJsonAssoc($point['agent_config_json'] ?? null);
                }
            }
        }
        $wireguardEndpointHost = trim((string) ($sidecarConfig['wireguard_endpoint_host'] ?? ''));
        if ($wireguardEndpointHost === '') {
            $wireguardEndpointHost = $sidecarHost;
        }

        $listenSource = [
            'listen_port' => (int) ($combination['sidecar_listen_port'] ?? 0),
            'bond_listen_port' => (int) ($combination['sidecar_bond_listen_port'] ?? 0),
            'mptcp_listen_port' => (int) ($combination['sidecar_mptcp_listen_port'] ?? 0),
        ];
        $sidecarListenPort = $this->sidecarWireguardListenPort($listenSource, $sidecarConfig);
        $sidecarPortRangeEnd = $this->sidecarWireguardPortRangeEnd($sidecarListenPort, $sidecarConfig);
        // A leigod self-leg is exclusive: even when the client->slave ingress is cf_mode (so the combo can
        // race against a cf combo on the same sidecar), the slave<->sidecar uplink is built as leigod ONLY.
        // Without this, a cf_mode + self_leg_outer_transport=leigod combo would trip BOTH branches and emit a
        // hybrid (cf_* and leigod_* fields) uplink.
        $leigodMode = $this->staticSidecarCombinationUsesLeigod($combination);
        $cfMode = $leigodMode ? false : $this->staticSidecarCombinationUsesCf($combination);
        // MPTCP overlay routing across the leigod uplink: collect the overlay client/sidecar
        // addresses of every enabled MPTCP forward bound to this combination+node. The static
        // uplink (shared per combo+node) must carry them so the slave routes overlay-sidecar
        // traffic over the leigod hop and the sidecar owns the overlay-sidecar address (so its
        // 0.0.0.0 MPTCP listener receives the subflow) + routes overlay-client return traffic
        // back. Without this the overlay blackholes out the public default route and no MPTCP
        // subflow reaches the sidecar -- the data-path gap that left leigod_mode unable to carry
        // load despite a healthy WG/ICMP control plane.
        $overlaySidecarIps = [];
        $overlayClientIps = [];
        if ($leigodMode) {
            $overlayForwards = $this->db->fetchAll(
                'SELECT DISTINCT f.id FROM forwards f
                 JOIN service_endpoints se ON se.forward_id = f.id
                 WHERE f.forward_combination_id = :cid AND f.enabled = 1
                   AND f.client_mode = :mptcp AND se.node_id = :nid',
                ['cid' => $combinationId, 'mptcp' => 'mptcp', 'nid' => $nodeId]
            );
            foreach ($overlayForwards as $overlayForwardRow) {
                $overlayForwardId = (int) ($overlayForwardRow['id'] ?? 0);
                if ($overlayForwardId <= 0) {
                    continue;
                }
                $leg = $this->stableMptcpLegAddressing($overlayForwardId, $nodeId);
                $overlaySidecar = trim((string) ($leg['sidecar_address'] ?? ''));
                $overlayClient = trim((string) ($leg['client_address'] ?? ''));
                if ($overlaySidecar !== '') {
                    $overlaySidecarIps[] = $overlaySidecar;
                }
                if ($overlayClient !== '') {
                    $overlayClientIps[] = $overlayClient;
                }
            }
            $overlaySidecarIps = array_values(array_unique($overlaySidecarIps));
            $overlayClientIps = array_values(array_unique($overlayClientIps));
        }
        if ($sharedPeerMode) {
            $sidecarListenPort = (int) ($sidecarConfig['wireguard_listen_port'] ?? 0);
            if ($sidecarListenPort <= 0 || $sidecarListenPort > 65535 || $sidecarHost === '') {
                return [];
            }
            $addresses = $this->allocateStaticSidecarUplinkSubnet($pointId, 0, $nodeId, 'shared');
            $relayPort = $this->sidecarBondListenPort([
                'listen_port' => (int) ($combination['sidecar_listen_port'] ?? 0),
                'bond_listen_port' => (int) ($combination['sidecar_bond_listen_port'] ?? 0),
            ]);
            if ($relayPort <= 0 || $relayPort > 65535) {
                return [];
            }
            $base = [
                'enabled' => true,
                'mode' => 'static_slave_sidecar',
                'wireguard_peer_mode' => 'shared',
                'forward_combination_id' => $combinationId,
                'sidecar_point_id' => $pointId,
                'node_id' => $nodeId,
                'lane' => 'shared',
                'subnet' => $addresses['subnet'],
                'listen_port' => $sidecarListenPort,
                'outer_transport' => 'udp',
                'peer_keepalive_seconds' => max(1, (int) ($this->config->getInt('STATIC_SIDECAR_WG_KEEPALIVE_SECONDS', 25) ?? 25)),
                'watchdog' => [
                    'enabled' => true,
                    'handshake_stale_seconds' => max(10, (int) ($this->config->getInt('STATIC_SIDECAR_WG_HANDSHAKE_STALE_SECONDS', 90) ?? 90)),
                    'restart_cooldown_seconds' => max(10, (int) ($this->config->getInt('STATIC_SIDECAR_WG_RESTART_COOLDOWN_SECONDS', 30) ?? 30)),
                ],
            ];
            $sidecar = $base + [
                'interface_name' => $this->sharedSidecarWireguardInterfaceName($pointId),
                'local_address' => $addresses['sidecar_address'],
                'peer_address' => $addresses['slave_address'],
                'slave_peer_public_key' => $slavePublicKey,
                'sidecar_peer_public_key' => $sidecarPublicKey,
                'relay_target' => $addresses['sidecar_address'] . ':' . $relayPort,
            ];
            $slave = $base + [
                'interface_name' => $this->sharedSlaveWireguardInterfaceName($pointId, $nodeId),
                'local_address' => $addresses['slave_address'],
                'peer_address' => $addresses['sidecar_address'],
                'sidecar_peer_public_key' => $sidecarPublicKey,
                'slave_peer_public_key' => $slavePublicKey,
                'sidecar_endpoint_host' => $wireguardEndpointHost,
                'sidecar_endpoint_port' => $sidecarListenPort,
                'local_listen_port' => $this->stableStaticLocalUdpPort($pointId, 0, $nodeId, 'shared'),
                'relay_target' => $addresses['sidecar_address'] . ':' . $relayPort,
            ];
            return [['sidecar' => $sidecar, 'slave' => $slave]];
        }
        $lanes = [];
        foreach (['primary' => 'p', 'backup' => 'b'] as $lane => $suffix) {
            $addresses = $this->allocateStaticSidecarUplinkSubnet($pointId, $combinationId, $nodeId, $lane);
            $listenPort = $this->allocateStaticSidecarUplinkPort($sidecarListenPort, $sidecarPortRangeEnd, $pointId, $combinationId, $nodeId, $lane);
            if ($listenPort <= 0) {
                continue;
            }
            $interfaceName = $this->staticSidecarUplinkInterfaceName($pointId, $combinationId, $nodeId, $suffix);
            $base = [
                'enabled' => true,
                'mode' => 'static_slave_sidecar',
                'forward_combination_id' => $combinationId,
                'sidecar_point_id' => $pointId,
                'node_id' => $nodeId,
                'lane' => $lane,
                'interface_name' => $interfaceName,
                'subnet' => $addresses['subnet'],
                'listen_port' => $listenPort,
                'outer_transport' => $leigodMode ? 'leigod' : ($cfMode ? 'wstunnel_udp' : 'udp'),
                'peer_keepalive_seconds' => max(1, (int) ($this->config->getInt('STATIC_SIDECAR_WG_KEEPALIVE_SECONDS', 25) ?? 25)),
                'watchdog' => [
                    'enabled' => true,
                    'handshake_stale_seconds' => max(10, (int) ($this->config->getInt('STATIC_SIDECAR_WG_HANDSHAKE_STALE_SECONDS', 90) ?? 90)),
                    'restart_cooldown_seconds' => max(10, (int) ($this->config->getInt('STATIC_SIDECAR_WG_RESTART_COOLDOWN_SECONDS', 30) ?? 30)),
                ],
            ];
            $slaveListenPort = $this->stableStaticLocalUdpPort($pointId, $combinationId, $nodeId, $lane);
            $relayPort = $this->sidecarBondListenPort([
                'listen_port' => (int) ($combination['sidecar_listen_port'] ?? 0),
                'bond_listen_port' => (int) ($combination['sidecar_bond_listen_port'] ?? 0),
            ]);
            $sidecar = $base + [
                'local_address' => $addresses['sidecar_address'],
                'peer_address' => $addresses['slave_address'],
                'slave_peer_public_key' => $slavePublicKey,
                'sidecar_peer_public_key' => $sidecarPublicKey,
                'relay_target' => $addresses['sidecar_address'] . ':' . $relayPort,
            ];
            $slave = $base + [
                'local_address' => $addresses['slave_address'],
                'peer_address' => $addresses['sidecar_address'],
                'sidecar_peer_public_key' => $sidecarPublicKey,
                'slave_peer_public_key' => $slavePublicKey,
                'sidecar_endpoint_host' => $wireguardEndpointHost,
                'sidecar_endpoint_port' => $listenPort,
                'local_listen_port' => $slaveListenPort,
                'relay_target' => $addresses['sidecar_address'] . ':' . $relayPort,
            ];
            if ($cfMode) {
                $pathPrefix = $this->staticSidecarCfPathPrefix($pointId, $combinationId, $nodeId, $lane);
                $cfListenPort = (int) ($combination['sidecar_cf_listen_port'] ?? 0);
                if ($cfListenPort <= 0) {
                    $cfListenPort = 443;
                }
                $cfSniHost = trim((string) ($sidecarConfig['cf_sni_host'] ?? $sidecarConfig['cf_sni'] ?? ''));
                $cfGroupId = (int) ($combination['cf_location_group_id'] ?? 0);
                $cfGroup = $cfGroupId > 0
                    ? $this->db->fetchOne('SELECT * FROM cf_location_groups WHERE id = :id LIMIT 1', ['id' => $cfGroupId])
                    : null;
                $cfCidrRows = $cfGroupId > 0
                    ? $this->db->fetchAll(
                        'SELECT cidr, priority, is_active FROM cf_location_group_cidrs WHERE group_id = :group_id AND is_active = 1 ORDER BY priority ASC, id ASC',
                        ['group_id' => $cfGroupId]
                    )
                    : [];
                $localUdpPort = $slaveListenPort;
                $sidecar['cf_path_prefix'] = $pathPrefix;
                $sidecar['cf_target_host'] = '127.0.0.1';
                $sidecar['cf_target_port'] = $listenPort;
                $slave['cf_path_prefix'] = $pathPrefix;
                $slave['cf_dial_host'] = $sidecarHost;
                $slave['cf_dial_port'] = $cfListenPort;
                $slave['cf_sni_host'] = $cfSniHost;
                $slave['cf_target_host'] = '127.0.0.1';
                $slave['cf_target_port'] = $listenPort;
                $slave['local_wstunnel_udp_port'] = $localUdpPort;
                $slave['sidecar_endpoint_host'] = '127.0.0.1';
                $slave['sidecar_endpoint_port'] = $localUdpPort;
                if ($cfGroupId > 0 && is_array($cfGroup) && $cfCidrRows !== []) {
                    $slave['cf_mode'] = [
                        'location_group_id' => $cfGroupId,
                        'group_name' => (string) ($cfGroup['name'] ?? ''),
                        'region_code' => (string) ($cfGroup['region_code'] ?? ''),
                        'probe_mode' => (string) ($cfGroup['probe_mode'] ?? 'auto'),
                        'cache_ttl_seconds' => max(30, (int) ($cfGroup['cache_ttl_seconds'] ?? 120)),
                        'switch_improvement_ms' => max(1, (int) ($cfGroup['switch_improvement_ms'] ?? 15)),
                        'max_candidates' => max(1, min(256, (int) ($cfGroup['max_candidates'] ?? 32))),
                        'probe_samples' => max(1, min(5, (int) ($this->config->getInt('STATIC_SIDECAR_CF_PROBE_SAMPLES', 1) ?? 1))),
                        'min_success_samples' => max(1, min(5, (int) ($this->config->getInt('STATIC_SIDECAR_CF_MIN_SUCCESS_SAMPLES', 1) ?? 1))),
                        'allow_probe_target_failures' => true,
                        'cidrs' => array_values(array_map(static fn(array $row): string => (string) $row['cidr'], $cfCidrRows)),
                        'dial_host' => $sidecarHost,
                        'dial_port' => $cfListenPort,
                        'sni_host' => $cfSniHost,
                        'path_prefix' => $pathPrefix,
                    ];
                }
            }
            if ($leigodMode) {
                // LeiGod basis: the slave<->sidecar WG hop rides a LeiGod line via gost UoT.
                // Slave runs leigod@<line> (SOCKS 127.0.0.1:20000+line) + a gost client; the sidecar
                // runs the matching gost UoT server. WG endpoint is re-pinned to the local gost listener.
                $gostSocks = $this->stableSidecarGostSocksPort($combinationId, $nodeId, $lane);
                [$socksUser, $socksPass] = $this->mintLeigodSocksCredentials($combination);
                // leigod_uot_impl selects the UoT carrier published to BOTH ends of this lane:
                // 'gost' (default) or 'lguot' (CPU-efficient batched recvmmsg/sendmmsg, ~2.4x/leg).
                $leigodUotImpl = strtolower((string) ($combination['leigod_uot_impl'] ?? 'gost')) === 'lguot' ? 'lguot' : 'gost';
                $slave['leigod_line_id'] = (int) ($combination['leigod_line_id'] ?? 0);
                $slave['gost_listen_udp_port'] = $slaveListenPort;
                $slave['sidecar_gost_host'] = $sidecarHost;
                $slave['sidecar_gost_port'] = $gostSocks;
                $slave['sidecar_wg_listen_port'] = $listenPort;
                $slave['socks_user'] = $socksUser;
                $slave['socks_pass'] = $socksPass;
                $slave['leigod_uot_impl'] = $leigodUotImpl;
                $slave['mtu'] = $this->config->getInt('BOND_LEIGOD_WG_MTU', 1280);
                $slave['sidecar_endpoint_host'] = '127.0.0.1';
                $slave['sidecar_endpoint_port'] = $slaveListenPort;
                $slave['leigod_line_toml'] = $this->leigodLineToml((int) ($combination['leigod_line_id'] ?? 0));
                $sidecar['gost_socks_listen_port'] = $gostSocks;
                $sidecar['socks_user'] = $socksUser;
                $sidecar['socks_pass'] = $socksPass;
                $sidecar['leigod_uot_impl'] = $leigodUotImpl;
                // MPTCP overlay stitching across the leigod hop (computed once above per combo+node).
                $slave['overlay_peer_allowed_ips'] = $overlaySidecarIps;
                $sidecar['overlay_peer_allowed_ips'] = $overlayClientIps;
                $sidecar['overlay_local_addresses'] = $overlaySidecarIps;
            }
            $lanes[] = ['sidecar' => $sidecar, 'slave' => $slave];
        }

        return $lanes;
    }

    /**
     * @param array<string, mixed> $forward
     * @param array<string, mixed> $combination
     */
    private function reconcileCombinationSelectorCap(array $forward, array $combination): int
    {
        $backendMode = strtolower(trim((string) ($forward['backend_mode'] ?? '')));
        $clientMode = $this->normalizeClientMode((string) ($forward['client_mode'] ?? 'single'));
        if ($backendMode === 'leigod_mode' && $clientMode === 'bond') {
            return 8;
        }

        return max(0, (int) ($combination['helper_group_cap'] ?? 0));
    }

    /**
     * @param array<int, array<string, mixed>> $selectedSlaves
     * @return array<int, array<string, mixed>>
     */
    private function reconcileRentedNodes(array $selectedSlaves, int $cap): array
    {
        $nodesById = [];
        foreach ($selectedSlaves as $candidate) {
            if (($candidate['candidate_source'] ?? '') !== 'rented_node') {
                continue;
            }
            $node = is_array($candidate['row'] ?? null) ? $candidate['row'] : [];
            $nodeId = (int) ($node['id'] ?? 0);
            if ($nodeId > 0 && !isset($nodesById[$nodeId])) {
                $nodesById[$nodeId] = $node;
            }
        }

        $nodes = array_values($nodesById);
        return $cap > 0 ? array_slice($nodes, 0, $cap) : $nodes;
    }

    private function reconcileForwardEndpoints(int $forwardId): void
    {
        if ($forwardId <= 0) {
            return;
        }
        $forward = $this->db->fetchOne(
            'SELECT f.*, s.status AS service_status
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.id = :id LIMIT 1',
            ['id' => $forwardId]
        );
        if ($forward === null || (int) ($forward['enabled'] ?? 0) !== 1 || (string) ($forward['service_status'] ?? '') !== 'active') {
            return;
        }
        $groupKeys = $this->orderedHelperGroupKeysForForward($forward);
        $clientMode = $this->normalizeClientMode((string) ($forward['client_mode'] ?? 'single'));
        $runtimePreference = $this->runtimePreferenceForForward($forward);
        $serviceId = (int) ($forward['service_id'] ?? 0);
        $desiredCount = $this->panelManagedEndpointCount($clientMode, $groupKeys, $serviceId ?: null);
        $combinationId = (int) ($forward['forward_combination_id'] ?? 0);
        $fixedEndpointLeigodBond = $this->usesFixedEndpointLeigodBond($forward);
        $existing = $this->db->fetchAll(
            'SELECT * FROM service_endpoints
             WHERE forward_id = :forward_id
               AND health_status <> :deleted
               AND health_status <> :deleting
             ORDER BY priority ASC, id ASC',
            [
                'forward_id' => $forwardId,
                'deleted' => 'deleted',
                'deleting' => 'deleting',
            ]
        );
        $preferredNodeIds = [];
        foreach ($existing as $endpoint) {
            if (!in_array((string) ($endpoint['health_status'] ?? ''), ['active', 'pending'], true)) {
                continue;
            }
            $nodeId = (int) ($endpoint['node_id'] ?? 0);
            if ($nodeId > 0) {
                $preferredNodeIds[] = $nodeId;
            }
        }
        $eligibleLeigodNodeIds = null;
        if ($this->isDedicatedLeigodRuntime((string) ($forward['backend_mode'] ?? ''), $clientMode, $runtimePreference) || $fixedEndpointLeigodBond) {
            $transport = $this->leigodEligibilityTransport((string) ($forward['transport_mode'] ?? 'tcp'));
            $routeId = (int) ($forward['forward_route_id'] ?? 0);
            $eligibleLeigodNodes = $routeId > 0
                ? $this->leigodLineCatalog()->eligibleNodesForRoute($routeId, $transport)
                : $this->leigodLineCatalog()->eligibleNodesForCombination($combinationId, $transport);
            $eligibleLeigodNodeIds = array_fill_keys(array_map(
                static fn(array $node): int => (int) ($node['id'] ?? 0),
                $eligibleLeigodNodes
            ), true);
        }
        if ($fixedEndpointLeigodBond) {
            $specificNodeId = $this->specificNodeIdFromCustomEndpointIds($forward['custom_endpoint_ids_json'] ?? null);
            $specificNode = $specificNodeId !== null
                ? $this->specificNodeForForward($specificNodeId, $serviceId)
                : null;
            $desiredNodes = is_array($specificNode)
                && (is_array($eligibleLeigodNodeIds) && isset($eligibleLeigodNodeIds[$specificNodeId]))
                ? [$specificNode]
                : [];
        } elseif ($combinationId > 0) {
            $combination = $this->forwardCombinationById($combinationId) ?? [];
            $selectorCap = $this->reconcileCombinationSelectorCap($forward, $combination);
            $selectedSlaves = $this->pickFastestSlavesForCombination($combinationId, $selectorCap);
            $desiredNodes = $this->reconcileRentedNodes($selectedSlaves, $selectorCap);
        } else {
            $specificNodeId = $this->specificNodeIdFromCustomEndpointIds($forward['custom_endpoint_ids_json'] ?? null);
            if ($specificNodeId !== null) {
                $specificNode = $this->specificNodeForForward($specificNodeId, $serviceId);
                $desiredNodes = is_array($specificNode) ? [$specificNode] : [];
            } else {
                $desiredNodes = $this->pickNodesForGroups(
                    $groupKeys,
                    $desiredCount,
                    $serviceId ?: null,
                    $preferredNodeIds
                );
            }
        }
        if (is_array($eligibleLeigodNodeIds)) {
            $desiredNodes = array_values(array_filter(
                $desiredNodes,
                static fn(array $node): bool => isset($eligibleLeigodNodeIds[(int) ($node['id'] ?? 0)])
            ));
        }
        $reachableNodes = [];
        foreach ($desiredNodes as $node) {
            $publicHost = $this->resolveNodePublicHost($node);
            if ($publicHost === '') {
                continue;
            }
            $node['resolved_public_host'] = $publicHost;
            $reachableNodes[(int) $node['id']] = $node;
        }
        $existingByNode = [];
        foreach ($existing as $endpoint) {
            $nodeId = (int) ($endpoint['node_id'] ?? 0);
            if ($nodeId > 0 && !isset($existingByNode[$nodeId])) {
                $existingByNode[$nodeId] = $endpoint;
            }
        }

        foreach ($reachableNodes as $nodeId => $node) {
            if (!isset($existingByNode[$nodeId])) {
                $priority = 10 + (count($existingByNode) * 10);
                $endpointId = $this->db->insert('service_endpoints', [
                    'forward_id' => $forwardId,
                    'node_id' => $nodeId,
                    'public_host' => (string) $node['resolved_public_host'],
                    'public_ip' => (string) ($node['public_ip'] ?? ''),
                    'public_port' => (int) ($forward['public_port'] ?? 0),
                    'cdn_host' => (string) ($forward['cdn_host'] ?? ''),
                    'priority' => $priority,
                    'weight' => 100,
                    'health_status' => 'pending',
                    'last_error' => null,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $payload = $this->publishTaskPayloadForForward($forward, $node, $endpointId);
                $this->queueTask($nodeId, 'publish', (int) ($forward['service_id'] ?? 0), $forwardId, $endpointId, $payload);
                continue;
            }
            $endpoint = $existingByNode[$nodeId];
            $endpointId = (int) ($endpoint['id'] ?? 0);
            $payload = $this->publishTaskPayloadForForward($forward, $node, $endpointId);
            $status = (string) ($endpoint['health_status'] ?? '');
            $fingerprint = $this->desiredEndpointPayloadFingerprint($forward, $node, $payload);
            $isUnconverged = in_array($status, ['pending', 'error', 'degraded'], true);
            // C.4a/C.4c: re-publish pending/error/degraded as before, AND re-publish an ACTIVE
            // endpoint whenever the materially-published desired payload drifted vs. the last
            // fingerprint we published for it. Unchanged active endpoints are left untouched so
            // we do not re-queue tasks on every reconcile cycle.
            $drifted = $this->endpointDesiredPayloadDrifted($endpointId, $fingerprint);
            // FIX A: an unconverged (pending|error|degraded) endpoint must NOT be re-published every
            // heartbeat. queueTask -> collapsePendingProvisionTasksForNode supersedes the prior pending
            // task, so a naive per-heartbeat re-queue created+superseded a fresh task every cycle and
            // (with oldest-first LIMIT pulls) it was buried/superseded before any agent claimed it ->
            // task-churn livelock. Only re-queue the unconverged case when there is NOT already a viable
            // task the agent can pull (a pending row, or a running row with a future lease), with a
            // staleness escape so a genuinely stuck pending task is eventually retried. The DRIFT path
            // for ACTIVE endpoints is preserved exactly as-is (it must always re-publish on a payload
            // change), and it is the agent-pull idempotency, not drift, that we are de-churning here.
            $unconvergedShouldQueue = $isUnconverged
                && !$this->hasViablePublishTask($nodeId, (int) ($forward['service_id'] ?? 0), $forwardId, $endpointId);
            if ($unconvergedShouldQueue || $drifted) {
                $this->queueTask($nodeId, 'publish', (int) ($forward['service_id'] ?? 0), $forwardId, $endpointId, $payload);
                $this->storeEndpointDesiredPayloadFingerprint($endpointId, $fingerprint);
            }
        }

        $membershipChanged = false;
        foreach ($existingByNode as $nodeId => $endpoint) {
            if (isset($reachableNodes[$nodeId])) {
                continue;
            }
            $membershipChanged = true;
            $removedEndpointId = (int) ($endpoint['id'] ?? 0);
            $serviceId = (int) ($forward['service_id'] ?? 0);
            // C.2: a removed member's slave-side sidecar-uplink lane selector must be torn down
            // explicitly so it does not linger binding its loopback port / racing dead candidates.
            $this->queueTask((int) $nodeId, 'destroy', $serviceId, $forwardId, $removedEndpointId, [
                'listen_port' => (int) ($endpoint['public_port'] ?? 0),
                'path_prefix' => (string) ($forward['path_prefix'] ?? ''),
                'reason' => 'reconcile',
                'selector_teardown' => [
                    'enabled' => true,
                    'service_id' => $serviceId,
                    'endpoint_id' => $removedEndpointId,
                    'reason' => 'membership_change',
                ],
            ]);
            $this->db->execute(
                'UPDATE service_endpoints SET health_status = :status, updated_at = :updated_at WHERE id = :id',
                [
                    'id' => $removedEndpointId,
                    'status' => 'deleting',
                    'updated_at' => $this->now(),
                ]
            );
            // The selector candidate set is per (point, combination, node); dropping a member
            // shrinks it, so drop the removed endpoint's fingerprint to force the next reconcile
            // to re-publish surviving endpoints with the recomputed candidate list.
            $this->storeEndpointDesiredPayloadFingerprint($removedEndpointId, '');
        }

        // C.2: when membership changed, force the surviving endpoints of this forward to be
        // re-published so their recomputed selector candidate list no longer references the
        // dropped member (the active-drift fingerprint above will fire on the next pass, but we
        // proactively invalidate it here so a single reconcile converges).
        if ($membershipChanged) {
            foreach ($reachableNodes as $survivingNodeId => $survivingNode) {
                $survivingEndpoint = $existingByNode[$survivingNodeId] ?? null;
                if (!is_array($survivingEndpoint)) {
                    continue;
                }
                $this->storeEndpointDesiredPayloadFingerprint((int) ($survivingEndpoint['id'] ?? 0), '');
            }
        }
    }

    /**
     * C.4a/C.4c: a stable sha1 over the fields of the materially-published payload that actually
     * drift between reconcile cycles — chosen target host/port, sidecar point/uplink selection,
     * routing/transport mode, ingress/backend protocol, combination membership and the resolved
     * public host of the chosen slave. A change in any of these means an already-active endpoint
     * is serving a stale config and must be re-published.
     *
     * @param array<string, mixed> $forward
     * @param array<string, mixed> $node
     * @param array<string, mixed> $payload
     */
    private function desiredEndpointPayloadFingerprint(array $forward, array $node, array $payload): string
    {
        $sidecarUplink = is_array($payload['sidecar_uplink'] ?? null) ? $payload['sidecar_uplink'] : [];
        $selector = is_array($payload['sidecar_uplink_selector'] ?? null) ? $payload['sidecar_uplink_selector'] : [];
        $material = [
            'target' => (string) ($payload['target'] ?? ''),
            'backend_mode' => (string) ($payload['backend_mode'] ?? ''),
            'transport_mode' => (string) ($payload['transport_mode'] ?? ''),
            'ingress_protocol' => (string) ($payload['ingress_protocol'] ?? ''),
            'backend_protocol' => (string) ($payload['backend_protocol'] ?? ''),
            'publish_bond_runtime' => (bool) ($payload['publish_bond_runtime'] ?? false),
            'forward_combination_id' => $payload['forward_combination_id'] ?? null,
            'path_prefix' => (string) ($payload['path_prefix'] ?? ''),
            'sni_host' => (string) ($payload['sni_host'] ?? ''),
            'cdn_host' => (string) ($payload['cdn_host'] ?? ''),
            'origin_group_key' => (string) ($payload['origin_group_key'] ?? ''),
            'node_id' => (int) ($node['id'] ?? $node['node_id'] ?? 0),
            'resolved_public_host' => (string) ($node['resolved_public_host'] ?? ''),
            'sidecar_point_id' => (int) ($sidecarUplink['sidecar_point_id'] ?? $forward['bond_sidecar_point_id'] ?? 0),
            'sidecar_uplink_interface' => (string) ($sidecarUplink['interface_name'] ?? ''),
            'sidecar_uplink_listen_port' => (int) ($sidecarUplink['listen_port'] ?? 0),
            'sidecar_relay_target' => (string) ($sidecarUplink['relay_target'] ?? ''),
            'selector_enabled' => (bool) ($selector['enabled'] ?? false),
            'selector_candidate_count' => is_array($selector['candidates'] ?? null) ? count($selector['candidates']) : 0,
            'selector_single_lane_monitor' => (bool) ($selector['single_lane_monitor'] ?? false),
            'leigod_forward' => $this->materialLeigodForwardFingerprint($payload['leigod_forward'] ?? null),
            'server_side_bond' => $this->materialServerSideBondFingerprint($payload['server_side_bond'] ?? null),
        ];
        return sha1((string) json_encode($material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function endpointDesiredPayloadFingerprintKey(int $endpointId): string
    {
        return 'endpoint_desired_payload_fingerprint:' . $endpointId;
    }

    private function endpointDesiredPayloadDrifted(int $endpointId, string $fingerprint): bool
    {
        if ($endpointId <= 0) {
            return false;
        }
        $stored = $this->appSettingGet($this->endpointDesiredPayloadFingerprintKey($endpointId));
        return $stored === null || $stored !== $fingerprint;
    }

    private function storeEndpointDesiredPayloadFingerprint(int $endpointId, string $fingerprint): void
    {
        if ($endpointId <= 0) {
            return;
        }
        $this->appSettingSet($this->endpointDesiredPayloadFingerprintKey($endpointId), $fingerprint);
    }

    /**
     * @param array<string, mixed> $forward
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */

    /**
     * @param array<string, mixed> $forward
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function runtimePreferenceForForward(array $forward): string
    {
        $runtimePreference = trim((string) ($forward['forward_combination_runtime_preference'] ?? $forward['runtime_preference'] ?? ''));
        if ($runtimePreference !== '') {
            return $runtimePreference;
        }

        $combinationId = (int) ($forward['forward_combination_id'] ?? 0);
        if ($combinationId > 0) {
            $combination = $this->db->fetchOne('SELECT runtime_preference FROM forward_combinations WHERE id = :id LIMIT 1', ['id' => $combinationId]);
            if (is_array($combination)) {
                $runtimePreference = trim((string) ($combination['runtime_preference'] ?? ''));
                if ($runtimePreference !== '') {
                    return $runtimePreference;
                }
            }
        }

        return 'single';
    }

    private function buildCfModePayload(array $forward, array $node): array
    {
        $groupId = (int) ($forward['cf_location_group_id'] ?? 0);
        $group = $groupId > 0
            ? $this->db->fetchOne('SELECT * FROM cf_location_groups WHERE id = :id LIMIT 1', ['id' => $groupId])
            : null;
        if (!is_array($group)) {
            throw new ApiException('cf_location_group_not_found', 404, ['cf_location_group_id' => $groupId]);
        }

        $cidrRows = $this->db->fetchAll(
            'SELECT cidr, priority, is_active FROM cf_location_group_cidrs WHERE group_id = :group_id AND is_active = 1 ORDER BY priority ASC, id ASC',
            ['group_id' => $groupId]
        );
        if ($cidrRows === []) {
            throw new ApiException('cf_location_group_empty', 422, ['cf_location_group_id' => $groupId]);
        }

        $point = (int) ($forward['bond_sidecar_point_id'] ?? 0) > 0
            ? $this->db->fetchOne('SELECT sidecar_host, listen_port, bond_listen_port, mptcp_listen_port, cf_listen_port, agent_config_json FROM sidecar_points WHERE id = :id LIMIT 1', ['id' => (int) $forward['bond_sidecar_point_id']])
            : null;
        $pointHost = trim((string) ($point['sidecar_host'] ?? $forward['bond_remote_host'] ?? ''));
        if ($pointHost === '') {
            throw new ApiException('cf_mode_sidecar_host_missing', 422, ['forward_id' => (int) ($forward['id'] ?? 0)]);
        }
        $dialPort = max(1, (int) ($point['cf_listen_port'] ?? 443));
        $pointConfig = $this->decodeJsonAssoc($point['agent_config_json'] ?? null);
        $cfSniHost = trim((string) ($pointConfig['cf_sni_host'] ?? $pointConfig['cf_sni'] ?? ''));
        if ($cfSniHost === '') {
            $cfSniHost = trim((string) ($forward['sni_host'] ?? ''));
        }

        return [
            'location_group_id' => $groupId,
            'group_name' => (string) ($group['name'] ?? ''),
            'region_code' => (string) ($group['region_code'] ?? ''),
            'probe_mode' => (string) ($group['probe_mode'] ?? 'auto'),
            'cache_ttl_seconds' => max(30, (int) ($group['cache_ttl_seconds'] ?? 120)),
            'switch_improvement_ms' => max(1, (int) ($group['switch_improvement_ms'] ?? 15)),
            'max_candidates' => max(1, min(256, (int) ($group['max_candidates'] ?? 32))),
            'cidrs' => array_values(array_map(static fn(array $row): string => (string) $row['cidr'], $cidrRows)),
            'dial_host' => $pointHost,
            'dial_port' => $dialPort,
            'sni_host' => $cfSniHost,
            'path_prefix' => (string) ($forward['path_prefix'] ?? ''),
            'route_key' => (string) ($forward['path_prefix'] ?? ''),
            'ingress_auth_token' => $this->bondIngressAuthToken($forward, (int) ($node['id'] ?? 0), (string) ($forward['path_prefix'] ?? '')),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function staticSidecarUplinksForForwardNode(array $forward, array $node): array
    {
        // Force direct-TCP to the sidecar (no WireGuard/UDP uplink) for forwards on
        // UDP-hostile transit links (e.g. the HK CNIX line drops UDP but carries TCP
        // fine). Listed forward IDs skip the static WG uplink + selector and fall
        // through to resolveSidecarDirectTarget() = sidecar_host:bond_listen_port.
        $directForwardIds = array_filter(array_map(
            static fn($v): int => (int) trim((string) $v),
            explode(',', (string) ($this->config->get('SIDECAR_DIRECT_TCP_FORWARD_IDS', '') ?? ''))
        ));
        if ($directForwardIds !== [] && in_array((int) ($forward['id'] ?? 0), $directForwardIds, true)) {
            return [];
        }
        // Per-sidecar-point variant: every forward whose sidecar point is listed uses
        // direct-TCP (e.g. the HK CNIX point), so existing + future forwards on that
        // point are covered automatically without per-forward bookkeeping.
        $directPointIds = array_filter(array_map(
            static fn($v): int => (int) trim((string) $v),
            explode(',', (string) ($this->config->get('SIDECAR_DIRECT_TCP_POINT_IDS', '') ?? ''))
        ));
        if ($directPointIds !== [] && in_array((int) ($forward['bond_sidecar_point_id'] ?? 0), $directPointIds, true)) {
            return [];
        }
        // Forward Route racing: when the forward belongs to a race-ON route, emit ONE primary uplink
        // per enabled route-combo (same sidecar, per B0/R2). The existing selector then races them and
        // rewrites the active target. Falls through to the single-combo path otherwise.
        $routeCombos = $this->enabledRouteCombinationsForForward($forward);
        if (count($routeCombos) >= 2) {
            $routeNodeId = (int) ($node['id'] ?? $node['node_id'] ?? 0);
            if ($routeNodeId <= 0) {
                return [];
            }
            $routeUplinks = [];
            foreach ($routeCombos as $routeCombo) {
                $routeCombo = $this->hydrateCombinationSidecarFields($routeCombo, (int) ($forward['bond_sidecar_point_id'] ?? 0));
                foreach ($this->buildStaticSidecarUplinkPairForNode($routeCombo, $node) as $lane) {
                    $slave = $lane['slave'];
                    if ($this->isCanonicalStaticSidecarUplink($slave)) {
                        $key = (int) ($slave['sidecar_point_id'] ?? 0)
                            . ':' . (int) ($slave['node_id'] ?? 0)
                            . ':' . (string) ($slave['interface_name'] ?? '');
                        $routeUplinks[$key] = $slave;
                    }
                }
            }
            if ($routeUplinks !== []) {
                return array_values($routeUplinks);
            }
        }
        $combinationId = (int) ($forward['forward_combination_id'] ?? 0);
        $nodeId = (int) ($node['id'] ?? $node['node_id'] ?? 0);
        if ($nodeId <= 0) {
            return [];
        }
        if ($combinationId <= 0) {
            if (!$this->usesSingleRaceSidecarFrontend($forward)) {
                return [];
            }
            $combination = [
                'id' => 0,
                'sidecar_point_id' => (int) ($forward['bond_sidecar_point_id'] ?? 0),
                'backend_mode' => (string) ($forward['backend_mode'] ?? 'current_mode'),
                'client_mode' => (string) ($forward['client_mode'] ?? 'single'),
                'runtime_preference' => $this->runtimePreferenceForForward($forward),
                'self_leg_outer_transport' => 'cnix',
            ];
        } else {
            $combination = $this->forwardCombinationById($combinationId);
            if (!is_array($combination)) {
                return [];
            }
        }
        if ((int) ($combination['sidecar_point_id'] ?? 0) <= 0 && (int) ($forward['bond_sidecar_point_id'] ?? 0) > 0) {
            $combination['sidecar_point_id'] = (int) $forward['bond_sidecar_point_id'];
        }
        $combination = $this->hydrateCombinationSidecarFields($combination, (int) ($forward['bond_sidecar_point_id'] ?? 0));

        return array_values(array_map(
            static fn(array $lane): array => $lane['slave'],
            $this->buildStaticSidecarUplinkPairForNode($combination, $node)
        ));
    }

    /**
     * Fill a combination's sidecar_* fields (host, listen ports, agent config) from its sidecar_point
     * when absent. Shared by the single-combo and the route-racing uplink builders.
     */
    private function hydrateCombinationSidecarFields(array $combination, int $fallbackPointId = 0): array
    {
        if (!empty($combination['sidecar_host']) && !empty($combination['sidecar_agent_config_json'])) {
            return $combination;
        }
        $pointId = (int) ($combination['sidecar_point_id'] ?? 0);
        if ($pointId <= 0) {
            $pointId = $fallbackPointId;
        }
        if ($pointId <= 0) {
            return $combination;
        }
        $point = $this->db->fetchOne(
            'SELECT sidecar_host, listen_port, bond_listen_port, mptcp_listen_port, cf_listen_port, agent_config_json FROM sidecar_points WHERE id = :id LIMIT 1',
            ['id' => $pointId]
        );
        if (is_array($point)) {
            $combination['sidecar_host'] = (string) ($point['sidecar_host'] ?? '');
            $combination['sidecar_listen_port'] = (int) ($point['listen_port'] ?? 0);
            $combination['sidecar_bond_listen_port'] = (int) ($point['bond_listen_port'] ?? 0);
            $combination['sidecar_mptcp_listen_port'] = (int) ($point['mptcp_listen_port'] ?? 0);
            $combination['sidecar_cf_listen_port'] = (int) ($point['cf_listen_port'] ?? 0);
            $combination['sidecar_agent_config_json'] = $point['agent_config_json'] ?? null;
        }
        return $combination;
    }

    /**
     * Enabled combinations of a forward's race-ON route, lowest sort_order first, capped, and
     * restricted to the forward's sidecar point so all candidates share one sidecar service port.
     * Returns [] when the forward has no route, the route is race-OFF, or fewer than the cap apply.
     */
    private function enabledRouteCombinationsForForward(array $forward): array
    {
        $routeId = (int) ($forward['forward_route_id'] ?? 0);
        if ($routeId <= 0) {
            return [];
        }
        $route = $this->db->fetchOne('SELECT race_enabled FROM forward_routes WHERE id = :id LIMIT 1', ['id' => $routeId]);
        if (!is_array($route) || (int) ($route['race_enabled'] ?? 0) !== 1) {
            return [];
        }
        $cap = max(1, (int) ($this->config->getInt('FORWARD_ROUTE_RACE_CANDIDATE_CAP', 3) ?? 3));
        $rows = $this->db->fetchAll(
            'SELECT fc.* FROM forward_route_combinations frc
             INNER JOIN forward_combinations fc ON fc.id = frc.forward_combination_id
             WHERE frc.forward_route_id = :id AND frc.enabled = 1 AND fc.is_active = 1
             ORDER BY frc.sort_order ASC, frc.id ASC',
            ['id' => $routeId]
        );
        $pointId = (int) ($forward['bond_sidecar_point_id'] ?? 0);
        $out = [];
        foreach ($rows as $fc) {
            if ($pointId > 0 && (int) ($fc['sidecar_point_id'] ?? 0) > 0 && (int) ($fc['sidecar_point_id'] ?? 0) !== $pointId) {
                continue; // same-sidecar only (R2): candidates must share the sidecar service port
            }
            $out[] = $fc;
            if (count($out) >= $cap) {
                break;
            }
        }
        return $out;
    }

    private function staticSidecarPrimaryUplinkForForwardNode(array $forward, array $node, string $targetMode): ?array
    {
        $uplinks = $this->staticSidecarUplinksForForwardNode($forward, $node);
        if ($uplinks === []) {
            return null;
        }
        $sidecarServicePort = 0;
        $normalizedTargetMode = $this->normalizeClientMode($targetMode);
        if (in_array($normalizedTargetMode, ['bond', 'mptcp'], true)) {
            $sidecarServicePort = (int) ($this->resolveSidecarDirectTarget($forward, $normalizedTargetMode)['port'] ?? 0);
        }
        foreach ($this->staticSidecarUplinksWithServicePort($uplinks, $sidecarServicePort) as $slave) {
            if (!$this->isCanonicalStaticSidecarUplink($slave)) {
                continue;
            }
            return $slave;
        }
        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $uplinks
     * @return array<int, array<string, mixed>>
     */
    private function staticSidecarUplinksWithServicePort(array $uplinks, int $sidecarServicePort): array
    {
        if ($sidecarServicePort <= 0) {
            return $uplinks;
        }
        foreach ($uplinks as &$uplink) {
            $peerAddress = trim((string) ($uplink['peer_address'] ?? ''));
            if ($peerAddress === '') {
                continue;
            }
            $uplink['relay_target'] = $peerAddress . ':' . $sidecarServicePort;
        }
        unset($uplink);
        return $uplinks;
    }

    /**
     * @param array<int, array<string, mixed>> $uplinks
     * @return array<string, mixed>
     */
    private function staticSidecarUplinkSelectorPayload(array $uplinks, int $sidecarServicePort, int $forwardId, int $endpointId): array
    {
        // Emergency relief valve: when STATIC_SIDECAR_SELECTOR_ENABLED is false, emit no selector at
        // all. On the next reconcile each agent then calls remove_sidecar_uplink_selector and tears the
        // selector services down fleet-wide. Default TRUE preserves the current behavior.
        if (!$this->config->getBool('STATIC_SIDECAR_SELECTOR_ENABLED', true)) {
            return [];
        }
        if ($sidecarServicePort <= 0 || $uplinks === []) {
            return [];
        }

        $candidates = [];
        $firstCombinationId = 0;
        $firstPointId = 0;
        $firstNodeId = 0;
        foreach ($uplinks as $uplink) {
            if (!($uplink['enabled'] ?? false)) {
                continue;
            }
            $interface = trim((string) ($uplink['interface_name'] ?? ''));
            $peerAddress = trim((string) ($uplink['peer_address'] ?? ''));
            if ($interface === '' || $peerAddress === '') {
                continue;
            }
            $combinationId = (int) ($uplink['forward_combination_id'] ?? 0);
            $pointId = (int) ($uplink['sidecar_point_id'] ?? 0);
            $nodeId = (int) ($uplink['node_id'] ?? 0);
            if ($firstCombinationId <= 0) {
                $firstCombinationId = $combinationId;
                $firstPointId = $pointId;
                $firstNodeId = $nodeId;
            }
            $candidates[] = [
                'lane' => (string) ($uplink['lane'] ?? ''),
                'forward_combination_id' => $combinationId,
                'interface_name' => $interface,
                'ping_interface' => $interface,
                'ping_address' => $peerAddress,
                'sidecar_address' => $peerAddress,
                'peer_address' => $peerAddress,
                'sidecar_port' => $sidecarServicePort,
            ];
        }

        $candidateCount = count($candidates);
        // C.7c: a single-lane forward normally gets NO selector (failover needs >=2 lanes). Behind
        // STATIC_SIDECAR_SELECTOR_SINGLE_LANE_RESTART (default off) we still emit a MONITOR-ONLY
        // selector for the single lane so a dead-but-handshaking one-way path can be detected and
        // restarted. The selector is flagged single_lane_monitor so the slave runs it as a watchdog
        // rather than a failover switch.
        $singleLaneMonitor = false;
        if ($candidateCount < 2) {
            if ($candidateCount === 1 && $this->config->getBool('STATIC_SIDECAR_SELECTOR_SINGLE_LANE_RESTART', false)) {
                $singleLaneMonitor = true;
            } else {
                return [];
            }
        }

        $selectorPort = $this->collisionFreeSidecarSelectorPort($firstNodeId, $firstPointId, $firstCombinationId, $forwardId, $endpointId);
        return [
            'enabled' => true,
            'strategy' => 'wg_allowed_ip_ping',
            'single_lane_monitor' => $singleLaneMonitor,
            'selector_port' => $selectorPort,
            'sidecar_service_port' => $sidecarServicePort,
            'probe_interval_seconds' => max(1, (int) ($this->config->getInt('STATIC_SIDECAR_SELECTOR_PROBE_INTERVAL_SECONDS', 3) ?? 3)),
            'ping_count' => max(1, (int) ($this->config->getInt('STATIC_SIDECAR_SELECTOR_PING_COUNT', 3) ?? 3)),
            'ping_timeout_seconds' => max(1, (int) ($this->config->getInt('STATIC_SIDECAR_SELECTOR_PING_TIMEOUT_SECONDS', 1) ?? 1)),
            'switch_improvement_ms' => max(1, (int) ($this->config->getInt('STATIC_SIDECAR_SELECTOR_SWITCH_IMPROVEMENT_MS', 3) ?? 3)),
            'max_active_avg_ms' => max(1, (int) ($this->config->getInt('STATIC_SIDECAR_SELECTOR_MAX_ACTIVE_AVG_MS', 70) ?? 70)),
            'max_loss_percent' => max(0, (int) ($this->config->getInt('STATIC_SIDECAR_SELECTOR_MAX_LOSS_PERCENT', 0) ?? 0)),
            'restart_cooldown_seconds' => max(10, (int) ($this->config->getInt('STATIC_SIDECAR_SELECTOR_RESTART_COOLDOWN_SECONDS', 60) ?? 60)),
            'candidates' => $candidates,
        ];
    }

    /**
     * Publish the race learner policy from panel configuration. The default remains latency-first;
     * throughput learning is deliberately opt-in and is never attached to single or bond relays.
     *
     * @return array<string, int|float|string>
     */
    private function leigodRacePolicyPayload(string $mode): array
    {
        if ($mode !== 'race') {
            return [];
        }
        $selectionSignal = strtolower(trim((string) ($this->config->get('LEIGOD_RACE_SELECTION_SIGNAL', 'connect_latency') ?? 'connect_latency')));
        if (!in_array($selectionSignal, ['connect_latency', 'throughput_ewma'], true)) {
            $selectionSignal = 'connect_latency';
        }
        $policy = ['selection_signal' => $selectionSignal];
        if ($selectionSignal !== 'throughput_ewma') {
            return $policy;
        }
        $floatConfig = function (string $key, float $default, float $minimum, float $maximum): float {
            $raw = $this->config->get($key);
            $value = is_numeric($raw) ? (float) $raw : $default;
            if (!is_finite($value)) {
                $value = $default;
            }
            return max($minimum, min($maximum, $value));
        };
        return $policy + [
            'throughput_min_bytes' => max(1, min(64 * 1024 * 1024, $this->config->getInt('LEIGOD_RACE_THROUGHPUT_MIN_BYTES', 1048576))),
            'throughput_min_seconds' => $floatConfig('LEIGOD_RACE_THROUGHPUT_MIN_SECONDS', 2.0, 0.001, 300.0),
            'throughput_ewma_alpha' => $floatConfig('LEIGOD_RACE_THROUGHPUT_EWMA_ALPHA', 0.25, 0.001, 1.0),
            'throughput_explore_every_connections' => max(2, min(10000, $this->config->getInt('LEIGOD_RACE_THROUGHPUT_EXPLORE_EVERY_CONNECTIONS', 20))),
            'throughput_sample_ttl_seconds' => $floatConfig('LEIGOD_RACE_THROUGHPUT_SAMPLE_TTL_SECONDS', 1800.0, 30.0, 86400.0),
            'throughput_stall_interval_seconds' => $floatConfig('LEIGOD_RACE_THROUGHPUT_STALL_INTERVAL_SECONDS', 1.0, 0.001, 60.0),
        ];
    }

    private function publishTaskPayloadForForward(array $forward, array $node, int $endpointId): array
    {
        $clientMode = $this->normalizeClientMode((string) ($forward['client_mode'] ?? 'single'));
        $bondIngressMode = $this->normalizeBondIngressMode((string) ($forward['bond_ingress_mode'] ?? 'wss_path_mux'));
        $runtimePreference = $this->runtimePreferenceForForward($forward);
        $backendMode = (string) ($forward['backend_mode'] ?? 'current_mode');
        $aggregationEnabled = $this->combinationUsesAggregation($clientMode, $runtimePreference);
        $sidecarFrontend = $this->usesSingleRaceSidecarFrontend($forward);
        $sidecarRequired = $sidecarFrontend
            || $this->sidecarRequiredForRuntime($backendMode, $clientMode, $runtimePreference);
        $dedicatedLeigod = $backendMode === 'leigod_mode' && $clientMode === 'single' && !$sidecarRequired;
        $fixedEndpointLeigodBond = $this->usesFixedEndpointLeigodBond($forward);
        $serverSideLeigodBond = $this->usesServerSideLeigodBond($forward);
        if ($serverSideLeigodBond && strtolower(trim((string) ($forward['transport_mode'] ?? 'tcp'))) !== 'tcp') {
            throw new ApiException('server_side_leigod_bond_requires_tcp', 409, [
                'forward_id' => (int) ($forward['id'] ?? 0),
                'transport_mode' => (string) ($forward['transport_mode'] ?? ''),
            ]);
        }
        $activeFamily = $sidecarFrontend
            ? 'sidecar_aggregation'
            : $this->activeFamilyForRuntime($backendMode, $clientMode, $runtimePreference);
        $targetContract = $sidecarFrontend
            ? 'sidecar_aggregation'
            : $this->targetContractForRuntime($backendMode, $clientMode, $runtimePreference);
        $publishesBondRuntime = $clientMode === 'bond' || $runtimePreference === 'mptcp_preferred_bond_fallback';
        $publishesBondRuntime = $aggregationEnabled && $publishesBondRuntime;
        $publishesBondRuntime = $publishesBondRuntime || $sidecarFrontend;
        $plainTcpIngress = $publishesBondRuntime && $bondIngressMode === 'plain_tcp_mux';
        $routeKey = (string) ($forward['path_prefix'] ?? '');
        $directTargetMode = $publishesBondRuntime ? 'bond' : $clientMode;
        $directSidecarTarget = $sidecarRequired && in_array($directTargetMode, ['bond', 'mptcp'], true)
            ? $this->resolveSidecarDirectTarget($forward, $directTargetMode)
            : null;
        $staticSidecarUplinks = $directSidecarTarget !== null && !$fixedEndpointLeigodBond
            ? $this->staticSidecarUplinksWithServicePort($this->staticSidecarUplinksForForwardNode($forward, $node), (int) ($directSidecarTarget['port'] ?? 0))
            : [];
        $staticSidecarUplink = $directSidecarTarget !== null
            ? (array_values(array_filter($staticSidecarUplinks, fn(array $uplink): bool => $this->isCanonicalStaticSidecarUplink($uplink)))[0] ?? null)
            : null;
        $sharedSidecarRequired = $directSidecarTarget !== null && $this->forwardRequiresSharedStaticSidecarUplink($forward);
        if ($sharedSidecarRequired && (int) ($forward['public_port'] ?? 0) === 443) {
            throw new ApiException('shared_sidecar_public_port_forbidden', 409, [
                'forward_id' => (int) ($forward['id'] ?? 0),
                'bond_sidecar_point_id' => (int) ($forward['bond_sidecar_point_id'] ?? 0),
            ]);
        }
        if ($sharedSidecarRequired && (
            !is_array($staticSidecarUplink)
            || (string) ($staticSidecarUplink['wireguard_peer_mode'] ?? '') !== 'shared'
        )) {
            throw new ApiException('shared_sidecar_uplink_unavailable', 409, [
                'forward_id' => (int) ($forward['id'] ?? 0),
                'node_id' => (int) ($node['id'] ?? $node['node_id'] ?? 0),
                'bond_sidecar_point_id' => (int) ($forward['bond_sidecar_point_id'] ?? 0),
            ]);
        }
        $staticSidecarSelector = $directSidecarTarget !== null
            ? $this->staticSidecarUplinkSelectorPayload($staticSidecarUplinks, (int) ($directSidecarTarget['port'] ?? 0), (int) ($forward['id'] ?? 0), $endpointId)
            : [];
        $backendProtocol = $plainTcpIngress && ($backendMode !== 'cf_mode' || $staticSidecarUplink !== null)
            ? 'tcp_relay'
            : 'wss_backend';
        if ($backendMode === 'cf_mode' && $directSidecarTarget !== null) {
            $publishTarget = '127.0.0.1:' . $directSidecarTarget['port'];
        } else {
            $publishTarget = $directSidecarTarget !== null
                ? ($directSidecarTarget['host'] . ':' . $directSidecarTarget['port'])
                : ((string) ($forward['target_host'] ?? '') . ':' . (int) ($forward['target_port'] ?? 0));
        }
        if (is_array($staticSidecarUplink) && trim((string) ($staticSidecarUplink['relay_target'] ?? '')) !== '') {
            $publishTarget = (string) $staticSidecarUplink['relay_target'];
        }
        $leigodForward = null;
        $serverSideBond = null;
        if ($dedicatedLeigod || $fixedEndpointLeigodBond) {
            $candidates = $this->leigodLineCatalog()->candidatesForForwardOnNode($forward, (int) ($node['id'] ?? 0));
            if ($candidates === []) {
                throw new ApiException('leigod_route_no_healthy_candidate', 409, [
                    'node_id' => (int) ($node['id'] ?? 0),
                    'forward_route_id' => (int) ($forward['forward_route_id'] ?? 0),
                    'forward_combination_id' => (int) ($forward['forward_combination_id'] ?? 0),
                ]);
            }
            if ($fixedEndpointLeigodBond && (count($candidates) < 2 || count($candidates) > 8)) {
                throw new ApiException('leigod_fixed_endpoint_candidate_count_invalid', 409, [
                    'node_id' => (int) ($node['id'] ?? 0),
                    'candidate_count' => count($candidates),
                ]);
            }
            if ($fixedEndpointLeigodBond) {
                $candidates = array_values(array_map(static function (array $candidate): array {
                    $candidate['leg_route_id'] = (int) ($candidate['line_id'] ?? 0);
                    return $candidate;
                }, $candidates));
                if ($serverSideLeigodBond) {
                    $candidates = $this->serverSideLeigodParallelCandidates($candidates);
                }
            }
            $relayPort = $this->stableLeigodRelayPort((int) ($forward['id'] ?? 0), $endpointId);
            $routeId = (int) ($forward['forward_route_id'] ?? 0);
            $route = !$fixedEndpointLeigodBond && $routeId > 0
                ? $this->db->fetchOne('SELECT race_enabled FROM forward_routes WHERE id = :id LIMIT 1', ['id' => $routeId])
                : null;
            $relayTarget = $fixedEndpointLeigodBond
                ? $this->resolveSidecarDirectTarget($forward, 'bond')
                : [
                    'host' => (string) ($forward['target_host'] ?? ''),
                    'port' => (int) ($forward['target_port'] ?? 0),
                ];
            $leigodMode = $fixedEndpointLeigodBond
                ? 'bond'
                : (is_array($route) && (int) ($route['race_enabled'] ?? 0) === 1 ? 'race' : 'single');
            $leigodForward = [
                'mode' => $leigodMode,
                'relay_port' => $relayPort,
                'target' => (string) $relayTarget['host'] . ':' . (int) $relayTarget['port'],
                'candidates' => $candidates,
                'health_max_age_seconds' => max(15, min(3600, $this->config->getInt('LEIGOD_LINE_HEALTH_MAX_AGE_SECONDS', 180))),
                'switch_improvement_ms' => max(1, min(1000, $this->config->getInt('LEIGOD_FORWARD_SWITCH_IMPROVEMENT_MS', 15))),
                'minimum_hold_seconds' => max(1, min(3600, $this->config->getInt('LEIGOD_FORWARD_MINIMUM_HOLD_SECONDS', 30))),
                'failure_threshold' => max(1, min(100, $this->config->getInt('LEIGOD_FORWARD_FAILURE_THRESHOLD', 2))),
                'recovery_success_threshold' => max(1, min(100, $this->config->getInt('LEIGOD_FORWARD_RECOVERY_SUCCESS_THRESHOLD', 2))),
            ];
            $leigodForward += $this->leigodRacePolicyPayload($leigodMode);
            $publishTarget = '127.0.0.1:' . $relayPort;
            if ($serverSideLeigodBond) {
                $frontendPort = $this->stableLeigodServerBondPort($endpointId);
                $publicPort = (int) ($forward['public_port'] ?? 0);
                if (in_array($frontendPort, [$relayPort, $publicPort], true) || $relayPort === $publicPort) {
                    throw new ApiException('server_side_leigod_bond_port_collision', 500, [
                        'frontend_port' => $frontendPort,
                        'relay_port' => $relayPort,
                        'public_port' => $publicPort,
                    ]);
                }
                ['issued_at' => $issuedAt, 'expires_at' => $expiresAt] = $this->bondTokenTimestamps();
                $serverSideBond = array_merge([
                    'frontend_port' => $frontendPort,
                    'leg_relay_target' => '127.0.0.1:' . $relayPort,
                    'sidecar_target' => (string) ($leigodForward['target'] ?? ''),
                    'session_token' => $this->buildBondSessionToken(
                        $forward,
                        $this->bondTokenSecret($forward),
                        $issuedAt,
                        $expiresAt,
                        'tcp'
                    ),
                    'token_expires_at' => $expiresAt,
                    'legs' => array_values(array_map(
                        static fn(array $candidate): array => [
                            'leg_route_id' => (int) ($candidate['leg_route_id'] ?? 0),
                        ],
                        $candidates
                    )),
                    'preferred_initial_legs' => count($candidates),
                    'minimum_initial_legs' => min(2, count($candidates)),
                    'initial_leg_wait_ms' => 3000,
                    'leg_reconnect_backoff_ms' => 5000,
                    'leg_reconnect_backoff_max_ms' => 60000,
                    'warmup_leg_delay_ms' => max(1, $this->config->getInt('BOND_WARMUP_LEG_DELAY_MS', 20)),
                    'leg_cooldown_ms' => max(1, $this->config->getInt('BOND_LEIGOD_LEG_COOLDOWN_MS', 20000)),
                ], $this->serverSideLeigodBondPolicy());
                $publishTarget = '127.0.0.1:' . $frontendPort;
            }
        }
        return [
            'listen_port' => (int) ($forward['public_port'] ?? 0),
            'target' => $publishTarget,
            'origin_group_key' => (string) ($node['node_group_key'] ?? $node['group_key'] ?? ''),
            'forward_combination_id' => ($forward['forward_combination_id'] ?? null) !== null ? (int) $forward['forward_combination_id'] : null,
            'contract_version' => 1,
            'backend_mode' => $backendMode,
            'client_mode' => $clientMode,
            'runtime_preference' => $runtimePreference,
            'bond_ingress_mode' => $bondIngressMode,
            'active_family' => $activeFamily,
            'aggregation_enabled' => $aggregationEnabled,
            'target_contract' => $targetContract,
            'sidecar_required' => $sidecarRequired,
            'publish_bond_runtime' => $publishesBondRuntime,
            'cf_mode' => $backendMode === 'cf_mode'
                ? $this->buildCfModePayload($forward, $node)
                : null,
            'path_prefix' => (string) ($forward['path_prefix'] ?? ''),
            'route_key' => $routeKey,
            'sni_host' => (string) ($forward['sni_host'] ?? ''),
            'cdn_host' => (string) ($forward['cdn_host'] ?? ''),
            'tls_cert_ref' => (string) $this->config->get('DEFAULT_TLS_CERT_REF', 'default-edge-cert'),
            'filter_profile' => (string) ($forward['filter_profile'] ?? 'off'),
            'transport_mode' => (string) ($forward['transport_mode'] ?? 'tcp+udp'),
            'ingress_protocol' => $plainTcpIngress ? 'plain_tcp_mux' : 'wss_path_mux',
            'backend_protocol' => $backendProtocol,
            'leigod_forward' => $leigodForward,
            'server_side_bond' => $serverSideBond,
            'sidecar_uplink' => $staticSidecarUplink ?? [],
            'sidecar_uplink_selector' => $staticSidecarSelector,
            'ingress_auth_token' => $plainTcpIngress
                ? $this->bondIngressAuthToken($forward, $endpointId, $routeKey)
                : '',
            'leg_relay_protocols' => $this->legRelayProtocolsForNode($clientMode, $node),
            'mptcp_underlay' => $clientMode === 'mptcp' ? $this->buildMptcpSlaveUnderlayPayload($forward, $node, [
                'id' => $endpointId,
                'node_id' => (int) ($node['id'] ?? 0),
                'public_port' => (int) ($forward['public_port'] ?? 0),
            ]) : null,
            'donation_policy' => $this->donationPolicyForForward($forward),
        ];
    }

    private function stableLeigodRelayPort(int $forwardId, int $endpointId): int
    {
        if ($forwardId <= 0 || $endpointId <= 0) {
            throw new ApiException('invalid_leigod_relay_identity', 500);
        }
        return 48000 + ($endpointId % 10000);
    }

    private function stableLeigodServerBondPort(int $endpointId): int
    {
        if ($endpointId <= 0) {
            throw new ApiException('invalid_server_side_leigod_bond_identity', 500);
        }
        return 58000 + ($endpointId % 7000);
    }

    private function isDedicatedLeigodRuntime(string $backendMode, string $clientMode, string $runtimePreference): bool
    {
        return $backendMode === 'leigod_mode'
            && $this->normalizeClientMode($clientMode) === 'single'
            && !$this->sidecarRequiredForRuntime($backendMode, $clientMode, $runtimePreference);
    }

    private function leigodEligibilityTransport(string $transportMode): string
    {
        $transportMode = strtolower(trim($transportMode));
        if ($transportMode === 'both' || str_contains($transportMode, '+')) {
            return 'both';
        }
        return $transportMode === 'udp' ? 'udp' : 'tcp';
    }

    private function forwardRouteUsesDedicatedLeigod(int $routeId): bool
    {
        if ($routeId <= 0) {
            return false;
        }
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS combination_count,
                    SUM(CASE WHEN fc.backend_mode = :backend_mode
                                  AND fc.client_mode = :client_mode
                                  AND fc.sidecar_point_id IS NULL
                             THEN 1 ELSE 0 END) AS dedicated_count
             FROM forward_route_combinations frc
             INNER JOIN forward_combinations fc ON fc.id = frc.forward_combination_id
             WHERE frc.forward_route_id = :route_id
               AND frc.enabled = 1
               AND fc.is_active = 1',
            [
                'route_id' => $routeId,
                'backend_mode' => 'leigod_mode',
                'client_mode' => 'single',
            ]
        );
        $combinationCount = (int) ($row['combination_count'] ?? 0);
        return $combinationCount > 0 && (int) ($row['dedicated_count'] ?? 0) === $combinationCount;
    }

    /** @return array<string, mixed>|null */
    private function materialLeigodForwardFingerprint(mixed $payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }
        $candidates = [];
        foreach ((array) ($payload['candidates'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidates[] = [
                'combination_id' => (int) ($candidate['combination_id'] ?? 0),
                'line_id' => (int) ($candidate['line_id'] ?? 0),
                'leg_route_id' => (int) ($candidate['leg_route_id'] ?? 0),
                'config_sha256' => (string) ($candidate['config_sha256'] ?? ''),
                'service_state' => (string) ($candidate['service_state'] ?? ''),
                'tcp_status' => (string) ($candidate['tcp_status'] ?? ''),
                'udp_status' => (string) ($candidate['udp_status'] ?? ''),
            ];
        }
        return [
            'mode' => (string) ($payload['mode'] ?? ''),
            'relay_port' => (int) ($payload['relay_port'] ?? 0),
            'target' => (string) ($payload['target'] ?? ''),
            'candidates' => $candidates,
        ];
    }

    /** @return array<string, mixed>|null */
    private function materialServerSideBondFingerprint(mixed $payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }
        return [
            'aggregation_placement' => (string) ($payload['aggregation_placement'] ?? ''),
            'frontend_port' => (int) ($payload['frontend_port'] ?? 0),
            'leg_relay_target' => (string) ($payload['leg_relay_target'] ?? ''),
            'sidecar_target' => (string) ($payload['sidecar_target'] ?? ''),
            'session_token_sha256' => hash('sha256', (string) ($payload['session_token'] ?? '')),
            'legs' => array_values(array_map(
                static fn(array $leg): int => (int) ($leg['leg_route_id'] ?? 0),
                array_values(array_filter((array) ($payload['legs'] ?? []), 'is_array'))
            )),
            'adaptive_line_admission' => (bool) ($payload['adaptive_line_admission'] ?? false),
            'throughput_min_mbps' => (float) ($payload['throughput_min_mbps'] ?? 0),
            'throughput_relative_floor' => (float) ($payload['throughput_relative_floor'] ?? 0),
            'throughput_bad_windows' => (int) ($payload['throughput_bad_windows'] ?? 0),
            'throughput_recovery_windows' => (int) ($payload['throughput_recovery_windows'] ?? 0),
        ];
    }


    public function evaluateDonationStateForCombination(int $combinationId): void
    {
        if ($combinationId <= 0) {
            return;
        }
        $combination = $this->forwardCombinationById($combinationId);
        if (!is_array($combination)) {
            return;
        }
        $donors = $this->db->fetchAll(
            'SELECT d.*, cds.last_seen_at AS device_last_seen_at, cds.last_heartbeat_at AS device_last_heartbeat_at, dcs.expires_at AS desktop_expires_at
             FROM forward_combination_self_leg_donors d
             LEFT JOIN client_device_sessions cds ON cds.client_id = d.client_id AND cds.device_id = d.device_id
             LEFT JOIN desktop_client_sessions dcs ON dcs.id = d.desktop_client_session_id
             WHERE d.forward_combination_id = :forward_combination_id
             ORDER BY d.id ASC',
            ['forward_combination_id' => $combinationId]
        );
        foreach ($donors as $donor) {
            $this->evaluateDonationRow($combination, $donor);
        }
    }

    /**
     * @param array<string, mixed> $combination
     * @param array<string, mixed> $donor
     */
    private function evaluateDonationRow(array $combination, array $donor): void
    {
        $state = (string) ($donor['state'] ?? 'qualifying');
        if ($state === 'revoked') {
            return;
        }
        $allow = (int) ($combination['allow_public_self_leg_donation'] ?? 0) === 1;
        $desired = (int) ($combination['desired_latency_ms'] ?? 0);
        if (!$allow || $desired <= 0 || (int) ($donor['owner_opt_in'] ?? 1) !== 1) {
            if ($state === 'active') {
                $this->transitionDonationState($donor, 'cooldown', 'admin_disabled');
            }
            return;
        }
        if ($state === 'cooldown') {
            $revokedAt = strtotime((string) ($donor['revoked_at'] ?? '')) ?: 0;
            if ($revokedAt > 0 && time() - $revokedAt < 300) {
                return;
            }
        }
        if (!$this->donorSessionHealthy($donor)) {
            if ($state === 'active') {
                $this->transitionDonationState($donor, 'cooldown', 'session_ended');
            }
            return;
        }
        $latency = $this->nullableFloat($donor['last_measured_latency_ms'] ?? null);
        $openPorts = $this->decodedOpenPorts($donor['open_ports_json'] ?? null);
        $reverseOk = $this->lastReverseProbeOk($donor['last_reverse_probe_json'] ?? null);
        $minPorts = max(0, (int) ($combination['donation_min_open_ports'] ?? 1));
        $openCount = count(array_filter($openPorts, static fn(array $port): bool => !empty($port['ok'])));
        if ($state === 'active') {
            if ($latency !== null && $latency > $desired) {
                $this->transitionDonationState($donor, 'cooldown', 'latency_above_target');
                return;
            }
            if ($openCount < $minPorts || !$reverseOk) {
                $this->transitionDonationState($donor, 'cooldown', 'port_probe_failed');
                return;
            }
            return;
        }
        if ($state === 'cooldown') {
            $this->db->execute(
                'UPDATE forward_combination_self_leg_donors SET state = :qualifying, qualify_started_at = NULL, revoke_reason = NULL, updated_at = :updated_at WHERE id = :id',
                ['id' => (int) ($donor['id'] ?? 0), 'qualifying' => 'qualifying', 'updated_at' => $this->now()]
            );
            $donor['state'] = 'qualifying';
            $donor['qualify_started_at'] = null;
        }
        $threshold = floor($desired * self::DONATION_GATE_RATIO);
        if ($latency === null || $latency > $threshold || $openCount < $minPorts || !$reverseOk) {
            return;
        }
        $qualifyStarted = strtotime((string) ($donor['qualify_started_at'] ?? '')) ?: 0;
        if ($qualifyStarted <= 0) {
            $this->db->execute(
                'UPDATE forward_combination_self_leg_donors SET state = :state, qualify_started_at = :qualify_started_at, updated_at = :updated_at WHERE id = :id',
                ['id' => (int) ($donor['id'] ?? 0), 'state' => 'qualifying', 'qualify_started_at' => $this->now(), 'updated_at' => $this->now()]
            );
            return;
        }
        $window = max(5, (int) ($combination['donation_qualify_window_seconds'] ?? 60));
        if (time() - $qualifyStarted >= $window) {
            $this->transitionDonationState($donor, 'active', 'qualified');
        }
    }

    /** @param array<string, mixed> $donor */
    private function donorSessionHealthy(array $donor): bool
    {
        $cutoff = time() - 120;
        foreach (['device_last_heartbeat_at', 'device_last_seen_at', 'last_heartbeat_at'] as $key) {
            $ts = strtotime((string) ($donor[$key] ?? '')) ?: 0;
            if ($ts >= $cutoff) {
                return true;
            }
        }
        $expires = strtotime((string) ($donor['desktop_expires_at'] ?? '')) ?: 0;
        return $expires > time();
    }

    /** @return array<int, array<string, mixed>> */
    private function decodedOpenPorts(mixed $json): array
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : [];
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    private function lastReverseProbeOk(mixed $json): bool
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : [];
        return is_array($decoded) && !empty($decoded['ok']);
    }

    /** @param array<string, mixed> $donor */
    private function transitionDonationState(array $donor, string $toState, string $reason): void
    {
        $fromState = (string) ($donor['state'] ?? 'qualifying');
        if ($fromState === $toState) {
            return;
        }
        $params = [
            'id' => (int) ($donor['id'] ?? 0),
            'state' => $toState,
            'active' => 'active',
            'cooldown' => 'cooldown',
            'revoked' => 'revoked',
            'updated_at' => $this->now(),
        ];
        $sets = ['state = :state', 'updated_at = :updated_at'];
        if ($toState === 'active') {
            // state = :active
            $sets[] = 'activated_at = :activated_at';
            $sets[] = 'revoked_at = NULL';
            $sets[] = 'revoke_reason = NULL';
            $params['activated_at'] = $this->now();
        }
        if (in_array($toState, ['cooldown', 'revoked'], true)) {
            // state = :cooldown / state = :revoked
            $sets[] = 'revoked_at = :revoked_at';
            $sets[] = 'revoke_reason = :revoke_reason';
            $params['revoked_at'] = $this->now();
            $params['revoke_reason'] = $reason;
        }
        $this->db->execute('UPDATE forward_combination_self_leg_donors SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
        error_log('donation_state donor=' . (int) ($donor['id'] ?? 0) . ' client=' . (int) ($donor['client_id'] ?? 0) . ' combo=' . (int) ($donor['forward_combination_id'] ?? 0) . ' from=' . $fromState . ' to=' . $toState . ' reason=' . $reason);
        $this->republishActiveForwardsForCombination((int) ($donor['forward_combination_id'] ?? 0));
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureUser(string $email, string $name): array
    {
        $user = $this->db->fetchOne('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
        if ($user !== null) {
            return $user;
        }

        $userId = $this->db->insert('users', [
            'name' => $name,
            'email' => $email,
            'api_token' => bin2hex(random_bytes(24)),
            'password_hash' => null,
            'role' => 'tenant',
            'feature_flags_json' => null,
            'status' => 'active',
            'email_verified_at' => $this->now(),
            'email_verification_token' => null,
            'verification_sent_at' => null,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        return $this->db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => $userId]) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function ensurePlan(string $productCode, int $bandwidthLimit, int $trafficLimit): array
    {
        $plan = $this->db->fetchOne('SELECT * FROM service_plans WHERE product_code = :code LIMIT 1', ['code' => $productCode]);
        if ($plan !== null) {
            return $plan;
        }

        $planId = $this->db->insert('service_plans', [
            'name' => $productCode,
            'product_code' => $productCode,
            'description' => null,
            'default_bandwidth_limit_mbps' => $bandwidthLimit,
            'default_traffic_limit_gb' => $trafficLimit,
            'billing_mode' => 'monthly_bundle',
            'billing_currency' => 'CNY',
            'monthly_base_fee' => 0,
            'monthly_included_traffic_gb' => $trafficLimit,
            'traffic_price_per_gb' => 0,
            'traffic_reset_price' => 0,
            'bandwidth_step_mbps' => 0,
            'bandwidth_step_price' => 0,
            'included_client_count' => 0,
            'extra_client_price' => 0,
            'is_active' => 1,
            'feature_flags_json' => null,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        return $this->db->fetchOne('SELECT * FROM service_plans WHERE id = :id', ['id' => $planId]) ?? [];
    }

    private function specificNodeIdFromNodeGroupSelector(string $nodeGroup): ?int
    {
        if (!str_starts_with($nodeGroup, '_user_node_')) {
            return null;
        }
        $suffix = substr($nodeGroup, strlen('_user_node_'));
        if (!preg_match('/^[1-9]\d*$/', $suffix)) {
            throw new ApiException('invalid_specific_node_selector', 422, ['node_group' => $nodeGroup]);
        }
        return (int) $suffix;
    }

    private function specificNodeIdFromCustomEndpointIds(mixed $value): ?int
    {
        $decoded = $this->decodeCustomEndpointIdsJson($value);
        if (!array_key_exists('specific_node_id', $decoded)) {
            return null;
        }
        $nodeId = $decoded['specific_node_id'];
        if (is_int($nodeId) && $nodeId > 0) {
            return $nodeId;
        }
        if (is_string($nodeId) && preg_match('/^[1-9]\d*$/', $nodeId) === 1) {
            return (int) $nodeId;
        }
        throw new ApiException('invalid_specific_node_metadata', 422, ['specific_node_id' => $nodeId]);
    }

    private function customEndpointIdsJsonWithSpecificNode(mixed $value, ?int $specificNodeId): ?string
    {
        $decoded = $this->decodeCustomEndpointIdsJson($value);
        unset($decoded['specific_node_id']);
        if ($specificNodeId !== null) {
            $decoded['specific_node_id'] = $specificNodeId;
        }
        return $decoded !== [] ? $this->normalizeJsonField($decoded) : null;
    }

    /**
     * @return array<string|int, mixed>
     */
    private function decodeCustomEndpointIdsJson(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new ApiException('invalid_custom_endpoint_ids_json', 422);
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }
        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new ApiException('invalid_custom_endpoint_ids_json', 422);
        }
        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function specificNodeForForward(int $nodeId, int $serviceId): ?array
    {
        if ($nodeId <= 0 || $serviceId <= 0) {
            return null;
        }
        $params = [
            'id' => $nodeId,
            'active' => 'active',
            'degraded' => 'degraded',
            'owner_service_id' => $serviceId,
            'shared' => 'group_shared',
            'svc_id_for_group' => $serviceId,
        ];
        $freshnessSql = $this->nodeSelectionFreshnessSql($params);

        return $this->db->fetchOne(
            'SELECT n.*, g.group_key AS node_group_key
             FROM nodes n
             LEFT JOIN node_groups g ON g.id = n.group_id
             WHERE n.id = :id
               AND (n.status = :active OR n.status = :degraded)
               AND (n.owner_service_id IS NULL OR n.owner_service_id = :owner_service_id
                    OR (n.sharing_mode = :shared AND n.user_slave_group_id IN (
                        SELECT m.group_id FROM user_slave_group_members m
                        INNER JOIN services s ON s.user_id = m.user_id
                        WHERE s.id = :svc_id_for_group
                    )))'
            . $freshnessSql .
            ' LIMIT 1',
            $params
        );
    }

    /** @param array<int, int> $preferredNodeIds */
    private function pickNodes(string $groupKey, int $count, ?int $serviceId = null, array $preferredNodeIds = []): array
    {
        $params = [
            'active' => 'active',
            'degraded' => 'degraded',
        ];
        $sql = 'SELECT n.*, g.group_key AS node_group_key
                FROM nodes n
                LEFT JOIN node_groups g ON g.id = n.group_id
                WHERE (n.status = :active OR n.status = :degraded)';

        if ($groupKey !== '' && $groupKey !== 'default') {
            $sql .= ' AND g.group_key = :group_key';
            $params['group_key'] = $groupKey;
        }

        if ($serviceId !== null) {
            // Platform nodes (no owner) + user-owned nodes + group-shared nodes visible to this service's user
            $sql .= ' AND (n.owner_service_id IS NULL OR n.owner_service_id = :owner_service_id';
            $params['owner_service_id'] = $serviceId;

            // Group-shared: visible if service owner is in the node's sharing group
            $sql .= ' OR (n.sharing_mode = :shared AND n.user_slave_group_id IN (
                SELECT m.group_id FROM user_slave_group_members m
                INNER JOIN services s ON s.user_id = m.user_id
                WHERE s.id = :svc_id_for_group
            ))';
            $params['shared'] = 'group_shared';
            $params['svc_id_for_group'] = $serviceId;

            $sql .= ')';
        }

        $sql .= $this->nodeSelectionFreshnessSql($params);
        $preferredPlaceholders = [];
        foreach (array_values(array_unique(array_filter(array_map('intval', $preferredNodeIds), static fn(int $id): bool => $id > 0))) as $index => $nodeId) {
            $key = 'preferred_node_' . $index;
            $preferredPlaceholders[] = ':' . $key;
            $params[$key] = $nodeId;
        }
        // Keep healthy assigned nodes ahead of new capacity so their own load contribution cannot evict
        // them on the next reconcile. Eligibility and freshness filters still force real failover; new
        // capacity remains load-balanced with an immutable node-id tie-break.
        $preferredOrder = $preferredPlaceholders !== []
            ? 'CASE WHEN n.id IN (' . implode(', ', $preferredPlaceholders) . ') THEN 0 ELSE 1 END ASC, '
            : '';
        $sql .= ' ORDER BY ' . $preferredOrder . 'n.current_load ASC, n.id ASC LIMIT ' . max(1, $count);
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function nodeSelectionFreshnessSql(array &$params): string
    {
        $maxAgeSeconds = $this->config->getInt('NODE_SELECTION_MAX_HEARTBEAT_AGE_SECONDS', 1800);
        if ($maxAgeSeconds <= 0) {
            return '';
        }
        $params['node_heartbeat_cutoff'] = gmdate('Y-m-d H:i:s', time() - $maxAgeSeconds);
        return ' AND n.last_heartbeat_at IS NOT NULL AND n.last_heartbeat_at >= :node_heartbeat_cutoff';
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    public function pickFastestSlavesForCombination(int $combinationId, int $cap): array
    {
        // fastest_slave_per_combination_selector
        if ($combinationId <= 0) {
            return [];
        }
        $combination = $this->forwardCombinationById($combinationId);
        if (!is_array($combination)) {
            return [];
        }
        $groupKeys = array_values(array_unique(array_filter(array_merge(
            [trim((string) ($combination['node_group_key'] ?? ''))],
            array_map(static fn(mixed $value): string => trim((string) $value), (array) ($combination['helper_node_group_keys'] ?? []))
        ), static fn(string $value): bool => $value !== '')));
        $candidates = [];
        $seenNodes = [];
        if ($groupKeys !== []) {
            $params = ['active' => 'active', 'degraded' => 'degraded'];
            $placeholders = [];
            foreach ($groupKeys as $idx => $groupKey) {
                $key = 'group_key_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $groupKey;
            }
            $freshnessClause = $this->nodeSelectionFreshnessSql($params);
            $rows = $this->db->fetchAll(
                'SELECT n.*, g.group_key AS node_group_key
                 FROM nodes n
                 LEFT JOIN node_groups g ON g.id = n.group_id
                 WHERE (n.status = :active OR n.status = :degraded)
                   AND g.group_key IN (' . implode(', ', $placeholders) . ')' . $freshnessClause . '
                 ORDER BY n.id ASC',
                $params
            );
            foreach ($rows as $row) {
                $nodeId = (int) ($row['id'] ?? 0);
                if ($nodeId <= 0 || isset($seenNodes[$nodeId])) {
                    continue;
                }
                $seenNodes[$nodeId] = true;
                $candidates[] = $this->normalizeRentedSlaveCandidate($row, false);
            }
        }

        $pinnedParams = [
            'forward_combination_id' => $combinationId,
            'selection_kind' => 'admin_pin',
            'active' => 'active',
            'degraded' => 'degraded',
        ];
        $pinnedFreshnessClause = $this->nodeSelectionFreshnessSql($pinnedParams);
        $pinnedRows = $this->db->fetchAll(
            'SELECT n.*, g.group_key AS node_group_key, fcs.sort_order AS pin_sort_order
             FROM forward_combination_slaves fcs
             INNER JOIN nodes n ON n.id = fcs.node_id
             LEFT JOIN node_groups g ON g.id = n.group_id
             WHERE fcs.forward_combination_id = :forward_combination_id
               AND fcs.is_active = 1
               AND fcs.selection_kind = :selection_kind
               AND (n.status = :active OR n.status = :degraded)' . $pinnedFreshnessClause . '
             ORDER BY fcs.sort_order ASC, n.id ASC',
            $pinnedParams
        );
        foreach ($pinnedRows as $row) {
            $nodeId = (int) ($row['id'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }
            if (isset($seenNodes[$nodeId])) {
                continue;
            }
            $seenNodes[$nodeId] = true;
            $candidates[] = $this->normalizeRentedSlaveCandidate($row, true);
        }

        $donorRows = $this->db->fetchAll(
            'SELECT d.*
             FROM forward_combination_self_leg_donors d
             WHERE d.forward_combination_id = :forward_combination_id
               AND d.state = :state
               AND d.owner_opt_in = 1
               AND d.donor_public_host IS NOT NULL
               AND d.donor_public_port IS NOT NULL
             ORDER BY d.id ASC',
            ['forward_combination_id' => $combinationId, 'state' => 'active']
        );
        foreach ($donorRows as $row) {
            $host = trim((string) ($row['donor_public_host'] ?? ''));
            $port = (int) ($row['donor_public_port'] ?? 0);
            if ($host === '' || $port <= 0) {
                continue;
            }
            $candidates[] = [
                'candidate_source' => 'self_leg_donor',
                'stable_id' => (int) ($row['id'] ?? 0),
                'node_id' => null,
                'donor_id' => (int) ($row['id'] ?? 0),
                'client_id' => (int) ($row['client_id'] ?? 0),
                'host' => $host,
                'port' => $port,
                'donor_transport' => (string) ($row['donor_transport'] ?? 'tcp'),
                'last_chain_latency_ms' => $this->nullableFloat($row['last_measured_latency_ms'] ?? null),
                'delivered_ewma' => 0.0,
                'row' => $row,
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            $leftLatency = $left['last_chain_latency_ms'];
            $rightLatency = $right['last_chain_latency_ms'];
            if ($leftLatency === null && $rightLatency !== null) {
                return 1;
            }
            if ($leftLatency !== null && $rightLatency === null) {
                return -1;
            }
            if ($leftLatency !== null && $rightLatency !== null) {
                $cmp = ((float) $leftLatency) <=> ((float) $rightLatency);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            $ewmaCmp = ((float) ($right['delivered_ewma'] ?? 0.0)) <=> ((float) ($left['delivered_ewma'] ?? 0.0));
            if ($ewmaCmp !== 0) {
                return $ewmaCmp;
            }
            return ((int) ($left['stable_id'] ?? 0)) <=> ((int) ($right['stable_id'] ?? 0));
        });

        return $cap > 0 ? array_slice($candidates, 0, $cap) : $candidates;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRentedSlaveCandidate(array $row, bool $adminPinned): array
    {
        $latency = null;
        $agentConfig = $this->decodeJsonAssoc($row['agent_config_json'] ?? null);
        foreach (['last_chain_latency_ms', 'best_latency_ms', 'last_latency_ms'] as $key) {
            if (array_key_exists($key, $row)) {
                $latency = $this->nullableFloat($row[$key]);
                if ($latency !== null) {
                    break;
                }
            }
            if (array_key_exists($key, $agentConfig)) {
                $latency = $this->nullableFloat($agentConfig[$key]);
                if ($latency !== null) {
                    break;
                }
            }
        }
        $delivered = $this->nullableFloat($row['delivered_ewma'] ?? ($agentConfig['delivered_ewma'] ?? 0)) ?? 0.0;
        return [
            'candidate_source' => 'rented_node',
            'selection_kind' => $adminPinned ? 'admin_pin' : 'helper_group',
            'stable_id' => (int) ($row['id'] ?? 0),
            'node_id' => (int) ($row['id'] ?? 0),
            'donor_id' => null,
            'client_id' => null,
            'host' => $this->resolveNodePublicHost($row),
            'port' => 0,
            'last_chain_latency_ms' => $latency,
            'delivered_ewma' => $delivered,
            'row' => $row,
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    /**
     * @param array<int, string> $groupKeys
     * @param array<int, int> $preferredNodeIds
     * @return array<int, array<string, mixed>>
     */
    private function pickNodesForGroups(array $groupKeys, int $count, ?int $serviceId = null, array $preferredNodeIds = []): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(static fn(string $item): string => trim($item), $groupKeys), static fn(string $item): bool => $item !== '')));
        if ($normalized === []) {
            return [];
        }

        $primary = [];
        $secondary = [];
        foreach ($normalized as $groupKey) {
            $groupNodes = $this->pickNodes($groupKey, max(1, $count), $serviceId, $preferredNodeIds);
            if ($groupNodes === []) {
                continue;
            }
            $first = array_shift($groupNodes);
            if (is_array($first)) {
                $primary[(int) $first['id']] = $first;
            }
            foreach ($groupNodes as $node) {
                $secondary[(int) $node['id']] = $node;
            }
        }

        // FIX C: stable, deterministic ordering. current_load is the primary signal, but
        // last_heartbeat_at is a VOLATILE column that changes on every heartbeat. The previous
        // sorter tie-broke equal-load nodes by last_heartbeat_at DESC, so two equal-load nodes
        // flipped relative order across reconciles -> the desired-node set (hence endpoint
        // membership) was non-deterministic -> endpoints flapped pending<->deleting and task
        // churn compounded. Tie-break equal-load nodes by node_id ASC (a stable, immutable key)
        // instead so the selected set + order is reproducible given unchanged membership inputs
        // even when the heartbeat timestamps drift. (Load remains the real selection signal; the
        // tie-break only governs which equal-load node wins, which must not flap on noise.)
        $sorter = static function (array $left, array $right): int {
            $loadCmp = ((int) $left['current_load']) <=> ((int) $right['current_load']);
            if ($loadCmp !== 0) {
                return $loadCmp;
            }
            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        };

        $orderedPrimary = array_values($primary);
        usort($orderedPrimary, $sorter);
        $orderedSecondary = array_values(array_filter($secondary, static fn(array $node): bool => !isset($primary[(int) $node['id']])));
        usort($orderedSecondary, $sorter);

        return array_slice(array_merge($orderedPrimary, $orderedSecondary), 0, max(1, $count));
    }

    /**
     * @param array<string, mixed> $forward
     * @return array<int, string>
     */
    private function orderedHelperGroupKeysForForward(array $forward): array
    {
        $forwardCombinationId = (int) ($forward['forward_combination_id'] ?? 0);
        $keys = $this->forwardCombinationHelperNodeGroupKeys($forwardCombinationId);
        if ($keys !== []) {
            return $keys;
        }
        $fallback = trim((string) ($forward['node_group_key'] ?? ''));
        return $fallback !== '' ? [$fallback] : [];
    }

    /**
     * @param array<int, string> $groupKeys
     */
    private function availableNodeCountForGroups(array $groupKeys, ?int $serviceId = null): int
    {
        $normalized = array_values(array_unique(array_filter(array_map(static fn(string $item): string => trim($item), $groupKeys), static fn(string $item): bool => $item !== '')));
        if ($normalized === []) {
            return 0;
        }

        $params = [
            'active' => 'active',
            'degraded' => 'degraded',
        ];
        $placeholders = [];
        foreach ($normalized as $index => $groupKey) {
            $placeholder = 'group_key_' . $index;
            $placeholders[] = ':' . $placeholder;
            $params[$placeholder] = $groupKey;
        }

        $ownerClause = '';
        if ($serviceId !== null) {
            $ownerClause = ' AND (n.owner_service_id IS NULL OR n.owner_service_id = :owner_service_id
                OR (n.sharing_mode = :shared AND n.user_slave_group_id IN (
                    SELECT m.group_id FROM user_slave_group_members m
                    INNER JOIN services s ON s.user_id = m.user_id
                    WHERE s.id = :svc_id_for_group
                )))';
            $params['owner_service_id'] = $serviceId;
            $params['shared'] = 'group_shared';
            $params['svc_id_for_group'] = $serviceId;
        }
        $freshnessClause = $this->nodeSelectionFreshnessSql($params);

        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total
             FROM nodes n
             LEFT JOIN node_groups g ON g.id = n.group_id
             WHERE (n.status = :active OR n.status = :degraded)
               AND g.group_key IN (' . implode(', ', $placeholders) . ')' . $ownerClause . $freshnessClause,
            $params
        );

        return max(0, (int) ($row['total'] ?? 0));
    }

    /**
     * @param array<int, string> $groupKeys
     */
    private function panelManagedEndpointCount(string $clientMode, array $groupKeys, ?int $serviceId = null): int
    {
        $availableCount = $this->availableNodeCountForGroups($groupKeys, $serviceId);
        $groupCount = count(array_filter(array_map(static fn(string $item): string => trim($item), $groupKeys), static fn(string $item): bool => $item !== ''));

        if ($clientMode === 'single') {
            return 1;
        }

        if (in_array($clientMode, ['bond', 'mptcp'], true)) {
            if ($availableCount > 0) {
                return max(2, $availableCount);
            }

            return max(2, $groupCount);
        }

        if ($availableCount > 0) {
            return $availableCount;
        }

        return max(1, $groupCount);
    }

    private function resolveEndpointPublicHost(array $endpoint): string
    {
        $storedHost = trim((string) ($endpoint['public_host'] ?? ''));
        if ($this->isUsablePublicHost($storedHost)) {
            return $storedHost;
        }

        $nodePublicIp = trim((string) ($endpoint['node_public_ip'] ?? ''));
        if ($this->isUsablePublicHost($nodePublicIp)) {
            return $nodePublicIp;
        }

        $nodeName = trim((string) ($endpoint['node_name'] ?? ''));
        if ($this->isUsablePublicHost($nodeName)) {
            return $nodeName;
        }

        return $storedHost;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nodeGroupByKey(string $groupKey): ?array
    {
        $normalized = trim($groupKey);
        if ($normalized === '') {
            return null;
        }

        $group = $this->db->fetchOne('SELECT * FROM node_groups WHERE group_key = :group_key LIMIT 1', [
            'group_key' => $normalized,
        ]);

        return is_array($group) ? $group : null;
    }

    /**
     * @param array<string, mixed>|null $ingressGroup
     */
    private function resolveIngressSniHost(string $forwardSniHost, ?array $ingressGroup = null): string
    {
        $groupSni = trim((string) (($ingressGroup['ingress_sni_host'] ?? '') ?: ''));
        if ($this->isUsableIngressHost($groupSni)) {
            return $groupSni;
        }

        $explicit = trim($forwardSniHost);
        if ($this->isUsableIngressHost($explicit)) {
            return $explicit;
        }

        $default = trim((string) ($this->config->get('DEFAULT_CDN_HOST', 'edge.example.com') ?? 'edge.example.com'));
        return $default !== '' ? $default : 'edge.example.com';
    }

    /**
     * @param array<string, mixed>|null $ingressGroup
     */
    private function resolveIngressCdnHost(string $forwardCdnHost, string $resolvedSniHost, ?array $ingressGroup = null): string
    {
        $groupCdn = trim((string) (($ingressGroup['ingress_cdn_host'] ?? '') ?: ''));
        if ($this->isUsableIngressHost($groupCdn)) {
            return $groupCdn;
        }

        $explicit = trim($forwardCdnHost);
        if ($this->isUsableIngressHost($explicit)) {
            return $explicit;
        }

        if ($this->isUsableIngressHost($resolvedSniHost)) {
            return $resolvedSniHost;
        }

        $default = trim((string) ($this->config->get('DEFAULT_CDN_HOST', 'edge.example.com') ?? 'edge.example.com'));
        return $default !== '' ? $default : 'edge.example.com';
    }

    private function isUsableIngressHost(string $value): bool
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return false;
        }

        if (filter_var($normalized, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if (strtolower($normalized) === 'localhost') {
            return false;
        }

        return preg_match('/^[A-Za-z0-9.-]+$/', $normalized) === 1;
    }

    private function resolveNodePublicHost(array $node): string
    {
        $publicIp = trim((string) ($node['public_ip'] ?? ''));
        if ($this->isUsablePublicHost($publicIp)) {
            return $publicIp;
        }

        $agentConfig = [];
        if (is_string($node['agent_config_json'] ?? null) && $node['agent_config_json'] !== '') {
            $decoded = json_decode((string) $node['agent_config_json'], true);
            if (is_array($decoded)) {
                $agentConfig = $decoded;
            }
        }

        foreach (['public_host', 'public_hostname', 'endpoint_host'] as $key) {
            $candidate = trim((string) ($agentConfig[$key] ?? ''));
            if ($this->isUsablePublicHost($candidate)) {
                return $candidate;
            }
        }

        $name = trim((string) ($node['name'] ?? ''));
        if ($this->isUsablePublicHost($name)) {
            return $name;
        }

        return '';
    }

    private function isUsablePublicHost(string $value): bool
    {
        $candidate = trim($value);
        if ($candidate === '') {
            return false;
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return true;
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return true;
        }

        if (!str_contains($candidate, '.')) {
            return false;
        }

        return preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/', $candidate) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function getServiceByWhmcsId(int $whmcsServiceId): array
    {
        $service = $this->db->fetchOne('SELECT * FROM services WHERE whmcs_service_id = :id LIMIT 1', ['id' => $whmcsServiceId]);
        if ($service === null) {
            throw new ApiException('service_not_found', 404, ['whmcs_service_id' => $whmcsServiceId]);
        }
        return $service;
    }

    /**
     * @param array<string, mixed> $context
     */
    /**
     * @param array<string, mixed>|null $existingService
     * @return array<string, mixed>
     */
    private function serviceBillingSnapshot(array $plan, int $trafficLimit, ?array $existingService = null): array
    {
        $billingMode = $this->normalizeBillingMode((string) ($plan['billing_mode'] ?? 'monthly_bundle'));
        $billingCurrency = strtoupper(trim((string) ($plan['billing_currency'] ?? 'CNY')));
        if ($billingCurrency === '') {
            $billingCurrency = 'CNY';
        }

        $includedTraffic = max(0, (int) ($plan['monthly_included_traffic_gb'] ?? $trafficLimit));
        if ($billingMode === 'monthly_bundle' && $includedTraffic <= 0) {
            $includedTraffic = max(0, $trafficLimit);
        }

        return [
            'billing_mode' => $billingMode,
            'billing_currency' => $billingCurrency,
            'monthly_base_fee' => $this->normalizeMoney($plan['monthly_base_fee'] ?? 0),
            'monthly_included_traffic_gb' => $includedTraffic,
            'traffic_price_per_gb' => $this->normalizeRate($plan['traffic_price_per_gb'] ?? 0),
            'billing_cycle_started_at' => (string) (($existingService['billing_cycle_started_at'] ?? '') ?: $this->now()),
            'billing_cycle_traffic_used_bytes' => max(0, (int) ($existingService['billing_cycle_traffic_used_bytes'] ?? 0)),
        ];
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private function billingOverviewFromService(array $service): array
    {
        $service = $this->hydrateServicePricingContext($service);
        $mode = $this->normalizeBillingMode((string) ($service['billing_mode'] ?? 'monthly_bundle'));
        $currency = strtoupper(trim((string) ($service['billing_currency'] ?? 'CNY')));
        if ($currency === '') {
            $currency = 'CNY';
        }

        $baseFee = (float) ($service['monthly_base_fee'] ?? 0);
        $includedGb = max(0, (int) ($service['monthly_included_traffic_gb'] ?? $service['traffic_limit_gb'] ?? 0));
        $pricePerGb = (float) ($service['traffic_price_per_gb'] ?? 0);
        $usageBytes = max(0, (int) ($service['billing_cycle_traffic_used_bytes'] ?? 0));
        $cycleStart = $this->asUtcDateTime((string) (($service['billing_cycle_started_at'] ?? '') ?: ($service['created_at'] ?? $this->now())));
        $cycleEnd = $this->nextBillingCycleStart($cycleStart);
        $includedBytes = $includedGb * 1024 * 1024 * 1024;
        $billableBytes = $mode === 'metered' ? max(0, $usageBytes - $includedBytes) : 0;
        $trafficCharge = $mode === 'metered'
            ? (($billableBytes / 1024 / 1024 / 1024) * $pricePerGb)
            : 0.0;

        $baseBandwidth = max(0, (int) ($service['plan_default_bandwidth_limit_mbps'] ?? $service['bandwidth_limit_mbps'] ?? 0));
        $currentBandwidth = max(0, (int) ($service['bandwidth_limit_mbps'] ?? 0));
        $bandwidthStepMbps = max(0, (int) ($service['plan_bandwidth_step_mbps'] ?? 0));
        $bandwidthStepPrice = (float) ($service['plan_bandwidth_step_price'] ?? 0);
        $extraBandwidthMbps = max(0, $currentBandwidth - $baseBandwidth);
        $extraBandwidthSteps = ($bandwidthStepMbps > 0 && $bandwidthStepPrice > 0 && $extraBandwidthMbps > 0)
            ? (int) ceil($extraBandwidthMbps / $bandwidthStepMbps)
            : 0;
        $bandwidthAddon = $extraBandwidthSteps * $bandwidthStepPrice;

        $includedClientCount = max(0, (int) ($service['plan_included_client_count'] ?? 0));
        $activeClientCount = max(0, (int) ($service['active_client_count'] ?? 0));
        $extraClientPrice = (float) ($service['plan_extra_client_price'] ?? 0);
        $extraClientCount = max(0, $activeClientCount - $includedClientCount);
        $clientAddon = $extraClientCount * $extraClientPrice;

        $estimatedTotal = $baseFee + $trafficCharge + $bandwidthAddon + $clientAddon;

        return [
            'mode' => $mode,
            'mode_label' => $mode === 'metered' ? 'Metered' : 'Monthly Bundle',
            'currency' => $currency,
            'base_fee' => number_format($baseFee, 2, '.', ''),
            'base_fee_formatted' => $currency . ' ' . number_format($baseFee, 2),
            'included_traffic_gb' => $includedGb,
            'included_bytes' => $includedBytes,
            'included_traffic_bytes' => $includedBytes,
            'traffic_price_per_gb' => number_format($pricePerGb, 4, '.', ''),
            'traffic_price_per_gb_formatted' => $currency . ' ' . number_format($pricePerGb, 4) . ' / GB',
            'cycle_started_at' => $cycleStart->format('Y-m-d H:i:s'),
            'cycle_ends_at' => $cycleEnd->format('Y-m-d H:i:s'),
            'current_cycle_used_bytes' => $usageBytes,
            'current_cycle_used_gb' => round($usageBytes / 1024 / 1024 / 1024, 2),
            'remaining_included_bytes' => max(0, $includedBytes - min($includedBytes, $usageBytes)),
            'remaining_included_gb' => $mode === 'monthly_bundle' ? max(0, round(($includedBytes - min($includedBytes, $usageBytes)) / 1024 / 1024 / 1024, 2)) : null,
            'billable_bytes' => $billableBytes,
            'billable_gb' => round($billableBytes / 1024 / 1024 / 1024, 2),
            'traffic_charge_amount' => number_format($trafficCharge, 2, '.', ''),
            'traffic_charge_formatted' => $currency . ' ' . number_format($trafficCharge, 2),
            'base_bandwidth_mbps' => $baseBandwidth,
            'current_bandwidth_mbps' => $currentBandwidth,
            'bandwidth_step_mbps' => $bandwidthStepMbps,
            'bandwidth_step_price' => number_format($bandwidthStepPrice, 2, '.', ''),
            'bandwidth_step_price_formatted' => $currency . ' ' . number_format($bandwidthStepPrice, 2),
            'extra_bandwidth_mbps' => $extraBandwidthMbps,
            'extra_bandwidth_steps' => $extraBandwidthSteps,
            'bandwidth_addon_amount' => number_format($bandwidthAddon, 2, '.', ''),
            'bandwidth_addon_formatted' => $currency . ' ' . number_format($bandwidthAddon, 2),
            'included_client_count' => $includedClientCount,
            'active_client_count' => $activeClientCount,
            'extra_client_count' => $extraClientCount,
            'extra_client_price' => number_format($extraClientPrice, 2, '.', ''),
            'extra_client_price_formatted' => $currency . ' ' . number_format($extraClientPrice, 2),
            'client_addon_amount' => number_format($clientAddon, 2, '.', ''),
            'client_addon_formatted' => $currency . ' ' . number_format($clientAddon, 2),
            'estimated_total_amount' => number_format($estimatedTotal, 2, '.', ''),
            'estimated_total_formatted' => $currency . ' ' . number_format($estimatedTotal, 2),
        ];
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private function hydrateServicePricingContext(array $service): array
    {
        $needsPlanPricing = !array_key_exists('plan_default_bandwidth_limit_mbps', $service)
            || !array_key_exists('plan_bandwidth_step_mbps', $service)
            || !array_key_exists('plan_bandwidth_step_price', $service)
            || !array_key_exists('plan_included_client_count', $service)
            || !array_key_exists('plan_extra_client_price', $service)
            || !array_key_exists('plan_traffic_reset_price', $service)
            || !array_key_exists('plan_billing_currency', $service);

        if ($needsPlanPricing && !empty($service['plan_id'])) {
            $plan = $this->db->fetchOne('SELECT * FROM service_plans WHERE id = :id LIMIT 1', ['id' => $service['plan_id']]);
            if ($plan !== null) {
                $service['plan_default_bandwidth_limit_mbps'] = (int) ($plan['default_bandwidth_limit_mbps'] ?? 0);
                $service['plan_bandwidth_step_mbps'] = (int) ($plan['bandwidth_step_mbps'] ?? 0);
                $service['plan_bandwidth_step_price'] = $plan['bandwidth_step_price'] ?? 0;
                $service['plan_included_client_count'] = (int) ($plan['included_client_count'] ?? 0);
                $service['plan_extra_client_price'] = $plan['extra_client_price'] ?? 0;
                $service['plan_traffic_reset_price'] = $plan['traffic_reset_price'] ?? 0;
                $service['plan_billing_currency'] = (string) ($plan['billing_currency'] ?? 'CNY');
            }
        }

        if (!array_key_exists('active_client_count', $service) && !empty($service['id'])) {
            $row = $this->db->fetchOne(
                'SELECT COUNT(DISTINCT line_client_id) AS total
                 FROM forwards
                 WHERE service_id = :service_id
                   AND enabled = 1
                   AND line_client_id IS NOT NULL',
                ['service_id' => $service['id']]
            );
            $service['active_client_count'] = (int) ($row['total'] ?? 0);
        }

        return $service;
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private function syncServiceBillingCycle(array $service): array
    {
        $cycleStart = $this->asUtcDateTime((string) (($service['billing_cycle_started_at'] ?? '') ?: ($service['created_at'] ?? $this->now())));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cycleUsageBytes = max(0, (int) ($service['billing_cycle_traffic_used_bytes'] ?? 0));
        $rolled = false;
        $resumeTrafficSuspendedService = false;

        while ($this->nextBillingCycleStart($cycleStart) <= $now) {
            $cycleEnd = $this->nextBillingCycleStart($cycleStart);
            $this->recordBillingCycle($service, $cycleStart, $cycleEnd, $cycleUsageBytes);
            $cycleStart = $cycleEnd;
            $cycleUsageBytes = 0;
            $rolled = true;
            if ((string) ($service['status'] ?? '') === 'traffic_suspended' && (string) ($service['suspend_reason'] ?? '') === 'traffic_limit') {
                $resumeTrafficSuspendedService = true;
            }
        }

        if (!$rolled) {
            return $service;
        }

        $params = [
            'id' => $service['id'],
            'billing_cycle_started_at' => $cycleStart->format('Y-m-d H:i:s'),
            'billing_cycle_traffic_used_bytes' => $cycleUsageBytes,
            'updated_at' => $this->now(),
        ];
        $sql = 'UPDATE services
                SET billing_cycle_started_at = :billing_cycle_started_at,
                    billing_cycle_traffic_used_bytes = :billing_cycle_traffic_used_bytes,
                    updated_at = :updated_at';
        if ($resumeTrafficSuspendedService) {
            $sql .= ',
                    status = :status,
                    suspend_reason = :suspend_reason';
            $params['status'] = 'active';
            $params['suspend_reason'] = null;
        }
        $sql .= ' WHERE id = :id';
        $this->db->execute($sql, $params);

        if ($resumeTrafficSuspendedService) {
            $this->queueLifecycleTasks((int) $service['id'], 'resume');
        }

        return $this->db->fetchOne('SELECT * FROM services WHERE id = :id LIMIT 1', ['id' => $service['id']]) ?? $service;
    }

    /**
     * @param array<string, mixed> $service
     */
    private function recordBillingCycle(array $service, DateTimeImmutable $cycleStart, DateTimeImmutable $cycleEnd, int $usageBytes): void
    {
        $existing = $this->db->fetchOne(
            'SELECT id FROM service_billing_cycles WHERE service_id = :service_id AND cycle_started_at = :cycle_started_at LIMIT 1',
            [
                'service_id' => $service['id'],
                'cycle_started_at' => $cycleStart->format('Y-m-d H:i:s'),
            ]
        );
        if ($existing !== null) {
            return;
        }

        $cycleService = $service;
        $cycleService['billing_cycle_started_at'] = $cycleStart->format('Y-m-d H:i:s');
        $cycleService['billing_cycle_traffic_used_bytes'] = $usageBytes;
        $overview = $this->billingOverviewFromService($cycleService);
        $this->db->insert('service_billing_cycles', [
            'service_id' => $service['id'],
            'cycle_started_at' => $cycleStart->format('Y-m-d H:i:s'),
            'cycle_ended_at' => $cycleEnd->format('Y-m-d H:i:s'),
            'billing_mode' => $overview['mode'],
            'billing_currency' => $overview['currency'],
            'monthly_base_fee' => $overview['base_fee'],
            'monthly_included_traffic_gb' => $overview['included_traffic_gb'],
            'traffic_price_per_gb' => $overview['traffic_price_per_gb'],
            'usage_bytes' => $usageBytes,
            'billable_bytes' => (int) ($overview['billable_bytes'] ?? 0),
            'estimated_total_amount' => $overview['estimated_total_amount'],
            'created_at' => $this->now(),
        ]);
    }

    private function normalizeBillingMode(string $value): string
    {
        return strtolower(trim($value)) === 'metered' ? 'metered' : 'monthly_bundle';
    }

    private function normalizeMoney(mixed $value): string
    {
        return number_format(max(0, (float) $value), 2, '.', '');
    }

    private function normalizeRate(mixed $value): string
    {
        return number_format(max(0, (float) $value), 4, '.', '');
    }

    private function nextBillingCycleStart(DateTimeImmutable $cycleStart): DateTimeImmutable
    {
        return $cycleStart->modify('+1 month');
    }

    private function asUtcDateTime(string $value): DateTimeImmutable
    {
        $timezone = new DateTimeZone('UTC');
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }

        try {
            return new DateTimeImmutable($value, $timezone);
        } catch (\Throwable) {
            return new DateTimeImmutable('now', $timezone);
        }
    }

    private function audit(string $action, array $context): void
    {
        $this->db->insert('audit_logs', [
            'action' => $action,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $this->now(),
        ]);
    }

    /**
     * Validate values that eventually reach generated slave units. The v1
     * contract performs strict JSON typing; this shared boundary protects
     * legacy, Web, and internal callers after their normalizers run.
     *
     * @return array{0:string,1:int,2:int}
     */
    private function validateForwardMutationInputs(
        string $targetHost,
        int $targetPort,
        int $listenPort,
        bool $allowAutomaticListenPort
    ): array {
        if ($targetPort < 1 || $targetPort > 65535) {
            throw new ApiException('invalid_forward_payload', 422);
        }
        if ($listenPort < 0 || $listenPort > 65535 || (!$allowAutomaticListenPort && $listenPort === 0)) {
            throw new ApiException('invalid_forward_payload', 422);
        }

        return [ForwardLineApiContract::validateTargetHost($targetHost), $targetPort, $listenPort];
    }

    /**
     * @param array{user_id?: int, feature?: string}|null $mutationPolicy
     * @return array<string, mixed>
     */
    private function lockForwardMutationService(int $serviceId, ?array $mutationPolicy, string $notFoundCode): array
    {
        $service = $this->db->fetchOne(
            'SELECT * FROM services WHERE id = :id LIMIT 1 FOR UPDATE',
            ['id' => $serviceId]
        );
        if ($service === null) {
            throw new ApiException($notFoundCode, 404);
        }
        if ($mutationPolicy === null) {
            return $service;
        }

        $expectedUserId = (int) ($mutationPolicy['user_id'] ?? 0);
        if ($expectedUserId <= 0 || (int) ($service['user_id'] ?? 0) !== $expectedUserId) {
            throw new ApiException($notFoundCode, 404);
        }
        if ((string) ($service['status'] ?? '') !== 'active') {
            throw new ApiException('service_inactive', 409);
        }

        $user = $this->db->fetchOne(
            'SELECT * FROM users WHERE id = :id LIMIT 1 FOR UPDATE',
            ['id' => $expectedUserId]
        );
        if ($user === null
            || (string) ($user['role'] ?? '') !== 'tenant'
            || (string) ($user['status'] ?? '') !== 'active') {
            throw new ApiException('user_auth_failed', 401);
        }

        $planFeatureFlagsJson = '';
        $planId = (int) ($service['plan_id'] ?? 0);
        if ($planId > 0) {
            $plan = $this->db->fetchOne(
                'SELECT * FROM service_plans WHERE id = :id LIMIT 1 FOR UPDATE',
                ['id' => $planId]
            );
            if ($plan === null) {
                throw new ApiException('operation_failed', 500);
            }
            $planFeatureFlagsJson = (string) ($plan['feature_flags_json'] ?? '');
        }

        $service['plan_feature_flags_json'] = $planFeatureFlagsJson;
        $service['user_feature_flags_json'] = (string) ($user['feature_flags_json'] ?? '');
        $service['user_wallet_balance'] = $user['wallet_balance'] ?? 0;
        $feature = (string) ($mutationPolicy['feature'] ?? '');
        $featureErrors = [
            'allow_manage_forwards' => 'feature_denied_manage_forwards',
            'allow_delete_forwards' => 'feature_denied_delete_forwards',
        ];
        if (!array_key_exists($feature, $featureErrors)) {
            throw new ApiException('operation_failed', 500);
        }
        $features = $this->effectiveFeatureFlagsForService($service);
        if (empty($features[$feature])) {
            throw new ApiException($featureErrors[$feature], 403);
        }

        return $service;
    }

    /** @param array<string, mixed> $forward */
    private function assertExpectedForwardRevision(array $forward, ?int $expectedRevision): void
    {
        if ($expectedRevision === null) {
            return;
        }
        $actualRevision = max(1, (int) ($forward['api_revision'] ?? 1));
        if ($expectedRevision !== $actualRevision) {
            throw new ApiException('forward_version_conflict', 412);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = (string) ($payload[$key] ?? '');
        if ($value === '') {
            throw new ApiException('missing_required_field', 422, ['field' => $key]);
        }
        return $value;
    }

    private function normalizeJsonField(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $trimmed;
            }
            return json_encode($trimmed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function normalizeClientMode(string $mode): string
    {
        return match (strtolower(trim($mode))) {
            '', 'single' => 'single',
            'race', 'failover', 'multi-path', 'lb' => 'race',
            'bond', 'bonding', 'aggregate', 'aggregated' => 'bond',
            'mptcp', 'mptcp-wireguard', 'mptcp-ipsec', 'multipath-tcp' => 'mptcp',
            default => 'single',
        };
    }

    private function normalizeBondIngressMode(string $value): string
    {
        return match (strtolower(trim($value))) {
            'plain_tcp_mux', 'plain-tcp-mux', 'plain_tcp', 'tcp_mux' => 'plain_tcp_mux',
            default => 'wss_path_mux',
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function bondTokenSecret(array $context = []): string
    {
        $sidecarPointSecret = trim((string) ($context['bond_sidecar_point_token_secret'] ?? $context['sidecar_point_token_secret'] ?? ''));
        if ($sidecarPointSecret !== '') {
            return $sidecarPointSecret;
        }

        $serviceToken = trim((string) ($context['client_token'] ?? ''));
        if ($serviceToken !== '') {
            return $serviceToken;
        }

        $tokenSecret = trim((string) ($this->config->get('BOND_TOKEN_SECRET', '') ?? ''));
        if ($tokenSecret === '') {
            throw new ApiException('bond_not_configured', 422, [
                'required_env' => ['BOND_TOKEN_SECRET'],
                'accepted_sources' => ['services.client_token', 'BOND_TOKEN_SECRET'],
            ]);
        }

        return $tokenSecret;
    }

    private function bondDefaultRemotePort(): int
    {
        $legacyPort = (int) ($this->config->getInt('BOND_AGGREGATOR_PORT', 0) ?? 0);
        if ($legacyPort > 0) {
            return $legacyPort;
        }

        $defaultPort = (int) ($this->config->getInt('BOND_DEFAULT_REMOTE_PORT', 9000) ?? 9000);
        return $defaultPort > 0 ? $defaultPort : 9000;
    }

    /**
     * @param array<string, mixed> $forward
     * @return array{host: string, port: int}
     */
    private function resolveBondRemoteTarget(array $forward): array
    {
        $host = trim((string) ($forward['bond_remote_host'] ?? $forward['bond_sidecar_host'] ?? ''));
        $port = (int) ($forward['bond_remote_port'] ?? $forward['bond_sidecar_port'] ?? 0);

        if ($host === '') {
            $host = trim((string) ($this->config->get('BOND_AGGREGATOR_HOST', '') ?? ''));
        }
        if ($port <= 0) {
            $port = $this->bondDefaultRemotePort();
        }

        if ($host === '' || $port <= 0) {
            throw new ApiException('bond_remote_target_required', 422, [
                'required_fields' => ['bond_remote_host', 'bond_remote_port'],
                'legacy_env_fallbacks' => ['BOND_AGGREGATOR_HOST', 'BOND_AGGREGATOR_PORT'],
            ]);
        }

        return [
            'host' => $host,
            'port' => $port,
        ];
    }

    /**
     * @param array<string, mixed> $forward
     */
    private function bondRemoteTarget(array $forward): string
    {
        $settings = $this->resolveBondRemoteTarget($forward);
        return $settings['host'] . ':' . $settings['port'];
    }

    /**
     * @param array<string, mixed> $forward
     * @return array<string, mixed>
     */
    private function buildBondClientConfig(array $forward, array $endpoints = []): array
    {
        $remote = $this->resolveBondRemoteTarget($forward);
        $direct = $this->resolveSidecarDirectTarget($forward, 'bond');
        $tokenSecret = $this->bondTokenSecret($forward);
        $ingressMode = $this->normalizeBondIngressMode((string) ($forward['bond_ingress_mode'] ?? 'wss_path_mux'));
        $frontendMode = $this->usesSingleRaceSidecarFrontend($forward)
            ? $this->normalizeClientMode((string) ($forward['client_mode'] ?? 'single'))
            : '';
        $clientDirectQuicEnabled = $this->config->getBool('BOND_CLIENT_DIRECT_QUIC_ENABLED', false);
        [
            'join_timeout_ms' => $adaptiveJoinTimeoutMs,
            'join_timeout_floor_ms' => $joinTimeoutFloorMs,
            'join_timeout_ceiling_ms' => $joinTimeoutCeilingMs,
            'join_timeout_per_leg_ms' => $joinTimeoutPerLegMs,
        ] = $this->adaptiveBondJoinTimeoutConfig($endpoints);
        ['issued_at' => $issuedAt, 'expires_at' => $expiresAt] = $this->bondTokenTimestamps();
        $config = [
            'enabled' => true,
            'frontend_mode' => $frontendMode,
            'strategy_locked' => $frontendMode !== '',
            'ingress_mode' => $ingressMode,
            'route_key' => (string) ($forward['path_prefix'] ?? ''),
            'hybrid_mode' => 'quic_ws',
            'sidecar_host' => $direct['host'],
            'sidecar_port' => $direct['port'],
            'aggregator_host' => $direct['host'],
            'aggregator_port' => $direct['port'],
            'relay_host' => $remote['host'],
            'relay_port' => $remote['port'],
            'remote_target' => $remote['host'] . ':' . $remote['port'],
            'session_token' => $this->buildBondSessionToken($forward, $tokenSecret, $issuedAt, $expiresAt, 'tcp'),
            'udp_session_token' => $this->buildBondSessionToken($forward, $tokenSecret, $issuedAt, $expiresAt, 'udp'),
            'chunk_size' => max(4096, (int) ($this->config->getInt('BOND_CHUNK_SIZE', 128 * 1024) ?? (128 * 1024))),
            'max_window_bytes' => max(65536, (int) ($this->config->getInt('BOND_MAX_WINDOW_BYTES', 16 * 1024 * 1024) ?? (16 * 1024 * 1024))),
            'retransmit_ms' => max(100, (int) ($this->config->getInt('BOND_RETRANSMIT_MS', 300) ?? 300)),
            'connect_timeout_ms' => max(100, (int) ($this->config->getInt('BOND_CONNECT_TIMEOUT_MS', 3000) ?? 3000)),
            'join_timeout_ms' => $adaptiveJoinTimeoutMs,
            'adaptive_join_timeout' => true,
            'join_timeout_floor_ms' => $joinTimeoutFloorMs,
            'join_timeout_ceiling_ms' => $joinTimeoutCeilingMs,
            'join_timeout_per_leg_ms' => $joinTimeoutPerLegMs,
            'interactive_chunk_bytes' => max(1024, (int) ($this->config->getInt('BOND_INTERACTIVE_CHUNK_BYTES', 16 * 1024) ?? (16 * 1024))),
            'latency_guard_bytes' => max(16 * 1024, (int) ($this->config->getInt('BOND_LATENCY_GUARD_BYTES', 256 * 1024) ?? (256 * 1024))),
            'interactive_rtt_slack_ms' => max(5, (int) ($this->config->getInt('BOND_INTERACTIVE_RTT_SLACK_MS', 30) ?? 30)),
            'warmup_trigger_bytes' => max(1024, (int) ($this->config->getInt('BOND_WARMUP_TRIGGER_BYTES', 64 * 1024) ?? (64 * 1024))),
            'warmup_slice_bytes' => max(1024, (int) ($this->config->getInt('BOND_WARMUP_SLICE_BYTES', 64 * 1024) ?? (64 * 1024))),
            'warmup_leg_delay_ms' => max(5, (int) ($this->config->getInt('BOND_WARMUP_LEG_DELAY_MS', 20) ?? 20)),
            'bulk_promote_bytes' => max(1024, (int) ($this->config->getInt('BOND_BULK_PROMOTE_BYTES', 256 * 1024) ?? (256 * 1024))),
            // Mode-aware leg cooldown. cf_mode keeps the long (30 min) isolation -- a "bad leg" there
            // is usually a structurally-bad CF edge IP that should stay sidelined. leigod_mode runs the
            // bond over CPU-bound 2-vCPU slaves where a stall is almost always a TRANSIENT CPU spike
            // that clears in seconds; the Rust cooldown is a FIXED duration with no escalating backoff
            // (apply_leg_cooldown), so a 30-min value turns a ~2s stall into a half-hour capacity loss
            // on a scarce few-leg bond (the combo-5 flap). Use a short, tunable cooldown for leigod_mode.
            // (forwards.backend_mode is kept in sync with the combination on save, so this fires.)
            'leg_cooldown_ms' => (string) ($forward['backend_mode'] ?? '') === 'leigod_mode'
                ? max(1000, (int) ($this->config->getInt('BOND_LEIGOD_LEG_COOLDOWN_MS', 20000) ?? 20000))
                : max(1000, (int) ($this->config->getInt('BOND_LEG_COOLDOWN_MS', 30 * 60 * 1000) ?? (30 * 60 * 1000))),
            // In the CNIX topology the client reaches sidecar through slave endpoints,
            // so QUIC legs must stay enabled while direct-to-sidecar experiments remain opt-in.
            'quic_enabled' => true,
            'quic_via_slave' => true,
            'quic_direct_enabled' => $clientDirectQuicEnabled,
            'active_leg_limit' => max(0, (int) ($this->config->getInt('BOND_ACTIVE_LEG_LIMIT', 0) ?? 0)),
            'quic_active_leg_limit' => max(0, (int) ($this->config->getInt('BOND_QUIC_ACTIVE_LEG_LIMIT', 0) ?? 0)),
            'wss_standby_enabled' => $this->config->getBool('BOND_WSS_STANDBY_ENABLED', true),
            'wss_standby_mode' => 'warm-passive',
            'disable_local_rate_limit' => true,
            'quic_server_name' => $direct['host'],
            'quic_insecure' => true,
            'quic_priority' => 0,
            'ws_priority' => 10,
            'policy_mode' => trim((string) ($this->config->get('BOND_POLICY_MODE', 'latency_first') ?? 'latency_first')) ?: 'latency_first',
            'udp_primary_only' => $this->config->getBool('BOND_UDP_PRIMARY_ONLY', true),
            'primary_reselect_on_failure_only' => $this->config->getBool('BOND_PRIMARY_RESELECT_ON_FAILURE_ONLY', true),
            'cf_primary_selection_mode' => trim((string) ($this->config->get('BOND_CF_PRIMARY_SELECTION_MODE', 'real_path') ?? 'real_path')) ?: 'real_path',
            'token_expires_at' => $expiresAt,
        ];
        if ($this->usesFixedEndpointLeigodBond($forward)) {
            $candidates = is_array($forward['fixed_endpoint_leigod_candidates'] ?? null)
                ? $forward['fixed_endpoint_leigod_candidates']
                : [];
            $config['fixed_endpoint'] = true;
            $config['fixed_endpoint_legs'] = array_values(array_map(
                static fn(array $candidate): array => [
                    'leg_route_id' => (int) ($candidate['line_id'] ?? 0),
                    'priority' => 10,
                ],
                $candidates
            ));
            $config['preferred_initial_legs'] = count($candidates);
            $config['minimum_initial_legs'] = min(2, count($candidates));
            $config['initial_leg_wait_ms'] = 3000;
            $config['leg_reconnect_backoff_ms'] = 5000;
            $config['leg_reconnect_backoff_max_ms'] = 60000;
            $config['quic_enabled'] = false;
            $config['quic_via_slave'] = false;
            $config['quic_direct_enabled'] = false;
            $config['wss_standby_enabled'] = false;
        }
        return $config;
    }

    private function bondIngressAuthToken(array $forward, int $endpointId, string $routeKey): string
    {
        $serviceToken = trim((string) ($forward['client_token'] ?? ''));
        if ($serviceToken === '') {
            $serviceId = (int) ($forward['service_id'] ?? 0);
            if ($serviceId > 0) {
                $service = $this->db->fetchOne('SELECT client_token FROM services WHERE id = :id LIMIT 1', ['id' => $serviceId]);
                $serviceToken = trim((string) ($service['client_token'] ?? ''));
            }
        }
        if ($serviceToken === '') {
            $serviceToken = trim((string) ($this->config->get('BOND_TOKEN_SECRET', 'bond-default') ?? 'bond-default'));
        }

        return hash_hmac(
            'sha256',
            'bond-ingress:' . $endpointId . ':' . $routeKey,
            $serviceToken
        );
    }

    /**
     * @param array<string, mixed> $forward
     */
    private function effectiveMptcpTransport(array $forward): string
    {
        $transport = strtolower(trim((string) ($forward['transport_mode'] ?? 'tcp')));
        if (!in_array($transport, ['tcp', 'udp', 'tcp+udp'], true)) {
            $transport = 'tcp';
        }

        $combinationCfTransport = strtolower(trim((string) ($forward['forward_combination_self_leg_cf_transport'] ?? '')));
        $forwardCfTransport = strtolower(trim((string) ($forward['self_leg_cf_transport'] ?? '')));
        $selfLegCfTransport = $combinationCfTransport !== '' && $combinationCfTransport !== 'cf_direct'
            ? $combinationCfTransport
            : $forwardCfTransport;
        $allowPrivateSelfLeg = (int) ($forward['forward_combination_allow_private_self_leg'] ?? $forward['allow_private_self_leg'] ?? 0) === 1;
        if ($selfLegCfTransport === 'cf_wg' && $allowPrivateSelfLeg) {
            return 'tcp+udp';
        }

        return $transport;
    }

    /**
     * @param array<string, mixed> $forward
     * @return array<string, mixed>
     */
    private function buildMptcpClientConfig(array $forward, array $endpoints = []): array
    {
        $direct = $this->resolveSidecarDirectTarget($forward, 'mptcp');
        $tokenSecret = $this->bondTokenSecret($forward);
        ['issued_at' => $issuedAt, 'expires_at' => $expiresAt] = $this->bondTokenTimestamps();
        $subflowLimitDefault = max(2, count($endpoints) > 0 ? count($endpoints) : 2);
        $underlayLegs = $this->mptcpUnderlayLegs($forward, $endpoints);
        // leigod_mode is a DIRECT-ingress mode: the client relay's MPTCP connect must target the
        // sidecar overlay directly (remote_host), NOT a per-forward slave pre-connect relay. The
        // Rust relay's tcp_remote_addr() prefers slave_relay_host whenever set, so emitting it for a
        // leigod forward makes the relay dial the (wrong/dead) preconnect target -> ETIMEDOUT and the
        // client never uses the proven leigod overlay path. Suppress slave-preconnect for leigod_mode.
        $leigodDirect = strtolower(trim((string) ($forward['backend_mode'] ?? ''))) === 'leigod_mode';
        $leigodPreconnectReuse = $leigodDirect && $this->config->getBool('LEIGOD_SLAVE_PRECONNECT_REUSE', false);
        $leigodPreconnectEndpoints = array_values($endpoints);
        $leigodPreconnectEndpoint = $leigodPreconnectReuse && isset($leigodPreconnectEndpoints[0]) && is_array($leigodPreconnectEndpoints[0])
            ? $leigodPreconnectEndpoints[0]
            : [];
        $leigodPreconnectHost = $leigodPreconnectEndpoint !== []
            ? $this->resolveEndpointPublicHost($leigodPreconnectEndpoint)
            : '';
        if ($leigodPreconnectHost !== '' && !$this->isUsablePublicHost($leigodPreconnectHost)) {
            $leigodPreconnectHost = '';
        }
        $leigodPreconnectReservedPorts = [];
        if ($leigodPreconnectReuse && $leigodPreconnectEndpoint !== []) {
            $leigodNodeConfig = $this->decodeJsonAssoc($leigodPreconnectEndpoint['node_agent_config_json'] ?? null);
            $nodeBasePort = isset($leigodNodeConfig['wireguard_listen_port']) && (int) $leigodNodeConfig['wireguard_listen_port'] > 0
                ? (int) $leigodNodeConfig['wireguard_listen_port']
                : (int) ($forward['public_port'] ?? $leigodPreconnectEndpoint['public_port'] ?? 0);
            $nodeBaseFallback = isset($leigodNodeConfig['wireguard_fallback_tcp_port']) && (int) $leigodNodeConfig['wireguard_fallback_tcp_port'] > 0
                ? (int) $leigodNodeConfig['wireguard_fallback_tcp_port']
                : ($nodeBasePort > 0 ? $nodeBasePort + 10000 : 0);
            $leigodPreconnectReservedPorts = [
                $this->stableMptcpSlavePort($nodeBasePort, (int) ($forward['id'] ?? 0)),
                $this->stableMptcpSlavePort($nodeBaseFallback, (int) ($forward['id'] ?? 0)),
            ];
        }
        if ($leigodPreconnectReuse && ($leigodPreconnectEndpoint === [] || $leigodPreconnectHost === '' || $leigodPreconnectReservedPorts === [])) {
            $leigodPreconnectReuse = false;
            $leigodPreconnectHost = '';
            $leigodPreconnectReservedPorts = [];
        }
        $leigodPreconnectPort = $leigodPreconnectReuse
            ? $this->stableLeigodPreconnectRelayPort(
                (int) ($forward['id'] ?? 0),
                (int) ($leigodPreconnectEndpoint['node_id'] ?? 0),
                $leigodPreconnectReservedPorts
            )
            : 0;
        // leigod overlay rides MPTCP-WG inside the leigod UoT carrier; its WG peer endpoint is the
        // local UoT listener (127.0.0.1), so wg-quick would auto-derive MTU from loopback (65456) and
        // oversized segments black-hole on the ~1280 leigod path. Pin a conservative overlay MTU so
        // bulk data fits. 0 = let wg-quick auto-derive (non-leigod direct-UDP paths, unchanged).
        $overlayMtu = $leigodDirect
            ? max(576, (int) ($this->config->getInt('MPTCP_LEIGOD_OVERLAY_WG_MTU', 1240) ?? 1240))
            : 0;
        $pathManager = (string) ($this->config->get('MPTCP_PATH_MANAGER', 'fullmesh') ?? 'fullmesh');
        $defaultRemoteHost = $direct['host'];
        if ($underlayLegs !== []) {
            $defaultRemoteHost = (string) ($underlayLegs[0]['sidecar_address'] ?? $defaultRemoteHost);
        }
        $legacySlavePreconnect = $this->config->getBool('MPTCP_SLAVE_PRECONNECT', false) && !$leigodDirect && $underlayLegs !== [];
        $legacySlaveRelayPort = $legacySlavePreconnect
            ? $this->stablePreconnectRelayPort((int) ($forward['id'] ?? 0), (int) ($underlayLegs[0]['node_id'] ?? 0))
            : 0;

        return [
            'enabled' => true,
            'sidecar_host' => $defaultRemoteHost,
            'sidecar_port' => $direct['port'],
            'relay_host' => $direct['host'],
            'relay_port' => $direct['port'],
            'remote_host' => $defaultRemoteHost,
            'remote_port' => $direct['port'],
            'tcp_session_token' => $this->buildBondSessionToken($forward, $tokenSecret, $issuedAt, $expiresAt, 'tcp'),
            'udp_session_token' => $this->buildBondSessionToken($forward, $tokenSecret, $issuedAt, $expiresAt, 'udp'),
            'udp_mode' => (string) ($this->config->get('MPTCP_UDP_MODE', 'stream_compat') ?? 'stream_compat'),
            'path_manager' => $pathManager,
            'scheduler' => (string) ($this->config->get('MPTCP_SCHEDULER', 'default') ?? 'default'),
            'subflow_limit' => $leigodPreconnectReuse ? 1 : max(1, (int) ($this->config->getInt('MPTCP_SUBFLOW_LIMIT', $subflowLimitDefault) ?? $subflowLimitDefault)),
            'connect_timeout_ms' => max(100, (int) ($this->config->getInt('MPTCP_CONNECT_TIMEOUT_MS', 3000) ?? 3000)),
            'relay_idle_timeout_seconds' => max(15, (int) ($this->config->getInt('MPTCP_RELAY_IDLE_TIMEOUT_SECONDS', 120) ?? 120)),
            'allow_tcp_fallback' => $this->config->getBool('MPTCP_ALLOW_TCP_FALLBACK', true),
            'tcp_congestion_control' => (string) ($this->config->get('MPTCP_TCP_CONGESTION_CONTROL', 'bbr') ?? 'bbr'),
            'socket_buffer_bytes' => max(1048576, (int) ($this->config->getInt('MPTCP_SOCKET_BUFFER_BYTES', 8388608) ?? 8388608)),
            'initcwnd' => max(10, (int) ($this->config->getInt('MPTCP_INITCWND', 80) ?? 80)),
            'initrwnd' => max(10, (int) ($this->config->getInt('MPTCP_INITRWND', 80) ?? 80)),
            'add_addr_timeout' => max(1, (int) ($this->config->getInt('MPTCP_ADD_ADDR_TIMEOUT', 5) ?? 5)),
            'tcp_wmem_max' => max(1048576, (int) ($this->config->getInt('MPTCP_TCP_WMEM_MAX', 16777216) ?? 16777216)),
            'tcp_rmem_max' => max(1048576, (int) ($this->config->getInt('MPTCP_TCP_RMEM_MAX', 16777216) ?? 16777216)),
            'manage_mptcp_endpoints' => $leigodPreconnectReuse ? false : $underlayLegs !== [],
            'mptcp_endpoint_id_base' => (((int) ($forward['id'] ?? 0) % 25) * 10) + 1,
            'mptcp_endpoints' => $leigodPreconnectReuse ? [] : array_values(array_map(function ($leg, $idx) use ($forward) {
                return [
                    'endpoint_id' => (int) ($leg['endpoint_id'] ?? 0),
                    'address' => $leg['local_address'],
                    'dev' => 'lw-mptcp-' . (int) ($forward['id'] ?? 0) . '-' . $idx,
                    'subflow' => true,
                    'signal' => false,
                    'backup_only' => $leg['backup_only'] ?? false,
                ];
            }, $underlayLegs, array_keys($underlayLegs))),
            'underlays' => $leigodPreconnectReuse ? [] : array_values(array_map(function ($leg, $idx) use ($forward, $overlayMtu) {
                return [
                    'type' => (string) ($this->config->get('MPTCP_UNDERLAY_TYPE', 'wireguard') ?? 'wireguard'),
                    'mode' => 'slave_transit',
                    'interface_name' => 'lw-mptcp-' . (int) ($forward['id'] ?? 0) . '-' . $idx,
                    'peer_keepalive_seconds' => max(0, (int) ($this->config->getInt('MPTCP_PEER_KEEPALIVE_SECONDS', 25) ?? 25)),
                    'mtu' => $overlayMtu,
                    'legs' => [$leg],
                ];
            }, $underlayLegs, array_keys($underlayLegs))),
            'token_expires_at' => $expiresAt,
            // Slave pre-connect relay: when enabled, client connects to slave's
            // relay port via plain TCP. The slave handles MPTCP to sidecar.
            'slave_relay_host' => $leigodPreconnectReuse ? $leigodPreconnectHost : (
                $legacySlavePreconnect
                    ? (string) ($underlayLegs[0]['slave_address'] ?? '')
                    : ''
            ),
            'slave_relay_port' => $leigodPreconnectReuse ? $leigodPreconnectPort : $legacySlaveRelayPort,
        ];
    }

    /**
     * @param array<string, mixed> $forward
     * @param array<int, array<string, mixed>> $endpoints
     * @return array<int, array<string, mixed>>
     */
    private function mptcpUnderlayLegs(array $forward, array $endpoints): array
    {
        $sidecarConfig = $this->decodeJsonAssoc($forward['bond_sidecar_point_agent_config_json'] ?? null);
        // leigod_mode rides the static leigod uplink directly; the per-forward slave pre-connect relay
        // does not belong on its legs (see buildMptcpClientConfig). Suppress it for leigod forwards.
        $leigodDirect = strtolower(trim((string) ($forward['backend_mode'] ?? ''))) === 'leigod_mode';
        $legs = [];
        foreach ($endpoints as $endpoint) {
            $nodeConfig = $this->decodeJsonAssoc($endpoint['node_agent_config_json'] ?? null);
            $slavePublicKey = trim((string) ($nodeConfig['wireguard_public_key'] ?? ''));
            if ($slavePublicKey === '') {
                continue;
            }
            $addresses = $this->stableMptcpLegAddressing((int) ($forward['id'] ?? 0), (int) ($endpoint['node_id'] ?? 0));
            $publicHost = $this->resolveEndpointPublicHost($endpoint);
            if ($publicHost === '') {
                continue;
            }
            $nodeBasePort = isset($nodeConfig['wireguard_listen_port']) && (int) $nodeConfig['wireguard_listen_port'] > 0
                ? (int) $nodeConfig['wireguard_listen_port']
                : (int) ($endpoint['public_port'] ?? 0);
            $peerPort = $this->stableMptcpSlavePort($nodeBasePort, (int) ($forward['id'] ?? 0));
            $nodeBaseFallback = isset($nodeConfig['wireguard_fallback_tcp_port']) && (int) $nodeConfig['wireguard_fallback_tcp_port'] > 0
                ? (int) $nodeConfig['wireguard_fallback_tcp_port']
                : ($nodeBasePort > 0 ? $nodeBasePort + 10000 : 0);
            $fallbackTcpPort = $this->stableMptcpSlavePort($nodeBaseFallback, (int) ($forward['id'] ?? 0));
            $outerTransport = strtolower(trim((string) ($nodeConfig['wireguard_outer_transport'] ?? 'udp')));
            if ($outerTransport === '') {
                $outerTransport = 'udp';
            }
            if ($this->forwardUsesCfMode($forward) && $this->nodeSupportsCfwgRelay($nodeConfig)) {
                $outerTransport = 'wstunnel_udp_via_slave';
            }
            $localRelayPort = $this->stableLocalCfwgUdpPort((int) ($forward['id'] ?? 0), (int) ($endpoint['node_id'] ?? 0));
            $slaveRelayPath = $this->mptcpSlaveRelayPathPrefix((int) ($forward['id'] ?? 0), (int) ($endpoint['node_id'] ?? 0));
            $legs[] = [
                'endpoint_id' => (int) ($endpoint['id'] ?? 0),
                'node_id' => (int) ($endpoint['node_id'] ?? 0),
                'node_name' => (string) ($endpoint['node_name'] ?? ''),
                'health_status' => (string) ($endpoint['health_status'] ?? ''),
                'outer_transport' => $outerTransport,
                'peer_public_host' => $outerTransport === 'wstunnel_udp_via_slave' ? '127.0.0.1' : $publicHost,
                'peer_udp_port' => $outerTransport === 'wstunnel_udp_via_slave' ? $localRelayPort : $peerPort,
                'peer_tcp_fallback_port' => $fallbackTcpPort,
                'local_uot_udp_port' => $this->stableLocalUotUdpPort((int) ($forward['id'] ?? 0), (int) ($endpoint['node_id'] ?? 0)),
                'slave_dial_host' => $outerTransport === 'wstunnel_udp_via_slave' ? $publicHost : '',
                'slave_dial_port' => $outerTransport === 'wstunnel_udp_via_slave' ? $peerPort : 0,
                'slave_sni_host' => $outerTransport === 'wstunnel_udp_via_slave' ? (string) ($forward['sni_host'] ?? $forward['cdn_host'] ?? '') : '',
                'slave_host_header' => $outerTransport === 'wstunnel_udp_via_slave' ? (string) ($forward['sni_host'] ?? $forward['cdn_host'] ?? '') : '',
                'slave_path_prefix' => $outerTransport === 'wstunnel_udp_via_slave' ? $slaveRelayPath : '',
                'cf_target_host' => $outerTransport === 'wstunnel_udp_via_slave' ? '127.0.0.1' : '',
                'cf_target_port' => $outerTransport === 'wstunnel_udp_via_slave' ? $peerPort : 0,
                'peer_public_key' => $slavePublicKey,
                'client_address' => $addresses['client_address'],
                'slave_address' => $addresses['slave_address'],
                'sidecar_address' => $addresses['sidecar_address'],
                'local_address' => $addresses['client_address'],
                'peer_address' => $addresses['slave_address'],
                'mptcp_remote_address' => $addresses['sidecar_address'],
                'subnet' => $addresses['subnet'],
                'sidecar_public_key' => trim((string) ($sidecarConfig['wireguard_public_key'] ?? '')),
                'backup_only' => false,
                'slave_preconnect' => $this->config->getBool('MPTCP_SLAVE_PRECONNECT', false) && !$leigodDirect,
                'preconnect_relay_port' => $leigodDirect ? 0 : $this->stablePreconnectRelayPort(
                    (int) ($forward['id'] ?? 0),
                    (int) ($endpoint['node_id'] ?? 0)
                ),
            ];
        }
        return $legs;
    }

    private function nodeSupportsCfwgRelay(array $nodeConfig): bool
    {
        if (array_key_exists('supports_cfwg_relay', $nodeConfig)) {
            return (bool) $nodeConfig['supports_cfwg_relay'];
        }
        return true;
    }

    private function stableLocalCfwgUdpPort(int $forwardId, int $nodeId): int
    {
        return 40000 + abs((($forwardId * 97) + ($nodeId * 13)) % 2000);
    }

    private function mptcpSlaveRelayPathPrefix(int $forwardId, int $nodeId): string
    {
        return 'lw-mptcp-' . $forwardId . '-' . $nodeId . '-cfwg';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonAssoc(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function configValueEnabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array{subnet:string,client_address:string,slave_address:string,sidecar_address:string}
     */
    private function mptcpLegAddressing(int $endpointId): array
    {
        $index = max(1, $endpointId);
        $bucket = intdiv($index - 1, 60);
        $networkBase = (($index - 1) % 60) * 4;
        $octet3 = 1 + ($bucket % 250);
        $subnet = '10.203.' . $octet3 . '.' . $networkBase . '/30';
        return [
            'subnet' => $subnet,
            'client_address' => '10.203.' . $octet3 . '.' . ($networkBase + 1),
            'slave_address' => '10.203.' . $octet3 . '.' . ($networkBase + 2),
            'sidecar_address' => '10.203.' . $octet3 . '.' . ($networkBase + 3),
        ];
    }

    /**
     * @return array{subnet:string,client_address:string,slave_address:string,sidecar_address:string}
     */
    private function stableMptcpLegAddressing(int $forwardId, int $nodeId): array
    {
        $stableId = max(1, ($forwardId * 256) + $nodeId);
        return $this->mptcpLegAddressing($stableId);
    }

    private function stableMptcpSlavePort(int $basePort, int $forwardId): int
    {
        $rangeStart = $this->mptcpSlavePortRangeStart();
        $rangeEnd = $this->mptcpSlavePortRangeEnd();
        if ($rangeEnd < $rangeStart) {
            [$rangeStart, $rangeEnd] = [$rangeEnd, $rangeStart];
        }
        $rangeSize = max(1, ($rangeEnd - $rangeStart) + 1);
        $normalizedBase = $basePort >= $rangeStart && $basePort <= $rangeEnd
            ? $basePort
            : $rangeStart;
        return $rangeStart + ((($normalizedBase - $rangeStart) + (($forwardId * 7) % $rangeSize)) % $rangeSize);
    }

    private function mptcpSlavePortRangeStart(): int
    {
        return max(1, (int) ($this->config->getInt('MPTCP_SLAVE_PORT_RANGE_START', 7000) ?? 7000));
    }

    private function mptcpSlavePortRangeEnd(): int
    {
        return max($this->mptcpSlavePortRangeStart(), (int) ($this->config->getInt('MPTCP_SLAVE_PORT_RANGE_END', 9000) ?? 9000));
    }

    private function forwardUsesCfMode(array $forward): bool
    {
        if (str_starts_with(strtolower(trim((string) ($forward['backend_mode'] ?? ''))), 'cf')
            || (int) ($forward['cf_location_group_id'] ?? 0) > 0) {
            return true;
        }

        $combinationId = (int) ($forward['forward_combination_id'] ?? 0);
        if ($combinationId <= 0) {
            return false;
        }

        $combination = $this->forwardCombinationById($combinationId);
        return is_array($combination) && $this->staticSidecarCombinationUsesCf($combination);
    }

    private function stableLocalUotUdpPort(int $forwardId, int $nodeId): int
    {
        return 45000 + abs((($forwardId * 257) + ($nodeId * 17)) % 15000);
    }

    private function stableStaticLocalUdpPort(int $pointId, int $combinationId, int $nodeId, string $lane): int
    {
        $laneOffset = $lane === 'backup' ? 1 : 0;
        return 43000 + abs((($pointId * 1009) + ($combinationId * 131) + ($nodeId * 17) + $laneOffset) % 2000);
    }

    /**
     * @return array{subnet:string,slave_address:string,sidecar_address:string}
     */
    private function staticSidecarUplinkAddressing(int $pointId, int $combinationId, int $nodeId, string $lane): array
    {
        $tupleHash = hash('crc32b', $pointId . ':' . $combinationId . ':' . $nodeId . ':' . $lane);
        $bucket = hexdec($tupleHash) % (250 * 64);
        $octet3 = 1 + intdiv($bucket, 64);
        $networkBase = ($bucket % 64) * 4;
        return [
            'subnet' => '10.240.' . $octet3 . '.' . $networkBase . '/30',
            'sidecar_address' => '10.240.' . $octet3 . '.' . ($networkBase + 1),
            'slave_address' => '10.240.' . $octet3 . '.' . ($networkBase + 2),
        ];
    }

    private function staticSidecarUplinkInterfaceName(int $pointId, int $combinationId, int $nodeId, string $laneSuffix): string
    {
        $name = 'lws' . $pointId . 'c' . $combinationId . 'n' . $nodeId . $laneSuffix;
        if (strlen($name) <= 15) {
            return $name;
        }
        return 'lws' . substr(hash('crc32b', $pointId . ':' . $combinationId . ':' . $nodeId . ':' . $laneSuffix), 0, 11);
    }

    /** @return array<int, true> */
    private function staticSidecarSharedWireguardPointIds(): array
    {
        $ids = [];
        foreach (explode(',', (string) ($this->config->get('STATIC_SIDECAR_SHARED_WG_POINT_IDS', '') ?? '')) as $raw) {
            $pointId = (int) trim($raw);
            if ($pointId > 0) {
                $ids[$pointId] = true;
            }
        }
        return $ids;
    }

    private function staticSidecarUsesSharedWireguardPeers(int $pointId): bool
    {
        return isset($this->staticSidecarSharedWireguardPointIds()[$pointId]);
    }

    private function sharedSidecarWireguardInterfaceName(int $pointId): string
    {
        $name = 'lwsp' . $pointId;
        if (strlen($name) <= 15) {
            return $name;
        }
        return 'lwsp' . substr(hash('crc32b', (string) $pointId), 0, 11);
    }

    private function sharedSlaveWireguardInterfaceName(int $pointId, int $nodeId): string
    {
        $name = 'lwsp' . $pointId . 'n' . $nodeId;
        if (strlen($name) <= 15) {
            return $name;
        }
        return 'lwp' . substr(hash('crc32b', $pointId . ':' . $nodeId), 0, 12);
    }

    private function stableStaticSidecarUplinkPort(int $sidecarListenPort, int $portRangeEnd, int $pointId, int $combinationId, int $nodeId, string $lane): int
    {
        if ($sidecarListenPort <= 0 || $portRangeEnd <= $sidecarListenPort) {
            return 0;
        }
        $rangeSize = $portRangeEnd - $sidecarListenPort;
        if ($rangeSize < 2) {
            return 0;
        }
        $uplinkRangeSize = max(1, intdiv($rangeSize, 2));
        $laneOffset = $lane === 'backup' ? 1 : 0;
        $stableIndex = abs((($pointId * 1009) + ($combinationId * 131) + ($nodeId * 2) + $laneOffset) % $uplinkRangeSize);
        return $sidecarListenPort + 1 + $stableIndex;
    }

    /**
     * Stable loopback (TCP) port for the slave-side sidecar-uplink lane selector proxy.
     *
     * The selector binds 127.0.0.1:<port> and the slave runs one selector systemd unit
     * per (service_id, endpoint_id). The port MUST therefore be unique per published
     * selector service on a node. The original inline formula keyed only on
     * (point, combination, node), so every forward/endpoint that shared a lane on the
     * same node collided on one port and the losing units crash-looped with EADDRINUSE.
     * We fold forward_id + endpoint_id into the key (mirroring stableSidecarLegPort,
     * which includes forward_id) so distinct selector services get distinct ports, and
     * keep it stateless/deterministic so any single-task republish reproduces the port.
     * Range (default 45000-49999, TCP) is config-tunable and disjoint from the TCP
     * preconnect (38000-39999) and backend (20000-40000) ranges.
     */
    private function stableSidecarSelectorPort(int $pointId, int $combinationId, int $nodeId, int $forwardId, int $endpointId): int
    {
        $rangeStart = max(1, (int) ($this->config->getInt('STATIC_SIDECAR_SELECTOR_PORT_RANGE_START', 45000) ?? 45000));
        $rangeSize = max(1, (int) ($this->config->getInt('STATIC_SIDECAR_SELECTOR_PORT_RANGE_SIZE', 5000) ?? 5000));
        $stableIndex = abs($this->sidecarSelectorPortSeed($pointId, $combinationId, $nodeId, $forwardId, $endpointId) % $rangeSize);
        return $rangeStart + $stableIndex;
    }

    /**
     * FIX B: the hash bucket seed for a selector's loopback port. Kept identical to the original
     * stableSidecarSelectorPort formula so the collision-free allocator starts each endpoint at the
     * SAME deterministic bucket it used before; only the post-collision probe-forward differs.
     */
    private function sidecarSelectorPortSeed(int $pointId, int $combinationId, int $nodeId, int $forwardId, int $endpointId): int
    {
        return ($pointId * 1009) + ($combinationId * 131) + ($nodeId * 17) + ($forwardId * 257) + ($endpointId * 65537);
    }

    /**
     * FIX B: DETERMINISTIC, per-node COLLISION-FREE selector loopback-port allocation.
     *
     * The slave runs one selector systemd unit per (service_id, endpoint_id), each binding
     * 127.0.0.1:<port>. The previous naive hash (45000 + seed % 5000) had NO collision check, so two
     * different (forward, endpoint) selectors on the same node could hash to the SAME port; the agent
     * crashed on EADDRINUSE and systemd Restart=on-failure crash-looped (14,972 restarts/30min).
     *
     * This enumerates ALL selector-bearing endpoints on the node in a STABLE order (endpoint_id ASC),
     * assigns each a port via probeFreeSlotInSubrange (which starts at the endpoint's deterministic
     * hash bucket and walks forward, skipping ports already assigned to OTHER endpoints on this node),
     * and returns the port for the target endpoint. Because the input set + order are stable and the
     * probe is deterministic, a republish reproduces the same port for the same endpoint (no gratuitous
     * selector restarts). If the target endpoint is not yet persisted / not enumerated (e.g. a brand-new
     * endpoint), it is allocated against the assigned-set built from the node's other endpoints so it
     * still avoids known collisions.
     */
    private function collisionFreeSidecarSelectorPort(int $nodeId, int $pointId, int $combinationId, int $forwardId, int $endpointId): int
    {
        $rangeStart = max(1, (int) ($this->config->getInt('STATIC_SIDECAR_SELECTOR_PORT_RANGE_START', 45000) ?? 45000));
        $rangeSize = max(1, (int) ($this->config->getInt('STATIC_SIDECAR_SELECTOR_PORT_RANGE_SIZE', 5000) ?? 5000));

        // Over-approximation per the plan: every non-deleted/non-deleting endpoint on this node whose
        // forward has a forward_combination_id is treated as selector-bearing. Over-reserving ports is
        // safe (it can only push a port further along the probe, never cause a collision).
        $endpoints = $nodeId > 0
            ? $this->db->fetchAll(
                "SELECT se.id AS endpoint_id, se.forward_id AS forward_id, f.forward_combination_id AS forward_combination_id
                 FROM service_endpoints se
                 INNER JOIN forwards f ON f.id = se.forward_id
                 WHERE se.node_id = :node_id
                   AND se.health_status <> :deleted
                   AND se.health_status <> :deleting
                   AND f.forward_combination_id IS NOT NULL
                 ORDER BY se.id ASC",
                [
                    'node_id' => $nodeId,
                    'deleted' => 'deleted',
                    'deleting' => 'deleting',
                ]
            )
            : [];

        $assigned = [];
        $targetPort = 0;
        $sawTarget = false;
        foreach ($endpoints as $row) {
            $rowEndpointId = (int) ($row['endpoint_id'] ?? 0);
            $rowForwardId = (int) ($row['forward_id'] ?? 0);
            $rowCombinationId = (int) ($row['forward_combination_id'] ?? 0);
            if ($rowEndpointId <= 0) {
                continue;
            }
            // The target endpoint uses the caller's resolved point/combination (its selector payload is
            // being built right now); for every other endpoint we resolve its own point from its
            // combination so its bucket matches what it would compute for itself.
            if ($rowEndpointId === $endpointId) {
                $rowPointId = $pointId;
                $rowCombinationId = $combinationId > 0 ? $combinationId : $rowCombinationId;
            } else {
                $rowPointId = $this->sidecarPointIdForCombination($rowCombinationId);
            }
            $seed = $this->sidecarSelectorPortSeed($rowPointId, $rowCombinationId, $nodeId, $rowForwardId, $rowEndpointId);
            $port = $this->probeFreeSlotInSubrange($rangeStart, $rangeSize, $seed, $assigned);
            if ($port <= 0) {
                // Subrange exhausted: fall back to the bucket (matches legacy behavior) rather than 0.
                $port = $rangeStart + abs($seed % $rangeSize);
            }
            $assigned[$port] = true;
            if ($rowEndpointId === $endpointId) {
                $targetPort = $port;
                $sawTarget = true;
            }
        }

        if ($sawTarget) {
            return $targetPort;
        }

        // Target not enumerated (new/unpersisted endpoint): allocate it against the node's assigned set.
        $seed = $this->sidecarSelectorPortSeed($pointId, $combinationId, $nodeId, $forwardId, $endpointId);
        $port = $this->probeFreeSlotInSubrange($rangeStart, $rangeSize, $seed, $assigned);
        if ($port <= 0) {
            $port = $rangeStart + abs($seed % $rangeSize);
        }
        return $port;
    }

    /**
     * FIX B helper: resolve a forward combination's sidecar point id (memoized for the request) so the
     * per-node collision-free allocator can reproduce each non-target endpoint's hash bucket.
     */
    private function sidecarPointIdForCombination(int $combinationId): int
    {
        if ($combinationId <= 0) {
            return 0;
        }
        if (array_key_exists($combinationId, $this->sidecarPointIdByCombination)) {
            return $this->sidecarPointIdByCombination[$combinationId];
        }
        $combination = $this->forwardCombinationById($combinationId);
        $pointId = is_array($combination) ? (int) ($combination['sidecar_point_id'] ?? 0) : 0;
        $this->sidecarPointIdByCombination[$combinationId] = $pointId;
        return $pointId;
    }

    private function staticSidecarCombinationUsesCf(array $combination): bool
    {
        foreach (['backend_mode', 'connection_type', 'client_mode'] as $key) {
            $value = strtolower(trim((string) ($combination[$key] ?? '')));
            if ($value !== '' && str_starts_with($value, 'cf')) {
                return true;
            }
        }
        return false;
    }

    private function staticSidecarCombinationUsesLeigod(array $combination): bool
    {
        $t = strtolower(trim((string) ($combination['self_leg_outer_transport'] ?? 'cnix')));
        return $t === 'leigod' && (int) ($combination['leigod_line_id'] ?? 0) > 0;
    }

    private function staticSidecarCombinationUsesSharedWireguardPeers(array $combination): bool
    {
        $pointId = (int) ($combination['sidecar_point_id'] ?? 0);
        return $this->staticSidecarUsesSharedWireguardPeers($pointId)
            && !$this->staticSidecarCombinationUsesLeigod($combination)
            && !$this->staticSidecarCombinationUsesCf($combination);
    }

    private function isCanonicalStaticSidecarUplink(array $uplink): bool
    {
        return in_array((string) ($uplink['lane'] ?? ''), ['primary', 'shared'], true);
    }

    private function forwardRequiresSharedStaticSidecarUplink(array $forward): bool
    {
        $pointId = (int) ($forward['bond_sidecar_point_id'] ?? 0);
        if ($pointId <= 0 || !$this->staticSidecarUsesSharedWireguardPeers($pointId)) {
            return false;
        }
        $backendMode = strtolower(trim((string) ($forward['backend_mode'] ?? 'current_mode')));
        if ($backendMode !== 'current_mode') {
            return false;
        }
        $clientMode = $this->normalizeClientMode((string) ($forward['client_mode'] ?? 'single'));
        $runtimePreference = $this->runtimePreferenceForForward($forward);
        return $this->usesSingleRaceSidecarFrontend($forward)
            || $this->sidecarRequiredForRuntime($backendMode, $clientMode, $runtimePreference);
    }

    private function primeSharedStaticSidecarSubnetRegistryForPoint(int $pointId): void
    {
        if ($pointId <= 0 || isset($this->sharedStaticSidecarSubnetRegistryPrimed[$pointId])) {
            return;
        }
        $this->sharedStaticSidecarSubnetRegistryPrimed[$pointId] = true;
        $nodeIds = [];
        foreach ($this->db->fetchAll('SELECT id FROM nodes ORDER BY id ASC') as $row) {
            $nodeId = (int) ($row['id'] ?? 0);
            if ($nodeId > 0) {
                $nodeIds[$nodeId] = true;
            }
        }
        ksort($nodeIds, SORT_NUMERIC);
        foreach (array_keys($nodeIds) as $nodeId) {
            $this->allocateStaticSidecarUplinkSubnet($pointId, 0, (int) $nodeId, 'shared');
        }
    }

    private function stableSidecarGostSocksPort(int $forwardCombinationId, int $nodeId, string $lane = ''): int
    {
        // Deterministic per (combination, node, lane) so the sidecar gost SOCKS port is stable across
        // republishes. Band 47000-47999 is disjoint from backend (20000-40000), cf-backend (26000-26999),
        // wg-local (43000+), and uot/selector (45000+) ranges. The lane keeps primary/backup distinct so
        // the two lanes' gost servers do not collide on one port.
        $seed = crc32("leigod-gost-socks:{$forwardCombinationId}:{$nodeId}:{$lane}");
        return 47000 + ($seed % 1000);
    }

    private function mintLeigodSocksCredentials(array $combination): array
    {
        $user = (string) ($combination['leigod_socks_user'] ?? '') ?: 'wguot';
        $pass = (string) ($combination['leigod_socks_pass'] ?? '');
        if ($pass === '') {
            $pass = rtrim(strtr(base64_encode(random_bytes(21)), '+/', '-_'), '=');
        }
        return [$user, $pass];
    }

    /**
     * The per-line LeiGod toml the slave writes to <leigod_root>/lines/<id>.toml, so the agent fully
     * self-provisions the line (no manual file placement). Sourced from the operator-set env
     * LEIGOD_LINE_TOML_<lineId> (base64-encoded toml). Empty when unset (the agent then assumes a
     * pre-existing toml and just starts the line).
     */
    private function leigodLineToml(int $lineId): string
    {
        if ($lineId <= 0) {
            return '';
        }
        $b64 = trim((string) ($this->config->get('LEIGOD_LINE_TOML_' . $lineId, '') ?? ''));
        if ($b64 === '') {
            return '';
        }
        $decoded = base64_decode($b64, true);
        return is_string($decoded) ? $decoded : '';
    }

    /**
     * The shared LeiGod account session.json the panel pushes to every slave running a leigod line.
     * One account session works concurrently across many slaves (proven 2026-06-02), so a single
     * operator-set env LEIGOD_SESSION_JSON_B64 (base64-encoded session.json) suffices for the fleet.
     * Empty when unset. The LeiGod login needs mobile+captcha+SMS, so the agent can never mint it.
     */
    public function leigodSharedSessionJson(): string
    {
        $b64 = trim((string) ($this->config->get('LEIGOD_SESSION_JSON_B64', '') ?? ''));
        if ($b64 === '') {
            return '';
        }
        $decoded = base64_decode($b64, true);
        return is_string($decoded) ? $decoded : '';
    }

    private function staticSidecarCfPathPrefix(int $pointId, int $combinationId, int $nodeId, string $lane): string
    {
        return 'lws-' . $pointId . '-' . $combinationId . '-' . $nodeId . '-' . ($lane === 'backup' ? 'b' : 'p');
    }

    /**
     * Stable relay port for the slave pre-connect MPTCP relay.
     * Uses port range 38000-39999 to avoid collisions with backend ports (20000-40000).
     */
    private function stablePreconnectRelayPort(int $forwardId, int $nodeId): int
    {
        $rangeStart = max(1, (int) ($this->config->getInt('PRECONNECT_RELAY_PORT_RANGE_START', 38000) ?? 38000));
        $rangeEnd = max($rangeStart, (int) ($this->config->getInt('PRECONNECT_RELAY_PORT_RANGE_END', 39999) ?? 39999));
        $rangeSize = max(1, ($rangeEnd - $rangeStart) + 1);
        return $rangeStart + abs((($forwardId * 131) + ($nodeId * 37)) % $rangeSize);
    }

    /**
     * Stable relay port for leigod slave pre-connect reuse.
     * The data port stays in 7000-8999, so health_port=data+1 <= 9000.
     */
    private function stableLeigodPreconnectRelayPort(int $forwardId, int $nodeId, array $reservedPorts = []): int
    {
        $configuredStart = (int) ($this->config->getInt('LEIGOD_PRECONNECT_RELAY_PORT_RANGE_START', 7000) ?? 7000);
        $configuredEnd = (int) ($this->config->getInt('LEIGOD_PRECONNECT_RELAY_PORT_RANGE_END', 8999) ?? 8999);
        $rangeStart = max(7000, min(8999, $configuredStart));
        $rangeEnd = max($rangeStart, min(8999, $configuredEnd));
        $reserved = [];
        foreach ($reservedPorts as $reservedPort) {
            $port = (int) $reservedPort;
            if ($port > 0) {
                $reserved[$port] = true;
            }
        }

        $seed = abs(($forwardId * 131) + ($nodeId * 37));
        foreach ([[$rangeStart, $rangeEnd], [7000, 8999]] as $range) {
            [$start, $end] = $range;
            $rangeSize = max(1, ($end - $start) + 1);
            for ($offset = 0; $offset < $rangeSize; $offset++) {
                $candidate = $start + (($seed + $offset) % $rangeSize);
                if (isset($reserved[$candidate]) || isset($reserved[$candidate + 1])) {
                    continue;
                }
                return $candidate;
            }
        }

        throw new \RuntimeException('No free LeiGod preconnect relay port in 7000-8999');
    }

    /**
     * Compute a stable per-leg listen port for the sidecar WG transit interface.
     * Returns 0 if the sidecar has no declared port range.
     */
    private function stableSidecarLegPort(int $sidecarListenPort, int $portRangeEnd, int $forwardId, int $nodeId): int
    {
        if ($sidecarListenPort <= 0 || $portRangeEnd <= $sidecarListenPort) {
            return 0;
        }
        $rangeSize = $portRangeEnd - $sidecarListenPort;
        if ($rangeSize < 2) {
            return 0;
        }
        $uplinkRangeSize = max(1, intdiv($rangeSize, 2));
        $legRangeStart = $sidecarListenPort + 1 + $uplinkRangeSize;
        $legRangeSize = max(1, $rangeSize - $uplinkRangeSize);
        $stableIndex = abs(($forwardId * 256 + $nodeId) % $legRangeSize);
        return $legRangeStart + $stableIndex;
    }

    /**
     * Probe within a contiguous [start..start+size-1] subrange for a free slot, beginning at
     * the deterministic hash bucket and walking forward (wrapping) until an unassigned slot is
     * found. Returns the chosen absolute value, or 0 when every slot in the subrange is taken.
     * Deterministic per ($hashSeed) given a fixed assigned-set, so a republish reproduces it.
     *
     * @param array<int, bool> $assignedValues set of already-assigned absolute values
     */
    private function probeFreeSlotInSubrange(int $rangeStart, int $rangeSize, int $hashSeed, array $assignedValues): int
    {
        if ($rangeSize <= 0) {
            return 0;
        }
        $startIndex = abs($hashSeed % $rangeSize);
        for ($step = 0; $step < $rangeSize; $step++) {
            $candidate = $rangeStart + (($startIndex + $step) % $rangeSize);
            if (!isset($assignedValues[$candidate])) {
                return $candidate;
            }
        }
        return 0;
    }

    /**
     * Record + log that a sidecar point's allocation subrange is too small to hold every
     * tuple that must fit. We persist an operational marker (consumed by ops dashboards) and
     * emit a log line; the marker is written once per (point, lane-class) per process.
     */
    private function reportStaticSidecarUndersize(int $pointId, string $laneClass, int $subrangeSize, int $demand): void
    {
        $markerKey = $pointId . ':' . $laneClass;
        if (isset($this->staticSidecarUndersizeReported[$markerKey])) {
            return;
        }
        $this->staticSidecarUndersizeReported[$markerKey] = true;
        $payload = json_encode([
            'sidecar_point_id' => $pointId,
            'lane_class' => $laneClass,
            'subrange_size' => $subrangeSize,
            'distinct_tuples_demanded' => $demand,
            'detected_at' => $this->now(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->appSettingSet('static_sidecar_alloc_undersize:' . $pointId, $payload !== false ? $payload : '');
        error_log(
            'static_sidecar_alloc_undersize point=' . $pointId
            . ' lane_class=' . $laneClass
            . ' subrange_size=' . $subrangeSize
            . ' demand=' . $demand
            . ' (subrange smaller than the number of distinct tuples that must fit)'
        );
    }

    /**
     * Uniqueness-checked uplink WG listen port allocation for a (point, combination, node, lane)
     * tuple. Carves the disjoint UPLINK half of the sidecar WG range and probes for the next
     * free slot relative to the deterministic bucket, de-colliding tuples that pure %rangeSize
     * hashing would alias. Cached per tuple for republish idempotency. Returns 0 if no range.
     */
    private function allocateStaticSidecarUplinkPort(int $sidecarListenPort, int $portRangeEnd, int $pointId, int $combinationId, int $nodeId, string $lane): int
    {
        if ($sidecarListenPort <= 0 || $portRangeEnd <= $sidecarListenPort) {
            return 0;
        }
        $rangeSize = $portRangeEnd - $sidecarListenPort;
        if ($rangeSize < 2) {
            return 0;
        }
        $tupleKey = $combinationId . ':' . $nodeId . ':' . $lane;
        $registry = &$this->staticSidecarUplinkPortRegistry[$pointId];
        if (!is_array($registry)) {
            $registry = [];
        }
        if (isset($registry[$tupleKey])) {
            return $registry[$tupleKey];
        }
        $uplinkRangeSize = max(1, intdiv($rangeSize, 2));
        $rangeStart = $sidecarListenPort + 1;
        $laneOffset = $lane === 'backup' ? 1 : 0;
        $hashSeed = abs(($pointId * 1009) + ($combinationId * 131) + ($nodeId * 2) + $laneOffset);
        $assigned = array_fill_keys(array_values($registry), true);
        if (count($registry) >= $uplinkRangeSize) {
            $this->reportStaticSidecarUndersize($pointId, 'uplink', $uplinkRangeSize, count($registry) + 1);
        }
        $port = $this->probeFreeSlotInSubrange($rangeStart, $uplinkRangeSize, $hashSeed, $assigned);
        if ($port <= 0) {
            // Subrange exhausted: fall back to the deterministic bucket so we still publish a
            // (possibly colliding) port rather than nothing — the undersize marker is the signal.
            $this->reportStaticSidecarUndersize($pointId, 'uplink', $uplinkRangeSize, count($registry) + 1);
            $port = $rangeStart + abs($hashSeed % $uplinkRangeSize);
        }
        $registry[$tupleKey] = $port;
        return $port;
    }

    /**
     * Uniqueness-checked per-forward leg port allocation. Carves the disjoint LEG half of the
     * sidecar WG range (above the uplink half) and probes for a free slot relative to the
     * deterministic bucket. Cached per (forward, node) tuple for republish idempotency.
     */
    private function allocateStaticSidecarLegPort(int $sidecarListenPort, int $portRangeEnd, int $pointId, int $forwardId, int $nodeId): int
    {
        if ($sidecarListenPort <= 0 || $portRangeEnd <= $sidecarListenPort) {
            return 0;
        }
        $rangeSize = $portRangeEnd - $sidecarListenPort;
        if ($rangeSize < 2) {
            return 0;
        }
        $tupleKey = $forwardId . ':' . $nodeId;
        $registry = &$this->staticSidecarLegPortRegistry[$pointId];
        if (!is_array($registry)) {
            $registry = [];
        }
        if (isset($registry[$tupleKey])) {
            return $registry[$tupleKey];
        }
        $uplinkRangeSize = max(1, intdiv($rangeSize, 2));
        $legRangeStart = $sidecarListenPort + 1 + $uplinkRangeSize;
        $legRangeSize = max(1, $rangeSize - $uplinkRangeSize);
        $hashSeed = abs(($forwardId * 256) + $nodeId);
        $assigned = array_fill_keys(array_values($registry), true);
        if (count($registry) >= $legRangeSize) {
            $this->reportStaticSidecarUndersize($pointId, 'leg', $legRangeSize, count($registry) + 1);
        }
        $port = $this->probeFreeSlotInSubrange($legRangeStart, $legRangeSize, $hashSeed, $assigned);
        if ($port <= 0) {
            $this->reportStaticSidecarUndersize($pointId, 'leg', $legRangeSize, count($registry) + 1);
            $port = $legRangeStart + abs($hashSeed % $legRangeSize);
        }
        $registry[$tupleKey] = $port;
        return $port;
    }

    /**
     * Uniqueness-checked /30 transit subnet allocation for a (point, combination, node, lane)
     * tuple. Probes the 10.240.0.0/16-derived /30 space for a free network given the subnets
     * already handed out for this sidecar point, de-colliding tuples that pure %bucket hashing
     * would alias onto the same /30. Cached per tuple for republish idempotency.
     *
     * @return array{subnet:string,slave_address:string,sidecar_address:string}
     */
    private function allocateStaticSidecarUplinkSubnet(int $pointId, int $combinationId, int $nodeId, string $lane): array
    {
        $tupleKey = $combinationId . ':' . $nodeId . ':' . $lane;
        $registry = &$this->staticSidecarSubnetRegistry[$pointId];
        if (!is_array($registry)) {
            $registry = [];
        }
        if (isset($registry[$tupleKey])) {
            return $this->staticSidecarSubnetFromBucket($registry[$tupleKey]);
        }
        $totalBuckets = 250 * 64; // 10.240.1.0 .. 10.240.250.x, 64 /30s per octet3
        $tupleHash = hash('crc32b', $pointId . ':' . $combinationId . ':' . $nodeId . ':' . $lane);
        $hashSeed = abs((int) hexdec($tupleHash));
        $assigned = array_fill_keys(array_values($registry), true);
        if (count($registry) >= $totalBuckets) {
            $this->reportStaticSidecarUndersize($pointId, 'subnet', $totalBuckets, count($registry) + 1);
        }
        $startIndex = $hashSeed % $totalBuckets;
        $bucket = -1;
        for ($step = 0; $step < $totalBuckets; $step++) {
            $candidate = ($startIndex + $step) % $totalBuckets;
            if (!isset($assigned[$candidate])) {
                $bucket = $candidate;
                break;
            }
        }
        if ($bucket < 0) {
            $this->reportStaticSidecarUndersize($pointId, 'subnet', $totalBuckets, count($registry) + 1);
            $bucket = $startIndex;
        }
        $registry[$tupleKey] = $bucket;
        return $this->staticSidecarSubnetFromBucket($bucket);
    }

    /**
     * @return array{subnet:string,slave_address:string,sidecar_address:string}
     */
    private function staticSidecarSubnetFromBucket(int $bucket): array
    {
        $octet3 = 1 + intdiv($bucket, 64);
        $networkBase = ($bucket % 64) * 4;
        return [
            'subnet' => '10.240.' . $octet3 . '.' . $networkBase . '/30',
            'sidecar_address' => '10.240.' . $octet3 . '.' . ($networkBase + 1),
            'slave_address' => '10.240.' . $octet3 . '.' . ($networkBase + 2),
        ];
    }

    private function sidecarWireguardListenPort(array $point, array $pointConfig): int
    {
        $configured = (int) ($pointConfig['wireguard_listen_port'] ?? 0);
        if ($configured > 0) {
            return $configured;
        }
        return $this->sidecarMptcpListenPort($point, $pointConfig);
    }

    private function sidecarWireguardPortRangeEnd(int $listenPort, array $pointConfig): int
    {
        $configured = (int) ($pointConfig['wireguard_port_range_end'] ?? 0);
        if ($configured > $listenPort) {
            return $configured;
        }
        if ($listenPort <= 0) {
            return 0;
        }
        return min(65535, $listenPort + 30);
    }

    private function legRelayProtocolsForNode(string $clientMode, array $node): array
    {
        if ($clientMode !== 'bond') {
            return [];
        }
        return $this->nodeSupportsBondQuic(
            $node['agent_config_json'] ?? null,
            (string) ($node['agent_version'] ?? $node['node_agent_version'] ?? '')
        ) ? ['quic'] : [];
    }

    private function nodeSupportsBondQuic(mixed $nodeConfig, string $nodeAgentVersion): bool
    {
        $decoded = $this->decodeJsonAssoc($nodeConfig);
        if ($decoded === []) {
            return false;
        }
        if ($nodeAgentVersion === '' || version_compare($nodeAgentVersion, '2026.04.15.2', '<')) {
            return false;
        }
        if (!(bool) ($decoded['supports_quic'] ?? false)) {
            return false;
        }
        $outerTransport = strtolower(trim((string) ($decoded['wireguard_outer_transport'] ?? 'udp')));
        if ($outerTransport === 'tcp') {
            return false;
        }
        $protocols = $decoded['bond_protocols'] ?? null;
        if (!is_array($protocols)) {
            return false;
        }
        $normalized = array_map(
            static fn(mixed $value): string => strtolower(trim((string) $value)),
            $protocols
        );
        return in_array('quic', $normalized, true);
    }


    /**
     * @param array<string, mixed> $forward
     * @return array<string, mixed>
     */
    public function donationPolicyForForward(array $forward): array
    {
        // donation_policy_payload
        $combinationId = (int) ($forward['forward_combination_id'] ?? 0);
        $desiredLatency = (int) ($forward['desired_latency_ms'] ?? $forward['forward_combination_desired_latency_ms'] ?? 0);
        $allow = (int) ($forward['allow_public_self_leg_donation'] ?? $forward['forward_combination_allow_public_self_leg_donation'] ?? 0);
        if ($combinationId <= 0 || $desiredLatency <= 0 || $allow !== 1) {
            return [];
        }
        return [
            'forward_combination_id' => $combinationId,
            'desired_latency_ms' => $desiredLatency,
            'donation_gate_ratio' => self::DONATION_GATE_RATIO,
            'donation_qualify_window_seconds' => max(5, (int) ($forward['donation_qualify_window_seconds'] ?? $forward['forward_combination_donation_qualify_window_seconds'] ?? 60)),
            'donation_revoke_window_seconds' => max(5, (int) ($forward['donation_revoke_window_seconds'] ?? $forward['forward_combination_donation_revoke_window_seconds'] ?? 30)),
            'donation_min_open_ports' => max(0, (int) ($forward['donation_min_open_ports'] ?? $forward['forward_combination_donation_min_open_ports'] ?? 1)),
            'donor_client_id' => (int) ($forward['line_client_id'] ?? 0),
            'desktop_client_session_id' => (int) ($forward['desktop_client_session_id'] ?? 0),
            'device_id' => (string) ($forward['device_id'] ?? ''),
        ];
    }

    public function recordDonationSelfCheck(int $nodeId, array $payload): void
    {
        // donation_self_check_ingest
        if ($nodeId <= 0) {
            return;
        }
        $items = is_array($payload['donation_self_check'] ?? null) ? $payload['donation_self_check'] : $payload;
        if (isset($items['forward_combination_id'])) {
            $items = [$items];
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $combinationId = (int) ($item['forward_combination_id'] ?? 0);
            if ($combinationId <= 0) {
                continue;
            }
            $donorId = (int) ($item['donor_id'] ?? 0);
            $clientId = (int) ($item['donor_client_id'] ?? $item['client_id'] ?? 0);
            $desktopSessionId = (int) ($item['desktop_client_session_id'] ?? 0);
            $deviceId = trim((string) ($item['device_id'] ?? ''));
            if ($donorId <= 0 && $clientId <= 0 && $deviceId === '') {
                continue;
            }

            $donor = $this->findOrCreateSelfLegDonorFromSelfCheck($combinationId, $donorId, $clientId, $desktopSessionId, $deviceId, $item);
            if (!is_array($donor)) {
                continue;
            }

            $openPorts = is_array($item['open_ports'] ?? null) ? $item['open_ports'] : [];
            $this->db->execute(
                'UPDATE forward_combination_self_leg_donors
                 SET last_self_check_json = :last_self_check_json,
                     last_measured_latency_ms = :last_measured_latency_ms,
                     open_ports_json = :open_ports_json,
                     donor_public_host = COALESCE(NULLIF(:donor_public_host, \'\'), donor_public_host),
                     donor_public_port = COALESCE(NULLIF(:donor_public_port, 0), donor_public_port),
                     last_heartbeat_at = :last_heartbeat_at,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'id' => (int) ($donor['id'] ?? 0),
                    'last_self_check_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'last_measured_latency_ms' => $this->nullableFloat($item['measured_chain_latency_ms'] ?? null),
                    'open_ports_json' => json_encode($openPorts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'donor_public_host' => trim((string) ($item['public_host'] ?? '')),
                    'donor_public_port' => max(0, (int) ($item['public_port'] ?? 0)),
                    'last_heartbeat_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function findOrCreateSelfLegDonorFromSelfCheck(int $combinationId, int $donorId, int $clientId, int $desktopSessionId, string $deviceId, array $item): ?array
    {
        if ($donorId > 0) {
            $donor = $this->db->fetchOne(
                'SELECT * FROM forward_combination_self_leg_donors WHERE id = :id AND forward_combination_id = :forward_combination_id LIMIT 1',
                ['id' => $donorId, 'forward_combination_id' => $combinationId]
            );
            return is_array($donor) ? $donor : null;
        }
        if ($clientId <= 0) {
            return null;
        }

        $params = [
            'forward_combination_id' => $combinationId,
            'client_id' => $clientId,
        ];
        $conditions = ['forward_combination_id = :forward_combination_id', 'client_id = :client_id'];
        if ($desktopSessionId > 0) {
            $conditions[] = 'desktop_client_session_id = :desktop_client_session_id';
            $params['desktop_client_session_id'] = $desktopSessionId;
        } else {
            $conditions[] = 'desktop_client_session_id IS NULL';
        }
        if ($deviceId !== '') {
            $conditions[] = 'device_id = :device_id';
            $params['device_id'] = $deviceId;
        } else {
            $conditions[] = '(device_id IS NULL OR device_id = \'\')';
        }

        $donor = $this->db->fetchOne(
            'SELECT * FROM forward_combination_self_leg_donors WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1',
            $params
        );
        if (is_array($donor)) {
            return $donor;
        }

        $host = trim((string) ($item['public_host'] ?? ''));
        $port = max(0, (int) ($item['public_port'] ?? 0));
        $id = $this->db->insert('forward_combination_self_leg_donors', [
            'forward_combination_id' => $combinationId,
            'client_id' => $clientId,
            'desktop_client_session_id' => $desktopSessionId > 0 ? $desktopSessionId : null,
            'device_id' => $deviceId !== '' ? $deviceId : null,
            'state' => 'qualifying',
            'donor_public_host' => $host !== '' ? $host : null,
            'donor_public_port' => $port > 0 ? $port : null,
            'donor_transport' => trim((string) ($item['donor_transport'] ?? 'tcp')) ?: 'tcp',
            'owner_opt_in' => 1,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        return $this->db->fetchOne('SELECT * FROM forward_combination_self_leg_donors WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function recordDonationReverseProbe(int $sidecarPointId, array $payload): void
    {
        if ($sidecarPointId <= 0) {
            return;
        }
        $items = is_array($payload['donation_reverse_probe'] ?? null) ? $payload['donation_reverse_probe'] : $payload;
        if (isset($items['donor_id'])) {
            $items = [$items];
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $donorId = (int) ($item['donor_id'] ?? 0);
            if ($donorId <= 0) {
                continue;
            }
            $this->db->execute(
                'UPDATE forward_combination_self_leg_donors
                 SET last_reverse_probe_json = :last_reverse_probe_json,
                     last_heartbeat_at = :last_heartbeat_at,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'id' => $donorId,
                    'last_reverse_probe_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'last_heartbeat_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function donationReverseProbeTargetsForPoint(int $sidecarPointId): array
    {
        if ($sidecarPointId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT d.*, f.id AS forward_id
             FROM forward_combination_self_leg_donors d
             INNER JOIN forward_combinations fc ON fc.id = d.forward_combination_id
             LEFT JOIN forwards f ON f.forward_combination_id = fc.id AND f.enabled = 1
             WHERE fc.sidecar_point_id = :sidecar_point_id
               AND d.state = :state
               AND d.owner_opt_in = 1
               AND d.donor_public_host IS NOT NULL
               AND d.donor_public_port IS NOT NULL
             ORDER BY d.id ASC, f.id ASC',
            ['sidecar_point_id' => $sidecarPointId, 'state' => 'active']
        );
        $targets = [];
        foreach ($rows as $row) {
            $host = trim((string) ($row['donor_public_host'] ?? ''));
            $port = (int) ($row['donor_public_port'] ?? 0);
            if ($host === '' || $port <= 0) {
                continue;
            }
            $targets[] = [
                'forward_combination_id' => (int) ($row['forward_combination_id'] ?? 0),
                'donor_id' => (int) ($row['id'] ?? 0),
                'client_id' => (int) ($row['client_id'] ?? 0),
                'forward_id' => (int) ($row['forward_id'] ?? 0),
                'protocol' => str_contains((string) ($row['donor_transport'] ?? 'tcp'), 'udp') ? 'udp' : 'tcp',
                'public_host' => $host,
                'public_port' => $port,
                'expected_transport' => (string) ($row['donor_transport'] ?? 'tcp'),
            ];
        }
        return $targets;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    // Publishes sidecar management field: self_leg_client_peers
    public function selfLegClientPeersForPoint(int $pointId): array
    {
        if ($pointId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT f.id AS forward_id, f.line_client_id, c.agent_config_json AS client_agent_config_json,
                    combo.self_leg_cf_transport AS forward_combination_self_leg_cf_transport,
                    sp.agent_config_json AS sidecar_agent_config_json, sp.mptcp_listen_port, sp.bond_listen_port
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             LEFT JOIN clients c ON c.id = f.line_client_id
             LEFT JOIN sidecar_points sp ON sp.id = f.bond_sidecar_point_id
             LEFT JOIN forward_combinations combo ON combo.id = f.forward_combination_id
             WHERE f.enabled = 1
               AND f.bond_sidecar_point_id = :point_id
               AND s.status = :service_status
               AND combo.allow_private_self_leg = 1
             ORDER BY f.id ASC',
            [
                'point_id' => $pointId,
                'service_status' => 'active',
            ]
        );
        $peers = [];
        foreach ($rows as $row) {
            $clientConfig = $this->decodeJsonAssoc($row['client_agent_config_json'] ?? null);
            $sidecarConfig = $this->decodeJsonAssoc($row['sidecar_agent_config_json'] ?? null);
            $clientPublicKey = trim((string) ($clientConfig['wireguard_public_key'] ?? ''));
            $sidecarPublicKey = trim((string) ($sidecarConfig['wireguard_public_key'] ?? ''));
            $forwardId = (int) ($row['forward_id'] ?? 0);
            $clientId = (int) ($row['line_client_id'] ?? 0);
            if ($clientPublicKey === '' || $sidecarPublicKey === '' || $forwardId <= 0 || $clientId <= 0) {
                continue;
            }
            $addresses = $this->selfLegWireGuardAddressing($forwardId, $clientId);
            $listenPort = (int) ($sidecarConfig['wireguard_listen_port'] ?? 0);
            if ($listenPort <= 0) {
                $listenPort = (int) ($row['mptcp_listen_port'] ?? $row['bond_listen_port'] ?? 0);
            }
            // Regression sentinel: cf_wg_sidecar_peer_uses_lw_cfwg_interface
            $selfLegCfTransport = strtolower(trim((string) ($row['forward_combination_self_leg_cf_transport'] ?? 'cf_direct')));
            $hasCfWgClientPeer = $selfLegCfTransport === 'cf_wg';
            if ($hasCfWgClientPeer) {
                $legacyInterfaceName = 'lw-self-' . $forwardId;
                $peers[] = [
                    'enabled' => false,
                    'mode' => 'client_self_leg',
                    'forward_id' => $forwardId,
                    'client_id' => $clientId,
                    'interface_name' => $legacyInterfaceName,
                    'listen_port' => $listenPort,
                    'sidecar_address' => $addresses['sidecar_address'],
                    'client_address' => $addresses['client_address'],
                    'client_public_key' => $clientPublicKey,
                    'sidecar_public_key' => $sidecarPublicKey,
                    'persistent_keepalive' => max(0, (int) ($this->config->getInt('SELF_LEG_WG_KEEPALIVE_SECONDS', 25) ?? 25)),
                ];
            }
            $interfaceName = $selfLegCfTransport === 'cf_wg' ? 'lw-cfwg-' . $forwardId : 'lw-self-' . $forwardId;
            $peers[] = [
                'enabled' => true,
                'mode' => 'client_self_leg',
                'forward_id' => $forwardId,
                'client_id' => $clientId,
                'interface_name' => $interfaceName,
                'listen_port' => $listenPort,
                'sidecar_address' => $addresses['sidecar_address'],
                'client_address' => $addresses['client_address'],
                'client_public_key' => $clientPublicKey,
                'sidecar_public_key' => $sidecarPublicKey,
                'persistent_keepalive' => max(0, (int) ($this->config->getInt('SELF_LEG_WG_KEEPALIVE_SECONDS', 25) ?? 25)),
            ];
        }
        return $peers;
    }

    /**
     * @return array<string, mixed>
     */
    public function mptcpRuntimeRowsForSidecarPoint(int $pointId): array
    {
        if ($pointId <= 0) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT f.id, f.path_prefix, f.target_host, f.target_port, f.transport_mode,
                    f.bond_remote_host, f.bond_remote_port, f.backend_mode,
                    fc.upstream_realization_mode AS chain_upstream_realization_mode,
                    combo.self_leg_cf_transport AS forward_combination_self_leg_cf_transport
             FROM forwards f
             LEFT JOIN forward_chains fc ON fc.id = f.forward_chain_id
             LEFT JOIN forward_combinations combo ON combo.id = f.forward_combination_id
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.bond_sidecar_point_id = :point_id
               AND f.enabled = 1
               AND f.client_mode = :client_mode
               AND s.status = :service_status
             ORDER BY f.id ASC',
            [
                'point_id' => $pointId,
                'client_mode' => 'mptcp',
                'service_status' => 'active',
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function mptcpSidecarManagementForPoint(int $pointId): array
    {
        $point = $this->sidecarPointById($pointId);
        if ($point === null) {
            return [];
        }
        $pointConfig = $this->decodeJsonAssoc($point['agent_config_json'] ?? null);
        $forwards = $this->db->fetchAll(
            'SELECT f.*, s.status AS service_status
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.bond_sidecar_point_id = :point_id
               AND f.enabled = 1
               AND f.client_mode = :client_mode
               AND (f.forward_combination_id IS NULL OR f.forward_combination_id = 0)
               AND s.status = :service_status
             ORDER BY f.id ASC',
            [
                'point_id' => $pointId,
                'client_mode' => 'mptcp',
                'service_status' => 'active',
            ]
        );
        $legs = [];
        foreach ($forwards as $forward) {
            $endpoints = $this->db->fetchAll(
                'SELECT e.*, n.name AS node_name, n.public_ip AS node_public_ip, n.agent_config_json AS node_agent_config_json
                 FROM service_endpoints e
                 INNER JOIN nodes n ON n.id = e.node_id
                 WHERE e.forward_id = :forward_id
                   AND e.health_status <> :deleted
                   AND e.health_status <> :deleting
                 ORDER BY e.priority ASC, e.id ASC',
                [
                    'forward_id' => (int) $forward['id'],
                    'deleted' => 'deleted',
                    'deleting' => 'deleting',
                ]
            );
            foreach ($endpoints as $endpoint) {
                $nodeConfig = $this->decodeJsonAssoc($endpoint['node_agent_config_json'] ?? null);
                $slavePublicKey = trim((string) ($nodeConfig['wireguard_public_key'] ?? ''));
                if ($slavePublicKey === '') {
                    continue;
                }
                $addresses = $this->stableMptcpLegAddressing((int) ($forward['id'] ?? 0), (int) ($endpoint['node_id'] ?? 0));
                $publicHost = $this->resolveEndpointPublicHost($endpoint);
                if ($publicHost === '') {
                    continue;
                }
                $nodeBasePort = isset($nodeConfig['wireguard_listen_port']) && (int) $nodeConfig['wireguard_listen_port'] > 0
                    ? (int) $nodeConfig['wireguard_listen_port']
                    : (int) ($endpoint['public_port'] ?? 0);
                $peerPort = $this->stableMptcpSlavePort($nodeBasePort, (int) ($forward['id'] ?? 0));
                $nodeBaseFallback = isset($nodeConfig['wireguard_fallback_tcp_port']) && (int) $nodeConfig['wireguard_fallback_tcp_port'] > 0
                    ? (int) $nodeConfig['wireguard_fallback_tcp_port']
                    : ($nodeBasePort > 0 ? $nodeBasePort + 10000 : 0);
                $fallbackTcpPort = $this->stableMptcpSlavePort($nodeBaseFallback, (int) ($forward['id'] ?? 0));
                $outerTransport = strtolower(trim((string) ($nodeConfig['wireguard_outer_transport'] ?? 'udp')));
                if ($outerTransport === '') {
                    $outerTransport = 'udp';
                }
                $legs[] = [
                    'forward_id' => (int) ($forward['id'] ?? 0),
                    'service_id' => (int) ($forward['service_id'] ?? 0),
                    'endpoint_id' => (int) ($endpoint['id'] ?? 0),
                    'node_id' => (int) ($endpoint['node_id'] ?? 0),
                    'node_name' => (string) ($endpoint['node_name'] ?? ''),
                    'outer_transport' => $outerTransport,
                    'peer_public_host' => $publicHost,
                    'peer_udp_port' => $peerPort,
                    'peer_tcp_fallback_port' => $fallbackTcpPort,
                    'peer_public_key' => $slavePublicKey,
                    'client_address' => $addresses['client_address'],
                    'slave_address' => $addresses['slave_address'],
                    'sidecar_address' => $addresses['sidecar_address'],
                    'local_address' => $addresses['sidecar_address'],
                    'peer_address' => $addresses['slave_address'],
                    'mptcp_remote_address' => $addresses['client_address'],
                    'subnet' => $addresses['subnet'],
                ];
            }
        }

        $sidecarListenPort = $this->sidecarWireguardListenPort($point, $pointConfig);
        $sidecarPortRangeEnd = $this->sidecarWireguardPortRangeEnd($sidecarListenPort, $pointConfig);

        return [
            'underlays' => array_values(array_map(function ($leg, $idx) use ($pointId, $sidecarListenPort, $sidecarPortRangeEnd) {
                $fid = (int) ($leg['forward_id'] ?? 0);
                $nid = (int) ($leg['node_id'] ?? 0);
                $fixedPort = $this->allocateStaticSidecarLegPort($sidecarListenPort, $sidecarPortRangeEnd, $pointId, $fid, $nid);
                return [
                    'type' => (string) ($this->config->get('MPTCP_UNDERLAY_TYPE', 'wireguard') ?? 'wireguard'),
                    'mode' => 'slave_transit',
                    'interface_name' => 'lw-sc-' . $pointId . '-' . $idx,
                    'listen_port' => $fixedPort,
                    'peer_keepalive_seconds' => 0,
                    'legs' => [$leg],
                ];
            }, $legs, array_keys($legs))),
            'mptcp_policy' => [
                'endpoint_id_base' => (($pointId % 25) * 10) + 1,
                'endpoints' => array_values(array_map(function ($leg, $idx) use ($pointId) {
                    return [
                        'address' => (string) ($leg['local_address'] ?? ''),
                        'dev' => 'lw-sc-' . $pointId . '-' . $idx,
                        'signal' => true,
                    ];
                }, $legs, array_keys($legs))),
            ],
            'wireguard_public_key' => trim((string) ($pointConfig['wireguard_public_key'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $forward
     * @param array<string, mixed> $node
     * @param array<string, mixed> $endpoint
     * @return array<string, mixed>|null
     */
    private function buildMptcpSlaveUnderlayPayload(array $forward, array $node, array $endpoint): ?array
    {
        $nodeConfig = $this->decodeJsonAssoc($node['agent_config_json'] ?? null);
        $client = (int) ($forward['line_client_id'] ?? 0) > 0
            ? $this->db->fetchOne('SELECT agent_config_json FROM clients WHERE id = :id LIMIT 1', ['id' => (int) $forward['line_client_id']])
            : null;
        $clientConfig = $this->decodeJsonAssoc($client['agent_config_json'] ?? null);
        $point = (int) ($forward['bond_sidecar_point_id'] ?? 0) > 0
            ? $this->db->fetchOne('SELECT agent_config_json, sidecar_host, listen_port, bond_listen_port, mptcp_listen_port, token_secret FROM sidecar_points WHERE id = :id LIMIT 1', ['id' => (int) $forward['bond_sidecar_point_id']])
            : null;
        $sidecarConfig = $this->decodeJsonAssoc($point['agent_config_json'] ?? null);
        // Slave pre-connect relay presents the session token to the sidecar on the
        // client's behalf; it MUST be a valid signed token (else the sidecar rejects
        // the handshake with "invalid token format: no dot separator"). Generate it
        // here using the sidecar point's secret (same secret the sidecar validates with).
        $uplinkTokenSecret = $this->bondTokenSecret(array_merge($forward, [
            'bond_sidecar_point_token_secret' => trim((string) (is_array($point) ? ($point['token_secret'] ?? '') : '')),
        ]));
        ['issued_at' => $uplinkIssuedAt, 'expires_at' => $uplinkExpiresAt] = $this->bondTokenTimestamps();
        $uplinkExistingTcpToken = trim((string) ($forward['tcp_session_token'] ?? $forward['session_token'] ?? ''));
        $uplinkExistingUdpToken = trim((string) ($forward['udp_session_token'] ?? $forward['session_token'] ?? ''));
        $uplinkTcpSessionToken = $uplinkExistingTcpToken !== ''
            ? $uplinkExistingTcpToken
            : $this->buildBondSessionToken($forward, $uplinkTokenSecret, $uplinkIssuedAt, $uplinkExpiresAt, 'tcp');
        $uplinkUdpSessionToken = $uplinkExistingUdpToken !== ''
            ? $uplinkExistingUdpToken
            : $this->buildBondSessionToken($forward, $uplinkTokenSecret, $uplinkIssuedAt, $uplinkExpiresAt, 'udp');

        $clientPublicKey = trim((string) ($clientConfig['wireguard_public_key'] ?? ''));
        $sidecarPublicKey = trim((string) ($sidecarConfig['wireguard_public_key'] ?? ''));
        if ($clientPublicKey === '' || $sidecarPublicKey === '') {
            return null;
        }

        $addresses = $this->stableMptcpLegAddressing((int) ($forward['id'] ?? 0), (int) ($node['id'] ?? 0));
        $publicPort = (int) ($endpoint['public_port'] ?? 0);
        $outerTransport = strtolower(trim((string) ($nodeConfig['wireguard_outer_transport'] ?? 'udp')));
        if ($outerTransport === '') {
            $outerTransport = 'udp';
        }

        $forwardId = (int) ($forward['id'] ?? 0);
        $nodeId = (int) ($node['id'] ?? 0);
        $leigodDirect = strtolower(trim((string) ($forward['backend_mode'] ?? ''))) === 'leigod_mode';
        $leigodPreconnectReuse = $leigodDirect && $this->config->getBool('LEIGOD_SLAVE_PRECONNECT_REUSE', false);
        $nodeBasePort = isset($nodeConfig['wireguard_listen_port']) && (int) $nodeConfig['wireguard_listen_port'] > 0
            ? (int) $nodeConfig['wireguard_listen_port']
            : $publicPort;
        $nodeBaseFallback = isset($nodeConfig['wireguard_fallback_tcp_port']) && (int) $nodeConfig['wireguard_fallback_tcp_port'] > 0
            ? (int) $nodeConfig['wireguard_fallback_tcp_port']
            : ($nodeBasePort > 0 ? $nodeBasePort + 10000 : 0);
        $slaveListenPort = $this->stableMptcpSlavePort($nodeBasePort, $forwardId);
        $slaveFallbackTcpPort = $this->stableMptcpSlavePort($nodeBaseFallback, $forwardId);
        $leigodPreconnectPort = $leigodPreconnectReuse
            ? $this->stableLeigodPreconnectRelayPort($forwardId, $nodeId, [$slaveListenPort, $slaveFallbackTcpPort])
            : 0;

        $sidecarListenPort = $this->sidecarWireguardListenPort(is_array($point) ? $point : [], $sidecarConfig);
        $sidecarPortRangeEnd = $this->sidecarWireguardPortRangeEnd($sidecarListenPort, $sidecarConfig);
        $sidecarLegPort = $this->stableSidecarLegPort($sidecarListenPort, $sidecarPortRangeEnd, $forwardId, $nodeId);
        $staticSidecarUplink = $this->staticSidecarPrimaryUplinkForForwardNode($forward, $node, 'mptcp');
        $usesStaticSidecarUplink = is_array($staticSidecarUplink) && trim((string) ($staticSidecarUplink['peer_address'] ?? '')) !== '';
        if ($this->forwardRequiresSharedStaticSidecarUplink($forward) && (
            !$usesStaticSidecarUplink
            || (string) ($staticSidecarUplink['wireguard_peer_mode'] ?? '') !== 'shared'
        )) {
            throw new ApiException('shared_sidecar_uplink_unavailable', 409, [
                'forward_id' => $forwardId,
                'node_id' => $nodeId,
                'bond_sidecar_point_id' => (int) ($forward['bond_sidecar_point_id'] ?? 0),
            ]);
        }
        $clientCfRelayEnabled = $this->forwardUsesCfMode($forward) && $this->nodeSupportsCfwgRelay($nodeConfig);
        if ($clientCfRelayEnabled) {
            $outerTransport = 'wstunnel_udp';
        }
        $effectiveSidecarAddress = $usesStaticSidecarUplink
            ? trim((string) ($staticSidecarUplink['peer_address'] ?? ''))
            : $addresses['sidecar_address'];
        $effectiveSidecarEndpointHost = $usesStaticSidecarUplink
            ? ''
            : trim((string) ($point['sidecar_host'] ?? ''));
        $effectiveSidecarEndpointPort = $usesStaticSidecarUplink
            ? 0
            : ($sidecarLegPort > 0 ? $sidecarLegPort : $sidecarListenPort);

        return [
            'enabled' => true,
            'interface_name' => 'lwf' . $forwardId . 'n' . $nodeId,
            'listen_port' => $slaveListenPort,
            'fallback_tcp_port' => $slaveFallbackTcpPort,
            'local_uot_udp_port' => $this->stableLocalUotUdpPort($forwardId, $nodeId),
            'outer_transport' => $outerTransport,
            'sidecar_endpoint_host' => $effectiveSidecarEndpointHost,
            'sidecar_endpoint_port' => $effectiveSidecarEndpointPort,
            'peer_keepalive_seconds' => max(0, (int) ($this->config->getInt('MPTCP_PEER_KEEPALIVE_SECONDS', 25) ?? 25)),
            'client_public_key' => $clientPublicKey,
            'sidecar_public_key' => $sidecarPublicKey,
            'client_address' => $addresses['client_address'],
            'slave_address' => $addresses['slave_address'],
            'sidecar_address' => $effectiveSidecarAddress,
            'subnet' => $addresses['subnet'],
            'uses_static_sidecar_uplink' => $usesStaticSidecarUplink,
            'sidecar_static_uplink' => $usesStaticSidecarUplink ? $staticSidecarUplink : [],
            'client_cf_relay_enabled' => $clientCfRelayEnabled,
            'client_cf_relay_listen_port' => $clientCfRelayEnabled ? $this->stableMptcpSlavePort($nodeBasePort, $forwardId) : 0,
            'client_cf_relay_path_prefix' => $clientCfRelayEnabled ? $this->mptcpSlaveRelayPathPrefix($forwardId, $nodeId) : '',
            'client_cf_relay_target_host' => $clientCfRelayEnabled ? '127.0.0.1' : '',
            'client_cf_relay_target_port' => $clientCfRelayEnabled ? $this->stableMptcpSlavePort($nodeBasePort, $forwardId) : 0,
            // Slave pre-connect relay: slave opens MPTCP to sidecar on behalf of client.
            // Client only needs plain TCP to the slave's relay port.
            'slave_preconnect' => $leigodPreconnectReuse ? true : $this->config->getBool('MPTCP_SLAVE_PRECONNECT', false) && !$leigodDirect,
            'preconnect_relay_port' => $leigodPreconnectReuse ? $leigodPreconnectPort : (
                $leigodDirect ? 0 : $this->stablePreconnectRelayPort($forwardId, $nodeId)
            ),
            'sidecar_mptcp_port' => (int) ($point['mptcp_listen_port'] ?? $point['bond_listen_port'] ?? $point['listen_port'] ?? 0),
            'tcp_session_token' => $uplinkTcpSessionToken,
            'udp_session_token' => $uplinkUdpSessionToken,
            'forward_id' => $forwardId,
        ] + ($leigodPreconnectReuse ? [
            'preconnect_listen_host' => '0.0.0.0',
            'preconnect_advertise_host' => $this->resolveNodePublicHost($node),
            'preconnect_health_port' => $leigodPreconnectPort + 1,
        ] : []);
    }

    /**
     * @param array<int, array<string, mixed>> $endpoints
     * @return array{join_timeout_ms:int,join_timeout_floor_ms:int,join_timeout_ceiling_ms:int,join_timeout_per_leg_ms:int}
     */
    private function adaptiveBondJoinTimeoutConfig(array $endpoints): array
    {
        $configuredJoinTimeout = max(40, (int) ($this->config->getInt('BOND_JOIN_TIMEOUT_MS', 400) ?? 400));
        $adaptiveEnabled = $this->config->getBool('BOND_ADAPTIVE_JOIN_TIMEOUT', true);
        $floor = max(20, (int) ($this->config->getInt('BOND_JOIN_TIMEOUT_FLOOR_MS', 35) ?? 35));
        $perLeg = max(0, (int) ($this->config->getInt('BOND_JOIN_TIMEOUT_PER_LEG_MS', 20) ?? 20));
        $ceilingDefault = min($configuredJoinTimeout, max($floor, 220));
        $ceiling = max($floor, (int) ($this->config->getInt('BOND_JOIN_TIMEOUT_CEILING_MS', $ceilingDefault) ?? $ceilingDefault));

        if (!$adaptiveEnabled) {
            return [
                'join_timeout_ms' => max($floor, min($ceiling, $configuredJoinTimeout)),
                'join_timeout_floor_ms' => $floor,
                'join_timeout_ceiling_ms' => $ceiling,
                'join_timeout_per_leg_ms' => $perLeg,
            ];
        }

        $wssLegs = max(1, count($endpoints));
        $quicLegs = 0;
        foreach ($endpoints as $endpoint) {
            if ($this->nodeSupportsBondQuic(
                $endpoint['node_agent_config_json'] ?? null,
                (string) ($endpoint['node_agent_version'] ?? '')
            )) {
                $quicLegs++;
            }
        }
        if ($quicLegs === 0) {
            $wssOnlyCeiling = max($ceiling, $configuredJoinTimeout);
            return [
                'join_timeout_ms' => max($floor, min($wssOnlyCeiling, $configuredJoinTimeout)),
                'join_timeout_floor_ms' => $floor,
                'join_timeout_ceiling_ms' => $wssOnlyCeiling,
                'join_timeout_per_leg_ms' => $perLeg,
            ];
        }
        $totalLegs = max(1, $wssLegs + $quicLegs);
        $adaptive = $floor + max(0, $totalLegs - 1) * $perLeg;

        return [
            'join_timeout_ms' => max($floor, min($ceiling, $adaptive)),
            'join_timeout_floor_ms' => $floor,
            'join_timeout_ceiling_ms' => $ceiling,
            'join_timeout_per_leg_ms' => $perLeg,
        ];
    }

    /**
     * @param array<string, mixed> $forward
     * @return array{host: string, port: int}
     */
    private function resolveBondDirectTarget(array $forward): array
    {
        return $this->resolveSidecarDirectTarget($forward, 'bond');
    }

    /**
     * @param array<string, mixed> $forward
     * @return array{host: string, port: int}
     */
    private function resolveSidecarDirectTarget(array $forward, string $clientMode): array
    {
        $normalizedMode = $this->normalizeClientMode($clientMode);
        $host = trim((string) ($forward['bond_sidecar_host'] ?? ''));
        $pointConfig = $this->decodeJsonAssoc($forward['bond_sidecar_point_agent_config_json'] ?? null);
        $port = $normalizedMode === 'mptcp'
            ? (int) ($forward['mptcp_sidecar_port'] ?? 0)
            : (int) ($forward['bond_sidecar_port'] ?? 0);

        if (($host === '' || $port <= 0) && (int) ($forward['bond_sidecar_point_id'] ?? 0) > 0) {
            $point = $this->db->fetchOne(
                'SELECT sidecar_host, listen_port, bond_listen_port, mptcp_listen_port, agent_config_json FROM sidecar_points WHERE id = :id LIMIT 1',
                ['id' => (int) $forward['bond_sidecar_point_id']]
            );
            if (is_array($point)) {
                $host = trim((string) ($point['sidecar_host'] ?? $host));
                $pointPort = $normalizedMode === 'mptcp'
                    ? $this->sidecarMptcpListenPort($point, $this->decodeJsonAssoc($point['agent_config_json'] ?? null))
                    : $this->sidecarBondListenPort($point);
                if ($pointPort > 0) {
                    $port = $pointPort;
                }
            }
        }

        if ($host !== '' && $port > 0) {
            return [
                'host' => $host,
                'port' => $port,
            ];
        }

        if ($normalizedMode === 'mptcp') {
            $fallbackHost = trim((string) ($forward['bond_remote_host'] ?? ''));
            $fallbackPort = (int) ($forward['bond_remote_port'] ?? 0);
            if ($fallbackHost !== '' && $fallbackPort > 0) {
                return [
                    'host' => $fallbackHost,
                    'port' => $fallbackPort,
                ];
            }
        }

        return $this->resolveBondRemoteTarget($forward);
    }

    /**
     * @param array<string, mixed> $forward
     */
    private function buildBondSessionToken(array $forward, string $tokenSecret, int $issuedAt, int $expiresAt, string $targetProtocol): string
    {
        $targetHost = (string) ($forward['target_host'] ?? '');
        $targetPort = (int) ($forward['target_port'] ?? 0);
        if ((string) ($forward['backend_mode'] ?? '') === 'cf_mode' && $targetPort > 0) {
            $sidecarHosts = array_filter(array_map(static fn($value): string => trim((string) $value), [
                $forward['bond_sidecar_host'] ?? '',
                $forward['bond_remote_host'] ?? '',
            ]));
            if ($targetHost !== '' && in_array($targetHost, $sidecarHosts, true)) {
                $targetHost = '127.0.0.1';
            }
        }
        $payload = json_encode([
            'target_host' => $targetHost,
            'target_port' => $targetPort,
            'target_protocol' => $targetProtocol,
            'service_id' => isset($forward['service_id']) ? (int) $forward['service_id'] : null,
            'forward_id' => isset($forward['id']) ? (int) $forward['id'] : (isset($forward['forward_id']) ? (int) $forward['forward_id'] : null),
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload) || $payload === '') {
            throw new ApiException('bond_token_generation_failed', 500);
        }

        $signature = hash_hmac('sha256', $payload, $tokenSecret, true);
        return $this->base64UrlEncode($payload) . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * Keep bond session tokens stable across normal client-agent polling so the
     * rendered startup script does not change every sync and force needless restarts.
     *
     * @return array{issued_at:int, expires_at:int}
     */
    private function bondTokenTimestamps(): array
    {
        $ttl = max(300, (int) ($this->config->getInt('BOND_TOKEN_TTL_SECONDS', 7 * 24 * 3600) ?? (7 * 24 * 3600)));
        $defaultRotation = max(60, min(86400, intdiv($ttl, 2)));
        $configuredRotation = (int) ($this->config->getInt('BOND_TOKEN_ROTATION_SECONDS', $defaultRotation) ?? $defaultRotation);
        $rotation = max(60, min($ttl - 60, $configuredRotation > 0 ? $configuredRotation : $defaultRotation));

        $now = time();
        $issuedAt = intdiv($now, $rotation) * $rotation;
        return [
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + $ttl,
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed>|null $client
     */
    private function pickClientPort(?array $client, ?int $excludeForwardId, int $preferredPort = 0, string $strategy = 'client_range_then_target'): int
    {
        if (!is_array($client)) {
            return $preferredPort;
        }

        $start = (int) ($client['port_range_start'] ?? 0);
        $end = (int) ($client['port_range_end'] ?? 0);
        if ($start <= 0 || $end <= 0 || $end < $start) {
            return $preferredPort;
        }

        $sql = 'SELECT listen_port, public_port FROM forwards WHERE line_client_id = :client_id';
        $sql .= ' AND enabled = 1';
        $params = ['client_id' => (int) $client['id']];
        if (($excludeForwardId ?? 0) > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeForwardId;
        }
        $rows = $this->db->fetchAll($sql, $params);
        $used = [];
        foreach ($rows as $row) {
            $listen = (int) ($row['listen_port'] ?? 0);
            $public = (int) ($row['public_port'] ?? 0);
            if ($listen > 0) {
                $used[$listen] = true;
            }
            if ($public > 0) {
                $used[$public] = true;
            }
        }

        $strategy = strtolower(trim($strategy));
        if ($strategy === 'target_port_then_client_range') {
            if ($preferredPort >= $start && $preferredPort <= $end && !isset($used[$preferredPort])) {
                return $preferredPort;
            }
        }

        for ($port = $start; $port <= $end; $port++) {
            if (!isset($used[$port])) {
                return $port;
            }
        }

        if ($strategy !== 'target_port_then_client_range' && $preferredPort >= $start && $preferredPort <= $end && !isset($used[$preferredPort])) {
            return $preferredPort;
        }

        return $preferredPort > 0 ? $preferredPort : $start;
    }

    /**
     * @param array<string, mixed>|null $client
     */
    private function pickPublicPort(?array $client, ?int $excludeForwardId, int $preferredPort = 0, int $fallbackPort = 0, string $strategy = 'shared_preferred_port'): int
    {
        $strategy = strtolower(trim($strategy));
        if ($strategy === 'shared_preferred_port') {
            if ($preferredPort > 0) {
                return $preferredPort;
            }
            if ($fallbackPort > 0) {
                return $fallbackPort;
            }
            return $this->config->getInt('DEFAULT_EDGE_PUBLIC_PORT', 443);
        }

        $legacyPreferredPort = $preferredPort > 0 ? $preferredPort : $fallbackPort;
        return $this->pickClientPort($client, $excludeForwardId, $legacyPreferredPort, $strategy);
    }

    private function generatePathPrefix(string $strategy): string
    {
        return match (strtolower(trim($strategy))) {
            'date-random' => gmdate('ymd') . '-' . bin2hex(random_bytes(4)),
            default => bin2hex(random_bytes(8)),
        };
    }

    private function nodeGroupDescriptionColumnAvailable(): bool
    {
        if ($this->nodeGroupDescriptionColumnAvailable !== null) {
            return $this->nodeGroupDescriptionColumnAvailable;
        }

        $this->nodeGroupDescriptionColumnAvailable = $this->columnExists('node_groups', 'description');
        return $this->nodeGroupDescriptionColumnAvailable;
    }

    private function nodeGroupIngressColumnsAvailable(): bool
    {
        if ($this->nodeGroupIngressColumnsAvailable !== null) {
            return $this->nodeGroupIngressColumnsAvailable;
        }

        $this->nodeGroupIngressColumnsAvailable =
            $this->columnExists('node_groups', 'ingress_cdn_host')
            && $this->columnExists('node_groups', 'ingress_sni_host')
            && $this->columnExists('node_groups', 'ingress_path_strategy')
            && $this->columnExists('node_groups', 'ingress_port_strategy')
            && $this->columnExists('node_groups', 'ingress_preferred_public_port');

        return $this->nodeGroupIngressColumnsAvailable;
    }

    private function nodeGroupIngressSelectSql(): string
    {
        if ($this->nodeGroupIngressColumnsAvailable()) {
            return 'g.ingress_cdn_host, g.ingress_sni_host, g.ingress_path_strategy, g.ingress_port_strategy, g.ingress_preferred_public_port';
        }

        return "NULL AS ingress_cdn_host, NULL AS ingress_sni_host, 'random' AS ingress_path_strategy, 'shared_preferred_port' AS ingress_port_strategy, NULL AS ingress_preferred_public_port";
    }

    private function columnExists(string $table, string $column): bool
    {
        $driver = (string) $this->config->get('DB_DRIVER', 'mysql');
        if ($driver === 'sqlite') {
            $rows = $this->db->fetchAll('PRAGMA table_info(' . $table . ')');
            foreach ($rows as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }

        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name',
            [
                'table_name' => $table,
                'column_name' => $column,
            ]
        );

        return (int) ($row['total'] ?? 0) > 0;
    }

    /**
     * @return array<string, bool>
     */
    public function defaultFeatureFlags(): array
    {
        return [
            'allow_manage_forwards' => true,
            'allow_delete_forwards' => true,
            'allow_view_client_config' => true,
            'allow_rotate_client_token' => true,
            'allow_manage_clients' => false,
            'allow_delete_clients' => false,
            'allow_select_multiple_line_groups' => false,
            'allow_use_all_line_groups' => false,
            'allow_select_specific_line_client' => true,
            'allow_forward_chain' => false,
            'allow_custom_slaves' => false,
            'allow_inbound_proxy' => false,
            'allow_latency_test' => false,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function decodeFeatureFlags(string $json): array
    {
        $defaults = $this->defaultFeatureFlags();
        if ($json === '') {
            return $defaults;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        foreach ($defaults as $key => $value) {
            if (array_key_exists($key, $decoded)) {
                $defaults[$key] = (bool) $decoded[$key];
            }
        }
        return $defaults;
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, bool>
     */
    public function effectiveFeatureFlagsForService(array $service): array
    {
        $flags = $this->defaultFeatureFlags();
        foreach (['plan_feature_flags_json', 'user_feature_flags_json'] as $field) {
            $decoded = json_decode((string) ($service[$field] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }

            foreach ($flags as $key => $value) {
                if (array_key_exists($key, $decoded)) {
                    $flags[$key] = (bool) $decoded[$key];
                }
            }
        }
        return $flags;
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantUserById(int $userId): array
    {
        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
        if ($user === null || (string) ($user['role'] ?? '') !== 'tenant') {
            throw new ApiException('tenant_not_found', 404);
        }
        if ((string) ($user['status'] ?? '') !== 'active') {
            throw new ApiException('tenant_not_active', 422, ['message' => 'This tenant account is not active right now.']);
        }
        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function activePlanById(int $planId): array
    {
        $plan = $this->db->fetchOne('SELECT * FROM service_plans WHERE id = :id LIMIT 1', ['id' => $planId]);
        if ($plan === null) {
            throw new ApiException('plan_not_found', 404);
        }
        if ((int) ($plan['is_active'] ?? 0) !== 1) {
            throw new ApiException('plan_not_active', 422, ['message' => 'This package is not available for purchase right now.']);
        }
        if ((int) ($plan['is_tenant_visible'] ?? 1) !== 1) {
            throw new ApiException('plan_not_public', 422, ['message' => 'This package is not available for tenant self-purchase right now.']);
        }
        return $plan;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function chargeWallet(int $userId, string $currency, float $amount, string $direction, array $context = []): void
    {
        $user = $this->tenantUserById($userId);
        $walletBalance = (float) ($user['wallet_balance'] ?? 0);
        $walletCurrency = strtoupper(trim((string) ($user['wallet_currency'] ?? '')));
        $targetCurrency = strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'CNY';

        if ($walletBalance > 0 && $walletCurrency !== '' && $walletCurrency !== $targetCurrency) {
            throw new ApiException('wallet_currency_mismatch', 422, [
                'message' => 'Your wallet balance is in ' . $walletCurrency . ', but this action uses ' . $targetCurrency . '. Please reconcile the balance currency first.',
            ]);
        }
        if ($walletBalance < $amount) {
            throw new ApiException('wallet_balance_insufficient', 422, ['message' => 'Your wallet balance is not enough for this action.']);
        }

        $after = $walletBalance - $amount;
        $now = $this->now();
        $this->db->execute(
            'UPDATE users
             SET wallet_balance = :wallet_balance,
                 wallet_currency = :wallet_currency,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $userId,
                'wallet_balance' => $this->normalizeMoney($after),
                'wallet_currency' => $targetCurrency,
                'updated_at' => $now,
            ]
        );

        $this->db->insert('wallet_transactions', [
            'user_id' => $userId,
            'direction' => $direction,
            'amount' => $this->normalizeMoney($amount),
            'currency' => $targetCurrency,
            'panel_balance_before' => $this->normalizeMoney($walletBalance),
            'panel_balance_after' => $this->normalizeMoney($after),
            'whmcs_balance_before' => null,
            'whmcs_balance_after' => null,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
        ]);
    }

    private function generateSyntheticWhmcsServiceId(): int
    {
        do {
            $candidate = (int) (time() . random_int(100, 999));
            $existing = $this->db->fetchOne(
                'SELECT id FROM services WHERE whmcs_service_id = :whmcs_service_id LIMIT 1',
                ['whmcs_service_id' => $candidate]
            );
        } while ($existing !== null);

        return $candidate;
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $billingSnapshot
     * @return array<string, mixed>
     */
    private function buildFlexibleBillingPreview(array $plan, int $bandwidthLimitMbps, int $trafficLimitGb, int $activeClientCount, array $billingSnapshot): array
    {
        return [
            'billing_mode' => $billingSnapshot['billing_mode'],
            'billing_currency' => $billingSnapshot['billing_currency'],
            'monthly_base_fee' => $billingSnapshot['monthly_base_fee'],
            'monthly_included_traffic_gb' => $billingSnapshot['monthly_included_traffic_gb'],
            'traffic_price_per_gb' => $billingSnapshot['traffic_price_per_gb'],
            'billing_cycle_started_at' => $billingSnapshot['billing_cycle_started_at'],
            'billing_cycle_traffic_used_bytes' => 0,
            'traffic_limit_gb' => $trafficLimitGb,
            'bandwidth_limit_mbps' => $bandwidthLimitMbps,
            'plan_default_bandwidth_limit_mbps' => (int) ($plan['default_bandwidth_limit_mbps'] ?? $bandwidthLimitMbps),
            'plan_bandwidth_step_mbps' => (int) ($plan['bandwidth_step_mbps'] ?? 0),
            'plan_bandwidth_step_price' => $plan['bandwidth_step_price'] ?? 0,
            'plan_included_client_count' => (int) ($plan['included_client_count'] ?? 0),
            'plan_extra_client_price' => $plan['extra_client_price'] ?? 0,
            'plan_max_client_profiles' => (int) ($plan['max_client_profiles'] ?? 1),
            'active_client_count' => $activeClientCount,
            'created_at' => $this->now(),
        ];
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function normalizePurchasedBandwidth(array $plan, int $requestedBandwidthMbps): int
    {
        $baseBandwidth = max(1, (int) ($plan['default_bandwidth_limit_mbps'] ?? 100));
        $requested = $requestedBandwidthMbps > 0 ? $requestedBandwidthMbps : $baseBandwidth;
        if ($requested < $baseBandwidth) {
            $requested = $baseBandwidth;
        }

        $stepMbps = max(0, (int) ($plan['bandwidth_step_mbps'] ?? 0));
        $stepPrice = (float) ($plan['bandwidth_step_price'] ?? 0);
        if ($requested > $baseBandwidth && $stepMbps > 0 && $stepPrice > 0) {
            $diff = $requested - $baseBandwidth;
            if ($diff % $stepMbps !== 0) {
                throw new ApiException('invalid_bandwidth_step', 422, [
                    'message' => 'Bandwidth must increase in steps of ' . $stepMbps . ' Mbps for this package.',
                ]);
            }
        }

        return $requested;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * Read a persisted operational marker / state value from the app_settings k/v store.
     */
    private function appSettingGet(string $key): ?string
    {
        try {
            $row = $this->db->fetchOne(
                'SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1',
                ['setting_key' => $key]
            );
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($row) || !array_key_exists('setting_value', $row)) {
            return null;
        }
        $value = $row['setting_value'];
        return $value === null ? null : (string) $value;
    }

    /**
     * Upsert a persisted operational marker / state value into the app_settings k/v store.
     */
    private function appSettingSet(string $key, ?string $value): void
    {
        try {
            $existing = $this->db->fetchOne(
                'SELECT id FROM app_settings WHERE setting_key = :setting_key LIMIT 1',
                ['setting_key' => $key]
            );
            if (is_array($existing) && isset($existing['id'])) {
                $this->db->execute(
                    'UPDATE app_settings SET setting_value = :setting_value, updated_at = :updated_at WHERE setting_key = :setting_key',
                    [
                        'setting_value' => $value,
                        'updated_at' => $this->now(),
                        'setting_key' => $key,
                    ]
                );
                return;
            }
            $this->db->insert('app_settings', [
                'setting_key' => $key,
                'setting_value' => $value,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
        } catch (\Throwable $e) {
            error_log('appSettingSet failed for ' . $key . ': ' . $e->getMessage());
        }
    }
}
