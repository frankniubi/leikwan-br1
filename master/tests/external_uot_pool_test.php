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

// Pool heartbeat end to end on SQLite: the config holds only live forwards and
// a persisted HMAC secret, and the heartbeat's "usage" lands in the ledger.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP pool heartbeat usage ingest: pdo_sqlite unavailable\n";
    exit(0);
}
$dir = sys_get_temp_dir() . '/uot-pool-usage-' . bin2hex(random_bytes(8));
putenv('EXTERNAL_UOT_POOL_STATE_DIR=' . $dir);
putenv('EXTERNAL_UOT_POOL_JSON=' . json_encode([['id' => 'la1', 'target' => 'la1.test:7443', 'secret' => str_repeat('a', 32), 'origins' => ['origin.test:7443']]]));
try {
    $controller = new Leikwan\Master\Controllers\ExternalUotConfigController(new Config('/nonexistent'));
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
    (new ReflectionProperty(Leikwan\Master\Controllers\BaseController::class, 'db'))->getValue($controller)->setPdo($pdo);
    $future = gmdate('Y-m-d H:i:s', time() + 86400);
    $past = gmdate('Y-m-d H:i:s', time() - 60);
    $pdo->exec("CREATE TABLE forward_combinations (id INTEGER PRIMARY KEY, external_uot_enabled INTEGER, external_uot_sidecar_target TEXT,
     external_uot_token TEXT, external_uot_hmac_secret TEXT, external_uot_queue_packets INTEGER, external_uot_keepalive_seconds INTEGER,
     external_uot_reconnect_grace_seconds INTEGER, external_uot_frame_payload_max INTEGER);
    CREATE TABLE forwards (id INTEGER PRIMARY KEY, forward_combination_id INTEGER, service_id INTEGER, line_client_id INTEGER,
     target_host TEXT, target_port INTEGER, enabled INTEGER, user_deleted_at TEXT, balance_suspended_at TEXT);
    CREATE TABLE services (id INTEGER PRIMARY KEY, status TEXT, expire_at TEXT, bandwidth_limit_mbps INTEGER);
    CREATE TABLE clients (id INTEGER PRIMARY KEY, speed_limit_mbps INTEGER);
    CREATE TABLE external_uot_usage_counters (source TEXT, instance_id TEXT, forward_id INTEGER, to_target_bytes INTEGER,
     from_target_bytes INTEGER, updated_at TEXT, PRIMARY KEY (source, instance_id, forward_id));
    CREATE TABLE external_uot_usage_daily (forward_id INTEGER, usage_date TEXT, service_id INTEGER, sidecar_bytes INTEGER DEFAULT 0,
     client_bytes INTEGER DEFAULT 0, billed_extra_bytes INTEGER DEFAULT 0, reconciled_at TEXT, updated_at TEXT, PRIMARY KEY (forward_id, usage_date));
    INSERT INTO forward_combinations VALUES (5, 1, 'origin.test:7443', 'static-5', NULL, 256, 10, 30, 65507);
    INSERT INTO services VALUES (1, 'active', NULL, 100), (2, 'active', '$past', 100), (3, 'suspended', '$future', 100), (4, 'active', '$future', 0);
    INSERT INTO clients VALUES (9, 40);
    INSERT INTO forwards VALUES
     (101, 5, 1, 9, '203.0.113.1', 53, 1, NULL, NULL),
     (102, 5, 2, NULL, '203.0.113.2', 53, 1, NULL, NULL),
     (103, 5, 3, NULL, '203.0.113.3', 53, 1, NULL, NULL),
     (104, 5, 4, NULL, '203.0.113.4', 53, 0, NULL, NULL),
     (105, 5, 4, NULL, '203.0.113.5', 53, 1, '$past', NULL),
     (106, 5, 4, NULL, '203.0.113.6', 53, 1, NULL, '$past'),
     (107, 5, 4, NULL, '203.0.113.7', 53, 1, NULL, NULL),
     (108, 5, 99, NULL, '203.0.113.8', 53, 1, NULL, NULL);");
    $poolRequest = static fn (string $method, array $body = []) => new Leikwan\Master\Http\Request(
        $method, '/api/external-uot/pool/la1/config', [], $body, ['Authorization' => 'Bearer ' . str_repeat('a', 32)], ''
    );
    $document = json_decode($controller->poolNode($poolRequest('GET'), ['id' => 'la1'])->body(), true);
    $secret = (string) $pdo->query('SELECT external_uot_hmac_secret FROM forward_combinations WHERE id = 5')->fetchColumn();
    checkPool(preg_match('/^[0-9a-f]{64}$/', $secret) === 1, 'pool render did not persist a secret');
    checkPool(array_keys($document['config']['forwards']) === [101, 107], 'pool published a non-live forward');
    checkPool($document['config']['forwards'][101] === ['secret' => $secret, 'token' => 'static-5',
        'targets' => ['203.0.113.1:53', '1.1.1.1:53'], 'rate_limit_mbps' => 40], 'pool binding shape');
    checkPool(!isset($document['config']['forwards'][107]['rate_limit_mbps']), 'unlimited forward got a rate limit');
    $again = json_decode($controller->poolNode($poolRequest('GET'), ['id' => 'la1'])->body(), true);
    checkPool($again['config_hash'] === $document['config_hash'], 'pool config hash not stable across renders');
    $usage = ['version' => 1, 'instance' => str_repeat('c', 32), 'forwards' => [
        '101' => ['to_target_bytes' => 1000, 'from_target_bytes' => 4000], '999' => ['to_target_bytes' => 1, 'from_target_bytes' => 1]]];
    $response = $controller->poolNode($poolRequest('POST', ['healthy' => true, 'config_hash' => $document['config_hash'], 'usage' => $usage]), ['id' => 'la1']);
    checkPool($response->status() === 200, 'heartbeat with usage refused');
    $row = $pdo->query('SELECT * FROM external_uot_usage_daily WHERE forward_id = 101')->fetch();
    checkPool(is_array($row) && (int) $row['sidecar_bytes'] === 5000 && (int) $row['service_id'] === 1 && $row['usage_date'] === gmdate('Y-m-d'),
        'heartbeat usage not recorded');
    checkPool((int) $pdo->query("SELECT COUNT(*) FROM external_uot_usage_counters WHERE source = 'pool:la1'")->fetchColumn() === 1, 'counters not keyed by pool:<id>');
    $usage['forwards']['101'] = ['to_target_bytes' => 1500, 'from_target_bytes' => 4000];
    $controller->poolNode($poolRequest('POST', ['healthy' => true, 'config_hash' => str_repeat('0', 64), 'usage' => $usage]), ['id' => 'la1']);
    checkPool((int) $pdo->query('SELECT sidecar_bytes FROM external_uot_usage_daily WHERE forward_id = 101')->fetchColumn() === 5500,
        'usage on a stale-config heartbeat was dropped');
    echo "PASS pool heartbeat: live-only bindings with HMAC secret and rate limit, usage ingested as pool:<id>\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($dir)) { rmdir($dir); }
    foreach (['EXTERNAL_UOT_POOL_JSON', 'EXTERNAL_UOT_POOL_STATE_DIR'] as $key) { putenv($key); }
}
