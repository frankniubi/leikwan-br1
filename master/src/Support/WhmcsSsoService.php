<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;

/**
 * One-time sign-on links for the WHMCS server module (ServiceSingleSignOn).
 *
 * WHMCS asks for a link server-to-server with the webhook token; the browser
 * then opens /auth/whmcs/sso?token=... within TOKEN_TTL_SECONDS. Only the
 * SHA-256 of the token is stored and consumption is a single conditional
 * UPDATE, so a token signs in at most once.
 */
final class WhmcsSsoService
{
    public const TOKEN_TTL_SECONDS = 60;

    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{token: string, user_id: int, service_id: int, redirect_path: string, expires_in: int}
     */
    public function issue(array $payload): array
    {
        $whmcsServiceId = (int) ($payload['whmcs_service_id'] ?? 0);
        $whmcsClientId = (int) ($payload['whmcs_client_id'] ?? 0);
        if ($whmcsServiceId <= 0 || $whmcsClientId <= 0) {
            throw new ApiException('missing_required_field', 422, ['field' => 'whmcs_service_id/whmcs_client_id']);
        }

        $service = $this->db->fetchOne('SELECT id, user_id, status FROM services WHERE whmcs_service_id = :id LIMIT 1', ['id' => $whmcsServiceId]);
        if ($service === null || (string) ($service['status'] ?? '') === 'terminated') {
            throw new ApiException('service_not_found', 404, ['whmcs_service_id' => $whmcsServiceId]);
        }
        $user = $this->db->fetchOne('SELECT id, role, status, whmcs_client_id FROM users WHERE id = :id LIMIT 1', ['id' => $service['user_id']]);
        if (
            $user === null
            || (string) ($user['role'] ?? '') !== 'tenant'
            || (string) ($user['status'] ?? '') !== 'active'
            || (int) ($user['whmcs_client_id'] ?? 0) !== $whmcsClientId
        ) {
            // Never sign a WHMCS client into a panel account it is not bound to.
            throw new ApiException('whmcs_sso_not_allowed', 403);
        }

        $serviceId = (int) $service['id'];
        $redirectPath = $this->localPath((string) ($payload['redirect_path'] ?? ''), '/tenant/services/' . $serviceId);
        $token = bin2hex(random_bytes(32));
        $now = gmdate('Y-m-d H:i:s');
        $this->db->execute('DELETE FROM whmcs_sso_tokens WHERE expires_at < :now', ['now' => $now]);
        $this->db->insert('whmcs_sso_tokens', [
            'token_hash' => hash('sha256', $token),
            'user_id' => (int) $user['id'],
            'service_id' => $serviceId,
            'redirect_path' => $redirectPath,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS),
            'used_at' => null,
            'created_at' => $now,
        ]);

        return [
            'token' => $token,
            'user_id' => (int) $user['id'],
            'service_id' => $serviceId,
            'redirect_path' => $redirectPath,
            'expires_in' => self::TOKEN_TTL_SECONDS,
        ];
    }

    /**
     * @return array{user: array<string, mixed>, redirect_path: string}|null
     */
    public function consume(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);
        $now = gmdate('Y-m-d H:i:s');
        $claimed = $this->db->execute(
            'UPDATE whmcs_sso_tokens SET used_at = :now WHERE token_hash = :hash AND used_at IS NULL AND expires_at >= :now2',
            ['now' => $now, 'now2' => $now, 'hash' => $hash]
        );
        if ($claimed !== 1) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT user_id, redirect_path FROM whmcs_sso_tokens WHERE token_hash = :hash LIMIT 1', ['hash' => $hash]);
        $user = $row === null ? null : $this->db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $row['user_id']]);
        if ($user === null || (string) ($user['role'] ?? '') !== 'tenant' || (string) ($user['status'] ?? '') !== 'active') {
            return null;
        }

        return ['user' => $user, 'redirect_path' => $this->localPath((string) $row['redirect_path'], '/tenant')];
    }

    private function localPath(string $path, string $fallback): string
    {
        $path = trim($path);
        if (
            $path === ''
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\r\n]/', $path) === 1
        ) {
            return $fallback;
        }

        return $path;
    }
}
