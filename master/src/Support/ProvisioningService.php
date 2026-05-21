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
    // donation_qualify_gate_ratio
    public const DONATION_GATE_RATIO = 0.75;


    private ?bool $nodeGroupIngressColumnsAvailable = null;
    private ?bool $nodeGroupDescriptionColumnAvailable = null;
    public function __construct(
        private Config $config,
        private Database $db,
    ) {
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
            $forwards
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
                $this->db->execute(
                    'UPDATE services
                     SET user_id = :user_id,
                         plan_id = :plan_id,
                         name = :name,
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
    public function createForward(int $serviceId, array $payload, string $actor = 'user'): array
    {
        return $this->saveForward($serviceId, null, $payload, $actor);
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
        if ($backendMode === 'cf_mode') {
            return self::DEFAULT_MAX_LEGS_USED;
        }
        return 0;
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

        if ($name === '' || $tenantLabel === '' || $nodeGroupKey === '' || $sidecarPointId <= 0) {
            throw new ApiException('invalid_forward_combination_payload', 422, ['payload' => $payload]);
        }
        if (!in_array($backendMode, ['current_mode', 'cf_mode'], true)) {
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

        $normalized = [
            'name' => $name,
            'tenant_label' => $tenantLabel,
            'tenant_description' => $tenantDescription !== '' ? $tenantDescription : null,
            'tenant_badge' => $tenantBadge !== '' ? $tenantBadge : null,
            'node_group_key' => $nodeGroupKey,
            'backend_mode' => $backendMode,
            'sidecar_point_id' => $sidecarPointId,
            'cf_location_group_id' => $cfLocationGroupId > 0 ? $cfLocationGroupId : null,
            'client_mode' => $clientMode,
            'runtime_preference' => $runtimePreference,
            'allow_private_self_leg' => $allowPrivateSelfLeg ? 1 : 0,
            'self_leg_scope' => $selfLegScope,
            'self_leg_role' => $selfLegRole,
            'helper_group_cap' => $helperGroupCap,
            'helper_escalation_policy' => $helperEscalationPolicy,
            'self_leg_cf_transport' => $selfLegCfTransport,
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

        if ($id > 0) {
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
                $normalized + ['id' => $id]
            );
        } else {
            $id = $this->db->insert('forward_combinations', $normalized + [
                'created_at' => $this->now(),
            ]);
        }

        $this->db->execute('DELETE FROM forward_combination_helper_groups WHERE forward_combination_id = :forward_combination_id', [
            'forward_combination_id' => $id,
        ]);
        foreach ($helperNodeGroupKeys as $sortOrder => $helperNodeGroupKey) {
            $this->db->insert('forward_combination_helper_groups', [
                'forward_combination_id' => $id,
                'node_group_key' => $helperNodeGroupKey,
                'sort_order' => $sortOrder,
                'is_active' => 1,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
        }

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
             INNER JOIN sidecar_points sp ON sp.id = fc.sidecar_point_id
             LEFT JOIN cf_location_groups cg ON cg.id = fc.cf_location_group_id
             WHERE spfc.plan_id = :plan_id
             ORDER BY fc.sort_order ASC, fc.id ASC',
            ['plan_id' => $planId]
        );
        return $this->attachHelperNodeGroupKeysToForwardCombinations($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
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
             INNER JOIN sidecar_points sp ON sp.id = fc.sidecar_point_id
             LEFT JOIN cf_location_groups cg ON cg.id = fc.cf_location_group_id
             WHERE fc.is_active = 1
             ORDER BY fc.sort_order ASC, fc.id ASC'
        );
        $rows = $this->attachHelperNodeGroupKeysToForwardCombinations($rows);

        return array_values(array_filter($rows, static function (array $row) use ($allowedGroupKeys, $allowedSidecarIds): bool {
            $groupOk = $allowedGroupKeys === [] || in_array((string) ($row['node_group_key'] ?? ''), $allowedGroupKeys, true);
            $sidecarOk = $allowedSidecarIds === [] || in_array((int) ($row['sidecar_point_id'] ?? 0), $allowedSidecarIds, true);
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
             INNER JOIN sidecar_points sp ON sp.id = fc.sidecar_point_id
             LEFT JOIN cf_location_groups cg ON cg.id = fc.cf_location_group_id
             ORDER BY fc.sort_order ASC, fc.id ASC'
        );
        return $this->attachHelperNodeGroupKeysToForwardCombinations($rows);
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
             INNER JOIN sidecar_points sp ON sp.id = fc.sidecar_point_id
             LEFT JOIN cf_location_groups cg ON cg.id = fc.cf_location_group_id
             WHERE fc.id = :id LIMIT 1',
            ['id' => $combinationId]
        );

        if (!is_array($row)) {
            return null;
        }
        $rows = $this->attachHelperNodeGroupKeysToForwardCombinations([$row]);
        return $rows[0] ?? null;
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
        return $this->db->fetchAll(
            'SELECT n.*, g.group_key AS node_group_key
             FROM nodes n
             LEFT JOIN node_groups g ON g.id = n.group_id
             WHERE (n.status = :active OR n.status = :degraded)
               AND g.group_key IN (' . implode(', ', $placeholders) . ')
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
            $plan['allowed_forward_combinations'] = $this->allowedForwardCombinationsForPlan((int) $plan['id']);
            $plan['allowed_node_groups'] = $this->allowedNodeGroupsForPlan((int) $plan['id']);
            $plan['allowed_client_groups'] = $this->allowedClientGroupsForPlan((int) $plan['id']);
            $plan['allowed_sidecar_points'] = $this->allowedSidecarPointsForPlan((int) $plan['id']);
            $plan['forward_combination_count'] = count($plan['allowed_forward_combinations'] ?? []);
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
        $forwardCombinationIds = [];
        foreach ((array) ($payload['allowed_forward_combination_ids'] ?? []) as $combinationId) {
            $casted = (int) $combinationId;
            if ($casted > 0) {
                $forwardCombinationIds[] = $casted;
            }
        }
        $forwardCombinationIds = array_values(array_unique($forwardCombinationIds));

        if ($name === '' || $productCode === '') {
            throw new ApiException('invalid_plan_payload', 422, ['payload' => $payload]);
        }
        if ($forwardCombinationIds === []) {
            throw new ApiException(
                'invalid_plan_forward_combination_payload',
                422,
                ['message' => 'Select at least one active forward combination before saving the plan.']
            );
        }

        if ($billingCurrency === '') {
            $billingCurrency = 'CNY';
        }
        if ($billingMode === 'monthly_bundle' && $monthlyIncludedTraffic <= 0) {
            $monthlyIncludedTraffic = $traffic;
        }

        $forwardCombinationRows = $this->db->fetchAll(
            'SELECT id, is_active
             FROM forward_combinations
             WHERE id IN (' . implode(',', array_map('intval', $forwardCombinationIds)) . ')'
        );
        $validForwardCombinationIds = [];
        foreach ($forwardCombinationRows as $row) {
            if ((int) ($row['is_active'] ?? 0) === 1) {
                $validForwardCombinationIds[] = (int) ($row['id'] ?? 0);
            }
        }
        sort($validForwardCombinationIds);
        $expectedForwardCombinationIds = $forwardCombinationIds;
        sort($expectedForwardCombinationIds);
        if ($validForwardCombinationIds !== $expectedForwardCombinationIds) {
            throw new ApiException(
                'invalid_plan_forward_combination_payload',
                422,
                ['message' => 'Select at least one active forward combination before saving the plan.']
            );
        }

        return $this->db->transaction(function () use ($id, $name, $productCode, $description, $bandwidth, $traffic, $billingMode, $billingCurrency, $monthlyBaseFee, $monthlyIncludedTraffic, $trafficPricePerGb, $trafficResetPrice, $bandwidthStepMbps, $bandwidthStepPrice, $includedClientCount, $extraClientPrice, $isActive, $featureFlagsJson, $groupIds, $clientGroupIds, $sidecarPointIds, $forwardCombinationIds, $payload): array {
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

            $plan = $this->db->fetchOne('SELECT * FROM service_plans WHERE id = :id LIMIT 1', ['id' => $id]) ?? [];
            $plan['uses_forward_combinations'] = $this->planUsesForwardCombinations($id);
            $plan['allowed_forward_combinations'] = $this->allowedForwardCombinationsForPlan($id);
            $plan['allowed_node_groups'] = $this->allowedNodeGroupsForPlan($id);
            $plan['allowed_client_groups'] = $this->allowedClientGroupsForPlan($id);
            $plan['allowed_sidecar_points'] = $this->allowedSidecarPointsForPlan($id);
            $plan['forward_combination_count'] = count($plan['allowed_forward_combinations'] ?? []);
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
                 FROM service_plan_forward_combinations spfc
                 INNER JOIN forward_combinations fc ON fc.id = spfc.forward_combination_id
                 INNER JOIN node_groups g ON g.group_key = fc.node_group_key
                 LEFT JOIN nodes n ON n.group_id = g.id AND (n.status = :active OR n.status = :degraded)
                 WHERE spfc.plan_id = :plan_id
                 GROUP BY ' . $groupBy . '
                 ORDER BY g.name ASC, g.id ASC',
                [
                    'plan_id' => $planId,
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
             ORDER BY f.id ASC'
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

    private function truthyValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function autoCfwgPath(int $forwardId, string $legType, int $nodeId = 0): string
    {
        if ($forwardId <= 0) {
            return '';
        }
        $stableLegType = str_replace('-', '_', strtolower(trim($legType)));
        if ($stableLegType === '') {
            return '';
        }
        $stableNodeId = max(0, $nodeId);
        $digest = hash('sha256', 'leikwan-cfwg-path-v1:' . $stableLegType . ':forward:' . $forwardId . ':node:' . $stableNodeId);
        $h8 = substr($digest, 0, 8);
        $h12 = substr($digest, 0, 12);
        $major = (string) ((($forwardId * 7 + 2) % 20) + 1);
        $minor = (string) (($forwardId * 13 + 5) % 99);
        $episode = (string) ((($forwardId * 3 + 1) % 500) + 1);
        $templates = [
            'assets/v' . $major . '.' . $minor . '/chunk-' . $h8 . '.ts',
            'cdn/releases/' . $h8 . '/update-' . $major . '.' . $minor . '.bin',
            'media/stream-' . $h8 . '.m3u8',
            'files/' . $h8 . '/Windows-' . $major . '.' . $minor . '.iso',
            'static/js/' . $h8 . '.chunk.js',
            'api/v2/telemetry/' . $h12,
            'download/' . $h8 . '/package-' . $major . '.' . $minor . '.deb',
            'content/' . $h8 . '/episode-' . $episode . '.mp4',
        ];
        return $templates[$forwardId % count($templates)];
    }

    private function nodeSupportsCfwgRelay(mixed $nodeConfig, string $nodeAgentVersion): bool
    {
        $decoded = $this->decodeJsonAssoc($nodeConfig);
        if ($nodeAgentVersion !== '' && version_compare($nodeAgentVersion, '2026.05.21.4', '<')) {
            return false;
        }
        return $this->truthyValue($decoded['supports_cfwg_relay'] ?? false);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private function cfwgRelayEndpointForConfig(array $config): ?array
    {
        if (strtolower(trim((string) ($config['self_leg_cf_transport'] ?? ''))) !== 'cf_wg') {
            return null;
        }
        foreach ((array) ($config['endpoints'] ?? []) as $endpoint) {
            if (!is_array($endpoint)) {
                continue;
            }
            if (!$this->truthyValue($endpoint['supports_cfwg_relay'] ?? false)) {
                continue;
            }
            $host = trim((string) ($endpoint['host'] ?? $endpoint['public_host'] ?? ''));
            $port = (int) ($endpoint['port'] ?? $endpoint['public_port'] ?? 0);
            $nodeId = (int) ($endpoint['node_id'] ?? 0);
            if ($host === '' || $port <= 0 || $port < $this->mptcpSlavePortRangeStart() || $port > $this->mptcpSlavePortRangeEnd()) {
                continue;
            }
            return [
                'endpoint_id' => (int) ($endpoint['endpoint_id'] ?? $endpoint['id'] ?? 0),
                'node_id' => $nodeId,
                'host' => $host,
                'port' => $port,
                'sni_host' => trim((string) ($endpoint['cdn_host'] ?? $config['sni'] ?? $config['sni_host'] ?? $host)),
            ];
        }
        return null;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $relayEndpoint
     * @param string $cfWgPathPrefix
     * @return array<string, mixed>
     */
    private function buildCfwgSlaveRelayConfig(array $config, array $relayEndpoint, string $cfWgPathPrefix): array
    {
        $forwardId = (int) ($config['forward_id'] ?? $config['id'] ?? 0);
        $clientId = (int) ($config['line_client_id'] ?? 0);
        $nodeId = (int) ($relayEndpoint['node_id'] ?? 0);
        $addresses = $this->selfLegWireGuardAddressing($forwardId, $clientId, $nodeId);
        return [
            'enabled' => true,
            'path_algorithm' => 'cfwg-relay-v1',
            'slave_host' => (string) ($relayEndpoint['host'] ?? ''),
            'slave_port' => (int) ($relayEndpoint['port'] ?? 0),
            'slave_sni_host' => (string) ($relayEndpoint['sni_host'] ?? ''),
            'slave_path_prefix' => $this->autoCfwgPath($forwardId, 'slave_relay', $nodeId),
            'node_id' => $nodeId,
            'endpoint_id' => (int) ($relayEndpoint['endpoint_id'] ?? 0),
            'client_address' => $addresses['client_address'],
            'sidecar_address' => $addresses['sidecar_address'],
            'cf_target_host' => '127.0.0.1',
            'cf_target_port' => (int) ($config['bond_sidecar_wireguard_listen_port'] ?? 0),
            'cf_path_prefix' => $cfWgPathPrefix,
        ];
    }

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
            $relayEndpoint = $this->cfwgRelayEndpointForConfig($config);
            if ($relayEndpoint !== null) {
                $wgProfile['slave_relay'] = $this->buildCfwgSlaveRelayConfig($config, $relayEndpoint, $cfWgPathPrefix);
            }
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
    private function selfLegWireGuardAddressing(int $forwardId, int $clientId, int $salt = 0): array
    {
        $stableId = max(1, ($forwardId * 1024) + $clientId + (max(0, $salt) * 131072));
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
    public function updateForward(int $forwardId, array $payload, string $actor = 'user'): array
    {
        $forward = $this->db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', ['id' => $forwardId]);
        if ($forward === null) {
            throw new ApiException('forward_not_found', 404);
        }

        $this->revokeForwardEndpoints($forwardId, 'update');
        return $this->saveForward((int) $forward['service_id'], $forwardId, $payload, $actor);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateForwardNonStructural(int $forwardId, array $payload, string $actor = 'user'): array
    {
        $forward = $this->db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', ['id' => $forwardId]);
        if ($forward === null) {
            throw new ApiException('forward_not_found', 404);
        }

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

        $this->db->execute(
            'UPDATE forwards
             SET listen_port = :listen_port,
                 target_host = :target_host,
                 target_port = :target_port,
                 transport_mode = :transport_mode,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $forwardId,
                'listen_port' => $listenPort,
                'target_host' => $targetHost,
                'target_port' => $targetPort,
                'transport_mode' => $transportMode,
                'updated_at' => $this->now(),
            ]
        );

        $this->audit('forward.updated', [
            'forward_id' => $forwardId,
            'service_id' => (int) ($forward['service_id'] ?? 0),
            'actor' => $actor,
            'scope' => 'non_structural',
        ]);

        $this->revokeForwardEndpoints($forwardId, 'update');
        $this->reconcileForwardEndpoints($forwardId);

        return $this->buildClientConfig($forwardId);
    }

    public function deleteForward(int $forwardId, string $actor = 'user'): void
    {
        $forward = $this->db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', ['id' => $forwardId]);
        if ($forward === null) {
            throw new ApiException('forward_not_found', 404);
        }

        $this->revokeForwardEndpoints($forwardId, 'delete');
        $this->db->execute('UPDATE forwards SET enabled = 0, updated_at = :updated_at WHERE id = :id', [
            'id' => $forwardId,
            'updated_at' => $this->now(),
        ]);
        $this->audit('forward.deleted', ['forward_id' => $forwardId, 'actor' => $actor]);
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
             WHERE f.id = :id LIMIT 1',
            ['id' => $forwardId]
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
        $publishesBondRuntime = $clientMode === 'bond' || $runtimePreference === 'mptcp_preferred_bond_fallback';
        $pointConfig = $this->decodeJsonAssoc($forward['bond_sidecar_point_agent_config_json'] ?? null);
        $pointPortSource = [
            'listen_port' => (int) ($forward['bond_sidecar_legacy_port'] ?? 0),
            'bond_listen_port' => (int) ($forward['bond_sidecar_port'] ?? 0),
            'mptcp_listen_port' => (int) ($forward['mptcp_sidecar_port'] ?? 0),
        ];
        $effectiveBondSidecarPort = $this->sidecarBondListenPort($pointPortSource);
        $effectiveMptcpSidecarPort = $this->sidecarMptcpListenPort($pointPortSource, $pointConfig);
        $effectiveSidecarTarget = in_array($clientMode, ['bond', 'mptcp'], true)
            ? $this->resolveSidecarDirectTarget(
                array_merge($forward, [
                    'bond_sidecar_port' => $effectiveBondSidecarPort,
                    'mptcp_sidecar_port' => $effectiveMptcpSidecarPort,
                ]),
                $clientMode
            )
            : [
                'host' => (string) ($forward['bond_remote_host'] ?? ''),
                'port' => isset($forward['bond_remote_port']) && (int) $forward['bond_remote_port'] > 0 ? (int) $forward['bond_remote_port'] : 0,
            ];
        $ingressGroup = $this->nodeGroupByKey((string) ($forward['node_group_key'] ?? ''));
        $resolvedSniHost = $this->resolveIngressSniHost((string) ($forward['sni_host'] ?? ''), $ingressGroup);
        $resolvedCdnHost = $this->resolveIngressCdnHost((string) ($forward['cdn_host'] ?? ''), $resolvedSniHost, $ingressGroup);
        $bondConfig = $publishesBondRuntime
            ? $this->buildBondClientConfig(
                array_merge($forward, [
                    'bond_ingress_mode' => $bondIngressMode,
                ]),
                $endpoints
            )
            : null;
        $mptcpConfig = $clientMode === 'mptcp' ? $this->buildMptcpClientConfig($forward, $endpoints) : null;
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
            'bond_sidecar_point_id' => $forward['bond_sidecar_point_id'] !== null ? (int) $forward['bond_sidecar_point_id'] : null,
            'bond_sidecar_point_name' => (string) ($forward['bond_sidecar_point_name'] ?? ''),
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
            'backend_mode' => (string) ($forward['backend_mode'] ?? 'current_mode'),
            'cf_location_group_id' => $forward['cf_location_group_id'] !== null ? (int) $forward['cf_location_group_id'] : null,
            'bond_sidecar_host' => (string) ($forward['bond_sidecar_host'] ?? ''),
            'bond_sidecar_port' => $effectiveBondSidecarPort > 0 ? $effectiveBondSidecarPort : null,
            'mptcp_sidecar_port' => $effectiveMptcpSidecarPort > 0 ? $effectiveMptcpSidecarPort : null,
            'bond_sidecar_cf_listen_port' => (int) ($forward['bond_sidecar_cf_listen_port'] ?? 0) > 0 ? (int) ($forward['bond_sidecar_cf_listen_port'] ?? 0) : null,
            'bond_sidecar_cf_sni_host' => (string) ($pointConfig['cf_sni_host'] ?? $pointConfig['cf_sni'] ?? ''),
            'bond_sidecar_wireguard_listen_port' => (int) ($pointConfig['wireguard_listen_port'] ?? 0),
            'bond_sidecar_approved_upstream_realizations' => $this->approvedCfUpstreamRealizations($pointConfig),
            'bond_sidecar_point_agent_config_json' => (string) ($forward['bond_sidecar_point_agent_config_json'] ?? ''),
            'line_client_agent_config_json' => (string) ($forward['line_client_agent_config_json'] ?? ''),
            'bond_remote_host' => (string) ($effectiveSidecarTarget['host'] ?? $forward['bond_remote_host'] ?? ''),
            'bond_remote_port' => (int) ($effectiveSidecarTarget['port'] ?? $forward['bond_remote_port'] ?? 0) > 0 ? (int) ($effectiveSidecarTarget['port'] ?? $forward['bond_remote_port'] ?? 0) : null,
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
            'transport' => (string) $forward['transport_mode'],
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
            'endpoints' => array_map(function (array $endpoint) use ($resolvedSniHost, $resolvedCdnHost, $ingressGroup, $clientMode, $bondIngressMode, $publishesBondRuntime, $forward): array {
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
                    'node_id' => (int) ($endpoint['node_id'] ?? 0),
                    'node_agent_version' => (string) ($endpoint['node_agent_version'] ?? ''),
                    'supports_quic_leg' => $this->nodeSupportsBondQuic($nodeConfig, (string) ($endpoint['node_agent_version'] ?? '')),
                    'supports_cfwg_relay' => $this->nodeSupportsCfwgRelay($nodeConfig, (string) ($endpoint['node_agent_version'] ?? '')),
                    'origin_group_key' => (string) ($endpoint['origin_group_key'] ?? $endpoint['node_group_key'] ?? $endpoint['group_key'] ?? ''),
                    'last_chain_latency_ms' => $this->nullableFloat($endpoint['last_chain_latency_ms'] ?? $endpoint['best_latency_ms'] ?? null),
                    'delivered_ewma' => $this->nullableFloat($endpoint['delivered_ewma'] ?? null),
                ];
                if ($publishesBondRuntime && $bondIngressMode === 'plain_tcp_mux') {
                    $item['ingress_protocol'] = 'plain_tcp_mux';
                    $item['ingress_auth_token'] = $this->bondIngressAuthToken($forward, (int) $endpoint['id'], (string) ($forward['path_prefix'] ?? ''));
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
                 ORDER BY e.priority ASC, e.id ASC',
                ['forward_id' => $forward['id']]
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
    private function saveForward(int $serviceId, ?int $forwardId, array $payload, string $actor): array
    {
        unset($payload['endpoint_count']);

        $forwardCombinationId = (int) ($payload['forward_combination_id'] ?? 0);
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
        $lineClientId = (int) ($payload['line_client_id'] ?? 0);
        $lineClientGroupId = (int) ($payload['line_client_group_id'] ?? 0);
        $lineGroupMode = trim((string) ($payload['line_group_mode'] ?? 'selected'));
        $endpointMode = trim((string) ($payload['endpoint_mode'] ?? 'slave_group'));
        $forwardChainId = (int) ($payload['forward_chain_id'] ?? 0);
        $sniHost = trim((string) ($payload['sni_host'] ?? ''));
        $pathPrefix = trim((string) ($payload['path_prefix'] ?? ''));
        $nodeGroup = trim((string) ($payload['node_group'] ?? $this->config->get('DEFAULT_NODE_GROUP', 'default')));
        $specificNodeId = null;
        if (str_starts_with($nodeGroup, '_user_node_')) {
            $specificNodeId = (int) substr($nodeGroup, strlen('_user_node_'));
            $nodeGroup = '';
        }
        $publicPort = (int) ($payload['public_port'] ?? 0);
        $cdnHost = trim((string) ($payload['cdn_host'] ?? ''));
        $filterProfile = (string) ($payload['filter_profile'] ?? 'off');
        $customEndpointIdsJson = $this->normalizeJsonField($payload['custom_endpoint_ids_json'] ?? null);
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

        if ($forwardCombinationId > 0) {
            $combination = $this->visibleForwardCombinationForService($forwardCombinationId, $serviceId);
            $backendMode = (string) ($combination['backend_mode'] ?? 'current_mode');
            $clientMode = $this->normalizeClientMode((string) ($combination['client_mode'] ?? 'single'));
            if (!in_array($clientMode, ['single', 'bond', 'mptcp'], true)) {
                throw new ApiException('invalid_forward_combination_client_mode', 422, ['client_mode' => $clientMode]);
            }
            $nodeGroup = (string) ($combination['node_group_key'] ?? '');
            $bondSidecarPointId = (int) ($combination['sidecar_point_id'] ?? 0);
            $bondIngressMode = $this->normalizeBondIngressMode((string) ($combination['bond_ingress_mode'] ?? 'plain_tcp_mux'));
            $cfLocationGroupId = $backendMode === 'cf_mode' ? (int) ($combination['cf_location_group_id'] ?? 0) : 0;
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
        $endpointGroupKeys = [];

        if ($forwardChainId > 0) {
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

        if (in_array($clientMode, ['bond', 'mptcp'], true)) {
            if ($bondSidecarPointId > 0) {
                $sidecarPoint = $this->visibleSidecarPointForUser($bondSidecarPointId, (int) ($service['user_id'] ?? 0));
                if ($sidecarPoint === null || (int) ($sidecarPoint['is_active'] ?? 0) !== 1) {
                    throw new ApiException('bond_sidecar_point_not_allowed', 422, ['bond_sidecar_point_id' => $bondSidecarPointId]);
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
        }

        if ($allowedGroups !== [] && $endpointGroupKeys !== []) {
            $allowedGroupKeys = array_map(static fn(array $group): string => (string) $group['group_key'], $allowedGroups);
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

        if ($allowedGroups !== [] && $nodeGroup !== '' && !in_array($nodeGroup, array_map(static fn(array $group): string => (string) $group['group_key'], $allowedGroups), true)) {
                throw new ApiException('node_group_not_allowed', 422, [
                    'requested_node_group' => $nodeGroup,
                    'allowed_node_groups' => array_map(static fn(array $group): string => (string) $group['group_key'], $allowedGroups),
                ]);
        }

        $endpointCount = $specificNodeId !== null ? 1 : $this->panelManagedEndpointCount($clientMode, $endpointGroupKeys, $serviceId);

        if ($forwardId === null) {
            $forwardId = $this->db->insert('forwards', [
                'service_id' => $serviceId,
                'listen_port' => $listenPort,
                'target_host' => $targetHost,
                'target_port' => $targetPort,
                'bond_remote_host' => $bondRemoteHost !== '' ? $bondRemoteHost : null,
                'bond_remote_port' => $bondRemotePort > 0 ? $bondRemotePort : null,
                'bond_ingress_mode' => $bondIngressMode,
                'bond_sidecar_point_id' => $bondSidecarPointId > 0 ? $bondSidecarPointId : null,
                'forward_combination_id' => $forwardCombinationId > 0 ? $forwardCombinationId : null,
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
                    'updated_at' => $this->now(),
                ]
            );
            $this->audit('forward.updated', ['forward_id' => $forwardId, 'service_id' => $serviceId, 'actor' => $actor]);
        }

        $forward = $this->db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', ['id' => $forwardId]);

        // Specific user-node bypass: publish directly to the requested node
        if ($specificNodeId !== null) {
            $specificNode = $this->db->fetchOne('SELECT * FROM nodes WHERE id = :id LIMIT 1', ['id' => $specificNodeId]);
            if ($specificNode === null) {
                throw new ApiException('specific_node_not_found', 404, ['node_id' => $specificNodeId]);
            }
            $specificPublicHost = $this->resolveNodePublicHost($specificNode);
            if ($specificPublicHost === '') {
                throw new ApiException('specific_node_no_public_host', 409, ['node_id' => $specificNodeId]);
            }
            $specificNode['resolved_public_host'] = $specificPublicHost;
            $directSidecarTarget = in_array($clientMode, ['bond', 'mptcp'], true)
                ? $this->resolveSidecarDirectTarget($forward, $clientMode)
                : null;
            $publishTarget = $directSidecarTarget !== null
                ? ($directSidecarTarget['host'] . ':' . $directSidecarTarget['port'])
                : $forward['target_host'] . ':' . $forward['target_port'];
            $endpointId = $this->db->insert('service_endpoints', [
                'forward_id' => $forwardId,
                'node_id' => $specificNodeId,
                'public_host' => $specificPublicHost,
                'public_ip' => (string) ($specificNode['public_ip'] ?? ''),
                'public_port' => (int) $forward['public_port'],
                'cdn_host' => (string) ($forward['cdn_host'] ?? ''),
                'priority' => 10,
                'weight' => 100,
                'health_status' => 'pending',
                'last_error' => null,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
            $this->queueTask($specificNodeId, 'publish', $serviceId, $forwardId, $endpointId, [
                'listen_port' => (int) $forward['public_port'],
                'target' => $publishTarget,
                'path_prefix' => (string) $forward['path_prefix'],
                'sni_host' => (string) $forward['sni_host'],
                'cdn_host' => (string) ($forward['cdn_host'] ?? ''),
                'tls_cert_ref' => (string) $this->config->get('DEFAULT_TLS_CERT_REF', 'default-edge-cert'),
                'filter_profile' => (string) $forward['filter_profile'],
                'transport_mode' => (string) $forward['transport_mode'],
                'leg_relay_protocols' => $this->legRelayProtocolsForNode($clientMode, $specificNode),
                'mptcp_underlay' => $clientMode === 'mptcp' ? $this->buildMptcpSlaveUnderlayPayload($forward, $specificNode, [
                    'id' => $endpointId,
                    'public_port' => $forward['public_port'],
                ]) : null,
            ]);
            return $this->buildClientConfig($forwardId);
        }

        $desiredEndpointCount = max((int) $forward['endpoint_count'], count($endpointGroupKeys));
        $nodes = $this->pickNodesForGroups($endpointGroupKeys, $desiredEndpointCount, $serviceId);
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
        if ($nodes === []) {
            throw new ApiException('no_available_nodes', 409, [
                'requested_node_group' => $forward['node_group_key'],
                'allowed_node_groups' => array_map(static fn(array $group): string => (string) $group['group_key'], $allowedGroups),
                'available_node_groups' => array_map(static fn(array $group): string => (string) $group['group_key'], $this->availableNodeGroupsForService($serviceId)),
                'nodes_missing_public_host' => $skippedNodes,
            ]);
        }

        $directSidecarTarget = in_array($clientMode, ['bond', 'mptcp'], true)
            ? $this->resolveSidecarDirectTarget($forward, $clientMode)
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

            $this->queueTask((int) $node['id'], 'publish', $serviceId, $forwardId, $endpointId, [
                'listen_port' => (int) $forward['public_port'],
                'target' => $publishTarget,
                'path_prefix' => (string) $forward['path_prefix'],
                'sni_host' => (string) $forward['sni_host'],
                'cdn_host' => (string) $forward['cdn_host'],
                'tls_cert_ref' => (string) $this->config->get('DEFAULT_TLS_CERT_REF', 'default-edge-cert'),
                'filter_profile' => (string) $forward['filter_profile'],
                'transport_mode' => (string) $forward['transport_mode'],
                'leg_relay_protocols' => $this->legRelayProtocolsForNode($clientMode, $node),
                'mptcp_underlay' => $clientMode === 'mptcp' ? $this->buildMptcpSlaveUnderlayPayload($forward, $node, [
                    'id' => $endpointId,
                    'public_port' => $forward['public_port'],
                ]) : null,
            ]);
            $priority += 10;
        }

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
     * Temporary compatibility shim for newer sidecar agents that request
     * persistent slave-sidecar uplink management. Returning an empty uplink set
     * allows the heartbeat to succeed and lets the sidecar agent clean up any
     * stale persisted uplinks locally instead of crashing on the panel call.
     *
     * @return array{uplinks: array<int, array<string, mixed>>}
     */
    public function slaveSidecarUplinkManagementForPoint(int $pointId): array
    {
        if ($pointId <= 0) {
            return ['uplinks' => []];
        }

        return ['uplinks' => []];
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
        $desiredCount = $this->panelManagedEndpointCount($clientMode, $groupKeys, (int) ($forward['service_id'] ?? 0) ?: null);
        $combinationId = (int) ($forward['forward_combination_id'] ?? 0);
        if ($combinationId > 0) {
            $combination = $this->forwardCombinationById($combinationId) ?? [];
            $selectorCap = max(0, (int) ($combination['helper_group_cap'] ?? 0));
            $selectedSlaves = $this->pickFastestSlavesForCombination($combinationId, $selectorCap);
            $desiredNodes = array_values(array_map(
                static fn(array $candidate): array => is_array($candidate['row'] ?? null) ? $candidate['row'] : [],
                array_filter($selectedSlaves, static fn(array $candidate): bool => ($candidate['candidate_source'] ?? '') === 'rented_node')
            ));
        } else {
            $desiredNodes = $this->pickNodesForGroups($groupKeys, $desiredCount, (int) ($forward['service_id'] ?? 0) ?: null);
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
            if (in_array((string) ($endpoint['health_status'] ?? ''), ['pending', 'error', 'degraded'], true)) {
                $payload = $this->publishTaskPayloadForForward($forward, $node, (int) ($endpoint['id'] ?? 0));
                $this->queueTask($nodeId, 'publish', (int) ($forward['service_id'] ?? 0), $forwardId, (int) ($endpoint['id'] ?? 0), $payload);
            }
        }

        foreach ($existingByNode as $nodeId => $endpoint) {
            if (isset($reachableNodes[$nodeId])) {
                continue;
            }
            $this->queueTask((int) $nodeId, 'destroy', (int) ($forward['service_id'] ?? 0), $forwardId, (int) ($endpoint['id'] ?? 0), [
                'listen_port' => (int) ($endpoint['public_port'] ?? 0),
                'path_prefix' => (string) ($forward['path_prefix'] ?? ''),
                'reason' => 'reconcile',
            ]);
            $this->db->execute(
                'UPDATE service_endpoints SET health_status = :status, updated_at = :updated_at WHERE id = :id',
                [
                    'id' => (int) ($endpoint['id'] ?? 0),
                    'status' => 'deleting',
                    'updated_at' => $this->now(),
                ]
            );
        }
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

    private function publishTaskPayloadForForward(array $forward, array $node, int $endpointId): array
    {
        $clientMode = $this->normalizeClientMode((string) ($forward['client_mode'] ?? 'single'));
        $bondIngressMode = $this->normalizeBondIngressMode((string) ($forward['bond_ingress_mode'] ?? 'wss_path_mux'));
        $runtimePreference = $this->runtimePreferenceForForward($forward);
        $publishesBondRuntime = $clientMode === 'bond' || $runtimePreference === 'mptcp_preferred_bond_fallback';
        $backendMode = (string) ($forward['backend_mode'] ?? 'current_mode');
        $plainTcpIngress = $publishesBondRuntime && $bondIngressMode === 'plain_tcp_mux';
        $backendProtocol = $plainTcpIngress && $backendMode !== 'cf_mode'
            ? 'tcp_relay'
            : 'wss_backend';
        $routeKey = (string) ($forward['path_prefix'] ?? '');
        $directTargetMode = $publishesBondRuntime ? 'bond' : $clientMode;
        $directSidecarTarget = in_array($directTargetMode, ['bond', 'mptcp'], true)
            ? $this->resolveSidecarDirectTarget($forward, $directTargetMode)
            : null;
        if ($backendMode === 'cf_mode' && $directSidecarTarget !== null) {
            $publishTarget = '127.0.0.1:' . $directSidecarTarget['port'];
        } else {
            $publishTarget = $directSidecarTarget !== null
                ? ($directSidecarTarget['host'] . ':' . $directSidecarTarget['port'])
                : ((string) ($forward['target_host'] ?? '') . ':' . (int) ($forward['target_port'] ?? 0));
        }
        $payload = [
            'listen_port' => (int) ($forward['public_port'] ?? 0),
            'target' => $publishTarget,
            'origin_group_key' => (string) ($node['node_group_key'] ?? $node['group_key'] ?? ''),
            'forward_combination_id' => ($forward['forward_combination_id'] ?? null) !== null ? (int) $forward['forward_combination_id'] : null,
            'backend_mode' => $backendMode,
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

        $nodeConfig = $this->decodeJsonAssoc($node['agent_config_json'] ?? null);
        if (
            $this->nodeSupportsCfwgRelay($nodeConfig, (string) ($node['agent_version'] ?? $node['node_agent_version'] ?? ''))
            && strtolower(trim((string) ($forward['self_leg_cf_transport'] ?? $forward['forward_combination_self_leg_cf_transport'] ?? ''))) === 'cf_wg'
            && (int) ($forward['public_port'] ?? 0) >= $this->mptcpSlavePortRangeStart()
            && (int) ($forward['public_port'] ?? 0) <= $this->mptcpSlavePortRangeEnd()
        ) {
            $point = (int) ($forward['bond_sidecar_point_id'] ?? 0) > 0
                ? $this->db->fetchOne('SELECT agent_config_json, sidecar_host, listen_port, bond_listen_port, mptcp_listen_port FROM sidecar_points WHERE id = :id LIMIT 1', ['id' => (int) $forward['bond_sidecar_point_id']])
                : null;
            $pointConfig = $this->decodeJsonAssoc(is_array($point) ? ($point['agent_config_json'] ?? null) : null);
            $cfBasePathPrefix = trim((string) ($forward['path_prefix'] ?? '/'));
            $cfWgPathPrefix = str_ends_with($cfBasePathPrefix, '.cfwg') ? $cfBasePathPrefix : $cfBasePathPrefix . '.cfwg';
            $wireguardListenPort = (int) ($pointConfig['wireguard_listen_port'] ?? 0);
            if ($wireguardListenPort > 0) {
                $payload['cfwg_relay'] = [
                    'enabled' => true,
                    'path_algorithm' => 'cfwg-relay-v1',
                    'slave_path_prefix' => $this->autoCfwgPath((int) ($forward['id'] ?? $forward['forward_id'] ?? 0), 'slave_relay', (int) ($node['id'] ?? $node['node_id'] ?? 0)),
                    'cf_target_host' => '127.0.0.1',
                    'cf_target_port' => $wireguardListenPort,
                    'cf_dial_host' => (string) (($payload['cf_mode']['dial_host'] ?? '') ?: ($forward['bond_sidecar_host'] ?? $forward['bond_remote_host'] ?? '')),
                    'cf_dial_port' => (int) (($payload['cf_mode']['dial_port'] ?? 0) ?: ($forward['bond_sidecar_cf_listen_port'] ?? 443)),
                    'cf_sni_host' => (string) (($payload['cf_mode']['sni_host'] ?? '') ?: ($forward['sni'] ?? $forward['sni_host'] ?? '')),
                    'cf_path_prefix' => $cfWgPathPrefix,
                ];
            }
        }

        return $payload;
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pickNodes(string $groupKey, int $count, ?int $serviceId = null): array
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

        $sql .= ' ORDER BY n.current_load ASC, n.last_heartbeat_at DESC, n.id ASC LIMIT ' . max(1, $count);
        return $this->db->fetchAll($sql, $params);
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
            $rows = $this->db->fetchAll(
                'SELECT n.*, g.group_key AS node_group_key
                 FROM nodes n
                 LEFT JOIN node_groups g ON g.id = n.group_id
                 WHERE (n.status = :active OR n.status = :degraded)
                   AND g.group_key IN (' . implode(', ', $placeholders) . ')
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

        $pinnedRows = $this->db->fetchAll(
            'SELECT n.*, g.group_key AS node_group_key, fcs.sort_order AS pin_sort_order
             FROM forward_combination_slaves fcs
             INNER JOIN nodes n ON n.id = fcs.node_id
             LEFT JOIN node_groups g ON g.id = n.group_id
             WHERE fcs.forward_combination_id = :forward_combination_id
               AND fcs.is_active = 1
               AND fcs.selection_kind = :selection_kind
               AND (n.status = :active OR n.status = :degraded)
             ORDER BY fcs.sort_order ASC, n.id ASC',
            [
                'forward_combination_id' => $combinationId,
                'selection_kind' => 'admin_pin',
                'active' => 'active',
                'degraded' => 'degraded',
            ]
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
     * @return array<int, array<string, mixed>>
     */
    private function pickNodesForGroups(array $groupKeys, int $count, ?int $serviceId = null): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(static fn(string $item): string => trim($item), $groupKeys), static fn(string $item): bool => $item !== '')));
        if ($normalized === []) {
            return [];
        }

        $primary = [];
        $secondary = [];
        foreach ($normalized as $groupKey) {
            $groupNodes = $this->pickNodes($groupKey, max(1, $count), $serviceId);
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

        $sorter = static function (array $left, array $right): int {
            $loadCmp = ((int) $left['current_load']) <=> ((int) $right['current_load']);
            if ($loadCmp !== 0) {
                return $loadCmp;
            }
            return strcmp((string) ($right['last_heartbeat_at'] ?? ''), (string) ($left['last_heartbeat_at'] ?? ''));
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
            $ownerClause = ' AND (n.owner_service_id IS NULL OR n.owner_service_id = :owner_service_id)';
            $params['owner_service_id'] = $serviceId;
        }

        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total
             FROM nodes n
             LEFT JOIN node_groups g ON g.id = n.group_id
             WHERE (n.status = :active OR n.status = :degraded)
               AND g.group_key IN (' . implode(', ', $placeholders) . ')' . $ownerClause,
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
        $clientDirectQuicEnabled = $this->config->getBool('BOND_CLIENT_DIRECT_QUIC_ENABLED', false);
        [
            'join_timeout_ms' => $adaptiveJoinTimeoutMs,
            'join_timeout_floor_ms' => $joinTimeoutFloorMs,
            'join_timeout_ceiling_ms' => $joinTimeoutCeilingMs,
            'join_timeout_per_leg_ms' => $joinTimeoutPerLegMs,
        ] = $this->adaptiveBondJoinTimeoutConfig($endpoints);
        ['issued_at' => $issuedAt, 'expires_at' => $expiresAt] = $this->bondTokenTimestamps();
        return [
            'enabled' => true,
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
            'leg_cooldown_ms' => max(1000, (int) ($this->config->getInt('BOND_LEG_COOLDOWN_MS', 30 * 60 * 1000) ?? (30 * 60 * 1000))),
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
     * @return array<string, mixed>
     */
    private function buildMptcpClientConfig(array $forward, array $endpoints = []): array
    {
        $direct = $this->resolveSidecarDirectTarget($forward, 'mptcp');
        $tokenSecret = $this->bondTokenSecret($forward);
        ['issued_at' => $issuedAt, 'expires_at' => $expiresAt] = $this->bondTokenTimestamps();
        $subflowLimitDefault = max(2, count($endpoints) > 0 ? count($endpoints) : 2);
        $underlayLegs = $this->mptcpUnderlayLegs($forward, $endpoints);
        $pathManager = (string) ($this->config->get('MPTCP_PATH_MANAGER', 'fullmesh') ?? 'fullmesh');
        $defaultRemoteHost = $direct['host'];
        if ($underlayLegs !== []) {
            $defaultRemoteHost = (string) ($underlayLegs[0]['sidecar_address'] ?? $defaultRemoteHost);
        }

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
            'subflow_limit' => max(1, (int) ($this->config->getInt('MPTCP_SUBFLOW_LIMIT', $subflowLimitDefault) ?? $subflowLimitDefault)),
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
            'manage_mptcp_endpoints' => $underlayLegs !== [],
            'mptcp_endpoint_id_base' => (((int) ($forward['id'] ?? 0) % 25) * 10) + 1,
            'mptcp_endpoints' => array_values(array_map(function ($leg, $idx) use ($forward) {
                return [
                    'endpoint_id' => (int) ($leg['endpoint_id'] ?? 0),
                    'address' => $leg['local_address'],
                    'dev' => 'lw-mptcp-' . (int) ($forward['id'] ?? 0) . '-' . $idx,
                    'subflow' => true,
                    'signal' => false,
                    'backup_only' => $leg['backup_only'] ?? false,
                ];
            }, $underlayLegs, array_keys($underlayLegs))),
            'underlays' => array_values(array_map(function ($leg, $idx) use ($forward) {
                return [
                    'type' => (string) ($this->config->get('MPTCP_UNDERLAY_TYPE', 'wireguard') ?? 'wireguard'),
                    'mode' => 'slave_transit',
                    'interface_name' => 'lw-mptcp-' . (int) ($forward['id'] ?? 0) . '-' . $idx,
                    'peer_keepalive_seconds' => max(0, (int) ($this->config->getInt('MPTCP_PEER_KEEPALIVE_SECONDS', 25) ?? 25)),
                    'legs' => [$leg],
                ];
            }, $underlayLegs, array_keys($underlayLegs))),
            'token_expires_at' => $expiresAt,
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
            $legs[] = [
                'endpoint_id' => (int) ($endpoint['id'] ?? 0),
                'node_id' => (int) ($endpoint['node_id'] ?? 0),
                'node_name' => (string) ($endpoint['node_name'] ?? ''),
                'outer_transport' => $outerTransport,
                'peer_public_host' => $publicHost,
                'peer_udp_port' => $peerPort,
                'peer_tcp_fallback_port' => $fallbackTcpPort,
                'local_uot_udp_port' => $this->stableLocalUotUdpPort((int) ($forward['id'] ?? 0), (int) ($endpoint['node_id'] ?? 0)),
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
            ];
        }
        return $legs;
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

    private function stableLocalUotUdpPort(int $forwardId, int $nodeId): int
    {
        return 45000 + abs((($forwardId * 257) + ($nodeId * 17)) % 15000);
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
        $rangeSize = $portRangeEnd - $sidecarListenPort; // e.g. 14999-14969 = 30
        $stableIndex = abs(($forwardId * 256 + $nodeId) % $rangeSize);
        return $sidecarListenPort + 1 + $stableIndex;
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
                $fixedPort = $this->stableSidecarLegPort($sidecarListenPort, $sidecarPortRangeEnd, $fid, $nid);
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
            ? $this->db->fetchOne('SELECT agent_config_json, sidecar_host, listen_port, bond_listen_port, mptcp_listen_port FROM sidecar_points WHERE id = :id LIMIT 1', ['id' => (int) $forward['bond_sidecar_point_id']])
            : null;
        $sidecarConfig = $this->decodeJsonAssoc($point['agent_config_json'] ?? null);

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
        $nodeBasePort = isset($nodeConfig['wireguard_listen_port']) && (int) $nodeConfig['wireguard_listen_port'] > 0
            ? (int) $nodeConfig['wireguard_listen_port']
            : $publicPort;
        $nodeBaseFallback = isset($nodeConfig['wireguard_fallback_tcp_port']) && (int) $nodeConfig['wireguard_fallback_tcp_port'] > 0
            ? (int) $nodeConfig['wireguard_fallback_tcp_port']
            : ($nodeBasePort > 0 ? $nodeBasePort + 10000 : 0);

        $sidecarListenPort = $this->sidecarWireguardListenPort(is_array($point) ? $point : [], $sidecarConfig);
        $sidecarPortRangeEnd = $this->sidecarWireguardPortRangeEnd($sidecarListenPort, $sidecarConfig);
        $sidecarLegPort = $this->stableSidecarLegPort($sidecarListenPort, $sidecarPortRangeEnd, $forwardId, $nodeId);

        return [
            'enabled' => true,
            'interface_name' => 'lwf' . $forwardId . 'n' . $nodeId,
            'listen_port' => $this->stableMptcpSlavePort($nodeBasePort, $forwardId),
            'fallback_tcp_port' => $this->stableMptcpSlavePort($nodeBaseFallback, $forwardId),
            'local_uot_udp_port' => $this->stableLocalUotUdpPort($forwardId, $nodeId),
            'outer_transport' => $outerTransport,
            'sidecar_endpoint_host' => trim((string) ($point['sidecar_host'] ?? '')),
            'sidecar_endpoint_port' => $sidecarLegPort > 0 ? $sidecarLegPort : $sidecarListenPort,
            'peer_keepalive_seconds' => max(0, (int) ($this->config->getInt('MPTCP_PEER_KEEPALIVE_SECONDS', 25) ?? 25)),
            'client_public_key' => $clientPublicKey,
            'sidecar_public_key' => $sidecarPublicKey,
            'client_address' => $addresses['client_address'],
            'slave_address' => $addresses['slave_address'],
            'sidecar_address' => $addresses['sidecar_address'],
            'subnet' => $addresses['subnet'],
        ];
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
}
