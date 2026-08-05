<?php
declare(strict_types=1);

function failLeaseSeed(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function assertLeaseSeedSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        failLeaseSeed($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertLeaseSeedThrows(string $expected, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        assertLeaseSeedSame($expected, $throwable->getMessage(), $message);
        return;
    }
    failLeaseSeed($message . ': expected exception ' . $expected);
}

final class LeaseSeedFakeDatabase
{
    /** @var array<int, array<string, mixed>> */
    public array $catalog = [];
    /** @var array<int, array<string, mixed>> */
    public array $desiredPairs = [];
    /** @var array<int, array<string, mixed>> */
    public array $leases = [];
    /** @var array<int, array{method:string,sql:string}> */
    public array $calls = [];
    public bool $inTransaction = false;

    public function transaction(callable $callback): mixed
    {
        if ($this->inTransaction) {
            throw new RuntimeException('unexpected_nested_seed_transaction');
        }
        $this->calls[] = ['method' => 'transaction', 'sql' => ''];
        $this->inTransaction = true;
        try {
            return $callback($this);
        } finally {
            $this->inTransaction = false;
        }
    }

    /** @param array<string, mixed> $params */
    public function fetchAll(string $sql, array $params = []): array
    {
        $this->calls[] = ['method' => 'fetchAll', 'sql' => $sql];
        if (str_contains($sql, 'FROM nodes')) {
            if (str_contains($sql, 'SELECT DISTINCT')
                && str_contains($sql, 'ORDER BY ll.sort_order')
                && !str_contains($sql, 'll.sort_order AS line_sort_order')) {
                throw new RuntimeException('distinct_order_column_not_selected');
            }
            return $this->desiredPairs;
        }
        if (str_contains($sql, 'FROM leigod_execution_leases')) {
            return array_values($this->leases);
        }
        if (str_contains($sql, 'FROM leigod_lines')) {
            return array_values($this->catalog);
        }
        throw new RuntimeException('unexpected_seed_query');
    }
}

final class LeaseSeedFakeService
{
    /** @var array<int, array{line_id:int,node_id:int}> */
    public array $calls = [];

    public function __construct(private LeaseSeedFakeDatabase $db)
    {
    }

    /** @return array<string, mixed> */
    public function acquireForNode(int $catalogLineId, int $nodeId): array
    {
        if (!$this->db->inTransaction) {
            throw new RuntimeException('seed_write_outside_transaction');
        }
        $this->calls[] = ['line_id' => $catalogLineId, 'node_id' => $nodeId];
        return [
            'leigod_line_id' => $catalogLineId,
            'owner_type' => 'node',
            'owner_id' => $nodeId,
            'state' => 'pending',
        ];
    }
}

/** @return array<int, array<string, mixed>> */
function leaseSeedCatalog(): array
{
    return [
        1 => ['id' => 1, 'allow_node_execution' => 1, 'sort_order' => 20],
        2 => ['id' => 2, 'allow_node_execution' => 1, 'sort_order' => 10],
        3 => ['id' => 3, 'allow_node_execution' => 0, 'sort_order' => 30],
    ];
}

/** @return array<string, mixed> */
function leaseSeedExisting(int $lineId, string $ownerType, int $ownerId, string $expiresAt, string $state = 'active'): array
{
    return [
        'leigod_line_id' => $lineId,
        'lease_id' => '00000000-0000-4000-8000-' . str_pad((string) $lineId, 12, '0', STR_PAD_LEFT),
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
        'owner_device_id' => $ownerType === 'node' ? null : 'linux-device-a',
        'generation' => 1,
        'state' => $state,
        'expires_at' => $expiresAt,
        'ready_deadline_at' => $expiresAt,
    ];
}

$cliPath = __DIR__ . '/../cli/seed-leigod-execution-leases.php';
if (!is_file($cliPath)) {
    failLeaseSeed('seed CLI is missing');
}
define('LEIGOD_EXECUTION_LEASE_SEED_LIBRARY_ONLY', true);
require_once $cliPath;

$defaults = leigodExecutionLeaseSeedParseArguments(['seed-leigod-execution-leases.php']);
assertLeaseSeedSame(false, $defaults['apply'], 'default mode is dry-run');
assertLeaseSeedSame(true, $defaults['dry_run'], 'default marks dry-run');

$explicitDryRun = leigodExecutionLeaseSeedParseArguments([
    'seed-leigod-execution-leases.php',
    '--dry-run',
]);
assertLeaseSeedSame(false, $explicitDryRun['apply'], 'explicit dry-run does not apply');

assertLeaseSeedThrows(
    'seed_apply_confirmation_required',
    static fn(): array => leigodExecutionLeaseSeedParseArguments([
        'seed-leigod-execution-leases.php',
        '--apply',
    ]),
    'apply requires confirmation'
);
assertLeaseSeedThrows(
    'seed_apply_confirmation_required',
    static fn(): array => leigodExecutionLeaseSeedParseArguments([
        'seed-leigod-execution-leases.php',
        '--apply',
        '--confirm=WRONG',
    ]),
    'apply rejects incorrect confirmation'
);
assertLeaseSeedThrows(
    'seed_confirm_requires_apply',
    static fn(): array => leigodExecutionLeaseSeedParseArguments([
        'seed-leigod-execution-leases.php',
        '--confirm=SEED-LEIGOD-LEASES',
    ]),
    'confirmation alone cannot apply'
);

$apply = leigodExecutionLeaseSeedParseArguments([
    'seed-leigod-execution-leases.php',
    '--apply',
    '--confirm=SEED-LEIGOD-LEASES',
]);
assertLeaseSeedSame(true, $apply['apply'], 'confirmed apply mode');
assertLeaseSeedSame(false, $apply['dry_run'], 'confirmed apply is not dry-run');

$now = 1_786_000_000;
$future = gmdate('Y-m-d H:i:s', $now + 90);
$past = gmdate('Y-m-d H:i:s', $now - 1);

$dryDb = new LeaseSeedFakeDatabase();
$dryDb->catalog = leaseSeedCatalog();
$dryDb->desiredPairs = [
    ['leigod_line_id' => 2, 'node_id' => 20],
    ['leigod_line_id' => 1, 'node_id' => 10],
];
$dryDb->leases = [2 => leaseSeedExisting(2, 'node', 20, $future)];
$dryService = new LeaseSeedFakeService($dryDb);
$originalTimezone = date_default_timezone_get();
date_default_timezone_set('Asia/Taipei');
$drySummary = leigodExecutionLeaseSeedRun($defaults, $dryDb, $dryService, static fn(): int => $now);
date_default_timezone_set($originalTimezone);
assertLeaseSeedSame([
    'catalog_lines' => 3,
    'desired_pairs' => 2,
    'seeded' => 0,
    'conflicts' => 0,
    'unchanged' => 1,
], $drySummary, 'dry-run audit counts');
assertLeaseSeedSame([], $dryService->calls, 'dry-run performs no lease writes');
assertLeaseSeedSame(
    'catalog_lines=3 desired_pairs=2 seeded=0 conflicts=0 unchanged=1',
    leigodExecutionLeaseSeedSummary($drySummary),
    'summary contains counts only'
);

$applyDb = new LeaseSeedFakeDatabase();
$applyDb->catalog = leaseSeedCatalog();
$applyDb->desiredPairs = $dryDb->desiredPairs;
$applyDb->leases = $dryDb->leases;
$applyService = new LeaseSeedFakeService($applyDb);
$applySummary = leigodExecutionLeaseSeedRun($apply, $applyDb, $applyService, static fn(): int => $now);
assertLeaseSeedSame([
    'catalog_lines' => 3,
    'desired_pairs' => 2,
    'seeded' => 1,
    'conflicts' => 0,
    'unchanged' => 1,
], $applySummary, 'apply counts created and unchanged leases');
assertLeaseSeedSame(
    [['line_id' => 1, 'node_id' => 10]],
    $applyService->calls,
    'apply creates only the missing desired lease'
);
assertLeaseSeedSame(1, count(array_filter(
    $applyDb->calls,
    static fn(array $call): bool => $call['method'] === 'transaction'
)), 'apply uses one outer transaction');

$duplicateDb = new LeaseSeedFakeDatabase();
$duplicateDb->catalog = leaseSeedCatalog();
$duplicateDb->desiredPairs = [
    ['leigod_line_id' => 1, 'node_id' => 10],
    ['leigod_line_id' => 1, 'node_id' => 11],
];
$duplicateService = new LeaseSeedFakeService($duplicateDb);
$duplicateSummary = leigodExecutionLeaseSeedRun($apply, $duplicateDb, $duplicateService, static fn(): int => $now);
assertLeaseSeedSame(1, $duplicateSummary['conflicts'], 'one catalog line on multiple nodes is one conflict');
assertLeaseSeedSame(0, $duplicateSummary['seeded'], 'conflicting apply seeds nothing');
assertLeaseSeedSame([], $duplicateService->calls, 'conflicting apply is all-or-nothing');

$ownerConflictDb = new LeaseSeedFakeDatabase();
$ownerConflictDb->catalog = leaseSeedCatalog();
$ownerConflictDb->desiredPairs = [['leigod_line_id' => 1, 'node_id' => 10]];
$ownerConflictDb->leases = [1 => leaseSeedExisting(1, 'client_device', 30, $future)];
$ownerConflictService = new LeaseSeedFakeService($ownerConflictDb);
$ownerConflict = leigodExecutionLeaseSeedRun($apply, $ownerConflictDb, $ownerConflictService, static fn(): int => $now);
assertLeaseSeedSame(1, $ownerConflict['conflicts'], 'live different owner is a conflict');
assertLeaseSeedSame([], $ownerConflictService->calls, 'live different owner is never preempted');

$expiredDb = new LeaseSeedFakeDatabase();
$expiredDb->catalog = leaseSeedCatalog();
$expiredDb->desiredPairs = [['leigod_line_id' => 1, 'node_id' => 10]];
$expiredDb->leases = [1 => leaseSeedExisting(1, 'node', 99, $past)];
$expiredService = new LeaseSeedFakeService($expiredDb);
$expiredSummary = leigodExecutionLeaseSeedRun($apply, $expiredDb, $expiredService, static fn(): int => $now);
assertLeaseSeedSame(0, $expiredSummary['conflicts'], 'expired different owner is replaceable');
assertLeaseSeedSame(1, $expiredSummary['seeded'], 'expired desired lease is reseeded');

$policyDb = new LeaseSeedFakeDatabase();
$policyDb->catalog = leaseSeedCatalog();
$policyDb->desiredPairs = [['leigod_line_id' => 3, 'node_id' => 30]];
$policyService = new LeaseSeedFakeService($policyDb);
$policySummary = leigodExecutionLeaseSeedRun($apply, $policyDb, $policyService, static fn(): int => $now);
assertLeaseSeedSame(1, $policySummary['conflicts'], 'node-forbidden catalog line blocks seeding');
assertLeaseSeedSame([], $policyService->calls, 'policy conflict performs no writes');

$joinedSql = implode("\n", array_column($applyDb->calls, 'sql'));
if (str_contains($joinedSql, 'LEIGOD_EXECUTION_LEASES_ENABLED') || str_contains($joinedSql, '.env')) {
    failLeaseSeed('seed CLI must not modify the rollout gate');
}

echo "ok\n";
