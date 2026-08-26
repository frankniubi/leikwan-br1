<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;

final class DeviceControlService
{
    private const CHALLENGE_TTL_SECONDS = 300;
    private const SESSION_TTL_SECONDS = 900;

    public function __construct(
        private Database $db,
        private DeviceAttestationVerifier $attestationVerifier,
    ) {
    }

    /**
     * @param array<string, mixed> $client
     * @return array{nonce:string,expires_at:string}
     */
    public function issueChallenge(array $client, string $deviceId): array
    {
        $clientId = $this->clientId($client);
        $deviceId = $this->requireDeviceId($deviceId);
        $control = $this->controlFor($clientId, $deviceId);
        if ($control !== null && (string) ($control['state'] ?? '') === 'banned') {
            $this->throwIfTerminal($control);
        }

        $nonce = $this->randomToken();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::CHALLENGE_TTL_SECONDS);
        $this->db->insert('android_device_challenges', [
            'client_id' => $clientId,
            'device_id' => $deviceId,
            'nonce_hash' => hash('sha256', $nonce),
            'expires_at' => $expiresAt,
            'consumed_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['nonce' => $nonce, 'expires_at' => $expiresAt];
    }

    /**
     * @param array<string, mixed> $client
     * @param array<string, mixed> $proof
     * @return array{device_session:string,expires_at:string,command_version:int}
     */
    public function enroll(array $client, string $deviceId, string $nonce, array $proof): array
    {
        $clientId = $this->clientId($client);
        $deviceId = $this->requireDeviceId($deviceId);
        $nonce = trim($nonce);
        if ($nonce === '') {
            throw new ApiException('device_challenge_invalid', 403);
        }

        return $this->db->transaction(function () use ($client, $clientId, $deviceId, $nonce, $proof): array {
            $control = $this->controlFor($clientId, $deviceId);
            if ($control !== null && (string) ($control['state'] ?? '') === 'banned') {
                $this->throwIfTerminal($control);
            }

            $challenge = $this->db->fetchOne(
                'SELECT * FROM android_device_challenges
                 WHERE client_id = :client_id AND device_id = :device_id AND nonce_hash = :nonce_hash
                 LIMIT 1',
                [
                    'client_id' => $clientId,
                    'device_id' => $deviceId,
                    'nonce_hash' => hash('sha256', $nonce),
                ]
            );
            if ($challenge === null || ($challenge['consumed_at'] ?? null) !== null || !$this->isFuture($challenge['expires_at'] ?? null)) {
                throw new ApiException('device_challenge_invalid', 403);
            }

            $verification = $this->attestationVerifier->verify($proof, $nonce, $clientId, $deviceId);
            if (!$verification->trusted || $verification->identityKeyHash === null || $verification->attestationKeyHash === null) {
                throw new ApiException('device_untrusted', 403, ['reason' => $verification->reason]);
            }

            $now = gmdate('Y-m-d H:i:s');
            if ($control === null) {
                $controlId = $this->db->insert('client_device_controls', [
                    'client_id' => $clientId,
                    'device_id' => $deviceId,
                    'identity_key_hash' => $verification->identityKeyHash,
                    'attestation_key_hash' => $verification->attestationKeyHash,
                    'state' => 'active',
                    'reason_code' => null,
                    'trust_state' => 'trusted',
                    'trust_reason' => null,
                    'command_version' => 0,
                    'last_acknowledged_command_version' => 0,
                    'acknowledged_at' => null,
                    'last_attested_at' => $now,
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $control = [
                    'id' => $controlId,
                    'client_id' => $clientId,
                    'device_id' => $deviceId,
                    'state' => 'active',
                    'command_version' => 0,
                ];
            } else {
                $updated = $this->db->execute(
                    'UPDATE client_device_controls
                     SET identity_key_hash = :identity_key_hash,
                         attestation_key_hash = :attestation_key_hash,
                         state = :state,
                         reason_code = :reason_code,
                         trust_state = :trust_state,
                         trust_reason = :trust_reason,
                         last_attested_at = :last_attested_at,
                         last_seen_at = :last_seen_at,
                         updated_at = :updated_at
                     WHERE id = :id AND command_version = :expected_command_version',
                    [
                        'id' => (int) $control['id'],
                        'expected_command_version' => (int) ($control['command_version'] ?? 0),
                        'identity_key_hash' => $verification->identityKeyHash,
                        'attestation_key_hash' => $verification->attestationKeyHash,
                        'state' => 'active',
                        'reason_code' => null,
                        'trust_state' => 'trusted',
                        'trust_reason' => null,
                        'last_attested_at' => $now,
                        'last_seen_at' => $now,
                        'updated_at' => $now,
                    ]
                );
                if ($updated !== 1) {
                    $latest = $this->controlFor($clientId, $deviceId);
                    if ($latest !== null && (string) ($latest['state'] ?? '') === 'banned') {
                        $this->throwIfTerminal($latest);
                    }
                    throw new ApiException('device_enrollment_conflict', 409);
                }
                $control['state'] = 'active';
            }

            $consumed = $this->db->execute(
                'UPDATE android_device_challenges
                 SET consumed_at = :consumed_at
                 WHERE id = :id AND consumed_at IS NULL',
                ['id' => (int) $challenge['id'], 'consumed_at' => $now]
            );
            if ($consumed !== 1) {
                throw new ApiException('device_challenge_invalid', 403);
            }
            $this->db->execute(
                'UPDATE android_device_sessions
                 SET revoked_at = :revoked_at
                 WHERE client_id = :client_id AND device_id = :device_id AND revoked_at IS NULL',
                ['client_id' => $clientId, 'device_id' => $deviceId, 'revoked_at' => $now]
            );

            $session = $this->randomToken();
            $expiresAt = gmdate('Y-m-d H:i:s', time() + self::SESSION_TTL_SECONDS);
            $commandVersion = max(0, (int) ($control['command_version'] ?? 0));
            $this->db->insert('android_device_sessions', [
                'client_id' => $clientId,
                'device_id' => $deviceId,
                'session_hash' => hash('sha256', $session),
                'command_version' => $commandVersion,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'created_at' => $now,
            ]);
            $this->writeAudit($clientId, $deviceId, $commandVersion, 'enrolled', null, 'device', null);

            return [
                'device_session' => $session,
                'expires_at' => $expiresAt,
                'command_version' => $commandVersion,
            ];
        });
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    public function requireActive(array $client, Request $request): array
    {
        $clientId = $this->clientId($client);
        $deviceId = $this->requireDeviceId((string) ($request->header('x-device-id') ?? ''));
        $session = trim((string) ($request->header('x-device-session') ?? ''));
        if ($session === '') {
            throw new ApiException('device_untrusted', 403);
        }

        $control = $this->controlFor($clientId, $deviceId);
        if ($control === null) {
            throw new ApiException('device_untrusted', 403);
        }
        $this->throwIfTerminal($control);
        if ((string) ($control['trust_state'] ?? '') !== 'trusted'
            || !preg_match('/^[a-f0-9]{64}$/', (string) ($control['identity_key_hash'] ?? ''))) {
            throw new ApiException('device_untrusted', 403);
        }

        $sessionRow = $this->db->fetchOne(
            'SELECT * FROM android_device_sessions
             WHERE client_id = :client_id AND device_id = :device_id AND session_hash = :session_hash
             LIMIT 1',
            [
                'client_id' => $clientId,
                'device_id' => $deviceId,
                'session_hash' => hash('sha256', $session),
            ]
        );
        if ($sessionRow === null || ($sessionRow['revoked_at'] ?? null) !== null || !$this->isFuture($sessionRow['expires_at'] ?? null)
            || (int) ($sessionRow['command_version'] ?? -1) !== (int) ($control['command_version'] ?? 0)) {
            throw new ApiException('device_untrusted', 403);
        }

        $this->db->execute(
            'UPDATE client_device_controls SET last_seen_at = :last_seen_at, updated_at = :updated_at WHERE id = :id',
            [
                'id' => (int) $control['id'],
                'last_seen_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
        return $control;
    }

    /**
     * @return array<string, mixed>
     */
    public function setState(int $clientId, string $deviceId, string $state, ?string $reasonCode, ?int $actorId = null): array
    {
        if ($clientId <= 0 || !in_array($state, ['active', 'kicked', 'banned'], true)) {
            throw new ApiException('device_control_invalid', 422);
        }
        $deviceId = $this->requireDeviceId($deviceId);
        return $this->db->transaction(function () use ($clientId, $deviceId, $state, $reasonCode, $actorId): array {
            $control = $this->controlFor($clientId, $deviceId);
            if ($control === null) {
                throw new ApiException('device_control_not_found', 404);
            }
            $now = gmdate('Y-m-d H:i:s');
            $previousCommandVersion = max(0, (int) ($control['command_version'] ?? 0));
            $commandVersion = $previousCommandVersion + 1;
            $reasonCode = $this->trimOrNull($reasonCode);
            $updated = $this->db->execute(
                'UPDATE client_device_controls
                 SET state = :state,
                     reason_code = :reason_code,
                     command_version = :command_version,
                     changed_by_user_id = :changed_by_user_id,
                     changed_at = :changed_at,
                     updated_at = :updated_at
                 WHERE id = :id AND command_version = :previous_command_version',
                [
                    'id' => (int) $control['id'],
                    'previous_command_version' => $previousCommandVersion,
                    'state' => $state,
                    'reason_code' => $reasonCode,
                    'command_version' => $commandVersion,
                    'changed_by_user_id' => $actorId,
                    'changed_at' => $now,
                    'updated_at' => $now,
                ]
            );
            if ($updated !== 1) {
                throw new ApiException('device_control_conflict', 409);
            }
            $this->db->execute(
                'UPDATE android_device_sessions
                 SET revoked_at = :revoked_at
                 WHERE client_id = :client_id AND device_id = :device_id AND revoked_at IS NULL',
                ['client_id' => $clientId, 'device_id' => $deviceId, 'revoked_at' => $now]
            );
            $eventType = $state === 'active' ? 'unbanned' : $state;
            $this->writeAudit($clientId, $deviceId, $commandVersion, $eventType, $reasonCode, 'tenant', $actorId);

            return array_merge($control, [
                'state' => $state,
                'reason_code' => $reasonCode,
                'command_version' => $commandVersion,
            ]);
        });
    }

    /**
     * @param array<string, mixed> $client
     */
    public function acknowledge(array $client, string $deviceId, int $commandVersion): void
    {
        $clientId = $this->clientId($client);
        $deviceId = $this->requireDeviceId($deviceId);
        $control = $this->controlFor($clientId, $deviceId);
        if ($control === null) {
            return;
        }
        $acknowledged = min(max(0, $commandVersion), max(0, (int) ($control['command_version'] ?? 0)));
        if ($acknowledged <= (int) ($control['last_acknowledged_command_version'] ?? 0)) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->execute(
            'UPDATE client_device_controls
             SET last_acknowledged_command_version = :last_acknowledged_command_version,
                 acknowledged_at = :acknowledged_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => (int) $control['id'],
                'last_acknowledged_command_version' => $acknowledged,
                'acknowledged_at' => $now,
                'updated_at' => $now,
            ]
        );
        $this->writeAudit($clientId, $deviceId, $acknowledged, 'acknowledged', null, 'device', null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function controlFor(int $clientId, string $deviceId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM client_device_controls WHERE client_id = :client_id AND device_id = :device_id LIMIT 1',
            ['client_id' => $clientId, 'device_id' => $deviceId]
        );
    }

    /** @param array<string, mixed> $control */
    private function throwIfTerminal(array $control): void
    {
        $state = (string) ($control['state'] ?? '');
        if ($state !== 'kicked' && $state !== 'banned') {
            return;
        }
        $action = $state === 'kicked' ? 'kick' : 'ban';
        throw new ApiException(
            $state === 'kicked' ? 'device_kicked' : 'device_banned',
            $state === 'kicked' ? 409 : 403,
            [
                'command' => [
                    'version' => max(0, (int) ($control['command_version'] ?? 0)),
                    'action' => $action,
                    'reason' => (string) ($control['reason_code'] ?? ''),
                ],
            ]
        );
    }

    private function clientId(array $client): int
    {
        $clientId = (int) ($client['id'] ?? 0);
        if ($clientId <= 0) {
            throw new ApiException('client_not_found', 404);
        }
        return $clientId;
    }

    private function requireDeviceId(string $deviceId): string
    {
        $deviceId = trim($deviceId);
        if (preg_match('/^android-client-[1-9][0-9]*-[a-f0-9]{32}$/', $deviceId) !== 1) {
            throw new ApiException('device_id_invalid', 422);
        }
        return $deviceId;
    }

    private function isFuture(mixed $value): bool
    {
        $timestamp = is_string($value) ? strtotime($value . ' UTC') : false;
        return $timestamp !== false && $timestamp > time();
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function trimOrNull(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : substr($value, 0, 64);
    }

    private function writeAudit(
        int $clientId,
        string $deviceId,
        int $commandVersion,
        string $eventType,
        ?string $reason,
        string $actorType,
        ?int $actorId,
    ): void {
        $this->db->insert('client_device_control_audits', [
            'client_id' => $clientId,
            'device_id' => $deviceId,
            'command_version' => $commandVersion,
            'event_type' => $eventType,
            'reason' => $reason,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
