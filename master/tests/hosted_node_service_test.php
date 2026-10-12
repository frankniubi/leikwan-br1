<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\HostedNodeService;
use Leikwan\Master\Support\HostedNodeSpec;
use Leikwan\Master\Support\HostedNodeVault;

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

function assertThrows(callable $action, string $code, string $label): ApiException
{
    try {
        $action();
    } catch (ApiException $exception) {
        assertSame($code, $exception->getMessage(), $label);
        return $exception;
    }
    fail($label . ': expected ' . $code);
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-hosted-nodes-');
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\nULTRA_TOKEN_KEY=" . str_repeat('ab', 32) . "\n");
$config = new Config($envPath);
@unlink($envPath);
$db = new Database($config);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->setPdo($pdo);

$pdo->exec('CREATE TABLE client_hosted_nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, name TEXT NOT NULL,
    protocol TEXT NOT NULL, sealed_config TEXT NOT NULL, fingerprint TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1,
    source TEXT NOT NULL DEFAULT \'console\', created_by_user_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    UNIQUE (client_id, fingerprint))');
$pdo->exec('CREATE TABLE client_hosted_node_pairings (client_id INTEGER NOT NULL, forward_id INTEGER NOT NULL, node_id INTEGER NOT NULL,
    source TEXT NOT NULL DEFAULT \'console\', updated_at TEXT NOT NULL, PRIMARY KEY (client_id, forward_id))');
$pdo->exec('CREATE TABLE forwards (id INTEGER PRIMARY KEY, service_id INTEGER, enabled INTEGER, user_deleted_at TEXT NULL,
    listen_port INTEGER, target_host TEXT, target_port INTEGER, line_client_id INTEGER NULL, line_client_group_id INTEGER NULL,
    line_client_group_ids_json TEXT NULL)');

$addForward = static function (int $id, ?int $clientId, string $host, int $port, array $extra = []) use ($pdo): void {
    $row = array_merge([
        'id' => $id, 'service_id' => 10, 'enabled' => 1, 'user_deleted_at' => null, 'listen_port' => 20000 + $id,
        'target_host' => $host, 'target_port' => $port, 'line_client_id' => $clientId,
        'line_client_group_id' => null, 'line_client_group_ids_json' => null,
    ], $extra);
    $stmt = $pdo->prepare('INSERT INTO forwards VALUES (:id, :service_id, :enabled, :user_deleted_at, :listen_port, :target_host,
        :target_port, :line_client_id, :line_client_group_id, :line_client_group_ids_json)');
    $stmt->execute($row);
};

$service = HostedNodeService::fromConfig($config, $db);
assertSame(true, $service->available(), 'service is available with an Ultra key');
$clientA = ['id' => 7, 'group_id' => null];
$clientB = ['id' => 8, 'group_id' => null];
$uuid = '1b4e28ba-2fa1-11d2-883f-0016d3cca427';

// --- create, dedupe, at-rest encryption ------------------------------------
assertSame('', $service->revisionForClient($clientA), 'no nodes: empty revision');
$ssUri = 'ss://' . rtrim(base64_encode('aes-128-gcm:top-secret-pass'), '=') . '@ss.example.com:8388#SS';
$first = $service->create(7, ['uri' => $ssUri], HostedNodeService::SOURCE_CONSOLE, 42);
assertSame(true, $first['created'], 'first upload creates');
$again = $service->create(7, ['uri' => $ssUri, 'name' => 'renamed'], HostedNodeService::SOURCE_DEVICE);
assertSame(false, $again['created'], 'same connection is idempotent');
assertSame($first['node']['id'], $again['node']['id'], 'idempotent upload returns the existing node');
$otherClient = $service->create(8, ['uri' => $ssUri], HostedNodeService::SOURCE_DEVICE);
assertSame(true, $otherClient['created'], 'the same node is separate per client');

$stored = $pdo->query('SELECT * FROM client_hosted_nodes WHERE client_id = 7')->fetch();
foreach (['top-secret-pass', 'ss.example.com', '8388', 'aes-128-gcm'] as $secret) {
    if (str_contains((string) $stored['sealed_config'], $secret) || str_contains((string) $stored['fingerprint'], $secret)) {
        fail('stored row leaks ' . $secret);
    }
}
assertSame(1, preg_match('/^hn1[xg]\./', (string) $stored['sealed_config']), 'sealed config uses the hosted-node scheme');
assertSame('SS', $stored['name'], 'name is a clear column');
assertSame('shadowsocks', $stored['protocol'], 'protocol is a clear column');
assertSame(42, (int) $stored['created_by_user_id'], 'console creator recorded');

// A ciphertext moved onto another client's row must not open (AAD = client id).
$pdo->prepare('UPDATE client_hosted_nodes SET sealed_config = :sealed WHERE client_id = 8')->execute(['sealed' => $stored['sealed_config']]);
assertSame([], $service->nodesForClient(8), 'a blob copied across clients does not open');
$pdo->exec('DELETE FROM client_hosted_nodes WHERE client_id = 8');

$wrongKey = new HostedNodeService($db, new HostedNodeVault(str_repeat('cd', 32)));
assertSame([], $wrongKey->nodesForClient(7), 'a different key does not open the rows');
$noKey = new HostedNodeService($db, new HostedNodeVault(null));
assertSame(false, $noKey->available(), 'no key: unavailable');
assertThrows(fn() => $noKey->create(7, ['uri' => $ssUri], 'console'), 'hosted_nodes_unavailable', 'no key: create fails closed');

// --- ownership --------------------------------------------------------------
$nodeA = (int) $first['node']['id'];
assertSame(null, $service->findNode(8, $nodeA), 'another client cannot read the node');
assertSame(false, $service->delete(8, $nodeA), 'another client cannot delete the node');
assertThrows(fn() => $service->update(8, $nodeA, ['name' => 'x']), 'hosted_node_not_found', 'another client cannot update the node');

// --- pairings ---------------------------------------------------------------
$socks = $service->create(7, ['protocol' => 'socks5', 'server' => '198.51.100.7', 'port' => 1080, 'username' => 'u', 'password' => 'p'], 'device');
$nodeB = (int) $socks['node']['id'];
$addForward(101, 7, 'SS.example.com', 8388);         // matches the SS node by target
$addForward(102, 7, '198.51.100.7', 1080);           // matches the SOCKS node
$addForward(103, 7, '203.0.113.1', 443);             // matches nothing
$addForward(104, 8, 'ss.example.com', 8388);         // another client's forward
$addForward(105, 7, 'ss.example.com', 8388, ['enabled' => 0]);
$addForward(106, 7, 'ss.example.com', 8388, ['user_deleted_at' => '2026-10-01 00:00:00']);
$addForward(107, null, '198.51.100.7', 1080, ['line_client_group_ids_json' => '[3,9]']);

$pairings = $service->effectivePairings($clientA);
assertSame([
    ['forward_id' => 101, 'node_id' => $nodeA, 'source' => 'target_match'],
    ['forward_id' => 102, 'node_id' => $nodeB, 'source' => 'target_match'],
], $pairings, 'target matching pairs forwards that already point at a node');

$service->setPairing($clientA, 103, $nodeB, 'console');
$service->setPairing($clientA, 101, $nodeB, 'device');
$pairings = $service->effectivePairings($clientA);
assertSame([
    ['forward_id' => 101, 'node_id' => $nodeB, 'source' => 'explicit'],
    ['forward_id' => 102, 'node_id' => $nodeB, 'source' => 'target_match'],
    ['forward_id' => 103, 'node_id' => $nodeB, 'source' => 'explicit'],
], $pairings, 'explicit pairings beat target matching');

$service->setPairing($clientA, 101, null, 'console');
assertSame('target_match', $service->effectivePairings($clientA)[0]['source'], 'clearing falls back to target matching');

assertThrows(fn() => $service->setPairing($clientA, 104, $nodeA, 'console'), 'hosted_node_forward_not_found', 'cannot pair another client\'s forward');
assertThrows(fn() => $service->setPairing($clientA, 106, $nodeA, 'console'), 'hosted_node_forward_not_found', 'cannot pair a user-deleted forward');
assertThrows(fn() => $service->setPairing($clientA, 999, $nodeA, 'console'), 'hosted_node_forward_not_found', 'cannot pair a missing forward');
$foreignNode = $service->create(8, ['protocol' => 'socks5', 'server' => '192.0.2.1', 'port' => 1080], 'device');
assertThrows(fn() => $service->setPairing($clientA, 103, (int) $foreignNode['node']['id'], 'console'), 'hosted_node_not_found', 'cannot pair another client\'s node');

// Group-assigned forwards belong to members of the group.
$groupClient = ['id' => 9, 'group_id' => 3];
$groupNode = $service->create(9, ['protocol' => 'socks5', 'server' => '198.51.100.7', 'port' => 1080], 'device');
$groupPairings = $service->effectivePairings($groupClient);
assertSame([['forward_id' => 107, 'node_id' => (int) $groupNode['node']['id'], 'source' => 'target_match']], $groupPairings, 'group forwards pair for group members');

// --- listing and revision ---------------------------------------------------
$listing = $service->clientListing($clientA);
assertSame(2, count($listing['nodes']), 'listing has both nodes');
$ssEntry = $listing['nodes'][0];
assertSame('shadowsocks', $ssEntry['protocol'], 'listing protocol');
assertSame('top-secret-pass', $ssEntry['settings']['password'], 'trusted listing carries credentials');
assertSame(HostedNodeSpec::toShareUri(HostedNodeSpec::normalize(['uri' => $ssUri])), $ssEntry['uri'], 'listing carries the canonical share link');
assertSame($listing['revision'], $service->revisionForClient($clientA), 'pull revision equals the listing revision');
if ($listing['revision'] === '') {
    fail('a client with nodes has a revision');
}

$before = $service->revisionForClient($clientA);
$service->update(7, $nodeB, ['name' => 'Home SOCKS']);
$afterRename = $service->revisionForClient($clientA);
if ($afterRename === $before) {
    fail('renaming a node changes the revision');
}
assertSame('Home SOCKS', $service->findNode(7, $nodeB)['name'], 'rename applied');
assertSame('u', $service->findNode(7, $nodeB)['node']['settings']['username'], 'rename keeps credentials');
assertSame(2, $service->findNode(7, $nodeB)['version'], 'update bumps version');
$service->setPairing($clientA, 103, $nodeA, 'console');
if ($service->revisionForClient($clientA) === $afterRename) {
    fail('changing a pairing changes the revision');
}
assertThrows(fn() => $service->update(7, $nodeB, ['uri' => $ssUri]), 'hosted_node_duplicate', 'update cannot duplicate another node');

$console = $service->consoleNodes(7);
$consoleJson = (string) json_encode($console);
foreach (['top-secret-pass', '"p"', 'password'] as $secret) {
    if (str_contains($consoleJson, $secret)) {
        fail('console listing leaks ' . $secret);
    }
}

// --- delete -----------------------------------------------------------------
assertSame(true, $service->delete(7, $nodeA), 'owner deletes the node');
$left = (int) $pdo->query('SELECT COUNT(*) FROM client_hosted_node_pairings WHERE client_id = 7 AND node_id = ' . $nodeA)->fetchColumn();
assertSame(0, $left, 'deleting a node deletes its pairings');
assertSame(false, $service->delete(7, $nodeA), 'deleting twice reports not found');

// --- limit ------------------------------------------------------------------
for ($i = 0; $i < HostedNodeService::MAX_NODES_PER_CLIENT; $i++) {
    try {
        $service->create(20, ['protocol' => 'socks5', 'server' => 'n' . $i . '.example.com', 'port' => 1080], 'console');
    } catch (ApiException $exception) {
        fail('unexpected failure before the limit: ' . $exception->getMessage());
    }
}
$limit = assertThrows(
    fn() => $service->create(20, ['protocol' => 'socks5', 'server' => 'over.example.com', 'port' => 1080], 'console'),
    'hosted_node_limit_reached',
    'per-client node limit'
);
assertSame(409, $limit->statusCode(), 'limit status');

$source = (string) file_get_contents(__DIR__ . '/../src/Support/HostedNodeService.php')
    . (string) file_get_contents(__DIR__ . '/../src/Support/HostedNodeVault.php');
if (preg_match('/\bmb_[a-z_]+\s*\(|error_log\s*\(/', $source) === 1) {
    fail('hosted node service must not use mbstring or log');
}

echo "ok\n";
