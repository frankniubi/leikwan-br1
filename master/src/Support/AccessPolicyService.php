<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use DateTimeImmutable;
use DateTimeZone;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use Throwable;

/**
 * Distribution-neutral access decision engine.
 *
 * Native clients only need to implement the challenge/report contract. All
 * blacklist, geo-lock, rate-limit, and account-ban decisions are made here so
 * Android, Windows, macOS, and future iOS clients share one policy.
 */
class AccessPolicyService
{
    public const CHALLENGE_TTL_SECONDS = 300;
    public const OBSERVATION_CACHE_SECONDS = 86400;
    public const OBSERVATION_MAX_AGE_SECONDS = 900;
    public const POLL_INTERVAL_SECONDS = 1800;
    public const POLL_JITTER_SECONDS = 300;
    public const VIOLATION_WINDOW_SECONDS = 86400;
    public const TEMPORARY_BLOCK_SECONDS = 1800;

    private AccessPolicyStore $store;
    private MainlandRegionPolicy $regions;
    private TrustedClientIpResolver $clientIpResolver;
    private DateTimeImmutable $clock;

    public function __construct(
        Database $db,
        ?MainlandRegionPolicy $regions = null,
        ?DateTimeImmutable $clock = null,
        ?AccessPolicyStore $store = null,
        ?TrustedClientIpResolver $clientIpResolver = null,
    ) {
        $this->store = $store ?? new AccessPolicyStore($db);
        $this->regions = $regions ?? new MainlandRegionPolicy();
        $this->clock = ($clock ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $this->clientIpResolver = $clientIpResolver ?? new TrustedClientIpResolver();
    }

    /** @param array<string, mixed> $client @return array<string, mixed> */
    public function issueLocationChallenge(
        array $client,
        string $deviceUuid,
        string $attemptId,
        string $platform = 'unknown',
        ?Request $request = null,
    ): array {
        $context = $this->clientContext($client, $deviceUuid, $attemptId, $platform);
        $this->assertAccountUsable($context['user_id']);
        if ($request !== null) {
            $protocolVersion = (int) $request->input('protocol_version', 1);
            if ($protocolVersion !== 1) {
                throw new ApiException('location_protocol_version_unsupported', 422, ['supported' => [1]]);
            }
            $sourceIp = $this->resolvedSourceIp($request);
            if ($sourceIp === null) {
                throw new ApiException('source_ip_unavailable', 400);
            }
            $this->enforceGlobalRules($context, $sourceIp);
        }
        $this->assertDeviceUsable($context['client_id'], $context['device_uuid']);

        $token = $this->randomToken();
        $now = $this->now();
        $expiresAt = $now->modify('+' . self::CHALLENGE_TTL_SECONDS . ' seconds');
        $this->store->insertChallenge([
            'token_hash' => hash('sha256', $token),
            'auth_context_hash' => $this->authContextHash($context),
            'user_id' => $context['user_id'],
            'client_id' => $context['client_id'] > 0 ? $context['client_id'] : null,
            'device_uuid' => $context['device_uuid'],
            'attempt_id' => $context['attempt_id'],
            'platform' => $context['platform'],
            'expires_at' => $this->sqlTime($expiresAt),
            'consumed_at' => null,
            'created_at' => $this->sqlTime($now),
        ]);

        return [
            'protocol_version' => 1,
            'token' => $token,
            'attempt_id' => $context['attempt_id'],
            'platform' => $context['platform'],
            'expires_at' => $expiresAt->format('Y-m-d\\TH:i:s\\Z'),
            'cache_ttl_seconds' => self::OBSERVATION_CACHE_SECONDS,
            'poll_interval_seconds' => self::POLL_INTERVAL_SECONDS,
            'poll_jitter_seconds' => self::POLL_JITTER_SECONDS,
            'provider' => 'bilibili_zone',
        ];
    }

    /** Stable protocol name used by all distribution adapters. */
    public function issueChallenge(
        array $client,
        string $deviceUuid,
        string $attemptId,
        string $platform = 'unknown',
        ?Request $request = null,
    ): array {
        return $this->issueLocationChallenge($client, $deviceUuid, $attemptId, $platform, $request);
    }

    public function effectiveGeoLockForUser(int $userId): bool
    {
        return $this->store->geoLockEnabledForUser($userId);
    }

    /**
     * Accept and evaluate one challenge-bound location report. The report may
     * contain an effective (possibly proxied) observation and, when required,
     * a physical_no_proxy observation collected by the client.
     *
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    public function recordLocationReport(array $client, Request $request): array
    {
        $encodedBody = json_encode($request->body(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bodySize = max(strlen($request->rawBody()), is_string($encodedBody) ? strlen($encodedBody) : 0);
        if ($bodySize > 32768) {
            throw new ApiException('location_report_too_large', 413);
        }
        $protocolVersion = (int) $request->input('protocol_version', 1);
        if ($protocolVersion !== 1) {
            throw new ApiException('location_protocol_version_unsupported', 422, ['supported' => [1]]);
        }
        $deviceUuid = $this->deviceUuidFromRequest($request);
        $attemptId = $this->requiredAttemptId((string) $request->input('attempt_id', ''));
        $token = trim((string) ($request->input('challenge_token', $request->bearerToken() ?? '')));
        if ($token === '') {
            throw new ApiException('location_challenge_invalid', 403);
        }
        $reportedPlatform = trim((string) ($request->input('platform', '') ?: $request->header('x-device-platform', '')));
        $context = $this->clientContext($client, $deviceUuid, $attemptId, $reportedPlatform === '' ? 'unknown' : $reportedPlatform);
        $this->assertAccountUsable($context['user_id']);
        $sourceIp = $this->resolvedSourceIp($request);
        if ($sourceIp === null) {
            throw new ApiException('source_ip_unavailable', 400);
        }
        $this->enforceGlobalRules($context, $sourceIp);
        $this->assertDeviceUsable($context['client_id'], $context['device_uuid']);

        $challenge = $this->store->challengeByHash(hash('sha256', $token));
        if ($context['platform'] === 'unknown' && is_array($challenge) && trim((string) ($challenge['platform'] ?? '')) !== '') {
            $context['platform'] = strtolower(trim((string) $challenge['platform']));
        }
        $this->assertChallenge($challenge, $context);
        $observationsInput = $request->input('observations', []);
        if (!is_array($observationsInput) || $observationsInput === []) {
            throw new ApiException('location_observations_required', 422);
        }

        $normalized = [];
        if (count($observationsInput) > 2) {
            throw new ApiException('location_observations_too_many', 422);
        }
        $seenModes = [];
        foreach ($observationsInput as $observation) {
            if (!is_array($observation)) {
                throw new ApiException('location_observation_invalid', 422);
            }
            $item = $this->normalizeObservation($observation, $sourceIp, $context);
            if (isset($seenModes[$item['collection_mode']])) {
                throw new ApiException('location_collection_mode_duplicate', 422);
            }
            $seenModes[$item['collection_mode']] = true;
            $normalized[] = $item;
        }
        $effective = $this->observationByMode($normalized, 'effective');
        if ($effective === null) {
            throw new ApiException('location_effective_observation_required', 422);
        }
        $physical = $this->observationByMode($normalized, 'physical_no_proxy');
        $matchedBlacklist = $this->matchBlacklistObservations($context, $sourceIp, $normalized);
        if (!$this->isMainlandCountry($effective['country_code'] ?? null)
            && $physical === null
            && $matchedBlacklist === null) {
            // Leave the challenge unconsumed so the client can submit the
            // required direct physical observation with the same attempt.
            throw new ApiException('direct_location_check_required', 428, [
                'challenge_token' => $token,
                'challenge_expires_at' => $this->isoTime($challenge['expires_at'] ?? null),
                'attempt_id' => $context['attempt_id'],
                'required_collection_mode' => 'physical_no_proxy',
            ]);
        }

        $transactionResult = $this->store->transaction(function () use ($challenge, $token, $context, $normalized, $effective, $physical, $sourceIp): array {
            $locked = $this->store->challengeByHash(hash('sha256', $token), true);
            $this->assertChallenge($locked, $context);
            if (!$this->store->consumeChallenge((int) $locked['id'], $this->sqlTime($this->now()))) {
                throw new ApiException('location_challenge_invalid', 403);
            }

            $observationRows = [];
            foreach ($normalized as $observation) {
                $observation['challenge_id'] = (int) $locked['id'];
                $observation['user_id'] = $context['user_id'];
                $observation['client_id'] = $context['client_id'] > 0 ? $context['client_id'] : null;
                $observation['device_uuid'] = $context['device_uuid'];
                $observation['attempt_id'] = $context['attempt_id'];
                $observation['platform'] = (string) $locked['platform'];
                $id = $this->store->insertObservation($observation);
                $observation['id'] = $id;
                $observationRows[] = $observation;
            }

            $effectiveRow = $this->rowForNormalized($observationRows, $effective);
            $physicalRow = $physical === null ? null : $this->rowForNormalized($observationRows, $physical);
            try {
                $decision = $this->evaluateObservations(
                    $context,
                    $sourceIp,
                    $observationRows,
                    $effectiveRow,
                    $physicalRow,
                    true
                );
            } catch (ApiException $exception) {
                // Policy denials intentionally commit their evidence and
                // device/account state. Validation and storage errors still
                // escape so Database::transaction() rolls them back.
                if (!in_array($exception->getMessage(), [
                    'access_blacklisted',
                    'geo_lock_violation',
                    'account_banned',
                    'device_temporarily_banned',
                    'device_banned',
                ], true)) {
                    throw $exception;
                }
                return ['policy_exception' => $exception];
            }
            $latest = $observationRows[0] ?? $effectiveRow;
            $decision['next_location_check_at'] = $this->nextLocationCheckAt($latest, $context, $sourceIp)
                ->format('Y-m-d\\TH:i:s\\Z');
            return ['decision' => $decision];
        });
        if (is_array($transactionResult) && ($transactionResult['policy_exception'] ?? null) instanceof ApiException) {
            throw $transactionResult['policy_exception'];
        }
        if (!is_array($transactionResult) || !is_array($transactionResult['decision'] ?? null)) {
            throw new ApiException('location_policy_transaction_invalid', 500);
        }
        return $transactionResult['decision'];
    }

    /**
     * Enforce policy at login, enrollment, pull, and heartbeat boundaries.
     * A successful call returns a compact decision payload; denied calls throw
     * ApiException with a stable protocol code and machine-readable details.
     *
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    public function authorizeClientRequest(
        array $client,
        Request $request,
        string $touchType = 'request',
        ?string $deviceUuid = null,
    ): array
    {
        $deviceUuid = $deviceUuid !== null ? trim($deviceUuid) : $this->deviceUuidFromRequest($request);
        $context = $this->clientContext(
            $client,
            $deviceUuid,
            $this->optionalAttemptId($request) ?? $this->generatedAttemptId(),
            (string) ($request->header('x-device-platform') ?? $request->input('platform', 'unknown'))
        );
        $this->assertAccountUsable($context['user_id']);
        $sourceIp = $this->resolvedSourceIp($request);
        if ($sourceIp === null) {
            throw new ApiException('source_ip_unavailable', 400);
        }

        $this->enforceGlobalRules($context, $sourceIp);
        $this->assertDeviceUsable($context['client_id'], $context['device_uuid']);

        $geoLockEnabled = $this->store->geoLockEnabledForUser($context['user_id']);
        $profile = $this->store->profileForUser($context['user_id']);
        $resetPending = $geoLockEnabled
            && is_array($profile)
            && (string) ($profile['state'] ?? '') === 'reset_pending';
        $needsLocation = $geoLockEnabled
            || $this->store->hasActiveGeographicRules()
            || $resetPending;
        if (!$needsLocation) {
            return [
                'decision' => 'allow',
                'touch_type' => $touchType,
                'source_ip' => $sourceIp,
                'location_required' => false,
            ];
        }

        $cutoff = $this->sqlTime($this->now()->modify('-' . self::OBSERVATION_CACHE_SECONDS . ' seconds'));
        $observations = $this->store->recentObservations($context['user_id'], $context['device_uuid'], $sourceIp, $cutoff);
        $selected = $this->selectCachedObservations($observations);
        $freshAuthentication = in_array($touchType, ['login', 'enrollment'], true);
        $selectedObservations = [];
        if ($selected !== null) {
            $selectedObservations[] = $selected['effective'];
            if ($selected['physical'] !== null) {
                $selectedObservations[] = $selected['physical'];
            }
        }
        $cachedBlacklist = $selectedObservations === []
            ? null
            : $this->matchBlacklistObservations($context, $sourceIp, $selectedObservations);
        // A fresh authentication is satisfied only by the selected coherent
        // attempt. An unrelated observation carrying the same attempt ID
        // must not suppress the login check.
        $attemptAlreadyVerified = $selected !== null
            && (string) ($selected['effective']['attempt_id'] ?? '') === $context['attempt_id'];
        $cachedDirectCheckRequired = $selected !== null
            && !$this->isMainlandCountry($selected['effective']['country_code'] ?? null)
            && $selected['physical'] === null
            && $cachedBlacklist === null;
        if ($selected === null
            || ($freshAuthentication && !$attemptAlreadyVerified && $cachedBlacklist === null)
            || (!$freshAuthentication && $this->cacheIsDue($selected['latest'], $context, $sourceIp))
            || $cachedDirectCheckRequired
            || $resetPending) {
            $challenge = $this->issueLocationChallenge(
                $client,
                $context['device_uuid'],
                $context['attempt_id'],
                $context['platform']
            );
            throw new ApiException('location_check_required', 428, [
                'challenge' => $challenge,
                'source_ip' => $sourceIp,
                'next_location_check_at' => $selected === null
                    ? $this->now()->format('Y-m-d\\TH:i:s\\Z')
                    : $this->nextLocationCheckAt($selected['latest'], $context, $sourceIp)->format('Y-m-d\\TH:i:s\\Z'),
            ]);
        }

        // Only the coherent attempt selected above participates in the
        // decision. Older cached attempts remain useful for audit/history,
        // but must not trigger a newly activated geographic deny rule.
        $evaluationResult = $this->store->transaction(function () use ($context, $sourceIp, $selectedObservations, $selected): array {
            try {
                return ['decision' => $this->evaluateObservations(
                    $context,
                    $sourceIp,
                    $selectedObservations,
                    $selected['effective'],
                    $selected['physical'],
                    false
                )];
            } catch (ApiException $exception) {
                if (!in_array($exception->getMessage(), [
                    'access_blacklisted',
                    'geo_lock_violation',
                    'account_banned',
                    'device_temporarily_banned',
                    'device_banned',
                ], true)) {
                    throw $exception;
                }
                return ['policy_exception' => $exception];
            }
        });
        if (is_array($evaluationResult) && ($evaluationResult['policy_exception'] ?? null) instanceof ApiException) {
            throw $evaluationResult['policy_exception'];
        }
        if (!is_array($evaluationResult) || !is_array($evaluationResult['decision'] ?? null)) {
            throw new ApiException('location_policy_transaction_invalid', 500);
        }
        $decision = $evaluationResult['decision'];
        $decision['next_location_check_at'] = $this->nextLocationCheckAt($selected['latest'], $context, $sourceIp)->format('Y-m-d\\TH:i:s\\Z');
        return $decision;
    }

    /**
     * Login-stage guard used before a desktop session is issued. It performs
     * only checks that do not require a selected client/location report;
     * selectDesktopClient performs the full geo decision before returning an
     * agent token.
     */
    public function preflightClientRequest(array $client, Request $request, ?string $deviceUuid = null): void
    {
        $deviceUuid = $deviceUuid !== null ? trim($deviceUuid) : $this->deviceUuidFromRequest($request);
        $context = $this->clientContext(
            $client,
            $deviceUuid,
            $this->optionalAttemptId($request) ?? $this->generatedAttemptId(),
            (string) ($request->header('x-device-platform') ?? $request->input('platform', 'unknown'))
        );
        $this->assertAccountUsable($context['user_id']);
        $sourceIp = $this->resolvedSourceIp($request);
        if ($sourceIp === null) {
            throw new ApiException('source_ip_unavailable', 400);
        }
        $this->enforceGlobalRules($context, $sourceIp);
        $this->assertDeviceUsable($context['client_id'], $context['device_uuid']);
    }

    /**
     * Return the safe distribution handoff state without issuing or consuming
     * a location challenge.
     *
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    public function policyStatus(array $client, Request $request, ?string $deviceUuid = null): array
    {
        $deviceUuid = $deviceUuid !== null ? trim($deviceUuid) : $this->deviceUuidFromRequest($request);
        $context = $this->clientContext(
            $client,
            $deviceUuid,
            $this->optionalAttemptId($request) ?? $this->generatedAttemptId(),
            (string) ($request->header('x-device-platform') ?? $request->input('platform', 'unknown'))
        );
        $this->assertAccountUsable($context['user_id']);
        $sourceIp = $this->resolvedSourceIp($request);
        if ($sourceIp === null) {
            throw new ApiException('source_ip_unavailable', 400);
        }
        $this->enforceGlobalRules($context, $sourceIp);
        $this->assertDeviceUsable($context['client_id'], $context['device_uuid']);

        $geoLockEnabled = $this->store->geoLockEnabledForUser($context['user_id']);
        $locationPolicyRequired = $geoLockEnabled || $this->store->hasActiveGeographicRules();
        $profile = $this->store->profileForUser($context['user_id']);
        $status = [
            'protocol_version' => 1,
            'geo_lock_enabled' => $geoLockEnabled,
            'location_required' => $locationPolicyRequired,
            'location_check_due' => false,
            'main_province_code' => $profile['main_province_code'] ?? null,
            'profile_state' => $profile['state'] ?? 'unbound',
            'next_location_check_at' => null,
        ];
        if (!$locationPolicyRequired) {
            return $status;
        }

        $cutoff = $this->sqlTime($this->now()->modify('-' . self::OBSERVATION_CACHE_SECONDS . ' seconds'));
        $observations = $this->store->recentObservations($context['user_id'], $context['device_uuid'], $sourceIp, $cutoff);
        $selected = $this->selectCachedObservations($observations);
        if ($selected === null) {
            $status['location_check_due'] = true;
            return $status;
        }
        if ($geoLockEnabled && (string) ($profile['state'] ?? '') === 'reset_pending') {
            $status['location_check_due'] = true;
            $status['next_location_check_at'] = $this->now()->format('Y-m-d\TH:i:s\Z');
            return $status;
        }
        if (!$this->isMainlandCountry($selected['effective']['country_code'] ?? null)
            && $selected['physical'] === null) {
            $status['location_check_due'] = true;
            $status['next_location_check_at'] = $this->now()->format('Y-m-d\TH:i:s\Z');
            return $status;
        }
        $next = $this->nextLocationCheckAt($selected['latest'], $context, $sourceIp);
        $status['location_check_due'] = $next <= $this->now();
        $status['next_location_check_at'] = $next->format('Y-m-d\\TH:i:s\\Z');
        return $status;
    }

    /**
     * Normalize and persist an admin blacklist rule. Admin controllers can
     * pass the returned row directly to their response renderer.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveBlacklistRule(array $payload, ?int $actorId = null): array
    {
        $row = $this->normalizeBlacklistRule($payload);
        $row['created_by_user_id'] = $actorId;
        $row['updated_by_user_id'] = $actorId;
        return $this->store->saveBlacklistRule($row);
    }

    /** @return array<int, array<string, mixed>> */
    public function listBlacklistRules(array $filters = []): array
    {
        $entityType = isset($filters['entity_type']) ? trim((string) $filters['entity_type']) : null;
        $activeOnly = array_key_exists('active_only', $filters) ? (bool) $filters['active_only'] : null;
        return $this->store->listBlacklistRules($entityType !== '' ? $entityType : null, $activeOnly);
    }

    public function registerBlacklistRule(array $payload, int $actorUserId = 0): array
    {
        return $this->saveBlacklistRule($payload, $actorUserId > 0 ? $actorUserId : null);
    }

    public function setBlacklistRuleActive(int $ruleId, bool $active, int $actorId = 0): bool
    {
        if ($ruleId <= 0) {
            throw new ApiException('blacklist_rule_invalid', 422);
        }
        return $this->store->setBlacklistRuleActive($ruleId, $active, $actorId);
    }

    /** @return array<int, array<string, mixed>> */
    public function adminProfiles(int $limit = 100): array
    {
        return $this->store->adminProfiles($limit);
    }

    /** @return array<int, array<string, mixed>> */
    public function adminViolations(int $limit = 100): array
    {
        return $this->store->adminViolations($limit);
    }

    /** @return array<int, array<string, mixed>> */
    public function adminDeviceBlocks(int $limit = 200): array
    {
        return $this->store->adminDeviceBlocks($limit);
    }

    /** @return array<int, array<string, mixed>> */
    public function adminAccountBans(int $limit = 100): array
    {
        return $this->store->adminAccountBans($limit);
    }

    /** @return array<string, mixed>|null */
    public function tenantProfile(int $userId): ?array
    {
        $profile = $this->store->profileForUser($userId);
        if ($profile === null) {
            return null;
        }
        return [
            'state' => (string) ($profile['state'] ?? 'unbound'),
            'main_province_code' => $profile['main_province_code'] ?? null,
            'previous_province_code' => $profile['previous_province_code'] ?? null,
            'last_verified_at' => $profile['last_verified_at'] ?? null,
            'self_change_available_at' => $profile['self_change_available_at'] ?? null,
            'reset_generation' => (int) ($profile['reset_generation'] ?? 0),
            'reset_started_at' => $profile['reset_started_at'] ?? null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function tenantDeviceRows(int $userId): array
    {
        return $this->store->tenantDeviceRows($userId);
    }

    /** @return array<string, mixed> */
    public function releaseDeviceBlock(int $clientId, string $deviceUuid, int $actorUserId, bool $isAdmin = true): array
    {
        if (!$isAdmin || $actorUserId <= 0) {
            throw new ApiException('device_block_release_forbidden', 403);
        }
        $deviceUuid = trim($deviceUuid);
        $row = $this->store->transaction(function () use ($clientId, $deviceUuid, $actorUserId): ?array {
            $control = $this->store->controlFor($clientId, $deviceUuid);
            if ($control === null) {
                throw new ApiException('device_control_not_found', 404);
            }
            $state = (string) ($control['state'] ?? '');
            if ($state === 'banned') {
                return $this->store->releasePermanentDeviceBlock($clientId, $deviceUuid, $actorUserId);
            }
            if (($control['blocked_until'] ?? null) !== null) {
                return $this->store->releaseTemporaryDeviceBlock($clientId, $deviceUuid, $actorUserId);
            }
            if ($state === 'kicked') {
                return $this->store->releasePermanentDeviceBlock($clientId, $deviceUuid, $actorUserId);
            }
            throw new ApiException('device_block_release_forbidden', 403);
        });
        if ($row === null) {
            throw new ApiException('device_control_not_found', 404);
        }
        return $row;
    }

    public function releaseAccountBan(int $userId, int $actorUserId, ?string $note = null): void
    {
        if ($actorUserId <= 0) {
            throw new ApiException('account_ban_release_forbidden', 403);
        }
        if (!$this->store->releaseAccountBan($userId, $actorUserId, $note)) {
            throw new ApiException('account_ban_not_found', 404);
        }
    }

    /**
     * Change a user's main province. Self-service changes are limited to once
     * per rolling 24 hours and must be backed by a fresh valid observation.
     * Admin changes may bypass that window but still require a known province.
     *
     * @return array<string, mixed>
     */
    public function changeMainProvince(int $userId, string $province, int $actorUserId = 0, bool $admin = false): array
    {
        $provinceCode = $this->regions->normalizeProvince($province);
        if ($userId <= 0 || $provinceCode === null) {
            throw new ApiException('main_province_invalid', 422);
        }
        $now = $this->now();
        return $this->store->transaction(function () use ($userId, $provinceCode, $actorUserId, $admin, $now): array {
            $profile = $this->store->profileForUser($userId, true);
            if ($profile === null) {
                throw new ApiException('main_province_unbound', 409);
            }
            if ((string) ($profile['state'] ?? '') === 'reset_pending') {
                throw new ApiException('main_province_reset_pending', 409);
            }
            if (!$admin && $this->isFuture($profile['self_change_available_at'] ?? null, $now)) {
                throw new ApiException('main_province_change_rate_limited', 429, [
                    'available_at' => (string) $profile['self_change_available_at'],
                ]);
            }
            if (!$admin) {
                $freshCutoff = $this->sqlTime($now->modify('-600 seconds'));
                $valid = false;
                foreach ($this->store->recentUserObservations($userId, $freshCutoff) as $observation) {
                    if ((string) ($observation['province_code'] ?? '') === $provinceCode
                        && $this->isMainlandCountry($observation['country_code'] ?? null)) {
                        $valid = true;
                        break;
                    }
                }
                if (!$valid) {
                    throw new ApiException('main_province_observation_required', 409);
                }
            }
            $previous = trim((string) ($profile['main_province_code'] ?? ''));
            $this->store->updateProfile($userId, [
                'state' => 'bound',
                'previous_province_code' => $previous !== '' ? $previous : null,
                'main_province_code' => $provinceCode,
                'self_change_available_at' => $admin ? ($profile['self_change_available_at'] ?? null) : $this->sqlTime($now->modify('+86400 seconds')),
                'last_verified_at' => $this->sqlTime($now),
                'updated_at' => $this->sqlTime($now),
            ]);
            $this->store->insertPolicyEvent([
                'decision_id' => bin2hex(random_bytes(16)),
                'user_id' => $userId,
                'client_id' => null,
                'device_uuid' => null,
                'source_ip' => null,
                'event_type' => 'province_change',
                'decision' => 'allow',
                'reason_code' => $admin ? 'admin_change' : 'tenant_change',
                'matched_entity_type' => null,
                'matched_observation_id' => null,
                'attempt_id' => 'province-change-' . bin2hex(random_bytes(12)),
                'counts_as_geo_violation' => 0,
                'details_digest' => hash('sha256', implode('|', [$previous, $provinceCode, (string) $actorUserId])),
                'created_at' => $this->sqlTime($now),
            ]);
            return array_merge($profile, [
                'state' => 'bound',
                'previous_province_code' => $previous !== '' ? $previous : null,
                'main_province_code' => $provinceCode,
            ]);
        });
    }

    /** @return array{reset_generation:int,snapshot_count:int,revoked_count:int,idempotent:bool} */
    public function beginAllDeviceReset(int $userId, int $actorUserId): array
    {
        if ($userId <= 0 || $actorUserId <= 0) {
            throw new ApiException('device_reset_invalid', 422);
        }
        $now = $this->now();
        return $this->store->transaction(function () use ($userId, $actorUserId, $now): array {
            if ($this->store->userById($userId, true) === null) {
                throw new ApiException('user_not_found', 404);
            }
            $pending = $this->store->activeResetGeneration($userId, true);
            if ($pending !== null) {
                return [
                    'reset_generation' => (int) ($pending['generation'] ?? 0),
                    'snapshot_count' => count($this->store->resetDevicesForGeneration((int) $pending['id'])),
                    'revoked_count' => 0,
                    'idempotent' => true,
                ];
            }

            $profile = $this->store->profileForUser($userId, true);
            $currentProvince = $this->regions->normalizeProvince((string) ($profile['main_province_code'] ?? ''));
            $generation = max(0, (int) ($profile['reset_generation'] ?? 0)) + 1;
            $devices = $this->deduplicateDevices($this->store->knownDevicesForUser($userId));
            $reset = $this->store->createResetGeneration([
                'user_id' => $userId,
                'generation' => $generation,
                'previous_province_code' => $currentProvince,
                'state' => 'pending',
                'started_at' => $this->sqlTime($now),
                'completed_at' => null,
                'created_by_user_id' => $actorUserId,
            ]);
            foreach ($devices as $device) {
                $this->store->insertResetDevice([
                    'reset_generation_id' => (int) $reset['id'],
                    'user_id' => $userId,
                    'client_id' => $device['client_id'],
                    'device_uuid' => $device['device_uuid'],
                    'blocked_until' => null,
                    'block_created_at' => null,
                    'created_at' => $this->sqlTime($now),
                ]);
            }

            $profileData = [
                'state' => 'reset_pending',
                'main_province_code' => null,
                'previous_province_code' => $currentProvince,
                'reset_generation' => $generation,
                'reset_started_at' => $this->sqlTime($now),
                'updated_at' => $this->sqlTime($now),
            ];
            if ($profile === null) {
                $this->store->createProfile($userId, $profileData + [
                    'bound_observation_id' => null,
                    'last_verified_at' => null,
                    'self_change_available_at' => null,
                    'created_at' => $this->sqlTime($now),
                ]);
            } else {
                $this->store->updateProfile($userId, $profileData);
            }
            $revoked = $this->store->revokeApplicationSessions($userId, $this->sqlTime($now));
            return [
                'reset_generation' => $generation,
                'snapshot_count' => count($devices),
                'revoked_count' => $revoked,
                'idempotent' => false,
            ];
        });
    }

    /** @return MainlandRegionPolicy */
    public function regions(): MainlandRegionPolicy
    {
        return $this->regions;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $observations
     * @param array<string, mixed> $effective
     * @param array<string, mixed>|null $physical
     * @return array<string, mixed>
     */
    private function evaluateObservations(
        array $context,
        string $sourceIp,
        array $observations,
        array $effective,
        ?array $physical,
        bool $persisted,
    ): array {
        $matched = $this->matchBlacklistObservations($context, $sourceIp, $observations);
        if ($matched !== null) {
            $this->recordBlacklistEvent($context, $sourceIp, $matched, $effective);
            throw new ApiException('access_blacklisted', 403, [
                'matched_entity_type' => $matched['entity_type'],
                'matched_value' => $matched['display_value'] ?? $matched['canonical_value'],
                'observation_id' => $effective['id'] ?? null,
                'counts_as_geo_violation' => false,
            ]);
        }

        $geoEnabled = $this->store->geoLockEnabledForUser($context['user_id']);
        $hasGeoRules = $this->store->hasActiveGeographicRules();
        $countryCode = $this->normalizeCountryCode((string) ($effective['country_code'] ?? ''));
        if (!$geoEnabled && !$hasGeoRules) {
            return [
                'decision' => 'allow',
                'location_required' => false,
                'source_ip' => $sourceIp,
                'observation_id' => $effective['id'] ?? null,
            ];
        }

        // Geographic blacklist rules only decide whether the observed
        // entity is denied. They do not implicitly enable the account-wide
        // main-province adjacency policy; that policy is controlled solely by
        // the effective plan switch.
        if (!$geoEnabled) {
            return [
                'decision' => 'allow',
                'location_required' => true,
                'geo_lock_enabled' => false,
                'source_ip' => $sourceIp,
                'country_code' => $countryCode,
                'observation_id' => $effective['id'] ?? null,
                'persisted' => $persisted,
            ];
        }

        // A foreign effective result is accepted only when physical_no_proxy
        // supplied a Mainland observation; use that observation for geo lock.
        $selected = $effective;
        if (!$this->isMainlandCountry($countryCode) && $physical !== null) {
            $selected = $physical;
            $countryCode = $this->normalizeCountryCode((string) ($selected['country_code'] ?? ''));
        }
        if (!$this->isMainlandCountry($countryCode)) {
            $this->raiseGeoViolation($context, $sourceIp, $effective, 'foreign_location');
        }

        $province = $this->regions->normalizeProvince((string) ($selected['province_code'] ?? ''));
        if ($province === null) {
            $this->raiseGeoViolation($context, $sourceIp, $selected, 'mainland_province_missing');
        }

        $profile = $this->store->profileForUser($context['user_id'], true);
        $resetResult = [];
        if ($geoEnabled) {
            if ($profile === null) {
                $now = $this->now();
                $profile = $this->store->createProfile($context['user_id'], [
                    'state' => 'bound',
                    'main_province_code' => $province,
                    'previous_province_code' => null,
                    'bound_observation_id' => $selected['id'] ?? null,
                    'last_verified_at' => $this->sqlTime($now),
                    // No prior self-change exists at first bind; the first
                    // user-initiated change is therefore immediately eligible.
                    'self_change_available_at' => null,
                    'reset_generation' => 0,
                    'reset_started_at' => null,
                    'created_at' => $this->sqlTime($now),
                    'updated_at' => $this->sqlTime($now),
                ]);
            } else {
                if ((string) ($profile['state'] ?? '') === 'reset_pending') {
                    $resetResult = $this->completeResetBinding($context, $profile, $selected, $province);
                    $profile = array_merge($profile, [
                        'state' => 'bound',
                        'main_province_code' => $province,
                        'bound_observation_id' => $selected['id'] ?? null,
                    ]);
                    if (!empty($resetResult['current_device_permanent'])) {
                        throw new ApiException('device_banned', 403, [
                            'reason' => 'province_reset',
                        ]);
                    }
                    if (!empty($resetResult['current_device_blocked'])) {
                        $blockedUntil = (string) ($resetResult['current_device_blocked_until'] ?? $this->sqlTime($this->now()->modify('+3600 seconds')));
                        throw new ApiException('device_temporarily_banned', 423, [
                            'blocked_until' => $blockedUntil,
                            'retry_after_seconds' => max(1, strtotime($blockedUntil . ' UTC') - $this->now()->getTimestamp()),
                            'block_origin' => 'province_reset',
                            'release_policy' => 'admin_only',
                        ]);
                    }
                } else {
                    $main = $this->regions->normalizeProvince((string) ($profile['main_province_code'] ?? ''));
                    if ($main === null) {
                        $now = $this->now();
                        $this->store->updateProfile($context['user_id'], [
                            'state' => 'bound',
                            'main_province_code' => $province,
                            'bound_observation_id' => $selected['id'] ?? null,
                            'last_verified_at' => $this->sqlTime($now),
                            'self_change_available_at' => null,
                            'updated_at' => $this->sqlTime($now),
                        ]);
                        $profile['main_province_code'] = $province;
                    } elseif (!in_array($province, $this->regions->allowedProvinces($main), true)) {
                        $this->raiseGeoViolation($context, $sourceIp, $selected, 'non_adjacent_province', $main, $province);
                    } else {
                        $this->store->updateProfile($context['user_id'], [
                            'last_verified_at' => $this->sqlTime($this->now()),
                            'bound_observation_id' => $selected['id'] ?? ($profile['bound_observation_id'] ?? null),
                            'updated_at' => $this->sqlTime($this->now()),
                        ]);
                    }
                }
            }
        }

        return [
            'decision' => 'allow',
            'location_required' => true,
            'geo_lock_enabled' => $geoEnabled,
            'source_ip' => $sourceIp,
            'province_code' => $province,
            'main_province_code' => $profile['main_province_code'] ?? $province,
            'observation_id' => $selected['id'] ?? null,
            'persisted' => $persisted,
        ] + $resetResult;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $observation
     * @return array<string, mixed>
     */
    private function completeResetBinding(array $context, array $profile, array $observation, string $province): array
    {
        $now = $this->now();
        $reset = $this->store->activeResetGeneration($context['user_id'], true);
        $previous = $this->regions->normalizeProvince((string) ($profile['previous_province_code'] ?? ''));
        $changed = $previous !== null && $previous !== $province;
        $blockedCount = 0;
        $currentDeviceBlocked = false;
        $currentDevicePermanent = false;
        $currentDeviceBlockedUntil = null;

        if ($reset !== null) {
            $devices = $this->store->resetDevicesForGeneration((int) $reset['id']);
            if ($changed) {
                $blockedUntil = $this->sqlTime($now->modify('+3600 seconds'));
                foreach ($devices as $device) {
                    $clientId = (int) ($device['client_id'] ?? 0);
                    $deviceUuid = trim((string) ($device['device_uuid'] ?? ''));
                    if ($clientId <= 0 || $deviceUuid === '') {
                        continue;
                    }
                    if (($device['block_created_at'] ?? null) !== null) {
                        // A partially completed retry may already have marked
                        // this snapshot row. Re-read the control so the
                        // current old UUID is still denied after the reset.
                        if ($clientId === $context['client_id'] && hash_equals($deviceUuid, $context['device_uuid'])) {
                            $control = $this->store->controlFor($clientId, $deviceUuid);
                            $currentDevicePermanent = is_array($control)
                                && (string) ($control['state'] ?? '') === 'banned'
                                && ($control['blocked_until'] ?? null) === null;
                            $currentDeviceBlockedUntil = is_array($control)
                                ? (($control['blocked_until'] ?? null) !== null ? (string) $control['blocked_until'] : null)
                                : null;
                            $currentDeviceBlocked = $currentDevicePermanent || $currentDeviceBlockedUntil !== null;
                        }
                        continue;
                    }
                    $this->store->upsertTemporaryDeviceBlock(
                        $clientId,
                        $deviceUuid,
                        $blockedUntil,
                        'province_reset',
                        'admin_only'
                    );
                    $this->store->markResetDeviceBlocked((int) $device['id'], $blockedUntil, $this->sqlTime($now));
                    $control = $this->store->controlFor($clientId, $deviceUuid);
                    $hasTemporaryBlock = is_array($control)
                        && (string) ($control['blocked_until'] ?? '') === $blockedUntil;
                    $hasPermanentBlock = is_array($control)
                        && (string) ($control['state'] ?? '') === 'banned'
                        && ($control['blocked_until'] ?? null) === null;
                    if ($hasTemporaryBlock) {
                        $blockedCount++;
                    }
                    if ($clientId === $context['client_id'] && hash_equals($deviceUuid, $context['device_uuid'])) {
                        $currentDeviceBlocked = $hasTemporaryBlock || $hasPermanentBlock;
                        $currentDevicePermanent = $hasPermanentBlock;
                        $currentDeviceBlockedUntil = $hasTemporaryBlock ? $blockedUntil : null;
                    }
                }
            }
            $this->store->completeResetGeneration((int) $reset['id'], $this->sqlTime($now));
        }

        $this->store->updateProfile($context['user_id'], [
            'state' => 'bound',
            'main_province_code' => $province,
            'previous_province_code' => $previous,
            'bound_observation_id' => $observation['id'] ?? null,
            'last_verified_at' => $this->sqlTime($now),
            // A completed all-device reset establishes a fresh binding; the
            // previous self-service cooldown must not carry into that reset.
            'self_change_available_at' => null,
            'reset_started_at' => null,
            'updated_at' => $this->sqlTime($now),
        ]);
        $this->store->insertPolicyEvent([
            'decision_id' => bin2hex(random_bytes(16)),
            'user_id' => $context['user_id'],
            'client_id' => $context['client_id'] > 0 ? $context['client_id'] : null,
            'device_uuid' => $context['device_uuid'],
            'source_ip' => $observation['source_ip'] ?? null,
            'event_type' => 'province_reset',
            'decision' => 'allow',
            'reason_code' => $changed ? 'province_changed' : 'province_unchanged',
            'matched_entity_type' => null,
            'matched_observation_id' => $observation['id'] ?? null,
            'attempt_id' => (string) ($observation['attempt_id'] ?? $context['attempt_id']),
            'counts_as_geo_violation' => 0,
            'details_digest' => hash('sha256', implode('|', [$previous ?? '', $province, (string) $blockedCount])),
            'created_at' => $this->sqlTime($now),
        ]);

        return [
            'reset_generation' => (int) ($reset['generation'] ?? $profile['reset_generation'] ?? 0),
            'province_changed' => $changed,
            'reset_blocked_device_count' => $blockedCount,
            'current_device_blocked' => $currentDeviceBlocked,
            'current_device_permanent' => $currentDevicePermanent,
            'current_device_blocked_until' => $currentDeviceBlockedUntil,
        ];
    }

    /** @param array<string, mixed> $context */
    private function enforceGlobalRules(array $context, string $sourceIp): void
    {
        $matched = $this->matchBlacklistObservations($context, $sourceIp, []);
        if ($matched === null) {
            return;
        }
        $this->recordBlacklistEvent($context, $sourceIp, $matched, null);
        throw new ApiException('access_blacklisted', 403, [
            'matched_entity_type' => $matched['entity_type'],
            'matched_value' => $matched['display_value'] ?? $matched['canonical_value'],
            'counts_as_geo_violation' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $observations
     * @return array<string, mixed>|null
     */
    private function matchBlacklistObservations(array $context, string $sourceIp, array $observations): ?array
    {
        $rules = $this->store->activeBlacklistRules();
        foreach ($rules as $rule) {
            $type = match (strtolower(trim((string) ($rule['entity_type'] ?? '')))) {
                'device', 'device_code', 'uuid' => 'device_uuid',
                'ip_address', 'address' => 'ip',
                'ip_subnet', 'subnet', 'network' => 'cidr',
                'cn_province', 'province_cn', 'mainland_province' => 'province',
                'cn_city', 'city_cn', 'prefecture', 'prefecture_city' => 'city',
                'country_region', 'region', 'country_code' => 'country',
                default => strtolower(trim((string) ($rule['entity_type'] ?? ''))),
            };
            $canonical = trim((string) ($rule['canonical_value'] ?? ''));
            if ($type === 'province') {
                $provinceCandidates = [
                    (string) ($rule['province_code'] ?? ''),
                    $canonical,
                    (string) ($rule['display_value'] ?? ''),
                ];
                foreach ($provinceCandidates as $candidate) {
                    $normalized = $this->regions->normalizeProvince($candidate);
                    if ($normalized !== null) {
                        $canonical = $normalized;
                        break;
                    }
                }
            } elseif ($type === 'city') {
                $province = $this->regions->normalizeProvince((string) ($rule['province_code'] ?? ''));
                $cityValue = trim((string) ($rule['city_code'] ?? ''));
                if ($cityValue === '') {
                    $cityValue = $canonical !== '' ? $canonical : trim((string) ($rule['display_value'] ?? ''));
                }
                if ($province === null && str_contains($cityValue, ':')) {
                    [$rawProvince, $rawCity] = explode(':', $cityValue, 2);
                    $province = $this->regions->normalizeProvince($rawProvince);
                    $cityValue = $rawCity;
                }
                if ($province !== null) {
                    $canonical = $this->regions->normalizeCity($province, $cityValue) ?? $canonical;
                }
            } elseif ($type === 'country') {
                $countryCandidates = [
                    (string) ($rule['country_code'] ?? ''),
                    $canonical,
                    (string) ($rule['display_value'] ?? ''),
                ];
                foreach ($countryCandidates as $candidate) {
                    $normalized = $this->normalizeCountryCode($candidate);
                    if ($normalized !== '') {
                        $canonical = $normalized;
                        break;
                    }
                }
            } elseif (($type === 'ip' || $type === 'cidr') && $canonical === '') {
                $canonical = trim((string) ($rule['display_value'] ?? ''));
            }
            if ($type === 'device_uuid' && hash_equals(strtolower($canonical), strtolower($context['device_uuid']))) {
                return $rule;
            }
            if ($type === 'ip' || $type === 'cidr') {
                if (IpAddressMatcher::contains($canonical, $sourceIp)) {
                    return $rule;
                }
                foreach ($observations as $observation) {
                    if (IpAddressMatcher::contains($canonical, (string) ($observation['public_ip'] ?? ''))) {
                        return $rule;
                    }
                }
            }
            foreach ($observations as $observation) {
                if ($type === 'province' && (string) ($observation['province_code'] ?? '') === $canonical) {
                    return $rule;
                }
                if ($type === 'city' && (string) ($observation['city_code'] ?? '') === $canonical) {
                    return $rule;
                }
                if ($type === 'country' && $this->normalizeCountryCode((string) ($observation['country_code'] ?? '')) === $canonical) {
                    return $rule;
                }
            }
        }
        return null;
    }

    /** @param array<string, mixed> $context @param array<string, mixed>|null $observation */
    private function recordBlacklistEvent(array $context, string $sourceIp, array $rule, ?array $observation): void
    {
        $attemptId = trim((string) ($observation['attempt_id'] ?? $context['attempt_id'] ?? ''));
        $this->store->insertPolicyEvent([
            'decision_id' => bin2hex(random_bytes(16)),
            'user_id' => $context['user_id'],
            'client_id' => $context['client_id'] > 0 ? $context['client_id'] : null,
            'device_uuid' => $context['device_uuid'],
            'source_ip' => $sourceIp,
            'event_type' => 'blacklist_block',
            'decision' => 'deny',
            'reason_code' => (string) ($rule['reason_code'] ?? 'blacklist'),
            'matched_entity_type' => (string) ($rule['entity_type'] ?? ''),
            'matched_observation_id' => $observation['id'] ?? null,
            'attempt_id' => $attemptId !== '' ? $attemptId : null,
            'counts_as_geo_violation' => 0,
            'details_digest' => hash('sha256', json_encode($rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            'created_at' => $this->sqlTime($this->now()),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $observation
     */
    private function raiseGeoViolation(
        array $context,
        string $sourceIp,
        array $observation,
        string $reason,
        ?string $mainProvince = null,
        ?string $observedProvince = null,
    ): never {
        $now = $this->now();
        // Serialize all violation attempts on the account before counting.
        $this->store->userById($context['user_id'], true);
        $event = $this->store->insertPolicyEvent([
            'decision_id' => bin2hex(random_bytes(16)),
            'user_id' => $context['user_id'],
            'client_id' => $context['client_id'] > 0 ? $context['client_id'] : null,
            'device_uuid' => $context['device_uuid'],
            'source_ip' => $sourceIp,
            'event_type' => 'geo_violation',
            'decision' => 'deny',
            'reason_code' => $reason,
            'matched_entity_type' => null,
            'matched_observation_id' => $observation['id'] ?? null,
            'attempt_id' => (string) ($observation['attempt_id'] ?? $context['attempt_id']),
            'counts_as_geo_violation' => 1,
            'details_digest' => hash('sha256', json_encode([
                'reason' => $reason,
                'main_province' => $mainProvince,
                'observed_province' => $observedProvince ?? ($observation['province_code'] ?? null),
                'country_code' => $observation['country_code'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            'created_at' => $this->sqlTime($now),
        ]);
        $isNewEvent = is_array($event) && (bool) ($event['_inserted'] ?? true);

        $events = $this->store->geoViolationEvents(
            $context['user_id'],
            $this->sqlTime($now->modify('-' . self::VIOLATION_WINDOW_SECONDS . ' seconds'))
        );
        $count = count($events);
        $ban = $this->store->activeAccountBan($context['user_id'], true);
        if ($ban !== null) {
            throw new ApiException('account_banned', 403, [
                'violation_count' => $count,
                'reason_code' => (string) ($ban['reason_code'] ?? 'account_access_ban'),
            ]);
        }
        if ($count >= 3 && $ban === null && $isNewEvent) {
            $eventIds = array_values(array_map(static fn(array $item): int => (int) ($item['id'] ?? 0), $events));
            $this->store->createAccountBan([
                'user_id' => $context['user_id'],
                'origin' => 'geo_policy',
                'reason_code' => 'three_geo_violations_24h',
                'evidence_event_ids_json' => json_encode($eventIds, JSON_THROW_ON_ERROR),
                'banned_at' => $this->sqlTime($now),
                'released_at' => null,
                'released_by_user_id' => null,
                'release_note' => null,
            ]);
            $this->store->setUserStatus($context['user_id'], 'banned');
            $this->store->revokeApplicationSessions($context['user_id'], $this->sqlTime($now));
            throw new ApiException('account_banned', 403, [
                'violation_count' => $count,
                'window_seconds' => self::VIOLATION_WINDOW_SECONDS,
                'reason_code' => 'three_geo_violations_24h',
            ]);
        }

        if ($isNewEvent && $context['client_id'] > 0) {
            $this->store->upsertTemporaryDeviceBlock(
                $context['client_id'],
                $context['device_uuid'],
                $this->sqlTime($now->modify('+' . self::TEMPORARY_BLOCK_SECONDS . ' seconds')),
                'policy',
                'admin_only'
            );
        }
        throw new ApiException('geo_lock_violation', 403, [
            'violation_count' => $count,
            'window_seconds' => self::VIOLATION_WINDOW_SECONDS,
            'temporary_block_seconds' => self::TEMPORARY_BLOCK_SECONDS,
            'blocked_until' => $this->sqlTime($now->modify('+' . self::TEMPORARY_BLOCK_SECONDS . ' seconds')),
            'reason_code' => $reason,
            'main_province_code' => $mainProvince,
            'observed_province_code' => $observedProvince ?? ($observation['province_code'] ?? null),
        ]);
    }

    /** @param array<string, mixed> $client @return array{client_id:int,user_id:int,device_uuid:string,attempt_id:string,platform:string} */
    private function clientContext(array $client, string $deviceUuid, string $attemptId, string $platform): array
    {
        $clientId = (int) ($client['id'] ?? 0);
        $userId = (int) ($client['owner_user_id'] ?? $client['user_id'] ?? 0);
        $deviceUuid = trim($deviceUuid);
        $attemptId = trim($attemptId);
        $platform = strtolower(trim($platform));
        if ($deviceUuid === ''
            || strlen($deviceUuid) > 191
            || preg_match('/[\x00-\x20\x7f]/', $deviceUuid) === 1) {
            throw new ApiException('device_uuid_invalid', 422);
        }
        if ($attemptId === ''
            || strlen($attemptId) > 128
            || preg_match('/[\x00-\x20\x7f]/', $attemptId) === 1) {
            throw new ApiException('location_attempt_invalid', 422);
        }
        if ($platform === '') {
            $platform = 'unknown';
        }
        $platform = match (true) {
            $platform === 'win' || str_starts_with($platform, 'windows') => 'windows',
            $platform === 'mac' || $platform === 'osx' || str_starts_with($platform, 'macos') || str_starts_with($platform, 'darwin') => 'macos',
            str_starts_with($platform, 'android') => 'android',
            str_starts_with($platform, 'ios') => 'ios',
            str_starts_with($platform, 'linux') => 'linux',
            default => $platform,
        };
        if (strlen($platform) > 32 || preg_match('/^[a-z0-9._-]+$/', $platform) !== 1
            || !in_array($platform, ['android', 'windows', 'macos', 'ios', 'linux', 'unknown'], true)) {
            throw new ApiException('location_platform_invalid', 422);
        }
        if ($userId <= 0) {
            // Shared/legacy clients can still be protected by global device/IP
            // rules, but cannot participate in account geo-lock evaluation.
            $userId = 0;
        }
        return [
            'client_id' => $clientId,
            'user_id' => $userId,
            'device_uuid' => $deviceUuid,
            'attempt_id' => $attemptId,
            'platform' => $platform,
        ];
    }

    /** @param array<string, mixed> $client */
    private function deviceUuidFromRequest(Request $request): string
    {
        $value = '';
        foreach ([
            $request->header('x-device-uuid', ''),
            $request->header('x-device-id', ''),
            $request->input('device_uuid', ''),
            $request->input('device_id', ''),
        ] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            if ($value !== '' && !hash_equals($value, $candidate)) {
                throw new ApiException('device_uuid_invalid', 422);
            }
            $value = $candidate;
        }
        if ($value === '') {
            throw new ApiException('device_uuid_required', 422);
        }
        return $value;
    }

    private function requiredAttemptId(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new ApiException('location_attempt_required', 422);
        }
        return $value;
    }

    private function optionalAttemptId(Request $request): ?string
    {
        $value = trim((string) ($request->header('x-attempt-id') ?? $request->input('attempt_id', '')));
        return $value === '' ? null : $value;
    }

    private function generatedAttemptId(): string
    {
        return 'poll-' . bin2hex(random_bytes(12));
    }

    private function resolvedSourceIp(Request $request): ?string
    {
        $resolved = $this->clientIpResolver->resolve($request);
        if ($resolved === null) {
            return null;
        }
        $canonical = IpAddressMatcher::canonicalize($resolved);
        return $canonical['canonical'] ?? null;
    }

    /** @param array<string, mixed>|null $challenge @param array<string, mixed> $context */
    private function assertChallenge(?array $challenge, array $context): void
    {
        if ($challenge === null
            || ($challenge['consumed_at'] ?? null) !== null
            || (int) ($challenge['user_id'] ?? 0) !== $context['user_id']
            || (int) ($challenge['client_id'] ?? 0) !== ($context['client_id'] > 0 ? $context['client_id'] : 0)
            || (string) ($challenge['device_uuid'] ?? '') !== $context['device_uuid']
            || (string) ($challenge['attempt_id'] ?? '') !== $context['attempt_id']
            || ($context['platform'] !== 'unknown' && (string) ($challenge['platform'] ?? '') !== $context['platform'])
            || !hash_equals((string) ($challenge['auth_context_hash'] ?? ''), $this->authContextHash($context))
            || !$this->isFuture($challenge['expires_at'] ?? null, $this->now())) {
            throw new ApiException('location_challenge_invalid', 403);
        }
    }

    private function assertAccountUsable(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $user = $this->store->userById($userId);
        if ($user === null) {
            throw new ApiException('user_not_found', 404);
        }
        if ((string) ($user['status'] ?? 'active') === 'banned' || $this->store->activeAccountBan($userId) !== null) {
            throw new ApiException('account_banned', 403, ['reason_code' => 'account_access_ban']);
        }
    }

    private function assertDeviceUsable(int $clientId, string $deviceUuid): void
    {
        if ($clientId <= 0) {
            return;
        }
        $control = $this->store->controlFor($clientId, $deviceUuid);
        if ($control === null) {
            return;
        }
        $state = (string) ($control['state'] ?? 'active');
        if ($state === 'banned') {
            throw new ApiException('device_banned', 403, ['reason' => $control['reason_code'] ?? null]);
        }
        $rawBlockedUntil = $control['blocked_until'] ?? null;
        $blockedUntil = $this->parseTime($rawBlockedUntil);
        if ($rawBlockedUntil !== null && $blockedUntil === null) {
            // An invalid persisted timer must fail closed instead of being
            // interpreted as an active device. Only an administrator can
            // repair the control row.
            throw new ApiException('device_temporarily_banned', 423, [
                'retry_after_seconds' => self::TEMPORARY_BLOCK_SECONDS,
                'block_origin' => $control['block_origin'] ?? 'policy',
                'release_policy' => $control['release_policy'] ?? 'admin_only',
            ]);
        }
        if ($blockedUntil !== null) {
            $now = $this->now();
            if ($blockedUntil > $now) {
                throw new ApiException('device_temporarily_banned', 423, [
                    'blocked_until' => $this->sqlTime($blockedUntil),
                    'retry_after_seconds' => max(1, $blockedUntil->getTimestamp() - $now->getTimestamp()),
                    'block_origin' => $control['block_origin'] ?? 'policy',
                    'release_policy' => $control['release_policy'] ?? 'admin_only',
                ]);
            }
            $this->store->clearExpiredTemporaryDeviceBlock(
                $clientId,
                $deviceUuid,
                $this->sqlTime($now)
            );
            // Re-read after the compare-and-swap expiry cleanup. A concurrent
            // policy writer may have installed a new timer after the first
            // snapshot; fail closed instead of returning an active decision.
            $latest = $this->store->controlFor($clientId, $deviceUuid);
            if ($latest !== null) {
                $latestState = (string) ($latest['state'] ?? 'active');
                if ($latestState === 'banned') {
                    throw new ApiException('device_banned', 403, ['reason' => $latest['reason_code'] ?? null]);
                }
                $latestRawBlockedUntil = $latest['blocked_until'] ?? null;
                $latestBlockedUntil = $this->parseTime($latestRawBlockedUntil);
                if ($latestRawBlockedUntil !== null && $latestBlockedUntil === null) {
                    throw new ApiException('device_temporarily_banned', 423, [
                        'retry_after_seconds' => self::TEMPORARY_BLOCK_SECONDS,
                        'block_origin' => $latest['block_origin'] ?? 'policy',
                        'release_policy' => $latest['release_policy'] ?? 'admin_only',
                    ]);
                }
                if ($latestBlockedUntil !== null && $latestBlockedUntil > $this->now()) {
                    throw new ApiException('device_temporarily_banned', 423, [
                        'blocked_until' => $this->sqlTime($latestBlockedUntil),
                        'retry_after_seconds' => max(1, $latestBlockedUntil->getTimestamp() - $this->now()->getTimestamp()),
                        'block_origin' => $latest['block_origin'] ?? 'policy',
                        'release_policy' => $latest['release_policy'] ?? 'admin_only',
                    ]);
                }
                if ($latestBlockedUntil === null && $latestState === 'kicked') {
                    throw new ApiException('device_kicked', 409, ['reason' => $latest['reason_code'] ?? null]);
                }
            }
            return;
        }
        if ($state === 'kicked') {
            throw new ApiException('device_kicked', 409, ['reason' => $control['reason_code'] ?? null]);
        }
    }

    /** @param array<int, array<string, mixed>> $observations @return array{latest:array<string,mixed>,effective:array<string,mixed>,physical:array<string,mixed>|null}|null */
    private function selectCachedObservations(array $observations): ?array
    {
        if ($observations === []) {
            return null;
        }

        // Effective and physical readings are a single decision only when
        // they belong to the same client attempt. Never combine a fresh
        // foreign reading with an older physical reading from another check.
        $groups = [];
        foreach ($observations as $observation) {
            $attemptId = trim((string) ($observation['attempt_id'] ?? ''));
            $challengeId = (int) ($observation['challenge_id'] ?? 0);
            $groupKey = $attemptId !== ''
                ? 'attempt:' . $attemptId
                : ($challengeId > 0 ? 'challenge:' . $challengeId : 'observation:' . (int) ($observation['id'] ?? 0));
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'latest' => $observation,
                    'effective' => null,
                    'physical' => null,
                ];
            }
            $group =& $groups[$groupKey];
            if ($this->observationIsNewer($observation, $group['latest'])) {
                $group['latest'] = $observation;
            }
            $mode = (string) ($observation['collection_mode'] ?? '');
            if ($mode === 'effective' && ($group['effective'] === null || $this->observationIsNewer($observation, $group['effective']))) {
                $group['effective'] = $observation;
            }
            if ($mode === 'physical_no_proxy' && ($group['physical'] === null || $this->observationIsNewer($observation, $group['physical']))) {
                $group['physical'] = $observation;
            }
            unset($group);
        }

        $groups = array_values(array_filter($groups, static fn(array $group): bool => is_array($group['effective'] ?? null)));
        usort($groups, fn(array $left, array $right): int => $this->compareObservationRecency($right['latest'], $left['latest']));
        $selected = $groups[0] ?? null;
        return $selected === null ? null : [
            'latest' => $selected['latest'],
            'effective' => $selected['effective'],
            'physical' => $selected['physical'],
        ];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function observationIsNewer(array $left, array $right): bool
    {
        return $this->compareObservationRecency($left, $right) > 0;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareObservationRecency(array $left, array $right): int
    {
        $timeCompare = strcmp((string) ($left['received_at'] ?? ''), (string) ($right['received_at'] ?? ''));
        return $timeCompare !== 0
            ? $timeCompare
            : ((int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0));
    }

    /** @param array<string, mixed> $latest @param array<string, mixed> $context */
    private function cacheIsDue(array $latest, array $context, string $sourceIp): bool
    {
        return $this->nextLocationCheckAt($latest, $context, $sourceIp) <= $this->now();
    }

    /** @param array<string, mixed> $latest @param array<string, mixed> $context */
    private function nextLocationCheckAt(array $latest, array $context, string $sourceIp): DateTimeImmutable
    {
        $received = $this->parseTime($latest['received_at'] ?? null);
        if ($received === null) {
            return $this->now();
        }
        $seed = $context['user_id'] . '|' . $context['device_uuid'] . '|' . $sourceIp;
        $jitter = hexdec(substr(hash('sha256', $seed), 0, 8)) % (self::POLL_JITTER_SECONDS + 1);
        $pollAt = $received->modify('+' . (self::POLL_INTERVAL_SECONDS + $jitter) . ' seconds');
        $cacheExpiry = $received->modify('+' . self::OBSERVATION_CACHE_SECONDS . ' seconds');
        return $pollAt < $cacheExpiry ? $pollAt : $cacheExpiry;
    }

    /** @param array<string, mixed> $observation */
    private function normalizeObservation(array $observation, string $sourceIp, array $context): array
    {
        $providerData = $observation['data'] ?? ($observation['response']['data'] ?? null);
        if (is_array($providerData)) {
            $observation = array_merge($observation, $providerData);
        }
        $providerCode = $observation['code'] ?? ($observation['response']['code'] ?? 0);
        $providerCodeIsSuccess = is_int($providerCode) && $providerCode === 0
            || is_float($providerCode) && $providerCode === 0.0
            || is_string($providerCode) && preg_match('/^0+(?:\.0+)?$/', trim($providerCode)) === 1;
        if (!$providerCodeIsSuccess) {
            throw new ApiException('location_check_unavailable', 503, [
                'provider' => (string) ($observation['provider'] ?? 'bilibili_zone'),
                'retry_after_seconds' => self::POLL_INTERVAL_SECONDS,
            ]);
        }
        if (array_key_exists('error', $observation) && trim((string) $observation['error']) !== '') {
            throw new ApiException('location_check_unavailable', 503, [
                'provider' => (string) ($observation['provider'] ?? 'bilibili_zone'),
                'retry_after_seconds' => self::POLL_INTERVAL_SECONDS,
            ]);
        }
        $mode = strtolower(trim((string) ($observation['collection_mode'] ?? $observation['mode'] ?? '')));
        $mode = match ($mode) {
            'effective_path', 'effective-path', 'effective' => 'effective',
            'physical_no_proxy', 'physical-no-proxy', 'direct', 'direct_physical' => 'physical_no_proxy',
            default => $mode,
        };
        if (!in_array($mode, ['effective', 'physical_no_proxy'], true)) {
            throw new ApiException('location_collection_mode_invalid', 422);
        }
        $providerStatus = strtolower(trim((string) ($observation['status'] ?? $observation['provider_status'] ?? 'ok')));
        if ($providerStatus !== '' && !in_array($providerStatus, ['ok', 'success', '0'], true)) {
            throw new ApiException('location_check_unavailable', 503, [
                'provider' => (string) ($observation['provider'] ?? 'bilibili_zone'),
                'retry_after_seconds' => self::POLL_INTERVAL_SECONDS,
            ]);
        }
        $provider = strtolower(trim((string) ($observation['provider'] ?? 'bilibili_zone')));
        if ($provider !== 'bilibili_zone') {
            throw new ApiException('location_provider_invalid', 422);
        }
        $ip = IpAddressMatcher::canonicalize((string) ($observation['public_ip'] ?? $observation['addr'] ?? ''));
        if ($ip === null) {
            throw new ApiException('location_public_ip_invalid', 422);
        }
        $rawCountryCode = trim((string) ($observation['country_code'] ?? ''));
        $rawCountry = trim((string) ($observation['country'] ?? ''));
        // Prefer the provider's stable country code whenever it is present.
        // A display name is only a fallback; otherwise a caller could pair a
        // foreign code with a localized "China" label and bypass the direct
        // physical-network check.
        $countryCode = $this->normalizeObservedCountryCode($rawCountryCode, $rawCountry);
        if ($countryCode === '') {
            throw new ApiException('location_country_invalid', 422);
        }
        $provinceCode = null;
        $cityCode = null;
        if ($this->isMainlandCountry($countryCode)) {
            $provinceCode = $this->regions->normalizeProvince((string) ($observation['province'] ?? $observation['province_code'] ?? ''));
            if ($provinceCode === null) {
                throw new ApiException('location_mainland_province_invalid', 422);
            }
            $cityCode = $this->regions->normalizeCity(
                $provinceCode,
                (string) ($observation['city'] ?? $observation['city_code'] ?? '')
            );
        }
        $observedAt = $this->parseTime($observation['observed_at'] ?? null);
        if ($observedAt === null) {
            throw new ApiException('location_observed_at_invalid', 422);
        }
        $now = $this->now();
        if ($observedAt > $now->modify('+300 seconds') || $observedAt < $now->modify('-' . self::OBSERVATION_MAX_AGE_SECONDS . ' seconds')) {
            throw new ApiException('location_observation_stale', 422);
        }
        $payload = [
            'collection_mode' => $mode,
            'provider' => $provider,
            'public_ip' => $ip['canonical'],
            'country_code' => $countryCode,
            'province_code' => $provinceCode,
            'city_code' => $cityCode,
            'observed_at' => $this->sqlTime($observedAt),
            'received_at' => $this->sqlTime($now),
            'source_ip' => $sourceIp,
        ];
        $payload['payload_digest'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        return $payload;
    }

    /** @param array<int, array<string, mixed>> $rows @param array<string, mixed> $needle @return array<string, mixed> */
    private function rowForNormalized(array $rows, array $needle): array
    {
        foreach ($rows as $row) {
            if ((string) ($row['collection_mode'] ?? '') === (string) ($needle['collection_mode'] ?? '')
                && (string) ($row['public_ip'] ?? '') === (string) ($needle['public_ip'] ?? '')) {
                return $row;
            }
        }
        return $needle;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function normalizeBlacklistRule(array $payload): array
    {
        $type = strtolower(trim((string) ($payload['entity_type'] ?? $payload['type'] ?? '')));
        $type = match ($type) {
            'device', 'device_code', 'uuid' => 'device_uuid',
            'ip_address', 'address' => 'ip',
            'ip_subnet', 'subnet', 'network' => 'cidr',
            'mainland_province', 'prefecture_province', 'cn_province', 'province_cn' => 'province',
            'prefecture', 'prefecture_city', 'cn_city', 'city_cn' => 'city',
            'region', 'country_region', 'country_code' => 'country',
            default => $type,
        };
        $allowed = ['device_uuid', 'ip', 'cidr', 'province', 'city', 'country'];
        if (!in_array($type, $allowed, true)) {
            throw new ApiException('blacklist_entity_type_invalid', 422);
        }
        $value = '';
        foreach (['value', 'canonical_value', 'country_code', 'country'] as $field) {
            $candidate = trim((string) ($payload[$field] ?? ''));
            if ($candidate !== '') {
                $value = $candidate;
                break;
            }
        }
        if ($type === 'city' && $value === '') {
            $value = trim((string) ($payload['city'] ?? $payload['city_code'] ?? ''));
        }
        if ($value === '') {
            throw new ApiException('blacklist_entity_value_required', 422);
        }
        $row = [
            'entity_type' => $type,
            'canonical_value' => '',
            'display_value' => $value,
            'ip_family' => null,
            'ip_start' => null,
            'ip_end' => null,
            'province_code' => null,
            'city_code' => null,
            'country_code' => null,
            'is_active' => $this->strictBoolean($payload['is_active'] ?? 1) ? 1 : 0,
            'reason_code' => $this->boundedText((string) ($payload['reason_code'] ?? 'admin_blacklist'), 64),
            'admin_note' => $this->boundedNullableText($payload['admin_note'] ?? null, 500),
        ];

        if ($type === 'device_uuid') {
            $canonical = strtolower($value);
            if (strlen($canonical) > 191 || preg_match('/^[a-z0-9][a-z0-9._:-]*$/', $canonical) !== 1) {
                throw new ApiException('blacklist_device_uuid_invalid', 422);
            }
            $row['canonical_value'] = $canonical;
            return $row;
        }
        if ($type === 'ip') {
            $ip = IpAddressMatcher::canonicalize($value);
            if ($ip === null) {
                throw new ApiException('blacklist_ip_invalid', 422);
            }
            $row['canonical_value'] = $ip['canonical'];
            $row['ip_family'] = $ip['family'];
            $row['ip_start'] = $ip['packed'];
            $row['ip_end'] = $ip['packed'];
            return $row;
        }
        if ($type === 'cidr') {
            $cidr = IpAddressMatcher::canonicalCidr($value);
            if ($cidr === null) {
                throw new ApiException('blacklist_cidr_invalid', 422);
            }
            $row['canonical_value'] = $cidr['canonical'];
            $row['ip_family'] = $cidr['family'];
            $row['ip_start'] = $cidr['start'];
            $row['ip_end'] = $cidr['end'];
            return $row;
        }
        if ($type === 'province') {
            $province = $this->regions->normalizeProvince($value);
            if ($province === null) {
                throw new ApiException('blacklist_province_invalid', 422);
            }
            $row['canonical_value'] = $province;
            $row['province_code'] = $province;
            return $row;
        }
        if ($type === 'city') {
            $province = trim((string) ($payload['province'] ?? $payload['province_code'] ?? ''));
            $cityValue = $value;
            if ($province === '' && str_contains($cityValue, ':')) {
                [$province, $cityValue] = explode(':', $cityValue, 2);
            }
            $provinceCode = $this->regions->normalizeProvince($province);
            $city = $provinceCode === null
                ? null
                : $this->regions->normalizeCity($provinceCode, $cityValue);
            if ($city === null) {
                throw new ApiException('blacklist_city_invalid', 422);
            }
            $row['canonical_value'] = $city;
            $row['province_code'] = $provinceCode;
            $row['city_code'] = $city;
            return $row;
        }

        $country = $this->normalizeCountryCode($value);
        if ($country === '' || $country === 'CN') {
            throw new ApiException('blacklist_country_invalid', 422);
        }
        $row['canonical_value'] = $country;
        $row['country_code'] = $country;
        return $row;
    }

    private function normalizeCountryCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $upper = strtoupper($value);
        if (in_array($upper, ['CN', 'CHN', '86', '中国', '中国大陆', '中国内地', 'MAINLAND CHINA', 'CHINA'], true)) {
            return 'CN';
        }
        // The admin form is intentionally friendly to common provider/UI
        // country names while retaining ISO-like codes as the canonical
        // persisted value. Unknown labels still receive a deterministic key.
        $commonAliases = [
            'UNITED STATES' => 'US', 'UNITED STATES OF AMERICA' => 'US', '美国' => 'US',
            'JAPAN' => 'JP', '日本' => 'JP', 'SOUTH KOREA' => 'KR', '韩国' => 'KR',
            'SINGAPORE' => 'SG', '新加坡' => 'SG', 'HONG KONG' => 'HK', '香港' => 'HK',
            'MACAO' => 'MO', 'MACAU' => 'MO', '澳门' => 'MO', 'TAIWAN' => 'TW', '台湾' => 'TW',
            'UNITED KINGDOM' => 'GB', '英国' => 'GB', 'CANADA' => 'CA', '加拿大' => 'CA',
            'AUSTRALIA' => 'AU', '澳大利亚' => 'AU', 'GERMANY' => 'DE', '德国' => 'DE',
            'FRANCE' => 'FR', '法国' => 'FR', 'RUSSIA' => 'RU', '俄罗斯' => 'RU',
            'MALAYSIA' => 'MY', '马来西亚' => 'MY', 'THAILAND' => 'TH', '泰国' => 'TH',
            'VIETNAM' => 'VN', '越南' => 'VN', 'INDIA' => 'IN', '印度' => 'IN',
        ];
        if (isset($commonAliases[$upper])) {
            return $commonAliases[$upper];
        }
        if (preg_match('/^[A-Z]{2,3}$/', $upper) === 1) {
            return $upper;
        }
        if (preg_match('/^[0-9]{1,3}$/', $upper) === 1) {
            $numeric = (int) $upper;
            return $numeric === 86 ? 'CN' : (string) $numeric;
        }
        // Keep non-Latin provider labels deterministic without treating them
        // as Mainland. Admins can use the stable generated key in rules.
        return 'NAME:' . strtoupper(substr(hash('sha256', strtolower($value)), 0, 16));
    }

    private function normalizeObservedCountryCode(string $countryCode, string $country): string
    {
        if ($countryCode === '') {
            return $this->normalizeCountryCode($country);
        }

        $stable = $this->normalizeCountryCode($countryCode);
        if (preg_match('/^[0-9]{1,3}$/', $countryCode) !== 1) {
            return $stable;
        }

        $callingCode = (string) ((int) $countryCode);
        $callingCodeAliases = [
            '1' => ['US', 'CA'],
            '7' => ['RU', 'KZ'],
            '33' => ['FR'],
            '44' => ['GB'],
            '49' => ['DE'],
            '60' => ['MY'],
            '61' => ['AU'],
            '65' => ['SG'],
            '66' => ['TH'],
            '81' => ['JP'],
            '82' => ['KR'],
            '84' => ['VN'],
            '86' => ['CN'],
            '852' => ['HK'],
            '853' => ['MO'],
            '886' => ['TW'],
            '91' => ['IN'],
        ];
        $displayCode = $this->normalizeCountryCode($country);
        $candidates = $callingCodeAliases[$callingCode] ?? [];
        if ($displayCode !== '' && in_array($displayCode, $candidates, true)) {
            return $displayCode;
        }
        return count($candidates) === 1 ? $candidates[0] : $stable;
    }

    private function isMainlandCountry(mixed $countryCode): bool
    {
        return $this->normalizeCountryCode((string) $countryCode) === 'CN';
    }

    /** @param array<string, mixed> $context */
    private function authContextHash(array $context): string
    {
        return hash('sha256', implode('|', [
            $context['user_id'],
            $context['client_id'],
            $context['device_uuid'],
            $context['attempt_id'],
            $context['platform'],
        ]));
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock;
    }

    private function sqlTime(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function isoTime(mixed $value): ?string
    {
        $time = $this->parseTime($value);
        return $time?->format('Y-m-d\\TH:i:s\\Z');
    }

    private function parseTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function isFuture(mixed $value, DateTimeImmutable $now): bool
    {
        $time = $this->parseTime($value);
        return $time !== null && $time > $now;
    }

    private function boundedText(string $value, int $length): string
    {
        $value = trim($value);
        return substr($value === '' ? 'policy' : $value, 0, $length);
    }

    private function boundedNullableText(mixed $value, int $length): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : substr($value, 0, $length);
    }

    private function strictBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function observationByMode(array $observations, string $mode): ?array
    {
        foreach ($observations as $observation) {
            if ((string) ($observation['collection_mode'] ?? '') === $mode) {
                return $observation;
            }
        }
        return null;
    }

    /** @param array<int, array<string, mixed>> $observations */
    private function observationsContainAttempt(array $observations, string $attemptId): bool
    {
        if ($attemptId === '' || str_starts_with($attemptId, 'poll-')) {
            return false;
        }
        foreach ($observations as $observation) {
            if ((string) ($observation['attempt_id'] ?? '') === $attemptId) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $devices
     * @return array<int, array{client_id:int,device_uuid:string}>
     */
    private function deduplicateDevices(array $devices): array
    {
        $result = [];
        $seen = [];
        foreach ($devices as $device) {
            $clientId = (int) ($device['client_id'] ?? 0);
            $deviceUuid = trim((string) ($device['device_uuid'] ?? $device['device_id'] ?? ''));
            if ($clientId <= 0 || $deviceUuid === '' || strlen($deviceUuid) > 191) {
                continue;
            }
            $key = $clientId . '|' . $deviceUuid;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = ['client_id' => $clientId, 'device_uuid' => $deviceUuid];
        }
        return $result;
    }
}
