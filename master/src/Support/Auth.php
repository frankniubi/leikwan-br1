<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;

final class Auth
{
    /**
     * @return array<string, mixed>
     */
    public static function requireAdmin(Request $request, Config $config): array
    {
        $token = $request->bearerToken() ?? (string) $request->input('token', '');
        $expected = (string) $config->get('MASTER_ADMIN_TOKEN', '');
        if ($token === '' || !hash_equals($expected, $token)) {
            throw new ApiException('admin_auth_failed', 401);
        }

        return ['role' => 'admin'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireWhmcsWebhook(Request $request, Config $config): array
    {
        $token = $request->header('x-whmcs-webhook-token') ?? $request->bearerToken() ?? '';
        $expected = (string) $config->get('WHMCS_WEBHOOK_TOKEN', '');
        if ($token === '' || !hash_equals($expected, $token)) {
            throw new ApiException('whmcs_auth_failed', 401);
        }

        return ['source' => 'whmcs'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireUser(Request $request, Database $db): array
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            throw new ApiException('user_auth_failed', 401);
        }

        $user = $db->fetchOne('SELECT * FROM users WHERE api_token = :token AND status = :status LIMIT 1', [
            'token' => $token,
            'status' => 'active',
        ]);

        if ($user === null) {
            throw new ApiException('user_auth_failed', 401);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireNode(Request $request, Database $db): array
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            throw new ApiException('node_auth_failed', 401);
        }

        $node = $db->fetchOne(
            'SELECT * FROM nodes WHERE api_token = :token AND (status = :active OR status = :degraded) LIMIT 1',
            [
                'token' => $token,
                'active' => 'active',
                'degraded' => 'degraded',
            ]
        );

        if ($node === null) {
            throw new ApiException('node_auth_failed', 401);
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireClientToken(Request $request, Database $db, int $forwardId): array
    {
        $token = $request->bearerToken() ?? (string) $request->input('token', '');
        if ($token === '') {
            throw new ApiException('client_token_missing', 401);
        }

        $row = $db->fetchOne(
            'SELECT s.*, f.id AS forward_id
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.id = :forward_id AND s.client_token = :token LIMIT 1',
            [
                'forward_id' => $forwardId,
                'token' => $token,
            ]
        );

        if ($row === null) {
            throw new ApiException('client_auth_failed', 401);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireClientAgent(Request $request, Database $db): array
    {
        $token = $request->bearerToken() ?? (string) $request->input('token', '');
        if ($token === '') {
            throw new ApiException('client_agent_auth_failed', 401);
        }

        $client = $db->fetchOne('SELECT * FROM clients WHERE agent_token = :token LIMIT 1', [
            'token' => $token,
        ]);

        if ($client === null) {
            throw new ApiException('client_agent_auth_failed', 401);
        }

        (new ClientOnlineSessionService($db))->enforceFromRequest($client, $request);

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireSidecarPointAgent(Request $request, Database $db): array
    {
        $token = $request->bearerToken() ?? (string) $request->input('token', '');
        if ($token === '') {
            throw new ApiException('sidecar_agent_auth_failed', 401);
        }

        $point = $db->fetchOne('SELECT * FROM sidecar_points WHERE agent_token = :token LIMIT 1', [
            'token' => $token,
        ]);

        if ($point === null) {
            throw new ApiException('sidecar_agent_auth_failed', 401);
        }

        return $point;
    }
}
