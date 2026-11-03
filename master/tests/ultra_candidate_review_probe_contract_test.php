<?php
declare(strict_types=1);

/**
 * The proof block the source-host probe actually emits must survive the live gate.
 *
 * The Python probe tests pin the shape it intends; this test closes the loop by
 * feeding the block the probe really prints - the committed fixture
 * source-agent/tests/fixtures/review_proof_healthy.json, which
 * source-agent/tests/test_ultra_line_review.py regenerates and compares on every
 * run - through UltraCandidateReviewService::recordResult() itself.
 *
 * If the two sides ever disagree about what a discriminating review looks like,
 * a probe that reports success would be recorded as rebuttal=blocked and nobody
 * would notice. This is the test that notices.
 */

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $reviews = [];
        /** @var array<int, array<string, mixed>> */
        public array $observers = [];
        /** @var array<int, array<string, mixed>> */
        public array $lines = [];
        /** @var array<int, string> */
        public array $strikeStatements = [];
        public bool $quarantineLifted = false;

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'SELECT DISTINCT observer_hash')) {
                return $this->observers;
            }
            if (str_contains($sql, 'FROM ultra_lines WHERE line_id')) {
                $wanted = (int) ($params['line_id'] ?? 0);
                return array_values(array_filter(
                    $this->lines,
                    static fn(array $row): bool => (int) ($row['line_id'] ?? 0) === $wanted
                ));
            }
            return [];
        }

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM ultra_candidate_reviews WHERE id')) {
                foreach ($this->reviews as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
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
            if (str_contains($sql, 'strikes = strikes + 1') || str_contains($sql, 'strikes = GREATEST')) {
                $this->strikeStatements[] = $sql;
            }
            if (str_contains($sql, 'UPDATE ultra_candidate_reputation_current')) {
                $this->quarantineLifted = true;
            }
            if (str_contains($sql, 'UPDATE ultra_candidate_reviews')) {
                foreach ($this->reviews as $index => $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        $this->reviews[$index]['result_json'] = $params['result_json'] ?? null;
                    }
                }
            }
            return 1;
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

    function failProbeContract(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertProbeContract(bool $condition, string $message): void
    {
        if (!$condition) {
            failProbeContract($message);
        }
    }

    $fixturePath = __DIR__ . '/../../source-agent/tests/fixtures/review_proof_healthy.json';
    $fixture = is_file($fixturePath) ? json_decode((string) file_get_contents($fixturePath), true) : null;
    assertProbeContract(
        is_array($fixture) && isset($fixture['proof']) && is_array($fixture['proof']),
        'the probe proof fixture is missing or unreadable: ' . $fixturePath
    );
    $proof = $fixture['proof'];
    $advertised = is_array($fixture['advertised'] ?? null) ? array_values($fixture['advertised']) : [];

    // Sanity: the fixture must describe exactly the discriminating shape, and
    // nothing about it may depend on a value this test invents.
    assertProbeContract($advertised !== [], 'the fixture must carry the advertised relay set');
    $endpoint = strtolower(trim((string) ($proof['endpoint'] ?? '')));
    $host = str_contains($endpoint, ':') ? strtolower(trim((string) strstr($endpoint, ':', true))) : $endpoint;
    assertProbeContract(
        in_array($host, array_map(static fn($ip): string => strtolower(trim((string) $ip)), $advertised), true),
        'the fixture endpoint is not one of the relays it advertises: ' . $endpoint
    );
    assertProbeContract(filter_var($host, FILTER_VALIDATE_IP) !== false, 'the fixture endpoint is not an IP');
    assertProbeContract(
        array_key_exists('local_path_only', $proof) && $proof['local_path_only'] === false,
        'the fixture must carry local_path_only as a real JSON false'
    );
    assertProbeContract((int) ($proof['end_to_end_ok'] ?? 0) >= 1, 'the fixture must carry a transfer count');

    /**
     * Run one review of line 453 with the fixture proof and the given relay set.
     *
     * @param array<string, mixed> $proofBlock
     * @param array<int, string> $relayIps
     * @return array{0: Database, 1: array<string, mixed>}
     */
    function runProbeReview(array $proofBlock, array $relayIps, bool $passed = true): array
    {
        $db = new Database();
        $db->reviews[] = [
            'id' => 21,
            'candidate_key_hash' => str_repeat('a', 64),
            'source_id' => 'source-home-171',
            'line_id' => 453,
            'protocol' => 'tcp+udp',
            'state' => UltraCandidateReviewService::STATE_RUNNING,
            'attempts' => 1,
            'result_json' => null,
        ];
        $db->observers = [
            ['observer_hash' => str_repeat('b', 64)],
            ['observer_hash' => str_repeat('c', 64)],
        ];
        $db->lines = [[
            'line_id' => 453,
            'source_id' => 'source-home-171',
            'relay_ips_json' => json_encode(array_values($relayIps)),
        ]];
        $service = new UltraCandidateReviewService($db, static fn(): int => 1_900_000_000);
        $row = $service->recordResult(21, $passed, ['proof' => $proofBlock], 'probe review');
        return [$db, $row];
    }

    // The block the probe really prints: the panel must act on it.
    [$db, $row] = runProbeReview($proof, $advertised);
    $stored = json_decode((string) ($db->reviews[0]['result_json'] ?? ''), true);
    assertProbeContract(
        is_array($stored) && (string) ($stored['proof']['reason'] ?? '') === UltraCandidateReviewService::PROOF_OK,
        'the gate refused the proof block the probe emits: '
            . (string) (is_array($stored) ? ($stored['proof']['reason'] ?? 'no reason') : 'no result')
    );
    assertProbeContract(
        is_array($stored) && (string) ($stored['rebuttal'] ?? '') === 'applied',
        'the gate recorded the probe proof as blocked instead of applied'
    );
    assertProbeContract(count($db->strikeStatements) === 2, 'a discriminating pass must rebut both witnesses');
    assertProbeContract($db->quarantineLifted === true, 'a discriminating pass must lift the quarantine');

    // Same block, an endpoint this line does not advertise: the panel derives the
    // mandate itself and must refuse it whatever the probe claims.
    [$db] = runProbeReview($proof, ['9.9.9.9']);
    assertProbeContract($db->strikeStatements === [], 'the gate trusted an endpoint the line does not advertise');
    assertProbeContract($db->quarantineLifted === false, 'an unadvertised endpoint lifted a quarantine');

    // Same block, no transfer: not proof either.
    $withoutTransfer = $proof;
    $withoutTransfer['end_to_end_ok'] = 0;
    [$db] = runProbeReview($withoutTransfer, $advertised);
    assertProbeContract($db->strikeStatements === [], 'a proof without a transfer struck a device');
    assertProbeContract($db->quarantineLifted === false, 'a proof without a transfer lifted a quarantine');

    // A relay-local pass: the shape the OLD probe would have produced.
    $localOnly = $proof;
    $localOnly['local_path_only'] = true;
    $localOnly['end_to_end_ok'] = 0;
    [$db] = runProbeReview($localOnly, $advertised);
    assertProbeContract($db->strikeStatements === [], 'a relay-local pass struck a device');
    assertProbeContract($db->quarantineLifted === false, 'a relay-local pass lifted a quarantine');

    echo 'ultra_candidate_review_probe_contract_test: OK' . PHP_EOL;
}
