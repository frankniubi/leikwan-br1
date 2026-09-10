<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
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
                    'tenant_label' => 'CAN to HKG',
                    'ultra_destination_zone' => 'hong-kong',
                ]];
            }
            return [];
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            return 1;
        }

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM ultra_client_leases')
                && (int) ($params['client_id'] ?? 0) === 7
                && (int) ($params['forward_id'] ?? 0) === 42
                && hash_equals(hash('sha256', 'device-a'), (string) ($params['device_hash'] ?? ''))
                && hash_equals(hash('sha256', 'lease-a'), (string) ($params['lease_hash'] ?? ''))) {
                return ['id' => 1];
            }
            return null;
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

    function failUltraCandidateFeedback(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertUltraCandidateFeedback(bool $condition, string $message): void
    {
        if (!$condition) {
            failUltraCandidateFeedback($message);
        }
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-ultra-feedback-env-');
    if ($envPath === false) {
        failUltraCandidateFeedback('failed to create env file');
    }
    file_put_contents($envPath, "APP_ENV=test\n");
    $scope = [
        'source_id' => 'source-a',
        'line_id' => 347,
        'ingress_zone' => 'guangzhou',
        'destination_zone' => 'hong-kong',
        'protocol' => 'tcp',
    ];
    $now = 1_900_000_000;
    $reputation = new UltraCandidateReputationService(null, static function () use (&$now): int {
        return $now;
    });
    $reputation->observeFailure($scope, 'relay_connect_timeout', 'seed-observer', 'seed');
    $hash = UltraCandidateReputationService::candidateKeyHash($scope);
    $otherScope = $scope;
    $otherScope['line_id'] = 197;
    $otherHash = UltraCandidateReputationService::candidateKeyHash($otherScope);
    $reputation->observeFailure($otherScope, 'relay_connect_timeout', 'seed-observer', 'seed-other');
    $status = new UltraClientStatusService(new Config($envPath), new Database(), static fn(): int => $now, $reputation);

    $accepted = $status->ingestFeedback(7, 'device-a', [
        [
            'forward_id' => 42,
            'candidate_key_hash' => $hash,
            'lease_hash' => hash('sha256', 'lease-a'),
            'protocol' => 'tcp',
            'outcome' => 'failure',
            'reason' => 'relay_connect_timeout',
            'evidence_id' => 'feedback-1',
            'detected_ingress_zone' => 'guangzhou',
        ],
        [
            'forward_id' => 42,
            'candidate_key_hash' => $hash,
            'lease_hash' => hash('sha256', 'lease-a'),
            'protocol' => 'tcp',
            'outcome' => 'failure',
            'reason' => 'process_stderr',
            'evidence_id' => 'feedback-stderr',
            'detected_ingress_zone' => 'guangzhou',
        ],
        [
            'forward_id' => 99,
            'candidate_key_hash' => $hash,
            'lease_hash' => hash('sha256', 'lease-a'),
            'protocol' => 'tcp',
            'outcome' => 'failure',
            'reason' => 'relay_connect_timeout',
            'evidence_id' => 'feedback-unassigned',
            'detected_ingress_zone' => 'guangzhou',
        ],
        [
            'forward_id' => 42,
            'candidate_key_hash' => $otherHash,
            'lease_hash' => hash('sha256', 'not-issued-to-this-device'),
            'protocol' => 'tcp',
            'outcome' => 'failure',
            'reason' => 'relay_connect_timeout',
            'evidence_id' => 'feedback-unbound',
            'detected_ingress_zone' => 'guangzhou',
        ],
    ]);
    assertUltraCandidateFeedback($accepted === 1, 'feedback accepted invalid or duplicate rows');
    $row = $reputation->stateFor($scope);
    assertUltraCandidateFeedback(($row['consecutive_failures'] ?? 0) === 2, 'valid feedback did not increment failure state');

    $mismatched = $status->ingestFeedback(7, 'device-a', [[
        'forward_id' => 42,
        'candidate_key_hash' => $hash,
        'protocol' => 'udp',
        'outcome' => 'failure',
        'reason' => 'udp_association_failed',
        'evidence_id' => 'feedback-protocol-mismatch',
        'detected_ingress_zone' => 'guangzhou',
    ]]);
    assertUltraCandidateFeedback($mismatched === 0, 'protocol-mismatched feedback was accepted');

    $dualScope = $scope;
    $dualScope['protocol'] = 'tcp+udp';
    $dualHash = UltraCandidateReputationService::candidateKeyHash($dualScope);
    $reputation->observeFailure($dualScope, 'relay_connect_timeout', 'seed-observer', 'dual-seed');
    $dualAccepted = $status->ingestFeedback(7, 'device-a', [[
        'forward_id' => 42,
        'candidate_key_hash' => $dualHash,
        'lease_hash' => hash('sha256', 'lease-a'),
        'protocol' => 'tcp',
        'outcome' => 'success',
        'reason' => 'canary_probe_ok',
        'evidence_id' => 'feedback-dual-protocol',
        'detected_ingress_zone' => 'guangzhou',
    ]]);
    assertUltraCandidateFeedback($dualAccepted === 1, 'tcp feedback for a tcp+udp candidate was rejected');

    $unclassifiedSuccess = $status->ingestFeedback(7, 'device-a', [[
        'forward_id' => 42,
        'candidate_key_hash' => $hash,
        'lease_hash' => hash('sha256', 'lease-a'),
        'protocol' => 'tcp',
        'outcome' => 'success',
        'reason' => 'arbitrary_success',
        'evidence_id' => 'feedback-unclassified-success',
        'detected_ingress_zone' => 'guangzhou',
    ]]);
    assertUltraCandidateFeedback($unclassifiedSuccess === 0, 'unclassified success feedback was accepted');

    $source = (string) file_get_contents(__DIR__ . '/../src/Support/UltraClientStatusService.php');
    foreach (['ingestFeedback', 'candidate_key_hash', 'lease_hash', 'ultra_client_leases', 'process_stderr'] as $needle) {
        assertUltraCandidateFeedback(str_contains($source, $needle), 'missing feedback boundary: ' . $needle);
    }
    $controller = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
    assertUltraCandidateFeedback(str_contains($controller, 'ultra_candidate_feedback'), 'heartbeat feedback field is not wired');
    assertUltraCandidateFeedback(str_contains($controller, 'ingestFeedback'), 'heartbeat does not submit candidate feedback');
    @unlink($envPath);
    echo "ok\n";
}
