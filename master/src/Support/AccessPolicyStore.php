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
             WHERE is_active = 1 AND entity_type IN ('province', 'city', 'country') LIMIT 1"
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
        $now = gmdate('Y-m-d H:i:s');
        if ($existing === null) {
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
                'command_version' => 0,
                'last_acknowledged_command_version' => 0,
                'last_attested_at' => null,
                'last_seen_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

        $this->db->execute(
            'UPDATE client_device_controls
             SET blocked_until = :blocked_until,
                 block_origin = :block_origin,
                 release_policy = :release_policy,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => (int) $existing['id'],
                'blocked_until' => $until,
                'block_origin' => $origin,
                'release_policy' => $releasePolicy,
                'updated_at' => $now,
            ]
        );
    }

    public function revokeApplicationSessions(int $userId, string $revokedAt): void
    {
        if ($userId <= 0) {
            return;
        }
        // These statements intentionally target only application sessions;
        // the authenticated web Panel session is left intact for recovery.
        $this->db->execute(
            'DELETE FROM desktop_client_sessions WHERE user_id = :user_id',
            ['user_id' => $userId]
        );
        $this->db->execute(
            'UPDATE android_device_sessions ads
             INNER JOIN clients c ON c.id = ads.client_id
             SET ads.revoked_at = :revoked_at
             WHERE c.owner_user_id = :user_id AND ads.revoked_at IS NULL',
            ['user_id' => $userId, 'revoked_at' => $revokedAt]
        );
        // client_device_sessions is an online presence index without a
        // revocation column; deleting rows forces the next request to enroll.
        $this->db->execute(
            'DELETE cds FROM client_device_sessions cds
             INNER JOIN clients c ON c.id = cds.client_id
             WHERE c.owner_user_id = :user_id',
            ['user_id' => $userId]
        );
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

    /** @param callable $callback */
    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback);
    }
}
