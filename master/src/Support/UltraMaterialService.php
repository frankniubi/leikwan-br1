<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use DateTimeImmutable;
use DateTimeZone;
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use RuntimeException;
use Throwable;

final class UltraMaterialService
{
    private const MATERIAL_PREFIX = 'ultra.material.v2.source.';
    private const LEGACY_MATERIAL_PREFIX = 'ultra.material.v1.line.';
    private const GRANT_CACHE_PREFIX = 'ultra.access.v3.client.';
    private const CLIENT_SCOPE_PREFIX = 'ultra.client.scope.v2.';
    private const SOURCE_NONCE_PREFIX = 'ultra.source.nonces.v1.';
    private const TICKET_NONCE_PREFIX = 'ultra.source.tickets.v1.';
    private const CREDENTIAL_REJECTED_PREFIX = 'ultra.material.rejected.v1.';
    private const CREDENTIAL_REJECTED_EVIDENCE_PREFIX = 'ultra.material.rejected.evidence.v1.';
    private const POLICY_GENERATION_SERIAL_PREFIX = 'ultra.policy.generation.serial.v1.forward.';

    /** @var callable(): int */
    private $clock;

    /** @var array<string, string> */
    private array $memorySettings = [];

    private UltraCandidateReputationService $candidateReputation;

    public function __construct(
        private Config $config,
        private ?Database $db = null,
        private ?AppSettings $settings = null,
        ?callable $clock = null,
        ?UltraCandidateReputationService $candidateReputation = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $this->candidateReputation = $candidateReputation ?? new UltraCandidateReputationService($db, $this->clock);
    }

    /** @return array<int, int> */
    public function requiredLineIds(?string $sourceId = null): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT ul.line_id
             FROM forward_combinations fc
             INNER JOIN forward_combination_ultra_lines fcul
                     ON fcul.forward_combination_id = fc.id AND fcul.enabled = 1
             INNER JOIN ultra_lines ul
                    ON ul.id = fcul.ultra_line_id
                   AND ul.is_source_present = 1
                   AND ul.is_enabled = 1
                   AND ((ul.access_class = \'native_udp\' AND ul.native_udp_state = \'verified\')
                        OR ul.native_socks5_state = \'verified\')
             INNER JOIN ultra_sources us
                     ON us.source_id = ul.source_id
                    AND us.is_active = 1
             INNER JOIN forwards f ON f.forward_combination_id = fc.id
             INNER JOIN services s ON s.id = f.service_id
             WHERE fc.backend_mode = :combination_mode
               AND fc.is_active = 1
               AND f.backend_mode = :forward_mode
               AND f.enabled = 1
               AND s.status = :service_status
             ' . ($sourceId !== null ? ' AND ul.source_id = :source_id' : '') . '
             ORDER BY ul.line_id ASC',
            [
                'combination_mode' => UltraMode::BACKEND_MODE,
                'forward_mode' => UltraMode::BACKEND_MODE,
                'service_status' => 'active',
            ] + ($sourceId !== null ? ['source_id' => trim($sourceId)] : [])
        );
        $result = [];
        foreach ($rows as $row) {
            $lineId = (int) ($row['line_id'] ?? 0);
            if ($lineId > 0 && $lineId <= 45535) {
                $result[$lineId] = $lineId;
            }
        }
        ksort($result, SORT_NUMERIC);
        return array_values($result);
    }

    /**
     * Lines whose material is due for a refresh, most urgent first.
     *
     * Only eight are handed out per poll. They used to be the eight lowest line
     * ids, so a line the source could not acquire kept its place at the front
     * forever and the lines behind it were only refreshed once their old
     * material had already expired. Most urgent first (never acquired, then
     * soonest to expire) fixes that without needing any state; $skipLineIds
     * are lines the source reports it is backing off from.
     *
     * A line whose current material a relay refused (noteCredentialRejected)
     * is due before its window and sorts like material that expires now.
     *
     * @param array<int, mixed> $skipLineIds
     * @return array<int, int>
     */
    public function dueLineIds(string|int $sourceIdOrRefreshWindow = '', int $refreshWindowSeconds = 600, array $skipLineIds = []): array
    {
        $sourceId = '';
        if (is_int($sourceIdOrRefreshWindow)) {
            $refreshWindowSeconds = $sourceIdOrRefreshWindow;
        } else {
            $sourceId = trim($sourceIdOrRefreshWindow);
        }
        if ($sourceId === '') {
            $sourceId = $this->primarySourceId();
        }
        $skip = [];
        foreach (array_slice($skipLineIds, 0, 64) as $rawLineId) {
            $skip[(int) $rawLineId] = true;
        }
        $now = $this->now();
        $due = [];
        foreach ($this->requiredLineIds($sourceId !== '' ? $sourceId : null) as $lineId) {
            if (isset($skip[$lineId])) {
                continue;
            }
            $material = $this->loadMaterial($sourceId, $lineId, false);
            $expiresAt = $this->parseTimestamp((string) ($material['expires_at'] ?? '')) ?? 0;
            if ($this->credentialRejectedForMaterial($sourceId, $lineId, $material)) {
                $due[$lineId] = min($expiresAt, $now);
            } elseif ($expiresAt <= $now + max(60, $refreshWindowSeconds)) {
                $due[$lineId] = $expiresAt;
            }
        }
        // asort keeps the original (ascending line id) order among equals.
        asort($due, SORT_NUMERIC);
        return array_slice(array_keys($due), 0, 8);
    }

    /**
     * A relay refused the credential of this line's current material (05 02).
     * LeiGod retires relays 10-20 minutes into a credential while the refresh
     * window only fires at minute 20, so clients keep dialling a dead relay for
     * up to ten minutes. Mark the material so dueLineIds() refreshes it early.
     *
     * Rate limited and bound to the observed material:
     *   - ignored while the material is younger than the configured interval;
     *   - ignored when the report's observed_at or lease issue time predates the
     *     current material's acquisition, so rolling historical feedback cannot
     *     mark a replacement upload;
     *   - deduplicated per observer + evidence_id so replayed heartbeats cannot
     *     re-trigger across material generations;
     *   - one pending mark per material generation/acquisition, cleared on upload.
     */
    public function noteCredentialRejected(
        string $sourceId,
        int $lineId,
        string $observerHash = '',
        string $evidenceId = '',
        ?int $observedAt = null,
        ?int $leaseIssuedAt = null,
    ): bool {
        $sourceId = trim($sourceId);
        $interval = $this->credentialRejectedRefreshIntervalSeconds();
        if ($sourceId === '' || $lineId <= 0 || $lineId > 45535 || $interval === 0) {
            return false;
        }
        $now = $this->now();
        if ($observedAt !== null
            && ($observedAt < $now - UltraMode::FEEDBACK_MAX_AGE_SECONDS
                || $observedAt > $now + UltraMode::FEEDBACK_FUTURE_SKEW_SECONDS)) {
            return false;
        }
        $material = $this->loadMaterial($sourceId, $lineId, true);
        $acquiredAt = $this->parseTimestamp((string) ($material['acquired_at'] ?? ''));
        if ($acquiredAt === null || $acquiredAt > $now - $interval) {
            return false;
        }
        if ($observedAt !== null && $observedAt < $acquiredAt) {
            return false;
        }
        if ($leaseIssuedAt !== null && $leaseIssuedAt > 0 && $leaseIssuedAt < $acquiredAt) {
            return false;
        }
        if ($this->credentialRejectedForMaterial($sourceId, $lineId, $material)) {
            return false;
        }
        $evidenceId = trim($evidenceId);
        if ($evidenceId !== '') {
            $scope = self::CREDENTIAL_REJECTED_EVIDENCE_PREFIX . hash('sha256', $sourceId) . '.line.' . $lineId;
            $replayKey = strtolower(trim($observerHash)) . ':' . $evidenceId;
            if (!$this->consumeReplayValue($scope, $replayKey, $this->materialMaxTtlSeconds())) {
                return false;
            }
        }
        $this->settingSet($this->credentialRejectedKey($sourceId, $lineId), $this->materialIdentityToken($material));
        return true;
    }

    /** @param array<string, mixed> $material */
    private function credentialRejectedForMaterial(string $sourceId, int $lineId, array $material): bool
    {
        $acquiredAt = (string) ($material['acquired_at'] ?? '');
        if ($acquiredAt === '') {
            return false;
        }
        // The mark names the material (generation + acquired_at) it was raised
        // against; any newer upload replaces it and clears the mark by itself.
        // Accept bare acquired_at as well so marks written before this change
        // remain legible across deploy.
        $marked = $this->settingGet($this->credentialRejectedKey($sourceId, $lineId), '');
        if ($marked === '') {
            return false;
        }
        return hash_equals($marked, $this->materialIdentityToken($material))
            || hash_equals($marked, $acquiredAt);
    }

    /** @param array<string, mixed> $material */
    private function materialIdentityToken(array $material): string
    {
        $acquiredAt = trim((string) ($material['acquired_at'] ?? ''));
        if ($acquiredAt === '') {
            return '';
        }
        $generation = trim((string) ($material['generation'] ?? ''));
        return $generation !== '' ? ($generation . '@' . $acquiredAt) : $acquiredAt;
    }

    private function credentialRejectedRefreshIntervalSeconds(): int
    {
        $raw = trim((string) $this->config->get(UltraMode::CREDENTIAL_REJECTED_REFRESH_INTERVAL_KEY, ''));
        if ($raw === '' || preg_match('/^[0-9]+$/', $raw) !== 1) {
            return UltraMode::DEFAULT_CREDENTIAL_REJECTED_REFRESH_INTERVAL_SECONDS;
        }
        $seconds = (int) $raw;
        return $seconds === 0 ? 0 : max(60, min(3600, $seconds));
    }

    private function credentialRejectedKey(string $sourceId, int $lineId): string
    {
        return self::CREDENTIAL_REJECTED_PREFIX . hash('sha256', trim($sourceId)) . '.line.' . $lineId;
    }

    /** @param array<int, int> $lineIds */
    public function issueUploadTicket(string $sourceId, array $lineIds): string
    {
        $normalized = $this->normalizeLineIds($lineIds);
        if ($sourceId === '' || $normalized === []) {
            return '';
        }
        $now = $this->now();
        return $this->signTicket([
            'kind' => 'source-upload',
            'source_id' => $sourceId,
            'line_ids' => $normalized,
            'issued_at' => $now,
            'expires_at' => $now + 180,
            'nonce' => bin2hex(random_bytes(16)),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $materials
     * @return array<int, int>
     */
    public function acceptSourceUpload(string $sourceId, string $ticket, array $materials): array
    {
        $ticketPayload = $this->openTicket($ticket);
        $now = $this->now();
        if (($ticketPayload['kind'] ?? '') !== 'source-upload'
            || !hash_equals((string) ($ticketPayload['source_id'] ?? ''), $sourceId)
            || (int) ($ticketPayload['expires_at'] ?? 0) <= $now) {
            throw new ApiException('ultra_source_ticket_invalid', 401);
        }
        $ticketNonce = trim((string) ($ticketPayload['nonce'] ?? ''));
        if ($ticketNonce === '') {
            throw new ApiException('ultra_source_ticket_replayed', 409);
        }
        // Acquiring a line invalidates the credential clients currently hold
        // for it, so the source uploads each line the moment it has it rather
        // than batching the cycle. A ticket therefore admits several uploads,
        // but each of its lines at most once.
        $replayScope = self::TICKET_NONCE_PREFIX . hash('sha256', $sourceId);
        $allowed = array_fill_keys($this->normalizeLineIds((array) ($ticketPayload['line_ids'] ?? [])), true);
        $accepted = [];
        $replayed = 0;
        foreach (array_slice($materials, 0, 8) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $material = $this->normalizeMaterial($raw, $now);
            if ($material === null || !isset($allowed[$material['line_id']])) {
                continue;
            }
            if (!$this->consumeReplayValue($replayScope, $ticketNonce . ':' . $material['line_id'], 300)) {
                $replayed++;
                continue;
            }
            $existing = $this->loadMaterial($sourceId, $material['line_id'], false);
            $existingAcquired = $this->parseTimestamp((string) ($existing['acquired_at'] ?? '')) ?? 0;
            $newAcquired = $this->parseTimestamp((string) $material['acquired_at']) ?? 0;
            if ($existingAcquired > $newAcquired) {
                continue;
            }
            $encoded = $this->seal(['kind' => 'line-material', 'material' => $material]);
            if ($encoded === null) {
                throw new RuntimeException('ultra_material_encryption_unavailable');
            }
            $this->settingSet($this->materialKey($sourceId, (int) $material['line_id']), $encoded);
            if ($this->db !== null) {
                try {
                    (new UltraInventoryService($this->config, $this->db))->safeMaterialUpdate(
                        $sourceId,
                        (int) $material['line_id'],
                        array_values(array_map(
                            static fn(array $relay): string => (string) ($relay['ip'] ?? ''),
                            (array) ($material['relays'] ?? [])
                        )),
                        (string) $material['acquired_at'],
                        (string) $material['expires_at']
                    );
                } catch (Throwable) {
                    // Material remains usable even if the safe admin projection is temporarily unavailable.
                }
            }
            $accepted[$material['line_id']] = $material['line_id'];
        }
        if ($accepted === [] && $replayed > 0) {
            throw new ApiException('ultra_source_ticket_replayed', 409);
        }
        ksort($accepted, SORT_NUMERIC);
        return array_values($accepted);
    }

    /**
     * @param array<int, array{source_id:string,line_id:int}|int> $lineRefs
     * @return array{line_ips:array<int,string>,fingerprint:string,path_fingerprint:string,expires_at:int,changed_at:int}|null
     *         changed_at is the newest acquisition time among the lines: the
     *         moment the fingerprint last moved because material was refreshed.
     *
     *         fingerprint moves with every acquisition (the source's material
     *         generation hashes the credential), which is right for the v1
     *         capability grant: it carries the material itself. path_fingerprint
     *         only names the relay endpoints each line offers, in order, and is
     *         what policy v2 folds into policy_generation (see LeigodTokenBroker).
     */
    public function lineSnapshot(array $lineRefs): ?array
    {
        $normalized = $this->normalizeLineRefs($lineRefs);
        $ips = [];
        $generations = [];
        $paths = [];
        $expiryOfLines = [];
        $changedAt = 0;
        foreach ($normalized as $lineRef) {
            $sourceId = (string) $lineRef['source_id'];
            $lineId = (int) $lineRef['line_id'];
            $material = $this->loadMaterial($sourceId, $lineId, true);
            if ($material === []) {
                continue;
            }
            $generations[] = $sourceId . ':' . $lineId . ':' . (string) ($material['generation'] ?? '');
            $expiryOfLines[] = $this->parseTimestamp((string) ($material['expires_at'] ?? '')) ?? 0;
            $changedAt = max($changedAt, $this->parseTimestamp((string) ($material['acquired_at'] ?? '')) ?? 0);
            $endpoints = [];
            foreach ((array) ($material['relays'] ?? []) as $relay) {
                if (!is_array($relay)) {
                    continue;
                }
                $ip = trim((string) ($relay['ip'] ?? ''));
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $ips[$ip] = true;
                    $port = (int) ($relay['port'] ?? 0);
                    if ($port > 0 && $port <= 65535) {
                        $endpoints[] = strtolower($ip) . '/' . $port;
                    }
                }
            }
            $paths[] = $sourceId . ':' . $lineId . '=' . implode(',', $endpoints);
        }
        if ($ips === [] || $generations === [] || $expiryOfLines === []) {
            return null;
        }
        $now = $this->now();
        // A grant can only be as long as the material behind it, and it is not
        // issued at all when that is under 30 seconds. Capping it by the
        // soonest-expiring line meant one line whose refresh failed took every
        // other healthy line of the forward down with it, a few seconds before
        // its own expiry. A line that is about to expire is left out of the cap
        // while at least one other line is comfortably valid: the lease plane
        // resolves candidates from fresh material only, so the dying line is
        // simply not offered once it is gone.
        $comfortable = array_filter(
            $expiryOfLines,
            static fn(int $expiry): bool => $expiry > $now + UltraMode::MATERIAL_EXPIRING_MARGIN_SECONDS
        );
        $expiresAt = min($comfortable !== [] ? $comfortable : $expiryOfLines);
        if ($expiresAt <= $now) {
            return null;
        }
        return [
            'line_ips' => array_slice(array_keys($ips), 0, 8),
            'fingerprint' => hash('sha256', implode('|', $generations)),
            'path_fingerprint' => hash('sha256', "ultra-paths-v1\n" . implode('|', $paths)),
            'expires_at' => $expiresAt,
            'changed_at' => $changedAt,
        ];
    }

    /**
     * The lines a grant covers as a sorted list of "source:line" keys, for
     * comparing the lines a client's ticket was minted for with today's.
     *
     * @param array<int, mixed> $lineRefs
     * @return array<int, string>
     */
    public function lineKeys(array $lineRefs): array
    {
        $keys = [];
        foreach ($this->normalizeClientScopeLines(['lines' => $lineRefs]) as $line) {
            $keys[] = (string) $line['source_id'] . ':' . (int) $line['line_id'];
        }
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * What a client's own session ticket says it was minted for, once the ticket
     * is proven to belong to this client, device and forward and to still be
     * valid. Used to recognise a grant that was current until the forward's
     * relay paths changed (see LeigodTokenBroker). Returns [] for anything else,
     * including tickets minted before the revocation epoch, the path
     * fingerprint or the generation serial was recorded in them: those can
     * never be given the benefit of the doubt.
     *
     * @return array{policy_generation:string,material_fingerprint:string,path_fingerprint:string,generation_serial:int,revocation_epoch:int,issued_at:int,expires_at:int,line_keys:array<int,string>}|array{}
     */
    public function presentedTicketScope(string $token, int $clientId, string $deviceId, int $forwardId): array
    {
        $token = trim($token);
        if ($token === '') {
            return [];
        }
        $payload = str_starts_with($token, 'u2')
            ? $this->open($token)
            : $this->open((string) $this->settingGet(self::CLIENT_SCOPE_PREFIX . hash('sha256', $token), ''));
        $now = $this->now();
        $generation = strtolower(trim((string) ($payload['policy_generation'] ?? '')));
        $fingerprint = strtolower(trim((string) ($payload['material_fingerprint'] ?? '')));
        $pathFingerprint = strtolower(trim((string) ($payload['path_fingerprint'] ?? '')));
        if (($payload['kind'] ?? '') !== 'client-access'
            || (int) ($payload['client_id'] ?? 0) !== $clientId
            || (int) ($payload['forward_id'] ?? 0) !== $forwardId
            || !hash_equals((string) ($payload['device_hash'] ?? ''), hash('sha256', $deviceId !== '' ? $deviceId : 'none'))
            || (int) ($payload['expires_at'] ?? 0) <= $now
            || !array_key_exists('revocation_epoch', $payload)
            || preg_match('/^[a-f0-9]{64}$/', $generation) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $pathFingerprint) !== 1
            || !is_int($payload['generation_serial'] ?? null)
            || $payload['generation_serial'] < 0) {
            return [];
        }
        return [
            'policy_generation' => $generation,
            'material_fingerprint' => $fingerprint,
            'path_fingerprint' => $pathFingerprint,
            'generation_serial' => (int) $payload['generation_serial'],
            'revocation_epoch' => (int) $payload['revocation_epoch'],
            'issued_at' => (int) ($payload['issued_at'] ?? 0),
            'expires_at' => (int) $payload['expires_at'],
            'line_keys' => $this->lineKeys(is_array($payload['lines'] ?? null) ? $payload['lines'] : []),
        ];
    }

    /** @param array<string, mixed> $scope */
    public function issueClientToken(array $scope, int $expiresAt): string
    {
        $now = $this->now();
        $payload = $scope + [
            'kind' => 'client-access',
            'issued_at' => $now,
            'expires_at' => $expiresAt,
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $encoded = $this->seal($payload);
        if ($encoded === null) {
            return '';
        }
        if (!str_starts_with($encoded, 'mem1.')) {
            return $encoded;
        }
        $token = 'ua1.' . $this->base64UrlEncode(random_bytes(32));
        $this->settingSet(self::CLIENT_SCOPE_PREFIX . hash('sha256', $token), $encoded);
        return $token;
    }

    /** @return array<string, mixed> */
    public function resolveClientToken(string $token, int $clientId, string $deviceId, int $forwardId, array $_orderedIps = []): array
    {
        $payload = str_starts_with($token, 'u2')
            ? $this->open($token)
            : $this->open((string) $this->settingGet(self::CLIENT_SCOPE_PREFIX . hash('sha256', $token), ''));
        $now = $this->now();
        if (($payload['kind'] ?? '') !== 'client-access'
            || (int) ($payload['client_id'] ?? 0) !== $clientId
            || (int) ($payload['forward_id'] ?? 0) !== $forwardId
            || !hash_equals((string) ($payload['device_hash'] ?? ''), hash('sha256', $deviceId !== '' ? $deviceId : 'none'))
            || (int) ($payload['expires_at'] ?? 0) <= $now) {
            return [];
        }
        $scopeLines = $this->normalizeClientScopeLines($payload);
        $entries = [];
        foreach ($scopeLines as $scopeLine) {
            $sourceId = (string) $scopeLine['source_id'];
            $lineId = (int) $scopeLine['line_id'];
            if (!$this->lineAllowsNativeUdp($sourceId, $lineId)) {
                continue;
            }
            $material = $this->loadMaterial($sourceId, $lineId, true);
            foreach ((array) ($material['relays'] ?? []) as $relay) {
                if (!is_array($relay)) {
                    continue;
                }
                $ip = trim((string) ($relay['ip'] ?? ''));
                $port = (int) ($relay['port'] ?? 0);
                $user = trim((string) ($material['user'] ?? ''));
                $credential = trim((string) ($material['credential'] ?? ''));
                if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false || $port <= 0 || $port > 65535 || $user === '' || $credential === '') {
                    continue;
                }
                $entryRef = $this->entryReference($token, [
                    (string) $clientId,
                    (string) ($payload['device_hash'] ?? ''),
                    (string) $forwardId,
                    $sourceId,
                    (string) $lineId,
                    (string) ($material['generation'] ?? ''),
                    $ip,
                    (string) ($payload['transport'] ?? 'tcp+udp'),
                ]);
                if ($entryRef === '') {
                    continue;
                }
                $entries[$sourceId . ':' . $lineId . ':' . $ip . ':' . $port . ':' . $user] = [
                    'entry_ref' => $entryRef,
                    'ingress_zone' => (string) $scopeLine['ingress_zone'],
                    'destination_zone' => (string) $scopeLine['destination_zone'],
                    'ip' => $ip,
                    'port' => $port,
                    'user' => $user,
                    'credential' => $credential,
                ];
            }
        }
        if ($entries === []) {
            return [];
        }
        $clearMaterial = [
            'entries' => array_slice(array_values($entries), 0, 8),
            'policy' => [
                'destination_zone' => $this->normalizeZone((string) ($payload['destination_zone'] ?? 'unknown')),
                'allowed_ingress_zones' => $this->normalizeZones((array) ($payload['allowed_ingress_zones'] ?? [])),
                'fallback_policy' => $this->normalizeFallbackPolicy((string) ($payload['fallback_policy'] ?? 'detected_then_any')),
            ],
        ];
        $sealedMaterial = $this->sealForClient($token, $clearMaterial);
        if ($sealedMaterial === null) {
            return [];
        }
        return [
            'forward_id' => $forwardId,
            'token' => $token,
            'expires_at' => $this->formatTimestamp((int) $payload['expires_at']),
            'sealed_material' => $sealedMaterial,
        ];
    }

    /**
     * Resolve one approved provider candidate inside the Panel process for a
     * one-shot Ultra lease. Provider credentials never leave this method's
     * caller boundary except in the short-lived lease response.
     *
     * @return array<string, mixed>
     */
    public function serverCandidateForClientToken(
        string $token,
        int $clientId,
        string $deviceId,
        int $forwardId,
        string $policyGeneration = '',
        int $candidateOffset = 0,
        string $purpose = '',
    ): array {
        $payload = str_starts_with($token, 'u2')
            ? $this->open($token)
            : $this->open((string) $this->settingGet(self::CLIENT_SCOPE_PREFIX . hash('sha256', $token), ''));
        $now = $this->now();
        if (($payload['kind'] ?? '') !== 'client-access'
            || (int) ($payload['client_id'] ?? 0) !== $clientId
            || (int) ($payload['forward_id'] ?? 0) !== $forwardId
            || !hash_equals((string) ($payload['device_hash'] ?? ''), hash('sha256', $deviceId !== '' ? $deviceId : 'none'))
            || ($policyGeneration !== '' && !hash_equals($policyGeneration, (string) ($payload['policy_generation'] ?? '')))
            || (int) ($payload['expires_at'] ?? 0) <= $now) {
            return [];
        }

        $candidateOffset = max(0, min(7, $candidateOffset));
        $nativePurpose = strtolower(trim($purpose)) === UltraMode::NATIVE_OUTBOUND_PURPOSE;
        $transport = strtolower(trim((string) ($payload['transport'] ?? 'tcp+udp')));
        if (!in_array($transport, ['tcp', 'udp', 'tcp+udp'], true)) {
            $transport = 'tcp+udp';
        }
        $candidateProtocol = $nativePurpose ? 'native_socks5' : $transport;
        $lineCandidates = [];
        $fallbackRejectedLineCandidates = [];
        $seenCandidates = [];
        foreach ($this->normalizeClientScopeLines($payload) as $scopeLine) {
            $sourceId = (string) $scopeLine['source_id'];
            $lineId = (int) $scopeLine['line_id'];
            if ($nativePurpose
                ? !$this->lineAllowsNativeSocks5($sourceId, $lineId)
                : !$this->lineAllowsNativeUdp($sourceId, $lineId)) {
                continue;
            }
            $material = $this->loadMaterial($sourceId, $lineId, true);
            $lineRejected = $this->credentialRejectedForMaterial($sourceId, $lineId, $material);
            $relayCandidates = [];
            foreach ((array) ($material['relays'] ?? []) as $relay) {
                if (!is_array($relay)) {
                    continue;
                }
                $ip = trim((string) ($relay['ip'] ?? ''));
                $port = (int) ($relay['port'] ?? 0);
                $user = trim((string) ($material['user'] ?? ''));
                $credential = trim((string) ($material['credential'] ?? ''));
                if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false
                    || $port <= 0 || $port > 65535 || $user === '' || $credential === '') {
                    continue;
                }
                // Not bound to the session ticket: a ticket is re-minted on
                // every grant renewal, and a runtime proves a same-generation
                // refresh is still its selected path by this reference (plus
                // ip:port). Bound to the ticket it changed on every renewal and
                // the runtime had to fall back to matching the material's user,
                // which a credential refresh may rotate; the policy generation
                // then had to move with every acquisition to keep refreshes
                // acceptable, and every move re-probed the whole candidate set.
                // Still scoped to this client, device, forward, line and relay.
                $selectionRef = $this->selectionReference([
                    (string) $clientId,
                    (string) ($payload['device_hash'] ?? ''),
                    (string) $forwardId,
                    $sourceId,
                    (string) $lineId,
                    $ip,
                    (string) $port,
                    (string) ($payload['transport'] ?? 'tcp+udp'),
                ]);
                if ($selectionRef === '') {
                    continue;
                }
                // The same relay socket may serve multiple logical lines.
                // Deduplicate repeated material by its opaque selection
                // reference, while preserving distinct credentials/lines for
                // client-side quality probing.
                $candidateKey = strtolower($ip) . ':' . $port . ':' . $selectionRef;
                if (isset($seenCandidates[$candidateKey])) {
                    continue;
                }
                $seenCandidates[$candidateKey] = true;
                $candidateScope = [
                    'source_id' => $sourceId,
                    'line_id' => $lineId,
                    'ingress_zone' => (string) ($scopeLine['ingress_zone'] ?? 'unknown'),
                    'destination_zone' => (string) ($scopeLine['destination_zone'] ?? ($payload['destination_zone'] ?? 'unknown')),
                    'protocol' => $candidateProtocol,
                ];
                // Register the scoped identity even in shadow mode so an
                // authenticated feedback hash can be resolved before the
                // dynamic filter is enabled.
                $this->candidateReputation->stateFor($candidateScope);
                $candidate = [
                    'selection_ref' => $selectionRef,
                    'policy_generation' => (string) ($payload['policy_generation'] ?? ''),
                    'ip' => $ip,
                    'port' => $port,
                    'user' => $user,
                    'credential' => $credential,
                    'transport' => $transport,
                    'selection_tier' => $this->selectionTier($sourceId, $lineId),
                    // This opaque hash is safe for lease-audit linkage and does
                    // not disclose the provider line identity to the client.
                    'candidate_key_hash' => UltraCandidateReputationService::candidateKeyHash($candidateScope),
                    '_candidate_scope' => $candidateScope,
                ];
                if ($nativePurpose) {
                    $candidate['native_socks5_state'] = 'verified';
                }
                $relayCandidates[] = $candidate;
            }
            if ($relayCandidates === []) {
                continue;
            }
            if ($lineRejected) {
                // A line whose current material is already awaiting a
                // credential_rejected refresh is placed behind healthy
                // lines so leases Issued during the source replacement
                // window do not keep handing out the dead relay/credential
                // first; single-line combos still keep it as fallback.
                $fallbackRejectedLineCandidates[] = $relayCandidates;
            } else {
                $lineCandidates[] = $relayCandidates;
            }
        }
        $candidates = array_merge(
            self::interleaveLineCandidates($lineCandidates),
            self::interleaveLineCandidates($fallbackRejectedLineCandidates)
        );
        if ($this->candidateDynamicQuarantineEnabled()) {
            $candidates = $this->candidateReputation->filterCandidates($candidates, 1);
        }
        $candidate = $candidates[$candidateOffset] ?? [];
        if (is_array($candidate)) {
            unset($candidate['_candidate_scope']);
            return $candidate;
        }
        return [];
    }

    /**
     * Fingerprint the dynamic candidate states that are relevant to a grant.
     * The broker folds this value into policy_generation so an enforced
     * quarantine cannot leave a client pinned to the old sticky candidate.
     *
     * @param array<int, mixed> $lineRefs
     */
    public function candidateReputationFingerprint(array $lineRefs, string $transport): string
    {
        if (!$this->candidateDynamicQuarantineEnabled()) {
            return '';
        }
        $transport = strtolower(trim($transport));
        if (!in_array($transport, ['tcp', 'udp', 'tcp+udp'], true)) {
            $transport = 'tcp+udp';
        }
        $protocols = [$transport];
        if ($transport === 'tcp+udp') {
            // Native outbound leases share the v2 grant generation with
            // ordinary Ultra, so a native-only state change must invalidate
            // the same sticky ticket as well.
            $protocols[] = 'native_socks5';
        }
        $scopes = [];
        foreach ($this->normalizeClientScopeLines(['lines' => $lineRefs]) as $line) {
            foreach ($protocols as $protocol) {
                $scopes[] = $line + ['protocol' => $protocol];
            }
        }
        return $this->candidateReputation->snapshotFingerprint($scopes);
    }

    /**
     * A per-forward serial that moves forward every time the content of the
     * forward's policy generation changes, so a generation can never repeat.
     *
     * A runtime refuses a generation it has already left (ErrPolicyRollback,
     * tools/ultra-client policy.go seenGens) for as long as it runs. While the
     * generation hashed the per-acquisition credential it never came back, but
     * a generation that covers only meaningful state does return to earlier
     * values: a candidate leaves quarantine, a lapsed line is re-acquired with
     * the relays it had. Folding this serial in makes every such change a new
     * generation and leaves an unchanged forward on the one it has.
     *
     * Best effort: a failed write only means the next pull derives the same
     * serial again from the stored state.
     */
    public function policyGenerationSerial(int $forwardId, string $contentHash): int
    {
        if ($forwardId <= 0 || $contentHash === '') {
            return 0;
        }
        $key = self::POLICY_GENERATION_SERIAL_PREFIX . $forwardId;
        $decoded = json_decode($this->settingGet($key, ''), true);
        $stored = is_array($decoded) ? $decoded : [];
        $serial = max(0, (int) ($stored['serial'] ?? 0));
        if (hash_equals((string) ($stored['content'] ?? ''), $contentHash)) {
            return $serial;
        }
        $serial++;
        try {
            $this->settingSet($key, json_encode(['content' => $contentHash, 'serial' => $serial], JSON_UNESCAPED_SLASHES) ?: '');
        } catch (Throwable) {
            // See above: the serial is re-derived from the stored state.
        }
        return $serial;
    }

    /**
     * Revocation bookkeeping for the policy-generation derivation.
     *
     * A policy generation is a pure function of the request, so a generation the
     * lease plane has already refused would be re-minted on every pull for ever:
     * the forward stays enabled with no lease, the agent retries the same dead
     * generation, and no client-side change can break the loop. The broker needs
     * to know which generations are dead and how far the forward's revocation
     * history has advanced so it can hand out one the lease plane will honour.
     *
     * @param array<int, int|string> $forwardIds
     * @return array{epochs: array<int, int>, revoked: array<string, true>}
     */
    public function revocationIndex(array $forwardIds): array
    {
        $empty = ['epochs' => [], 'revoked' => []];
        if ($this->db === null) {
            return $empty;
        }

        $ids = [];
        foreach ($forwardIds as $forwardId) {
            $id = (int) $forwardId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return $empty;
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $placeholders[] = ':forward_' . $index;
            $params['forward_' . $index] = $id;
        }

        $rows = $this->db->fetchAll(
            'SELECT forward_id, policy_generation, MAX(id) AS epoch
             FROM ultra_client_leases
             WHERE revoked_at IS NOT NULL AND forward_id IN (' . implode(', ', $placeholders) . ')
             GROUP BY forward_id, policy_generation',
            $params
        );

        $epochs = [];
        $revoked = [];
        foreach ($rows as $row) {
            $forwardId = (int) ($row['forward_id'] ?? 0);
            if ($forwardId <= 0) {
                continue;
            }
            $epoch = (int) ($row['epoch'] ?? 0);
            if ($epoch > ($epochs[$forwardId] ?? 0)) {
                $epochs[$forwardId] = $epoch;
            }
            $generation = strtolower(trim((string) ($row['policy_generation'] ?? '')));
            if ($generation !== '') {
                $revoked[$forwardId . ':' . $generation] = true;
            }
        }

        return ['epochs' => $epochs, 'revoked' => $revoked];
    }

    /**
     * $validityClass keeps long (offline-capable) grants apart from the short
     * legacy ones: '' is the legacy cache key, unchanged; any other class gets
     * its own key, so a short grant is never handed to a capable client and a
     * long grant never reaches a client that did not ask for one.
     *
     * @return array<string, array<string, mixed>>
     */
    public function loadGrantCache(int $clientId, string $deviceId, string $validityClass = ''): array
    {
        $decoded = $this->open((string) $this->settingGet($this->grantCacheKey($clientId, $deviceId, $validityClass), ''));
        $grants = is_array($decoded['grants'] ?? null) ? $decoded['grants'] : [];
        $result = [];
        foreach ($grants as $forwardId => $grant) {
            if (is_array($grant) && $this->parseTimestamp((string) ($grant['expires_at'] ?? '')) > $this->now()) {
                $result[(string) $forwardId] = $grant;
            }
        }
        return $result;
    }

    /** @param array<string, array<string, mixed>> $grants */
    public function saveGrantCache(int $clientId, string $deviceId, array $grants, string $validityClass = ''): void
    {
        $encoded = $this->seal(['kind' => 'grant-cache', 'grants' => $grants]);
        if ($encoded !== null) {
            $this->settingSet($this->grantCacheKey($clientId, $deviceId, $validityClass), $encoded);
        }
    }

    public function verifySourceRequest(Request $request): string
    {
        $sourceId = trim((string) ($request->header('x-ultra-source-id') ?? ''));
        $timestampText = trim((string) ($request->header('x-ultra-timestamp') ?? ''));
        $nonce = trim((string) ($request->header('x-ultra-nonce') ?? ''));
        $signature = strtolower(trim((string) ($request->header('x-ultra-signature') ?? '')));
        $credentials = $this->sourceCredentials();
        $secret = (string) ($credentials[$sourceId] ?? '');
        $timestamp = ctype_digit($timestampText) ? (int) $timestampText : 0;
        if ($sourceId === '' || $secret === ''
            || abs($this->now() - $timestamp) > 300
            || preg_match('/^[a-f0-9]{16,128}$/i', $nonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            throw new ApiException('ultra_source_auth_failed', 401);
        }
        if ($this->db !== null) {
            $registered = $this->db->fetchOne(
                'SELECT is_active FROM ultra_sources WHERE source_id = :source_id LIMIT 1',
                ['source_id' => $sourceId]
            );
            if (!is_array($registered) || (int) ($registered['is_active'] ?? 0) !== 1) {
                throw new ApiException('ultra_source_auth_failed', 401);
            }
        }
        $canonical = implode("\n", [
            $request->method(),
            $request->path(),
            $sourceId,
            (string) $timestamp,
            $nonce,
            hash('sha256', $request->rawBody()),
        ]);
        $expected = hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new ApiException('ultra_source_auth_failed', 401);
        }
        if (!$this->consumeReplayValue(self::SOURCE_NONCE_PREFIX . hash('sha256', $sourceId), $nonce, 600)) {
            throw new ApiException('ultra_source_replay', 409);
        }
        return $sourceId;
    }

    /** @param array<string, mixed> $raw @return array<string, mixed>|null */
    private function normalizeMaterial(array $raw, int $now): ?array
    {
        $lineId = (int) ($raw['line_id'] ?? 0);
        $generation = trim((string) ($raw['generation'] ?? ''));
        $user = trim((string) ($raw['user'] ?? ''));
        $credential = trim((string) ($raw['credential'] ?? ''));
        $acquiredAt = $this->parseTimestamp((string) ($raw['acquired_at'] ?? ''));
        $expiresAt = $this->parseTimestamp((string) ($raw['expires_at'] ?? ''));
        if ($lineId <= 0 || $lineId > 45535 || $generation === '' || strlen($generation) > 128
            || $user === '' || strlen($user) > 255 || $credential === '' || strlen($credential) > 255
            || $acquiredAt === null || $acquiredAt > $now + 300 || $expiresAt === null || $expiresAt <= $now
            || ($expiresAt - $now) > $this->materialMaxTtlSeconds()) {
            return null;
        }
        $relays = [];
        foreach (array_slice(is_array($raw['relays'] ?? null) ? $raw['relays'] : [], 0, 8) as $relay) {
            if (!is_array($relay)) {
                continue;
            }
            $ip = trim((string) ($relay['ip'] ?? ''));
            $port = (int) ($relay['port'] ?? 0);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false && $port > 0 && $port <= 65535) {
                $relays[$ip . ':' . $port] = ['ip' => $ip, 'port' => $port];
            }
        }
        if ($relays === []) {
            return null;
        }
        return [
            'line_id' => $lineId,
            'generation' => $generation,
            'acquired_at' => $this->formatTimestamp($acquiredAt),
            'expires_at' => $this->formatTimestamp($expiresAt),
            'user' => $user,
            'credential' => $credential,
            'relays' => array_values($relays),
            'probe' => ['ok' => (bool) (($raw['probe']['ok'] ?? false) === true)],
        ];
    }

    /** @return array<string, mixed> */
    private function loadMaterial(string $sourceId, int $lineId, bool $requireFresh): array
    {
        $sourceId = trim($sourceId);
        $encoded = $this->settingGet($this->materialKey($sourceId, $lineId), '');
        if ($encoded === '' && $sourceId !== '' && hash_equals($this->primarySourceId(), $sourceId)) {
            $encoded = $this->settingGet(self::LEGACY_MATERIAL_PREFIX . $lineId, '');
        }
        $decoded = $this->open($encoded);
        $material = is_array($decoded['material'] ?? null) ? $decoded['material'] : [];
        if (($decoded['kind'] ?? '') !== 'line-material' || $material === []) {
            return [];
        }
        if ($requireFresh && (($this->parseTimestamp((string) ($material['expires_at'] ?? '')) ?? 0) <= $this->now())) {
            return [];
        }
        return $material;
    }

    /** @param array<string, mixed> $payload @return array<int, array{source_id:string,line_id:int,ingress_zone:string,destination_zone:string}> */
    private function normalizeClientScopeLines(array $payload): array
    {
        $rawLines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        if ($rawLines === []) {
            $rawLines = array_map(
                static fn(mixed $lineId): array => ['line_id' => $lineId],
                is_array($payload['line_ids'] ?? null) ? $payload['line_ids'] : []
            );
        }
        $result = [];
        foreach ($rawLines as $rawLine) {
            if (!is_array($rawLine)) {
                continue;
            }
            $lineId = (int) ($rawLine['line_id'] ?? 0);
            $sourceId = trim((string) ($rawLine['source_id'] ?? ''));
            if ($sourceId === '') {
                $sourceId = $this->primarySourceId();
            }
            if ($lineId <= 0 || $lineId > 45535 || $sourceId === '') {
                continue;
            }
            $key = $sourceId . ':' . $lineId;
            if (isset($result[$key])) {
                continue;
            }
            $result[$key] = [
                'source_id' => $sourceId,
                'line_id' => $lineId,
                'ingress_zone' => $this->normalizeZone((string) ($rawLine['ingress_zone'] ?? 'unknown')),
                'destination_zone' => $this->normalizeZone((string) ($rawLine['destination_zone'] ?? $payload['destination_zone'] ?? 'unknown')),
            ];
            if (count($result) >= 8) {
                break;
            }
        }
        return array_values($result);
    }

    /**
     * Order candidates line-first-relay-first: every line's primary relay, then
     * every line's second relay, and so on, keeping the line order (preferred
     * line first) and each line's own relay order.
     *
     * A lease batch is the first max_candidates (at most 4) entries of this
     * list. Listed line by line, one line with three relays (speed_ip,
     * test_speed_ip and a fail-over IP of the same LeiGod line, sharing one
     * credential and one candidate_key_hash) took three of the four slots, so
     * the runtime's four-way failover covered two lines and most retries went
     * back to the line that had just failed. Offset 0 is unchanged.
     *
     * @param array<int, array<int, array<string, mixed>>> $lineCandidates
     * @return array<int, array<string, mixed>>
     */
    private static function interleaveLineCandidates(array $lineCandidates): array
    {
        $result = [];
        for ($rank = 0; ; $rank++) {
            $added = false;
            foreach ($lineCandidates as $relayCandidates) {
                if (isset($relayCandidates[$rank])) {
                    $result[] = $relayCandidates[$rank];
                    $added = true;
                }
            }
            if (!$added) {
                return $result;
            }
        }
    }

    /**
     * Opaque, stable reference to one relay of one line for one client device
     * and forward. Unlike entryReference() it does not change with the session
     * ticket or the line's material, so a runtime can recognise its selected
     * path across grant renewals and credential refreshes.
     *
     * @param array<int, string> $scope
     */
    private function selectionReference(array $scope): string
    {
        $key = $this->keyMaterial();
        if ($key === null) {
            return '';
        }
        $derived = hash_hmac('sha256', "ultra-selection-ref-v2\n", $key, true);
        return $this->base64UrlEncode(hash_hmac('sha256', implode("\n", $scope), $derived, true));
    }

    /** @param array<int, string> $scope */
    private function entryReference(string $token, array $scope): string
    {
        $key = $this->keyMaterial();
        if ($token === '' || $key === null) {
            return '';
        }
        $derived = hash_hmac('sha256', "ultra-entry-ref-v1\n" . $token, $key, true);
        return $this->base64UrlEncode(hash_hmac('sha256', implode("\n", $scope), $derived, true));
    }

    private function normalizeZone(string $value): string
    {
        $zone = strtolower(trim($value));
        return preg_match('/^[a-z0-9-]{1,32}$/', $zone) === 1 ? $zone : 'unknown';
    }

    private function lineAllowsNativeUdp(string $sourceId, int $lineId): bool
    {
        if ($this->db === null) {
            return true;
        }
        $row = $this->db->fetchOne(
            'SELECT access_class, native_udp_state, is_source_present, is_enabled
             FROM ultra_lines
             WHERE source_id = :source_id AND line_id = :line_id
             LIMIT 1',
            ['source_id' => trim($sourceId), 'line_id' => $lineId]
        );
        return is_array($row)
            && (int) ($row['is_source_present'] ?? 0) === 1
            && (int) ($row['is_enabled'] ?? 0) === 1
            && (string) ($row['access_class'] ?? '') === 'native_udp'
            && (string) ($row['native_udp_state'] ?? '') === 'verified';
    }

    private function lineAllowsNativeSocks5(string $sourceId, int $lineId): bool
    {
        if ($this->db === null) {
            return true;
        }
        $row = $this->db->fetchOne(
            'SELECT access_class, native_udp_state, native_socks5_state,
                    is_source_present, is_enabled
             FROM ultra_lines
             WHERE source_id = :source_id AND line_id = :line_id
             LIMIT 1',
            ['source_id' => trim($sourceId), 'line_id' => $lineId]
        );
        return is_array($row)
            && (int) ($row['is_source_present'] ?? 0) === 1
            && (int) ($row['is_enabled'] ?? 0) === 1
            && UltraMode::isNativeCandidateApproved($row);
    }

    private function selectionTier(string $sourceId, int $lineId): string
    {
        if ($this->db === null) {
            return 'unknown';
        }
        $row = $this->db->fetchOne(
            'SELECT pricing_class FROM ultra_lines
             WHERE source_id = :source_id AND line_id = :line_id LIMIT 1',
            ['source_id' => trim($sourceId), 'line_id' => $lineId]
        );
        return match ((string) ($row['pricing_class'] ?? '')) {
            'paid' => 'paid',
            'mixed' => 'mixed',
            'free' => 'free',
            default => 'unknown',
        };
    }

    /** @param array<int, mixed> $values @return array<int, string> */
    private function normalizeZones(array $values): array
    {
        $zones = [];
        foreach (array_slice($values, 0, 16) as $value) {
            $zone = $this->normalizeZone((string) $value);
            if ($zone !== 'unknown' && !in_array($zone, $zones, true)) {
                $zones[] = $zone;
            }
        }
        return $zones;
    }

    private function normalizeFallbackPolicy(string $value): string
    {
        $policy = strtolower(trim($value));
        return in_array($policy, ['detected_only', 'detected_then_any', 'any'], true)
            ? $policy
            : 'detected_then_any';
    }

    /**
     * @param array<int, mixed> $lineRefs
     * @return array<int, array{source_id:string,line_id:int}>
     */
    private function normalizeLineRefs(array $lineRefs): array
    {
        $result = [];
        $primary = $this->primarySourceId();
        foreach (array_slice($lineRefs, 0, 32) as $rawRef) {
            if (is_array($rawRef)) {
                $lineId = (int) ($rawRef['line_id'] ?? 0);
                $sourceId = trim((string) ($rawRef['source_id'] ?? '')) ?: $primary;
            } else {
                $lineId = (int) $rawRef;
                $sourceId = $primary;
            }
            if ($sourceId === '' || preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $sourceId) !== 1
                || $lineId <= 0 || $lineId > 45535) {
                continue;
            }
            $key = $sourceId . ':' . $lineId;
            if (!isset($result[$key])) {
                $result[$key] = ['source_id' => $sourceId, 'line_id' => $lineId];
            }
            if (count($result) >= 8) {
                break;
            }
        }
        return array_values($result);
    }

    /** @param array<int, mixed> $lineIds @return array<int, int> */
    private function normalizeLineIds(array $lineIds): array
    {
        $result = [];
        foreach ($lineIds as $rawLineId) {
            $lineId = (int) $rawLineId;
            if ($lineId > 0 && $lineId <= 45535) {
                $result[$lineId] = $lineId;
            }
        }
        ksort($result, SORT_NUMERIC);
        return array_slice(array_values($result), 0, 16);
    }

    private function materialKey(string $sourceId, int $lineId): string
    {
        return self::MATERIAL_PREFIX . hash('sha256', trim($sourceId)) . '.line.' . $lineId;
    }

    private function primarySourceId(): string
    {
        $legacy = trim((string) ($this->config->get(UltraMode::SOURCE_ID_KEY, '') ?? ''));
        if ($legacy !== '') {
            return $legacy;
        }
        $credentials = $this->sourceCredentials();
        $first = array_key_first($credentials);
        return is_string($first) ? $first : '';
    }

    /** @return array<string, string> */
    private function sourceCredentials(): array
    {
        $credentials = [];
        $encoded = trim((string) ($this->config->get(UltraMode::SOURCE_CREDENTIALS_KEY, '') ?? ''));
        if ($encoded !== '') {
            try {
                $decoded = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    foreach ($decoded as $rawId => $rawSecret) {
                        $sourceId = trim((string) $rawId);
                        $secret = trim((string) $rawSecret);
                        if (preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $sourceId) === 1 && $secret !== '') {
                            $credentials[$sourceId] = $secret;
                        }
                    }
                }
            } catch (Throwable) {
                // Invalid optional map falls back to the legacy pair below.
            }
        }
        $legacyId = trim((string) ($this->config->get(UltraMode::SOURCE_ID_KEY, '') ?? ''));
        $legacySecret = trim((string) ($this->config->get(UltraMode::SOURCE_AUTH_KEY, '') ?? ''));
        if ($legacyId !== '' && $legacySecret !== '' && !isset($credentials[$legacyId])) {
            $credentials[$legacyId] = $legacySecret;
        }
        return $credentials;
    }

    private function consumeReplayValue(string $key, string $value, int $ttlSeconds): bool
    {
        $now = $this->now();
        $decoded = json_decode((string) $this->settingGet($key, '{}'), true);
        $values = is_array($decoded) ? $decoded : [];
        foreach ($values as $stored => $expiresAt) {
            if ((int) $expiresAt <= $now) {
                unset($values[$stored]);
            }
        }
        $hash = hash('sha256', $value);
        if (isset($values[$hash])) {
            return false;
        }
        $values[$hash] = $now + max(60, $ttlSeconds);
        if (count($values) > 256) {
            asort($values, SORT_NUMERIC);
            $values = array_slice($values, -256, null, true);
        }
        $this->settingSet($key, json_encode($values, JSON_UNESCAPED_SLASHES) ?: '{}');
        return true;
    }

    private function grantCacheKey(int $clientId, string $deviceId, string $validityClass = ''): string
    {
        $key = self::GRANT_CACHE_PREFIX . $clientId . '.device.' . hash('sha256', $deviceId !== '' ? $deviceId : 'none');
        $validityClass = strtolower(trim($validityClass));
        if ($validityClass !== '' && preg_match('/^[a-z0-9]{1,16}$/', $validityClass) === 1) {
            $key .= '.' . $validityClass;
        }
        return $key;
    }

    private function candidateDynamicQuarantineEnabled(): bool
    {
        $configured = $this->settings?->get('ULTRA_CANDIDATE_QUARANTINE_ENABLED', null);
        if ($configured === null || trim($configured) === '') {
            $configured = $this->config->get('ULTRA_CANDIDATE_QUARANTINE_ENABLED', '0');
        }
        $value = strtolower(trim((string) ($configured ?? '0')));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function settingGet(string $key, string $default): string
    {
        if ($this->settings !== null) {
            return (string) ($this->settings->get($key, $default) ?? $default);
        }
        return $this->memorySettings[$key] ?? $default;
    }

    private function settingSet(string $key, string $value): void
    {
        if ($this->settings !== null) {
            $this->settings->set($key, $value);
            return;
        }
        $this->memorySettings[$key] = $value;
    }

    /** @param array<string, mixed> $payload */
    private function seal(array $payload): ?string
    {
        $key = $this->keyMaterial();
        if ($key === null) {
            return null;
        }
        $plain = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 'u2s.' . $this->base64UrlEncode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
        }
        if (function_exists('openssl_encrypt')) {
            $nonce = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            if (is_string($cipher) && strlen($tag) === 16) {
                return 'u2o.' . $this->base64UrlEncode($nonce . $tag . $cipher);
            }
        }
        if ($this->settings === null) {
            return 'mem1.' . $this->base64UrlEncode($plain);
        }
        return $this->sealWithOpenSslCli($plain, $key, 'u2c');
    }

    /** @return array<string, mixed> */
    private function open(string $encoded): array
    {
        $key = $this->keyMaterial();
        if ($key === null || $encoded === '') {
            return [];
        }
        [$scheme, $value] = array_pad(explode('.', $encoded, 2), 2, '');
        $raw = $this->base64UrlDecode($value);
        if ($raw === null) {
            return [];
        }
        $plain = false;
        if ($scheme === 'mem1' && $this->settings === null) {
            $plain = $raw;
        } elseif ($scheme === 'u2s' && function_exists('sodium_crypto_secretbox_open')) {
            $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (strlen($raw) > $nonceLength) {
                $plain = sodium_crypto_secretbox_open(substr($raw, $nonceLength), substr($raw, 0, $nonceLength), $key);
            }
        } elseif ($scheme === 'u2o' && function_exists('openssl_decrypt') && strlen($raw) > 28) {
            $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        } elseif ($scheme === 'u2c') {
            $plain = $this->openWithOpenSslCli($raw, $key);
        }
        if (!is_string($plain) || $plain === '') {
            return [];
        }
        try {
            $decoded = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    private function sealWithOpenSslCli(string $plain, string $key, string $scheme): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $iv = random_bytes(16);
        $command = 'openssl enc -aes-256-ctr -K ' . escapeshellarg(bin2hex($key))
            . ' -iv ' . escapeshellarg(bin2hex($iv));
        $pipes = [];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        fwrite($pipes[0], $plain);
        fclose($pipes[0]);
        $cipher = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($cipher)) {
            return null;
        }
        $mac = hash_hmac('sha256', $iv . $cipher, $key, true);
        return $scheme . '.' . $this->base64UrlEncode($iv . $mac . $cipher);
    }

    private function openWithOpenSslCli(string $raw, string $key): string|false
    {
        if (!function_exists('proc_open') || strlen($raw) <= 48) {
            return false;
        }
        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $cipher = substr($raw, 48);
        if (!hash_equals(hash_hmac('sha256', $iv . $cipher, $key, true), $mac)) {
            return false;
        }
        $command = 'openssl enc -d -aes-256-ctr -K ' . escapeshellarg(bin2hex($key))
            . ' -iv ' . escapeshellarg(bin2hex($iv));
        $pipes = [];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return false;
        }
        fwrite($pipes[0], $cipher);
        fclose($pipes[0]);
        $plain = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        return proc_close($process) === 0 && is_string($plain) ? $plain : false;
    }

    private function keyMaterial(): ?string
    {
        $raw = trim((string) ($this->config->get(UltraMode::TOKEN_KEY, '') ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($this->config->get(UltraMode::CACHE_KEY, '') ?? ''));
        }
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^[a-f0-9]{64}$/i', $raw) === 1) {
            $decoded = hex2bin($raw);
            if (is_string($decoded) && strlen($decoded) === 32) {
                return $decoded;
            }
        }
        $decoded = base64_decode($raw, true);
        if (is_string($decoded) && strlen($decoded) === 32) {
            return $decoded;
        }
        return hash('sha256', $raw, true);
    }

    /** @param array<string, mixed> $payload */
    private function sealForClient(string $token, array $payload): ?string
    {
        if ($token === '') {
            return null;
        }
        $plain = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!function_exists('openssl_encrypt')) {
            return $this->settings === null ? 'umt.' . $this->base64UrlEncode($plain) : null;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $token, true), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($cipher) || strlen($tag) !== 16) {
            return null;
        }
        return 'um1.' . $this->base64UrlEncode($nonce . $tag . $cipher);
    }

    /** @return array<string, mixed> */
    private function openForClient(string $token, string $encoded): array
    {
        if ($token === '') {
            return [];
        }
        if (str_starts_with($encoded, 'umt.') && $this->settings === null) {
            $plain = $this->base64UrlDecode(substr($encoded, 4));
            if (!is_string($plain)) {
                return [];
            }
            try {
                $decoded = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return [];
            }
            return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
        }
        if (!function_exists('openssl_decrypt') || !str_starts_with($encoded, 'um1.')) {
            return [];
        }
        $raw = $this->base64UrlDecode(substr($encoded, 4));
        if ($raw === null || strlen($raw) <= 28) {
            return [];
        }
        $plain = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            hash('sha256', $token, true),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );
        if (!is_string($plain) || $plain === '') {
            return [];
        }
        try {
            $decoded = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function signTicket(array $payload): string
    {
        $key = $this->keyMaterial();
        if ($key === null) {
            return '';
        }
        $body = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $body, $key, true));
        return 'ut1.' . $body . '.' . $signature;
    }

    /** @return array<string, mixed> */
    private function openTicket(string $ticket): array
    {
        $key = $this->keyMaterial();
        $parts = explode('.', $ticket);
        if ($key === null || count($parts) !== 3 || $parts[0] !== 'ut1') {
            return [];
        }
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $parts[1], $key, true));
        if (!hash_equals($expected, $parts[2])) {
            return [];
        }
        $raw = $this->base64UrlDecode($parts[1]);
        if ($raw === null) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    /**
     * How far ahead of material expiry a line is handed back to the source
     * agent for re-acquisition. Default 600 (the historical value). It must
     * stay below the source agent's material_lifetime_seconds minus its poll
     * interval, or every poll re-acquires every line; so it is capped at the
     * panel's material TTL ceiling minus 300 s.
     */
    public function materialRefreshWindowSeconds(): int
    {
        $raw = trim((string) $this->config->get(UltraMode::MATERIAL_REFRESH_WINDOW_KEY, ''));
        if ($raw === '' || preg_match('/^[0-9]+$/', $raw) !== 1) {
            return UltraMode::DEFAULT_MATERIAL_REFRESH_WINDOW_SECONDS;
        }
        return max(60, min($this->materialMaxTtlSeconds() - 300, (int) $raw));
    }

    private function materialMaxTtlSeconds(): int
    {
        return max(300, min(7200, $this->config->getInt(UltraMode::MATERIAL_MAX_TTL_KEY, 2100)));
    }

    private function parseTimestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
            return null;
        }
        try {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, new DateTimeZone('UTC'));
            return $date instanceof DateTimeImmutable ? $date->getTimestamp() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function formatTimestamp(int $timestamp): string
    {
        return gmdate('Y-m-d\\TH:i:s\\Z', $timestamp);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
