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
        $user = $this->requireTenant($request);
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
        $user = $this->requireTenant($request);
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
        try {
        $user = $this->requireTenant($request);
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

        $payload = $this->normalizeLegacyCreatePayload($request->body());
        $runtime = $this->safeForwardMutation(fn(): array => $this->provisioning->createForward(
            $serviceId,
            $payload,
            'user_legacy:' . (int) $user['id'],
            null,
            null,
            max(1, $this->config->getInt('USER_FORWARD_LIMIT_PER_SERVICE', 64)),
            ['user_id' => (int) $user['id'], 'feature' => 'allow_manage_forwards']
        ));
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
        } catch (\Throwable $throwable) {
            return $this->legacyForwardErrorResponse($throwable);
        }
    }

    public function updateForward(Request $request, array $params): Response
    {
        try {
        $user = $this->requireTenant($request);
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
        $merged = ForwardLineApiContract::mergePatch($forward, $changes);
        $structural = array_key_exists('forward_route_id', $changes)
            || array_key_exists('target_source', $changes)
            || array_key_exists('target_service_id', $changes)
            || (array_key_exists('transport_mode', $changes)
                && (string) $merged['transport_mode'] !== (string) ($forward['transport_mode'] ?? 'tcp'))
            || (array_key_exists('listen_port', $changes) && (int) $changes['listen_port'] === 0);
        if ($structural) {
            $this->safeForwardMutation(fn(): array => $this->provisioning->updateForward(
                $forwardId,
                $merged,
                'user_legacy:' . (int) $user['id'],
                (int) ($forward['api_revision'] ?? 1),
                ['user_id' => (int) $user['id'], 'feature' => 'allow_manage_forwards']
            ));
        } else {
            $this->safeForwardMutation(fn(): array => $this->provisioning->updateForwardNonStructural(
                $forwardId,
                $merged,
                'user_legacy:' . (int) $user['id'],
                (int) ($forward['api_revision'] ?? 1),
                ['user_id' => (int) $user['id'], 'feature' => 'allow_manage_forwards']
            ));
        }
        $updated = $this->loadOwnedForward($forwardId, (int) $user['id']);

        return $this->legacyJson([
            'ok' => true,
            'forward' => ForwardLineApiContract::presentForward($updated),
            'client_config_url' => '/api/user/forwards/' . $forwardId . '/client-config',
        ]);
        } catch (\Throwable $throwable) {
            return $this->legacyForwardErrorResponse($throwable);
        }
    }

    public function deleteForward(Request $request, array $params): Response
    {
        try {
        $user = $this->requireTenant($request);
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

        $this->safeForwardMutation(function () use ($forwardId, $user, $forward): null {
            $this->provisioning->deleteForward(
                $forwardId,
                'user_legacy:' . (int) $user['id'],
                (int) ($forward['api_revision'] ?? 1),
                ['user_id' => (int) $user['id'], 'feature' => 'allow_delete_forwards']
            );
            return null;
        });
        return $this->legacyJson(['ok' => true]);
        } catch (\Throwable $throwable) {
            return $this->legacyForwardErrorResponse($throwable);
        }
    }

    public function clientConfig(Request $request, array $params): Response
    {
        try {
        $forwardId = $this->idParam($params);

        try {
            $user = $this->requireTenant($request);
            $forward = $this->db->fetchOne(
                'SELECT f.*, s.user_id
                 FROM forwards f
                 INNER JOIN services s ON s.id = f.service_id
                 WHERE f.id = :id
                   AND f.enabled = 1
                   AND s.status = :active_status
                 LIMIT 1',
                ['id' => $forwardId, 'active_status' => 'active']
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
        ])->withHeaders([
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        } catch (\Throwable $throwable) {
            return $this->legacyForwardErrorResponse($throwable);
        }
    }

    public function regenerateClientToken(Request $request, array $params): Response
    {
        $user = $this->requireTenant($request);
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

    /** @return array<string, mixed> */
    private function requireTenant(Request $request): array
    {
        $user = Auth::requireUser($request, $this->db);
        if ((string) ($user['role'] ?? '') !== 'tenant') {
            throw new ApiException('user_auth_failed', 401);
        }
        return $user;
    }

    private function safeForwardMutation(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $throwable) {
            throw $this->normalizeForwardThrowable($throwable);
        }
    }

    private function legacyForwardErrorResponse(\Throwable $throwable): Response
    {
        $safe = $this->normalizeForwardThrowable($throwable);
        return $this->legacyJson([
            'error' => $safe->getMessage(),
            'details' => $safe->details(),
        ], $safe->statusCode());
    }

    private function normalizeForwardThrowable(\Throwable $throwable): ApiException
    {
        if (!$throwable instanceof ApiException) {
            error_log('legacy user forward API operation failed: ' . $throwable::class);
            return new ApiException('operation_failed', 500);
        }

        $internalCode = $throwable->getMessage();
        $aliases = [
            'leigod_route_no_healthy_candidate' => 'route_unavailable',
            'no_available_nodes' => 'route_unavailable',
            'forward_route_combination_mismatch' => 'route_unavailable',
            'invalid_forward_payload' => 'validation_failed',
            'invalid_forward_target_source' => 'validation_failed',
            'target_service_not_found' => 'validation_failed',
            'target_service_not_allowed' => 'validation_failed',
            'target_service_ip_missing' => 'validation_failed',
        ];
        $code = $aliases[$internalCode] ?? $internalCode;
        $statuses = [
            'user_auth_failed' => 401,
            'client_token_missing' => 401,
            'client_auth_failed' => 401,
            'service_not_found' => 404,
            'forward_not_found' => 404,
            'validation_failed' => 422,
            'unknown_fields' => 422,
            'service_inactive' => 409,
            'forward_quota_exceeded' => 409,
            'forward_storage_quota_exceeded' => 409,
            'route_unavailable' => 409,
            'forward_version_conflict' => 412,
            'forward_route_not_allowed' => 422,
            'forward_route_has_no_enabled_combination' => 422,
            'feature_denied_manage_forwards' => 403,
            'feature_denied_delete_forwards' => 403,
            'operation_failed' => 500,
        ];
        if (!array_key_exists($code, $statuses)) {
            $safeInternalCode = preg_replace('/[^A-Za-z0-9_.:-]/', '?', substr($internalCode, 0, 80));
            error_log('legacy user forward API internal error code: ' . $safeInternalCode);
            return new ApiException('operation_failed', 500);
        }

        $details = [];
        $sourceDetails = $throwable->details();
        if (in_array($code, ['validation_failed', 'unknown_fields'], true)
            && is_array($sourceDetails['fields'] ?? null)) {
            $details['fields'] = $sourceDetails['fields'];
        }
        return new ApiException($code, $statuses[$code], $details);
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
    private function normalizeLegacyCreatePayload(array $payload): array
    {
        unset($payload['endpoint_count']);
        $payload = $this->normalizeLegacyScalarFields($payload);
        if (array_key_exists('transport_mode', $payload)) {
            $payload['transport_mode'] = $this->normalizeLegacyTransport($payload['transport_mode']);
        }
        return ForwardLineApiContract::normalizeLegacyCreate($payload);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function normalizeForwardNonStructuralPayload(array $payload): array
    {
        unset($payload['endpoint_count']);
        $payload = $this->normalizeLegacyScalarFields($payload);
        if (array_key_exists('transport_mode', $payload)) {
            $payload['transport_mode'] = $this->normalizeLegacyTransport($payload['transport_mode']);
        }
        return ForwardLineApiContract::normalizeLegacyUpdate($payload);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function normalizeLegacyScalarFields(array $payload): array
    {
        if (array_key_exists('listen_port', $payload)
            && ($payload['listen_port'] === '' || $payload['listen_port'] === 0 || $payload['listen_port'] === '0')) {
            $payload['listen_port'] = null;
        }
        foreach (['forward_route_id', 'route_id', 'listen_port', 'target_port', 'target_service_id'] as $field) {
            if (!is_string($payload[$field] ?? null)) {
                continue;
            }
            $value = trim((string) $payload[$field]);
            if (preg_match('/\A[0-9]+\z/D', $value) === 1) {
                $payload[$field] = (int) $value;
            }
        }
        return $payload;
    }

    private function normalizeLegacyTransport(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $normalized = strtolower(trim($value));
        return match ($normalized) {
            'udp' => 'udp',
            'tcp+udp', 'udp+tcp', 'both' => 'tcp+udp',
            'tcp' => 'tcp',
            default => $normalized,
        };
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
