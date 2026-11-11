<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

/**
 * Route Directions (user-facing direction labels), their primary realization (the
 * helper forward + local endpoint a device routes a Client Rule into), plan
 * entitlements, and the route-rule part of the client config pull.
 *
 * Contract: docs/superpowers/plans/2026-07-22-backend-admin-route-direction-client-rules.md
 * Android consumer: leikwan-android .../data/clientrules + api/PanelModels.kt.
 */
final class RouteDirectionService
{
    public function __construct(
        private Config $config,
        private Database $db,
        private ClientRuleValidator $validator,
    ) {
    }

    public function normalizeDirectionId(string $id): string
    {
        $normalized = strtolower(trim($id));
        $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? $normalized;
        $normalized = trim($normalized, '_');
        if (!preg_match('/^dir_[a-z0-9_]{2,60}$/', $normalized)) {
            throw new ApiException('invalid_route_direction_id', 422);
        }
        return $normalized;
    }

    public function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        if (!in_array($normalized, ['available', 'unavailable', 'maintenance', 'retired'], true)) {
            throw new ApiException('invalid_route_direction_status', 422);
        }
        return $normalized;
    }

    public function directionPayload(array $direction, bool $admin = false): array
    {
        $status = $this->normalizeStatus((string) ($direction['status'] ?? 'available'));
        $payload = [
            'id' => (string) $direction['id'],
            'label' => (string) $direction['label'],
            'status' => $status,
            'is_available' => $status === 'available',
            'sort_order' => (int) ($direction['sort_order'] ?? 100),
        ];
        if (!$admin) {
            return $payload;
        }
        $payload['admin_notes'] = (string) ($direction['admin_notes'] ?? '');
        $payload['internal_mapping'] = $this->decodeJsonAssoc($direction['internal_mapping_json'] ?? null);
        $payload['realization'] = $this->primaryRealizationForDirection((string) $direction['id']);
        $payload['usage'] = $this->usageForDirection((string) $direction['id']);
        return $payload;
    }

    public function listDirections(bool $admin = false, ?string $serviceScopeId = null): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM route_directions ORDER BY sort_order ASC, label ASC, id ASC'
        );
        if ($serviceScopeId !== null) {
            $allowed = $this->entitlementForScope($serviceScopeId)['allowed_direction_ids'] ?? [];
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => in_array((string) ($row['id'] ?? ''), $allowed, true)
            ));
        }
        return array_map(fn(array $row): array => $this->directionPayload($row, $admin), $rows);
    }

    public function getDirection(string $directionId, bool $admin = false): ?array
    {
        $id = $this->normalizeDirectionId($directionId);
        $row = $this->db->fetchOne('SELECT * FROM route_directions WHERE id = :id LIMIT 1', ['id' => $id]);
        return $row === null ? null : $this->directionPayload($row, $admin);
    }

    public function saveDirection(array $payload): array
    {
        $id = $this->normalizeDirectionId((string) ($payload['id'] ?? ''));
        $status = $this->normalizeStatus((string) ($payload['status'] ?? 'available'));
        $label = trim((string) ($payload['label'] ?? ''));
        if ($label === '') {
            throw new ApiException('invalid_route_direction_label', 422);
        }
        $now = $this->now();
        $values = [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'admin_notes' => (string) ($payload['admin_notes'] ?? ''),
            'sort_order' => (int) ($payload['sort_order'] ?? 100),
            'internal_mapping_json' => json_encode($payload['internal_mapping'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];
        $existing = $this->db->fetchOne('SELECT id FROM route_directions WHERE id = :id LIMIT 1', ['id' => $id]);
        if ($existing === null) {
            $this->db->insert('route_directions', $values + ['created_at' => $now]);
        } else {
            $this->db->execute(
                'UPDATE route_directions SET label = :label, status = :status, admin_notes = :admin_notes, sort_order = :sort_order, internal_mapping_json = :internal_mapping_json, updated_at = :updated_at WHERE id = :id',
                $values
            );
        }
        if (is_array($payload['realization'] ?? null)) {
            $this->savePrimaryRealization($id, $payload['realization']);
        }
        $saved = $this->getDirection($id, true);
        if ($saved === null) {
            throw new ApiException('route_direction_save_failed', 500);
        }
        return $saved;
    }

    public function savePlanEntitlements(int $planId, array $directionIds, int $maxRules): void
    {
        $normalized = [];
        foreach ($directionIds as $directionId) {
            $normalized[] = $this->normalizeDirectionId((string) $directionId);
        }
        $normalized = array_values(array_unique($normalized));
        $now = $this->now();
        $this->db->transaction(function () use ($planId, $normalized, $maxRules, $now): void {
            $this->db->execute('DELETE FROM service_plan_route_directions WHERE plan_id = :plan_id', ['plan_id' => $planId]);
            foreach ($normalized as $directionId) {
                $this->db->insert('service_plan_route_directions', [
                    'plan_id' => $planId,
                    'direction_id' => $directionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->db->execute(
                'UPDATE service_plans SET max_client_rules_per_service_scope = :max_rules, updated_at = :updated_at WHERE id = :id',
                ['id' => $planId, 'max_rules' => max(0, $maxRules), 'updated_at' => $now]
            );
        });
    }

    public function allowedDirectionIdsForPlan(int $planId): array
    {
        if ($planId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT direction_id FROM service_plan_route_directions WHERE plan_id = :plan_id ORDER BY direction_id ASC',
            ['plan_id' => $planId]
        );
        return array_values(array_map(static fn(array $row): string => (string) $row['direction_id'], $rows));
    }

    public function serviceScopeForServiceId(int $serviceId): string
    {
        if ($serviceId <= 0) {
            throw new ApiException('invalid_service_scope', 422);
        }
        return 'svc_scope_' . $serviceId;
    }

    public function serviceIdForScope(string $serviceScopeId): int
    {
        if (preg_match('/^svc_scope_(\d+)$/', $serviceScopeId, $matches) !== 1) {
            throw new ApiException('invalid_service_scope', 422);
        }
        return (int) $matches[1];
    }

    public function serviceForScope(string $serviceScopeId): ?array
    {
        return $this->db->fetchOne(
            'SELECT s.*, p.max_client_rules_per_service_scope
             FROM services s
             LEFT JOIN service_plans p ON p.id = s.plan_id
             WHERE s.id = :service_id
             LIMIT 1',
            ['service_id' => $this->serviceIdForScope($serviceScopeId)]
        );
    }

    public function entitlementForScope(string $serviceScopeId): array
    {
        $service = $this->serviceForScope($serviceScopeId);
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }
        $planId = (int) ($service['plan_id'] ?? 0);
        $maxRules = max(0, (int) ($service['max_client_rules_per_service_scope'] ?? 0));
        $usedRules = (int) (($this->db->fetchOne(
            'SELECT COUNT(*) AS total FROM client_rules WHERE service_scope_id = :service_scope_id AND tombstoned_at IS NULL',
            ['service_scope_id' => $serviceScopeId]
        )['total'] ?? 0));
        return [
            'service_scope_id' => $serviceScopeId,
            'service_id' => (int) $service['id'],
            'subscription_id' => ($service['whmcs_service_id'] ?? null) !== null ? (int) $service['whmcs_service_id'] : null,
            'max_rules' => $maxRules,
            'remaining_rules' => max(0, $maxRules - $usedRules),
            'allowed_direction_ids' => $this->allowedDirectionIdsForPlan($planId),
            'over_quota' => $usedRules > $maxRules,
        ];
    }

    /**
     * The route-rule part of configs/pull. Empty (no keys at all) unless the device
     * advertised both route-rule capabilities in its heartbeat.
     */
    public function configPullForClient(int $clientId, array $capabilities, array $forwards): array
    {
        if (empty($capabilities['route_direction_rules']) || empty($capabilities['exact_destination_rules'])) {
            return [];
        }
        $serviceIds = $this->serviceIdsForClientPull($clientId, $forwards);
        $payload = [
            'route_directions' => [],
            'route_rule_entitlements' => [],
            'client_rules' => [],
            'direction_runtime_bindings' => [],
            'capabilities' => ['route_direction_rules' => true, 'exact_destination_rules' => true],
        ];
        if ($serviceIds === []) {
            return $payload;
        }
        $directionsById = [];
        foreach ($serviceIds as $serviceId) {
            $serviceScopeId = $this->serviceScopeForServiceId((int) $serviceId);
            $entitlement = $this->entitlementForScope($serviceScopeId);
            $payload['route_rule_entitlements'][] = $entitlement;
            foreach ($this->rulesForScope($serviceScopeId) as $rule) {
                $payload['client_rules'][] = [
                    'id' => (string) $rule['id'],
                    'service_scope_id' => $serviceScopeId,
                    'destination_ip' => (string) $rule['destination_ip'],
                    'destination_port' => (int) $rule['destination_port'],
                    'protocol' => 'tcp_udp',
                    'direction_id' => (string) $rule['direction_id'],
                    'enabled' => (int) ($rule['enabled'] ?? 0) === 1,
                    'effective_state' => $this->effectiveStateForRule($rule, $entitlement),
                    'sync_version' => (int) ($rule['sync_version'] ?? 1),
                ];
            }
            foreach ($this->directionsRelevantToScope($serviceScopeId) as $direction) {
                $directionsById[(string) $direction['id']] = $this->directionPayload($direction, false);
                $binding = $this->runtimeBindingForDirection($serviceScopeId, $direction);
                if ($binding !== null) {
                    $payload['direction_runtime_bindings'][] = $binding;
                }
            }
        }
        $payload['route_directions'] = array_values($directionsById);
        return $payload;
    }

    /**
     * Change marker for the route-rule pull payload, folded into the stable
     * config_revision so a rule write (from any device or the console) wakes the
     * long poll. '' when the device gets no route-rule payload, so legacy
     * clients keep their existing revision.
     */
    public function revisionDigestForClient(int $clientId, array $capabilities, array $forwards): string
    {
        $payload = $this->configPullForClient($clientId, $capabilities, $forwards);
        if ($payload === []) {
            return '';
        }
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function savePrimaryRealization(string $directionId, array $realization): void
    {
        $now = $this->now();
        $forwardId = max(0, (int) ($realization['forward_id'] ?? 0));
        $tcpPort = max(0, (int) ($realization['local_tcp_port'] ?? 0));
        $udpPort = max(0, (int) ($realization['local_udp_port'] ?? 0));
        $bindingState = (string) ($realization['binding_state'] ?? ($forwardId > 0 ? 'ready' : 'missing'));
        if (!in_array($bindingState, ['ready', 'missing'], true)) {
            throw new ApiException('invalid_realization_binding_state', 422);
        }
        // Devices route both transports into one local listener (Android readyForAndroid
        // requires equal ports); a ready binding with diverging ports could never apply.
        if ($bindingState === 'ready' && $tcpPort > 0 && $udpPort > 0 && $tcpPort !== $udpPort) {
            throw new ApiException('realization_ports_must_match', 422);
        }
        $data = [
            'direction_id' => $directionId,
            'is_primary' => 1,
            'binding_state' => $bindingState,
            'forward_id' => $forwardId > 0 ? $forwardId : null,
            'local_endpoint_host' => (string) ($realization['local_endpoint_host'] ?? '127.0.0.1'),
            'local_tcp_port' => $tcpPort > 0 ? $tcpPort : null,
            'local_udp_port' => $udpPort > 0 ? $udpPort : null,
            'tcp_ready' => !empty($realization['tcp_ready']) ? 1 : 0,
            'udp_ready' => !empty($realization['udp_ready']) ? 1 : 0,
            'helper_only' => array_key_exists('helper_only', $realization) ? (!empty($realization['helper_only']) ? 1 : 0) : 1,
            'internal_mapping_json' => json_encode($realization['internal_mapping'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];
        $existing = $this->db->fetchOne(
            'SELECT id FROM direction_realizations WHERE direction_id = :direction_id AND is_primary = 1 LIMIT 1',
            ['direction_id' => $directionId]
        );
        if ($existing === null) {
            $this->db->insert('direction_realizations', $data + ['created_at' => $now]);
            return;
        }
        $data['id'] = (int) $existing['id'];
        $this->db->execute(
            'UPDATE direction_realizations SET binding_state = :binding_state, forward_id = :forward_id, local_endpoint_host = :local_endpoint_host, local_tcp_port = :local_tcp_port, local_udp_port = :local_udp_port, tcp_ready = :tcp_ready, udp_ready = :udp_ready, helper_only = :helper_only, internal_mapping_json = :internal_mapping_json, updated_at = :updated_at WHERE id = :id',
            $data
        );
    }

    private function runtimeBindingForDirection(string $serviceScopeId, array $direction): ?array
    {
        $realization = $this->primaryRealizationForDirection((string) ($direction['id'] ?? ''));
        if ($realization === null) {
            return null;
        }
        $forwardId = (int) ($realization['forward_id'] ?? 0);
        $tcpPort = (int) ($realization['local_tcp_port'] ?? 0);
        $udpPort = (int) ($realization['local_udp_port'] ?? 0);
        $tcpReady = (int) ($realization['tcp_ready'] ?? 0) === 1;
        $udpReady = (int) ($realization['udp_ready'] ?? 0) === 1;
        $ready = (string) ($realization['binding_state'] ?? '') === 'ready'
            && $forwardId > 0
            && $tcpPort > 0
            && $udpPort > 0
            && $tcpReady
            && $udpReady;

        return [
            'service_scope_id' => $serviceScopeId,
            'client_runtime_binding_id' => $serviceScopeId . ':' . (string) $direction['id'],
            'direction_id' => (string) $direction['id'],
            'binding_state' => $ready ? 'ready' : 'missing',
            'runtime_ref' => [
                'type' => 'forward_ref',
                'forward_id' => $forwardId > 0 ? $forwardId : null,
                'selectable' => false,
                'transport' => 'tcp_udp',
            ],
            'local_endpoint' => [
                'host' => (string) ($realization['local_endpoint_host'] ?? '127.0.0.1'),
                'tcp_port' => $tcpPort > 0 ? $tcpPort : null,
                'udp_port' => $udpPort > 0 ? $udpPort : null,
            ],
            'tcp_ready' => $ready,
            'udp_ready' => $ready,
        ];
    }

    private function primaryRealizationForDirection(string $directionId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM direction_realizations WHERE direction_id = :direction_id AND is_primary = 1 LIMIT 1',
            ['direction_id' => $directionId]
        );
    }

    private function usageForDirection(string $directionId): array
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS rules FROM client_rules WHERE direction_id = :direction_id AND tombstoned_at IS NULL',
            ['direction_id' => $directionId]
        ) ?? [];
        return ['rules' => (int) ($row['rules'] ?? 0)];
    }

    private function serviceIdsForClientPull(int $clientId, array $forwards): array
    {
        $serviceIds = [];
        foreach ($forwards as $forward) {
            if (is_array($forward) && isset($forward['service_id'])) {
                $serviceIds[] = (int) $forward['service_id'];
            }
        }
        if ($serviceIds === []) {
            $rows = $this->db->fetchAll(
                'SELECT DISTINCT s.id
                 FROM services s
                 INNER JOIN clients c ON c.owner_user_id = s.user_id
                 WHERE c.id = :client_id AND s.status = :active
                 ORDER BY s.id ASC',
                ['client_id' => $clientId, 'active' => 'active']
            );
            $serviceIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        }
        $serviceIds = array_values(array_unique(array_filter($serviceIds, static fn(int $id): bool => $id > 0)));
        sort($serviceIds);
        return $serviceIds;
    }

    private function rulesForScope(string $serviceScopeId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM client_rules WHERE service_scope_id = :service_scope_id AND tombstoned_at IS NULL ORDER BY id ASC',
            ['service_scope_id' => $serviceScopeId]
        );
    }

    private function directionsRelevantToScope(string $serviceScopeId): array
    {
        $entitlement = $this->entitlementForScope($serviceScopeId);
        $ids = $entitlement['allowed_direction_ids'] ?? [];
        foreach ($this->rulesForScope($serviceScopeId) as $rule) {
            $ids[] = (string) $rule['direction_id'];
        }
        $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->fetchAll(
            'SELECT * FROM route_directions WHERE id IN (' . $placeholders . ') ORDER BY sort_order ASC, label ASC, id ASC',
            $ids
        );
    }

    public function effectiveStateForRule(array $rule, array $entitlement): string
    {
        if ((int) ($rule['enabled'] ?? 0) !== 1) {
            return 'disabled';
        }
        if (!empty($entitlement['over_quota'])) {
            return 'over_quota';
        }
        if (!in_array((string) ($rule['direction_id'] ?? ''), $entitlement['allowed_direction_ids'] ?? [], true)) {
            return 'not_allowed_by_plan';
        }
        $direction = $this->db->fetchOne(
            'SELECT * FROM route_directions WHERE id = :id LIMIT 1',
            ['id' => (string) $rule['direction_id']]
        );
        if ($direction === null || (string) ($direction['status'] ?? '') !== 'available') {
            return 'unavailable';
        }
        $binding = $this->runtimeBindingForDirection((string) $entitlement['service_scope_id'], $direction);
        return $binding !== null && ($binding['binding_state'] ?? '') === 'ready' ? 'active' : 'runtime_missing';
    }

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

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
