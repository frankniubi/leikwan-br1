<?php
declare(strict_types=1);

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Support\HostedNodeService;
use Leikwan\Master\Support\SessionAuth;

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

function assertContains(string $needle, string $haystack, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        fail($label . ': missing ' . $needle);
    }
}

function assertNotContains(string $needle, string $haystack, string $label): void
{
    if (str_contains($haystack, $needle)) {
        fail($label . ': unexpectedly found ' . $needle);
    }
}

$env = tempnam(sys_get_temp_dir(), 'lw-hosted-edit-');
file_put_contents($env, implode("\n", [
    'APP_NAME=LeiKwan Bridge',
    'APP_URL=https://panel.example.test',
    'DB_DRIVER=sqlite',
    'DB_NAME=:memory:',
    'AUTO_RUN_MIGRATIONS=false',
    'ULTRA_TOKEN_KEY=' . str_repeat('9a', 32),
]) . "\n");

try {
    $app = new Bootstrap($env);
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $app->db()->setPdo($pdo);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, status TEXT, name TEXT, email TEXT, api_token TEXT)');
    $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, owner_user_id INTEGER, is_default_for_owner INTEGER, status TEXT,
        group_id INTEGER NULL, name TEXT)');
    $pdo->exec('CREATE TABLE services (id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, status TEXT)');
    $pdo->exec('CREATE TABLE forwards (id INTEGER PRIMARY KEY, service_id INTEGER, enabled INTEGER, user_deleted_at TEXT NULL,
        listen_port INTEGER, target_host TEXT, target_port INTEGER, line_client_id INTEGER NULL, line_client_group_id INTEGER NULL,
        line_client_group_ids_json TEXT NULL)');
    $pdo->exec('CREATE TABLE client_hosted_nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, name TEXT NOT NULL,
        protocol TEXT NOT NULL, sealed_config TEXT NOT NULL, fingerprint TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1,
        source TEXT NOT NULL DEFAULT \'console\', created_by_user_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        UNIQUE (client_id, fingerprint))');
    $pdo->exec('CREATE TABLE client_hosted_node_pairings (client_id INTEGER NOT NULL, forward_id INTEGER NOT NULL, node_id INTEGER NOT NULL,
        source TEXT NOT NULL DEFAULT \'console\', updated_at TEXT NOT NULL, PRIMARY KEY (client_id, forward_id))');
    $pdo->exec("INSERT INTO users VALUES (42, 'tenant', 'active', 'Tenant', 'tenant@example.test', NULL)");
    $pdo->exec("INSERT INTO users VALUES (43, 'tenant', 'active', 'Other', 'other@example.test', NULL)");
    $pdo->exec("INSERT INTO clients VALUES (7, 42, 1, 'active', NULL, 'Default client')");
    $pdo->exec("INSERT INTO clients VALUES (8, 43, 1, 'active', NULL, 'Other client')");

    $service = HostedNodeService::fromConfig($app->config(), $app->db());
    $client = ['id' => 7, 'group_id' => null];
    $psk = base64_encode(str_repeat("\x05", 16));
    $ssId = (int) $service->create(7, [
        'protocol' => 'shadowsocks', 'name' => 'SS', 'server' => 'ss.example.com', 'port' => 8388,
        'method' => '2022-blake3-aes-128-gcm', 'password' => $psk, 'plugin' => 'obfs-local', 'plugin_opts' => 'obfs=http',
    ], 'console')['node']['id'];
    $uuid = '1b4e28ba-2fa1-11d2-883f-0016d3cca427';
    $vlessId = (int) $service->create(7, [
        'uri' => 'vless://' . $uuid . '@v.example.com:443?security=tls&sni=v.example.com&type=ws&path=%2Fray#VL',
    ], 'device')['node']['id'];
    $socksId = (int) $service->create(7, ['uri' => 'socks5://alice:wonder@198.51.100.7:1080#Home'], 'console')['node']['id'];

    $tenant = ['id' => 42, 'role' => 'tenant', 'status' => 'active', 'name' => 'Tenant', 'email' => 'tenant@example.test'];
    $csrf = 'hosted-edit-csrf';
    $login = static function (array $user) use ($csrf): void {
        SessionAuth::boot();
        SessionAuth::login($user);
        $_SESSION['csrf_token'] = $csrf;
        unset($_SESSION['flash']);
    };
    $editPost = static fn(int $id, array $body): Request => new Request('POST', '/tenant/hosted-nodes/' . $id . '/edit', [], $body + ['csrf_token' => $csrf, 'client_id' => '7'], [], '');
    $node = static fn(int $id): array => $service->findNode(7, $id) ?? fail('node ' . $id . ' missing');

    // --- edit page --------------------------------------------------------------
    $login($tenant);
    $list = $app->handle(new Request('GET', '/tenant/hosted-nodes', [], [], [], ''))->body();
    assertContains('href="/tenant/hosted-nodes/' . $ssId . '/edit?client_id=7"', $list, 'list links to the edit page');

    $login($tenant);
    $page = $app->handle(new Request('GET', '/tenant/hosted-nodes/' . $ssId . '/edit', ['client_id' => '7'], [], [], ''));
    assertSame(200, $page->status(), 'edit page renders');
    $html = $page->body();
    assertContains('action="/tenant/hosted-nodes/' . $ssId . '/edit"', $html, 'edit form posts back to the node');
    assertContains('value="ss.example.com"', $html, 'server prefilled');
    assertContains('value="8388"', $html, 'port prefilled');
    assertContains('<option value="shadowsocks" selected>', $html, 'protocol preselected');
    assertContains('<option value="obfs-local" selected>', $html, 'plugin preselected');
    assertContains('value="obfs=http"', $html, 'plugin options prefilled');
    assertContains('Leave blank to keep the current value', $html, 'secret field explains keep-on-blank');
    assertNotContains($psk, $html, 'stored password is never rendered');

    $login($tenant);
    $vlessPage = $app->handle(new Request('GET', '/tenant/hosted-nodes/' . $vlessId . '/edit', ['client_id' => '7'], [], [], ''))->body();
    assertNotContains($uuid, $vlessPage, 'stored UUID is never rendered');
    assertContains('value="/ray"', $vlessPage, 'ws path prefilled');

    // Another tenant cannot open or change the node.
    $other = ['id' => 43, 'role' => 'tenant', 'status' => 'active', 'name' => 'Other', 'email' => 'other@example.test'];
    $login($other);
    $foreign = $app->handle(new Request('GET', '/tenant/hosted-nodes/' . $ssId . '/edit', ['client_id' => '7'], [], [], ''));
    assertSame(302, $foreign->status(), 'foreign client edit page redirects');
    $login($other);
    $foreignDefault = $app->handle(new Request('GET', '/tenant/hosted-nodes/' . $ssId . '/edit', [], [], [], ''));
    assertSame(302, $foreignDefault->status(), 'a node of another client is not found under the own default client');
    $login($other);
    $app->handle($editPost($ssId, ['protocol' => 'shadowsocks', 'server' => 'evil.example.com', 'port' => '1']));
    assertSame('ss.example.com', $node($ssId)['node']['server'], 'foreign tenant cannot edit');

    // Wrong CSRF changes nothing.
    $login($tenant);
    $app->handle(new Request('POST', '/tenant/hosted-nodes/' . $ssId . '/edit', [], ['csrf_token' => 'nope', 'client_id' => '7', 'server' => 'x.example.com'], [], ''));
    assertSame('ss.example.com', $node($ssId)['node']['server'], 'csrf failure changes nothing');

    // --- edit in place, blank secret keeps the stored value --------------------
    $revisionBefore = $service->revisionForClient($client);
    $versionBefore = $node($ssId)['version'];
    $login($tenant);
    $saved = $app->handle($editPost($ssId, [
        'name' => 'SS Tokyo', 'protocol' => 'shadowsocks', 'server' => 'ss2.example.com', 'port' => '9000',
        'method' => '2022-blake3-aes-128-gcm', 'ss_password' => '', 'plugin' => '', 'plugin_opts' => 'ignored', 'udp_over_tcp' => '1',
    ]));
    assertSame('success', $_SESSION['flash']['type'] ?? null, 'edit succeeds');
    assertSame('/tenant/hosted-nodes?client_id=7', $saved->headers()['Location'] ?? null, 'edit returns to the list');
    $edited = $node($ssId);
    assertSame('SS Tokyo', $edited['name'], 'name edited');
    assertSame('ss2.example.com', $edited['node']['server'], 'server edited');
    assertSame(9000, $edited['node']['port'], 'port edited');
    assertSame($psk, $edited['node']['settings']['password'], 'blank password keeps the stored one');
    assertSame('', $edited['node']['settings']['plugin'], 'choosing no plugin clears it');
    assertSame('', $edited['node']['settings']['plugin_opts'], 'plugin options go with the plugin');
    assertSame(true, $edited['node']['settings']['udp_over_tcp'], 'checkbox applied');
    assertSame($versionBefore + 1, $edited['version'], 'edit bumps the version');
    if ($service->revisionForClient($client) === $revisionBefore) {
        fail('an edit must move the client revision so devices sync');
    }
    assertSame($ssId, $edited['id'], 'edited in place, same id');

    // A new secret replaces the stored one.
    $newPsk = base64_encode(str_repeat("\x06", 16));
    $login($tenant);
    $app->handle($editPost($ssId, ['protocol' => 'shadowsocks', 'server' => 'ss2.example.com', 'port' => '9000',
        'method' => '2022-blake3-aes-128-gcm', 'ss_password' => $newPsk, 'udp_over_tcp' => '0']));
    assertSame($newPsk, $node($ssId)['node']['settings']['password'], 're-entered secret replaces the stored one');
    assertSame(false, $node($ssId)['node']['settings']['udp_over_tcp'], 'unchecked checkbox clears');

    // VLESS: blank UUID keeps it; switching to REALITY validates its fields.
    $login($tenant);
    $app->handle($editPost($vlessId, ['protocol' => 'vless', 'server' => 'v.example.com', 'port' => '8443',
        'security' => 'tls', 'sni' => 'v.example.com', 'transport' => 'ws', 'path' => '/new', 'host' => '', 'alpn' => 'h2']));
    $vless = $node($vlessId)['node'];
    assertSame($uuid, $vless['settings']['uuid'], 'blank UUID keeps the stored one');
    assertSame([8443, '/new', ['h2']], [$vless['port'], $vless['settings']['path'], $vless['settings']['alpn']], 'vless fields edited');

    $login($tenant);
    $bad = $app->handle($editPost($vlessId, ['protocol' => 'vless', 'server' => 'v.example.com', 'port' => '8443',
        'security' => 'reality', 'sni' => 'www.example.com', 'transport' => 'tcp', 'public_key' => 'short']));
    assertSame('error', $_SESSION['flash']['type'] ?? null, 'invalid edit flashes an error');
    assertContains('REALITY public key', (string) ($_SESSION['flash']['message'] ?? ''), 'error names the field');
    assertSame('/tenant/hosted-nodes/' . $vlessId . '/edit?client_id=7', $bad->headers()['Location'] ?? null, 'invalid edit returns to the edit form');
    assertSame('tls', $node($vlessId)['node']['settings']['security'], 'invalid edit changes nothing');

    // SOCKS5: clearing the username turns auth off; keeping it keeps the password.
    $login($tenant);
    $app->handle($editPost($socksId, ['protocol' => 'socks5', 'server' => '198.51.100.7', 'port' => '1081', 'username' => 'alice']));
    assertSame(['username' => 'alice', 'password' => 'wonder'], $node($socksId)['node']['settings'], 'socks password kept on blank');
    $login($tenant);
    $app->handle($editPost($socksId, ['protocol' => 'socks5', 'server' => '198.51.100.7', 'port' => '1081', 'username' => '']));
    assertSame(['username' => '', 'password' => ''], $node($socksId)['node']['settings'], 'clearing username removes auth');

    // Switching protocol carries no secret over: the new protocol's secret is required.
    $login($tenant);
    $app->handle($editPost($socksId, ['protocol' => 'shadowsocks', 'server' => '198.51.100.7', 'port' => '1081', 'method' => 'aes-128-gcm']));
    assertSame('error', $_SESSION['flash']['type'] ?? null, 'protocol switch without a secret fails');
    assertSame('socks5', $node($socksId)['node']['protocol'], 'failed switch changes nothing');
    $login($tenant);
    $app->handle($editPost($socksId, ['protocol' => 'shadowsocks', 'server' => '198.51.100.7', 'port' => '1081', 'method' => 'aes-128-gcm', 'ss_password' => 'pw']));
    assertSame(['shadowsocks', 'pw'], [$node($socksId)['node']['protocol'], $node($socksId)['node']['settings']['password']], 'protocol switch with a secret works');

    // A share link replaces everything; an edit that duplicates another node is refused.
    $login($tenant);
    $app->handle($editPost($socksId, ['uri' => 'socks5://bob:builder@203.0.113.9:1080#Bob', 'name' => '']));
    assertSame(['socks5', '203.0.113.9', 'Bob'], [$node($socksId)['node']['protocol'], $node($socksId)['node']['server'], $node($socksId)['name']], 'share link replaces the node');
    $login($tenant);
    $app->handle($editPost($socksId, ['uri' => 'vless://' . $uuid . '@v.example.com:8443?security=tls&sni=v.example.com&type=ws&path=%2Fnew&alpn=h2']));
    assertSame('error', $_SESSION['flash']['type'] ?? null, 'duplicate edit refused');
    assertContains('Another node already uses these settings.', (string) ($_SESSION['flash']['message'] ?? ''), 'duplicate message');

    // Editing a node that no longer exists returns to the list.
    $login($tenant);
    $missing = $app->handle($editPost(999, ['protocol' => 'socks5', 'server' => 'a.example.com', 'port' => '1']));
    assertSame('/tenant/hosted-nodes?client_id=7', $missing->headers()['Location'] ?? null, 'missing node returns to the list');
} finally {
    @unlink($env);
}

echo "ok\n";
