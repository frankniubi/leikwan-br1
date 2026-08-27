<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;

final class ClientOnlineSessionService
{
    public const ACTIVE_WINDOW_SECONDS = 180;

    private TrustedClientIpResolver $clientIpResolver;

    public function __construct(private Database $db, ?TrustedClientIpResolver $clientIpResolver = null)
    {
        $this->clientIpResolver = $clientIpResolver ?? new TrustedClientIpResolver();
    }

    /**
     * @param array<string, mixed> $client
     */
    public function enforceFromRequest(array $client, Request $request, string $touchType = 'pull'): string
    {
        $deviceId = $this->deviceIdFromRequest($request);
        $this->recordAndEnforce($client, $deviceId, $this->deviceMetadataFromRequest($request), $touchType);
        return $deviceId;
    }

    /**
     * @param array<string, mixed> $client
     * @param array<string, mixed> $metadata
     */
    public function recordAndEnforce(array $client, string $deviceId, array $metadata = [], string $touchType = 'pull'): string
    {
        $clientId = (int) ($client['id'] ?? 0);
        if ($clientId <= 0) {
            throw new ApiException('client_not_found', 404);
        }

        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            $deviceId = 'legacy-unknown-device';
        }

        $now = gmdate('Y-m-d H:i:s');
        $existing = $this->db->fetchOne(
            'SELECT * FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id LIMIT 1',
            [
                'client_id' => $clientId,
                'device_id' => $deviceId,
            ]
        );

        if ($existing === null) {
            $this->db->insert('client_device_sessions', [
                'client_id' => $clientId,
                'device_id' => $deviceId,
                'device_name' => $this->trimOrNull($metadata['device_name'] ?? null),
                'platform' => $this->trimOrNull($metadata['platform'] ?? null),
                'client_version' => $this->trimOrNull($metadata['client_version'] ?? null),
                'os_release' => $this->trimOrNull($metadata['os_release'] ?? null),
                'user_agent' => $this->trimOrNull($metadata['user_agent'] ?? null),
                'last_ip' => $this->trimOrNull($metadata['last_ip'] ?? null),
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'last_pull_at' => $touchType === 'pull' ? $now : null,
                'last_heartbeat_at' => $touchType === 'heartbeat' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $deviceId;
        }

        $this->db->execute(
            'UPDATE client_device_sessions
             SET device_name = :device_name,
                 platform = :platform,
                 client_version = :client_version,
                 os_release = :os_release,
                 user_agent = :user_agent,
                 last_ip = :last_ip,
                 last_seen_at = :last_seen_at,
                 last_pull_at = :last_pull_at,
                 last_heartbeat_at = :last_heartbeat_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $existing['id'],
                'device_name' => $this->trimOrNull($metadata['device_name'] ?? ($existing['device_name'] ?? null)),
                'platform' => $this->trimOrNull($metadata['platform'] ?? ($existing['platform'] ?? null)),
                'client_version' => $this->trimOrNull($metadata['client_version'] ?? ($existing['client_version'] ?? null)),
                'os_release' => $this->trimOrNull($metadata['os_release'] ?? ($existing['os_release'] ?? null)),
                'user_agent' => $this->trimOrNull($metadata['user_agent'] ?? ($existing['user_agent'] ?? null)),
                'last_ip' => $this->trimOrNull($metadata['last_ip'] ?? ($existing['last_ip'] ?? null)),
                'last_seen_at' => $now,
                'last_pull_at' => $touchType === 'pull' ? $now : ($existing['last_pull_at'] ?? null),
                'last_heartbeat_at' => $touchType === 'heartbeat' ? $now : ($existing['last_heartbeat_at'] ?? null),
                'updated_at' => $now,
            ]
        );

        return $deviceId;
    }

    public function activeCountForClient(int $clientId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total
             FROM client_device_sessions
             WHERE client_id = :client_id
               AND last_seen_at >= :cutoff',
            [
                'client_id' => $clientId,
                'cutoff' => gmdate('Y-m-d H:i:s', time() - self::ACTIVE_WINDOW_SECONDS),
            ]
        );

        return max(0, (int) ($row['total'] ?? 0));
    }

    public function revokeAllForClient(int $clientId): int
    {
        if ($clientId <= 0) {
            return 0;
        }

        return $this->db->execute(
            'DELETE FROM client_device_sessions WHERE client_id = :client_id',
            ['client_id' => $clientId]
        );
    }

    public function revokeDevice(int $clientId, string $deviceId): int
    {
        $deviceId = trim($deviceId);
        if ($clientId <= 0 || $deviceId === '') {
            return 0;
        }

        return $this->db->execute(
            'DELETE FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id',
            ['client_id' => $clientId, 'device_id' => $deviceId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeSessionsForClient(int $clientId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM client_device_sessions WHERE client_id = :client_id',
            ['client_id' => $clientId]
        );
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::ACTIVE_WINDOW_SECONDS);
        $rows = array_values(array_filter($rows, static function (array $row) use ($cutoff): bool {
            return (string) ($row['last_seen_at'] ?? '') >= $cutoff;
        }));

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string) ($right['last_seen_at'] ?? ''), (string) ($left['last_seen_at'] ?? ''));
        });

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeSessionForClientAndDevice(int $clientId, string $deviceId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id LIMIT 1',
            [
                'client_id' => $clientId,
                'device_id' => trim($deviceId),
            ]
        );
        if ($row === null) {
            return null;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::ACTIVE_WINDOW_SECONDS);
        return (string) ($row['last_seen_at'] ?? '') >= $cutoff ? $row : null;
    }

    public function deviceIdFromRequest(Request $request): string
    {
        $header = trim((string) ($request->header('x-device-id') ?? ''));
        if ($header !== '') {
            return $header;
        }

        $body = trim((string) $request->input('device_id', ''));
        if ($body !== '') {
            return $body;
        }

        $userAgent = trim((string) ($request->header('user-agent') ?? 'unknown'));
        $ip = $this->requestIp($request) ?: 'unknown';
        return 'legacy-' . sha1($userAgent . '|' . $ip);
    }

    /**
     * @return array<string, mixed>
     */
    public function deviceMetadataFromRequest(Request $request): array
    {
        $userAgent = trim((string) ($request->header('user-agent') ?? ''));
        $platform = trim((string) ($request->header('x-device-platform') ?? (string) $request->input('platform', '')));
        $deviceName = trim((string) ($request->header('x-device-name') ?? (string) $request->input('device_name', '')));
        $osRelease = trim((string) $request->input('os_release', $request->input('kernel_release', '')));

        if ($deviceName === '' && $userAgent !== '') {
            $deviceName = $userAgent;
        }

        return [
            'device_name' => $deviceName,
            'platform' => $platform !== '' ? $platform : 'unknown',
            'client_version' => $this->clientVersionFromRequest($request, $userAgent),
            'os_release' => $osRelease !== '' ? $osRelease : null,
            'user_agent' => $userAgent !== '' ? $userAgent : null,
            'last_ip' => $this->requestIp($request),
        ];
    }

    private function clientVersionFromRequest(Request $request, string $userAgent): ?string
    {
        $version = trim((string) ($request->header('x-client-version') ?? (string) $request->input('agent_version', '')));
        if ($version !== '') {
            return $version;
        }
        if ($userAgent !== '' && preg_match('/Leikwan-Client-Agent\/([A-Za-z0-9._-]+)/', $userAgent, $matches) === 1) {
            return $matches[1];
        }
        return null;
    }

    private function requestIp(Request $request): ?string
    {
        return $this->clientIpResolver->resolve($request);
    }

    private function trimOrNull(mixed $value): ?string
    {
        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }
}
