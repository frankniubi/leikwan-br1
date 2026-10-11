<?php
declare(strict_types=1);

/**
 * A review handed to the source host must say what to test.
 *
 * The probe can only speak about the endpoint it was pointed at and the
 * destination it was pointed to. Handing it only {review_id, line_id, protocol,
 * seconds} left it to pick relays[0] of its own fresh acquisition, which is how
 * 8 of the first 9 reviews came to probe an endpoint the panel never advertised
 * for the line - evidence about something, but not about the candidate it was
 * recorded against. These tests pin the advertised relay set and the client
 * target into the payload, and pin the backward-compatible keys that the
 * deployed agent already reads.
 */

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $reviews = [];
        /** @var array<int, array<string, mixed>> */
        public array $lines = [];
        /** @var array<int, array<string, mixed>> */
        public array $scopes = [];
        /** @var array<int, array<string, mixed>> */
        public array $targets = [];
        /** @var array<int, string> */
        public array $statements = [];

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            $this->statements[] = $sql;
            if (str_contains($sql, 'FROM ultra_candidate_reputation_current c')) {
                return [];
            }
            if (str_contains($sql, 'FROM ultra_lines WHERE line_id')) {
                $wanted = (int) ($params['line_id'] ?? 0);
                return array_values(array_filter(
                    $this->lines,
                    static fn(array $row): bool => (int) ($row['line_id'] ?? 0) === $wanted
                ));
            }
            if (str_contains($sql, 'FROM ultra_candidate_reviews') && str_contains($sql, 'ORDER BY requested_at ASC')) {
                $out = [];
                foreach ($this->reviews as $row) {
                    if ((string) $row['source_id'] === (string) ($params['source_id'] ?? '')
                        && (string) $row['state'] === (string) ($params['pending'] ?? 'pending')) {
                        $out[] = $row;
                    }
                }
                return $out;
            }
            return [];
        }

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $this->statements[] = $sql;
            if (str_contains($sql, 'destination_zone FROM ultra_candidate_reputation_current')) {
                foreach ($this->scopes as $row) {
                    if ((string) ($row['candidate_key_hash'] ?? '') === (string) ($params['candidate_key_hash'] ?? '')) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($sql, 'FROM forwards f')) {
                $zone = (string) ($params['destination_zone'] ?? '');
                foreach ($this->targets as $row) {
                    if ((string) ($row['ultra_destination_zone'] ?? '') === $zone) {
                        return $row;
                    }
                }
                return null;
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $this->statements[] = $sql;
            if (str_contains($sql, 'SET state = :running, started_at = :started_at, attempts = attempts + 1')) {
                foreach ($this->reviews as $index => $row) {
                    if ((int) $row['id'] === (int) ($params['id'] ?? 0) && (string) $row['state'] === 'pending') {
                        $this->reviews[$index]['state'] = 'running';
                        $this->reviews[$index]['attempts'] = (int) $row['attempts'] + 1;
                        return 1;
                    }
                }
                return 0;
            }
            return 0;
        }

        /** @param callable():mixed $work */
        public function transaction(callable $work): mixed
        {
            return $work();
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

    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\UltraCandidateReviewService;

    function failPayload(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertPayload(bool $condition, string $message): void
    {
        if (!$condition) {
            failPayload($message);
        }
    }

    /** @param array<string, mixed> $scope @param array<string, mixed> $target */
    function claimOne(array $scope, array $target, array $lines): array
    {
        $now = 1_900_000_000;
        $clock = static fn(): int => $now;
        $db = new Database();
        $hash = str_repeat('a', 64);
        $db->reviews[] = [
            'id' => 41,
            'candidate_key_hash' => $hash,
            'source_id' => 'source-home-171',
            'line_id' => 453,
            'protocol' => 'tcp+udp',
            'state' => UltraCandidateReviewService::STATE_PENDING,
            'attempts' => 0,
            'requested_at' => gmdate('Y-m-d H:i:s', $now - 60),
        ];
        $db->lines = $lines;
        $db->scopes = $scope === [] ? [] : [['candidate_key_hash' => $hash, 'destination_zone' => $scope['destination_zone']]];
        $db->targets = $target === [] ? [] : [$target];
        $service = new UltraCandidateReviewService($db, $clock);
        return [$db, $service->requestForSource('source-home-171', 2)];
    }

    // The advertised set belongs to the line AND the source: another source
    // advertising the same numeric line_id must not leak into this payload, and
    // junk or duplicate entries must not survive.
    $lines = [
        [
            'line_id' => 453,
            'source_id' => 'source-home-171',
            'relay_ips_json' => '["1.117.238.119","121.5.240.89","1.117.238.119","not-an-ip",""]',
        ],
        [
            'line_id' => 453,
            'source_id' => 'source-other',
            'relay_ips_json' => '["9.9.9.9"]',
        ],
    ];
    [$db, $claimed] = claimOne(['destination_zone' => 'tokyo'], [
        'ultra_destination_zone' => 'tokyo',
        'target_host' => '45.143.235.221',
        'target_port' => 10809,
    ], $lines);

    assertPayload(count($claimed) === 1, 'the pending review must still be claimed');
    $review = $claimed[0];

    // The keys the deployed agent already reads must not change shape.
    assertPayload((int) $review['review_id'] === 41, 'review_id must be preserved');
    assertPayload((int) $review['line_id'] === 453, 'line_id must be preserved');
    assertPayload((string) $review['protocol'] === 'tcp+udp', 'protocol must be preserved');
    assertPayload((int) $review['seconds'] === UltraCandidateReviewService::REVIEW_SECONDS, 'seconds must be preserved');

    assertPayload(
        ($review['relays'] ?? null) === ['1.117.238.119', '121.5.240.89'],
        'the payload must carry the advertised relays of this line and source, deduplicated and validated'
    );
    assertPayload(
        ($review['target'] ?? null) === '45.143.235.221:10809',
        'the payload must carry the client target for the candidate zone'
    );

    // A candidate whose zone the panel cannot pin down degrades to no target -
    // it must not fail the review, and it must not invent a destination.
    [$db, $claimed] = claimOne(['destination_zone' => 'unknown'], [], $lines);
    assertPayload(count($claimed) === 1, 'a review with no resolvable target must still be handed out');
    assertPayload(($claimed[0]['target'] ?? null) === '', 'an unknown zone must not produce a target');
    assertPayload(
        ($claimed[0]['relays'] ?? null) === ['1.117.238.119', '121.5.240.89'],
        'the relays must still be advertised when only the target is unknown'
    );
    foreach ($db->statements as $sql) {
        assertPayload(
            !str_contains($sql, 'FROM forwards f'),
            'an unknown zone must not reach the forwards lookup at all'
        );
    }

    // A resolvable zone with no live forward is equally targetless.
    [$db, $claimed] = claimOne(['destination_zone' => 'tokyo'], [], $lines);
    assertPayload(($claimed[0]['target'] ?? null) === '', 'a zone with no forward must not produce a target');

    // A candidate row that has gone missing must not stall the queue.
    [$db, $claimed] = claimOne([], [
        'ultra_destination_zone' => 'tokyo',
        'target_host' => '45.143.235.221',
        'target_port' => 10809,
    ], $lines);
    assertPayload(count($claimed) === 1, 'a review whose candidate row is gone must still be handed out');
    assertPayload(($claimed[0]['target'] ?? null) === '', 'a missing candidate row must not produce a target');

    // A line the panel advertises nothing for is handed an empty set, not a
    // stale or borrowed one.
    [$db, $claimed] = claimOne(['destination_zone' => 'tokyo'], [
        'ultra_destination_zone' => 'tokyo',
        'target_host' => '45.143.235.221',
        'target_port' => 10809,
    ], [[
        'line_id' => 453,
        'source_id' => 'source-home-171',
        'relay_ips_json' => '[]',
    ]]);
    assertPayload(($claimed[0]['relays'] ?? null) === [], 'a line with no advertised relays must yield an empty set');

    echo "ultra_candidate_review_payload_test: OK" . PHP_EOL;
}
