<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen('Leikwan\\Master\\'))) . '.php';
    if (is_file($path)) { require_once $path; }
});
use Leikwan\Master\Config;
use Leikwan\Master\Support\ExternalUoTPool;
use Leikwan\Master\Support\ApiException;
function checkPool(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$dir = sys_get_temp_dir() . '/uot-pool-test-' . bin2hex(random_bytes(8));
putenv('EXTERNAL_UOT_POOL_STATE_DIR=' . $dir);
putenv('EXTERNAL_UOT_POOL_TTL_SECONDS=45');
$nodes = [
    ['id' => 'la1', 'target' => 'la1.test:7443', 'secret' => str_repeat('a', 32), 'origins' => ['origin.test:7443']],
    ['id' => 'la2', 'target' => 'la2.test:7443', 'secret' => str_repeat('b', 32), 'origins' => ['origin.test:7443'], 'reliable' => true],
];
$forwards = [['id' => 17, 'external_uot' => ['enabled' => true, 'sidecar_target' => 'origin.test:7443', 'token' => 'unchanged']]];
try {
    putenv('EXTERNAL_UOT_POOL_JSON=');
    $config = new Config('/nonexistent');
    checkPool((new ExternalUoTPool($config))->assign(1, 'd', $forwards) === $forwards, 'disabled compatibility');
    putenv('EXTERNAL_UOT_POOL_JSON=' . json_encode($nodes));
    $pool = new ExternalUoTPool($config);
    checkPool($pool->authenticate('la1', 'wrong') === null, 'bad secret accepted');
    checkPool($pool->authenticate('../la1', str_repeat('a', 32)) === null, 'invalid id accepted');
    checkPool($pool->authenticate('la1', str_repeat('a', 32)) !== null, 'valid secret rejected');
    try { $pool->assign(1, 'd', $forwards, 100); throw new RuntimeException('empty pool allowed'); }
    catch (ApiException $e) { checkPool($e->statusCode() === 503, 'wrong unavailable status'); }
    $pool->heartbeat('la1', true, str_repeat('c', 64), 100);
    $pool->heartbeat('la2', true, str_repeat('d', 64), 100);
    $seen = [];
    for ($id = 1; $id <= 100; $id++) {
        $a = $pool->assign($id, 'd', $forwards, 120);
        checkPool($a === $pool->assign($id, 'd', $forwards, 120), 'unstable assignment');
        $seen[$a[0]['external_uot']['sidecar_target']] = true;
        checkPool($a[0]['external_uot']['token'] === 'unchanged', 'token changed');
        checkPool(array_keys($a[0]['external_uot']) === array_keys($forwards[0]['external_uot']), 'new client key emitted');
    }
    checkPool(count($seen) === 2, 'no distribution');
    $pool->heartbeat('la1', false, str_repeat('c', 64), 121);
    checkPool($pool->assign(1, 'd', $forwards, 121)[0]['external_uot']['sidecar_target'] === 'la2.test:7443', 'failed node selected');
    try { $pool->assign(1, 'd', $forwards, 146); throw new RuntimeException('stale pool allowed'); }
    catch (ApiException $e) {}
    try { $pool->assign(1, 'd', $forwards, 99); throw new RuntimeException('future heartbeat allowed'); }
    catch (ApiException $e) {}
    $unmanaged = $forwards;
    $unmanaged[0]['external_uot']['sidecar_target'] = 'other.test:7443';
    $logFile = $dir . '/pool-diagnostic.log';
    $previousLog = ini_get('error_log');
    ini_set('error_log', $logFile);
    $unmanagedResult = $pool->assign(1, 'd', $unmanaged, 146);
    ini_set('error_log', $previousLog === false ? '' : $previousLog);
    $diagnostic = is_file($logFile) ? (string) file_get_contents($logFile) : '';
    checkPool($unmanagedResult === $unmanaged, 'unmanaged changed');
    // An origin no node lists is left as-is, so the forward would fail later at
    // HELLO admission with an opaque "forward is not allowed". That silent gap
    // must at least name the origin and the forward.
    checkPool(str_contains($diagnostic, 'other.test:7443') && str_contains($diagnostic, 'forward 17'),
        'uncovered origin did not report the gap');
    $reliable = $forwards;
    $reliable[0]['external_uot']['delivery_mode'] = 'reliable_datagram_v1';
    $pool->heartbeat('la1', true, str_repeat('c', 64), 150);
    $pool->heartbeat('la2', false, str_repeat('d', 64), 150);
    try { $pool->assign(1, 'd', $reliable, 150); throw new RuntimeException('reliable downgraded'); }
    catch (ApiException $e) {}
    $combos = [['id' => 1, 'external_uot_sidecar_target' => 'origin.test:7443', 'external_uot_token' => 'same'],
        ['id' => 2, 'external_uot_sidecar_target' => 'other.test:7443']];
    $mapped = $pool->combinationsForNode($nodes[1], $combos);
    checkPool(count($mapped) === 1 && $mapped[0]['external_uot_token'] === 'same'
        && $mapped[0]['external_uot_sidecar_target'] === 'la2.test:7443', 'standby config not scoped');
    putenv('EXTERNAL_UOT_POOL_JSON={bad');
    try { new ExternalUoTPool($config); throw new RuntimeException('bad JSON allowed'); }
    catch (JsonException $e) {}
    echo "PASS pool auth, stable distribution, failover, stale/future heartbeat, protocol compatibility, reliable gating, config scope, uncovered-origin visibility\n";
} finally {
    foreach (glob($dir . '/*.json') ?: [] as $file) { unlink($file); }
    if (is_dir($dir)) { rmdir($dir); }
    foreach (['EXTERNAL_UOT_POOL_JSON', 'EXTERNAL_UOT_POOL_STATE_DIR', 'EXTERNAL_UOT_POOL_TTL_SECONDS'] as $key) { putenv($key); }
}
