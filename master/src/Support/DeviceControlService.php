<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
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

    public static function fromConfig(Config $config, Database $db): self
    {
        $signingDigests = self::configDigests((string) ($config->get('ANDROID_ATTESTATION_SIGNING_CERT_SHA256', '') ?? ''));
        $rootDigests = self::configDigests((string) ($config->get('ANDROID_ATTESTATION_ROOT_CERT_SHA256', '') ?? ''));
        return new self(
            $db,
            new AndroidDeviceAttestationVerifier(
                (string) ($config->get('ANDROID_ATTESTATION_PACKAGE_NAME', 'com.leikwan.bridge') ?? 'com.leikwan.bridge'),
                $signingDigests,
                $rootDigests,
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function controlsForClient(int $clientId): array
    {
        if ($clientId <= 0) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT * FROM client_device_controls WHERE client_id = :client_id ORDER BY updated_at DESC, id DESC',
            ['client_id' => $clientId]
        );
    }

    /**
     * @param array<string, mixed> $client
     * @return array{nonce:string,expires_at:string}
     */
    public function issueChallenge(array $client, string $deviceId, int $accessPolicyProtocolVersion = 0): array
    {
        $clientId = $this->clientId($client);
        $deviceId = $this->requireDeviceId($deviceId);
        $control = $this->controlFor($clientId, $deviceId);
        if ($control !== null) {
            $this->throwIfTemporarilyBlocked($control, $accessPolicyProtocolVersion === 1);
            // Expiry cleanup may have changed a legacy kicked row to active;
            // do not make the terminal decision from the stale pre-cleanup
            // snapshot.
            $control = $this->controlFor($clientId, $deviceId);
            if ($control !== null) {
                // A concurrent writer may have replaced an expired timer
                // while the cleanup CAS was in flight; re-check the fresh
                // row before issuing a new challenge.
                $this->throwIfTemporarilyBlocked($control, $accessPolicyProtocolVersion === 1);
                // Legacy kicked rows retain the original explicit
                // re-enrollment recovery contract. Permanent bans remain
                // terminal.
                if ((string) ($control['state'] ?? '') === 'banned') {
                    $this->throwIfTerminal($control);
                }
            }
        }

        $nonce = $this->randomToken();
        $expiresAtTimestamp = time() + self::CHALLENGE_TTL_SECONDS;
        $expiresAt = gmdate('Y-m-d H:i:s', $expiresAtTimestamp);
        $this->db->insert('android_device_challenges', [
            'client_id' => $clientId,
            'device_id' => $deviceId,
            'nonce_hash' => hash('sha256', $nonce),
            'expires_at' => $expiresAt,
            'consumed_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['nonce' => $nonce, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresAtTimestamp)];
    }

    /**
     * Issue a challenge for a device that has already completed trusted
     * hardware enrollment. Renewal never creates or re-trusts a control row.
     *
     * @param array<string, mixed> $client
     * @return array{nonce:string,expires_at:string}
     */
    public function issueRenewalChallenge(array $client, string $deviceId, int $accessPolicyProtocolVersion = 0): array
    {
        $clientId = $this->clientId($client);
        $deviceId = $this->requireDeviceId($deviceId);
        $control = $this->controlFor($clientId, $deviceId);
        if ($control === null) {
            throw new ApiException('device_renewal_not_available', 404);
        }
        $this->throwIfTemporarilyBlocked($control, $accessPolicyProtocolVersion === 1);
        $control = $this->controlFor($clientId, $deviceId);
        if ($control === null) {
            throw new ApiException('device_renewal_not_available', 404);
        }
        $this->throwIfTemporarilyBlocked($control, $accessPolicyProtocolVersion === 1);
        $this->throwIfTerminal($control);
        if ((string) ($control['trust_state'] ?? '') !== 'trusted'
            || !preg_match('/^[a-f0-9]{64}$/', (string) ($control['identity_key_hash'] ?? ''))) {
            throw new ApiException('device_renewal_not_available', 404);
        }

        return $this->issueChallenge($client, $deviceId, $accessPolicyProtocolVersion);
    }

    /**
     * Rotate the short-lived device session using the persistent Android
     * identity key. The key was hardware-attested during initial enrollment;
     * this path only proves possession of that same key.
     *
     * @param array<string, mixed> $client
     * @return array{device_session:string,expires_at:string,command_version:int}
     */
    public function renew(
        array $client,
        string $deviceId,
        string $nonce,
        string $identityPublicKey,
        string $bindingSignature,
        int $accessPolicyProtocolVersion = 0,
    ): array {
        $clientId = $this->clientId($client);
        $deviceId = $this->requireDeviceId($deviceId);
        $nonce = trim($nonce);
        if ($nonce === '') {
            throw new ApiException('device_renewal_invalid', 403);
        }

        $identityPublicKey = trim($identityPublicKey);
        $bindingSignature = trim($bindingSignature);
        $simpleRenewal = $identityPublicKey === '' && $bindingSignature === '';
        if (!$simpleRenewal && ($identityPublicKey === '' || $bindingSignature === '')) {
            throw new ApiException('device_renewal_invalid', 403);
        }
        $identitySpki = $simpleRenewal ? null : $this->decodeRenewalField($identityPublicKey);
        $signature = $simpleRenewal ? null : $this->decodeRenewalField($bindingSignature);

        return $this->db->transaction(function () use (
            $clientId,
            $deviceId,
            $nonce,
            $identitySpki,
            $signature,
            $simpleRenewal,
            $accessPolicyProtocolVersion,
        ): array {
            $control = $this->controlFor($clientId, $deviceId);
            if ($control === null) {
                throw new ApiException('device_renewal_not_available', 404);
            }
            $this->throwIfTemporarilyBlocked($control, $accessPolicyProtocolVersion === 1);
            $control = $this->controlFor($clientId, $deviceId);
            if ($control === null) {
                throw new ApiException('device_renewal_not_available', 404);
            }
            $this->throwIfTemporarilyBlocked($control, $accessPolicyProtocolVersion === 1);
            $this->throwIfTerminal($control);
            $storedIdentityHash = strtolower((string) ($control['identity_key_hash'] ?? ''));
            if ((string) ($control['trust_state'] ?? '') !== 'trusted'
                || !preg_match('/^[a-f0-9]{64}$/', $storedIdentityHash)) {
                throw new ApiException('device_renewal_not_available', 404);
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
            if ($challenge === null || ($challenge['consumed_at'] ?? null) !== null
                || !$this->isFuture($challenge['expires_at'] ?? null)) {
                throw new ApiException('device_renewal_invalid', 403);
            }

            if (!$simpleRenewal) {
                $identityHash = hash('sha256', (string) $identitySpki);
                $expectedDeviceId = 'android-client-' . $clientId . '-' . substr($identityHash, 0, 32);
                if (!hash_equals($storedIdentityHash, $identityHash)
                    || !hash_equals($expectedDeviceId, $deviceId)) {
                    throw new ApiException('device_renewal_invalid', 403);
                }

                $binding = 'LEIKWAN_ANDROID_SESSION_RENEWAL_V1|' . $nonce . '|' . $clientId
                    . '|' . $deviceId . '|' . $identityHash;
                if (!function_exists('openssl_pkey_get_public') || !function_exists('openssl_verify')) {
                    throw new ApiException('device_renewal_unavailable', 503);
                }
                $pem = "-----BEGIN PUBLIC KEY-----\n"
                    . chunk_split(base64_encode((string) $identitySpki), 64, "\n")
                    . "-----END PUBLIC KEY-----\n";
                $publicKey = @openssl_pkey_get_public($pem);
                if ($publicKey === false || @openssl_verify($binding, (string) $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
                    throw new ApiException('device_renewal_invalid', 403);
                }
            }

            $now = gmdate('Y-m-d H:i:s');
            $consumed = $this->db->execute(
                'UPDATE android_device_challenges
                 SET consumed_at = :consumed_at
                 WHERE id = :id AND consumed_at IS NULL',
                ['id' => (int) $challenge['id'], 'consumed_at' => $now]
            );
            if ($consumed !== 1) {
                throw new ApiException('device_renewal_invalid', 403);
            }

            $updated = $this->db->execute(
                'UPDATE client_device_controls
                 SET last_seen_at = :last_seen_at, updated_at = :updated_at
                 WHERE id = :id AND command_version = :expected_command_version
                   AND state = :state AND trust_state = :trust_state',
                [
                    'id' => (int) $control['id'],
                    'expected_command_version' => (int) ($control['command_version'] ?? 0),
                    'state' => 'active',
                    'trust_state' => 'trusted',
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ]
            );
            if ($updated !== 1) {
                throw new ApiException('device_renewal_conflict', 409);
            }

            $this->revokeDeviceSessions($clientId, $deviceId, $now);
            $session = $this->randomToken();
            $expiresAtTimestamp = time() + self::SESSION_TTL_SECONDS;
            $this->db->insert('android_device_sessions', [
                'client_id' => $clientId,
                'device_id' => $deviceId,
                'session_hash' => hash('sha256', $session),
                'command_version' => max(0, (int) ($control['command_version'] ?? 0)),
                'expires_at' => gmdate('Y-m-d H:i:s', $expiresAtTimestamp),
                'revoked_at' => null,
                'created_at' => $now,
            ]);
            $this->writeAudit(
                $clientId,
                $deviceId,
                max(0, (int) ($control['command_version'] ?? 0)),
                'session_renewed',
                null,
                'device',
                null,
            );

            return [
                'device_session' => $session,
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresAtTimestamp),
                'command_version' => max(0, (int) ($control['command_version'] ?? 0)),
            ];
        });
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
            if ($control !== null) {
                $protocolV1 = (int) ($proof['access_policy_protocol_version'] ?? $proof['protocol_version'] ?? 0) === 1;
                $this->throwIfTemporarilyBlocked($control, $protocolV1);
                $control = $this->controlFor($clientId, $deviceId);
                if ($control !== null) {
                    // See issueChallenge(): do not use a stale row after
                    // expiry cleanup races with a new block.
                    $this->throwIfTemporarilyBlocked($control, $protocolV1);
                    // Legacy kicked rows may be recovered by a fresh
                    // attestation; only a permanent ban blocks enrollment.
                    if ((string) ($control['state'] ?? '') === 'banned') {
                        $this->throwIfTerminal($control);
                    }
                }
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
            $expiresAtTimestamp = time() + self::SESSION_TTL_SECONDS;
            $expiresAt = gmdate('Y-m-d H:i:s', $expiresAtTimestamp);
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
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresAtTimestamp),
                'command_version' => $commandVersion,
            ];
        });
    }

    /**
     * Enroll a device using only its persistent Android Keystore public key.
     * This is the default Android user path and intentionally does not invoke
     * Google RKP, key attestation, or any external verifier.
     *
     * @param array<string, mixed> $client
     * @return array{device_session:string,expires_at:string,command_version:int}
     */
    public function enrollIdentity(
        array $client,
        string $deviceId,
        string $nonce,
        string $identityPublicKey,
        int $accessPolicyProtocolVersion = 0,
    ): array {
        $clientId = $this->clientId($client);
        $deviceId = $this->requireDeviceId($deviceId);
        $nonce = trim($nonce);
        if ($nonce === '') {
            throw new ApiException('device_challenge_invalid', 403);
        }

        $identityPublicKey = trim($identityPublicKey);
        $identitySpki = $identityPublicKey === '' ? null : $this->decodeRenewalField($identityPublicKey);
        $identityHash = $identitySpki === null
            ? hash('sha256', 'LEIKWAN_ANDROID_DEVICE_IDENTITY_V1|' . $deviceId)
            : hash('sha256', $identitySpki);
        if ($identitySpki !== null) {
            $expectedDeviceId = 'android-client-' . $clientId . '-' . substr($identityHash, 0, 32);
            if (!hash_equals($expectedDeviceId, $deviceId)) {
                throw new ApiException('device_identity_mismatch', 403);
            }
        }

        return $this->db->transaction(function () use (
            $clientId,
            $deviceId,
            $nonce,
            $identitySpki,
            $identityHash,
            $accessPolicyProtocolVersion,
        ): array {
            $control = $this->controlFor($clientId, $deviceId);
            if ($control !== null) {
                $this->throwIfTemporarilyBlocked($control, $accessPolicyProtocolVersion === 1);
                $control = $this->controlFor($clientId, $deviceId);
                if ($control !== null) {
                    $this->throwIfTemporarilyBlocked($control, $accessPolicyProtocolVersion === 1);
                    // A permanent ban remains terminal. Legacy kicked rows may
                    // be recovered by an explicit user enrollment.
                    if ((string) ($control['state'] ?? '') === 'banned') {
                        $this->throwIfTerminal($control);
                    }
                    $storedIdentityHash = strtolower((string) ($control['identity_key_hash'] ?? ''));
                    if ($identitySpki !== null && (string) ($control['trust_state'] ?? '') === 'trusted'
                        && preg_match('/^[a-f0-9]{64}$/', $storedIdentityHash) === 1
                        && !hash_equals($storedIdentityHash, $identityHash)) {
                        throw new ApiException('device_identity_changed', 403);
                    }
                }
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
            if ($challenge === null || ($challenge['consumed_at'] ?? null) !== null
                || !$this->isFuture($challenge['expires_at'] ?? null)) {
                throw new ApiException('device_challenge_invalid', 403);
            }

            $now = gmdate('Y-m-d H:i:s');
            if ($control === null) {
                $controlId = $this->db->insert('client_device_controls', [
                    'client_id' => $clientId,
                    'device_id' => $deviceId,
                    'identity_key_hash' => $identityHash,
                    'attestation_key_hash' => null,
                    'state' => 'active',
                    'reason_code' => null,
                    'blocked_until' => null,
                    'block_origin' => null,
                    'release_policy' => null,
                    'trust_state' => 'trusted',
                    'trust_reason' => 'identity_only',
                    'command_version' => 0,
                    'last_acknowledged_command_version' => 0,
                    'acknowledged_at' => null,
                    'last_attested_at' => null,
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
                         attestation_key_hash = NULL,
                         state = :state,
                         reason_code = :reason_code,
                         blocked_until = NULL,
                         block_origin = NULL,
                         release_policy = NULL,
                         trust_state = :trust_state,
                         trust_reason = :trust_reason,
                         last_attested_at = NULL,
                         last_seen_at = :last_seen_at,
                         updated_at = :updated_at
                     WHERE id = :id AND command_version = :expected_command_version',
                    [
                        'id' => (int) $control['id'],
                        'expected_command_version' => (int) ($control['command_version'] ?? 0),
                        'identity_key_hash' => $identityHash,
                        'state' => 'active',
                        'reason_code' => null,
                        'trust_state' => 'trusted',
                        'trust_reason' => 'identity_only',
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

            $this->revokeDeviceSessions($clientId, $deviceId, $now);
            $session = $this->randomToken();
            $expiresAtTimestamp = time() + self::SESSION_TTL_SECONDS;
            $commandVersion = max(0, (int) ($control['command_version'] ?? 0));
            $this->db->insert('android_device_sessions', [
                'client_id' => $clientId,
                'device_id' => $deviceId,
                'session_hash' => hash('sha256', $session),
                'command_version' => $commandVersion,
                'expires_at' => gmdate('Y-m-d H:i:s', $expiresAtTimestamp),
                'revoked_at' => null,
                'created_at' => $now,
            ]);
            $this->writeAudit($clientId, $deviceId, $commandVersion, 'identity_enrolled', null, 'device', null);

            return [
                'device_session' => $session,
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresAtTimestamp),
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
        $protocolV1 = (int) ($request->header('x-access-policy-version') ?? $request->input('protocol_version', 0)) === 1;
        $this->throwIfTemporarilyBlocked($control, $protocolV1);
        $control = $this->controlFor($clientId, $deviceId) ?? $control;
        // If a concurrent write won the expiry CAS, the second read may now
        // contain a new active timer. Re-apply the block check to fail closed.
        $this->throwIfTemporarilyBlocked($control, $protocolV1);
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
        // State controls are distribution-neutral. Attestation/enrollment
        // keeps the Android-specific validator; policy actions accept any
        // stable UUID issued by a supported client.
        $deviceId = $this->requireStableDeviceId($deviceId);
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
                     blocked_until = :blocked_until,
                     block_origin = :block_origin,
                     release_policy = :release_policy,
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
                    'blocked_until' => null,
                    'block_origin' => null,
                    'release_policy' => null,
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

    /** @return array<string, mixed> */
    public function setTemporaryBlock(
        int $clientId,
        string $deviceId,
        string $origin,
        int $seconds,
        int $actorUserId,
    ): array {
        if ($clientId <= 0 || !in_array($seconds, [900, 1800, 3600, 86400, 604800], true)) {
            throw new ApiException('device_block_invalid', 422);
        }
        $origin = strtolower(trim($origin));
        $releasePolicy = match ($origin) {
            'manual_tenant' => 'tenant_or_admin',
            'manual_admin', 'policy', 'province_reset' => 'admin_only',
            default => throw new ApiException('device_block_origin_invalid', 422),
        };
        $deviceId = $this->requireStableDeviceId($deviceId);

        return $this->db->transaction(function () use ($clientId, $deviceId, $origin, $seconds, $actorUserId, $releasePolicy): array {
            $control = $this->controlFor($clientId, $deviceId);
            if ($control !== null && (string) ($control['state'] ?? '') === 'banned') {
                $this->throwIfTerminal($control);
            }
            if ($control !== null
                && $origin === 'manual_tenant'
                && (string) ($control['state'] ?? '') === 'kicked'
                && ($control['blocked_until'] ?? null) === null) {
                // A legacy permanent kick is administrator-controlled; a
                // tenant must not turn it into a restorable temporary block.
                throw new ApiException('device_block_release_forbidden', 403);
            }
            if ($control !== null
                && $origin === 'manual_tenant'
                && $this->activeTemporaryBlock($control) !== null
                && (string) ($control['release_policy'] ?? '') !== 'tenant_or_admin') {
                // A tenant kick must never rewrite a policy/admin/reset block
                // into a tenant-restorable origin.
                throw new ApiException('device_block_release_forbidden', 403);
            }
            $now = gmdate('Y-m-d H:i:s');
            $blockedUntil = gmdate('Y-m-d H:i:s', time() + $seconds);
            $actorType = $origin === 'manual_tenant' ? 'tenant' : ($origin === 'policy' || $origin === 'province_reset' ? 'system' : 'admin');

            if ($control === null) {
                $commandVersion = 1;
                $controlId = $this->db->insert('client_device_controls', [
                    'client_id' => $clientId,
                    'device_id' => $deviceId,
                    'identity_key_hash' => hash('sha256', 'panel-unverified|' . $clientId . '|' . $deviceId),
                    'attestation_key_hash' => null,
                    'state' => 'active',
                    'blocked_until' => $blockedUntil,
                    'block_origin' => $origin,
                    'release_policy' => $releasePolicy,
                    'reason_code' => 'temporary_' . $origin,
                    'trust_state' => 'untrusted',
                    'trust_reason' => 'policy_block',
                    'command_version' => $commandVersion,
                    'last_acknowledged_command_version' => 0,
                    'acknowledged_at' => null,
                    'last_attested_at' => null,
                    'last_seen_at' => null,
                    'changed_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                    'changed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $control = ['id' => $controlId, 'client_id' => $clientId, 'device_id' => $deviceId];
            } else {
                $previousCommandVersion = max(0, (int) ($control['command_version'] ?? 0));
                $commandVersion = $previousCommandVersion + 1;
                $updated = $this->db->execute(
                    'UPDATE client_device_controls
                     SET state = :state,
                         blocked_until = :blocked_until,
                         block_origin = :block_origin,
                         release_policy = :release_policy,
                         reason_code = :reason_code,
                         command_version = :command_version,
                         changed_by_user_id = :changed_by_user_id,
                         changed_at = :changed_at,
                         updated_at = :updated_at
                     WHERE id = :id AND command_version = :previous_command_version',
                    [
                        'id' => (int) $control['id'],
                        'previous_command_version' => $previousCommandVersion,
                        'state' => 'active',
                        'blocked_until' => $blockedUntil,
                        'block_origin' => $origin,
                        'release_policy' => $releasePolicy,
                        'reason_code' => 'temporary_' . $origin,
                        'command_version' => $commandVersion,
                        'changed_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                        'changed_at' => $now,
                        'updated_at' => $now,
                    ]
                );
                if ($updated !== 1) {
                    throw new ApiException('device_control_conflict', 409);
                }
            }

            $this->revokeDeviceSessions($clientId, $deviceId, $now);
            $this->writeAudit($clientId, $deviceId, $commandVersion, 'temporarily_blocked', $origin, $actorType, $actorUserId > 0 ? $actorUserId : null);
            return array_merge($control, [
                'state' => 'active',
                'blocked_until' => $blockedUntil,
                'block_origin' => $origin,
                'release_policy' => $releasePolicy,
                'reason_code' => 'temporary_' . $origin,
                'command_version' => $commandVersion,
            ]);
        });
    }

    /** @return array<string, mixed> */
    public function releaseTemporaryBlock(
        int $clientId,
        string $deviceId,
        int $actorUserId,
        bool $isAdmin,
    ): array {
        if ($clientId <= 0) {
            throw new ApiException('device_block_invalid', 422);
        }
        $deviceId = $this->requireStableDeviceId($deviceId);
        return $this->db->transaction(function () use ($clientId, $deviceId, $actorUserId, $isAdmin): array {
            $control = $this->controlFor($clientId, $deviceId);
            if ($control === null) {
                throw new ApiException('device_control_not_found', 404);
            }
            if ((string) ($control['state'] ?? '') === 'banned'
                && ($control['blocked_until'] ?? null) === null) {
                throw new ApiException('device_block_release_forbidden', 403);
            }
            if ((string) ($control['state'] ?? '') === 'kicked'
                && ($control['blocked_until'] ?? null) === null) {
                throw new ApiException('device_block_release_forbidden', 403);
            }
            $activeBlock = $this->activeTemporaryBlock($control);
            if ($activeBlock === null) {
                $this->clearExpiredTemporaryBlock($control);
                $latest = $this->controlFor($clientId, $deviceId) ?? $control;
                return array_merge($latest, [
                    'state' => 'active',
                    'blocked_until' => null,
                    'block_origin' => null,
                    'release_policy' => null,
                ]);
            }
            if (!$isAdmin && (string) ($control['release_policy'] ?? '') !== 'tenant_or_admin') {
                throw new ApiException('device_block_release_forbidden', 403);
            }
            $now = gmdate('Y-m-d H:i:s');
            $previousCommandVersion = max(0, (int) ($control['command_version'] ?? 0));
            $commandVersion = $previousCommandVersion + 1;
            $updated = $this->db->execute(
                'UPDATE client_device_controls
                 SET state = :state,
                     blocked_until = :blocked_until,
                     block_origin = :block_origin,
                     release_policy = :release_policy,
                     reason_code = :reason_code,
                     command_version = :command_version,
                     changed_by_user_id = :changed_by_user_id,
                     changed_at = :changed_at,
                     updated_at = :updated_at
                 WHERE id = :id AND command_version = :previous_command_version',
                [
                    'id' => (int) $control['id'],
                    'state' => 'active',
                    'previous_command_version' => $previousCommandVersion,
                    'blocked_until' => null,
                    'block_origin' => null,
                    'release_policy' => null,
                    'reason_code' => null,
                    'command_version' => $commandVersion,
                    'changed_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                    'changed_at' => $now,
                    'updated_at' => $now,
                ]
            );
            if ($updated !== 1) {
                throw new ApiException('device_control_conflict', 409);
            }
            $this->writeAudit(
                $clientId,
                $deviceId,
                $commandVersion,
                'temporary_block_released',
                (string) ($control['block_origin'] ?? ''),
                $isAdmin ? 'admin' : 'tenant',
                $actorUserId > 0 ? $actorUserId : null
            );
            return array_merge($control, [
                'state' => 'active',
                'blocked_until' => null,
                'block_origin' => null,
                'release_policy' => null,
                'reason_code' => null,
                'command_version' => $commandVersion,
            ]);
        });
    }

    /** @return array<string, mixed>|null */
    public function temporaryBlockFor(int $clientId, string $deviceId): ?array
    {
        if ($clientId <= 0) {
            return null;
        }
        $deviceId = $this->requireStableDeviceId($deviceId);
        $control = $this->controlFor($clientId, $deviceId);
        if ($control === null) {
            return null;
        }
        $active = $this->activeTemporaryBlock($control);
        if ($active !== null) {
            return $active;
        }
        $this->clearExpiredTemporaryBlock($control);
        return null;
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

    /** @param array<string, mixed> $control @return array<string, mixed>|null */
    private function activeTemporaryBlock(array $control): ?array
    {
        $blockedUntil = $control['blocked_until'] ?? null;
        if ($blockedUntil !== null && (!is_string($blockedUntil) || trim($blockedUntil) === '')) {
            return $control;
        }
        $timestamp = is_string($blockedUntil) ? strtotime($blockedUntil . ' UTC') : false;
        if ($blockedUntil !== null && $timestamp === false) {
            // Treat a malformed persisted timer as an active block. This is
            // deliberately fail-closed until an administrator repairs it.
            return $control;
        }
        return $timestamp !== false && $timestamp > time() ? $control : null;
    }

    /** @param array<string, mixed> $control */
    private function throwIfTemporarilyBlocked(array $control, bool $protocolV1 = false): void
    {
        $active = $this->activeTemporaryBlock($control);
        if ($active === null) {
            $this->clearExpiredTemporaryBlock($control);
            return;
        }
        $timestamp = strtotime((string) $active['blocked_until'] . ' UTC');
        if ($timestamp === false) {
            throw new ApiException('device_temporarily_banned', 423, [
                'retry_after_seconds' => 1800,
                'block_origin' => (string) ($active['block_origin'] ?? 'policy'),
                'release_policy' => (string) ($active['release_policy'] ?? 'admin_only'),
            ]);
        }
        $details = [
            'blocked_until' => gmdate('Y-m-d\TH:i:s\Z', (int) $timestamp),
            'retry_after_seconds' => max(1, (int) $timestamp - time()),
            'block_origin' => (string) ($active['block_origin'] ?? ''),
            'release_policy' => (string) ($active['release_policy'] ?? 'admin_only'),
        ];
        if (!$protocolV1) {
            $details['command'] = [
                'version' => max(0, (int) ($control['command_version'] ?? 0)),
                'action' => 'kick',
                'reason' => (string) ($control['reason_code'] ?? 'temporary_block'),
            ];
            throw new ApiException('device_kicked', 409, $details);
        }
        throw new ApiException('device_temporarily_banned', 423, $details);
    }

    /** @param array<string, mixed> $control */
    private function clearExpiredTemporaryBlock(array $control): void
    {
        if (($control['blocked_until'] ?? null) === null || (int) ($control['id'] ?? 0) <= 0) {
            return;
        }
        $timestamp = is_string($control['blocked_until']) ? strtotime($control['blocked_until'] . ' UTC') : false;
        if ($timestamp === false || $timestamp > time()) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $previousVersion = max(0, (int) ($control['command_version'] ?? 0));
        $commandVersion = $previousVersion + 1;
        $updated = $this->db->execute(
            'UPDATE client_device_controls
             SET state = :state,
                 blocked_until = :blocked_until,
                 block_origin = :block_origin,
                 release_policy = :release_policy,
                 reason_code = :reason_code,
                 command_version = :command_version,
                 changed_by_user_id = NULL,
                 changed_at = :changed_at,
                 updated_at = :updated_at
             WHERE id = :id AND command_version = :previous_command_version
               AND blocked_until = :previous_blocked_until',
            [
                'id' => (int) $control['id'],
                'state' => 'active',
                'blocked_until' => null,
                'block_origin' => null,
                'release_policy' => null,
                'reason_code' => null,
                'command_version' => $commandVersion,
                'previous_command_version' => $previousVersion,
                'previous_blocked_until' => (string) $control['blocked_until'],
                'changed_at' => $now,
                'updated_at' => $now,
            ]
        );
        if ($updated !== 1) {
            return;
        }
        $this->writeAudit(
            (int) ($control['client_id'] ?? 0),
            (string) ($control['device_id'] ?? ''),
            $commandVersion,
            'temporary_block_expired',
            (string) ($control['block_origin'] ?? ''),
            'system',
            null
        );
    }

    private function revokeDeviceSessions(int $clientId, string $deviceId, string $now): void
    {
        $this->db->execute(
            'UPDATE android_device_sessions
             SET revoked_at = :revoked_at
             WHERE client_id = :client_id AND device_id = :device_id AND revoked_at IS NULL',
            ['client_id' => $clientId, 'device_id' => $deviceId, 'revoked_at' => $now]
        );
        $this->db->execute(
            'DELETE FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id',
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

    private function decodeRenewalField(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 16384) {
            throw new ApiException('device_renewal_invalid', 403);
        }
        $decoded = base64_decode($value, true);
        if (!is_string($decoded) || strlen($decoded) < 16 || strlen($decoded) > 4096) {
            throw new ApiException('device_renewal_invalid', 403);
        }
        return $decoded;
    }

    private function requireStableDeviceId(string $deviceId): string
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '' || strlen($deviceId) > 191 || preg_match('/[\x00-\x20\x7f]/', $deviceId) === 1) {
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

    /** @return array<int, string> */
    private static function configDigests(string $value): array
    {
        $parts = preg_split('/[\s,;]+/', trim($value)) ?: [];
        return array_values(array_filter(array_map(
            static fn(string $item): string => strtolower(trim($item)),
            $parts
        ), static fn(string $item): bool => preg_match('/^[a-f0-9]{64}$/', $item) === 1));
    }
}
