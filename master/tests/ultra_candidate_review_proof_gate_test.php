<?php
declare(strict_types=1);

/**
 * A review may only change a device's standing when it could actually
 * discriminate.
 *
 * The probe runs on the source host and can only speak for the source host's
 * path to the relay it picked. A relay-local keep echo, an endpoint the panel
 * never advertised, or an agent that sends no evidence at all cannot support a
 * strike against the devices that reported the candidate as broken - and it
 * cannot lift the quarantine either. Before this gate existed, a passing review
 * rebutted every witness and demoted them at two strikes, which is how the
 * panel's only tokyo witnesses disappeared while the candidates they reported
 * stayed advertised.
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
        /** @var array<string, mixed> */
        public array $canned = [];

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
            if (str_contains($sql, 'witnesses_eligible')) {
                return $this->canned['witness_losses'] ?? [];
            }
            if (str_contains($sql, 'GROUP BY state')) {
                return $this->canned['review_states'] ?? [];
            }
            if (str_contains($sql, 'SELECT DISTINCT candidate_key_hash')) {
                return $this->canned['reviewed_candidates'] ?? [];
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
            if (str_contains($sql, 'state IN (:pending, :running)')) {
                return ['total' => (int) ($this->canned['open'] ?? 0)];
            }
            if (str_contains($sql, 'COUNT(*) AS total FROM ultra_candidate_reputation_current')) {
                return ['total' => (int) ($this->canned['candidates_total'] ?? 0)];
            }
            if (str_contains($sql, 'FROM ultra_candidate_reputation_events')) {
                return ['total' => (int) ($this->canned['client_failures'] ?? 0)];
            }
            if (str_contains($sql, 'last_rebuttal_at IS NOT NULL AND last_rebuttal_at >=')) {
                return ['total' => (int) ($this->canned['rebutted'] ?? 0)];
            }
            if (str_contains($sql, 'demoted_at IS NOT NULL AND demoted_at >=')) {
                return ['total' => (int) ($this->canned['demoted'] ?? 0)];
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
                        $this->reviews[$index]['state'] = $params['state'] ?? $row['state'];
                        $this->reviews[$index]['completed_at'] = $params['completed_at'] ?? null;
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

    function failReviewProof(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertReviewProof(bool $condition, string $message): void
    {
        if (!$condition) {
            failReviewProof($message);
        }
    }

    $now = 1_900_000_000;
    $clock = static function () use (&$now): int {
        return $now;
    };

    /**
     * One review of one candidate, with the configured proof payload.
     *
     * @param array<string, mixed> $detail
     * @return array{0: Database, 1: array<string, mixed>}
     */
    function runReview(array $detail, bool $passed, array $canned = [], string $candidateHash = ''): array
    {
        $db = new Database();
        $hash = $candidateHash !== '' ? $candidateHash : str_repeat('a', 64);
        $db->reviews[] = [
            'id' => 21,
            'candidate_key_hash' => $hash,
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
            'relay_ips_json' => '["1.117.238.119","121.5.240.89","113.31.112.106"]',
        ]];
        $db->canned = $canned;
        $service = new UltraCandidateReviewService($db, static fn(): int => 1_900_000_000);
        $row = $service->recordResult(21, $passed, $detail, 'probe note');
        return [$db, $row];
    }

    // The exact shape the field probe sends today: counters only, no proof block,
    // and a relay the source host picked for itself.
    $fieldDetail = [
        'passed' => true,
        'relay' => '106.75.250.117:5438',
        'rounds' => 30,
        'udp_ok' => 30,
        'udp_failed' => 0,
        'big_ok' => 30,
        'big_failed' => 0,
        'dns_ok' => 30,
        'dns_failed' => 0,
        'max_echo_bytes' => 1410,
        'min_rtt_ms' => 0.076,
        'last_error' => '',
    ];
    [$db, $row] = runReview($fieldDetail, true);
    assertReviewProof(
        $db->strikeStatements === [],
        'a review with no discriminating proof struck ' . count($db->strikeStatements) . ' device statement(s)'
    );
    assertReviewProof(
        $db->quarantineLifted === false,
        'a review with no discriminating proof lifted a quarantine'
    );
    $stored = json_decode((string) ($db->reviews[0]['result_json'] ?? ''), true);
    assertReviewProof(
        is_array($stored) && (string) ($stored['rebuttal'] ?? '') === 'blocked',
        'a non-discriminating review was not recorded as blocked'
    );
    assertReviewProof(
        is_array($stored) && (string) ($stored['proof']['reason'] ?? '') === UltraCandidateReviewService::PROOF_REASON_MISSING,
        'the stored proof reason does not name missing evidence'
    );

    // A proof block that names an endpoint the panel never advertised for the
    // line is not evidence about that candidate either.
    [$db] = runReview([
        'proof' => [
            'endpoint' => '113.31.108.186:5438',
            'advertised' => true,
            'local_path_only' => false,
            'end_to_end_ok' => 30,
        ],
    ], true);
    assertReviewProof($db->strikeStatements === [], 'an unadvertised endpoint struck a device');
    assertReviewProof($db->quarantineLifted === false, 'an unadvertised endpoint lifted a quarantine');

    // A relay-local echo is not a client path, however clean it looks.
    [$db] = runReview([
        'proof' => [
            'endpoint' => '1.117.238.119:5438',
            'advertised' => true,
            'local_path_only' => true,
            'end_to_end_ok' => 0,
        ],
    ], true);
    assertReviewProof($db->strikeStatements === [], 'a relay-local echo struck a device');
    assertReviewProof($db->quarantineLifted === false, 'a relay-local echo lifted a quarantine');

    // An advertised endpoint with no end-to-end transfer is still not proof.
    [$db, $row] = runReview([
        'proof' => [
            'endpoint' => '1.117.238.119:5438',
            'advertised' => true,
            'local_path_only' => false,
            'end_to_end_ok' => 0,
        ],
    ], true);
    assertReviewProof($db->strikeStatements === [], 'a pass with no end-to-end transfer struck a device');
    assertReviewProof($db->quarantineLifted === false, 'a pass with no end-to-end transfer lifted a quarantine');
    $stored = json_decode((string) ($db->reviews[0]['result_json'] ?? ''), true);
    assertReviewProof(
        is_array($stored) && (string) ($stored['proof']['reason'] ?? '') === UltraCandidateReviewService::PROOF_REASON_TRANSFER,
        'the stored proof reason does not name the missing transfer'
    );

    // Real proof: an advertised endpoint that carried an end-to-end transfer. The
    // quarantine is lifted and the witnesses are rebutted, exactly as before.
    [$db, $row] = runReview([
        'proof' => [
            'endpoint' => '1.117.238.119:5438',
            'advertised' => true,
            'local_path_only' => false,
            'end_to_end_ok' => 30,
        ],
    ], true);
    assertReviewProof(count($db->strikeStatements) === 2, 'a discriminating pass must rebut both witnesses');
    assertReviewProof($db->quarantineLifted === true, 'a discriminating pass must lift the quarantine');
    $stored = json_decode((string) ($db->reviews[0]['result_json'] ?? ''), true);
    assertReviewProof(
        is_array($stored) && (string) ($stored['proof']['reason'] ?? '') === UltraCandidateReviewService::PROOF_OK,
        'a discriminating pass was not recorded as such'
    );
    assertReviewProof(
        is_array($stored) && (string) ($stored['rebuttal'] ?? '') === 'applied',
        'a discriminating pass was not recorded as applied'
    );

    // A discriminating FAILED review still gives the witnesses their strike back:
    // their report was right.
    [$db] = runReview([
        'proof' => [
            'endpoint' => '121.5.240.89:5438',
            'local_path_only' => false,
            'end_to_end_ok' => 5,
        ],
    ], false);
    assertReviewProof($db->strikeStatements !== [], 'a discriminating failure must credit the witnesses');
    assertReviewProof(
        str_contains($db->strikeStatements[0], 'GREATEST'),
        'a confirmed report must remove a strike, not add one'
    );

    // Detection health: a 100% pass rate over a window in which the fleet kept
    // reporting failures for the same candidates is self-contradictory, and a
    // candidate with no eligible witness left is the incident this whole design
    // is about. Both have to be named.
    $db = new Database();
    $db->canned = [
        'review_states' => [['state' => 'passed', 'total' => 4]],
        'open' => 0,
        'candidates_total' => 30,
        'reviewed_candidates' => [['candidate_key_hash' => str_repeat('d', 64)]],
        'client_failures' => 333,
        'rebutted' => 5,
        'demoted' => 4,
        'witness_losses' => [[
            'line_id' => 453,
            'destination_zone' => 'tokyo',
            'witnesses_all_time' => 6,
            'witnesses_eligible' => 0,
            'failures_in_window' => 333,
        ]],
    ];
    $health = (new UltraCandidateReviewService($db, static fn(): int => $now))->reviewHealth(86400);
    assertReviewProof((float) ($health['pass_rate'] ?? -1.0) === 1.0, 'pass rate was not computed');
    assertReviewProof((int) ($health['client_failures_on_reviewed_candidates'] ?? 0) === 333, 'client failures were not counted');
    assertReviewProof(
        in_array('review_pass_rate_100_percent_while_clients_report_failures', $health['alarms'] ?? [], true),
        'a 100% pass rate over real client failures must raise an alarm'
    );
    assertReviewProof(
        in_array('candidate_witness_pool_exhausted', $health['alarms'] ?? [], true),
        'a candidate that lost every witness must raise an alarm'
    );
    assertReviewProof(
        (int) ($health['witness_losses'][0]['witnesses_eligible'] ?? -1) === 0,
        'witness loss must be able to read as zero eligible witnesses'
    );

    echo "ultra_candidate_review_proof_gate_test: OK" . PHP_EOL;
}
