<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

final class TenantQueryPlanDatabase
{
    /** @var array<int, array{sql:string,params:array<string,mixed>}> */
    public array $queries = [];

    /**
     * @param array<int, array{id:int}> $tenantForwards
     * @param array<int, array<string,mixed>> $events
     */
    public function __construct(
        private array $tenantForwards,
        private array $events = []
    ) {
    }

    /** @return array<int, array<string,mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        if (str_contains($sql, 'SELECT f.id')) {
            return $this->tenantForwards;
        }
        if (str_contains($sql, 'FROM bond_leg_reputation_events e')) {
            return $this->events;
        }
        fail('unexpected query: ' . $sql);
    }
}

require_once __DIR__ . '/../src/Support/BondLegReputationService.php';

$db = new TenantQueryPlanDatabase(
    [['id' => 7], ['id' => 12], ['id' => 7], ['id' => 0]],
    [['id' => 91, 'forward_id' => 12]]
);
$service = new \Leikwan\Master\Support\BondLegReputationService($db);
$events = $service->recentEvents(['user_id' => 33], 50);

if ($events !== [['id' => 91, 'forward_id' => 12]]) {
    fail('tenant event query must return the event rows');
}
if (count($db->queries) !== 2) {
    fail('tenant event query must resolve ownership before reading events');
}

$ownershipQuery = $db->queries[0];
if (!str_contains($ownershipQuery['sql'], 'INNER JOIN services s ON s.id = f.service_id')
    || !str_contains($ownershipQuery['sql'], 'WHERE s.user_id = :user_id')
    || $ownershipQuery['params'] !== ['user_id' => 33]) {
    fail('tenant forward ownership query is malformed');
}

$eventQuery = $db->queries[1];
if (str_contains($eventQuery['sql'], 'JOIN forwards') || str_contains($eventQuery['sql'], 'JOIN services')) {
    fail('event query must not join the multi-million-row event table through tenant tables');
}
if (!str_contains($eventQuery['sql'], 'e.forward_id IN (:tenant_forward_id_0, :tenant_forward_id_1)')) {
    fail('event query must use the resolved tenant forward allowlist');
}
if ($eventQuery['params'] !== ['tenant_forward_id_0' => 7, 'tenant_forward_id_1' => 12]) {
    fail('event query must bind only deduplicated tenant forward ids');
}
if (!str_contains($eventQuery['sql'], 'ORDER BY e.observed_at DESC, e.id DESC LIMIT 50')) {
    fail('event query must preserve newest-first bounded results');
}

$narrowDb = new TenantQueryPlanDatabase([['id' => 7], ['id' => 12]], [['id' => 92]]);
$narrowService = new \Leikwan\Master\Support\BondLegReputationService($narrowDb);
$narrowService->recentEvents(['user_id' => 33, 'forward_id' => 12], 10);
if (($narrowDb->queries[1]['params'] ?? null) !== ['tenant_forward_id_0' => 12]) {
    fail('explicit forward filter must stay inside the tenant allowlist');
}

$rejectedDb = new TenantQueryPlanDatabase([['id' => 7]]);
$rejectedService = new \Leikwan\Master\Support\BondLegReputationService($rejectedDb);
if ($rejectedService->recentEvents(['user_id' => 33, 'forward_id' => 12], 10) !== []
    || count($rejectedDb->queries) !== 1) {
    fail('out-of-scope forward must be rejected before querying events');
}

$emptyDb = new TenantQueryPlanDatabase([]);
$emptyService = new \Leikwan\Master\Support\BondLegReputationService($emptyDb);
if ($emptyService->recentEvents(['user_id' => 33], 10) !== [] || count($emptyDb->queries) !== 1) {
    fail('tenant without forwards must not query the event table');
}

echo "ok\n";
