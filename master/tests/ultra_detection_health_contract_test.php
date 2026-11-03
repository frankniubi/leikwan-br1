<?php
declare(strict_types=1);

/**
 * The detection-health alarms must be reachable, and the payload a console needs
 * must be present.
 *
 * Scope note, deliberately recorded here: this does NOT cover the admin console
 * surface, because that surface is not built yet. A console that shows a number
 * nobody acts on is worse than no console, so the surface is only worth adding
 * together with its translations - and master/public/i18n/zh-Hans.json and
 * zh-TW.json currently carry uncommitted work from another workstream, so adding
 * the ~16 new UI strings they would need cannot be committed without sweeping in
 * unrelated changes. The exact uncovered-string checklist is reproduced by
 * running master/tests/i18n_locale_regression_test.php once the surface lands.
 *
 * What IS pinned here is the half that a console would depend on: that the
 * service computes detection health at all, that both self-contradiction alarms
 * can actually fire from real inputs, and that every figure the console would
 * render exists and is a real number.
 */

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, mixed> */
        public array $canned = [];

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'GROUP BY state')) {
                return $this->canned['review_states'] ?? [];
            }
            if (str_contains($sql, 'SELECT DISTINCT candidate_key_hash')) {
                return $this->canned['reviewed_candidates'] ?? [];
            }
            if (str_contains($sql, 'witnesses_eligible')) {
                return $this->canned['witness_losses'] ?? [];
            }
            return [];
        }

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
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
            return 0;
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

    function failHealth(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertHealth(bool $condition, string $message): void
    {
        if (!$condition) {
            failHealth($message);
        }
    }

    $db = new Database();
    $db->canned = [
        'review_states' => [['state' => 'passed', 'total' => 4]],
        'open' => 2,
        'candidates_total' => 30,
        'reviewed_candidates' => [['candidate_key_hash' => str_repeat('d', 64)]],
        'client_failures' => 333,
        'rebutted' => 5,
        'demoted' => 6,
        'witness_losses' => [[
            'line_id' => 453,
            'destination_zone' => 'tokyo',
            'witnesses_all_time' => 6,
            'witnesses_eligible' => 0,
            'failures_in_window' => 333,
        ]],
    ];
    $health = (new UltraCandidateReviewService($db, static fn(): int => 1_900_000_000))->reviewHealth(86400);

    // The two self-contradictions the mechanism can commit, both reachable. A
    // 100% pass rate while the fleet reports failures on the SAME candidates, and
    // a candidate whose eligible witnesses are all gone while failures arrive.
    assertHealth(
        in_array('review_pass_rate_100_percent_while_clients_report_failures', $health['alarms'] ?? [], true),
        'a 100% pass rate over real client failures must be reachable as an alarm'
    );
    assertHealth(
        in_array('candidate_witness_pool_exhausted', $health['alarms'] ?? [], true),
        'a candidate that lost every witness must be reachable as an alarm'
    );

    // Every figure a console would render must exist and be a real number.
    assertHealth((float) ($health['pass_rate'] ?? -1.0) === 1.0, 'pass rate must be computed');
    assertHealth((int) ($health['reviews_completed'] ?? 0) === 4, 'completed reviews must be counted');
    assertHealth((int) ($health['reviews_passed'] ?? 0) === 4, 'passed reviews must be counted');
    assertHealth((int) ($health['reviews_open'] ?? 0) === 2, 'open reviews must be counted');
    assertHealth((int) ($health['candidates_reviewed'] ?? 0) === 1, 'reviewed candidates must be counted');
    assertHealth((int) ($health['candidates_total'] ?? 0) === 30, 'total candidates must be counted');
    assertHealth((int) ($health['devices_rebutted'] ?? 0) === 5, 'rebutted devices must be counted');
    assertHealth((int) ($health['devices_demoted'] ?? 0) === 6, 'demoted devices must be counted');
    assertHealth(
        (int) ($health['client_failures_on_reviewed_candidates'] ?? 0) === 333,
        'client failures on reviewed candidates must be counted'
    );
    assertHealth(
        (int) ($health['witness_losses'][0]['witnesses_eligible'] ?? -1) === 0,
        'the witness pool must be able to report zero eligible witnesses'
    );

    // The healthy case must NOT alarm, or the alarm is noise and will be ignored.
    $calm = new Database();
    $calm->canned = [
        'review_states' => [['state' => 'passed', 'total' => 2], ['state' => 'failed', 'total' => 1]],
        'open' => 0,
        'candidates_total' => 30,
        'reviewed_candidates' => [['candidate_key_hash' => str_repeat('d', 64)]],
        'client_failures' => 0,
        'witness_losses' => [],
    ];
    $quiet = (new UltraCandidateReviewService($calm, static fn(): int => 1_900_000_000))->reviewHealth(86400);
    assertHealth(($quiet['alarms'] ?? ['x']) === [], 'a detector with a real negative and no witness loss must not alarm');

    echo "ultra_detection_health_contract_test: OK" . PHP_EOL;
}
