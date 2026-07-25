<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

final class GuardDatabase
{
    public int $writes = 0;

    public function fetchOne(string $sql, array $params = []): ?array
    {
        if (str_contains($sql, 'FROM forwards')) {
            return (int) ($params['id'] ?? 0) === 26 ? ['id' => 26] : null;
        }
        return null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->writes++;
        return 1;
    }

    public function insert(string $table, array $data): int
    {
        $this->writes++;
        return $this->writes;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }
}

require_once __DIR__ . '/../src/Support/BondLegReputationService.php';

$db = new GuardDatabase();
$service = new \Leikwan\Master\Support\BondLegReputationService($db);
$snapshot = static fn(int $forwardId): array => [[
    'forward_id' => $forwardId,
    'updated_at' => '2026-07-25T03:00:00Z',
    'legs' => [[
        'endpoint_id' => 100,
        'host' => '127.0.0.1',
        'tcp' => ['state' => 'healthy'],
        'recent_events' => [[
            'protocol' => 'tcp',
            'to' => 'healthy',
            'observed_at' => '2026-07-25T03:00:00Z',
        ]],
    ]],
]];

$service->ingestClientSnapshots(1, $snapshot(999));
if ($db->writes !== 0) {
    fail('deleted forward telemetry must not create reputation rows');
}

$service->ingestClientSnapshots(1, $snapshot(26));
if ($db->writes === 0) {
    fail('existing forward telemetry should still create reputation rows');
}

echo "ok\n";
