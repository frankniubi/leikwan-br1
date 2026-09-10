<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/master/src/Support/LeigodOnlyFencedPublishQueue.php';
require_once $repoRoot . '/master/src/Support/LeigodOnlyFencedPublishExecutor.php';

final class PubDb
{
    public array $calls = [];
    public array $fetchOneQueue = [];
    public array $executeQueue = [];

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->calls[] = ['fetchOne', $sql, $params];
        return array_shift($this->fetchOneQueue);
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->calls[] = ['execute', $sql, $params];
        return array_shift($this->executeQueue) ?? 1;
    }

    public function insert(string $table, array $data): int
    {
        $this->calls[] = ['insert', $table, $data];
        return 66;
    }

    public function transaction(callable $cb): mixed
    {
        $this->calls[] = ['transaction'];
        return $cb($this);
    }
}

final class Publisher
{
    public array $calls = [];

    public function publishFenced(array $job): void
    {
        $this->calls[] = $job;
    }

    public function reconcileForwardEndpoints(): void
    {
        fail('executor must not call generic reconcileForwardEndpoints');
    }
}

$desiredHash = str_repeat('a', 64);
$artifactHash = str_repeat('b', 64);
$publishFence = str_repeat('c', 64);
$ownerToken = str_repeat('d', 64);
$fencingToken = str_repeat('e', 64);
$identity = [
    'client_id' => 12,
    'client_device_session_id' => 99,
    'device_id' => 99,
    'desktop_client_session_id' => 77,
    'current_slot_key' => '47:12:99:77:desired-current',
];

$db = new PubDb();
$queue = new \Leikwan\Master\Support\LeigodOnlyFencedPublishQueue($db);
$jobHash = $queue->enqueue([
    'scope' => 'global',
    'forward_id' => 47,
    'client_id' => $identity['client_id'],
    'client_device_session_id' => $identity['client_device_session_id'],
    'device_id' => $identity['device_id'],
    'desktop_client_session_id' => $identity['desktop_client_session_id'],
    'current_slot_key' => $identity['current_slot_key'],
    'desired_generation' => 10,
    'desired_state_hash' => $desiredHash,
    'artifact_snapshot_hash' => $artifactHash,
    'publish_fence_token' => $publishFence,
    'owner_token' => $ownerToken,
    'fencing_token' => $fencingToken,
    'payload' => ['x' => 1],
    'plan_hash' => str_repeat('1', 64),
    'batch_hash' => str_repeat('2', 64),
    'affected_forward_ids' => [47],
]);
if (!is_string($jobHash) || strlen($jobHash) !== 64) fail('expected job hash');

$insert = $db->calls[0] ?? null;
if (($insert[0] ?? '') !== 'insert') fail('expected queue insert call');
if (($insert[1] ?? '') !== 'leigod_only_fenced_publish_jobs') fail('queue inserted wrong table');
$queuedRow = $insert[2];
foreach ([
    'client_id' => 12,
    'client_device_session_id' => 99,
    'desktop_client_session_id' => 77,
    'current_slot_key' => $identity['current_slot_key'],
    'desired_generation' => 10,
    'desired_state_hash' => $desiredHash,
    'artifact_snapshot_hash' => $artifactHash,
    'publish_fence_token' => $publishFence,
] as $field => $expected) {
    if ((string)($queuedRow[$field] ?? '') !== (string)$expected) fail('queue row mismatch ' . $field);
}
$queuedPayload = json_decode((string)($queuedRow['payload_json'] ?? ''), true);
if (!is_array($queuedPayload)) fail('queue payload malformed');
if (($queuedPayload['identity']['client_id'] ?? null) !== 12 || ($queuedPayload['identity']['client_device_session_id'] ?? null) !== 99 || ($queuedPayload['identity']['desktop_client_session_id'] ?? null) !== 77 || ($queuedPayload['identity']['current_slot_key'] ?? '') !== $identity['current_slot_key']) {
    fail('queue payload missing identity');
}
if (($queuedPayload['affected_forward_ids_json'] ?? []) !== [47]) fail('queue payload missing affected forward ids');
if (($queuedPayload['payload'] ?? null) !== ['x' => 1]) fail('queue payload missing body payload');
$joined = json_encode($db->calls);
foreach ([
    'leigod_only_fenced_publish_jobs',
    'client_id',
    'client_device_session_id',
    'desktop_client_session_id',
    'current_slot_key',
] as $needle) {
    if (!str_contains((string)$joined, $needle)) fail('queue missing ' . $needle);
}

$db = new PubDb();
$db->fetchOneQueue[] = $queuedRow;
$db->executeQueue[] = 1;
$db->fetchOneQueue[] = [
    'scope' => 'global',
    'forward_id' => 47,
    'client_id' => 12,
    'client_device_session_id' => 99,
    'desktop_client_session_id' => 77,
    'current_slot_key' => $identity['current_slot_key'],
    'desired_generation' => 10,
    'desired_state_hash' => $desiredHash,
    'artifact_snapshot_hash' => $artifactHash,
    'owner_token' => $ownerToken,
    'fencing_token' => $fencingToken,
    'state' => 'fenced',
    'superseded_at' => null,
];
$db->executeQueue[] = 1;
$publisher = new Publisher();
$exec = new \Leikwan\Master\Support\LeigodOnlyFencedPublishExecutor($db, $publisher);
$exec->execute([
    'scope' => 'global',
    'job_hash' => $jobHash,
    'forward_id' => 47,
    'client_id' => 12,
    'client_device_session_id' => 99,
    'device_id' => 99,
    'desktop_client_session_id' => 77,
    'current_slot_key' => $identity['current_slot_key'],
    'desired_generation' => 10,
    'desired_state_hash' => $desiredHash,
    'publish_fence_token' => $publishFence,
    'owner_token' => $ownerToken,
    'fencing_token' => $fencingToken,
]);
if (count($publisher->calls) !== 1) fail('executor must call fence-aware publish wrapper once');
$published = $publisher->calls[0];
foreach ([
    'job_hash' => $jobHash,
    'client_id' => 12,
    'client_device_session_id' => 99,
    'desktop_client_session_id' => 77,
    'current_slot_key' => $identity['current_slot_key'],
] as $field => $expected) {
    if ((string)($published[$field] ?? '') !== (string)$expected) fail('published job mismatch ' . $field);
}
$publishedPayload = json_decode((string)($published['payload_json'] ?? ''), true);
if (!is_array($publishedPayload) || ($publishedPayload['identity']['current_slot_key'] ?? '') !== $identity['current_slot_key']) {
    fail('published payload lost identity');
}
$fence = $published['desired_state_fence'] ?? null;
if (!is_array($fence)) fail('publishFenced must include desired state fence');
foreach ([
    'client_id' => 12,
    'client_device_session_id' => 99,
    'desktop_client_session_id' => 77,
    'current_slot_key' => $identity['current_slot_key'],
    'state' => 'fenced',
] as $field => $expected) {
    if ((string)($fence[$field] ?? '') !== (string)$expected) fail('fence row mismatch ' . $field);
}
$joined = json_encode($db->calls);
foreach ([
    'leigod_only_fenced_publish_jobs',
    'leigod_only_forward_desired_state_fences',
    'COALESCE(client_id,0) = :client_id',
    'COALESCE(client_device_session_id,0) = :device_id',
    'COALESCE(desktop_client_session_id,0) = :desktop_client_session_id',
    'current_slot_key',
] as $needle) {
    if (!str_contains((string)$joined, $needle)) fail('executor missing fenced transition ' . $needle);
}

$db = new PubDb();
$queue = new \Leikwan\Master\Support\LeigodOnlyFencedPublishQueue($db);
try {
    $queue->enqueue([
        'scope' => 'global',
        'forward_id' => 47,
        'client_device_session_id' => 99,
        'device_id' => 98,
        'desktop_client_session_id' => 77,
        'desired_generation' => 10,
        'desired_state_hash' => $desiredHash,
        'artifact_snapshot_hash' => $artifactHash,
        'publish_fence_token' => $publishFence,
        'owner_token' => $ownerToken,
        'fencing_token' => $fencingToken,
    ]);
} catch (\InvalidArgumentException $e) {
    if (str_contains($e->getMessage(), 'client_device_session_id_mismatch')) {
        goto queue_mismatch_ok;
    }
    fail('wrong queue mismatch message');
}
fail('expected queue identity mismatch rejection');
queue_mismatch_ok:

$db = new PubDb();
$db->fetchOneQueue[] = $queuedRow;
$publisher = new Publisher();
$exec = new \Leikwan\Master\Support\LeigodOnlyFencedPublishExecutor($db, $publisher);
try {
    $exec->execute([
        'scope' => 'global',
        'job_hash' => $jobHash,
        'forward_id' => 47,
        'client_id' => 12,
        'client_device_session_id' => 99,
        'device_id' => 99,
        'desktop_client_session_id' => 77,
        'current_slot_key' => $identity['current_slot_key'] . ':stale',
        'desired_generation' => 10,
        'desired_state_hash' => $desiredHash,
        'publish_fence_token' => $publishFence,
        'owner_token' => $ownerToken,
        'fencing_token' => $fencingToken,
    ]);
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'current_slot_key_mismatch')) {
        goto executor_slot_mismatch_ok;
    }
    fail('wrong current slot mismatch message');
}
fail('expected executor current slot mismatch rejection');
executor_slot_mismatch_ok:

$db = new PubDb();
$db->fetchOneQueue[] = $queuedRow;
$db->executeQueue[] = 0;
$publisher = new Publisher();
$exec = new \Leikwan\Master\Support\LeigodOnlyFencedPublishExecutor($db, $publisher);
try {
    $exec->execute([
        'scope' => 'global',
        'job_hash' => $jobHash,
        'forward_id' => 47,
        'client_id' => 12,
        'client_device_session_id' => 99,
        'device_id' => 99,
        'desktop_client_session_id' => 77,
        'current_slot_key' => $identity['current_slot_key'],
        'desired_generation' => 10,
        'desired_state_hash' => $desiredHash,
        'publish_fence_token' => $publishFence,
        'owner_token' => $ownerToken,
        'fencing_token' => $fencingToken,
    ]);
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'exactly_one_row_required') && $publisher->calls === []) {
        echo 'ok' . PHP_EOL;
        return;
    }
    fail('wrong lease exact-count error');
}
fail('expected exact-count lease rejection');
