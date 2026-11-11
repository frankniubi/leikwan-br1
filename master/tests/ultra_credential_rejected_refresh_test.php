<?php
declare(strict_types=1);

/**
 * 2026-11-09: relays refused a line's credential (05 02) 10-20 minutes into its
 * material while the refresh window only fired at minute 20. Of 45 rejections
 * matched to a material window, 42 hit a relay the next acquisition dropped;
 * clients kept dialling it for up to ten minutes.
 *
 * Pinned here:
 *   - a credential_rejected report marks the line's material due before its
 *     window, and the line is handed out first;
 *   - it is still not a reputation strike;
 *   - per line, no mark while the material is younger than the interval, and a
 *     burst of reports marks it once;
 *   - a new upload clears the mark; the interval 0 disables the feature.
 */

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public static array $lineRows = [];

        /** @var array<string, array<string, mixed>> */
        public static array $leaseOverrides = [];

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'SELECT DISTINCT ul.line_id')) {
                return self::$lineRows;
            }
            if (str_contains($sql, 'SELECT id, group_id FROM clients')) {
                return [['id' => 7, 'group_id' => 2]];
            }
            if (str_contains($sql, 'SELECT f.id, f.line_client_id')) {
                return [[
                    'id' => 42,
                    'line_client_id' => 7,
                    'line_client_group_id' => null,
                    'line_client_group_ids_json' => null,
                    'target_host' => '198.51.100.10',
                    'target_port' => 443,
                    'name' => 'ultra-route',
                    'tenant_label' => 'SHA to ICN',
                    'ultra_destination_zone' => 'south-korea',
                ]];
            }
            return [];
        }

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM ultra_lines')) {
                return [
                    'access_class' => 'native_udp',
                    'native_udp_state' => 'verified',
                    'native_socks5_state' => 'verified',
                    'is_source_present' => 1,
                    'is_enabled' => 1,
                    'pricing_class' => 'paid',
                ];
            }
            if (str_contains($sql, 'FROM ultra_client_leases')
                && (int) ($params['client_id'] ?? 0) === 7
                && (int) ($params['forward_id'] ?? 0) === 42) {
                $leaseHash = (string) ($params['lease_hash'] ?? '');
                if (isset(self::$leaseOverrides[$leaseHash])) {
                    $row = self::$leaseOverrides[$leaseHash];
                    $minExpires = (string) ($params['min_expires_at'] ?? '');
                    if ($minExpires !== '' && isset($row['expires_at']) && (string) $row['expires_at'] < $minExpires) {
                        return null;
                    }
                    return $row;
                }
                if (hash_equals(hash('sha256', 'lease-a'), $leaseHash)) {
                    return ['id' => 1];
                }
            }
            return null;
        }

        public function insert(string $table, array $data): int
        {
            return 1;
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            return 1;
        }

        public function transaction(callable $callback)
        {
            return $callback($this);
        }
    }
}

namespace {

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\UltraCandidateReputationService;
    use Leikwan\Master\Support\UltraClientStatusService;
    use Leikwan\Master\Support\UltraMaterialService;

    function failCredRefresh(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertCredRefresh(bool $condition, string $message): void
    {
        if (!$condition) {
            failCredRefresh($message);
        }
    }

    function describeCredRefresh(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: 'unencodable';
    }

    $makeEnv = static function (string $extra = ''): string {
        $path = tempnam(sys_get_temp_dir(), 'lw-cred-refresh-env-');
        if ($path === false) {
            failCredRefresh('failed to create test env');
        }
        file_put_contents(
            $path,
            "APP_ENV=test\n"
            . "ULTRA_TOKEN_KEY=token-key-for-tests\n"
            . "ULTRA_MATERIAL_MAX_TTL_SECONDS=2100\n"
            . "ULTRA_SOURCE_CREDENTIALS_JSON={\"source-a\":\"source-a-secret\"}\n"
            . $extra
        );
        return $path;
    };

    $now = 1_893_456_000;
    $clock = static function () use (&$now): int {
        return $now;
    };

    $build = static function (string $envPath) use ($clock): array {
        $reputation = new UltraCandidateReputationService(null, $clock);
        $materials = new UltraMaterialService(new Config($envPath), new Database(), null, $clock, $reputation);
        $status = new UltraClientStatusService(new Config($envPath), new Database(), $clock, $reputation, null, null, $materials);
        return [$materials, $status, $reputation];
    };

    $upload = static function (UltraMaterialService $materials, int $lineId, int $lifetime) use (&$now): void {
        $ticket = $materials->issueUploadTicket('source-a', [$lineId]);
        $accepted = $materials->acceptSourceUpload('source-a', $ticket, [[
            'line_id' => $lineId,
            'generation' => 'generation-' . $lineId . '-' . $now,
            'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
            'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + $lifetime),
            'user' => 'line-user-' . $lineId,
            'credential' => '1234567890123456',
            'relays' => [['ip' => '203.0.113.' . ($lineId % 200 + 1), 'port' => 5438]],
            'probe' => ['ok' => true],
        ]]);
        assertCredRefresh($accepted === [$lineId], 'material for line ' . $lineId . ' was not accepted');
    };

    $scopeFor = static fn(int $lineId): array => [
        'source_id' => 'source-a',
        'line_id' => $lineId,
        'ingress_zone' => 'shanghai',
        'destination_zone' => 'south-korea',
        'protocol' => 'tcp+udp',
    ];

    $evidence = 0;
    $report = static function (UltraClientStatusService $status, int $lineId, string $reason = 'credential_rejected') use ($scopeFor, &$evidence): int {
        $evidence++;
        return $status->ingestFeedback(7, 'device-a', [[
            'forward_id' => 42,
            'candidate_key_hash' => UltraCandidateReputationService::candidateKeyHash($scopeFor($lineId)),
            'lease_hash' => hash('sha256', 'lease-a'),
            'protocol' => 'tcp',
            'outcome' => 'failure',
            'reason' => $reason,
            'evidence_id' => 'cred-' . $evidence,
            'detected_ingress_zone' => 'shanghai',
        ]]);
    };

    [$materials, $status, $reputation] = $build($makeEnv());
    Database::$lineRows = [['line_id' => 11], ['line_id' => 431], ['line_id' => 525]];
    foreach ([11, 431, 525] as $lineId) {
        $reputation->stateFor($scopeFor($lineId));
    }

    // Fresh material for all three; nothing is due inside the 600 s window.
    $upload($materials, 11, 1800);
    $upload($materials, 431, 1800);
    $upload($materials, 525, 1800);
    assertCredRefresh($materials->dueLineIds('source-a', 600) === [], 'fresh material must not be due');

    // A rejection on young material is ignored: a just-acquired credential that
    // a relay refuses is not the retirement pattern, and must not loop.
    $now += 120;
    $report($status, 431);
    assertCredRefresh($materials->dueLineIds('source-a', 600) === [], 'young material must not be marked; got ' . describeCredRefresh($materials->dueLineIds('source-a', 600)));

    // 12 minutes in, the relay is retired: the line becomes due, first in line.
    $now += 600;
    $before = $reputation->stateFor($scopeFor(431));
    assertCredRefresh($report($status, 431) === 0, 'credential_rejected must still stay out of reputation');
    $after = $reputation->stateFor($scopeFor(431));
    assertCredRefresh(
        ($after['consecutive_failures'] ?? 0) === ($before['consecutive_failures'] ?? 0)
            && ($after['last_reason'] ?? '') !== 'credential_rejected',
        'credential_rejected moved a reputation counter'
    );
    $due = $materials->dueLineIds('source-a', 600);
    assertCredRefresh($due === [431], 'a rejected line must be due before its window; got ' . describeCredRefresh($due));

    // Ordering: a rejected line sorts like material expiring now, so it beats a
    // line that is merely inside its window.
    $now += 600; // 431/11/525 now have 480 s left: all inside the window
    $due = $materials->dueLineIds('source-a', 600);
    assertCredRefresh($due[0] === 431, 'the rejected line must come first; got ' . describeCredRefresh($due));
    $now -= 600;

    // A burst of reports marks the line once and does not touch other lines.
    for ($i = 0; $i < 20; $i++) {
        $report($status, 431);
    }
    assertCredRefresh($materials->dueLineIds('source-a', 600) === [431], 'a burst must not mark other lines');

    // The source refreshes 431: the new upload clears the mark by itself.
    $upload($materials, 431, 1800);
    assertCredRefresh($materials->dueLineIds('source-a', 600) === [], 'a new upload must clear the mark');

    // Rate limit: right after the refresh, another rejection is ignored until the
    // new material is at least the interval (default 300 s) old.
    $now += 60;
    $report($status, 431);
    assertCredRefresh($materials->dueLineIds('source-a', 600) === [], 'the rate limit must hold right after a refresh');
    $now += 300;
    $report($status, 431);
    assertCredRefresh($materials->dueLineIds('source-a', 600) === [431], 'after the interval a new rejection must mark the line again');

    // Other transport reasons never mark a line.
    $report($status, 11, 'probe_tcp_failed');
    $report($status, 11, 'udp_arm_failed');
    assertCredRefresh(!in_array(11, $materials->dueLineIds('source-a', 600), true), 'only credential_rejected may mark a line');

    // Without the material service wired in, ingestFeedback behaves as before.
    $plain = new UltraClientStatusService(new Config($makeEnv()), new Database(), $clock, $reputation);
    assertCredRefresh($report($plain, 525) === 0, 'the unwired status service must still accept the report silently');
    assertCredRefresh(!in_array(525, $materials->dueLineIds('source-a', 600), true), 'the unwired status service must not mark a line');

    // Interval 0 disables the feature. 400 s in, the material is old enough to
    // be marked and still well outside the 600 s refresh window. Only line 11
    // is required here: a line with no material at all is always due.
    Database::$lineRows = [['line_id' => 11]];
    [$offMaterials, $offStatus, $offReputation] = $build($makeEnv("ULTRA_CREDENTIAL_REJECTED_REFRESH_INTERVAL_SECONDS=0\n"));
    $offReputation->stateFor($scopeFor(11));
    $upload($offMaterials, 11, 1800);
    $now += 400;
    $report($offStatus, 11);
    assertCredRefresh($offMaterials->dueLineIds('source-a', 600) === [], 'interval 0 must disable early refresh');
    [$onMaterials, $onStatus, $onReputation] = $build($makeEnv());
    $onReputation->stateFor($scopeFor(11));
    $now -= 400;
    $upload($onMaterials, 11, 1800);
    $now += 400;
    $report($onStatus, 11);
    assertCredRefresh($onMaterials->dueLineIds('source-a', 600) === [11], 'control: the same case with the default interval must mark');

    // Lines the source is backing off from are still skipped.
    Database::$lineRows = [['line_id' => 11], ['line_id' => 431], ['line_id' => 525]];
    assertCredRefresh(!in_array(431, $materials->dueLineIds('source-a', 600, [431]), true), 'a skipped line must not be handed out even when marked');

    // --- Cross-material replay, lease-expiry binding, and candidate deprioritization ---
    Database::$lineRows = [['line_id' => 431], ['line_id' => 525]];
    Database::$leaseOverrides = [];
    [$bindMaterials, $bindStatus, $bindReputation] = $build($makeEnv());
    $bindReputation->stateFor($scopeFor(431));
    $bindReputation->stateFor($scopeFor(525));
    $upload($bindMaterials, 431, 1800);
    $upload($bindMaterials, 525, 1800);
    $now += 600;
    $oldObservedAt = gmdate('Y-m-d\TH:i:s\Z', $now);
    $sameOldEvidence = [[
        'forward_id' => 42,
        'candidate_key_hash' => UltraCandidateReputationService::candidateKeyHash($scopeFor(431)),
        'lease_hash' => hash('sha256', 'lease-a'),
        'protocol' => 'tcp',
        'outcome' => 'failure',
        'reason' => 'credential_rejected',
        'evidence_id' => 'same-old-evidence',
        'observed_at' => $oldObservedAt,
        'detected_ingress_zone' => 'shanghai',
    ]];
    $bindStatus->ingestFeedback(7, 'device-a', $sameOldEvidence);
    assertCredRefresh($bindMaterials->dueLineIds('source-a', 600) === [431], 'initial rejection must mark line 431');

    // During the replacement window (before source uploads new material), line 431
    // is deprioritized behind healthy line 525 when resolving lease candidates,
    // while still serving as fallback if it is the only line.
    $grantGen = str_repeat('c', 64);
    $multiLineToken = $bindMaterials->issueClientToken([
        'client_id' => 7,
        'device_hash' => hash('sha256', 'device-a'),
        'forward_id' => 42,
        'policy_generation' => $grantGen,
        'transport' => 'tcp+udp',
        'destination_zone' => 'south-korea',
        'lines' => [
            ['source_id' => 'source-a', 'line_id' => 431, 'ingress_zone' => 'shanghai', 'destination_zone' => 'south-korea'],
            ['source_id' => 'source-a', 'line_id' => 525, 'ingress_zone' => 'shanghai', 'destination_zone' => 'south-korea'],
        ],
    ], $now + 1200);
    $firstCand = $bindMaterials->serverCandidateForClientToken($multiLineToken, 7, 'device-a', 42, $grantGen, 0);
    $secondCand = $bindMaterials->serverCandidateForClientToken($multiLineToken, 7, 'device-a', 42, $grantGen, 1);
    assertCredRefresh(
        ($firstCand['user'] ?? '') === 'line-user-525' && ($secondCand['user'] ?? '') === 'line-user-431',
        'pending-refresh line 431 must be deprioritized behind healthy line 525; got first='
        . ($firstCand['user'] ?? '') . ' second=' . ($secondCand['user'] ?? '')
    );

    // Source uploads replacement material for 431.
    $now += 1;
    $upload($bindMaterials, 431, 1800);
    assertCredRefresh($bindMaterials->dueLineIds('source-a', 600) === [], 'replacement upload must clear pending mark');
    $restoredFirst = $bindMaterials->serverCandidateForClientToken($multiLineToken, 7, 'device-a', 42, $grantGen, 0);
    assertCredRefresh(($restoredFirst['user'] ?? '') === 'line-user-431', 'after replacement upload line 431 returns to normal priority');

    // Replaying the exact historical evidence_id / observed_at after the 300 s
    // interval must NOT mark the replacement material.
    $now += 301;
    $bindStatus->ingestFeedback(7, 'device-a', $sameOldEvidence);
    assertCredRefresh($bindMaterials->dueLineIds('source-a', 600) === [], 'replayed historical evidence must not mark replacement material');

    // Even with a fresh evidence_id and fresh observed_at, a lease issued BEFORE
    // the replacement material was acquired cannot mark the new material.
    $oldLeaseHash = hash('sha256', 'lease-before-refresh');
    Database::$leaseOverrides[$oldLeaseHash] = [
        'id' => 99,
        'policy_generation' => str_repeat('1', 64),
        'issued_at' => gmdate('Y-m-d H:i:s', $now - 400),
        'expires_at' => gmdate('Y-m-d H:i:s', $now + 60),
    ];
    $bindStatus->ingestFeedback(7, 'device-a', [[
        'forward_id' => 42,
        'candidate_key_hash' => UltraCandidateReputationService::candidateKeyHash($scopeFor(431)),
        'lease_hash' => $oldLeaseHash,
        'protocol' => 'tcp',
        'outcome' => 'failure',
        'reason' => 'credential_rejected',
        'evidence_id' => 'fresh-id-on-pre-refresh-lease',
        'observed_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
        'detected_ingress_zone' => 'shanghai',
    ]]);
    assertCredRefresh($bindMaterials->dueLineIds('source-a', 600) === [], 'pre-refresh lease must not mark newer replacement material');

    // And an expired lease past FEEDBACK_LEASE_EXPIRY_GRACE_SECONDS is rejected outright.
    $expiredLeaseHash = hash('sha256', 'lease-long-expired');
    Database::$leaseOverrides[$expiredLeaseHash] = [
        'id' => 100,
        'policy_generation' => str_repeat('2', 64),
        'issued_at' => gmdate('Y-m-d H:i:s', $now - 1200),
        'expires_at' => gmdate('Y-m-d H:i:s', $now - 600),
    ];
    $bindStatus->ingestFeedback(7, 'device-a', [[
        'forward_id' => 42,
        'candidate_key_hash' => UltraCandidateReputationService::candidateKeyHash($scopeFor(431)),
        'lease_hash' => $expiredLeaseHash,
        'protocol' => 'tcp',
        'outcome' => 'failure',
        'reason' => 'credential_rejected',
        'evidence_id' => 'fresh-id-on-expired-lease',
        'observed_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
        'detected_ingress_zone' => 'shanghai',
    ]]);
    assertCredRefresh($bindMaterials->dueLineIds('source-a', 600) === [], 'long-expired lease must not bind feedback');

    fwrite(STDOUT, "ultra credential_rejected refresh test passed\n");
}
