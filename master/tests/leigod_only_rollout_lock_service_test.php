<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/master/src/Support/LeigodOnlyRolloutLockService.php';

final class RolloutLockDb
{
    public array $calls = [];
    public array $fetchQueue = [];
    public array $executeQueue = [];
    public ?Throwable $insertThrowable = null;
    public int $insertId = 55;
    public function fetchOne(string $sql, array $params = []): ?array { $this->calls[] = ['fetchOne',$sql,$params]; return array_shift($this->fetchQueue); }
    public function insert(string $table, array $data): int { $this->calls[] = ['insert',$table,$data]; if ($this->insertThrowable) throw $this->insertThrowable; return $this->insertId; }
    public function execute(string $sql, array $params = []): int { $this->calls[] = ['execute',$sql,$params]; return array_shift($this->executeQueue) ?? 1; }
}
function lockRow(string $owner = 'o', string $fence = 'f', string $expires = '2099-01-01 00:00:00', string $state='held'): array { return ['scope'=>'global','lock_key'=>'rollout','owner_token'=>$owner,'fencing_token'=>$fence,'lease_expires_at'=>$expires,'lock_state'=>$state,'artifact_snapshot_hash'=>str_repeat('a',64)]; }
$db = new RolloutLockDb();
$svc = new \Leikwan\Master\Support\LeigodOnlyRolloutLockService($db);

$svc->acquire('global','rollout','owner1','fence1',str_repeat('a',64),60);
if (($db->calls[1][0] ?? '') !== 'insert' || ($db->calls[1][1] ?? '') !== 'leigod_only_rollout_locks') fail('absent acquire must insert');
$data = $db->calls[1][2];
foreach (['scope','lock_key','owner_token','fencing_token','artifact_snapshot_hash','write_hash','lease_expires_at'] as $key) if (!isset($data[$key])) fail('missing lock insert field ' . $key);

$db = new RolloutLockDb(); $db->fetchQueue[] = lockRow('other','fence','2099-01-01 00:00:00'); $svc = new \Leikwan\Master\Support\LeigodOnlyRolloutLockService($db);
try { $svc->acquire('global','rollout','owner2','fence2',str_repeat('a',64),60); } catch (\RuntimeException $e) { if (!str_contains($e->getMessage(),'rollout_lock_held')) fail('wrong held message'); }
if (count($db->calls) !== 1) fail('held unexpired acquire must reject before side effects');

$db = new RolloutLockDb(); $db->fetchQueue[] = lockRow('old','oldf','2000-01-01 00:00:00'); $db->executeQueue[] = 1; $svc = new \Leikwan\Master\Support\LeigodOnlyRolloutLockService($db);
$svc->acquire('global','rollout','owner3','fence3',str_repeat('b',64),60);
$call = $db->calls[1]; if ($call[0] !== 'execute' || !str_contains($call[1], 'UPDATE leigod_only_rollout_locks') || !str_contains($call[1], 'WHERE scope = :scope') || !str_contains($call[1], 'lock_key = :lock_key') || !str_contains($call[1], 'lease_expires_at <=')) fail('expired acquire must CAS update');

foreach (['release','releaseExpired','renew'] as $method) {
    $db = new RolloutLockDb(); $db->executeQueue[] = 0; $svc = new \Leikwan\Master\Support\LeigodOnlyRolloutLockService($db);
    try { $method === 'renew' ? $svc->renew('global','rollout','owner','fence',60) : ($method === 'release' ? $svc->release('global','rollout','owner','fence') : $svc->releaseExpired('global','rollout')); } catch (\RuntimeException $e) { if (str_contains($e->getMessage(),'exactly_one_row_required')) continue; fail('wrong exact count message'); }
    fail('expected exact count failure for ' . $method);
}

$db = new RolloutLockDb(); $db->fetchQueue[] = lockRow('owner','fence','2099-01-01 00:00:00'); $svc = new \Leikwan\Master\Support\LeigodOnlyRolloutLockService($db); $svc->requireHeld('global','rollout','owner','fence');
$db = new RolloutLockDb(); $db->fetchQueue[] = lockRow('owner','wrong','2099-01-01 00:00:00'); $svc = new \Leikwan\Master\Support\LeigodOnlyRolloutLockService($db);
try { $svc->requireHeld('global','rollout','owner','fence'); } catch (\RuntimeException $e) { if (str_contains($e->getMessage(),'rollout_lock_not_held')) { echo "ok\n"; return; } }
fail('requireHeld must reject wrong fence');
