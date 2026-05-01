<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

final class TestDatabase
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $tables = [];

    /** @var array<string, int> */
    private array $autoIds = [];

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $nextId = ($this->autoIds[$table] ?? 1);
        $data['id'] = $nextId;
        $this->autoIds[$table] = $nextId + 1;
        $this->tables[$table][] = $data;
        return $nextId;
    }

    /**
     * @return mixed
     */
    public function transaction(callable $callback)
    {
        return $callback($this);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tableRows(string $table): array
    {
        return array_values($this->tables[$table] ?? []);
    }
}

$repoRoot = dirname(__DIR__, 2);
$servicePath = $repoRoot . '/master/src/Support/BondLegReputationService.php';
if (!is_file($servicePath)) {
    fail('expected BondLegReputationService.php to exist');
}
require_once $servicePath;

$db = new TestDatabase();
$service = new \Leikwan\Master\Support\BondLegReputationService($db);
$service->ingestClientSnapshots(1, [[
    'observer_type' => 'client',
    'observer_id' => 1,
    'forward_id' => 26,
    'updated_at' => '2026-04-19T14:30:00Z',
    'legs' => [[
        'endpoint_id' => 185,
        'node_id' => 5,
        'host' => '8.163.90.235',
        'tcp' => [
            'state' => 'degraded',
            'score' => 0.21,
            'effective_weight' => 0.10,
            'delivered_bytes_ewma' => 18231.4,
            'penalty_ewma' => 0.81,
        ],
        'udp' => [
            'state' => 'probation',
            'score' => 0.08,
            'effective_weight' => 0.05,
            'delivered_bytes_ewma' => 7211.2,
            'penalty_ewma' => 0.92,
        ],
        'recent_events' => [[
            'protocol' => 'tcp',
            'from' => 'healthy',
            'to' => 'degraded',
            'reason' => 'control_socket_closed_unexpectedly',
            'observed_at' => '2026-04-19T14:29:33Z',
        ]],
    ]],
]]);
$service->ingestSidecarSnapshot(4, [
    'observer_type' => 'sidecar',
    'observer_id' => 4,
    'forward_id' => 26,
    'updated_at' => '2026-04-19T14:31:00Z',
    'legs' => [[
        'endpoint_id' => 0,
        'node_id' => 0,
        'host' => '8.163.90.235',
        'tcp' => [
            'state' => 'healthy',
            'score' => 0.91,
            'effective_weight' => 1.0,
            'delivered_bytes_ewma' => 512312.4,
            'penalty_ewma' => 0.02,
        ],
        'udp' => [
            'state' => 'healthy',
            'score' => 0.88,
            'effective_weight' => 1.0,
            'delivered_bytes_ewma' => 412312.4,
            'penalty_ewma' => 0.03,
        ],
        'recent_events' => [[
            'protocol' => 'udp',
            'from' => 'probation',
            'to' => 'healthy',
            'reason' => 'probe_recovered',
            'observed_at' => '2026-04-19T14:30:40Z',
        ]],
    ]],
]);

$current = $service->latestGlobalRows(10);
$events = $service->recentEvents([], 10);

if (count($current) < 4) {
    fail('expected at least four current reputation rows, got ' . count($current));
}

$tcpRow = null;
$udpRow = null;
foreach ($current as $row) {
    if (($row['observer_type'] ?? '') === 'client' && ($row['protocol'] ?? '') === 'tcp') {
        $tcpRow = $row;
    }
    if (($row['observer_type'] ?? '') === 'client' && ($row['protocol'] ?? '') === 'udp') {
        $udpRow = $row;
    }
}

if (!is_array($tcpRow) || ($tcpRow['state'] ?? '') !== 'degraded') {
    fail('expected persisted client tcp degraded row');
}

if (!is_array($udpRow) || ($udpRow['state'] ?? '') !== 'probation') {
    fail('expected persisted client udp probation row');
}

if (($tcpRow['effective_weight'] ?? null) != 0.10) {
    fail('expected tcp effective_weight to be persisted');
}

if (($udpRow['effective_weight'] ?? null) != 0.05) {
    fail('expected udp effective_weight to be persisted');
}

if ($events === []) {
    fail('expected recent reputation events to be recorded');
}

$firstEvent = $events[0];
if (($firstEvent['to_state'] ?? '') === '') {
    fail('expected event to_state to be populated');
}

echo "ok\n";
