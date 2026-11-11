<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\KnowledgebaseAddressGuard;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database/Database.php';
require_once __DIR__ . '/../src/Support/KnowledgebaseAddressGuard.php';

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertLeaks(array $expected, KnowledgebaseAddressGuard $guard, string $text, string $message): void
{
    $actual = $guard->findLeaks($text);
    if ($actual !== $expected) {
        fail($message . '; expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
}

$guard = new KnowledgebaseAddressGuard();

// Documentation, private and panel addresses stay allowed.
assertLeaks([], $guard, '<p>Target 203.0.113.10:443, backup 198.51.100.7 and 192.0.2.1.</p>', 'documentation IPv4 ranges are allowed');
assertLeaks([], $guard, '<p>Local 127.0.0.1:30115, LAN 192.168.1.10, 10.0.0.1, 172.16.5.4, CGNAT 100.64.0.0/10, any 0.0.0.0.</p>', 'private and loopback IPv4 are allowed');
assertLeaks([], $guard, '<p>Windows 0.1.20.80, OpenWrt 10.3.32.1, runtime 2026.11.10.1, at 03:00:00.</p>', 'version strings and times are not addresses');
assertLeaks([], $guard, '<p>Use game.example.com, node.example.net, target.example.invalid or host.test.</p>', 'documentation domains are allowed');
assertLeaks([], $guard, '<pre><code>curl https://v3.leikwanhost.com/api/v1 -H x</code></pre><p>Mirror v3-mirror.leikwanhost.com or api-leikwanbridge.nbwly.com.</p>', 'public panel hosts are allowed');
assertLeaks([], $guard, '<p>IPv6 ::1, fe80::1, fd00::1 and 2001:db8::10.</p>', 'documentation and local IPv6 are allowed');
assertLeaks([], $guard, '<p>Fields target.host and target.port; see index.html or a.png.</p>', 'dotted identifiers are not operator hosts');
assertLeaks([], $guard, '<a href="https://testflight.apple.com/join/abc">TestFlight</a>', 'third-party links are allowed');

// Real addresses are reported.
assertLeaks(['east.nbwly.com'], $guard, '<td>east.nbwly.com:30522</td>', 'operator-zone hosts are rejected');
assertLeaks(['sh36.ctyun.mcbar.cc'], $guard, '<p>ssh root@sh36.ctyun.mcbar.cc</p>', 'mcbar.cc hosts are rejected');
assertLeaks(['nrt1.leikwanhost.com'], $guard, '<p>Edge NRT1.leikwanhost.com.</p>', 'case-insensitive operator-zone match, trailing period ignored');
assertLeaks(['185.220.238.143'], $guard, '<p>Sidecar 185.220.238.143:14950</p>', 'public IPv4 is rejected');
assertLeaks(['8.8.8.8'], $guard, '<img src="http://8.8.8.8/x.png" alt="">', 'addresses inside attributes are rejected');
assertLeaks(['47.116.104.15'], $guard, '<p>47&#46;116&#46;104&#46;15</p>', 'entity-encoded addresses are rejected');
assertLeaks(['2400:8500:1302::1'], $guard, '<p>[2400:8500:1302::1]:443</p>', 'public IPv6 is rejected');

try {
    $guard->assertClean('Title', 'Excerpt', '<p>east.nbwly.com</p>');
    fail('assertClean must throw on a leaked host');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'east.nbwly.com')) {
        fail('assertClean message must name the leaked host');
    }
}
$guard->assertClean('Title', 'Excerpt', '<p>node.example.com</p>');

// Hostnames the panel stores for nodes and forward targets are rejected even outside operator zones.
$tmpDir = sys_get_temp_dir() . '/leikwan-kb-guard-' . bin2hex(random_bytes(4));
if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    fail('failed to create temp dir');
}
$envPath = $tmpDir . '/.env';
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME={$tmpDir}/guard.sqlite\n");
$db = new Database(new Config($envPath));
$db->pdo()->exec('CREATE TABLE forwards (id INTEGER PRIMARY KEY, target_host TEXT, sni_host TEXT, cdn_host TEXT, bond_remote_host TEXT)');
$db->pdo()->exec('CREATE TABLE sidecar_points (id INTEGER PRIMARY KEY, sidecar_host TEXT)');
$db->pdo()->exec("INSERT INTO forwards (target_host, sni_host) VALUES ('Game-Node.Customer.net', 'game.example.com'), ('203.0.113.9', NULL)");
$db->pdo()->exec("INSERT INTO sidecar_points (sidecar_host) VALUES ('edge7.someprovider.io')");

$dbGuard = new KnowledgebaseAddressGuard($db);
assertLeaks(['game-node.customer.net'], $dbGuard, '<p>Forward to game-node.customer.net:27015</p>', 'stored forward targets are rejected');
assertLeaks(['edge7.someprovider.io'], $dbGuard, '<p>edge7.someprovider.io</p>', 'stored sidecar hosts are rejected');
assertLeaks([], $dbGuard, '<p>game.example.com and other.customer.net</p>', 'documentation hosts stay allowed even when stored; unknown hosts pass');

// Every bundled article must already be clean.
foreach (glob(__DIR__ . '/../resources/knowledgebase/*/*.html') ?: [] as $path) {
    $leaks = $guard->findLeaks((string) file_get_contents($path));
    if ($leaks !== []) {
        fail(basename(dirname($path)) . '/' . basename($path) . ' names real addresses: ' . implode(', ', $leaks));
    }
}

echo "knowledgebase_address_guard_test: ok\n";
