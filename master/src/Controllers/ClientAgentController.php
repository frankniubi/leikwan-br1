<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\Auth;
use Leikwan\Master\Support\AppSettings;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\AndroidEnrollmentCodeService;
use Leikwan\Master\Support\AccessPolicyService;
use Leikwan\Master\Support\OpenWrtPairingCodeService;
use Leikwan\Master\Support\BondLegReputationService;
use Leikwan\Master\Support\ClientOnlineSessionService;
use Leikwan\Master\Support\DeviceControlService;
use Leikwan\Master\Support\DebugCollectionStore;
use Leikwan\Master\Support\DesktopClientSessionService;
use Leikwan\Master\Support\LeigodTokenBroker;
use Leikwan\Master\Support\LeigodTokenProvider;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\UltraClientStatusService;
use Leikwan\Master\Support\UltraInventoryService;
use Leikwan\Master\Support\UltraLeaseService;
use Leikwan\Master\Support\UltraMode;
use Leikwan\Master\Support\UltraMaterialService;
use Leikwan\Master\Support\TrustedClientIpResolver;

final class ClientAgentController extends BaseController
{
    private LeigodTokenProvider $ultraTokenProvider;
    private UltraMaterialService $ultraMaterials;
    private UltraLeaseService $ultraLeases;
    private UltraClientStatusService $ultraClientStatus;
    private DeviceControlService $deviceControls;
    private AndroidEnrollmentCodeService $enrollmentCodes;
    private OpenWrtPairingCodeService $openWrtPairingCodes;
    private AccessPolicyService $accessPolicy;

    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ProvisioningService $provisioning,
        ?LeigodTokenProvider $leigodTokenProvider = null,
        ?UltraMaterialService $ultraMaterials = null,
        ?UltraClientStatusService $ultraClientStatus = null,
        ?UltraLeaseService $ultraLeases = null,
        ?DeviceControlService $deviceControls = null,
        ?AndroidEnrollmentCodeService $enrollmentCodes = null,
        ?AccessPolicyService $accessPolicy = null,
        ?OpenWrtPairingCodeService $openWrtPairingCodes = null,
    ) {
        parent::__construct($config, $db);
        $this->ultraMaterials = $ultraMaterials
            ?? new UltraMaterialService($config, $db, new AppSettings($db));
        $this->ultraTokenProvider = $leigodTokenProvider
            ?? new LeigodTokenBroker($config, new AppSettings($db), $this->ultraMaterials);
        $this->ultraLeases = $ultraLeases ?? new UltraLeaseService($config, $db, $this->ultraMaterials);
        $this->ultraClientStatus = $ultraClientStatus ?? new UltraClientStatusService($config, $db);
        $this->deviceControls = $deviceControls ?? DeviceControlService::fromConfig($config, $db);
        $this->enrollmentCodes = $enrollmentCodes ?? new AndroidEnrollmentCodeService($db);
        $this->openWrtPairingCodes = $openWrtPairingCodes ?? new OpenWrtPairingCodeService($db, $config);
        $this->accessPolicy = $accessPolicy ?? new AccessPolicyService(
            $db,
            clientIpResolver: new TrustedClientIpResolver((string) ($config->get('TRUSTED_PROXY_CIDRS', '') ?? '')),
        );
    }

    public function locationChallenge(Request $request, array $params): Response
    {
        $client = $this->accessPolicyClient($request);
        $platform = $this->accessPolicyPlatform($request);
        $challenge = $this->accessPolicy->issueLocationChallenge(
            $client,
            $this->accessPolicyDeviceUuid($request),
            $this->accessPolicyAttemptId($request),
            $platform,
            $request,
        );
        return $this->noStoreJson([
            'ok' => true,
            'protocol_version' => 1,
            'challenge_token' => (string) ($challenge['token'] ?? ''),
            'challenge_expires_at' => (string) ($challenge['expires_at'] ?? ''),
            'attempt_id' => (string) ($challenge['attempt_id'] ?? ''),
            'platform' => (string) ($challenge['platform'] ?? $platform),
            'poll_interval_seconds' => (int) ($challenge['poll_interval_seconds'] ?? 1800),
            'poll_jitter_seconds' => (int) ($challenge['poll_jitter_seconds'] ?? 300),
        ]);
    }

    public function locationReport(Request $request, array $params): Response
    {
        $client = $this->accessPolicyClient($request);
        $this->accessPolicyPlatform($request);
        try {
            $decision = $this->accessPolicy->recordLocationReport($client, $request);
        } catch (ApiException $exception) {
            $stable = [
                'direct_location_check_required', 'location_check_unavailable',
                'access_blacklisted', 'geo_lock_violation', 'device_temporarily_banned',
                'device_banned', 'account_banned',
            ];
            if (!in_array($exception->getMessage(), $stable, true)
                && in_array($exception->statusCode(), [400, 413, 422], true)) {
                throw new ApiException('location_report_invalid', 422);
            }
            throw $exception;
        }
        return $this->noStoreJson(['ok' => true, 'protocol_version' => 1] + $this->safeAccessDecision($decision));
    }

    public function accessPolicyStatus(Request $request, array $params): Response
    {
        $client = $this->accessPolicyClient($request);
        $this->accessPolicyPlatform($request);
        $status = $this->accessPolicy->policyStatus($client, $request, $this->accessPolicyDeviceUuid($request));
        return $this->noStoreJson(['ok' => true] + $status);
    }

    public function deviceChallenge(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $this->requireAndroidRequest($request);
        $this->assertRequestedClientId($client, $request);
        $deviceId = $this->requestedDeviceId($request);
        $this->accessPolicy->authorizeClientRequest($client, $request, 'enrollment', $deviceId);
        $challenge = $this->deviceControls->issueChallenge($client, $deviceId, 1);
        return $this->noStoreJson(['ok' => true] + $challenge);
    }

    public function deviceRenewalChallenge(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $this->requireAndroidRequest($request);
        $this->assertRequestedClientId($client, $request);
        $deviceId = $this->requestedDeviceId($request);
        $this->accessPolicy->authorizeClientRequest($client, $request, 'enrollment', $deviceId);
        $challenge = $this->deviceControls->issueRenewalChallenge($client, $deviceId, 1);
        return $this->noStoreJson(['ok' => true] + $challenge);
    }

    public function renewDevice(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $this->requireAndroidRequest($request);
        $this->assertRequestedClientId($client, $request);
        $deviceId = $this->requestedDeviceId($request);
        $this->accessPolicy->authorizeClientRequest($client, $request, 'enrollment', $deviceId);
        $enrollment = $this->deviceControls->renew(
            $client,
            $deviceId,
            (string) $request->input('nonce', ''),
            (string) $request->input('identity_public_key', ''),
            (string) $request->input('binding_signature', ''),
            1,
        );
        $this->clientOnlineSessions()->enforceFromRequest($client, $request, 'pull');
        return $this->noStoreJson([
            'ok' => true,
            'client_id' => (int) $client['id'],
        ] + $enrollment);
    }

    public function enrollDevice(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $this->requireAndroidRequest($request);
        $this->assertRequestedClientId($client, $request);
        $proof = $request->input('attestation_proof', []);
        if (!is_array($proof)) {
            throw new ApiException('attestation_proof_invalid', 422);
        }
        $deviceId = $this->requestedDeviceId($request);
        $this->accessPolicy->authorizeClientRequest($client, $request, 'enrollment', $deviceId);
        $proof['access_policy_protocol_version'] = 1;
        $enrollment = $this->deviceControls->enroll(
            $client,
            $deviceId,
            (string) $request->input('nonce', ''),
            $proof,
        );
        $this->clientOnlineSessions()->enforceFromRequest($client, $request, 'pull');
        return $this->noStoreJson([
            'ok' => true,
            'client_id' => (int) $client['id'],
        ] + $enrollment);
    }

    public function deviceIdentityEnroll(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $this->requireAndroidRequest($request);
        $this->assertRequestedClientId($client, $request);
        $deviceId = $this->requestedDeviceId($request);
        $this->accessPolicy->authorizeClientRequest($client, $request, 'enrollment', $deviceId);
        $enrollment = $this->deviceControls->enrollIdentity(
            $client,
            $deviceId,
            (string) $request->input('nonce', ''),
            (string) $request->input('identity_public_key', ''),
            1,
        );
        $this->clientOnlineSessions()->enforceFromRequest($client, $request, 'pull');
        return $this->noStoreJson([
            'ok' => true,
            'client_id' => (int) $client['id'],
        ] + $enrollment);
    }

    public function acknowledgeDevice(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $this->requireAndroidRequest($request);
        $deviceId = $this->requestedDeviceId($request);
        $this->accessPolicy->authorizeClientRequest($client, $request, 'acknowledge', $deviceId);
        $this->deviceControls->acknowledge(
            $client,
            $deviceId,
            max(0, (int) $request->input('command_version', 0)),
        );
        return $this->noStoreJson(['ok' => true]);
    }

    public function enrollmentCodeChallenge(Request $request, array $params): Response
    {
        $this->requireAndroidRequest($request);
        $client = $this->activeClientForEnrollmentCode((string) $request->input('enrollment_code', ''));
        $deviceId = $this->requestedDeviceId($request);
        $this->accessPolicy->authorizeClientRequest($client, $request, 'enrollment', $deviceId);
        $challenge = $this->deviceControls->issueChallenge($client, $deviceId, 1);
        return $this->noStoreJson([
            'ok' => true,
            'client_id' => (int) $client['id'],
        ] + $challenge);
    }

    public function enrollmentCodeEnroll(Request $request, array $params): Response
    {
        $this->requireAndroidRequest($request);
        $code = (string) $request->input('enrollment_code', '');
        $proof = $request->input('attestation_proof', []);
        if (!is_array($proof)) {
            throw new ApiException('attestation_proof_invalid', 422);
        }
        $policyClient = $this->activeClientForEnrollmentCode($code);
        $deviceId = $this->requestedDeviceId($request);
        $this->accessPolicy->authorizeClientRequest($policyClient, $request, 'enrollment', $deviceId);
        $proof['access_policy_protocol_version'] = 1;
        [$client, $enrollment] = $this->db->transaction(function () use ($code, $request, $proof): array {
            $codeRow = $this->enrollmentCodes->findActive($code);
            if ($codeRow === null) {
                throw new ApiException('android_enrollment_code_invalid', 403);
            }
            $client = $this->activeClientForEnrollmentCodeRow($codeRow);
            $enrollment = $this->deviceControls->enroll(
                $client,
                $this->requestedDeviceId($request),
                (string) $request->input('nonce', ''),
                $proof,
            );
            if ($this->enrollmentCodes->consume($code) === null) {
                throw new ApiException('android_enrollment_code_invalid', 403);
            }
            return [$client, $enrollment];
        });
        $this->clientOnlineSessions()->enforceFromRequest($client, $request, 'pull');
        return $this->noStoreJson([
            'ok' => true,
            'client_id' => (int) $client['id'],
            'agent_token' => (string) $client['agent_token'],
        ] + $enrollment);
    }

    public function enrollmentCodeIdentityEnroll(Request $request, array $params): Response
    {
        $this->requireAndroidRequest($request);
        $code = (string) $request->input('enrollment_code', '');
        $identityPublicKey = (string) $request->input('identity_public_key', '');
        $policyClient = $this->activeClientForEnrollmentCode($code);
        $deviceId = $this->requestedDeviceId($request);
        $this->accessPolicy->authorizeClientRequest($policyClient, $request, 'enrollment', $deviceId);
        [$client, $enrollment] = $this->db->transaction(function () use ($code, $request, $identityPublicKey): array {
            $codeRow = $this->enrollmentCodes->findActive($code);
            if ($codeRow === null) {
                throw new ApiException('android_enrollment_code_invalid', 403);
            }
            $client = $this->activeClientForEnrollmentCodeRow($codeRow);
            $enrollment = $this->deviceControls->enrollIdentity(
                $client,
                $this->requestedDeviceId($request),
                (string) $request->input('nonce', ''),
                $identityPublicKey,
                1,
            );
            if ($this->enrollmentCodes->consume($code) === null) {
                throw new ApiException('android_enrollment_code_invalid', 403);
            }
            return [$client, $enrollment];
        });
        $this->clientOnlineSessions()->enforceFromRequest($client, $request, 'pull');
        return $this->noStoreJson([
            'ok' => true,
            'client_id' => (int) $client['id'],
            'agent_token' => (string) $client['agent_token'],
        ] + $enrollment);
    }

    /**
     * OpenWrt router onboarding.
     *
     * Deliberately separate from the Android enrolment-code flow: routers have
     * no key attestation, so onboarding is gated by an operator-issued,
     * single-use pairing code that is scoped to exactly one client. It never
     * reads or consumes android_enrollment_codes.
     */
    public function pairOpenWrtDevice(Request $request, array $params): Response
    {
        $pairingCodes = $this->openWrtPairingCodes;
        $pairingCodes->assertAvailable();
        $code = (string) $request->input('pairing_code', '');
        $deviceId = $this->requestDeviceIdentity($request, true, 'openwrt_pairing_device_invalid');
        $policyClient = $this->activeClientForOpenWrtPairingCode($pairingCodes, $code);
        $requestedClientId = (int) $request->input('client_id', 0);
        if ($requestedClientId > 0 && $requestedClientId !== (int) ($policyClient['id'] ?? 0)) {
            // The code scope is authoritative; a request cannot redirect the
            // pairing to another client by naming one.
            throw new ApiException('client_id_mismatch', 403);
        }
        $this->accessPolicy->authorizeClientRequest($policyClient, $request, 'enrollment', $deviceId);
        $client = $this->db->transaction(function () use ($pairingCodes, $code): array {
            $row = $pairingCodes->findByCode($code);
            if ($row === null || $pairingCodes->isUsed($row)) {
                throw new ApiException('openwrt_pairing_code_invalid', 403);
            }
            if ($pairingCodes->isExpired($row)) {
                throw new ApiException('openwrt_pairing_code_expired', 403);
            }
            $client = $this->activeClientForOpenWrtPairingCodeRow($row);
            if ($pairingCodes->consume($code) === null) {
                throw new ApiException('openwrt_pairing_code_invalid', 403);
            }
            return $client;
        });
        $this->clientOnlineSessions()->enforceFromRequest($client, $request, 'pull');

        return $this->noStoreJson([
            'ok' => true,
            'client_id' => (int) $client['id'],
            'client_name' => (string) ($client['name'] ?? ''),
            'agent_token' => (string) ($client['agent_token'] ?? ''),
        ]);
    }

    public function resolveUltraMaterials(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        [$deviceSessions, $deviceId] = $this->enforceClientRequest($client, $request, 'pull');
        $deviceSession = $deviceSessions->activeSessionForClientAndDevice((int) $client['id'], $deviceId);
        $assigned = $this->provisioning->assignedForwardConfigsForClient((int) $client['id'], $deviceSession);
        $allowed = [];
        foreach ($assigned as $forward) {
            if (is_array($forward)
                && UltraMode::isBackendMode($forward['backend_mode'] ?? '')
                && (string) ($forward['service_status'] ?? '') === 'active') {
                $allowed[(int) ($forward['forward_id'] ?? 0)] = true;
            }
        }
        $resolved = [];
        $requests = $request->input('forwards', []);
        foreach (array_slice(is_array($requests) ? $requests : [], 0, 32) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $forwardId = (int) ($item['forward_id'] ?? 0);
            $token = trim((string) ($item['token'] ?? ''));
            if ($forwardId <= 0 || $token === '' || !isset($allowed[$forwardId])) {
                continue;
            }
            $material = $this->ultraMaterials->resolveClientToken(
                $token,
                (int) $client['id'],
                $deviceId,
                $forwardId,
                []
            );
            if ($material !== []) {
                $resolved[] = $material;
            }
        }
        return $this->json(['ok' => true, 'materials' => $resolved])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Issue one-shot Ultra v2 leases after re-reading the Panel policy. The
     * provider candidate is resolved server-side and is never persisted in a
     * client config or returned as a reusable material collection.
     */
    public function resolveUltraLease(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        [$deviceSessions, $deviceId] = $this->enforceClientRequest($client, $request, 'lease');
        $deviceSession = $deviceSessions->activeSessionForClientAndDevice((int) $client['id'], $deviceId);
        $assigned = $this->provisioning->assignedForwardConfigsForClient((int) $client['id'], $deviceSession);
        $assignedById = [];
        foreach (is_array($assigned) ? $assigned : [] as $forward) {
            if (is_array($forward)) {
                $assignedById[(int) ($forward['forward_id'] ?? 0)] = $forward;
            }
        }
        $forwards = $request->input('forwards', []);
        if (!is_array($forwards) || $forwards === []) {
            $forwards = [$request->body()];
        }
        $forwards = array_slice(array_values($forwards), 0, 4);
        $granted = $this->attachUltraAccessGrants($assigned, (int) $client['id'], $deviceId, true);
        $grantsById = [];
        foreach ($granted as $grantedForward) {
            if (!is_array($grantedForward)) {
                continue;
            }
            $grantedId = (int) ($grantedForward['forward_id'] ?? 0);
            if ($grantedId > 0 && is_array($grantedForward[UltraMode::ACCESS_FIELD] ?? null)) {
                $grantsById[$grantedId] = $grantedForward[UltraMode::ACCESS_FIELD];
            }
        }
        $nativeCapability = null;
        try {
            $nativeCapability = $this->provisioning->nativeOutboundCapabilityForAssignedForwards(
                is_array($assigned) ? $assigned : []
            );
        } catch (\Throwable) {
            $nativeCapability = null;
        }
        $nativeCarriersById = [];
        foreach (is_array($nativeCapability['forwards'] ?? null) ? $nativeCapability['forwards'] : [] as $carrier) {
            if (is_array($carrier)) {
                $nativeCarriersById[(int) ($carrier['forward_id'] ?? 0)] = $carrier;
            }
        }
        $leases = [];
        $lastError = 'lease_scope_invalid';
        foreach ($forwards as $item) {
            if (!is_array($item)) {
                continue;
            }
            $forwardId = (int) ($item['forward_id'] ?? 0);
            $forward = $assignedById[$forwardId] ?? null;
            $grant = $grantsById[$forwardId] ?? null;
            $purpose = strtolower(trim((string) ($item['purpose'] ?? '')));
            $isNative = $purpose === UltraMode::NATIVE_OUTBOUND_PURPOSE;
            if (!is_array($forward) || !is_array($grant)) {
                if ($isNative && !isset($nativeCarriersById[$forwardId])) {
                    $lastError = 'native_outbound_not_allowed';
                    continue;
                }
                $lastError = 'policy_unavailable';
                continue;
            }
            $nativeCarrier = $nativeCarriersById[$forwardId] ?? null;
            if ($isNative && !is_array($nativeCarrier)) {
                $lastError = 'native_outbound_not_allowed';
                continue;
            }
            $ticket = trim((string) ($item['session_ticket'] ?? ''));
            if ($ticket === '' || !hash_equals($ticket, (string) ($grant['session_ticket'] ?? ''))) {
                // The panel re-mints the ticket whenever the relay material rotates
                // (~every 30 minutes) while a running forward still carries the ticket
                // from its last config pull, so a strict comparison made every lease
                // refresh fail with 409 lease_scope_invalid. The agent then stopped the
                // forward and restarted it, which the user saw as an endless
                // "Starting routes" loop. The assignment above is already validated for
                // this exact client + device + forward, so a rotated ticket is accepted
                // and recorded instead of failing the refresh.
                if ($ticket === '' || (int) ($grant['forward_id'] ?? 0) !== $forwardId) {
                    $lastError = 'lease_scope_invalid';
                    continue;
                }
                error_log(sprintf(
                    'ultra_lease_ticket_rotated client=%d forward=%d device=%s',
                    $clientId,
                    $forwardId,
                    substr(hash('sha256', $deviceId), 0, 16)
                ));
            }
            $policy = [
                'forward_id' => $forwardId,
                'device_hash' => hash('sha256', $deviceId),
                'service_state' => (string) ($forward['service_status'] ?? 'suspended'),
                'policy_generation' => (string) ($grant['policy_generation'] ?? ''),
                'target' => trim((string) ($forward['target'] ?? '')),
                'transport' => (string) ($grant['transport'] ?? $forward['transport'] ?? 'tcp+udp'),
                'rate_limit_mbps' => (int) ($grant['rate_limit_mbps'] ?? $forward['speed_limit_mbps'] ?? 0),
                'quota_remaining_bytes' => (int) ($grant['quota_remaining_bytes'] ?? 0),
                'expires_at' => (string) ($grant['expires_at'] ?? ''),
            ];
            if ($isNative) {
                $direction = trim((string) ($nativeCarrier['direction'] ?? ''));
                $routeScope = UltraMode::normalizeNativeRouteScope(
                    (string) ($item['route_scope'] ?? 'global'),
                    $direction
                );
                $nativeTransport = (string) ($nativeCarrier['transport'] ?? '');
                $routePolicy = UltraMode::normalizeNativeRoutePolicy($item['route_policy'] ?? 'restricted');
                if ($routePolicy === null
                    || ($routePolicy === UltraMode::NATIVE_ROUTE_POLICY_UNRESTRICTED
                        && empty($nativeCarrier['unrestricted_allowed']))) {
                    $lastError = 'native_route_policy_not_allowed';
                    continue;
                }
                $requestDigest = strtolower(trim((string) ($item['target_digest'] ?? '')));
                $digestMatches = $routePolicy === UltraMode::NATIVE_ROUTE_POLICY_RESTRICTED
                    ? hash_equals(
                        UltraMode::nativeScopeDigest($forwardId, $direction, $nativeTransport, $routeScope ?? ''),
                        $requestDigest
                    ) || hash_equals(
                        UltraMode::nativeScopeDigestV2($forwardId, $direction, $nativeTransport, $routeScope ?? '', $routePolicy),
                        $requestDigest
                    )
                    : hash_equals(
                        UltraMode::nativeScopeDigestV2($forwardId, $direction, $nativeTransport, $routeScope ?? '', $routePolicy),
                        $requestDigest
                    );
                if ($routeScope === null || $nativeTransport !== 'tcp+udp' || !$digestMatches) {
                    $lastError = 'policy_scope_invalid';
                    continue;
                }
                $policy['target'] = $routePolicy === UltraMode::NATIVE_ROUTE_POLICY_RESTRICTED
                    ? implode("\n", [
                        'native-outbound:v1',
                        $forwardId,
                        strtolower($direction),
                        $nativeTransport,
                        $routeScope,
                    ])
                    : implode("\n", [
                        'native-outbound:v2',
                        $forwardId,
                        strtolower($direction),
                        $nativeTransport,
                        $routeScope,
                        $routePolicy,
                    ]);
                $policy['transport'] = $nativeTransport;
                $policy['direction'] = $direction;
                $policy['route_scope'] = $routeScope;
                $policy['route_policy'] = $routePolicy;
                $policy['native_unrestricted_allowed'] = !empty($nativeCarrier['unrestricted_allowed']);
            }
            $selectionMode = strtolower(trim((string) ($item['selection_mode'] ?? '')));
            $maxCandidates = $selectionMode === 'auto'
                ? max(1, min(4, (int) ($item['max_candidates'] ?? 4)))
                : 1;
            $baseNonce = strtolower(trim((string) ($item['nonce'] ?? '')));
            $issuedCandidates = [];
            $forwardLeaseCount = 0;
            for ($candidateOffset = 0; $candidateOffset < 8 && $forwardLeaseCount < $maxCandidates; $candidateOffset++) {
                $candidateRequest = $item;
                $candidateRequest['candidate_offset'] = $candidateOffset;
                if ($candidateOffset > 0) {
                    $candidateRequest['nonce'] = hash('sha256', $baseNonce . "\n" . $candidateOffset);
                }
                $result = $this->ultraLeases->issueLease($candidateRequest, (int) $client['id'], $deviceId, $policy);
                if (($result['ok'] ?? false) === true && is_array($result['lease'] ?? null)) {
                    $candidateLease = $result['lease'];
                    $endpoint = is_array($candidateLease['endpoint'] ?? null) ? $candidateLease['endpoint'] : [];
                    $endpointKey = strtolower(trim((string) ($endpoint['ip'] ?? ''))) . ':' . (int) ($endpoint['port'] ?? 0);
                    $selectionRef = trim((string) ($candidateLease['selection_ref'] ?? ''));
                    if ($selectionRef === '') {
                        $credential = is_array($candidateLease['credential'] ?? null) ? $candidateLease['credential'] : [];
                        $selectionRef = strtolower(trim((string) ($credential['user'] ?? '')));
                    }
                    $selectionKey = $endpointKey . ':' . $selectionRef;
                    if ($endpointKey !== ':' && !isset($issuedCandidates[$selectionKey])) {
                        $issuedCandidates[$selectionKey] = true;
                        $leases[] = $candidateLease;
                        $forwardLeaseCount++;
                    }
                    continue;
                }
                $lastError = (string) ($result['error'] ?? $lastError);
                if ($lastError !== 'no_approved_candidate') {
                    break;
                }
                break;
            }
        }
        if ($leases === []) {
            return $this->json(['ok' => false, 'error' => $lastError], 409)->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ]);
        }
        return $this->json(['ok' => true, 'leases' => $leases])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function pullConfigs(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        [$deviceSessionService, $deviceId] = $this->enforceClientRequest($client, $request, 'pull');
        $deviceSession = $deviceSessionService->activeSessionForClientAndDevice((int) $client['id'], $deviceId);

        $sinceRevision = trim((string) $request->input('since_revision', ''));
        $waitSeconds = max(0, min(25, (int) $request->input('wait_seconds', 0)));
        $runtimeArchitecture = $this->clientRuntimeArchitecture($request);

        $snapshot = $this->stableConfigSnapshot((int) $client['id'], $deviceSession, $client, $runtimeArchitecture);
        $configRevision = (string) $snapshot['config_revision'];
        $forceApply = (bool) $snapshot['force_apply'];

        // Bounded long-poll: only re-serve when the stable config actually changed.
        if ($sinceRevision !== '' && hash_equals($sinceRevision, $configRevision) && !$forceApply && $waitSeconds > 0) {
            $deadline = microtime(true) + $waitSeconds;
            while (microtime(true) < $deadline) {
                usleep(500000);
                $snapshot = $this->stableConfigSnapshot((int) $client['id'], $deviceSession, $client, $runtimeArchitecture);
                if (!hash_equals($sinceRevision, (string) $snapshot['config_revision']) || (bool) $snapshot['force_apply']) {
                    break;
                }
            }
        }

        if ($sinceRevision !== '' && hash_equals($sinceRevision, (string) $snapshot['config_revision']) && !(bool) $snapshot['force_apply']) {
            return new Response(304, [
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ], '');
        }

        $forwards = $snapshot['forwards'];
        $agentConfig = $snapshot['agent_config'];
        $clientAgent = $snapshot['client_agent'];
        $clientAgentInstaller = $snapshot['client_agent_installer'];
        $installForwardRuntime = $snapshot['install_forward_runtime'];
        $bondBinary = $snapshot['bond_binary'];
        $mptcpRuntime = $snapshot['mptcp_runtime'];
        $cfwgLatencyWatchdog = $snapshot['cfwg_latency_watchdog'];
        // Keep the pull endpoint JSON-safe while older deployments finish
        // rolling out the optional wstunnel artifact metadata.
        $wstunnelBinary = is_array($snapshot['wstunnel_binary'] ?? null)
            ? $snapshot['wstunnel_binary']
            : [];
        $desiredBondSha256 = (string) $snapshot['desired_bond_sha256'];
        $desiredUltraRuntimeVersion = (string) $snapshot['desired_ultra_runtime_version'];
        $desiredUltraRuntimeSha256 = (string) $snapshot['desired_ultra_runtime_sha256'];
        $updateRequestToken = (string) $snapshot['update_request_token'];
        $linuxProfileRuntime = $snapshot['linux_profile_runtime'];

        $platform = strtolower(trim((string) ($request->header('x-device-platform') ?? '')));
        // Older Mac builds did not consistently send X-Device-Platform. The
        // dedicated User-Agent remains an authenticated client identity signal
        // and keeps those builds on the scrubbed Ultra v2 contract.
        $userAgent = strtolower(trim((string) ($request->header('user-agent') ?? '')));
        $policyV2 = self::usesUltraPolicyV2($platform)
            || str_starts_with($userAgent, 'leikwan-mac-gui/');
        $forwards = $this->attachUltraAccessGrants(
            $forwards,
            (int) $client['id'],
            $deviceId,
            $policyV2 || $this->isAndroidRequest($request)
        );

        $responsePayload = [
            'ok' => true,
            'config_revision' => (string) $snapshot['config_revision'],
            'forward_revision' => (string) $snapshot['forward_revision'],
            'force_apply' => (bool) $snapshot['force_apply'],
            'next_poll_after_ms' => (int) $snapshot['next_poll_after_ms'],
            'client' => [
                'id' => (int) $client['id'],
                'name' => (string) $client['name'],
                'display_host' => (string) $client['display_host'],
                'group_id' => $client['group_id'] !== null ? (int) $client['group_id'] : null,
                'diag_request_token' => (string) ($client['last_diag_request_token'] ?? ''),
                'diag_status' => (string) ($client['last_diag_status'] ?? ''),
                'debug_collection' => (new DebugCollectionStore($this->db, $this->config))->metadata($client),
                'agent_config_json' => $agentConfig,
                'agent_update_request_token' => $updateRequestToken,
                'agent_update_requested_at' => (string) ($client['agent_update_requested_at'] ?? ''),
                'desired_agent_version' => (string) ($clientAgent['version'] ?? ''),
                'desired_agent_sha256' => (string) ($clientAgent['sha256'] ?? ''),
                'desired_installer_version' => (string) ($clientAgentInstaller['version'] ?? ''),
                'desired_installer_sha256' => (string) ($clientAgentInstaller['sha256'] ?? ''),
                'desired_install_forward_runtime_version' => (string) ($installForwardRuntime['version'] ?? ''),
                'desired_install_forward_runtime_sha256' => (string) ($installForwardRuntime['sha256'] ?? ''),
                'desired_ultra_runtime_version' => $desiredUltraRuntimeVersion,
                'desired_ultra_runtime_sha256' => $desiredUltraRuntimeSha256,
                'desired_bond_version' => (string) ($bondBinary['version'] ?? ''),
                'desired_bond_sha256' => $desiredBondSha256,
                'desired_mptcp_runtime_version' => (string) ($mptcpRuntime['version'] ?? ''),
                'desired_mptcp_runtime_sha256' => (string) ($mptcpRuntime['sha256'] ?? ''),
                'desired_cfwg_watchdog_version' => (string) ($cfwgLatencyWatchdog['version'] ?? ''),
                'desired_cfwg_watchdog_sha256' => (string) ($cfwgLatencyWatchdog['sha256'] ?? ''),
                'desired_wstunnel_version' => (string) ($wstunnelBinary['version'] ?? ''),
                'desired_wstunnel_sha256' => (string) ($wstunnelBinary['sha256'] ?? ''),
                'online_device_limit' => max(1, (int) ($client['online_device_limit'] ?? 1)),
                'active_online_devices' => $deviceSessionService->activeCountForClient((int) $client['id']),
                'current_device_id' => $deviceId,
            ],
            'forwards' => $forwards,
            'linux_profile_runtime' => $linuxProfileRuntime,
        ];
        if (is_array($snapshot['native_outbound'] ?? null)) {
            $responsePayload['native_outbound'] = $snapshot['native_outbound'];
        }
        $response = $this->json($responsePayload);
        return $response->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Reads the stable (grant-free) client config and derives the revision
     * hashes used for long-poll / 304. Ephemeral Ultra grant material is added
     * later and is intentionally excluded from the revision.
     *
     * @param array<string, mixed>|null $deviceSession
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function stableConfigSnapshot(
        int $clientId,
        ?array $deviceSession,
        array $client,
        string $runtimeArchitecture = 'amd64'
    ): array
    {
        $forwards = $this->provisioning->assignedForwardConfigsForClient($clientId, $deviceSession);
        $clientAgent = $this->artifactMetadata('client_agent');
        $clientAgentInstaller = $this->artifactMetadata('client_agent_installer');
        $installForwardRuntime = $this->artifactMetadata('install_forward_runtime');
        $arm64 = $runtimeArchitecture === 'arm64';
        $ultraClientRuntime = $this->artifactMetadata('ultra_client_runtime');
        if ($arm64) {
            $ultraClientRuntime = $this->artifactMetadata('ultra_client_runtime_linux_arm64');
        }
        $bondBinary = $this->artifactMetadata('leikwan_bond_linux_amd64');
        if ($arm64) {
            $bondBinary = $this->artifactMetadata('leikwan_bond_linux_arm64');
        }
        $mptcpRuntime = $this->artifactMetadata('mptcp_client_runtime');
        $cfwgLatencyWatchdog = $this->artifactMetadata('cfwg_latency_watchdog');
        $wstunnelBinary = $this->artifactMetadata('wstunnel_linux_amd64');
        if ($arm64) {
            $wstunnelBinary = $this->artifactMetadata('wstunnel_linux_arm64');
        }
        $desiredBondSha256 = (string) ($bondBinary['binary_sha256'] ?? $bondBinary['sha256'] ?? '');
        $desiredUltraRuntimeVersion = (string) ($ultraClientRuntime['version'] ?? '');
        $desiredUltraRuntimeSha256 = (string) ($ultraClientRuntime['binary_sha256'] ?? $ultraClientRuntime['sha256'] ?? '');
        $updateRequestToken = trim((string) ($client['agent_update_request_token'] ?? ''));
        if ($updateRequestToken === '') {
            $updateRequestToken = $this->autoUpdateTokenForVersion((string) ($client['agent_version'] ?? ''), 'client_agent', 'auto-client-agent');
        }

        $agentConfig = $this->authoritativeAgentConfig($client);
        $agentConfig['udp_uot_sidepath'] = $this->provisioning->udpUotSidepathConfigForAssignedClientForwards(
            is_array($forwards) ? $forwards : [],
            is_array($agentConfig['udp_uot_sidepath'] ?? null) ? $agentConfig['udp_uot_sidepath'] : []
        );
        $agentConfig['tcp_accel_sidepath'] = $this->provisioning->tcpAccelSidepathConfigForAssignedClientForwards(
            is_array($forwards) ? $forwards : [],
            is_array($agentConfig['tcp_accel_sidepath'] ?? null) ? $agentConfig['tcp_accel_sidepath'] : []
        );
        $linuxProfileRuntime = null;
        $runtimePlatformScope = (string) ($client['runtime_platform_scope'] ?? 'all');
        if (in_array($runtimePlatformScope, ['all', 'linux'], true)) {
            $linuxProfileRuntime = $this->provisioning->linuxProfileRuntimeForClient($client, is_array($forwards) ? $forwards : []);
        }

        $nativeOutbound = null;
        try {
            $nativeOutbound = $this->provisioning->nativeOutboundCapabilityForAssignedForwards(
                is_array($forwards) ? $forwards : []
            );
        } catch (\Throwable) {
            // Capability publication is optional; a lookup failure must keep
            // the feature invisible and must not break ordinary config pulls.
            $nativeOutbound = null;
        }
        $forwardRevision = self::forwardRevision($forwards, $nativeOutbound);
        $debugCollection = (new DebugCollectionStore($this->db, $this->config))->metadata($client);
        $configRevision = self::configRevision($forwardRevision, $agentConfig, [
            'client_agent' => $clientAgent,
            'client_agent_installer' => $clientAgentInstaller,
            'install_forward_runtime' => $installForwardRuntime,
            'ultra_client_runtime' => $ultraClientRuntime,
            'leikwan_bond_linux_amd64' => $bondBinary,
            'leikwan_bond_linux_' . $runtimeArchitecture => $bondBinary,
            'mptcp_client_runtime' => $mptcpRuntime,
            'cfwg_latency_watchdog' => $cfwgLatencyWatchdog,
            'wstunnel_binary' => $wstunnelBinary,
        ], ['debug_collection' => $debugCollection, 'runtime_architecture' => $runtimeArchitecture]);

        return [
            'forwards' => $forwards,
            'native_outbound' => $nativeOutbound,
            'agent_config' => $agentConfig,
            'linux_profile_runtime' => $linuxProfileRuntime,
            'client_agent' => $clientAgent,
            'client_agent_installer' => $clientAgentInstaller,
            'install_forward_runtime' => $installForwardRuntime,
            'ultra_client_runtime' => $ultraClientRuntime,
            'bond_binary' => $bondBinary,
            'wstunnel_binary' => $wstunnelBinary,
            'runtime_architecture' => $runtimeArchitecture,
            'mptcp_runtime' => $mptcpRuntime,
            'cfwg_latency_watchdog' => $cfwgLatencyWatchdog,
            'desired_bond_sha256' => $desiredBondSha256,
            'desired_ultra_runtime_version' => $desiredUltraRuntimeVersion,
            'desired_ultra_runtime_sha256' => $desiredUltraRuntimeSha256,
            'update_request_token' => $updateRequestToken,
            'forward_revision' => $forwardRevision,
            'config_revision' => $configRevision,
            'force_apply' => false,
            'next_poll_after_ms' => 30000,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $forwards
     */
    public static function forwardRevision(array $forwards, ?array $nativeOutbound = null): string
    {
        $projection = [];
        foreach ($forwards as $forward) {
            if (!is_array($forward)) {
                continue;
            }
            $projection[] = [
                'forward_id' => (int) ($forward['forward_id'] ?? 0),
                'backend_mode' => (string) ($forward['backend_mode'] ?? ''),
                'target' => (string) ($forward['target'] ?? ''),
                'listen_port' => (int) ($forward['listen_port'] ?? 0),
                'target_contract' => (string) ($forward['target_contract'] ?? ''),
                'contract_version' => (int) ($forward['contract_version'] ?? 1),
                'mode' => (string) ($forward['mode'] ?? ''),
                'transport' => (string) ($forward['transport'] ?? ''),
                'service_status' => (string) ($forward['service_status'] ?? ''),
                'speed_limit_mbps' => (int) ($forward['speed_limit_mbps'] ?? 0),
                'native_outbound' => !empty($forward['native_outbound'] ?? $forward['native_outbound_enabled'] ?? false),
                'native_direction' => (string) ($forward['native_direction'] ?? $forward['native_direction_key'] ?? ''),
            ];
        }
        if ($nativeOutbound === null) {
            return hash('sha256', (string) json_encode($projection, JSON_UNESCAPED_SLASHES));
        }
        return hash('sha256', (string) json_encode([
            'forwards' => $projection,
            'native_outbound' => $nativeOutbound,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $agentConfig
     * @param array<string, array<string, mixed>> $artifactVersions
     */
    public static function configRevision(string $forwardRevision, array $agentConfig, array $artifactVersions, array $extra = []): string
    {
        $artifacts = [];
        foreach ($artifactVersions as $key => $artifact) {
            $artifacts[$key] = [
                'version' => (string) ($artifact['version'] ?? ''),
                'sha256' => (string) ($artifact['sha256'] ?? $artifact['binary_sha256'] ?? ''),
            ];
        }
        return hash('sha256', (string) json_encode([
            'forward_revision' => $forwardRevision,
            'agent_config' => $agentConfig,
            'artifacts' => $artifacts,
            'extra' => $extra,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Attach only normalized, short-lived broker grants to active ultra
     * forwards. Legacy leigod_mode and current_mode forwards do not enter
     * this path. Broker failures fail closed for the grant, not for unrelated
     * client configuration.
     *
     * @param array<int, array<string, mixed>> $forwards
     * @return array<int, array<string, mixed>>
     */
    private function attachUltraAccessGrants(array $forwards, int $clientId, string $deviceId, bool $policyV2 = false): array
    {
        $brokerForwards = $forwards;
        if (isset($this->provisioning)) {
            $metadata = $this->provisioning->ultraLineMetadataForAssignedForwards($forwards);
            foreach ($brokerForwards as $index => $forward) {
                if (!is_array($forward)) {
                    continue;
                }
                $forwardId = (string) ((int) ($forward['forward_id'] ?? 0));
                if (!isset($metadata[$forwardId])) {
                    continue;
                }
                $brokerForwards[$index]['_ultra_line_ids'] = $metadata[$forwardId]['line_ids'];
                $brokerForwards[$index]['_ultra_lines'] = $metadata[$forwardId]['lines'];
                $brokerForwards[$index]['_ultra_line_direction'] = $metadata[$forwardId]['line_direction'];
                $brokerForwards[$index]['_ultra_destination_zone'] = $metadata[$forwardId]['destination_zone'];
                $brokerForwards[$index]['_ultra_allowed_ingress_zones'] = $metadata[$forwardId]['allowed_ingress_zones'];
                $brokerForwards[$index]['_ultra_fallback_policy'] = $metadata[$forwardId]['fallback_policy'];
            }
        }
        try {
            $grants = $this->ultraTokenProvider->grantsForClientForwards($clientId, $deviceId, $brokerForwards, $policyV2);
        } catch (\Throwable) {
            return $policyV2 ? $this->scrubUltraForwards($forwards) : $forwards;
        }
        if ($grants === []) {
            return $policyV2 ? $this->scrubUltraForwards($forwards) : $forwards;
        }

        $zoneAliases = [];
        try {
            foreach ((new UltraInventoryService($this->config, $this->db))->activeZones('ingress') as $zone) {
                $code = strtolower(trim((string) ($zone['zone_code'] ?? '')));
                $aliases = is_array($zone['aliases'] ?? null) ? array_values(array_map('strval', $zone['aliases'])) : [];
                if ($code !== '' && $code !== 'unknown' && $aliases !== []) {
                    $zoneAliases[$code] = array_slice($aliases, 0, 16);
                }
            }
        } catch (\Throwable) {
            $zoneAliases = [];
        }

        foreach ($forwards as $index => $forward) {
            if (!is_array($forward)
                || !UltraMode::isBackendMode($forward['backend_mode'] ?? '')
                || (string) ($forward['service_status'] ?? '') !== 'active') {
                continue;
            }
            if ($policyV2) {
                $forwards[$index] = $this->scrubUltraClientForward($forward);
            }
            $forwardId = (string) ((int) ($forward['forward_id'] ?? 0));
            $grant = $grants[$forwardId] ?? null;
            $validGrant = $policyV2
                ? is_array($grant)
                    && ($grant['protocol'] ?? '') === UltraMode::POLICY_PROTOCOL
                    && trim((string) ($grant['session_ticket'] ?? '')) !== ''
                : is_array($grant)
                    && ($grant['protocol'] ?? '') === UltraMode::CAPABILITY_PROTOCOL
                    && trim((string) ($grant['token'] ?? '')) !== '';
            if (!$validGrant) {
                continue;
            }
            if (!$policyV2) {
                $grant['zone_aliases'] = $zoneAliases;
            }
            $forwards[$index][UltraMode::ACCESS_FIELD] = $grant;
        }
        return $forwards;
    }

    private static function usesUltraPolicyV2(string $platform): bool
    {
        $platform = strtolower(trim($platform));
        return str_starts_with($platform, 'windows')
            || str_starts_with($platform, 'linux')
            || str_starts_with($platform, 'macos');
    }

    /** @param array<string,mixed> $forward @return array<string,mixed> */
    private function scrubUltraClientForward(array $forward): array
    {
        foreach (['name', 'tenant_label', 'tenant_description', 'tenant_badge', 'forward_chain_name'] as $field) {
            if (array_key_exists($field, $forward)) {
                $forward[$field] = $this->redactUltraClientDisplayText($forward[$field]);
            }
        }
        foreach ([
            'client_token', 'credential', 'credentials', 'password', 'provider', 'providers',
            'line_client_id', 'line_client_name', 'line_client_host',
            'line_client_group_id', 'line_client_group_name', 'line_client_group_ids',
            'line_client_groups', 'line_group_mode', 'line_client_agent_config_json',
            'forward_combination_label',
            'forward_combination_name', 'bond_sidecar_point_id', 'bond_sidecar_point_name',
            'bond_sidecar_host', 'bond_sidecar_port', 'mptcp_sidecar_port',
            'bond_sidecar_cf_listen_port', 'bond_sidecar_cf_sni_host',
            'bond_sidecar_approved_upstream_realizations', 'bond_sidecar_point_agent_config_json',
            'bond_remote_host', 'bond_remote_port', 'cf_location_group_id',
            '_ultra_line_ids', '_ultra_lines', '_ultra_line_direction',
            '_ultra_destination_zone', '_ultra_allowed_ingress_zones', '_ultra_fallback_policy',
        ] as $field) {
            unset($forward[$field]);
        }
        return $forward;
    }

    private function redactUltraClientDisplayText(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        $redacted = preg_replace('/Leigod|雷神/iu', 'Ultra', $value);
        return is_string($redacted) ? $redacted : 'Ultra';
    }

    /** @param array<int,array<string,mixed>> $forwards @return array<int,array<string,mixed>> */
    private function scrubUltraForwards(array $forwards): array
    {
        foreach ($forwards as $index => $forward) {
            if (is_array($forward) && UltraMode::isBackendMode($forward['backend_mode'] ?? '')) {
                $forwards[$index] = $this->scrubUltraClientForward($forward);
            }
        }
        return $forwards;
    }

    public function heartbeat(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        [$deviceSessionService, $deviceId] = $this->enforceClientRequest($client, $request, 'heartbeat');
        $ultraStatuses = $request->input('ultra_status', []);
        // The runtime build identity reaches the panel in two shapes: the
        // desktop client puts it inside each per-forward usage snapshot, the
        // Linux/macOS agent puts it inside each per-forward status entry. Only
        // the explicit argument used to be read, so no session row ever stored a
        // fingerprint and the (already enabled) attestation enforcement had
        // nothing to compare against.
        $runtimeAttestation = $request->input('runtime_attestation');
        if (!is_array($runtimeAttestation)) {
            foreach ((array) $request->input('ultra_usage', []) as $usageEntry) {
                if (is_array($usageEntry) && is_array($usageEntry['runtime_attestation'] ?? null)) {
                    $runtimeAttestation = $usageEntry['runtime_attestation'];
                    break;
                }
            }
        }
        if (is_array($ultraStatuses)) {
            try {
                $this->ultraClientStatus->ingest(
                    (int) $client['id'],
                    $deviceId,
                    $ultraStatuses,
                    is_array($runtimeAttestation) ? $runtimeAttestation : null
                );
            } catch (\Throwable) {
                // Runtime status is observational and must not interrupt the data-plane heartbeat.
            }
        }
        $ultraCandidateFeedback = $request->input('ultra_candidate_feedback', []);
        if (is_array($ultraCandidateFeedback)) {
            try {
                $this->ultraClientStatus->ingestFeedback(
                    (int) $client['id'],
                    $deviceId,
                    $ultraCandidateFeedback
                );
            } catch (\Throwable) {
                // Candidate reputation is observational and must not interrupt
                // the ordinary heartbeat or forwarding path.
            }
        }
        $usageBytesIn = max(0, (int) $request->input('usage_bytes_in', 0));
        $usageBytesOut = max(0, (int) $request->input('usage_bytes_out', 0));
        $existingAgentConfig = is_string($client['agent_config_json'] ?? null)
            ? json_decode((string) $client['agent_config_json'], true)
            : [];
        if (!is_array($existingAgentConfig)) {
            $existingAgentConfig = [];
        }
        $runtimeState = $this->mergeRuntimeState($existingAgentConfig, [
            'supports_mptcp' => $request->input('supports_mptcp'),
            'wireguard_public_key' => $request->input('wireguard_public_key'),
            'cfwg_watchdog_sha256' => $request->input('cfwg_watchdog_sha256'),
            'wstunnel_sha256' => $request->input('wstunnel_sha256'),
            'wstunnel_version' => $request->input('wstunnel_version'),
            'wireguard_listen_port' => $request->input('wireguard_listen_port'),
            'wireguard_fallback_tcp_port' => $request->input('wireguard_fallback_tcp_port'),
        ]);

        // Regression sentinel: client_heartbeat_republish_on_wireguard_identity_change
        // Client heartbeats are frequent, so only republish assigned forwards when the
        // client's WG identity changes, with a periodic safety-net force interval.
        $existingFingerprint = trim((string) ($existingAgentConfig['last_heartbeat_fingerprint'] ?? ''));
        $existingFingerprintAt = (int) ($existingAgentConfig['last_heartbeat_republish_at'] ?? 0);
        $forceIntervalSeconds = max(60, (int) ($this->config->getInt('CLIENT_REPUBLISH_FORCE_INTERVAL_SECONDS', 600) ?? 600));
        $wgFingerprintInputs = [
            (string) ($runtimeState['wireguard_public_key'] ?? ''),
            (string) ((int) ($runtimeState['wireguard_listen_port'] ?? 0)),
            (string) ((int) ($runtimeState['wireguard_fallback_tcp_port'] ?? 0)),
        ];
        $wgFingerprint = sha1(implode(':', $wgFingerprintInputs));
        $now = time();
        $shouldRepublish = ($wgFingerprint !== $existingFingerprint)
            || ($existingFingerprintAt <= 0)
            || (($now - $existingFingerprintAt) >= $forceIntervalSeconds);
        $runtimeState['last_heartbeat_fingerprint'] = $wgFingerprint;
        if ($shouldRepublish) {
            $runtimeState['last_heartbeat_republish_at'] = $now;
        } elseif ($existingFingerprintAt > 0) {
            $runtimeState['last_heartbeat_republish_at'] = $existingFingerprintAt;
        }

        $this->db->execute(
            'UPDATE clients
             SET agent_version = :agent_version,
                 agent_config_json = :agent_config_json,
                 last_sync_at = :last_sync_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $client['id'],
                'agent_version' => (string) $request->input('agent_version', ''),
                'agent_config_json' => $runtimeState !== [] ? json_encode($runtimeState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'last_sync_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
        $reputation = $request->input('bond_leg_reputation', []);
        if (is_array($reputation) && $reputation !== []) {
            (new BondLegReputationService($this->db))->ingestClientSnapshots(
                (int) $client['id'],
                is_array($reputation['forwards'] ?? null) ? $reputation['forwards'] : []
            );
        }
        $this->provisioning->recordAgentUsage('client', (int) $client['id'], $usageBytesIn, $usageBytesOut);
        $ultraUsage = $request->input('ultra_usage', []);
        $ultraActions = [];
        $ultraUsageAcknowledged = true;
        if (is_array($ultraUsage)) {
            foreach (array_slice($ultraUsage, 0, 32) as $usage) {
                if (!is_array($usage)) {
                    continue;
                }
                try {
                    $action = $this->provisioning->recordUltraUsage((int) $client['id'], $deviceId, $usage);
                    if (is_array($action)
                        && (int) ($action['forward_id'] ?? 0) > 0
                        && (string) ($action['action'] ?? '') === 'stop') {
                        $ultraActions[(int) $action['forward_id']] = [
                            'forward_id' => (int) $action['forward_id'],
                            'policy_generation' => (string) ($action['policy_generation'] ?? ''),
                            'action' => 'stop',
                            'reason' => (string) ($action['reason'] ?? 'policy'),
                        ];
                    }
                } catch (\Throwable) {
                    // Usage accounting is retried by the client and must not
                    // interrupt the ordinary heartbeat response.
                    $ultraUsageAcknowledged = false;
                }
            }
        }
        if ($shouldRepublish) {
            $this->provisioning->republishActiveForwardsForClient((int) $client['id']);
        }

        return $this->json([
            'ok' => true,
            'ultra_usage_acknowledged' => $ultraUsageAcknowledged,
            'ultra_actions' => array_values($ultraActions),
        ]);
    }

    public function diagnosticsResult(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $this->enforceClientRequest($client, $request, 'pull');
        $requestToken = (string) $request->input('request_token', '');
        $results = is_array($request->input('results', [])) ? $request->input('results', []) : [];
        $error = (string) $request->input('error', '');

        $this->db->execute(
            'UPDATE clients
             SET last_diag_status = :status,
                 last_diag_run_at = :run_at,
                 last_diag_result_json = :result_json,
                 last_diag_error = :error,
                 updated_at = :updated_at
             WHERE id = :id AND (last_diag_request_token = :request_token OR :request_token = \'\')',
            [
                'id' => $client['id'],
                'status' => $error === '' ? 'ok' : 'error',
                'run_at' => gmdate('Y-m-d H:i:s'),
                'result_json' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error' => $error !== '' ? $error : null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'request_token' => $requestToken,
            ]
        );

        return $this->json(['ok' => true]);
    }

    /**
     * Receives one authenticated, pre-redacted debug-log chunk. Raw logs are
     * written below private application storage and are never artifact files.
     */
    public function debugLogChunk(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $this->enforceClientRequest($client, $request, 'pull');
        if (strlen($request->rawBody()) > 512 * 1024) {
            return $this->noStoreJson(['ok' => false, 'error' => 'debug_chunk_too_large'], 413);
        }
        $uuid = (string) $request->input('collection_uuid', '');
        $sequence = filter_var($request->input('sequence', null), FILTER_VALIDATE_INT);
        // Accept the implementation field names as well as the public
        // contract aliases so older/newer agents can upload to the same API.
        $sha256 = (string) $request->input('sha256', $request->input('chunk_sha256', ''));
        $chunk = $request->input('chunk_base64', $request->input('content_base64', ''));
        if ($sequence === false || !is_string($chunk)) {
            return $this->json(['ok' => false, 'error' => 'debug_chunk_invalid'], 422);
        }

        $result = (new DebugCollectionStore($this->db, $this->config))->append(
            $client,
            $uuid,
            (int) $sequence,
            $sha256,
            $chunk,
        );
        return $this->noStoreJson([
            'ok' => true,
            'accepted' => (bool) ($result['accepted'] ?? false),
            'duplicate' => (bool) ($result['duplicate'] ?? false),
            'bytes_received' => (int) ($result['bytes_received'] ?? 0),
            'byte_limit' => DebugCollectionStore::BYTE_LIMIT,
        ]);
    }

    public function desktopLogin(Request $request, array $params): Response
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');

        if ($email === '' || $password === '') {
            return $this->json(['ok' => false, 'error' => 'email and password required'], 400);
        }

        $user = $this->db->fetchOne('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);

        if (
            $user === null ||
            (string) $user['status'] !== 'active' ||
            !is_string($user['password_hash']) ||
            $user['password_hash'] === '' ||
            !password_verify($password, (string) $user['password_hash'])
        ) {
            return $this->json(['ok' => false, 'error' => 'invalid credentials'], 401);
        }

        $loginDevice = $this->requestDeviceIdentity($request, false, 'device_uuid_invalid');
        if ($loginDevice !== '') {
            $this->accessPolicy->preflightClientRequest([
                'owner_user_id' => (int) $user['id'],
            ], $request, $loginDevice);
        }

        // Find clients owned by this user
        $clients = $this->db->fetchAll(
            'SELECT id, name, agent_token FROM clients WHERE owner_user_id = :user_id AND status = :status',
            ['user_id' => $user['id'], 'status' => 'active']
        );

        if (empty($clients)) {
            return $this->json(['ok' => false, 'error' => 'no clients assigned to this account'], 403);
        }

        $sessionService = new DesktopClientSessionService($this->db, $this->config);
        $session = $sessionService->issueForUser((int) $user['id'], (string) $user['email'], (string) ($user['name'] ?? $user['email']));

        return $this->json([
            'ok' => true,
            'session_token' => (string) $session['session_token'],
            'user_id' => (int) $user['id'],
            'username' => (string) ($user['name'] ?? $user['email']),
            'email' => (string) $user['email'],
            'clients' => array_map(fn($c) => [
                'id' => (int) $c['id'],
                'name' => (string) $c['name'],
            ], $clients),
        ]);
    }

    public function selectDesktopClient(Request $request, array $params): Response
    {
        $sessionToken = trim((string) $request->input('session_token', ''));
        $clientId = (int) $request->input('client_id', 0);

        if ($sessionToken === '' || $clientId <= 0) {
            return $this->json(['ok' => false, 'error' => 'session_token and client_id required'], 400);
        }

        $sessionService = new DesktopClientSessionService($this->db, $this->config);
        $session = $sessionService->findActive($sessionToken);
        if ($session === null) {
            return $this->json(['ok' => false, 'error' => 'invalid desktop session'], 401);
        }

        $client = $this->db->fetchOne(
            'SELECT * FROM clients WHERE id = :id AND owner_user_id = :user_id AND status = :status LIMIT 1',
            ['id' => $clientId, 'user_id' => $session['user_id'], 'status' => 'active']
        );

        if ($client === null) {
            return $this->json(['ok' => false, 'error' => 'client not found'], 404);
        }
        $deviceId = $this->clientOnlineSessions()->deviceIdFromRequest($request);
        $this->accessPolicy->authorizeClientRequest($client, $request, 'login', $deviceId);

        return $this->json([
            'ok' => true,
            'client_id' => (int) $client['id'],
            'client_name' => (string) $client['name'],
            'agent_token' => (string) $client['agent_token'],
        ]);
    }

    /** @return array<string, mixed> */
    private function accessPolicyClient(Request $request): array
    {
        $agentToken = trim((string) ($request->bearerToken() ?? $request->input('token', '')));
        if ($agentToken !== '') {
            $client = Auth::requireClientAgent($request, $this->db);
            $requestedClientId = (int) $request->input('client_id', 0);
            if ($requestedClientId > 0 && $requestedClientId !== (int) ($client['id'] ?? 0)) {
                throw new ApiException('client_id_mismatch', 403);
            }
            return $client;
        }

        $desktopSessionToken = trim((string) $request->input('session_token', ''));
        $clientId = (int) $request->input('client_id', 0);
        if ($desktopSessionToken !== '' && $clientId > 0) {
            $session = (new DesktopClientSessionService($this->db, $this->config))->findActive($desktopSessionToken);
            if ($session === null) {
                throw new ApiException('client_agent_auth_failed', 401);
            }
            $client = $this->db->fetchOne(
                'SELECT * FROM clients
                 WHERE id = :id AND owner_user_id = :user_id AND status = :status LIMIT 1',
                ['id' => $clientId, 'user_id' => (int) $session['user_id'], 'status' => 'active']
            );
            if ($client === null) {
                throw new ApiException('client_agent_auth_failed', 401);
            }
            return $client;
        }

        $enrollmentCode = trim((string) $request->input('enrollment_code', ''));
        if ($enrollmentCode !== '') {
            return $this->activeClientForEnrollmentCode($enrollmentCode);
        }
        throw new ApiException('client_agent_auth_failed', 401);
    }

    private function accessPolicyPlatform(Request $request): string
    {
        $platform = strtolower(trim((string) ($request->header('x-device-platform') ?? $request->input('platform', ''))));
        // Routers join the location protocol through the same classifier the
        // access policy applies, so openwrt/openwrt-* and the router form
        // factor cannot drift between the two surfaces.
        if (!in_array($platform, ['android', 'windows', 'macos', 'ios'], true)
            && !AccessPolicyService::isRouterPlatform($platform)) {
            throw new ApiException('location_platform_invalid', 422);
        }
        return $platform;
    }

    private function accessPolicyDeviceUuid(Request $request): string
    {
        return $this->requestDeviceIdentity($request, true, 'device_uuid_invalid');
    }

    private function accessPolicyAttemptId(Request $request): string
    {
        $attemptId = trim((string) ($request->header('x-attempt-id') ?? $request->input('attempt_id', '')));
        if ($attemptId === '' || strlen($attemptId) > 128) {
            throw new ApiException('location_attempt_invalid', 422);
        }
        return $attemptId;
    }

    /** @param array<string, mixed> $decision @return array<string, mixed> */
    private function safeAccessDecision(array $decision): array
    {
        $safe = [];
        foreach ([
            'decision', 'location_required', 'geo_lock_enabled', 'province_code',
            'main_province_code', 'next_location_check_at', 'reset_generation',
            'province_changed', 'reset_blocked_device_count',
        ] as $field) {
            if (array_key_exists($field, $decision)) {
                $safe[$field] = $decision[$field];
            }
        }
        return $safe;
    }

    /**
     * @param array<string, mixed> $client
     * @return array{0:ClientOnlineSessionService,1:string}
     */
    private function enforceClientRequest(array $client, Request $request, string $touchType): array
    {
        $sessions = $this->clientOnlineSessions();
        $deviceId = $sessions->deviceIdFromRequest($request);
        $this->accessPolicy->authorizeClientRequest($client, $request, $touchType, $deviceId);
        if ($this->isAndroidRequest($request)) {
            $this->deviceControls->requireActive($client, $request);
        }
        return [$sessions, $sessions->enforceFromRequest($client, $request, $touchType)];
    }

    private function clientOnlineSessions(): ClientOnlineSessionService
    {
        return new ClientOnlineSessionService(
            $this->db,
            new TrustedClientIpResolver((string) ($this->config->get('TRUSTED_PROXY_CIDRS', '') ?? '')),
        );
    }

    private function isAndroidRequest(Request $request): bool
    {
        return str_starts_with(strtolower(trim((string) ($request->header('x-device-platform') ?? ''))), 'android');
    }

    private function clientRuntimeArchitecture(Request $request): string
    {
        $raw = strtolower(trim((string) ($request->header('x-device-architecture') ?? $request->input('architecture', ''))));
        return in_array($raw, ['arm64', 'aarch64', 'armv8'], true) ? 'arm64' : 'amd64';
    }

    private function requireAndroidRequest(Request $request): void
    {
        if (!$this->isAndroidRequest($request)) {
            throw new ApiException('android_platform_required', 403);
        }
    }

    /** @param array<string, mixed> $client */
    private function assertRequestedClientId(array $client, Request $request): void
    {
        $requestedClientId = (int) $request->input('client_id', 0);
        if ($requestedClientId <= 0 || $requestedClientId !== (int) ($client['id'] ?? 0)
            || (string) ($client['status'] ?? '') !== 'active') {
            throw new ApiException('client_id_mismatch', 403);
        }
    }

    private function requestedDeviceId(Request $request): string
    {
        return $this->requestDeviceIdentity($request, false, 'device_id_invalid');
    }

    private function requestDeviceIdentity(Request $request, bool $required, string $errorCode): string
    {
        $deviceId = '';
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
            if ($deviceId !== '' && !hash_equals($deviceId, $candidate)) {
                throw new ApiException($errorCode, 422);
            }
            $deviceId = $candidate;
        }
        if (($required && $deviceId === '')
            || strlen($deviceId) > 191
            || preg_match('/[\x00-\x20\x7f]/', $deviceId) === 1) {
            throw new ApiException($errorCode, 422);
        }
        return $deviceId;
    }

    /** @return array<string, mixed> */
    private function activeClientForEnrollmentCode(string $code): array
    {
        $codeRow = $this->enrollmentCodes->findActive($code);
        if ($codeRow === null) {
            throw new ApiException('android_enrollment_code_invalid', 403);
        }
        return $this->activeClientForEnrollmentCodeRow($codeRow);
    }

    /** @param array<string, mixed> $codeRow @return array<string, mixed> */
    private function activeClientForEnrollmentCodeRow(array $codeRow): array
    {
        $client = $this->db->fetchOne(
            'SELECT * FROM clients WHERE id = :id AND status = :status LIMIT 1',
            [
                'id' => (int) ($codeRow['client_id'] ?? 0),
                'status' => 'active',
            ]
        );
        if ($client === null) {
            throw new ApiException('android_enrollment_code_invalid', 403);
        }
        return $client;
    }

    /** @return array<string, mixed> */
    private function activeClientForOpenWrtPairingCode(OpenWrtPairingCodeService $pairingCodes, string $code): array
    {
        $row = $pairingCodes->findByCode($code);
        if ($row === null || $pairingCodes->isUsed($row)) {
            throw new ApiException('openwrt_pairing_code_invalid', 403);
        }
        if ($pairingCodes->isExpired($row)) {
            throw new ApiException('openwrt_pairing_code_expired', 403);
        }
        return $this->activeClientForOpenWrtPairingCodeRow($row);
    }

    /** @param array<string, mixed> $codeRow @return array<string, mixed> */
    private function activeClientForOpenWrtPairingCodeRow(array $codeRow): array
    {
        $client = $this->db->fetchOne(
            'SELECT * FROM clients WHERE id = :id AND status = :status LIMIT 1',
            [
                'id' => (int) ($codeRow['client_id'] ?? 0),
                'status' => 'active',
            ]
        );
        if ($client === null) {
            throw new ApiException('openwrt_pairing_code_invalid', 403);
        }
        return $client;
    }

    /** @param array<string, mixed> $payload */
    private function noStoreJson(array $payload, int $status = 200): Response
    {
        return $this->json($payload, $status)->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function authoritativeAgentConfig(array $client): array
    {
        $stored = is_string($client['agent_config_json'] ?? null)
            ? json_decode((string) $client['agent_config_json'], true)
            : [];
        if (!is_array($stored)) {
            $stored = [];
        }

        $managedKeys = [
            'state_dir',
            'poll_interval_seconds',
            'health_check_interval_seconds',
            'log_level',
            'panel_mirror_urls',
            'udp_uot_sidepath',
            'tcp_accel_sidepath',
            'cfwg_latency_watchdog',
            'runtime_refresh',
            'cfwg_edge_pins',
            'ultra_selection_mode',
        ];
        $snapshot = array_fill_keys(array_merge(['master_url'], $managedKeys), null);
        $snapshot['master_url'] = $this->agentBaseUrl();
        $snapshot['artifact_base_url'] = $this->artifactBaseUrl();

        foreach ($managedKeys as $key) {
            if (array_key_exists($key, $stored)) {
                $snapshot[$key] = $stored[$key];
            }
        }
        // Ultra candidate racing is now the default runtime contract. Older
        // agents stored the previous implicit "single" value; publishing
        // "auto" here migrates them on the next authenticated config pull.
        $snapshot['ultra_selection_mode'] = 'auto';
        $snapshot['cfwg_latency_watchdog'] = $this->mergeCfwgLatencyWatchdogDefaults(
            is_array($snapshot['cfwg_latency_watchdog']) ? $snapshot['cfwg_latency_watchdog'] : []
        );

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function mergeCfwgLatencyWatchdogDefaults(array $stored): array
    {
        $defaults = $this->defaultCfwgLatencyWatchdogConfig();
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $stored)) {
                $stored[$key] = $value;
            }
        }
        if (!is_array($stored['baseline_rtt_ms'] ?? null)) {
            $stored['baseline_rtt_ms'] = $defaults['baseline_rtt_ms'];
        } else {
            foreach ($defaults['baseline_rtt_ms'] as $forwardId => $rttMs) {
                if (!array_key_exists((string) $forwardId, $stored['baseline_rtt_ms'])) {
                    $stored['baseline_rtt_ms'][(string) $forwardId] = $rttMs;
                }
            }
        }
        if (!is_array($stored['restart_actions'] ?? null) || $stored['restart_actions'] === []) {
            $stored['restart_actions'] = $defaults['restart_actions'];
        }
        return $stored;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultCfwgLatencyWatchdogConfig(): array
    {
        $enabled = $this->config->getBool('CFWG_LATENCY_WATCHDOG_ENABLED', true);
        $forwardIds = [];
        foreach (explode(',', (string) ($this->config->get('CFWG_LATENCY_WATCHDOG_FORWARD_IDS', '') ?? '')) as $rawForwardId) {
            $forwardId = (int) trim($rawForwardId);
            if ($forwardId > 0 && !in_array($forwardId, $forwardIds, true)) {
                $forwardIds[] = $forwardId;
            }
        }
        $baselineRtt = [];
        foreach (explode(',', (string) ($this->config->get('CFWG_LATENCY_WATCHDOG_BASELINES', '47:31') ?? '47:31')) as $rawPair) {
            $parts = array_map('trim', explode(':', $rawPair, 2));
            if (count($parts) !== 2) {
                continue;
            }
            $forwardId = (int) $parts[0];
            $rttMs = (float) $parts[1];
            if ($forwardId > 0 && $rttMs > 0) {
                $baselineRtt[(string) $forwardId] = $rttMs;
            }
        }

        return [
            'enabled' => $enabled,
            'forward_ids' => $forwardIds,
            'probe_interval_seconds' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_PROBE_INTERVAL_SECONDS', 5),
            'window_samples' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_WINDOW_SAMPLES', 3),
            'dwell_windows' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_DWELL_WINDOWS', 2),
            'critical_rtt_ms' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_CRITICAL_RTT_MS', 55),
            'critical_rtt_delta_ms' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_CRITICAL_RTT_DELTA_MS', 10),
            'baseline_rtt_ms' => $baselineRtt,
            'relative_degradation_factor' => (float) ($this->config->get('CFWG_LATENCY_WATCHDOG_RELATIVE_FACTOR', '1.35') ?? '1.35'),
            'min_relative_degradation_ms' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_MIN_RELATIVE_DELTA_MS', 8),
            'critical_loss_pct' => (float) ($this->config->get('CFWG_LATENCY_WATCHDOG_CRITICAL_LOSS_PCT', '0.34') ?? '0.34'),
            'cooldown_seconds' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_COOLDOWN_SECONDS', 240),
            'ping_timeout_seconds' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_PING_TIMEOUT_SECONDS', 1),
            'restart_actions' => ['wstunnel', 'wireguard'],
            'state_write_interval_seconds' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_STATE_WRITE_INTERVAL_SECONDS', 15),
        ];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function mergeRuntimeState(array $existing, array $input): array
    {
        $merged = $existing;
        foreach (['supports_mptcp'] as $boolKey) {
            if (array_key_exists($boolKey, $input) && $input[$boolKey] !== null && $input[$boolKey] !== '') {
                $merged[$boolKey] = !in_array(strtolower(trim((string) $input[$boolKey])), ['0', 'false', 'no', 'off'], true);
            }
        }
        foreach (['wireguard_public_key', 'cfwg_watchdog_sha256', 'wstunnel_sha256', 'wstunnel_version'] as $stringKey) {
            if (array_key_exists($stringKey, $input)) {
                $value = trim((string) ($input[$stringKey] ?? ''));
                if ($value !== '') {
                    $merged[$stringKey] = $value;
                }
            }
        }
        foreach (['wireguard_listen_port', 'wireguard_fallback_tcp_port'] as $intKey) {
            if (array_key_exists($intKey, $input) && $input[$intKey] !== null && $input[$intKey] !== '') {
                $merged[$intKey] = max(0, (int) $input[$intKey]);
            }
        }
        return $merged;
    }
}
