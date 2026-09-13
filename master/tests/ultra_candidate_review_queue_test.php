<?php
declare(strict_types=1);

/**
 * The review queue must not leak a row in "running" forever.
 *
 * A review is handed to the source host, which may go away mid-probe - a home
 * source host that sleeps is the normal case, not an exception. While such a row
 * sits in "running" it is never handed out again, and enqueueQuarantined() reads
 * any open review as a reason not to queue another one, so a candidate that is
 * still quarantined would have no way back at all. These tests pin both halves:
 * retry while the attempt budget lasts, close it (without blaming anyone) when
 * the budget is gone.
 */

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $reviews = [];
        /** @var array<int, array<string, mixed>> */
        public array $observers = [];
        /** @var array<int, string> */
        public array $statements = [];

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            $this->statements[] = $sql;
            if (str_contains($sql, 'FROM ultra_candidate_reputation_current c')) {
                return [];
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
            foreach ($this->reviews as $row) {
                if ((int) $row['id'] === (int) ($params['id'] ?? 0)) {
                    return $row;
                }
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $this->statements[] = $sql;
            if (str_contains($sql, 'SET state = :pending, started_at = NULL')) {
                $touched = 0;
                foreach ($this->reviews as $index => $row) {
                    if ((string) $row['state'] === 'running'
                        && $row['started_at'] !== null
                        && (string) $row['started_at'] < (string) $params['cutoff']
                        && (int) $row['attempts'] < (int) $params['max_attempts']) {
                        $this->reviews[$index]['state'] = 'pending';
                        $this->reviews[$index]['started_at'] = null;
                        $touched++;
                    }
                }
                return $touched;
            }
            if (str_contains($sql, 'SET state = :failed, completed_at')) {
                $touched = 0;
                foreach ($this->reviews as $index => $row) {
                    if ((string) $row['state'] === 'running'
                        && $row['started_at'] !== null
                        && (string) $row['started_at'] < (string) $params['cutoff']
                        && (int) $row['attempts'] >= (int) $params['max_attempts']) {
                        $this->reviews[$index]['state'] = 'failed';
                        $this->reviews[$index]['completed_at'] = $params['completed_at'];
                        $this->reviews[$index]['result_json'] = $params['result_json'];
                        $touched++;
                    }
                }
                return $touched;
            }
            if (str_contains($sql, 'SET demoted_at = CASE WHEN strikes - 1 < :threshold')) {
                $touched = 0;
                foreach ($this->observers as $index => $row) {
                    if ((int) $row['strikes'] <= 0) {
                        continue;
                    }
                    if ($row['last_decay_at'] !== null && (string) $row['last_decay_at'] >= (string) $params['cutoff']) {
                        continue;
                    }
                    if ($row['last_rebuttal_at'] !== null && (string) $row['last_rebuttal_at'] >= (string) $params['cutoff']) {
                        continue;
                    }
                    $this->observers[$index]['strikes'] = (int) $row['strikes'] - 1;
                    if ($this->observers[$index]['strikes'] < (int) $params['threshold']) {
                        $this->observers[$index]['demoted_at'] = null;
                    }
                    $this->observers[$index]['last_decay_at'] = $params['now'];
                    $touched++;
                }
                return $touched;
            }
            if (str_contains($sql, 'SET state = :running, started_at = :started_at, attempts = attempts + 1')) {
                $touched = 0;
                foreach ($this->reviews as $index => $row) {
                    if ((int) $row['id'] === (int) ($params['id'] ?? 0) && (string) $row['state'] === 'pending') {
                        $this->reviews[$index]['state'] = 'running';
                        $this->reviews[$index]['started_at'] = $params['started_at'];
                        $this->reviews[$index]['attempts'] = (int) $row['attempts'] + 1;
                        $touched++;
                    }
                }
                return $touched;
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

    $now = 1_900_000_000;

    function failReviewQueue(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertReviewQueue(bool $condition, string $message): void
    {
        if (!$condition) {
            failReviewQueue($message);
        }
    }

    $clock = static function () use (&$now): int {
        return $now;
    };

    // 1. An over-budget review is closed, not left running.
    $db = new Database();
    $db->reviews[] = [
        'id' => 7,
        'candidate_key_hash' => str_repeat('a', 64),
        'source_id' => 'source-a',
        'line_id' => 325,
        'protocol' => 'tcp+udp',
        'state' => UltraCandidateReviewService::STATE_RUNNING,
        'attempts' => UltraCandidateReviewService::MAX_ATTEMPTS,
        'started_at' => gmdate('Y-m-d H:i:s', $now - UltraCandidateReviewService::STALE_RUNNING_SECONDS - 60),
    ];
    $service = new UltraCandidateReviewService($db, $clock);
    $claimed = $service->requestForSource('source-a', 2);

    assertReviewQueue($claimed === [], 'an abandoned review must not be handed out again');
    assertReviewQueue(
        $db->reviews[0]['state'] === UltraCandidateReviewService::STATE_FAILED,
        'a review that used up its attempts must be closed as failed, not left running'
    );
    $result = json_decode((string) $db->reviews[0]['result_json'], true);
    assertReviewQueue(is_array($result) && ($result['abandoned'] ?? false) === true, 'the closed review must record why');
    assertReviewQueue(($db->reviews[0]['completed_at'] ?? null) !== null, 'the closed review must be timestamped');

    $touchedOthers = array_filter(
        $db->statements,
        static fn(string $sql): bool => str_contains($sql, 'strikes = strikes + 1')
            || str_contains($sql, 'last_reason')
    );
    assertReviewQueue(
        $touchedOthers === [],
        'a review nobody reported back on must not strike a device or move reputation'
    );

    // 2. A stale review that still has budget is retried.
    $db = new Database();
    $db->reviews[] = [
        'id' => 9,
        'candidate_key_hash' => str_repeat('b', 64),
        'source_id' => 'source-a',
        'line_id' => 453,
        'protocol' => 'tcp+udp',
        'state' => UltraCandidateReviewService::STATE_RUNNING,
        'attempts' => 1,
        'started_at' => gmdate('Y-m-d H:i:s', $now - UltraCandidateReviewService::STALE_RUNNING_SECONDS - 60),
    ];
    $service = new UltraCandidateReviewService($db, $clock);
    $claimed = $service->requestForSource('source-a', 2);
    assertReviewQueue(count($claimed) === 1, 'a stale review with attempts left must be handed out again');
    assertReviewQueue((int) $claimed[0]['review_id'] === 9, 'the same review must be retried');
    assertReviewQueue((int) $db->reviews[0]['attempts'] === 2, 'the retry must consume an attempt');

    // 3. A review that is still inside its window is left alone.
    $db = new Database();
    $db->reviews[] = [
        'id' => 11,
        'candidate_key_hash' => str_repeat('c', 64),
        'source_id' => 'source-a',
        'line_id' => 574,
        'protocol' => 'tcp+udp',
        'state' => UltraCandidateReviewService::STATE_RUNNING,
        'attempts' => 1,
        'started_at' => gmdate('Y-m-d H:i:s', $now - 60),
    ];
    $service = new UltraCandidateReviewService($db, $clock);
    $claimed = $service->requestForSource('source-a', 2);
    assertReviewQueue($claimed === [], 'a review still inside its window must not be disturbed');
    assertReviewQueue(
        $db->reviews[0]['state'] === UltraCandidateReviewService::STATE_RUNNING
            && (int) $db->reviews[0]['attempts'] === 1,
        'a review still inside its window must keep its claim'
    );

// 4. A source that runs no reviews is handed none.
    $db = new Database();
    $db->reviews[] = [
        'id' => 13,
        'candidate_key_hash' => str_repeat('d', 64),
        'source_id' => 'source-a',
        'line_id' => 689,
        'protocol' => 'tcp+udp',
        'state' => UltraCandidateReviewService::STATE_PENDING,
        'attempts' => 0,
        'started_at' => null,
        'requested_at' => gmdate('Y-m-d H:i:s', $now - 60),
    ];
    $service = new UltraCandidateReviewService($db, $clock);
    $claimed = $service->requestForSource('source-a', 0);
    assertReviewQueue($claimed === [], 'a source that runs no reviews must not be handed one');
    assertReviewQueue(
        $db->reviews[0]['state'] === UltraCandidateReviewService::STATE_PENDING
            && (int) $db->reviews[0]['attempts'] === 0,
        'a review nobody was handed must keep its attempts'
    );


    // 5. A strike decays once the device stops being disproved, so a client bug
    //    cannot demote a device for good.
    $db = new Database();
    $db->observers[] = [
        'observer_hash' => str_repeat('e', 64),
        'client_id' => 3,
        'device_id' => 'windows-client-3',
        'strikes' => 2,
        'demoted_at' => gmdate('Y-m-d H:i:s', $now - 40 * 86400),
        'last_rebuttal_at' => gmdate('Y-m-d H:i:s', $now - 30 * 86400),
        'last_decay_at' => null,
    ];
    $db->observers[] = [
        'observer_hash' => str_repeat('f', 64),
        'client_id' => 3,
        'device_id' => 'windows-client-3-fresh',
        'strikes' => 1,
        'demoted_at' => null,
        'last_rebuttal_at' => gmdate('Y-m-d H:i:s', $now - 60),
        'last_decay_at' => null,
    ];
    $service = new UltraCandidateReviewService($db, $clock);
    $service->requestForSource('source-a', 0);
    assertReviewQueue((int) $db->observers[0]['strikes'] === 1, 'a stale strike must decay');
    assertReviewQueue($db->observers[0]['demoted_at'] === null, 'a decayed device must get its standing back');
    assertReviewQueue((int) $db->observers[1]['strikes'] === 1, 'a fresh rebuttal must protect the strike');

    echo "ultra_candidate_review_queue_test: OK" . PHP_EOL;
}