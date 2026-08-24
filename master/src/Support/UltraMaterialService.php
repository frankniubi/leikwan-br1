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
    private const MATERIAL_PREFIX = 'ultra.material.v1.line.';
    private const GRANT_CACHE_PREFIX = 'ultra.access.v3.client.';
    private const CLIENT_SCOPE_PREFIX = 'ultra.client.scope.v2.';
    private const SOURCE_NONCE_PREFIX = 'ultra.source.nonces.v1.';
    private const TICKET_NONCE_PREFIX = 'ultra.source.tickets.v1.';

    /** @var callable(): int */
    private $clock;

    /** @var array<string, string> */
    private array $memorySettings = [];

    public function __construct(
        private Config $config,
        private ?Database $db = null,
        private ?AppSettings $settings = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @return array<int, int> */
    public function requiredLineIds(): array
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
             INNER JOIN forwards f ON f.forward_combination_id = fc.id
             INNER JOIN services s ON s.id = f.service_id
             WHERE fc.backend_mode = :combination_mode
               AND fc.is_active = 1
               AND f.backend_mode = :forward_mode
               AND f.enabled = 1
               AND s.status = :service_status
             ORDER BY ul.line_id ASC',
            [
                'combination_mode' => UltraMode::BACKEND_MODE,
                'forward_mode' => UltraMode::BACKEND_MODE,
                'service_status' => 'active',
            ]
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

    /** @return array<int, int> */
    public function dueLineIds(int $refreshWindowSeconds = 600): array
    {
        $now = $this->now();
        $due = [];
        foreach ($this->requiredLineIds() as $lineId) {
            $material = $this->loadMaterial($lineId, false);
            $expiresAt = $this->parseTimestamp((string) ($material['expires_at'] ?? '')) ?? 0;
            if ($expiresAt <= $now + max(60, $refreshWindowSeconds)) {
                $due[] = $lineId;
            }
        }
        return array_slice($due, 0, 8);
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
        if ($ticketNonce === '' || !$this->consumeReplayValue(self::TICKET_NONCE_PREFIX . hash('sha256', $sourceId), $ticketNonce, 300)) {
            throw new ApiException('ultra_source_ticket_replayed', 409);
        }
        $allowed = array_fill_keys($this->normalizeLineIds((array) ($ticketPayload['line_ids'] ?? [])), true);
        $accepted = [];
        foreach (array_slice($materials, 0, 8) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $material = $this->normalizeMaterial($raw, $now);
            if ($material === null || !isset($allowed[$material['line_id']])) {
                continue;
            }
            $existing = $this->loadMaterial($material['line_id'], false);
            $existingAcquired = $this->parseTimestamp((string) ($existing['acquired_at'] ?? '')) ?? 0;
            $newAcquired = $this->parseTimestamp((string) $material['acquired_at']) ?? 0;
            if ($existingAcquired > $newAcquired) {
                continue;
            }
            $encoded = $this->seal(['kind' => 'line-material', 'material' => $material]);
            if ($encoded === null) {
                throw new RuntimeException('ultra_material_encryption_unavailable');
            }
            $this->settingSet(self::MATERIAL_PREFIX . $material['line_id'], $encoded);
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
        ksort($accepted, SORT_NUMERIC);
        return array_values($accepted);
    }

    /**
     * @param array<int, int> $lineIds
     * @return array{line_ips:array<int,string>,fingerprint:string,expires_at:int}|null
     */
    public function lineSnapshot(array $lineIds): ?array
    {
        $normalized = $this->normalizeLineIds($lineIds);
        $ips = [];
        $generations = [];
        $expiresAt = PHP_INT_MAX;
        foreach ($normalized as $lineId) {
            $material = $this->loadMaterial($lineId, true);
            if ($material === []) {
                continue;
            }
            $generations[] = $lineId . ':' . (string) ($material['generation'] ?? '');
            $materialExpiry = $this->parseTimestamp((string) ($material['expires_at'] ?? '')) ?? 0;
            $expiresAt = min($expiresAt, $materialExpiry);
            foreach ((array) ($material['relays'] ?? []) as $relay) {
                if (!is_array($relay)) {
                    continue;
                }
                $ip = trim((string) ($relay['ip'] ?? ''));
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $ips[$ip] = true;
                }
            }
        }
        if ($ips === [] || $generations === [] || $expiresAt <= $this->now()) {
            return null;
        }
        return [
            'line_ips' => array_slice(array_keys($ips), 0, 8),
            'fingerprint' => hash('sha256', implode('|', $generations)),
            'expires_at' => $expiresAt,
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
            $lineId = (int) $scopeLine['line_id'];
            $material = $this->loadMaterial($lineId, true);
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
                    (string) $lineId,
                    (string) ($material['generation'] ?? ''),
                    $ip,
                    (string) ($payload['transport'] ?? 'tcp+udp'),
                ]);
                if ($entryRef === '') {
                    continue;
                }
                $entries[$lineId . ':' . $ip . ':' . $port . ':' . $user] = [
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

    /** @return array<string, array<string, mixed>> */
    public function loadGrantCache(int $clientId, string $deviceId): array
    {
        $decoded = $this->open((string) $this->settingGet($this->grantCacheKey($clientId, $deviceId), ''));
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
    public function saveGrantCache(int $clientId, string $deviceId, array $grants): void
    {
        $encoded = $this->seal(['kind' => 'grant-cache', 'grants' => $grants]);
        if ($encoded !== null) {
            $this->settingSet($this->grantCacheKey($clientId, $deviceId), $encoded);
        }
    }

    public function verifySourceRequest(Request $request): string
    {
        $sourceId = trim((string) ($request->header('x-ultra-source-id') ?? ''));
        $timestampText = trim((string) ($request->header('x-ultra-timestamp') ?? ''));
        $nonce = trim((string) ($request->header('x-ultra-nonce') ?? ''));
        $signature = strtolower(trim((string) ($request->header('x-ultra-signature') ?? '')));
        $expectedId = trim((string) ($this->config->get(UltraMode::SOURCE_ID_KEY, '') ?? ''));
        $secret = trim((string) ($this->config->get(UltraMode::SOURCE_AUTH_KEY, '') ?? ''));
        $timestamp = ctype_digit($timestampText) ? (int) $timestampText : 0;
        if ($sourceId === '' || $expectedId === '' || $secret === '' || !hash_equals($expectedId, $sourceId)
            || abs($this->now() - $timestamp) > 300
            || preg_match('/^[a-f0-9]{16,128}$/i', $nonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            throw new ApiException('ultra_source_auth_failed', 401);
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
    private function loadMaterial(int $lineId, bool $requireFresh): array
    {
        $decoded = $this->open((string) $this->settingGet(self::MATERIAL_PREFIX . $lineId, ''));
        $material = is_array($decoded['material'] ?? null) ? $decoded['material'] : [];
        if (($decoded['kind'] ?? '') !== 'line-material' || $material === []) {
            return [];
        }
        if ($requireFresh && (($this->parseTimestamp((string) ($material['expires_at'] ?? '')) ?? 0) <= $this->now())) {
            return [];
        }
        return $material;
    }

    /** @param array<string, mixed> $payload @return array<int, array{line_id:int,ingress_zone:string,destination_zone:string}> */
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
            if ($lineId <= 0 || $lineId > 45535 || isset($result[$lineId])) {
                continue;
            }
            $result[$lineId] = [
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
        return array_slice(array_values($result), 0, 8);
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

    private function grantCacheKey(int $clientId, string $deviceId): string
    {
        return self::GRANT_CACHE_PREFIX . $clientId . '.device.' . hash('sha256', $deviceId !== '' ? $deviceId : 'none');
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
