<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;

/**
 * Persistence boundary for the distribution-neutral access policy protocol.
 * Keeping SQL here lets future clients use the same challenge, observation,
 * and decision contract without coupling the policy to a native client.
 */
class AccessPolicyStore
{
    public function __construct(protected Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function userById(int $userId, bool $forUpdate = false): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $sql = 'SELECT * FROM users WHERE id = :id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        return $this->db->fetchOne($sql, ['id' => $userId]);
    }

    public function geoLockEnabledForUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $row = $this->db->fetchOne(
            'SELECT 1 AS enabled
             FROM services s
             INNER JOIN service_plans p ON p.id = s.plan_id
             WHERE s.user_id = :user_id
               AND s.status = :service_status
               AND p.is_active = 1
               AND p.geo_lock_enabled = 1
             LIMIT 1',
            ['user_id' => $userId, 'service_status' => 'active']
        );
        return $row !== null;
    }

    /** @return array<int, array<string, mixed>> */
    public function activeBlacklistRules(?string $entityType = null): array
    {
        $sql = 'SELECT * FROM access_blacklist_rules WHERE is_active = 1';
        $params = [];
        if ($entityType !== null) {
            $sql .= ' AND entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        $sql .= ' ORDER BY entity_type ASC, id ASC';
        return $this->db->fetchAll($sql, $params);
    }

    public function hasActiveGeographicRules(): bool
    {
        $row = $this->db->fetchOne(
            "SELECT 1 AS enabled FROM access_blacklist_rules
            WHERE is_active = 1 AND entity_type IN (
                'province', 'city', 'country',
                'cn_province', 'province_cn', 'mainland_province',
                'cn_city', 'city_cn', 'prefecture', 'prefecture_city',
                'country_region', 'region', 'country_code'
            ) LIMIT 1"
        );
        return $row !== null;
    }

    /** @return array<string, mixed>|null */
    public function controlFor(int $clientId, string $deviceUuid): ?array
    {
        if ($clientId <= 0 || trim($deviceUuid) === '') {
            return null;
        }
        return $this->db->fetchOne(
            'SELECT * FROM client_device_controls
             WHERE client_id = :client_id AND device_id = :device_id LIMIT 1',
            ['client_id' => $clientId, 'device_id' => trim($deviceUuid)]
        );
    }

    /** @return array<string, mixed>|null */
    public function profileForUser(int $userId, bool $forUpdate = false): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $sql = 'SELECT * FROM user_geo_lock_profiles WHERE user_id = :user_id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        return $this->db->fetchOne($sql, ['user_id' => $userId]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createProfile(int $userId, array $data): array
    {
        $now = (string) ($data['created_at'] ?? gmdate('Y-m-d H:i:s'));
        $row = [
            'user_id' => $userId,
            'state' => (string) ($data['state'] ?? 'unbound'),
            'main_province_code' => $data['main_province_code'] ?? null,
            'previous_province_code' => $data['previous_province_code'] ?? null,
            'bound_observation_id' => $data['bound_observation_id'] ?? null,
            'last_verified_at' => $data['last_verified_at'] ?? null,
            'self_change_available_at' => $data['self_change_available_at'] ?? null,
            'reset_generation' => (int) ($data['reset_generation'] ?? 0),
            'reset_started_at' => $data['reset_started_at'] ?? null,
            'created_at' => $now,
            'updated_at' => (string) ($data['updated_at'] ?? $now),
        ];
        $id = $this->db->insert('user_geo_lock_profiles', $row);
        return ['id' => $id] + $row;
    }

    /** @param array<string, mixed> $data */
    public function updateProfile(int $userId, array $data): void
    {
        if ($userId <= 0 || $data === []) {
            return;
        }
        $allowed = [
            'state', 'main_province_code', 'previous_province_code', 'bound_observation_id',
            'last_verified_at', 'self_change_available_at', 'reset_generation', 'reset_started_at',
            'updated_at',
        ];
        $sets = [];
        $params = ['user_id' => $userId];
        foreach ($allowed as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $sets[] = $column . ' = :' . $column;
            $params[$column] = $data[$column];
        }
        if ($sets === []) {
            return;
        }
        $this->db->execute(
            'UPDATE user_geo_lock_profiles SET ' . implode(', ', $sets) . ' WHERE user_id = :user_id',
            $params
        );
    }

    /** @return array<int, array{client_id:int,device_uuid:string}> */
    public function knownDevicesForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT c.id AS client_id, cds.device_id AS device_uuid
             FROM clients c
             INNER JOIN client_device_sessions cds ON cds.client_id = c.id
             WHERE c.owner_user_id = :session_user_id
             UNION
             SELECT c.id AS client_id, cdc.device_id AS device_uuid
             FROM clients c
             INNER JOIN client_device_controls cdc ON cdc.client_id = c.id
             WHERE c.owner_user_id = :control_user_id
             ORDER BY client_id ASC, device_uuid ASC',
            ['session_user_id' => $userId, 'control_user_id' => $userId]
        );
        $devices = [];
        foreach ($rows as $row) {
            $clientId = (int) ($row['client_id'] ?? 0);
            $deviceUuid = trim((string) ($row['device_uuid'] ?? ''));
            if ($clientId > 0 && $deviceUuid !== '') {
                $devices[] = ['client_id' => $clientId, 'device_uuid' => $deviceUuid];
            }
        }
        return $devices;
    }

    /**
     * Return the tenant-safe device view used by the Panel security page.
     * Session metadata and control metadata are merged by the stable
     * `(client_id, device_id)` identity, so offline blocked devices remain
     * visible after their application session has been revoked.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tenantDeviceRows(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $rows = [];
        $sessions = $this->db->fetchAll(
            'SELECT c.id AS client_id,
                    c.name AS client_name,
                    s.device_id,
                    s.platform,
                    s.client_version,
                    s.os_release,
                    s.last_ip,
                    s.last_seen_at
             FROM client_device_sessions s
             INNER JOIN clients c ON c.id = s.client_id
             WHERE c.owner_user_id = :user_id
             ORDER BY c.id ASC, s.device_id ASC',
            ['user_id' => $userId]
        );
        foreach ($sessions as $row) {
            $clientId = (int) ($row['client_id'] ?? 0);
            $deviceId = trim((string) ($row['device_id'] ?? ''));
            if ($clientId <= 0 || $deviceId === '') {
                continue;
            }
            $rows[$clientId . '|' . $deviceId] = [
                'client_id' => $clientId,
                'client_name' => (string) ($row['client_name'] ?? ''),
                'device_uuid' => $deviceId,
                'platform' => (string) ($row['platform'] ?? ''),
                'client_version' => (string) ($row['client_version'] ?? ''),
                'os_release' => (string) ($row['os_release'] ?? ''),
                'last_ip' => (string) ($row['last_ip'] ?? ''),
                'last_seen_at' => $row['last_seen_at'] ?? null,
                'state' => 'active',
                'blocked_until' => null,
                'block_origin' => null,
                'release_policy' => null,
                'reason_code' => null,
            ];
        }

        $controls = $this->db->fetchAll(
            'SELECT c.id AS client_id,
                    c.name AS client_name,
                    cdc.device_id AS device_uuid,
                    cdc.state,
                    cdc.blocked_until,
                    cdc.block_origin,
                    cdc.release_policy,
                    cdc.reason_code,
                    cdc.last_seen_at,
                    cdc.updated_at
             FROM client_device_controls cdc
             INNER JOIN clients c ON c.id = cdc.client_id
             WHERE c.owner_user_id = :user_id
             ORDER BY c.id ASC, cdc.device_id ASC',
            ['user_id' => $userId]
        );
        foreach ($controls as $row) {
            $clientId = (int) ($row['client_id'] ?? 0);
            $deviceId = trim((string) ($row['device_uuid'] ?? ''));
            if ($clientId <= 0 || $deviceId === '') {
                continue;
            }
            $key = $clientId . '|' . $deviceId;
            $rows[$key] = array_merge($rows[$key] ?? [
                'client_id' => $clientId,
                'client_name' => (string) ($row['client_name'] ?? ''),
                'device_uuid' => $deviceId,
                'platform' => '',
                'client_version' => '',
                'os_release' => '',
                'last_ip' => '',
            ], [
                'state' => (string) ($row['state'] ?? 'active'),
                'blocked_until' => $row['blocked_until'] ?? null,
                'block_origin' => $row['block_origin'] ?? null,
                'release_policy' => $row['release_policy'] ?? null,
                'reason_code' => $row['reason_code'] ?? null,
                'last_seen_at' => $row['last_seen_at'] ?? ($rows[$key]['last_seen_at'] ?? null),
                'updated_at' => $row['updated_at'] ?? null,
            ]);
        }

        $result = array_values($rows);
        usort($result, static function (array $left, array $right): int {
            $clientCompare = ((int) ($left['client_id'] ?? 0)) <=> ((int) ($right['client_id'] ?? 0));
            return $clientCompare !== 0
                ? $clientCompare
                : strcmp((string) ($left['device_uuid'] ?? ''), (string) ($right['device_uuid'] ?? ''));
        });
        return $result;
    }

    /** @return array<string, mixed>|null */
    public function activeResetGeneration(int $userId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM access_reset_generations
                WHERE user_id = :user_id AND state = :state
                ORDER BY generation DESC, id DESC LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        return $this->db->fetchOne($sql, ['user_id' => $userId, 'state' => 'pending']);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createResetGeneration(array $data): array
    {
        $row = [
            'user_id' => (int) $data['user_id'],
            'generation' => (int) $data['generation'],
            'previous_province_code' => $data['previous_province_code'] ?? null,
            'state' => (string) ($data['state'] ?? 'pending'),
            'started_at' => (string) $data['started_at'],
            'completed_at' => $data['completed_at'] ?? null,
            'created_by_user_id' => (int) $data['created_by_user_id'],
        ];
        $id = $this->db->insert('access_reset_generations', $row);
        return ['id' => $id] + $row;
    }

    /** @param array<string, mixed> $data */
    public function insertResetDevice(array $data): int
    {
        return $this->db->insert('access_reset_devices', [
            'reset_generation_id' => (int) $data['reset_generation_id'],
            'user_id' => (int) $data['user_id'],
            'client_id' => (int) $data['client_id'],
            'device_uuid' => (string) $data['device_uuid'],
            'blocked_until' => $data['blocked_until'] ?? null,
            'block_created_at' => $data['block_created_at'] ?? null,
            'created_at' => (string) $data['created_at'],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function resetDevicesForGeneration(int $resetGenerationId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM access_reset_devices
             WHERE reset_generation_id = :reset_generation_id
             ORDER BY id ASC',
            ['reset_generation_id' => $resetGenerationId]
        );
    }

    public function completeResetGeneration(int $resetGenerationId, string $completedAt): void
    {
        $this->db->execute(
            'UPDATE access_reset_generations
             SET state = :state, completed_at = :completed_at
             WHERE id = :id AND state = :pending_state',
            [
                'id' => $resetGenerationId,
                'state' => 'completed',
                'pending_state' => 'pending',
                'completed_at' => $completedAt,
            ]
        );
    }

    public function markResetDeviceBlocked(int $resetDeviceId, string $blockedUntil, string $createdAt): void
    {
        $this->db->execute(
            'UPDATE access_reset_devices
             SET blocked_until = :blocked_until, block_created_at = :block_created_at
             WHERE id = :id AND block_created_at IS NULL',
            ['id' => $resetDeviceId, 'blocked_until' => $blockedUntil, 'block_created_at' => $createdAt]
        );
    }

    /** @param array<string, mixed> $data */
    public function insertChallenge(array $data): int
    {
        return $this->db->insert('access_location_challenges', [
            'token_hash' => $data['token_hash'],
            'auth_context_hash' => $data['auth_context_hash'],
            'user_id' => (int) $data['user_id'],
            'client_id' => isset($data['client_id']) ? (int) $data['client_id'] : null,
            'device_uuid' => (string) $data['device_uuid'],
            'attempt_id' => (string) $data['attempt_id'],
            'platform' => (string) $data['platform'],
            'expires_at' => (string) $data['expires_at'],
            'consumed_at' => $data['consumed_at'] ?? null,
            'created_at' => (string) $data['created_at'],
        ]);
    }

    /** @return array<string, mixed>|null */
    public function challengeByHash(string $tokenHash, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM access_location_challenges WHERE token_hash = :token_hash LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        return $this->db->fetchOne($sql, ['token_hash' => $tokenHash]);
    }

    public function consumeChallenge(int $challengeId, string $consumedAt): bool
    {
        return $this->db->execute(
            'UPDATE access_location_challenges
             SET consumed_at = :consumed_at
             WHERE id = :id AND consumed_at IS NULL',
            ['id' => $challengeId, 'consumed_at' => $consumedAt]
        ) === 1;
    }

    /** @param array<string, mixed> $data */
    public function insertObservation(array $data): int
    {
        return $this->db->insert('access_location_observations', [
            'challenge_id' => $data['challenge_id'] ?? null,
            'user_id' => (int) $data['user_id'],
            'client_id' => isset($data['client_id']) ? (int) $data['client_id'] : null,
            'device_uuid' => (string) $data['device_uuid'],
            'attempt_id' => (string) $data['attempt_id'],
            'platform' => (string) $data['platform'],
            'collection_mode' => (string) $data['collection_mode'],
            'provider' => (string) $data['provider'],
            'source_ip' => $data['source_ip'] ?? null,
            'public_ip' => $data['public_ip'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'province_code' => $data['province_code'] ?? null,
            'city_code' => $data['city_code'] ?? null,
            'observed_at' => (string) $data['observed_at'],
            'received_at' => (string) $data['received_at'],
            'payload_digest' => (string) $data['payload_digest'],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function recentObservations(int $userId, string $deviceUuid, string $sourceIp, string $cutoff): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM access_location_observations
             WHERE user_id = :user_id AND device_uuid = :device_uuid
               AND source_ip = :source_ip AND received_at >= :cutoff
             ORDER BY received_at DESC, id DESC',
            [
                'user_id' => $userId,
                'device_uuid' => $deviceUuid,
                'source_ip' => $sourceIp,
                'cutoff' => $cutoff,
            ]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function recentUserObservations(int $userId, string $cutoff): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM access_location_observations
             WHERE user_id = :user_id AND received_at >= :cutoff
             ORDER BY received_at DESC, id DESC',
            ['user_id' => $userId, 'cutoff' => $cutoff]
        );
    }

    /**
     * INSERT IGNORE makes the `(user_id, attempt_id, event_type)` uniqueness
     * boundary authoritative under concurrent retries. The existing row is
     * returned when another request won the race.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function insertPolicyEvent(array $data): ?array
    {
        $params = [
            'decision_id' => (string) $data['decision_id'],
            'user_id' => (int) $data['user_id'],
            'client_id' => isset($data['client_id']) ? (int) $data['client_id'] : null,
            'device_uuid' => $data['device_uuid'] ?? null,
            'source_ip' => $data['source_ip'] ?? null,
            'event_type' => (string) $data['event_type'],
            'decision' => (string) $data['decision'],
            'reason_code' => (string) $data['reason_code'],
            'matched_entity_type' => $data['matched_entity_type'] ?? null,
            'matched_observation_id' => $data['matched_observation_id'] ?? null,
            'attempt_id' => $data['attempt_id'] ?? null,
            'counts_as_geo_violation' => (int) ($data['counts_as_geo_violation'] ?? 0),
            'details_digest' => $data['details_digest'] ?? null,
            'created_at' => (string) $data['created_at'],
        ];
        $columns = implode(', ', array_keys($params));
        $placeholders = ':' . implode(', :', array_keys($params));
        $inserted = $this->db->execute(
            'INSERT IGNORE INTO access_policy_events (' . $columns . ') VALUES (' . $placeholders . ')',
            $params
        );
        if ($inserted === 1) {
            $row = $this->db->fetchOne(
                'SELECT * FROM access_policy_events WHERE decision_id = :decision_id LIMIT 1',
                ['decision_id' => $params['decision_id']]
            );
            return $row === null ? null : $row + ['_inserted' => true];
        }
        if ($params['attempt_id'] === null) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT * FROM access_policy_events
             WHERE user_id = :user_id AND attempt_id = :attempt_id AND event_type = :event_type
             ORDER BY id ASC LIMIT 1',
            [
                'user_id' => $params['user_id'],
                'attempt_id' => $params['attempt_id'],
                'event_type' => $params['event_type'],
            ]
        );
        return $row === null ? null : $row + ['_inserted' => false];
    }

    /** @return array<int, array<string, mixed>> */
    public function geoViolationEvents(int $userId, string $cutoff): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM access_policy_events
             WHERE user_id = :user_id AND counts_as_geo_violation = 1 AND created_at >= :cutoff
             ORDER BY created_at ASC, id ASC',
            ['user_id' => $userId, 'cutoff' => $cutoff]
        );
    }

    /** @return array<string, mixed>|null */
    public function activeAccountBan(int $userId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM account_access_bans
                WHERE user_id = :user_id AND released_at IS NULL
                ORDER BY banned_at DESC, id DESC LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        return $this->db->fetchOne($sql, ['user_id' => $userId]);
    }

    /** @param array<string, mixed> $data */
    public function createAccountBan(array $data): int
    {
        return $this->db->insert('account_access_bans', [
            'user_id' => (int) $data['user_id'],
            'origin' => (string) $data['origin'],
            'reason_code' => (string) $data['reason_code'],
            'evidence_event_ids_json' => (string) $data['evidence_event_ids_json'],
            'banned_at' => (string) $data['banned_at'],
            'released_at' => $data['released_at'] ?? null,
            'released_by_user_id' => $data['released_by_user_id'] ?? null,
            'release_note' => $data['release_note'] ?? null,
        ]);
    }

    public function setUserStatus(int $userId, string $status): void
    {
        $this->db->execute('UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id', [
            'id' => $userId,
            'status' => $status,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * A policy block is represented on the shared device-control row so
     * Android can consume it today and other distributions can consume the
     * same fields during their handoff implementation.
     */
    public function upsertTemporaryDeviceBlock(
        int $clientId,
        string $deviceUuid,
        string $until,
        string $origin,
        string $releasePolicy,
    ): void {
        $existing = $this->controlFor($clientId, $deviceUuid);
        if ($existing !== null && (string) ($existing['state'] ?? '') === 'banned') {
            // A timed policy/reset block must never downgrade a stronger
            // permanent administrator block.
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($existing === null) {
            $commandVersion = 1;
            $this->db->insert('client_device_controls', [
                'client_id' => $clientId,
                'device_id' => $deviceUuid,
                'identity_key_hash' => hash('sha256', 'panel-unverified|' . $clientId . '|' . $deviceUuid),
                'attestation_key_hash' => null,
                'state' => 'active',
                'blocked_until' => $until,
                'block_origin' => $origin,
                'release_policy' => $releasePolicy,
                'trust_state' => 'untrusted',
                'trust_reason' => 'policy_block',
                'reason_code' => 'temporary_' . $origin,
                'changed_by_user_id' => null,
                'changed_at' => $now,
                'command_version' => $commandVersion,
                'last_acknowledged_command_version' => 0,
                'acknowledged_at' => null,
                'last_attested_at' => null,
                'last_seen_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $previousCommandVersion = max(0, (int) ($existing['command_version'] ?? 0));
            $commandVersion = $previousCommandVersion + 1;
            $updated = $this->db->execute(
                'UPDATE client_device_controls
                 SET state = :state,
                     blocked_until = :blocked_until,
                     block_origin = :block_origin,
                     release_policy = :release_policy,
                     reason_code = :reason_code,
                     command_version = :command_version,
                     changed_at = :changed_at,
                     updated_at = :updated_at
                 WHERE id = :id AND command_version = :previous_command_version',
                [
                    'id' => (int) $existing['id'],
                    'previous_command_version' => $previousCommandVersion,
                    'state' => 'active',
                    'blocked_until' => $until,
                    'block_origin' => $origin,
                    'release_policy' => $releasePolicy,
                    'reason_code' => 'temporary_' . $origin,
                    'command_version' => $commandVersion,
                    'changed_at' => $now,
                    'updated_at' => $now,
                ]
            );
            if ($updated !== 1) {
                throw new ApiException('device_control_conflict', 409);
            }
        }
        $this->db->execute(
            'UPDATE android_device_sessions SET revoked_at = :revoked_at
             WHERE client_id = :client_id AND device_id = :device_id AND revoked_at IS NULL',
            ['client_id' => $clientId, 'device_id' => $deviceUuid, 'revoked_at' => $now]
        );
        $this->db->execute(
            'DELETE FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id',
            ['client_id' => $clientId, 'device_id' => $deviceUuid]
        );
        $this->db->insert('client_device_control_audits', [
            'client_id' => $clientId,
            'device_id' => $deviceUuid,
            'command_version' => $commandVersion,
            'event_type' => 'temporarily_blocked',
            'reason' => $origin,
            'actor_type' => 'system',
            'actor_id' => null,
            'created_at' => $now,
        ]);
    }

    public function revokeApplicationSessions(int $userId, string $revokedAt): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $revoked = $this->db->execute(
            'UPDATE forward_combination_self_leg_donors d
             INNER JOIN clients c ON c.id = d.client_id
             SET d.state = :state,
                 d.revoked_at = :revoked_at,
                 d.revoke_reason = :revoke_reason,
                 d.updated_at = :updated_at
             WHERE c.owner_user_id = :user_id AND d.state IN (:active_state, :qualifying_state)',
            [
                'user_id' => $userId,
                'state' => 'revoked',
                'active_state' => 'active',
                'qualifying_state' => 'qualifying',
                'revoked_at' => $revokedAt,
                'revoke_reason' => 'account_device_reset',
                'updated_at' => $revokedAt,
            ]
        );
        // These statements intentionally target only application sessions;
        // the authenticated web Panel session is left intact for recovery.
        $revoked += $this->db->execute(
            'DELETE FROM desktop_client_sessions WHERE user_id = :user_id',
            ['user_id' => $userId]
        );
        $revoked += $this->db->execute(
            'UPDATE android_device_sessions ads
             INNER JOIN clients c ON c.id = ads.client_id
             SET ads.revoked_at = :revoked_at
             WHERE c.owner_user_id = :user_id AND ads.revoked_at IS NULL',
            ['user_id' => $userId, 'revoked_at' => $revokedAt]
        );
        // client_device_sessions is an online presence index without a
        // revocation column; deleting rows forces the next request to enroll.
        $revoked += $this->db->execute(
            'DELETE cds FROM client_device_sessions cds
             INNER JOIN clients c ON c.id = cds.client_id
             WHERE c.owner_user_id = :user_id',
            ['user_id' => $userId]
        );
        return $revoked;
    }

    /** @return array<int, array<string, mixed>> */
    public function listBlacklistRules(?string $entityType = null, ?bool $activeOnly = null): array
    {
        $sql = 'SELECT * FROM access_blacklist_rules WHERE 1 = 1';
        $params = [];
        if ($entityType !== null) {
            $sql .= ' AND entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        if ($activeOnly !== null) {
            $sql .= ' AND is_active = :is_active';
            $params['is_active'] = $activeOnly ? 1 : 0;
        }
        $sql .= ' ORDER BY is_active DESC, entity_type ASC, updated_at DESC, id DESC';
        return $this->db->fetchAll($sql, $params);
    }

    /** @param array<string, mixed> $data */
    public function saveBlacklistRule(array $data): array
    {
        $now = (string) ($data['updated_at'] ?? gmdate('Y-m-d H:i:s'));
        $existing = $this->db->fetchOne(
            'SELECT * FROM access_blacklist_rules
             WHERE entity_type = :entity_type AND canonical_value = :canonical_value LIMIT 1',
            [
                'entity_type' => $data['entity_type'],
                'canonical_value' => $data['canonical_value'],
            ]
        );
        $row = [
            'entity_type' => (string) $data['entity_type'],
            'canonical_value' => (string) $data['canonical_value'],
            'display_value' => (string) $data['display_value'],
            'ip_family' => $data['ip_family'] ?? null,
            'ip_start' => $data['ip_start'] ?? null,
            'ip_end' => $data['ip_end'] ?? null,
            'province_code' => $data['province_code'] ?? null,
            'city_code' => $data['city_code'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'is_active' => (int) ($data['is_active'] ?? 1),
            'reason_code' => (string) $data['reason_code'],
            'admin_note' => $data['admin_note'] ?? null,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
            'updated_by_user_id' => $data['updated_by_user_id'] ?? null,
            'created_at' => (string) ($existing['created_at'] ?? $now),
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $id = $this->db->insert('access_blacklist_rules', $row);
            return ['id' => $id] + $row;
        }
        $sets = [];
        $params = ['id' => (int) $existing['id']];
        foreach ($row as $column => $value) {
            if ($column === 'created_at' || $column === 'entity_type' || $column === 'canonical_value') {
                continue;
            }
            $sets[] = $column . ' = :' . $column;
            $params[$column] = $value;
        }
        $this->db->execute('UPDATE access_blacklist_rules SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
        return ['id' => (int) $existing['id']] + $row;
    }

    public function setBlacklistRuleActive(int $ruleId, bool $active, ?int $actorId = null): bool
    {
        return $this->db->execute(
            'UPDATE access_blacklist_rules
             SET is_active = :is_active, updated_by_user_id = :updated_by_user_id, updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $ruleId,
                'is_active' => $active ? 1 : 0,
                'updated_by_user_id' => $actorId,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]
        ) === 1;
    }

    /** @return array<int, array<string, mixed>> */
    public function adminProfiles(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->fetchAll(
            'SELECT p.*, u.name AS user_name, u.email AS user_email
             FROM user_geo_lock_profiles p
             INNER JOIN users u ON u.id = p.user_id
             ORDER BY p.updated_at DESC, p.id DESC LIMIT ' . $limit
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function adminViolations(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->fetchAll(
            'SELECT e.*, u.name AS user_name, u.email AS user_email
             FROM access_policy_events e
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.counts_as_geo_violation = 1
             ORDER BY e.created_at DESC, e.id DESC LIMIT ' . $limit
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function adminDeviceBlocks(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        return $this->db->fetchAll(
            'SELECT cdc.*, c.name AS client_name, c.owner_user_id, u.name AS user_name, u.email AS user_email
             FROM client_device_controls cdc
             INNER JOIN clients c ON c.id = cdc.client_id
             LEFT JOIN users u ON u.id = c.owner_user_id
             WHERE cdc.blocked_until IS NOT NULL
                OR cdc.state IN (:banned_state, :kicked_state)
             ORDER BY cdc.updated_at DESC, cdc.id DESC LIMIT ' . $limit,
            ['banned_state' => 'banned', 'kicked_state' => 'kicked']
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function adminAccountBans(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->fetchAll(
            'SELECT b.*, u.name AS user_name, u.email AS user_email
             FROM account_access_bans b
             LEFT JOIN users u ON u.id = b.user_id
             ORDER BY (b.released_at IS NULL) DESC, b.banned_at DESC, b.id DESC LIMIT ' . $limit
        );
    }

    /** @return array<string, mixed>|null */
    public function releaseTemporaryDeviceBlock(int $clientId, string $deviceUuid, int $actorId): ?array
    {
        $control = $this->controlFor($clientId, $deviceUuid);
        if ($control === null || ($control['blocked_until'] ?? null) === null) {
            return null;
        }
        $now = gmdate('Y-m-d H:i:s');
        $previousVersion = max(0, (int) ($control['command_version'] ?? 0));
        $updated = $this->db->execute(
            'UPDATE client_device_controls
             SET state = :state,
                 blocked_until = NULL,
                 block_origin = NULL,
                 release_policy = NULL,
                 reason_code = NULL,
                 command_version = :command_version,
                 changed_by_user_id = :changed_by_user_id,
                 changed_at = :changed_at,
                 updated_at = :updated_at
             WHERE id = :id AND command_version = :previous_command_version',
            [
                'id' => (int) $control['id'],
                'previous_command_version' => $previousVersion,
                'state' => 'active',
                'command_version' => $previousVersion + 1,
                'changed_by_user_id' => $actorId > 0 ? $actorId : null,
                'changed_at' => $now,
                'updated_at' => $now,
            ]
        );
        if ($updated !== 1) {
            throw new ApiException('device_control_conflict', 409);
        }
        $this->db->execute(
            'UPDATE android_device_sessions SET revoked_at = :revoked_at
             WHERE client_id = :client_id AND device_id = :device_id AND revoked_at IS NULL',
            ['client_id' => $clientId, 'device_id' => $deviceUuid, 'revoked_at' => $now]
        );
        $this->db->execute(
            'DELETE FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id',
            ['client_id' => $clientId, 'device_id' => $deviceUuid]
        );
        $this->db->insert('client_device_control_audits', [
            'client_id' => $clientId,
            'device_id' => $deviceUuid,
            'command_version' => $previousVersion + 1,
            'event_type' => 'temporary_block_released',
            'reason' => (string) ($control['block_origin'] ?? 'temporary_block'),
            'actor_type' => 'admin',
            'actor_id' => $actorId > 0 ? $actorId : null,
            'created_at' => $now,
        ]);
        return array_merge($control, [
            'state' => 'active',
            'blocked_until' => null,
            'block_origin' => null,
            'release_policy' => null,
            'reason_code' => null,
            'command_version' => $previousVersion + 1,
        ]);
    }

    /**
     * Release a legacy or permanent device-control block. This path is kept
     * separate from temporary release so tenant-origin permissions cannot be
     * accidentally widened by an admin UI action.
     *
     * @return array<string, mixed>|null
     */
    public function releasePermanentDeviceBlock(int $clientId, string $deviceUuid, int $actorId): ?array
    {
        $control = $this->controlFor($clientId, $deviceUuid);
        if ($control === null) {
            return null;
        }
        if (!in_array((string) ($control['state'] ?? ''), ['banned', 'kicked'], true)
            || ($control['blocked_until'] ?? null) !== null) {
            return null;
        }
        $now = gmdate('Y-m-d H:i:s');
        $previousVersion = max(0, (int) ($control['command_version'] ?? 0));
        $updated = $this->db->execute(
            'UPDATE client_device_controls
             SET state = :state,
                 blocked_until = NULL,
                 block_origin = NULL,
                 release_policy = NULL,
                 reason_code = NULL,
                 command_version = :command_version,
                 changed_by_user_id = :changed_by_user_id,
                 changed_at = :changed_at,
                 updated_at = :updated_at
             WHERE id = :id AND command_version = :previous_command_version',
            [
                'id' => (int) $control['id'],
                'previous_command_version' => $previousVersion,
                'state' => 'active',
                'command_version' => $previousVersion + 1,
                'changed_by_user_id' => $actorId > 0 ? $actorId : null,
                'changed_at' => $now,
                'updated_at' => $now,
            ]
        );
        if ($updated !== 1) {
            throw new ApiException('device_control_conflict', 409);
        }
        $this->db->execute(
            'UPDATE android_device_sessions SET revoked_at = :revoked_at
             WHERE client_id = :client_id AND device_id = :device_id AND revoked_at IS NULL',
            ['client_id' => $clientId, 'device_id' => $deviceUuid, 'revoked_at' => $now]
        );
        $this->db->execute(
            'DELETE FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id',
            ['client_id' => $clientId, 'device_id' => $deviceUuid]
        );
        $this->db->insert('client_device_control_audits', [
            'client_id' => $clientId,
            'device_id' => $deviceUuid,
            'command_version' => $previousVersion + 1,
            'event_type' => 'permanent_block_released',
            'reason' => (string) ($control['reason_code'] ?? 'permanent_block'),
            'actor_type' => 'admin',
            'actor_id' => $actorId > 0 ? $actorId : null,
            'created_at' => $now,
        ]);
        return array_merge($control, [
            'state' => 'active',
            'blocked_until' => null,
            'block_origin' => null,
            'release_policy' => null,
            'reason_code' => null,
            'command_version' => $previousVersion + 1,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function clearExpiredTemporaryDeviceBlock(int $clientId, string $deviceUuid, string $clearedAt): ?array
    {
        $control = $this->controlFor($clientId, $deviceUuid);
        if ($control === null || ($control['blocked_until'] ?? null) === null) {
            return $control;
        }
        if ((string) ($control['state'] ?? '') === 'banned') {
            return $control;
        }
        $blockedTimestamp = strtotime((string) $control['blocked_until'] . ' UTC');
        $clearedTimestamp = strtotime($clearedAt . ' UTC');
        if ($blockedTimestamp === false || $clearedTimestamp === false) {
            return $control;
        }
        if ($blockedTimestamp !== false && $clearedTimestamp !== false && $blockedTimestamp > $clearedTimestamp) {
            return $control;
        }
        $previousVersion = max(0, (int) ($control['command_version'] ?? 0));
        $updated = $this->db->execute(
            'UPDATE client_device_controls
             SET state = :state,
                 blocked_until = NULL,
                 block_origin = NULL,
                 release_policy = NULL,
                 reason_code = NULL,
                 command_version = :command_version,
                 changed_by_user_id = NULL,
                 changed_at = :changed_at,
                 updated_at = :updated_at
             WHERE id = :id
               AND command_version = :previous_command_version
               AND blocked_until = :previous_blocked_until
               AND blocked_until <= :cleared_at',
            [
                'id' => (int) $control['id'],
                'previous_command_version' => $previousVersion,
                'previous_blocked_until' => (string) $control['blocked_until'],
                'cleared_at' => $clearedAt,
                'state' => 'active',
                'command_version' => $previousVersion + 1,
                'changed_at' => $clearedAt,
                'updated_at' => $clearedAt,
            ]
        );
        if ($updated !== 1) {
            throw new ApiException('device_control_conflict', 409);
        }
        $this->db->insert('client_device_control_audits', [
            'client_id' => $clientId,
            'device_id' => $deviceUuid,
            'command_version' => $previousVersion + 1,
            'event_type' => 'temporary_block_expired',
            'reason' => (string) ($control['block_origin'] ?? 'temporary_block'),
            'actor_type' => 'system',
            'actor_id' => null,
            'created_at' => $clearedAt,
        ]);
        return array_merge($control, [
            'state' => 'active',
            'blocked_until' => null,
            'block_origin' => null,
            'release_policy' => null,
            'reason_code' => null,
            'command_version' => $previousVersion + 1,
        ]);
    }

    public function releaseAccountBan(int $userId, int $actorId, ?string $note = null): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $now = gmdate('Y-m-d H:i:s');
        return $this->db->transaction(function () use ($userId, $actorId, $note, $now): bool {
            $changed = $this->db->execute(
                'UPDATE account_access_bans
                 SET released_at = :released_at,
                     released_by_user_id = :released_by_user_id,
                     release_note = :release_note
                 WHERE user_id = :user_id AND released_at IS NULL',
                [
                    'user_id' => $userId,
                    'released_at' => $now,
                    'released_by_user_id' => $actorId > 0 ? $actorId : null,
                    'release_note' => $note !== null ? substr(trim($note), 0, 500) : null,
                ]
            );
            if ($changed > 0) {
                $this->db->execute(
                    'UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id AND status = :banned_status',
                    ['id' => $userId, 'status' => 'active', 'banned_status' => 'banned', 'updated_at' => $now]
                );
            }
            return $changed > 0;
        });
    }

    /** @param callable $callback */
    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback);
    }
}
