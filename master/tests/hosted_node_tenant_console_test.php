<?php
declare(strict_types=1);

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Http\Request;
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

$env = tempnam(sys_get_temp_dir(), 'lw-hosted-console-');
file_put_contents($env, implode("\n", [
    'APP_NAME=LeiKwan Bridge',
    'APP_URL=https://panel.example.test',
    'DB_DRIVER=sqlite',
    'DB_NAME=:memory:',
    'AUTO_RUN_MIGRATIONS=false',
    'ULTRA_TOKEN_KEY=' . str_repeat('ef', 32),
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
    $pdo->exec("INSERT INTO services VALUES (30, 42, 'CAN -> HKG', 'active')");
    $pdo->exec("INSERT INTO forwards VALUES (101, 30, 1, NULL, 21001, 'node.example.com', 8388, 7, NULL, NULL)");
    $pdo->exec("INSERT INTO forwards VALUES (102, 30, 1, NULL, 21002, '203.0.113.5', 1080, 7, NULL, NULL)");

    $tenant = ['id' => 42, 'role' => 'tenant', 'status' => 'active', 'name' => 'Tenant', 'email' => 'tenant@example.test'];
    $csrf = 'hosted-console-csrf';
    $login = static function (array $user) use ($csrf): void {
        SessionAuth::boot();
        SessionAuth::login($user);
        $_SESSION['csrf_token'] = $csrf;
        unset($_SESSION['flash']);
    };
    $post = static fn(string $path, array $body): Request => new Request('POST', $path, [], $body, [], '');

    // Anonymous visitors go to login.
    $anonymous = $app->handle(new Request('GET', '/tenant/hosted-nodes', [], [], [], ''));
    assertSame(302, $anonymous->status(), 'anonymous redirect');
    assertSame('/login', $anonymous->headers()['Location'] ?? null, 'anonymous goes to login');

    $login($tenant);
    $empty = $app->handle(new Request('GET', '/tenant/hosted-nodes', [], [], [], ''));
    assertSame(200, $empty->status(), 'page renders');
    $html = $empty->body();
    assertContains('My Nodes', $html, 'page title / nav entry');
    assertContains('No nodes yet.', $html, 'empty state');
    // Protocols are named only in the add form, never as a node-list column.
    assertContains('Supports Shadowsocks, SOCKS5 and VLESS.', $html, 'add form names the supported protocols');
    assertSame(false, str_contains($html, '<th>Protocol</th>'), 'node list has no protocol column');
    assertContains('action="/tenant/hosted-nodes"', $html, 'add form');
    assertContains('CAN -&gt; HKG', $html, 'forward listed with escaped service name');

    // Wrong CSRF writes nothing.
    $login($tenant);
    $app->handle($post('/tenant/hosted-nodes', ['csrf_token' => 'nope', 'client_id' => '7', 'uri' => 'socks5://u:p@node.example.com:1080']));
    assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM client_hosted_nodes')->fetchColumn(), 'csrf failure writes nothing');
    assertSame('error', $_SESSION['flash']['type'] ?? null, 'csrf failure flashes error');

    // A client the tenant does not own is refused.
    $login($tenant);
    $app->handle($post('/tenant/hosted-nodes', ['csrf_token' => $csrf, 'client_id' => '8', 'uri' => 'socks5://u:p@node.example.com:1080']));
    assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM client_hosted_nodes')->fetchColumn(), 'foreign client writes nothing');
    $login($tenant);
    $foreignPage = $app->handle(new Request('GET', '/tenant/hosted-nodes', ['client_id' => '8'], [], [], ''));
    assertSame('/tenant/clients', $foreignPage->headers()['Location'] ?? null, 'foreign client page is refused');

    // Structured SS form.
    $psk = base64_encode(str_repeat("\x05", 16));
    $login($tenant);
    $created = $app->handle($post('/tenant/hosted-nodes', [
        'csrf_token' => $csrf, 'client_id' => '7', 'name' => 'My <SS>', 'protocol' => 'shadowsocks',
        'server' => 'node.example.com', 'port' => '8388', 'method' => '2022-blake3-aes-128-gcm', 'ss_password' => $psk,
        'socks_password' => 'ignored',
    ]));
    assertSame('/tenant/hosted-nodes?client_id=7', $created->headers()['Location'] ?? null, 'create returns to the page');
    assertSame('success', $_SESSION['flash']['type'] ?? null, 'create succeeds');

    // Share-link form.
    $login($tenant);
    $app->handle($post('/tenant/hosted-nodes', ['csrf_token' => $csrf, 'client_id' => '7', 'uri' => 'socks5://alice:wonder@203.0.113.5:1080#Home']));
    assertSame('success', $_SESSION['flash']['type'] ?? null, 'share link create succeeds');
    assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM client_hosted_nodes WHERE client_id = 7')->fetchColumn(), 'two nodes stored');

    // Invalid input names the field without echoing it.
    $login($tenant);
    $app->handle($post('/tenant/hosted-nodes', ['csrf_token' => $csrf, 'client_id' => '7', 'protocol' => 'vless', 'server' => 'v.example.com', 'port' => '443', 'uuid' => '<bad-secret>']));
    assertSame('error', $_SESSION['flash']['type'] ?? null, 'invalid node flashes error');
    assertContains('UUID', (string) ($_SESSION['flash']['message'] ?? ''), 'error names the field');
    assertNotContains('bad-secret', (string) ($_SESSION['flash']['message'] ?? ''), 'error does not echo input');

    $login($tenant);
    $page = $app->handle(new Request('GET', '/tenant/hosted-nodes', [], [], [], ''))->body();
    assertContains('My &lt;SS&gt;', $page, 'node name escaped');
    assertContains('node.example.com:8388', $page, 'server shown');
    assertContains('Automatic: My &lt;SS&gt;', $page, 'forward 101 auto-pairs by target');
    assertContains('Automatic: Home', $page, 'forward 102 auto-pairs by target');
    assertNotContains($psk, $page, 'ss password is write-only');
    assertNotContains('wonder', $page, 'socks password is write-only');

    // Explicit pairing.
    $socksId = (int) $pdo->query("SELECT id FROM client_hosted_nodes WHERE name = 'Home'")->fetchColumn();
    $login($tenant);
    $app->handle($post('/tenant/hosted-nodes/pairings', ['csrf_token' => $csrf, 'client_id' => '7', 'forward_id' => '101', 'node_id' => (string) $socksId]));
    assertSame('success', $_SESSION['flash']['type'] ?? null, 'pairing saved');
    $row = $pdo->query('SELECT * FROM client_hosted_node_pairings')->fetch();
    assertSame([7, 101, $socksId, 'console'], [(int) $row['client_id'], (int) $row['forward_id'], (int) $row['node_id'], $row['source']], 'pairing row');
    $login($tenant);
    $page = $app->handle(new Request('GET', '/tenant/hosted-nodes', [], [], [], ''))->body();
    assertContains('Paired: Home', $page, 'explicit pairing shown');
    assertContains('The forward target is not this node', $page, 'target mismatch warned');

    // The other tenant cannot touch tenant 42's node or forward.
    $other = ['id' => 43, 'role' => 'tenant', 'status' => 'active', 'name' => 'Other', 'email' => 'other@example.test'];
    $login($other);
    $app->handle($post('/tenant/hosted-nodes/' . $socksId . '/delete', ['csrf_token' => $csrf, 'client_id' => '7']));
    assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM client_hosted_nodes WHERE client_id = 7')->fetchColumn(), 'other tenant cannot delete');
    $login($other);
    $app->handle($post('/tenant/hosted-nodes/pairings', ['csrf_token' => $csrf, 'client_id' => '8', 'forward_id' => '101', 'node_id' => '']));
    assertSame('error', $_SESSION['flash']['type'] ?? null, 'other tenant cannot pair a foreign forward');

    // Delete removes the node and its pairing.
    $login($tenant);
    $app->handle($post('/tenant/hosted-nodes/' . $socksId . '/delete', ['csrf_token' => $csrf, 'client_id' => '7']));
    assertSame('success', $_SESSION['flash']['type'] ?? null, 'delete succeeds');
    assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM client_hosted_node_pairings')->fetchColumn(), 'pairing deleted with node');
} finally {
    @unlink($env);
}

echo "ok\n";
