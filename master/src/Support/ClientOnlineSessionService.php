<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;

final class ClientOnlineSessionService
{
    public const ACTIVE_WINDOW_SECONDS = 180;
    public const LIMIT_REACHED_ERROR = 'client_online_limit_reached';
    // Fallback ids derived from user-agent|ip when a client sends no device id.
    // They change whenever the IP changes, so one machine can appear as several
    // devices; they are neither counted toward nor refused by the online limit.
    private const LEGACY_DEVICE_PREFIX = 'legacy-';

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
        $this->enforceOnlineLimit($client, $clientId, $deviceId, $existing);

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

    /**
     * Refuse a device that is not already online when the client's online
     * device limit is full. Devices that are already online are never kicked,
     * even if the limit was lowered after they connected.
     *
     * @param array<string, mixed> $client
     */
    public function assertCanComeOnline(array $client, string $deviceId): void
    {
        $clientId = (int) ($client['id'] ?? 0);
        $deviceId = trim($deviceId);
        if ($clientId <= 0 || $deviceId === '') {
            return;
        }
        $existing = $this->db->fetchOne(
            'SELECT * FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id LIMIT 1',
            [
                'client_id' => $clientId,
                'device_id' => $deviceId,
            ]
        );
        $this->enforceOnlineLimit($client, $clientId, $deviceId, $existing);
    }

    /**
     * @param array<string, mixed> $client
     * @param array<string, mixed>|null $existing
     */
    private function enforceOnlineLimit(array $client, int $clientId, string $deviceId, ?array $existing): void
    {
        if (str_starts_with($deviceId, self::LEGACY_DEVICE_PREFIX)) {
            return;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::ACTIVE_WINDOW_SECONDS);
        if ($existing !== null && (string) ($existing['last_seen_at'] ?? '') >= $cutoff) {
            return;
        }

        $limit = max(1, (int) ($client['online_device_limit'] ?? 1));
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total
             FROM client_device_sessions
             WHERE client_id = :client_id
               AND last_seen_at >= :cutoff
               AND device_id <> :device_id
               AND device_id NOT LIKE :legacy_prefix',
            [
                'client_id' => $clientId,
                'cutoff' => $cutoff,
                'device_id' => $deviceId,
                'legacy_prefix' => self::LEGACY_DEVICE_PREFIX . '%',
            ]
        );
        $activeCount = max(0, (int) ($row['total'] ?? 0));
        if ($activeCount >= $limit) {
            throw new ApiException(self::LIMIT_REACHED_ERROR, 429, [
                'online_device_limit' => $limit,
                'active_online_devices' => $activeCount,
            ]);
        }
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

    public function revokeAllForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $clients = $this->db->fetchAll(
            'SELECT id FROM clients WHERE owner_user_id = :owner_user_id',
            ['owner_user_id' => $userId]
        );
        $now = gmdate('Y-m-d H:i:s');
        $revoked = 0;
        foreach ($clients as $client) {
            $clientId = (int) ($client['id'] ?? 0);
            if ($clientId <= 0) {
                continue;
            }
            $revoked += $this->db->execute(
                'UPDATE forward_combination_self_leg_donors
                 SET state = :state, revoked_at = :revoked_at, revoke_reason = :revoke_reason, updated_at = :updated_at
                 WHERE client_id = :client_id AND state IN (:active_state, :qualifying_state)',
                [
                    'client_id' => $clientId,
                    'state' => 'revoked',
                    'active_state' => 'active',
                    'qualifying_state' => 'qualifying',
                    'revoked_at' => $now,
                    'revoke_reason' => 'account_device_reset',
                    'updated_at' => $now,
                ]
            );
            $revoked += $this->db->execute(
                'UPDATE android_device_sessions SET revoked_at = :revoked_at
                 WHERE client_id = :client_id AND revoked_at IS NULL',
                ['client_id' => $clientId, 'revoked_at' => $now]
            );
            $revoked += $this->revokeAllForClient($clientId);
        }
        $revoked += $this->db->execute(
            'DELETE FROM desktop_client_sessions WHERE user_id = :user_id',
            ['user_id' => $userId]
        );
        return $revoked;
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
        $deviceId = '';
        foreach ([
            $request->header('x-device-id', ''),
            $request->header('x-device-uuid', ''),
            $request->input('device_id', ''),
            $request->input('device_uuid', ''),
        ] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            // Security: `legacy-` is the server's reserved namespace for
            // synthesized device ids, which are exempt from the online-device
            // limit. A client must not be able to self-assign into it (e.g.
            // `X-Device-Id: legacy-<random>`) to mint unlimited exempt devices.
            // Ignore such values and fall through to the server-derived id,
            // which is bounded to the caller's UA+IP.
            if (str_starts_with(strtolower($candidate), self::LEGACY_DEVICE_PREFIX)) {
                continue;
            }
            if ($deviceId !== '' && !hash_equals($deviceId, $candidate)) {
                throw new ApiException('device_id_invalid', 422);
            }
            $deviceId = $candidate;
        }
        if ($deviceId !== '') {
            return $deviceId;
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
