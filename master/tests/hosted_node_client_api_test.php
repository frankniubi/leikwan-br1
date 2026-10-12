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
$expectGate(fn() => $call('GET', 'clientList', $headers('token-seven', 'ios-client-' . str_repeat('a', 32), 'session-seven', 'ios')), 'device_untrusted', 'platforms without an official client are refused');
$expectGate(fn() => $call('GET', 'clientList', ['Authorization' => 'Bearer token-seven', 'X-Device-Id' => 'windows-client-7-' . str_repeat('d', 16)]), 'device_untrusted', 'a missing platform is refused');
$expectGate(fn() => $call('GET', 'clientList', $headers('token-seven', $deviceSeven, 'wrong-session', 'windows-desktop')), 'device_untrusted', 'an attested Android id is held to the Android gate whatever platform it claims');
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

// --- batch upload ("sync all local nodes to cloud") ---------------------------
[$status, $body] = $call('POST', 'clientBatchCreate', $seven, ['nodes' => [
    ['ref' => 'local-1', 'uri' => 'socks5://alice:wonder@198.51.100.7:1080#Dup'],
    ['ref' => 'local-2', 'uri' => 'ss://' . rtrim(base64_encode('aes-256-gcm:pw'), '=') . '@ss.example.com:8388#SS'],
    ['ref' => 'local-3', 'uri' => 'vmess://e30='],
    ['ref' => 'local-4'],
]]);
assertSame(200, $status, 'batch status');
assertSame(['ref' => 'local-1', 'ok' => true, 'node_id' => $nodeId, 'created' => false], $body['results'][0], 'batch dedupes an already hosted node');
assertSame([true, true], [$body['results'][1]['ok'], $body['results'][1]['created']], 'batch creates a new node');
assertSame(['ref' => 'local-3', 'ok' => false, 'error' => 'hosted_node_invalid', 'field' => 'uri'], $body['results'][2], 'unsupported protocol is reported per entry');
assertSame(['ref' => 'local-4', 'ok' => false, 'error' => 'hosted_node_invalid', 'field' => 'uri'], $body['results'][3], 'missing uri is reported per entry');
assertSame(2, count($body['nodes']), 'batch returns the listing');
$ssNodeId = (int) $body['results'][1]['node_id'];
[$status, $body] = $call('POST', 'clientBatchCreate', $seven, ['nodes' => array_fill(0, 33, ['uri' => 'socks5://a.example.com:1'])]);
assertSame([422, 'nodes'], [$status, $body['field'] ?? null], 'oversized batch refused');
[$status, $body] = $call('DELETE', 'clientDelete', $seven, [], ['id' => (string) $ssNodeId]);
assertSame(200, $status, 'cleanup batch node');

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

// --- desktop clients (Windows / macOS) -------------------------------------------
// Same identity as the desktop config pull: agent token, access policy, the
// platform's own per-install device id, online device limit.
$desktop = static fn(string $token, string $platform, ?string $deviceId): array => array_filter([
    'Authorization' => 'Bearer ' . $token,
    'X-Device-Platform' => $platform,
    'X-Device-Id' => $deviceId,
], static fn($value): bool => $value !== null);
$windowsSeven = 'windows-client-7-' . str_repeat('e', 16);
$macSeven = 'macos-client-' . str_repeat('f', 32);
$linuxSeven = 'linux-' . str_repeat('1', 32);
$expectGate(fn() => $call('GET', 'clientList', $desktop('token-seven', 'windows-desktop', null)), 'device_untrusted', 'desktop needs an explicit device id, not a server-derived one');
$expectGate(fn() => $call('GET', 'clientList', $desktop('token-seven', 'windows-desktop', 'windows-client-8-' . str_repeat('e', 16))), 'device_untrusted', 'a Windows device id is bound to its client');
$expectGate(fn() => $call('GET', 'clientList', $desktop('token-seven', 'windows-desktop', $macSeven)), 'device_untrusted', 'device id must match the claimed platform');
$expectGate(fn() => $call('GET', 'clientList', $desktop('token-seven', 'macos-desktop', 'legacy-' . str_repeat('a', 40))), 'device_untrusted', 'reserved legacy ids are refused');
$expectGate(fn() => $call('GET', 'clientList', $desktop('token-seven', 'macos-desktop', 'macos-client-XYZ')), 'device_untrusted', 'malformed mac id refused');
$expectGate(fn() => $call('GET', 'clientList', $desktop('wrong-token', 'windows-desktop', $windowsSeven)), 'client_agent_auth_failed', 'desktop needs the agent token');

$callsBefore = $policy->calls;
[$status, $body] = $call('POST', 'clientCreate', $desktop('token-seven', 'windows-desktop', $windowsSeven), ['uri' => 'socks5://198.51.100.9:1080#Desk']);
assertSame([200, true], [$status, $body['created']], 'Windows desktop uploads a node');
$deskNodeId = (int) $body['node_id'];
[$status, $body] = $call('GET', 'clientList', $desktop('token-seven', 'macos-desktop', $macSeven));
assertSame([200, 'Desk'], [$status, $body['nodes'][0]['name'] ?? null], 'macOS desktop of the same client receives it');
// Linux headless has no nodes feature yet: node credentials are not exposed to it.
$expectGate(fn() => $call('GET', 'clientList', $desktop('token-seven', 'linux', $linuxSeven)), 'device_untrusted', 'Linux headless is refused');
$expectGate(fn() => $call('GET', 'clientList', $desktop('token-seven', 'windows-desktop', $linuxSeven)), 'device_untrusted', 'a linux id cannot pose as Windows');
[$status, $body] = $call('GET', 'clientList', $seven);
assertSame('Desk', $body['nodes'][0]['name'] ?? null, 'Android device of the client sees the desktop upload');
if ($policy->calls < $callsBefore + 2) {
    fail('the access policy must run for desktop hosted-node calls');
}
foreach ([$windowsSeven, $macSeven] as $deskId) {
    $seen = (int) $pdo->query("SELECT COUNT(*) FROM client_device_sessions WHERE client_id = 7 AND device_id = '" . $deskId . "'")->fetchColumn();
    assertSame(1, $seen, 'desktop call is a visible device session: ' . $deskId);
}
$linuxSessions = (int) $pdo->query("SELECT COUNT(*) FROM client_device_sessions WHERE device_id = '" . $linuxSeven . "'")->fetchColumn();
assertSame(0, $linuxSessions, 'a refused Linux call does not register a device session');
[$status, $body] = $call('GET', 'clientList', $desktop('token-eight', 'windows-desktop', 'windows-client-8-' . str_repeat('e', 16)));
assertSame([200, []], [$status, $body['nodes']], 'another client\'s desktop sees none of these nodes');

// The online device limit applies exactly as in the desktop pull.
$pdo->exec('UPDATE clients SET online_device_limit = 1 WHERE id = 7');
$expectGate(
    fn() => $call('GET', 'clientList', $desktop('token-seven', 'windows-desktop', 'windows-client-7-' . str_repeat('9', 16))),
    'client_online_limit_reached',
    'a new desktop beyond the online device limit is refused'
);
$pdo->exec('UPDATE clients SET online_device_limit = 5 WHERE id = 7');
[$status] = $call('DELETE', 'clientDelete', $desktop('token-seven', 'windows-desktop', $windowsSeven), [], ['id' => (string) $deskNodeId]);
assertSame(200, $status, 'desktop deletes its node');

$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/HostedNodeController.php');
if (preg_match('/\bmb_[a-z_]+\s*\(|error_log\s*\(|getMessage\(\)\s*\)\s*;?\s*\/\/\s*echo/', $source) === 1) {
    fail('controller must not use mbstring or log');
}

echo "ok\n";
