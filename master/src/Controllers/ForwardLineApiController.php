<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Throwable;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\Auth;
use Leikwan\Master\Support\ForwardLineApiContract;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\ServiceStatus;

final class ForwardLineApiController extends BaseController
{
    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ProvisioningService $provisioning,
    ) {
        parent::__construct($config, $db);
    }

    /**
     * The service ids every other endpoint takes. They are services.id, not the
     * Client ID on the Clients page nor the WHMCS service id, and tenants could
     * not find them anywhere, so a program can discover them here first.
     */
    public function services(Request $request, array $params): Response
    {
        return $this->run(function () use ($request): Response {
            $user = $this->authenticateTenant($request);
            $rows = $this->db->fetchAll(
                'SELECT s.*, u.feature_flags_json AS user_feature_flags_json,
                        p.name AS plan_name, p.feature_flags_json AS plan_feature_flags_json
                 FROM services s
                 INNER JOIN users u ON u.id = s.user_id
                 LEFT JOIN service_plans p ON p.id = s.plan_id
                 WHERE s.user_id = :user_id
                 ORDER BY s.id ASC',
                ['user_id' => (int) $user['id']]
            );
            $services = [];
            foreach ($rows as $service) {
                $serviceId = (int) ($service['id'] ?? 0);
                $service['status'] = ServiceStatus::effective($service);
                $service['effective_features'] = $this->effectiveFeatures($service);
                $service['active_forward_count'] = $this->activeForwardCount($serviceId);
                $service['forward_limit'] = $this->provisioning->forwardLimitForService($serviceId);
                $services[] = ForwardLineApiContract::presentService($service);
            }

            return $this->success([
                'ok' => true,
                'services' => $services,
            ]);
        });
    }

    public function options(Request $request, array $params): Response
    {
        return $this->run(function () use ($request, $params): Response {
            $user = $this->authenticateTenant($request);
            $serviceId = $this->pathId($params, 'service_not_found');
            $service = $this->loadService($serviceId, (int) $user['id']);
            $service['effective_features'] = $this->effectiveFeatures($service);
            $service['active_forward_count'] = $this->activeForwardCount($serviceId);
            $service['forward_limit'] = $this->provisioning->forwardLimitForService($serviceId);

            $routes = array_map(
                static fn(array $route): array => ForwardLineApiContract::presentRoute($route),
                $this->provisioning->availableForwardRoutesForService($serviceId)
            );
            $safeService = ForwardLineApiContract::presentService($service);

            return $this->success([
                'ok' => true,
                'transports' => ['tcp', 'udp', 'tcp+udp'],
                'forward_limit' => $safeService['forward_limit'],
                'active_forward_count' => $safeService['active_forward_count'],
                'capabilities' => $safeService['capabilities'],
                'routes' => $routes,
            ]);
        });
    }

    public function index(Request $request, array $params): Response
    {
        return $this->run(function () use ($request, $params): Response {
            $user = $this->authenticateTenant($request);
            $serviceId = $this->pathId($params, 'service_not_found');
            $this->loadService($serviceId, (int) $user['id']);
            $rows = $this->db->fetchAll(
                'SELECT f.*, fr.tenant_label AS route_label,
                        (SELECT COUNT(*)
                         FROM service_endpoints se
                         WHERE se.forward_id = f.id
                           AND se.health_status = :active_endpoint_status) AS active_endpoint_count
                 FROM forwards f
                 LEFT JOIN forward_routes fr ON fr.id = f.forward_route_id
                 WHERE f.service_id = :service_id
                   AND f.enabled = 1
                 ORDER BY f.id ASC',
                ['service_id' => $serviceId, 'active_endpoint_status' => 'active']
            );

            return $this->success([
                'ok' => true,
                'forwards' => array_map(
                    static fn(array $forward): array => ForwardLineApiContract::presentForward($forward),
                    $rows
                ),
            ]);
        });
    }

    public function show(Request $request, array $params): Response
    {
        return $this->run(function () use ($request, $params): Response {
            $user = $this->authenticateTenant($request);
            $forwardId = $this->pathId($params, 'forward_not_found');
            $forward = $this->loadOwnedForward($forwardId, (int) $user['id']);
            return $this->resourceResponse(
                ['ok' => true, 'forward' => ForwardLineApiContract::presentForward($forward)],
                ForwardLineApiContract::etag($forward)
            );
        });
    }

    public function create(Request $request, array $params): Response
    {
        return $this->run(function () use ($request, $params): Response {
            $user = $this->authenticateTenant($request);
            $serviceId = $this->pathId($params, 'service_not_found');
            $service = $this->loadService($serviceId, (int) $user['id']);
            $this->assertServiceMutationAllowed($service, false);
            $payload = ForwardLineApiContract::normalizeCreate($this->jsonPayload($request));
            $idempotencyKey = $this->idempotencyKey($request);
            $idempotencyKeyHash = hash('sha256', $idempotencyKey);
            $requestHash = ForwardLineApiContract::requestHash($payload);

            $existing = $this->findIdempotentForward($serviceId, $idempotencyKeyHash);
            if ($existing !== null) {
                return $this->replayOrConflict($existing, $requestHash, (int) $user['id']);
            }

            $forwardLimit = $this->provisioning->forwardLimitForService($serviceId);
            if ($this->activeForwardCount($serviceId) >= $forwardLimit) {
                throw new ApiException('forward_quota_exceeded', 409);
            }

            try {
                $runtime = $this->provisioning->createForward(
                    $serviceId,
                    $payload,
                    'user_api:' . (int) $user['id'],
                    $idempotencyKeyHash,
                    $requestHash,
                    $forwardLimit,
                    ['user_id' => (int) $user['id'], 'feature' => 'allow_manage_forwards']
                );
            } catch (ApiException $exception) {
                throw $exception;
            } catch (Throwable $throwable) {
                $existing = $this->findIdempotentForward($serviceId, $idempotencyKeyHash);
                if ($existing !== null) {
                    return $this->replayOrConflict($existing, $requestHash, (int) $user['id']);
                }
                throw $throwable;
            }

            $forwardId = (int) ($runtime['forward_id'] ?? 0);
            if ($forwardId <= 0) {
                $existing = $this->findIdempotentForward($serviceId, $idempotencyKeyHash);
                if ($existing !== null) {
                    return $this->replayOrConflict($existing, $requestHash, (int) $user['id']);
                }
                throw new ApiException('operation_failed', 500);
            }
            $forward = $this->loadOwnedForward($forwardId, (int) $user['id']);
            return $this->resourceResponse(
                ['ok' => true, 'forward' => ForwardLineApiContract::presentForward($forward)],
                ForwardLineApiContract::etag($forward),
                201
            )->withHeaders(['Location' => '/api/v1/user/forwards/' . $forwardId]);
        });
    }

    public function patch(Request $request, array $params): Response
    {
        return $this->run(function () use ($request, $params): Response {
            $user = $this->authenticateTenant($request);
            $forwardId = $this->pathId($params, 'forward_not_found');
            $forward = $this->loadOwnedForward($forwardId, (int) $user['id']);
            $service = $this->loadService((int) $forward['service_id'], (int) $user['id']);
            $this->assertServiceMutationAllowed($service, false);
            $ifMatch = $this->requiredHeader($request, 'if-match', 'precondition_required');
            if (!ForwardLineApiContract::matchesEtag($ifMatch, $forward)) {
                throw new ApiException('forward_version_conflict', 412);
            }

            $changes = ForwardLineApiContract::normalizePatch($this->jsonPayload($request))['changes'];
            $merged = ForwardLineApiContract::mergePatch($forward, $changes);

            $structural = array_key_exists('forward_route_id', $changes)
                || array_key_exists('target_source', $changes)
                || array_key_exists('target_service_id', $changes)
                // Toggling native outbound rewrites the target and placement, so
                // it must take the structural (saveForward) path rather than the
                // narrow non-structural update that ignores native_outbound.
                || array_key_exists('native_outbound', $changes)
                || (array_key_exists('transport_mode', $changes)
                    && (string) $merged['transport_mode'] !== (string) ($forward['transport_mode'] ?? 'tcp'))
                || (array_key_exists('listen_port', $changes) && (int) $changes['listen_port'] === 0);
            if ($structural) {
                $this->provisioning->updateForward(
                    $forwardId,
                    $merged,
                    'user_api:' . (int) $user['id'],
                    (int) ($forward['api_revision'] ?? 1),
                    ['user_id' => (int) $user['id'], 'feature' => 'allow_manage_forwards']
                );
            } else {
                $this->provisioning->updateForwardNonStructural(
                    $forwardId,
                    $merged,
                    'user_api:' . (int) $user['id'],
                    (int) ($forward['api_revision'] ?? 1),
                    ['user_id' => (int) $user['id'], 'feature' => 'allow_manage_forwards']
                );
            }

            $updated = $this->loadOwnedForward($forwardId, (int) $user['id']);
            return $this->resourceResponse(
                ['ok' => true, 'forward' => ForwardLineApiContract::presentForward($updated)],
                ForwardLineApiContract::etag($updated)
            );
        });
    }

    public function delete(Request $request, array $params): Response
    {
        return $this->run(function () use ($request, $params): Response {
            $user = $this->authenticateTenant($request);
            $forwardId = $this->pathId($params, 'forward_not_found');
            $forward = $this->loadOwnedForward($forwardId, (int) $user['id']);
            $service = $this->loadService((int) $forward['service_id'], (int) $user['id']);
            $this->assertServiceMutationAllowed($service, true);
            $ifMatch = $this->requiredHeader($request, 'if-match', 'precondition_required');
            if (!ForwardLineApiContract::matchesEtag($ifMatch, $forward)) {
                throw new ApiException('forward_version_conflict', 412);
            }

            $this->provisioning->deleteForward(
                $forwardId,
                'user_api:' . (int) $user['id'],
                (int) ($forward['api_revision'] ?? 1),
                ['user_id' => (int) $user['id'], 'feature' => 'allow_delete_forwards']
            );
            return new Response(204, [
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ], '');
        });
    }

    private function authenticateTenant(Request $request): array
    {
        $user = Auth::requireUser($request, $this->db);
        if ((string) ($user['role'] ?? '') !== 'tenant' || (string) ($user['status'] ?? '') !== 'active') {
            throw new ApiException('user_auth_failed', 401);
        }
        return $user;
    }

    /** @param array<string, mixed> $service */
    private function assertServiceMutationAllowed(array $service, bool $delete): void
    {
        // Use the effective status: services.status is never written 'expired',
        // so a raw check let a service past expire_at keep managing forwards.
        if (\Leikwan\Master\Support\ServiceStatus::effective($service) !== 'active') {
            throw new ApiException('service_inactive', 409);
        }
        $features = $this->effectiveFeatures($service);
        $key = $delete ? 'allow_delete_forwards' : 'allow_manage_forwards';
        if (empty($features[$key])) {
            throw new ApiException($delete ? 'feature_denied_delete_forwards' : 'feature_denied_manage_forwards', 403);
        }
    }

    /** @return array<string, mixed> */
    private function jsonPayload(Request $request): array
    {
        $contentType = strtolower(trim((string) ($request->header('content-type') ?? '')));
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($mediaType !== 'application/json') {
            throw new ApiException('unsupported_media_type', 415);
        }
        if (strlen($request->rawBody()) > 16 * 1024) {
            throw new ApiException('request_too_large', 413);
        }
        if ($request->rawBody() !== '') {
            $decoded = json_decode($request->rawBody(), true);
            if (!is_array($decoded)) {
                throw new ApiException('validation_failed', 422, ['fields' => ['body' => 'json_object_required']]);
            }
            return $decoded;
        }
        return $request->body();
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $this->requiredHeader($request, 'idempotency-key', 'idempotency_key_required');
        if (preg_match('/\A[A-Za-z0-9._:-]{8,64}\z/D', $key) !== 1) {
            throw new ApiException('idempotency_key_invalid', 422);
        }
        return $key;
    }

    private function requiredHeader(Request $request, string $name, string $errorCode): string
    {
        $value = trim((string) ($request->header($name) ?? ''));
        if ($value === '') {
            throw new ApiException($errorCode, 428);
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private function loadService(int $serviceId, int $userId): array
    {
        $service = $this->db->fetchOne(
            'SELECT s.*, u.feature_flags_json AS user_feature_flags_json,
                    p.name AS plan_name, p.feature_flags_json AS plan_feature_flags_json
             FROM services s
             INNER JOIN users u ON u.id = s.user_id
             LEFT JOIN service_plans p ON p.id = s.plan_id
             WHERE s.id = :id AND s.user_id = :user_id LIMIT 1',
            ['id' => $serviceId, 'user_id' => $userId]
        );
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }
        return $service;
    }

    /** @return array<string, mixed> */
    private function loadOwnedForward(int $forwardId, int $userId, bool $activeOnly = true): array
    {
        $forward = $this->db->fetchOne(
            'SELECT f.*, s.user_id, s.status AS service_status,
                    fr.tenant_label AS route_label,
                    (SELECT COUNT(*)
                     FROM service_endpoints se
                     WHERE se.forward_id = f.id
                       AND se.health_status = :active_endpoint_status) AS active_endpoint_count
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             LEFT JOIN forward_routes fr ON fr.id = f.forward_route_id
             WHERE f.id = :id AND s.user_id = :user_id' . ($activeOnly ? ' AND f.enabled = 1' : '') . ' LIMIT 1',
            ['id' => $forwardId, 'user_id' => $userId, 'active_endpoint_status' => 'active']
        );
        if ($forward === null) {
            throw new ApiException('forward_not_found', 404);
        }
        return $forward;
    }

    /** @return array<string, mixed>|null */
    private function findIdempotentForward(int $serviceId, string $keyHash): ?array
    {
        return $this->db->fetchOne(
            'SELECT f.*, fr.tenant_label AS route_label
             FROM forwards f
             LEFT JOIN forward_routes fr ON fr.id = f.forward_route_id
             WHERE f.service_id = :service_id
               AND f.api_idempotency_key_hash = :key_hash
             LIMIT 1',
            ['service_id' => $serviceId, 'key_hash' => $keyHash]
        );
    }

    private function replayOrConflict(array $forward, string $requestHash, int $userId): Response
    {
        if (!hash_equals((string) ($forward['api_request_hash'] ?? ''), $requestHash)) {
            throw new ApiException('idempotency_conflict', 409);
        }
        if ((int) ($forward['enabled'] ?? 0) !== 1) {
            throw new ApiException('idempotency_resource_inactive', 409);
        }
        $owned = $this->loadOwnedForward((int) $forward['id'], $userId, false);
        // Content-Location, not Location: under php-fpm a 200 carries no
        // Status line, and nginx turns any FastCGI response with a Location
        // header but no Status into 302 Found, so the documented 200 replay
        // reached tenants as a redirect (ticket ZZN-514526).
        return $this->resourceResponse(
            ['ok' => true, 'forward' => ForwardLineApiContract::presentForward($owned)],
            ForwardLineApiContract::etag($owned),
            200
        )->withHeaders(['Content-Location' => '/api/v1/user/forwards/' . (int) $owned['id']]);
    }

    private function pathId(array $params, string $errorCode): int
    {
        $raw = $params['id'] ?? '';
        if (!is_string($raw) || preg_match('/\A[1-9][0-9]*\z/D', $raw) !== 1) {
            throw new ApiException($errorCode, 404);
        }
        return (int) $raw;
    }

    /** @return array<string, bool> */
    private function effectiveFeatures(array $service): array
    {
        if (is_array($service['effective_features'] ?? null)) {
            return $service['effective_features'];
        }
        if (method_exists($this->provisioning, 'effectiveFeatureFlagsForService')) {
            return $this->provisioning->effectiveFeatureFlagsForService($service);
        }
        return [
            'allow_manage_forwards' => true,
            'allow_delete_forwards' => true,
        ];
    }

    private function activeForwardCount(int $serviceId): int
    {
        $rows = $this->db->fetchAll(
            'SELECT COUNT(*) AS active_count
             FROM forwards
             WHERE service_id = :service_id AND enabled = 1',
            ['service_id' => $serviceId]
        );
        return max(0, (int) ($rows[0]['active_count'] ?? 0));
    }

    /** @param array<string, mixed> $payload */
    private function success(array $payload, int $status = 200): Response
    {
        return Response::json($status, $payload)->withHeaders([
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function resourceResponse(array $payload, string $etag, int $status = 200): Response
    {
        return $this->success($payload, $status)->withHeaders(['ETag' => $etag]);
    }

    /** @param callable(): Response $callback */
    private function run(callable $callback): Response
    {
        try {
            return $callback();
        } catch (ApiException $exception) {
            return $this->errorResponse($exception);
        } catch (Throwable $throwable) {
            error_log('forward v1 API operation failed: ' . $throwable::class);
            return $this->success([
                'ok' => false,
                'error' => [
                    'code' => 'operation_failed',
                    'message' => 'The forward operation failed.',
                ],
            ], 500);
        }
    }

    private function errorResponse(ApiException $exception): Response
    {
        $internalCode = $exception->getMessage();
        $aliases = [
            'leigod_route_no_healthy_candidate' => 'route_unavailable',
            'no_available_nodes' => 'route_unavailable',
            'forward_route_combination_mismatch' => 'route_unavailable',
            'invalid_forward_payload' => 'validation_failed',
            'invalid_forward_target_source' => 'validation_failed',
            'target_service_not_found' => 'validation_failed',
            'target_service_not_allowed' => 'validation_failed',
            'target_service_ip_missing' => 'validation_failed',
            // Internal placement/combination constraints resolved server-side.
            // Map to stable public codes so tenants get a 409/422 instead of an
            // opaque 500, without leaking internal node/sidecar topology names.
            'forward_combination_not_found' => 'route_unavailable',
            'forward_combination_not_allowed' => 'route_unavailable',
            'node_group_not_allowed' => 'forward_route_not_allowed',
            'line_client_required' => 'route_unavailable',
            'line_client_not_allowed' => 'route_unavailable',
            'line_client_group_not_allowed' => 'route_unavailable',
            'ultra_reprovision_unavailable' => 'route_unavailable',
            'shared_sidecar_public_port_forbidden' => 'route_unavailable',
            'sidecar_must_be_overseas_for_aggregation' => 'route_unavailable',
            'invalid_forward_combination_client_mode' => 'route_unavailable',
            'endpoint_mode_not_supported_yet' => 'route_unavailable',
            'native_target_port_unavailable' => 'route_unavailable',
        ];
        $code = $aliases[$internalCode] ?? $internalCode;
        $statuses = [
            'user_auth_failed' => 401,
            'service_not_found' => 404,
            'forward_not_found' => 404,
            'validation_failed' => 422,
            'unknown_fields' => 422,
            'service_inactive' => 409,
            'forward_quota_exceeded' => 409,
            'forward_storage_quota_exceeded' => 409,
            'idempotency_conflict' => 409,
            'idempotency_resource_inactive' => 409,
            'route_unavailable' => 409,
            'forward_version_conflict' => 412,
            'request_too_large' => 413,
            'unsupported_media_type' => 415,
            'idempotency_key_invalid' => 422,
            'forward_route_not_allowed' => 422,
            'forward_route_has_no_enabled_combination' => 422,
            'forward_target_not_allowed' => 422,
            'idempotency_key_required' => 428,
            'precondition_required' => 428,
            'feature_denied_manage_forwards' => 403,
            'feature_denied_delete_forwards' => 403,
            // Native outbound codes (see docs/api/native-outbound.md). Without
            // these the native path's exceptions collapsed to 500.
            'native_outbound_not_allowed' => 403,
            'native_outbound_requires_ultra_tcp_udp' => 422,
            'native_outbound_direction_missing' => 422,
            'native_direction_conflict' => 409,
            'operation_failed' => 500,
        ];
        if (!array_key_exists($code, $statuses)) {
            $safeInternalCode = preg_replace('/[^A-Za-z0-9_.:-]/', '?', substr($internalCode, 0, 80));
            error_log('forward v1 API internal error code: ' . $safeInternalCode);
            $code = 'operation_failed';
        }
        $messages = [
            'user_auth_failed' => 'Authentication failed.',
            'service_not_found' => 'Service not found.',
            'forward_not_found' => 'Forward not found.',
            'validation_failed' => 'The forward request is invalid.',
            'unknown_fields' => 'The forward request contains unknown fields.',
            'route_unavailable' => 'The selected route is currently unavailable.',
            'forward_target_not_allowed' => 'The selected route only accepts target IP addresses on its allowlist.',
        ];
        $error = [
            'code' => $code,
            'message' => $messages[$code] ?? 'The forward operation could not be completed.',
        ];
        $details = $exception->details();
        $fields = is_array($details['fields'] ?? null)
            ? $details['fields']
            : ForwardLineApiContract::fieldsForInternalCode($internalCode);
        if (in_array($code, ['validation_failed', 'unknown_fields'], true) && is_array($fields)) {
            $error['fields'] = $this->safeFields($fields);
        }

        return $this->success(['ok' => false, 'error' => $error], $statuses[$code]);
    }

    /** @return array<string, string>|list<string> */
    private function safeFields(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }
        if (array_is_list($fields)) {
            return array_values(array_filter(array_map(
                static fn(mixed $field): ?string => is_string($field) ? $field : null,
                $fields
            ), static fn(?string $field): bool => $field !== null));
        }
        $safe = [];
        foreach ($fields as $field => $reason) {
            if (is_string($field) && is_string($reason)) {
                $safe[$field] = $reason;
            }
        }
        return $safe;
    }
}
