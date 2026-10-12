<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\HostedNodeController;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Support\AccessPolicyService;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\DeviceControlService;
use Leikwan\Master\Support\ProvisioningService;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** The access policy (geo lock, blacklist) is covered by its own tests; here it records that it ran. */
final class RecordingAccessPolicy extends AccessPolicyService
{
    public int $calls = 0;

    public function __construct()
    {
    }

    public function authorizeClientRequest(array $client, Request $request, string $touchType = 'request', ?string $deviceUuid = null): array
    {
        $this->calls++;
        return [];
    }
}

$env = tempnam(sys_get_temp_dir(), 'lw-hosted-api-');
file_put_contents($env, "DB_DRIVER=sqlite\nDB_NAME=:memory:\nULTRA_TOKEN_KEY=" . str_repeat('12', 32) . "\nFORWARD_API_RATE_LIMIT_DIR=" . sys_get_temp_dir() . '/lw-hosted-api-rate-' . getmypid() . "\n");
$config = new Config($env);
@unlink($env);
$db = new Database($config);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->setPdo($pdo);

$pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, agent_token TEXT, status TEXT, group_id INTEGER NULL, online_device_limit INTEGER, name TEXT)');
$pdo->exec('CREATE TABLE client_device_controls (id INTEGER PRIMARY KEY, client_id INTEGER, device_id TEXT, state TEXT, trust_state TEXT,
    identity_key_hash TEXT, command_version INTEGER, blocked_until TEXT NULL, block_origin TEXT NULL, release_policy TEXT NULL,
    reason_code TEXT NULL, last_seen_at TEXT NULL, updated_at TEXT NULL)');
$pdo->exec('CREATE TABLE android_device_sessions (id INTEGER PRIMARY KEY, client_id INTEGER, device_id TEXT, session_hash TEXT,
    expires_at TEXT, revoked_at TEXT NULL, command_version INTEGER)');
$pdo->exec('CREATE TABLE client_device_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, device_id TEXT, device_name TEXT,
    platform TEXT, client_version TEXT, os_release TEXT, user_agent TEXT, last_ip TEXT, first_seen_at TEXT, last_seen_at TEXT,
    last_pull_at TEXT, last_heartbeat_at TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE forwards (id INTEGER PRIMARY KEY, service_id INTEGER, enabled INTEGER, user_deleted_at TEXT NULL,
    listen_port INTEGER, target_host TEXT, target_port INTEGER, line_client_id INTEGER NULL, line_client_group_id INTEGER NULL,
    line_client_group_ids_json TEXT NULL)');
$pdo->exec('CREATE TABLE client_hosted_nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, name TEXT NOT NULL,
    protocol TEXT NOT NULL, sealed_config TEXT NOT NULL, fingerprint TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1,
    source TEXT NOT NULL DEFAULT \'console\', created_by_user_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE (client_id, fingerprint))');
$pdo->exec('CREATE TABLE client_hosted_node_pairings (client_id INTEGER NOT NULL, forward_id INTEGER NOT NULL, node_id INTEGER NOT NULL,
    source TEXT NOT NULL DEFAULT \'console\', updated_at TEXT NOT NULL, PRIMARY KEY (client_id, forward_id))');

$pdo->exec("INSERT INTO clients VALUES (7, 'token-seven', 'active', NULL, 5, 'Seven')");
$pdo->exec("INSERT INTO clients VALUES (8, 'token-eight', 'active', NULL, 5, 'Eight')");
$pdo->exec("INSERT INTO forwards VALUES (101, 30, 1, NULL, 21001, '198.51.100.7', 1080, 7, NULL, NULL)");
$pdo->exec("INSERT INTO forwards VALUES (201, 31, 1, NULL, 22001, '198.51.100.7', 1080, 8, NULL, NULL)");

$deviceSeven = 'android-client-7-' . str_repeat('a', 32);
$deviceEight = 'android-client-8-' . str_repeat('b', 32);
$future = gmdate('Y-m-d H:i:s', time() + 3600);
foreach ([[7, $deviceSeven, 'session-seven'], [8, $deviceEight, 'session-eight']] as $i => [$clientId, $deviceId, $session]) {
    $pdo->prepare("INSERT INTO client_device_controls (id, client_id, device_id, state, trust_state, identity_key_hash, command_version)
        VALUES (:id, :client_id, :device_id, 'active', 'trusted', :identity, 0)")
        ->execute(['id' => $i + 1, 'client_id' => $clientId, 'device_id' => $deviceId, 'identity' => str_repeat('c', 64)]);
    $pdo->prepare('INSERT INTO android_device_sessions (client_id, device_id, session_hash, expires_at, command_version)
        VALUES (:client_id, :device_id, :hash, :expires, 0)')
        ->execute(['client_id' => $clientId, 'device_id' => $deviceId, 'hash' => hash('sha256', $session), 'expires' => $future]);
}

$policy = new RecordingAccessPolicy();
$controller = new HostedNodeController(
    $config,
    $db,
    new ProvisioningService($config, $db),
    accessPolicy: $policy,
    deviceControls: DeviceControlService::fromConfig($config, $db),
);

$headers = static fn(string $token, string $deviceId, string $session, string $platform = 'android'): array => [
    'Authorization' => 'Bearer ' . $token,
    'X-Device-Platform' => $platform,
    'X-Device-Id' => $deviceId,
    'X-Device-Session' => $session,
];
$seven = $headers('token-seven', $deviceSeven, 'session-seven');
$call = static function (string $method, string $action, array $headers, array $body = [], array $params = []) use ($controller): array {
    $request = new Request($method, '/api/client/hosted-nodes', [], $body, $headers, '');
    $response = $controller->{$action}($request, $params);
    $decoded = json_decode($response->body(), true);
    return [$response->status(), is_array($decoded) ? $decoded : [], $response->headers()];
};
$expectGate = static function (callable $action, string $code, string $label): void {
    try {
        $action();
    } catch (ApiException $exception) {
        assertSame($code, $exception->getMessage(), $label);
        return;
    }
    fail($label . ': expected ' . $code);
};

// --- gates -------------------------------------------------------------------
$expectGate(fn() => $call('GET', 'clientList', ['X-Device-Platform' => 'android']), 'client_agent_auth_failed', 'token required');
$expectGate(fn() => $call('GET', 'clientList', $headers('token-seven', $deviceSeven, 'session-seven', 'windows')), 'device_untrusted', 'non-Android platforms are refused');
$expectGate(fn() => $call('GET', 'clientList', $headers('token-seven', $deviceSeven, 'wrong-session')), 'device_untrusted', 'device session required');
$expectGate(fn() => $call('GET', 'clientList', $headers('token-seven', $deviceEight, 'session-eight')), 'device_untrusted', 'another client\'s device is not trusted for this client');
$pdo->exec("UPDATE client_device_controls SET trust_state = 'pending' WHERE client_id = 7");
$expectGate(fn() => $call('GET', 'clientList', $seven), 'device_untrusted', 'untrusted device refused');
$pdo->exec("UPDATE client_device_controls SET trust_state = 'trusted' WHERE client_id = 7");

// --- list / create -----------------------------------------------------------
[$status, $body, $responseHeaders] = $call('GET', 'clientList', $seven);
assertSame(200, $status, 'empty list status');
assertSame(['ok' => true, 'revision' => '', 'nodes' => [], 'pairings' => []], $body, 'empty listing');
assertSame(true, str_contains((string) ($responseHeaders['Cache-Control'] ?? ''), 'no-store'), 'listing is not cacheable');
if ($policy->calls < 1) {
    fail('the access policy must run for hosted-node calls');
}
$sessionsSeen = (int) $pdo->query('SELECT COUNT(*) FROM client_device_sessions WHERE client_id = 7')->fetchColumn();
assertSame(1, $sessionsSeen, 'the call counts as an online device session');

[$status, $body] = $call('POST', 'clientCreate', $seven, ['uri' => 'socks5://alice:wonder@198.51.100.7:1080#Home']);
assertSame(200, $status, 'create status');
assertSame(true, $body['created'], 'created flag');
$nodeId = (int) $body['node_id'];
assertSame('Home', $body['nodes'][0]['name'], 'listing returned after create');
assertSame('wonder', $body['nodes'][0]['settings']['password'], 'trusted device receives credentials');
assertSame('socks5://alice:wonder@198.51.100.7:1080#Home', $body['nodes'][0]['uri'], 'canonical share link');
assertSame([['forward_id' => 101, 'node_id' => $nodeId, 'source' => 'target_match']], $body['pairings'], 'target match pairing');
assertSame('device', $body['nodes'][0]['source'], 'device uploads are marked');

[$status, $body] = $call('POST', 'clientCreate', $seven, ['uri' => 'socks5://alice:wonder@198.51.100.7:1080#Again', 'pair_forward_id' => 101]);
assertSame(false, $body['created'], 'repeat upload is idempotent');
assertSame([['forward_id' => 101, 'node_id' => $nodeId, 'source' => 'explicit']], $body['pairings'], 'upload can pair explicitly');

[$status, $body] = $call('POST', 'clientCreate', $seven, ['uri' => 'vless://not-a-uuid@a.example.com:443', 'password' => 'never-echoed']);
assertSame(422, $status, 'invalid node status');
assertSame(['ok' => false, 'error' => 'hosted_node_invalid', 'field' => 'uuid'], $body, 'invalid node error names the field only');

// --- isolation ---------------------------------------------------------------
$eight = $headers('token-eight', $deviceEight, 'session-eight');
[$status, $body] = $call('GET', 'clientList', $eight);
assertSame([], $body['nodes'], 'another client sees none of these nodes');
[$status, $body] = $call('DELETE', 'clientDelete', $eight, [], ['id' => (string) $nodeId]);
assertSame(404, $status, 'another client cannot delete');
[$status, $body] = $call('PUT', 'clientPair', $eight, ['node_id' => $nodeId], ['forwardId' => '201']);
assertSame([404, 'hosted_node_not_found'], [$status, $body['error'] ?? null], 'another client cannot pair with this node');
[$status, $body] = $call('PUT', 'clientPair', $seven, ['node_id' => $nodeId], ['forwardId' => '201']);
assertSame([404, 'hosted_node_forward_not_found'], [$status, $body['error'] ?? null], 'cannot pair another client\'s forward');

// --- update / pair / delete --------------------------------------------------
[$status, $body] = $call('PUT', 'clientUpdate', $seven, ['name' => 'Renamed'], ['id' => (string) $nodeId]);
assertSame([200, 'Renamed', 2], [$status, $body['nodes'][0]['name'], $body['nodes'][0]['version']], 'rename via API');
[$status, $body] = $call('PUT', 'clientPair', $seven, ['node_id' => null], ['forwardId' => '101']);
assertSame('target_match', $body['pairings'][0]['source'], 'clearing an explicit pairing');
[$status, $body] = $call('DELETE', 'clientDelete', $seven, [], ['id' => (string) $nodeId]);
assertSame([200, [], []], [$status, $body['nodes'], $body['pairings']], 'delete via API');
[$status, $body] = $call('DELETE', 'clientDelete', $seven, [], ['id' => (string) $nodeId]);
assertSame(404, $status, 'delete twice');

$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/HostedNodeController.php');
if (preg_match('/\bmb_[a-z_]+\s*\(|error_log\s*\(|getMessage\(\)\s*\)\s*;?\s*\/\/\s*echo/', $source) === 1) {
    fail('controller must not use mbstring or log');
}

echo "ok\n";
