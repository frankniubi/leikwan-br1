<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\Auth;
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
        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id AND user_id = :user_id LIMIT 1', [
            'id' => $serviceId,
            'user_id' => $user['id'],
        ]);
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }

        return $this->json([
            'ok' => true,
            'service' => $this->provisioning->serviceSummary($serviceId),
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

        $payload = $this->normalizeForwardPayload($request->body());

        return $this->json([
            'ok' => true,
            'client_config' => $this->provisioning->createForward($serviceId, $payload, 'user'),
        ], 201);
    }

    public function updateForward(Request $request, array $params): Response
    {
        $user = Auth::requireUser($request, $this->db);
        $forwardId = $this->idParam($params);
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

        $payload = $this->normalizeForwardNonStructuralPayload($request->body());

        return $this->json([
            'ok' => true,
            'client_config' => $this->provisioning->updateForwardNonStructural($forwardId, $payload, 'user'),
        ]);
    }

    public function deleteForward(Request $request, array $params): Response
    {
        $user = Auth::requireUser($request, $this->db);
        $forwardId = $this->idParam($params);
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

        $this->provisioning->deleteForward($forwardId, 'user');
        return $this->json(['ok' => true]);
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeForwardPayload(array $payload): array
    {
        unset($payload['endpoint_count']);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeForwardNonStructuralPayload(array $payload): array
    {
        return [
            'listen_port' => max(0, (int) ($payload['listen_port'] ?? 0)),
            'target_host' => trim((string) ($payload['target_host'] ?? '')),
            'target_port' => max(0, (int) ($payload['target_port'] ?? 0)),
            'transport_mode' => match (strtolower(trim((string) ($payload['transport_mode'] ?? 'tcp')))) {
                'udp' => 'udp',
                'tcp+udp', 'udp+tcp', 'both' => 'tcp+udp',
                default => 'tcp',
            },
        ];
    }
}
