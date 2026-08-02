<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\Auth;
use Leikwan\Master\Support\ForwardLineApiContract;
use Leikwan\Master\Support\ProvisioningService;

final class ServiceController extends BaseController
{
    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ProvisioningService $provisioning,
    ) {
        parent::__construct($config, $db);
    }

    public function listServices(Request $request, array $params): Response
    {
        $user = Auth::requireUser($request, $this->db);
        $services = $this->db->fetchAll(
            'SELECT id, name, status, whmcs_service_id, expire_at, bandwidth_limit_mbps, traffic_limit_gb, traffic_used_bytes
             FROM services
             WHERE user_id = :user_id
             ORDER BY id DESC',
            ['user_id' => $user['id']]
        );

        return $this->json([
            'ok' => true,
            'services' => $services,
        ]);
    }

    public function showService(Request $request, array $params): Response
    {
        $user = Auth::requireUser($request, $this->db);
        $serviceId = $this->idParam($params);
        $service = $this->db->fetchOne(
            'SELECT id, user_id, plan_id, name, status
             FROM services
             WHERE id = :id AND user_id = :user_id LIMIT 1',
            [
            'id' => $serviceId,
            'user_id' => $user['id'],
            ]
        );
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }

        return $this->json([
            'ok' => true,
            'service' => ForwardLineApiContract::presentService($this->safeServiceViewData($service, $serviceId)),
        ]);
    }

    public function createForward(Request $request, array $params): Response
    {
        $user = Auth::requireUser($request, $this->db);
        $serviceId = $this->idParam($params);
        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id AND user_id = :user_id LIMIT 1', [
            'id' => $serviceId,
            'user_id' => $user['id'],
        ]);
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }
        $this->assertServiceActive($service);
        $features = $this->serviceFeatures($serviceId);
        $this->assertFeature($features, 'allow_manage_forwards', 'feature_denied_manage_forwards');
        $this->assertForwardQuota($serviceId);

        $payload = ForwardLineApiContract::normalizeLegacyCreate($request->body());
        $runtime = $this->provisioning->createForward(
            $serviceId,
            $payload,
            'user_legacy:' . (int) $user['id']
        );
        $forwardId = (int) ($runtime['forward_id'] ?? 0);
        if ($forwardId <= 0) {
            throw new ApiException('forward_creation_failed', 500);
        }
        $forward = $this->loadOwnedForward($forwardId, (int) $user['id']);

        return $this->legacyJson([
            'ok' => true,
            'forward' => ForwardLineApiContract::presentForward($forward),
            'client_config_url' => '/api/user/forwards/' . $forwardId . '/client-config',
        ], 201);
    }

    public function updateForward(Request $request, array $params): Response
    {
        $user = Auth::requireUser($request, $this->db);
        $forwardId = $this->idParam($params);
        $forward = $this->loadOwnedForward($forwardId, (int) $user['id']);
        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id AND user_id = :user_id LIMIT 1', [
            'id' => (int) $forward['service_id'],
            'user_id' => $user['id'],
        ]);
        if ($service === null) {
            throw new ApiException('forward_not_found', 404);
        }
        $this->assertServiceActive($service);
        $features = $this->serviceFeatures((int) $forward['service_id']);
        $this->assertFeature($features, 'allow_manage_forwards', 'feature_denied_manage_forwards');

        $changes = $this->normalizeForwardNonStructuralPayload($request->body());
        $merged = [
            'forward_route_id' => (int) ($forward['forward_route_id'] ?? 0),
            'listen_port' => (int) ($forward['listen_port'] ?? 0),
            'target_host' => (string) ($forward['target_host'] ?? ''),
            'target_port' => (int) ($forward['target_port'] ?? 0),
            'target_source' => (string) ($forward['target_source'] ?? 'manual'),
            'target_service_id' => isset($forward['target_service_id']) ? (int) $forward['target_service_id'] : 0,
            'transport_mode' => (string) ($forward['transport_mode'] ?? 'tcp'),
        ];
        foreach ($changes as $key => $value) {
            $merged[$key] = $value;
        }
        $structural = array_key_exists('forward_route_id', $changes)
            || array_key_exists('target_source', $changes)
            || array_key_exists('target_service_id', $changes);
        if ($structural) {
            $this->provisioning->updateForward($forwardId, $merged, 'user_legacy:' . (int) $user['id']);
        } else {
            $this->provisioning->updateForwardNonStructural($forwardId, $merged, 'user_legacy:' . (int) $user['id']);
        }
        $updated = $this->loadOwnedForward($forwardId, (int) $user['id']);

        return $this->legacyJson([
            'ok' => true,
            'forward' => ForwardLineApiContract::presentForward($updated),
            'client_config_url' => '/api/user/forwards/' . $forwardId . '/client-config',
        ]);
    }

    public function deleteForward(Request $request, array $params): Response
    {
        $user = Auth::requireUser($request, $this->db);
        $forwardId = $this->idParam($params);
        $forward = $this->loadOwnedForward($forwardId, (int) $user['id']);
        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id AND user_id = :user_id LIMIT 1', [
            'id' => (int) $forward['service_id'],
            'user_id' => $user['id'],
        ]);
        if ($service === null) {
            throw new ApiException('forward_not_found', 404);
        }
        $this->assertServiceActive($service);
        $features = $this->serviceFeatures((int) $forward['service_id']);
        $this->assertFeature($features, 'allow_delete_forwards', 'feature_denied_delete_forwards');

        $this->provisioning->deleteForward($forwardId, 'user');
        return $this->legacyJson(['ok' => true]);
    }

    public function clientConfig(Request $request, array $params): Response
    {
        $forwardId = $this->idParam($params);

        try {
            $user = Auth::requireUser($request, $this->db);
            $forward = $this->db->fetchOne(
                'SELECT f.*, s.user_id
                 FROM forwards f
                 INNER JOIN services s ON s.id = f.service_id
                 WHERE f.id = :id LIMIT 1',
                ['id' => $forwardId]
            );
            if ($forward === null || (int) $forward['user_id'] !== (int) $user['id']) {
                throw new ApiException('forward_not_found', 404);
            }
        } catch (ApiException $exception) {
            Auth::requireClientToken($request, $this->db, $forwardId);
        }

        return $this->json([
            'ok' => true,
            'config' => $this->provisioning->buildClientConfig($forwardId),
        ]);
    }

    public function regenerateClientToken(Request $request, array $params): Response
    {
        $user = Auth::requireUser($request, $this->db);
        $serviceId = $this->idParam($params);
        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id AND user_id = :user_id LIMIT 1', [
            'id' => $serviceId,
            'user_id' => $user['id'],
        ]);
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }

        return $this->json([
            'ok' => true,
            'token' => $this->provisioning->regenerateClientToken($serviceId),
        ]);
    }

    public function listAllServices(Request $request, array $params): Response
    {
        Auth::requireAdmin($request, $this->config);
        $services = $this->db->fetchAll(
            'SELECT s.id, s.name, s.status, s.whmcs_service_id, s.expire_at, s.traffic_limit_gb, s.traffic_used_bytes, u.email AS user_email
             FROM services s
             INNER JOIN users u ON u.id = s.user_id
             ORDER BY s.id DESC
             LIMIT 200'
        );

        return $this->json([
            'ok' => true,
            'services' => $services,
        ]);
    }

    /** @param array<string, mixed> $service */
    private function assertServiceActive(array $service): void
    {
        if ((string) ($service['status'] ?? '') !== 'active') {
            throw new ApiException('service_inactive', 409);
        }
    }

    /** @param array<string, bool> $features */
    private function assertFeature(array $features, string $feature, string $errorCode): void
    {
        if (empty($features[$feature])) {
            throw new ApiException($errorCode, 403);
        }
    }

    /** @return array<string, bool> */
    private function serviceFeatures(int $serviceId): array
    {
        $summary = $this->provisioning->serviceSummary($serviceId);
        $features = $summary['effective_features'] ?? [];
        return is_array($features) ? $features : [];
    }

    private function assertForwardQuota(int $serviceId): void
    {
        $rows = $this->db->fetchAll(
            'SELECT id FROM forwards WHERE service_id = :service_id AND enabled = 1',
            ['service_id' => $serviceId]
        );
        if (count($rows) >= max(1, $this->config->getInt('USER_FORWARD_LIMIT_PER_SERVICE', 64))) {
            throw new ApiException('forward_quota_exceeded', 409);
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function normalizeForwardNonStructuralPayload(array $payload): array
    {
        return ForwardLineApiContract::normalizeLegacyUpdate($payload);
    }

    /** @param array<string, mixed> $service */
    private function safeServiceViewData(array $service, int $serviceId): array
    {
        $summary = $this->provisioning->serviceSummary($serviceId);
        $features = is_array($summary['effective_features'] ?? null) ? $summary['effective_features'] : [];
        $activeForwardCount = $this->db->fetchOne(
            'SELECT COUNT(*) AS active_forward_count
             FROM forwards
             WHERE service_id = :service_id AND enabled = 1',
            ['service_id' => $serviceId]
        );

        return [
            'id' => (int) ($service['id'] ?? 0),
            'name' => (string) ($service['name'] ?? ''),
            'status' => (string) ($service['status'] ?? ''),
            'plan_id' => isset($service['plan_id']) ? (int) $service['plan_id'] : null,
            'plan_name' => isset($summary['plan_name']) ? (string) $summary['plan_name'] : null,
            'effective_features' => [
                'allow_manage_forwards' => !empty($features['allow_manage_forwards']),
                'allow_delete_forwards' => !empty($features['allow_delete_forwards']),
            ],
            'active_forward_count' => max(0, (int) ($activeForwardCount['active_forward_count'] ?? 0)),
            'forward_limit' => max(1, $this->config->getInt('USER_FORWARD_LIMIT_PER_SERVICE', 64)),
        ];
    }

    /** @return array<string, mixed> */
    private function loadOwnedForward(int $forwardId, int $userId): array
    {
        $forward = $this->db->fetchOne(
            'SELECT f.*, s.user_id, fr.tenant_label AS route_label
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             LEFT JOIN forward_routes fr ON fr.id = f.forward_route_id
             WHERE f.id = :id AND s.user_id = :user_id AND f.enabled = 1 LIMIT 1',
            ['id' => $forwardId, 'user_id' => $userId]
        );
        if ($forward === null) {
            throw new ApiException('forward_not_found', 404);
        }
        return $forward;
    }

    /** @param array<string, mixed> $payload */
    private function legacyJson(array $payload, int $status = 200): Response
    {
        return $this->json($payload, $status)->withHeaders(['Deprecation' => 'true']);
    }
}
