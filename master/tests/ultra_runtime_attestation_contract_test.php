<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, mixed> */
        public static array $lastUpdate = [];

        /** @var array<int, array{table:string,data:array<string,mixed>}> */
        public static array $inserts = [];

        /** @var array<string, mixed> */
        public static array $session = [];

        /** @var array<int, array<string, mixed>> */
        public static array $runtimeSessions = [];

        /** @var array<int, array<string, mixed>> */
        public static array $countRows = [];

        /** @var array<int, array<string, mixed>> */
        public static array $refusalRows = [];

        public static int $liveLeases = 0;
        public static int $recentIssues = 0;
        public static bool $throwOnLimitLookup = false;

        /** @var array<string, mixed>|null row returned for the attestation lookup */
        public static ?array $attestationSession = null;

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'FROM client_device_sessions cds')) {
                if (str_contains($sql, 'cds.ultra_runtime_fingerprint IS NOT NULL')) {
                    return self::$runtimeSessions;
                }
                return self::$session === [] ? [] : [self::$session];
            }
            if (str_contains($sql, 'SELECT last_seen_at, ultra_runtime_fingerprint')) {
                return self::$countRows;
            }
            if (str_contains($sql, 'SELECT id, group_id FROM clients')) {
                return [['id' => 7, 'group_id' => 2]];
            }
            if (str_contains($sql, 'FROM forwards f') && str_contains($sql, 'ultra_destination_zone')) {
                return [[
                    'id' => 42,
                    'line_client_id' => 7,
                    'line_client_group_id' => null,
                    'line_client_group_ids_json' => null,
                    'target_host' => '8.8.8.8',
                    'target_port' => 53,
                    'name' => 'ultra-dns',
                    'tenant_label' => 'Tokyo DNS',
                    'ultra_destination_zone' => 'tokyo',
                ]];
            }
            if (str_contains($sql, 'SELECT DISTINCT device_hash FROM ultra_client_leases')) {
                return [['device_hash' => hash('sha256', 'device-a')]];
            }
            if (str_contains($sql, 'FROM ultra_lease_refusals')) {
                return self::$refusalRows;
            }
            return [];
        }

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'consumed_at IS NOT NULL AND revoked_at IS NULL')) {
                if (self::$throwOnLimitLookup) {
                    throw new \RuntimeException('limit lookup unavailable');
                }
                return ['total' => self::$liveLeases];
            }
            if (str_contains($sql, 'issued_at >= :since')) {
                return ['total' => self::$recentIssues];
            }
            if (str_contains($sql, 'ultra_runtime_fingerprint, ultra_runtime_self_sha256')) {
                return self::$attestationSession;
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            if (str_contains($sql, 'UPDATE client_device_sessions')) {
                self::$lastUpdate = $params;
            }
            return 1;
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int
        {
            self::$inserts[] = ['table' => $table, 'data' => $data];
            return 1;
        }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\Master\Database\Database') {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\UltraClientStatusService;
    use Leikwan\Master\Support\UltraLeaseService;

    function failUltraRuntimeAttestation(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertUltraRuntimeAttestation(bool $condition, string $message): void
    {
        if (!$condition) {
            failUltraRuntimeAttestation($message);
        }
    }

    const ULTRA_ATTESTATION_NOW = 1_893_456_000;
    const ULTRA_ATTESTATION_GENERATION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    foreach ([
        'ULTRA_RUNTIME_FINGERPRINTS',
        UltraLeaseService::MAX_DEVICES_PER_CLIENT_KEY,
        UltraLeaseService::MAX_LIVE_LEASES_PER_DEVICE_KEY,
        UltraLeaseService::ISSUE_RATE_PER_MINUTE_KEY,
    ] as $envKey) {
        putenv($envKey);
    }

    // Keep the (expected) refusal logging out of the test's stderr, and make
    // fatal errors visible even though the error log is redirected.
    $logPath = tempnam(sys_get_temp_dir(), 'lw-ultra-attest-log-');
    if ($logPath === false) {
        failUltraRuntimeAttestation('failed to create log file');
    }
    ini_set('error_log', $logPath);
    ini_set('display_errors', '1');

    /** @param array<string, string|int> $values */
    function ultraAttestationEnv(array $values): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lw-ultra-attest-');
        if ($path === false) {
            failUltraRuntimeAttestation('failed to create env file');
        }
        $body = '';
        foreach ($values as $key => $value) {
            $body .= $key . '=' . $value . "\n";
        }
        file_put_contents($path, $body);
        return $path;
    }

    /** @param array<string, mixed> $overrides */
    function ultraAttestationStatus(array $overrides = []): array
    {
        return $overrides + [
            'forward_id' => 42,
            'state' => 'ready',
            'detected_ingress_zone' => 'shanghai',
            'updated_at' => '2030-01-01T00:00:02Z',
            'error_code' => '',
        ];
    }

    /** @return array<string, mixed> */
    function ultraAttestationSessionRow(?string $fingerprint, ?string $selfSha256, ?int $debugger, ?int $instrumented): array
    {
        return [
            'client_id' => 7,
            'device_id' => 'device-a',
            'device_name' => 'canary',
            'platform' => 'linux',
            'last_ip' => '198.51.100.7',
            'last_seen_at' => gmdate('Y-m-d H:i:s', ULTRA_ATTESTATION_NOW - 30),
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s', ULTRA_ATTESTATION_NOW - 30),
            'ultra_status_json' => json_encode([['forward_id' => 42, 'state' => 'ready']]),
            'ultra_status_updated_at' => gmdate('Y-m-d H:i:s', ULTRA_ATTESTATION_NOW),
            'ultra_runtime_fingerprint' => $fingerprint,
            'ultra_runtime_self_sha256' => $selfSha256,
            'ultra_runtime_debugger' => $debugger,
            'ultra_runtime_instrumented' => $instrumented,
            'ultra_runtime_attested_at' => $fingerprint === null ? null : gmdate('Y-m-d H:i:s', ULTRA_ATTESTATION_NOW),
            'client_name' => 'client-seven',
            'group_id' => 2,
            'user_name' => 'User Seven',
            'user_email' => 'user7@example.test',
        ];
    }

    function ultraAttestationStatusService(Config $config): UltraClientStatusService
    {
        return new UltraClientStatusService($config, new Database(), static fn(): int => ULTRA_ATTESTATION_NOW);
    }

    /** @param array<string, mixed> $overrides */
    function ultraAttestationRequest(array $overrides = []): array
    {
        return $overrides + [
            'forward_id' => 42,
            'session_ticket' => 'ticket-a',
            'policy_generation' => ULTRA_ATTESTATION_GENERATION,
            'nonce' => bin2hex(random_bytes(16)),
            'target_digest' => hash('sha256', '198.51.100.10:443'),
            'transport' => 'tcp',
        ];
    }

    /** @return array<string, mixed> */
    function ultraAttestationPolicy(int $clientId, string $deviceId, int $forwardId): array
    {
        return [
            'forward_id' => $forwardId,
            'device_hash' => hash('sha256', $deviceId),
            'service_state' => 'active',
            'policy_generation' => ULTRA_ATTESTATION_GENERATION,
            'target' => '198.51.100.10:443',
            'transport' => 'tcp',
            'rate_limit_mbps' => 20,
            'quota_remaining_bytes' => 1024 * 1024,
            'expires_at' => gmdate('Y-m-d', 1_900_000_300) . 'T' . gmdate('H:i:s', 1_900_000_300) . 'Z',
        ];
    }

    /** @return array<string, mixed> */
    function ultraAttestationCandidate(array $request, int $clientId, string $deviceId): array
    {
        return [
            'selection_ref' => str_repeat('r', 43),
            'ip' => '203.0.113.20',
            'port' => 5438,
            'user' => 'line-user',
            'credential' => 'line-password',
        ];
    }

    function ultraAttestationLeaseService(Config $config, ?callable $clock = null): UltraLeaseService
    {
        return new UltraLeaseService(
            $config,
            null,
            null,
            'ultraAttestationCandidate',
            $clock ?? static fn(): int => 1_900_000_000,
            'ultraAttestationPolicy',
        );
    }

    // ---------------------------------------------------------------------
    // 1. Attestation absent -> unknown, and the lease path is untouched.
    // ---------------------------------------------------------------------
    $defaultEnv = ultraAttestationEnv(['APP_ENV' => 'test']);
    $defaultConfig = new Config($defaultEnv);
    $statusService = ultraAttestationStatusService($defaultConfig);
    assertUltraRuntimeAttestation(
        $statusService->ingest(7, 'device-a', [ultraAttestationStatus()]) === 1,
        'an attestation-free status report must still be ingested'
    );
    assertUltraRuntimeAttestation(
        !array_key_exists('ultra_runtime_fingerprint', Database::$lastUpdate),
        'an absent attestation must not write the attestation columns'
    );
    Database::$session = ultraAttestationSessionRow(null, null, null, null);
    Database::$countRows = [['last_seen_at' => gmdate('Y-m-d H:i:s', ULTRA_ATTESTATION_NOW - 30), 'ultra_runtime_fingerprint' => null]];
    $rows = $statusService->sessionsForAdmin([], 50, 0);
    assertUltraRuntimeAttestation(($rows[0]['attestation_state'] ?? '') === 'unknown', 'absent attestation must report unknown');
    assertUltraRuntimeAttestation(($rows[0]['runtime_fingerprint'] ?? null) === '', 'absent attestation must not invent a fingerprint');
    assertUltraRuntimeAttestation(($rows[0]['attestation_allowlisted'] ?? true) === false, 'absent attestation can never be allow-listed');
    $counts = $statusService->statusCounts();
    assertUltraRuntimeAttestation(
        ($counts['attestation_unknown'] ?? -1) === 1 && ($counts['attestation_known'] ?? -1) === 0 && ($counts['attestation_mismatch'] ?? -1) === 0,
        'status counts must report an absent attestation as unknown'
    );
    assertUltraRuntimeAttestation($statusService->runtimeAttestationMismatches(50) === [], 'no attested session means no mismatch rows');
    $leaseService = ultraAttestationLeaseService($defaultConfig);
    $lease = $leaseService->issueLease(ultraAttestationRequest(), 7, 'device-a');
    assertUltraRuntimeAttestation(($lease['ok'] ?? false) === true, 'a client without attestation must still receive a lease');
    assertUltraRuntimeAttestation(
        $statusService->attestationState(null) === 'unknown' && $statusService->attestationState('') === 'unknown',
        'missing fingerprint must resolve to unknown'
    );

    // ---------------------------------------------------------------------
    // 2. Attestation present and allow-listed -> known.
    // ---------------------------------------------------------------------
    $knownFingerprint = str_repeat('a', 16);
    $knownSha256 = str_repeat('b', 64);
    $knownEnv = ultraAttestationEnv([
        'APP_ENV' => 'test',
        'ULTRA_RUNTIME_FINGERPRINTS' => strtoupper($knownFingerprint) . ', ' . str_repeat('d', 8),
    ]);
    $knownConfig = new Config($knownEnv);
    $knownService = ultraAttestationStatusService($knownConfig);
    $knownStatus = ultraAttestationStatus(['runtime_attestation' => [
        'fingerprint' => strtoupper($knownFingerprint),
        'self_sha256' => strtoupper($knownSha256),
        'debugger' => false,
        'instrumented' => '0',
    ]]);
    assertUltraRuntimeAttestation($knownService->ingest(7, 'device-a', [$knownStatus]) === 1, 'attested status must be ingested');
    assertUltraRuntimeAttestation(
        (Database::$lastUpdate['ultra_runtime_fingerprint'] ?? null) === $knownFingerprint,
        'an uppercase fingerprint must be normalized to lowercase hex'
    );
    assertUltraRuntimeAttestation(
        (Database::$lastUpdate['ultra_runtime_self_sha256'] ?? null) === $knownSha256,
        'an uppercase executable hash must be normalized to lowercase hex'
    );
    assertUltraRuntimeAttestation(
        (Database::$lastUpdate['ultra_runtime_debugger'] ?? null) === 0
        && (Database::$lastUpdate['ultra_runtime_instrumented'] ?? null) === 0,
        'false booleans must persist as 0, never as NULL'
    );
    assertUltraRuntimeAttestation(
        is_string(Database::$lastUpdate['ultra_runtime_attested_at'] ?? null),
        'the attestation timestamp must be recorded'
    );
    Database::$session = ultraAttestationSessionRow($knownFingerprint, $knownSha256, 0, 0);
    Database::$countRows = [['last_seen_at' => gmdate('Y-m-d H:i:s', ULTRA_ATTESTATION_NOW - 30), 'ultra_runtime_fingerprint' => $knownFingerprint]];
    $rows = $knownService->sessionsForAdmin([], 50, 0);
    assertUltraRuntimeAttestation(($rows[0]['attestation_state'] ?? '') === 'known', 'an allow-listed fingerprint must report known');
    assertUltraRuntimeAttestation(($rows[0]['attestation_allowlisted'] ?? false) === true, 'an allow-listed fingerprint must be flagged');
    assertUltraRuntimeAttestation(($rows[0]['runtime_debugger'] ?? null) === false, 'stored flags must round-trip as booleans');
    assertUltraRuntimeAttestation(($knownService->statusCounts()['attestation_known'] ?? -1) === 1, 'known attestations must be counted');
    assertUltraRuntimeAttestation($knownService->runtimeAttestationMismatches(50) === [], 'allow-listed sessions must not be listed as mismatches');

    // ---------------------------------------------------------------------
    // 3. Attestation present with an unknown fingerprint -> mismatch, but the
    //    lease is still issued because enforcement stays off.
    // ---------------------------------------------------------------------
    $unknownFingerprint = str_repeat('c', 16);
    $unknownStatus = ultraAttestationStatus(['runtime_attestation' => [
        'fingerprint' => $unknownFingerprint,
        'self_sha256' => str_repeat('e', 64),
        'debugger' => true,
        'instrumented' => false,
    ]]);
    assertUltraRuntimeAttestation($knownService->ingest(7, 'device-a', [$unknownStatus]) === 1, 'an unknown fingerprint must still be ingested');
    assertUltraRuntimeAttestation(
        (Database::$lastUpdate['ultra_runtime_fingerprint'] ?? null) === $unknownFingerprint,
        'an unknown but well-formed fingerprint must be persisted for reporting'
    );
    Database::$session = ultraAttestationSessionRow($unknownFingerprint, str_repeat('e', 64), 1, 0);
    $rows = $knownService->sessionsForAdmin([], 50, 0);
    assertUltraRuntimeAttestation(($rows[0]['attestation_state'] ?? '') === 'mismatch', 'a non-allow-listed fingerprint must report mismatch');
    assertUltraRuntimeAttestation(($rows[0]['attestation_allowlisted'] ?? true) === false, 'a non-allow-listed fingerprint must not be flagged as known');
    Database::$countRows = [['last_seen_at' => gmdate('Y-m-d H:i:s', ULTRA_ATTESTATION_NOW - 30), 'ultra_runtime_fingerprint' => $unknownFingerprint]];
    assertUltraRuntimeAttestation(($knownService->statusCounts()['attestation_mismatch'] ?? -1) === 1, 'mismatches must be counted');
    Database::$runtimeSessions = [[
        'client_id' => 7,
        'client_name' => 'client-seven',
        'user_name' => 'User Seven',
        'user_email' => 'user7@example.test',
        'device_id' => 'device-a',
        'device_name' => 'canary',
        'platform' => 'linux',
        'last_ip' => '198.51.100.7',
        'last_seen_at' => gmdate('Y-m-d H:i:s', ULTRA_ATTESTATION_NOW - 30),
        'ultra_runtime_fingerprint' => $unknownFingerprint,
        'ultra_runtime_self_sha256' => str_repeat('e', 64),
        'ultra_runtime_debugger' => 1,
        'ultra_runtime_instrumented' => 0,
        'ultra_runtime_attested_at' => gmdate('Y-m-d H:i:s', ULTRA_ATTESTATION_NOW),
    ]];
    $mismatches = $knownService->runtimeAttestationMismatches(50);
    assertUltraRuntimeAttestation(count($mismatches) === 1, 'the admin mismatch view must list a non-allow-listed session');
    assertUltraRuntimeAttestation(($mismatches[0]['reason'] ?? '') === 'fingerprint_not_allowlisted', 'a configured allow-list must explain the mismatch');
    assertUltraRuntimeAttestation(($mismatches[0]['attestation_state'] ?? '') === 'mismatch', 'mismatch rows must carry their state');
    assertUltraRuntimeAttestation(($mismatches[0]['runtime_instrumented'] ?? null) === false, 'mismatch rows must carry bounded flags');
    $mismatchLease = ultraAttestationLeaseService($knownConfig)->issueLease(ultraAttestationRequest(), 7, 'device-a');
    assertUltraRuntimeAttestation(($mismatchLease['ok'] ?? false) === true, 'a mismatch must not deny a lease while enforcement is off');
    $leaseSource = (string) file_get_contents(__DIR__ . '/../src/Support/UltraLeaseService.php');
    assertUltraRuntimeAttestation(
        str_contains($leaseSource, "get(self::ATTESTATION_ENFORCEMENT_KEY, 'report')"),
        'attestation enforcement must exist and must default to report-only'
    );
    assertUltraRuntimeAttestation(
        str_contains($leaseSource, 'releasedRuntimeHashes'),
        'enforcement must compare against the published release hashes, not a hardcoded copy'
    );

    // Empty allow-list (the default): report everything as unknown, deny nothing.
    Database::$runtimeSessions[0]['ultra_runtime_fingerprint'] = $unknownFingerprint;
    $unconfigured = ultraAttestationStatusService($defaultConfig);
    assertUltraRuntimeAttestation(
        $unconfigured->attestationState($unknownFingerprint) === 'unknown',
        'an empty allow-list must report every fingerprint as unknown'
    );
    $unconfiguredRows = $unconfigured->runtimeAttestationMismatches(50);
    assertUltraRuntimeAttestation(count($unconfiguredRows) === 1, 'an empty allow-list still lists attested sessions for review');
    assertUltraRuntimeAttestation(
        ($unconfiguredRows[0]['reason'] ?? '') === 'no_allowlist_configured',
        'an empty allow-list must be reported as unconfigured, not as a mismatch'
    );

    // ---------------------------------------------------------------------
    // 4. Abuse limits: each cap refuses only when configured, and never at 0.
    // ---------------------------------------------------------------------
    $noCaps = ultraAttestationLeaseService($defaultConfig);
    // Five leases on one device inside the same minute prove that a live-lease
    // cap of 0 and a rate cap of 0 stay unlimited.
    for ($i = 0; $i < 5; $i++) {
        $result = $noCaps->issueLease(ultraAttestationRequest(), 7, 'device-0');
        assertUltraRuntimeAttestation(($result['ok'] ?? false) === true, 'without caps every lease must issue');
    }
    // Five distinct devices prove that a device cap of 0 stays unlimited.
    for ($i = 0; $i < 5; $i++) {
        $result = $noCaps->issueLease(ultraAttestationRequest(), 7, 'device-' . $i);
        assertUltraRuntimeAttestation(($result['ok'] ?? false) === true, 'without caps every device must issue');
    }
    $abuseCodes = [
        UltraLeaseService::ERROR_RATE_LIMITED,
        UltraLeaseService::ERROR_DEVICE_LIVE_LIMIT,
        UltraLeaseService::ERROR_CLIENT_DEVICE_LIMIT,
    ];
    assertUltraRuntimeAttestation(count(array_unique($abuseCodes)) === 3, 'each abuse limit needs its own error code');
    foreach ([
        'lease_scope_invalid',
        'lease_replayed',
        'policy_generation_invalid',
        'policy_scope_invalid',
        'policy_expired',
        'quota_exhausted',
        'no_approved_candidate',
        'forward_inactive',
        'native_route_policy_not_allowed',
    ] as $legacyCode) {
        assertUltraRuntimeAttestation(!in_array($legacyCode, $abuseCodes, true), 'an abuse limit must not reuse ' . $legacyCode);
    }

    $liveCappedEnv = ultraAttestationEnv(['APP_ENV' => 'test', UltraLeaseService::MAX_LIVE_LEASES_PER_DEVICE_KEY => '2']);
    $liveCapped = ultraAttestationLeaseService(new Config($liveCappedEnv));
    assertUltraRuntimeAttestation(($liveCapped->issueLease(ultraAttestationRequest(), 7, 'device-a')['ok'] ?? false) === true, 'first lease under the live cap must issue');
    assertUltraRuntimeAttestation(($liveCapped->issueLease(ultraAttestationRequest(), 7, 'device-a')['ok'] ?? false) === true, 'second lease under the live cap must issue');
    $liveRefusal = $liveCapped->issueLease(ultraAttestationRequest(), 7, 'device-a');
    assertUltraRuntimeAttestation(
        ($liveRefusal['error'] ?? '') === UltraLeaseService::ERROR_DEVICE_LIVE_LIMIT,
        'the live lease cap must refuse with its own error code'
    );
    assertUltraRuntimeAttestation(
        ($liveCapped->issueLease(ultraAttestationRequest(), 7, 'device-b')['ok'] ?? false) === true,
        'the live lease cap must be scoped per device'
    );

    $rateCappedEnv = ultraAttestationEnv(['APP_ENV' => 'test', UltraLeaseService::ISSUE_RATE_PER_MINUTE_KEY => '2']);
    $rateNow = 1_900_000_000;
    $rateClock = static function () use (&$rateNow): int {
        return $rateNow;
    };
    $rateCapped = ultraAttestationLeaseService(new Config($rateCappedEnv), $rateClock);
    assertUltraRuntimeAttestation(($rateCapped->issueLease(ultraAttestationRequest(), 7, 'device-a')['ok'] ?? false) === true, 'first issue inside the rate window must succeed');
    assertUltraRuntimeAttestation(($rateCapped->issueLease(ultraAttestationRequest(), 7, 'device-a')['ok'] ?? false) === true, 'second issue inside the rate window must succeed');
    $rateRefusal = $rateCapped->issueLease(ultraAttestationRequest(), 7, 'device-a');
    assertUltraRuntimeAttestation(
        ($rateRefusal['error'] ?? '') === UltraLeaseService::ERROR_RATE_LIMITED,
        'the per-device issue rate must refuse with its own error code'
    );
    $rateNow += UltraLeaseService::RATE_WINDOW_SECONDS + 1;
    assertUltraRuntimeAttestation(
        ($rateCapped->issueLease(ultraAttestationRequest(), 7, 'device-a')['ok'] ?? false) === true,
        'the rate window must slide instead of blocking forever'
    );

    $deviceCappedEnv = ultraAttestationEnv(['APP_ENV' => 'test', UltraLeaseService::MAX_DEVICES_PER_CLIENT_KEY => '1']);
    $deviceCapped = ultraAttestationLeaseService(new Config($deviceCappedEnv));
    assertUltraRuntimeAttestation(($deviceCapped->issueLease(ultraAttestationRequest(), 7, 'device-a')['ok'] ?? false) === true, 'the first device must issue');
    assertUltraRuntimeAttestation(($deviceCapped->issueLease(ultraAttestationRequest(), 7, 'device-a')['ok'] ?? false) === true, 'an existing device must not burn the device allowance');
    $deviceRefusal = $deviceCapped->issueLease(ultraAttestationRequest(), 7, 'device-b');
    assertUltraRuntimeAttestation(
        ($deviceRefusal['error'] ?? '') === UltraLeaseService::ERROR_CLIENT_DEVICE_LIMIT,
        'the device cap must refuse with its own error code'
    );
    assertUltraRuntimeAttestation(
        ($noCaps->issueLease(ultraAttestationRequest(), 7, 'device-c')['ok'] ?? false) === true,
        'a device cap of 0 must stay unlimited'
    );

    // ---------------------------------------------------------------------
    // 5. Refusals are logged (error log + audit table) without consuming the
    //    single-use nonce, and a broken limiter fails open.
    // ---------------------------------------------------------------------
    // Only the refusals below must be observable in the log file.
    file_put_contents($logPath, '');
    $dbCappedEnv = ultraAttestationEnv(['APP_ENV' => 'test', UltraLeaseService::MAX_LIVE_LEASES_PER_DEVICE_KEY => '1']);
    Database::$liveLeases = 3;
    Database::$inserts = [];
    $dbLeaseService = new UltraLeaseService(
        new Config($dbCappedEnv),
        new Database(),
        null,
        'ultraAttestationCandidate',
        static fn(): int => 1_900_000_000,
        'ultraAttestationPolicy'
    );
    $dbRefusal = $dbLeaseService->issueLease(ultraAttestationRequest(), 7, 'device-a');
    assertUltraRuntimeAttestation(
        ($dbRefusal['error'] ?? '') === UltraLeaseService::ERROR_DEVICE_LIVE_LIMIT,
        'a configured cap must refuse against the stored lease count'
    );
    $refusalInserts = array_values(array_filter(Database::$inserts, static fn(array $row): bool => $row['table'] === 'ultra_lease_refusals'));
    assertUltraRuntimeAttestation(count($refusalInserts) === 1, 'every refusal must be persisted for the admin');
    assertUltraRuntimeAttestation(
        ($refusalInserts[0]['data']['error_code'] ?? '') === UltraLeaseService::ERROR_DEVICE_LIVE_LIMIT
        && ($refusalInserts[0]['data']['client_id'] ?? 0) === 7
        && ($refusalInserts[0]['data']['device_hash'] ?? '') === hash('sha256', 'device-a')
        && ($refusalInserts[0]['data']['forward_id'] ?? 0) === 42,
        'the persisted refusal must identify client, device hash, forward and code'
    );
    assertUltraRuntimeAttestation(
        array_filter(Database::$inserts, static fn(array $row): bool => $row['table'] === 'ultra_client_leases') === [],
        'a refused lease must not consume the single-use nonce'
    );
    assertUltraRuntimeAttestation(
        str_contains((string) file_get_contents($logPath), 'ultra_lease_refused code=' . UltraLeaseService::ERROR_DEVICE_LIVE_LIMIT),
        'every refusal must be written to the error log'
    );
    Database::$liveLeases = 0;
    Database::$inserts = [];
    assertUltraRuntimeAttestation(
        ($dbLeaseService->issueLease(ultraAttestationRequest(), 7, 'device-a')['ok'] ?? false) === true,
        'the lease must issue again once the device is under the cap'
    );
    Database::$throwOnLimitLookup = true;
    assertUltraRuntimeAttestation(
        ($dbLeaseService->issueLease(ultraAttestationRequest(), 7, 'device-a')['ok'] ?? false) === true,
        'a limiter that cannot read its counter must fail open'
    );
    assertUltraRuntimeAttestation(
        str_contains((string) file_get_contents($logPath), 'ultra_lease_limit_lookup_failed'),
        'a broken limiter lookup must be logged'
    );
    Database::$throwOnLimitLookup = false;
    Database::$refusalRows = [[
        'id' => 5,
        'client_id' => 7,
        'device_hash' => hash('sha256', 'device-a'),
        'forward_id' => 42,
        'error_code' => UltraLeaseService::ERROR_DEVICE_LIVE_LIMIT,
        'created_at' => '2030-01-01 00:00:00',
    ]];
    $recent = $dbLeaseService->recentRefusals(10);
    assertUltraRuntimeAttestation(count($recent) === 1, 'the admin refusal view must read the audit table');
    assertUltraRuntimeAttestation(
        strlen((string) ($recent[0]['device_hash_prefix'] ?? '')) === 16
        && ($recent[0]['error_code'] ?? '') === UltraLeaseService::ERROR_DEVICE_LIVE_LIMIT,
        'the admin refusal view must expose only a bounded device hash prefix'
    );

    // ---------------------------------------------------------------------
    // 6. Validation: malformed fingerprints and hashes are never persisted.
    // ---------------------------------------------------------------------
    $validFingerprint = str_repeat('f', 16);
    $validSha256 = str_repeat('9', 64);
    assertUltraRuntimeAttestation(
        UltraClientStatusService::normalizeRuntimeAttestation(['fingerprint' => 'NOT-HEX!!']) === null,
        'a malformed fingerprint alone must not produce an attestation'
    );
    assertUltraRuntimeAttestation(
        UltraClientStatusService::normalizeRuntimeAttestation(['fingerprint' => 'abc']) === null,
        'a fingerprint shorter than 8 hex characters must be rejected'
    );
    assertUltraRuntimeAttestation(
        UltraClientStatusService::normalizeRuntimeAttestation(['fingerprint' => str_repeat('a', 65)]) === null,
        'a fingerprint longer than 64 hex characters must be rejected'
    );
    assertUltraRuntimeAttestation(
        UltraClientStatusService::normalizeRuntimeAttestation(['self_sha256' => str_repeat('a', 63)]) === null,
        'a 63 character executable hash must be rejected'
    );
    assertUltraRuntimeAttestation(
        UltraClientStatusService::normalizeRuntimeAttestation(['self_sha256' => str_repeat('z', 64)]) === null,
        'a non-hex executable hash must be rejected'
    );
    assertUltraRuntimeAttestation(
        UltraClientStatusService::normalizeRuntimeAttestation('fingerprint') === null
        && UltraClientStatusService::normalizeRuntimeAttestation([]) === null,
        'a non-object or empty attestation must be treated as absent'
    );
    $partial = UltraClientStatusService::normalizeRuntimeAttestation([
        'fingerprint' => strtoupper($validFingerprint),
        'self_sha256' => 'not-a-hash',
        'debugger' => 'true',
        'instrumented' => 1,
    ]);
    assertUltraRuntimeAttestation(
        is_array($partial)
        && $partial['fingerprint'] === $validFingerprint
        && $partial['self_sha256'] === null
        && $partial['debugger'] === true
        && $partial['instrumented'] === true,
        'each malformed field must degrade to NULL without discarding the valid ones'
    );
    $coerced = UltraClientStatusService::normalizeRuntimeAttestation([
        'fingerprint' => $validFingerprint,
        'self_sha256' => $validSha256,
        'debugger' => 'maybe',
        'instrumented' => 7,
    ]);
    assertUltraRuntimeAttestation(
        is_array($coerced) && $coerced['debugger'] === null && $coerced['instrumented'] === null,
        'unusable flag values must stay unknown instead of guessing'
    );
    assertUltraRuntimeAttestation(
        UltraClientStatusService::coerceAttestationFlag('off') === false
        && UltraClientStatusService::coerceAttestationFlag('on') === true
        && UltraClientStatusService::coerceAttestationFlag(null) === null,
        'documented boolean spellings must coerce while unknown stays NULL'
    );
    Database::$lastUpdate = [];
    assertUltraRuntimeAttestation(
        $statusService->ingest(7, 'device-a', [ultraAttestationStatus(['runtime_attestation' => ['fingerprint' => 'BAD FINGERPRINT']])]) === 1,
        'a malformed attestation must never fail the status ingest'
    );
    assertUltraRuntimeAttestation(
        !array_key_exists('ultra_runtime_fingerprint', Database::$lastUpdate),
        'a malformed fingerprint must never reach the database'
    );
    assertUltraRuntimeAttestation(
        $statusService->ingest(7, 'device-a', [ultraAttestationStatus(['runtime_attestation' => [
            'fingerprint' => 'BAD FINGERPRINT',
            'instrumented' => true,
        ]])]) === 1,
        'a partially malformed attestation must still be ingested'
    );
    assertUltraRuntimeAttestation(
        array_key_exists('ultra_runtime_fingerprint', Database::$lastUpdate)
        && Database::$lastUpdate['ultra_runtime_fingerprint'] === null
        && (Database::$lastUpdate['ultra_runtime_instrumented'] ?? null) === 1,
        'a rejected fingerprint must be stored as NULL while valid flags survive'
    );
    assertUltraRuntimeAttestation(
        !str_contains(json_encode(Database::$lastUpdate) ?: '', 'BAD FINGERPRINT'),
        'the raw rejected fingerprint must never be persisted'
    );
    // The heartbeat handler must hand the attestation to the ingest call. The
    // desktop client carries it inside its usage snapshots and the agent inside
    // its status entries; reading only the explicit argument meant no session
    // row ever stored a fingerprint.
    $agentControllerSource = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
    foreach ([
        '$runtimeAttestation = $request->input(\'runtime_attestation\')',
        '$usageEntry[\'runtime_attestation\']',
    ] as $needle) {
        assertUltraRuntimeAttestation(
            str_contains($agentControllerSource, $needle),
            'the client heartbeat handler does not collect the runtime attestation: ' . $needle
        );
    }
    assertUltraRuntimeAttestation(
        preg_match(
            '/->ingest\(\s*\(int\) \$client\[.id.\],\s*\$deviceId,\s*\$ultraStatuses,\s*is_array\(\$runtimeAttestation\)/',
            $agentControllerSource
        ) === 1,
        'the client heartbeat handler must pass the runtime attestation to the status ingest'
    );

    assertUltraRuntimeAttestation(
        $statusService->ingest(7, 'device-a', [ultraAttestationStatus()], ['fingerprint' => $validFingerprint, 'self_sha256' => $validSha256]) === 1,
        'a session-level attestation argument must be accepted as well'
    );
    assertUltraRuntimeAttestation(
        (Database::$lastUpdate['ultra_runtime_fingerprint'] ?? '') === $validFingerprint,
        'the session-level attestation argument must be persisted'
    );
    // The attestation is session scoped: a status entry for an unassigned
    // forward still carries the runtime identity of this device.
    assertUltraRuntimeAttestation(
        $statusService->ingest(7, 'device-a', [
            ultraAttestationStatus(['forward_id' => 42]),
            ultraAttestationStatus([
                'forward_id' => 99,
                'runtime_attestation' => ['fingerprint' => $validFingerprint, 'debugger' => true],
            ]),
        ]) === 1,
        'a status report stays ingestible when the attestation rides on another entry'
    );
    assertUltraRuntimeAttestation(
        array_key_exists('ultra_runtime_fingerprint', Database::$lastUpdate)
        && Database::$lastUpdate['ultra_runtime_fingerprint'] === $validFingerprint
        && (Database::$lastUpdate['ultra_runtime_debugger'] ?? null) === 1,
        'a session-scoped attestation must be captured from any status entry'
    );
    $decodedStatuses = json_decode((string) Database::$lastUpdate['ultra_status_json'], true);
    assertUltraRuntimeAttestation(
        is_array($decodedStatuses)
        && count($decodedStatuses) === 1
        && ($decodedStatuses[0]['forward_id'] ?? 0) === 42
        && !array_key_exists('runtime_attestation', $decodedStatuses[0]),
        'the attestation must never leak into the per-forward status payload'
    );

    // ---------------------------------------------------------------------
    // 7. Schema and admin surface contracts.
    // ---------------------------------------------------------------------
    $migration = (string) file_get_contents(__DIR__ . '/../migrations/20260912_add_ultra_runtime_attestation_and_abuse_limits.sql');
    foreach ([
        'ultra_runtime_fingerprint VARCHAR(64) NULL',
        'ultra_runtime_self_sha256 CHAR(64) NULL',
        'ultra_runtime_debugger TINYINT(1) NULL',
        'ultra_runtime_instrumented TINYINT(1) NULL',
        'ultra_runtime_attested_at DATETIME NULL',
        'CREATE TABLE IF NOT EXISTS ultra_lease_refusals',
        'error_code VARCHAR(64) NOT NULL',
    ] as $needle) {
        assertUltraRuntimeAttestation(str_contains($migration, $needle), 'the migration is missing: ' . $needle);
    }
    // ---------------------------------------------------------------------
    // 6. Repackaged-build enforcement: report by default, precise when on.
    // ---------------------------------------------------------------------
    $releaseManifest = json_decode((string) file_get_contents(__DIR__ . '/../../artifacts/versions.json'), true);
    $releasedFingerprint = '';
    $releasedHash = '';
    foreach ((array) $releaseManifest as $entry) {
        $attestation = is_array($entry) ? ($entry['attestation'] ?? null) : null;
        if (is_array($attestation)
            && is_string($attestation['fingerprint'] ?? null)
            && is_string($attestation['self_sha256'] ?? null)) {
            $releasedFingerprint = (string) $attestation['fingerprint'];
            $releasedHash = (string) $attestation['self_sha256'];
            break;
        }
    }
    assertUltraRuntimeAttestation(
        $releasedFingerprint !== '' && preg_match('/^[a-f0-9]{64}$/', $releasedHash) === 1,
        'the release manifest must publish a fingerprint + hash for enforcement to compare against'
    );

    // A current runtime entry must describe the bytes the panel actually serves.
    // With enforcement on, a device whose fingerprint is in this map but whose
    // reported binary hash is not gets refused: a stale attestation block (the
    // fingerprint updated, the hash left behind from an earlier build) would
    // lock out paying clients that run exactly the binary we published.
    $currentRuntimeEntries = 0;
    foreach ((array) $releaseManifest as $entryKey => $entry) {
        if (!is_array($entry) || !str_starts_with((string) $entryKey, 'ultra_client_runtime')) {
            continue;
        }
        $attestation = $entry['attestation'] ?? null;
        $version = (string) ($entry['version'] ?? '');
        if (!is_array($attestation) || $version === '') {
            continue;
        }
        $fingerprint = (string) ($attestation['fingerprint'] ?? '');
        if (preg_match('/^' . preg_quote($version, '/') . '\.[0-9a-f]{7,40}$/', $fingerprint) !== 1) {
            // A block carried over from an older release cannot be checked here.
            continue;
        }
        $currentRuntimeEntries++;
        assertUltraRuntimeAttestation(
            strtolower((string) ($attestation['self_sha256'] ?? '')) === strtolower((string) ($entry['sha256'] ?? '')),
            $entryKey . ': attestation.self_sha256 must equal the published artifact sha256'
        );
    }
    assertUltraRuntimeAttestation(
        $currentRuntimeEntries >= 1,
        'at least one current runtime entry must publish a verifiable attestation'
    );

    // The published fingerprint shape must survive validation end to end.
    assertUltraRuntimeAttestation(
        UltraClientStatusService::runtimeFingerprintOrNull($releasedFingerprint) === $releasedFingerprint,
        'a released version fingerprint (2026.09.12.1.<sha>) must validate'
    );
    assertUltraRuntimeAttestation(
        UltraClientStatusService::runtimeFingerprintOrNull('0123456789abcdef') === '0123456789abcdef',
        'a legacy hex build id must still validate'
    );
    assertUltraRuntimeAttestation(
        UltraClientStatusService::runtimeFingerprintOrNull('not a fingerprint') === null,
        'a malformed fingerprint must be dropped'
    );

    $enforceConfig = new Config(ultraAttestationEnv([
        'APP_ENV' => 'test',
        UltraLeaseService::ATTESTATION_ENFORCEMENT_KEY => 'enforce',
        UltraLeaseService::ATTESTATION_ENFORCE_PLATFORMS_KEY => 'linux',
    ]));
    $enforceService = new UltraLeaseService(
        $enforceConfig,
        new Database(),
        null,
        'ultraAttestationCandidate',
        static fn(): int => 1_900_000_000,
        'ultraAttestationPolicy'
    );

    // (a) the published binary keeps working.
    Database::$inserts = [];
    file_put_contents($logPath, '');
    Database::$attestationSession = [
        'platform' => 'linux-x86_64-agent',
        'ultra_runtime_fingerprint' => $releasedFingerprint,
        'ultra_runtime_self_sha256' => $releasedHash,
    ];
    $releasedLease = $enforceService->issueLease(ultraAttestationRequest(), 7, 'device-a');
    assertUltraRuntimeAttestation(
        ($releasedLease['error'] ?? '') !== UltraLeaseService::ERROR_RUNTIME_UNATTESTED,
        'a released binary must never be refused by the attestation check'
    );

    // (b) same fingerprint, different bytes -> a repackaged client, refused + audited.
    Database::$inserts = [];
    Database::$attestationSession['ultra_runtime_self_sha256'] = str_repeat('ab', 32);
    file_put_contents($logPath, '');
    $repackagedLease = $enforceService->issueLease(ultraAttestationRequest(), 7, 'device-a');
    assertUltraRuntimeAttestation(
        ($repackagedLease['error'] ?? '') === UltraLeaseService::ERROR_RUNTIME_UNATTESTED,
        'a repackaged binary claiming a released fingerprint must be refused'
    );
    $attestationRefusals = array_values(array_filter(
        Database::$inserts,
        static fn(array $row): bool => $row['table'] === 'ultra_lease_refusals'
            && ($row['data']['error_code'] ?? '') === UltraLeaseService::ERROR_RUNTIME_UNATTESTED
    ));
    assertUltraRuntimeAttestation(count($attestationRefusals) === 1, 'an attestation refusal must be audited for the admin');
    assertUltraRuntimeAttestation(
        str_contains((string) file_get_contents($logPath), 'ultra_attestation_mismatch'),
        'an attestation refusal must be logged'
    );

    // (c) a platform outside the gate (macOS ships a re-signed runtime) is never refused.
    Database::$attestationSession['platform'] = 'macos-arm64';
    $macLease = $enforceService->issueLease(ultraAttestationRequest(), 7, 'device-a');
    assertUltraRuntimeAttestation(
        ($macLease['error'] ?? '') !== UltraLeaseService::ERROR_RUNTIME_UNATTESTED,
        'the platform gate must keep re-signed platforms out of enforcement'
    );

    // (d) an unpublished fingerprint is reported, never denied.
    Database::$attestationSession = [
        'platform' => 'linux-x86_64-agent',
        'ultra_runtime_fingerprint' => '2020.01.01.1.deadbeef',
        'ultra_runtime_self_sha256' => str_repeat('cd', 32),
    ];
    $unpublishedLease = $enforceService->issueLease(ultraAttestationRequest(), 7, 'device-a');
    assertUltraRuntimeAttestation(
        ($unpublishedLease['error'] ?? '') !== UltraLeaseService::ERROR_RUNTIME_UNATTESTED,
        'a build we never published must be reported, not locked out'
    );

    // (e) no attestation at all (an older client) keeps working.
    Database::$attestationSession = null;
    $legacyLease = $enforceService->issueLease(ultraAttestationRequest(), 7, 'device-a');
    assertUltraRuntimeAttestation(
        ($legacyLease['error'] ?? '') !== UltraLeaseService::ERROR_RUNTIME_UNATTESTED,
        'a client that reports no attestation must keep working'
    );

    // (f) report mode (the default) never denies, even on a mismatch.
    Database::$attestationSession = [
        'platform' => 'linux-x86_64-agent',
        'ultra_runtime_fingerprint' => $releasedFingerprint,
        'ultra_runtime_self_sha256' => str_repeat('ab', 32),
    ];
    $reportOnlyService = new UltraLeaseService(
        new Config(ultraAttestationEnv(['APP_ENV' => 'test'])),
        new Database(),
        null,
        'ultraAttestationCandidate',
        static fn(): int => 1_900_000_000,
        'ultraAttestationPolicy'
    );
    $reportOnlyLease = $reportOnlyService->issueLease(ultraAttestationRequest(), 7, 'device-a');
    assertUltraRuntimeAttestation(
        ($reportOnlyLease['error'] ?? '') !== UltraLeaseService::ERROR_RUNTIME_UNATTESTED,
        'report mode must never deny a lease'
    );
    Database::$attestationSession = null;

    $schema = (string) file_get_contents(__DIR__ . '/../migrations/schema.sql');
    foreach ([
        'ultra_runtime_fingerprint VARCHAR(64) NULL',
        'ultra_runtime_instrumented TINYINT(1) NULL',
        'ultra_runtime_attested_at DATETIME NULL',
        'CREATE TABLE IF NOT EXISTS ultra_lease_refusals',
    ] as $needle) {
        assertUltraRuntimeAttestation(str_contains($schema, $needle), 'schema.sql is missing: ' . $needle);
    }
    foreach ([$migration, $schema] as $sql) {
        assertUltraRuntimeAttestation(
            !str_contains($sql, 'ultra_runtime_tampered'),
            'the runtime key is instrumented, not tampered'
        );
    }
    $statusSource = (string) file_get_contents(__DIR__ . '/../src/Support/UltraClientStatusService.php');
    foreach ([
        "RUNTIME_FINGERPRINTS_KEY = 'ULTRA_RUNTIME_FINGERPRINTS'",
        "ATTESTATION_STATE_KNOWN = 'known'",
        "ATTESTATION_STATE_UNKNOWN = 'unknown'",
        "ATTESTATION_STATE_MISMATCH = 'mismatch'",
        'normalizeRuntimeAttestation',
        'runtimeAttestationMismatches',
        'isRuntimeFingerprintAllowlisted',
        'no_allowlist_configured',
        'fingerprint_not_allowlisted',
        'ultra_runtime_instrumented',
    ] as $needle) {
        assertUltraRuntimeAttestation(str_contains($statusSource, $needle), 'the status service is missing: ' . $needle);
    }
    foreach ([
        "MAX_DEVICES_PER_CLIENT_KEY = 'ULTRA_MAX_DEVICES_PER_CLIENT'",
        "MAX_LIVE_LEASES_PER_DEVICE_KEY = 'ULTRA_MAX_LIVE_LEASES_PER_DEVICE'",
        "ISSUE_RATE_PER_MINUTE_KEY = 'ULTRA_LEASE_ISSUE_RATE_PER_MINUTE'",
        "ERROR_RATE_LIMITED = 'lease_issue_rate_limited'",
        "ERROR_DEVICE_LIVE_LIMIT = 'lease_device_live_limit'",
        "ERROR_CLIENT_DEVICE_LIMIT = 'lease_client_device_limit'",
        'ultra_lease_refusals',
        'error_log(',
    ] as $needle) {
        assertUltraRuntimeAttestation(str_contains($leaseSource, $needle), 'the lease service is missing: ' . $needle);
    }
    $adminSource = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
    foreach (['view=attestation', 'view=refusals', 'runtimeAttestationMismatches', 'recentRefusals', 'Runtime mismatch'] as $needle) {
        assertUltraRuntimeAttestation(str_contains($adminSource, $needle), 'the admin surface is missing: ' . $needle);
    }

    foreach ([$defaultEnv, $knownEnv, $liveCappedEnv, $rateCappedEnv, $deviceCappedEnv, $dbCappedEnv, $logPath] as $tempFile) {
        @unlink($tempFile);
    }
    echo "ok\n";
}
