<?php
declare(strict_types=1);

use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\HostedNodeSpec;

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

function assertInvalid(array $input, string $field, string $label): void
{
    try {
        HostedNodeSpec::normalize($input);
    } catch (ApiException $exception) {
        assertSame('hosted_node_invalid', $exception->getMessage(), $label . ' code');
        assertSame(422, $exception->statusCode(), $label . ' status');
        assertSame($field, $exception->details()['field'] ?? null, $label . ' field');
        return;
    }
    fail($label . ': expected hosted_node_invalid');
}

function roundTrip(array $node, string $label): void
{
    $again = HostedNodeSpec::normalize(['uri' => HostedNodeSpec::toShareUri($node)]);
    assertSame($node, $again, $label . ' survives toShareUri -> parse');
}

$psk16 = base64_encode(str_repeat("\x01", 16));
$psk32 = base64_encode(str_repeat("\x02", 32));

// --- Shadowsocks -----------------------------------------------------------
$sip002 = 'ss://' . rtrim(strtr(base64_encode('aes-256-gcm:pa ss:word'), '+/', '-_'), '=') . '@Node.Example.com:8388/?plugin=simple-obfs%3Bobfs%3Dhttp%3Bobfs-host%3Dcdn.example.com#My%20SS';
$ss = HostedNodeSpec::normalize(['uri' => $sip002]);
assertSame('shadowsocks', $ss['protocol'], 'ss protocol');
assertSame('My SS', $ss['name'], 'ss name from fragment');
assertSame('node.example.com', $ss['server'], 'ss host lowercased');
assertSame(8388, $ss['port'], 'ss port');
assertSame('aes-256-gcm', $ss['settings']['method'], 'ss method');
assertSame('pa ss:word', $ss['settings']['password'], 'ss password keeps colons after the first');
assertSame('obfs-local', $ss['settings']['plugin'], 'simple-obfs is normalized to obfs-local');
assertSame('obfs=http;obfs-host=cdn.example.com', $ss['settings']['plugin_opts'], 'ss plugin opts');
roundTrip($ss, 'ss');

$plain = HostedNodeSpec::normalize(['uri' => 'ss://2022-blake3-aes-128-gcm:' . rawurlencode($psk16) . '@1.2.3.4:443?uot=1']);
assertSame('2022-blake3-aes-128-gcm', $plain['settings']['method'], 'ss percent-encoded userinfo');
assertSame($psk16, $plain['settings']['password'], 'ss 2022 psk');
assertSame(true, $plain['settings']['udp_over_tcp'], 'ss uot flag');
assertSame('1.2.3.4:443', $plain['name'], 'default name is server:port');
roundTrip($plain, 'ss 2022');

$legacy = HostedNodeSpec::normalize(['uri' => 'ss://' . base64_encode('chacha20-ietf-poly1305:secret@5.6.7.8:9000') . '#legacy']);
assertSame('5.6.7.8', $legacy['server'], 'legacy ss host');
assertSame('secret', $legacy['settings']['password'], 'legacy ss password');

$multiUser = HostedNodeSpec::normalize([
    'protocol' => 'ss', 'server' => 'a.example.com', 'port' => '443',
    'method' => '2022-blake3-aes-256-gcm', 'password' => $psk32 . ':' . $psk32,
]);
assertSame($psk32 . ':' . $psk32, $multiUser['settings']['password'], 'ss 2022 iPSK:uPSK accepted');

assertInvalid(['protocol' => 'ss', 'server' => 'a.example.com', 'port' => 1, 'method' => 'rc4-md5', 'password' => 'x'], 'method', 'legacy stream cipher refused');
assertInvalid(['protocol' => 'ss', 'server' => 'a.example.com', 'port' => 1, 'method' => 'aes-128-gcm', 'password' => ''], 'password', 'empty ss password');
assertInvalid(['protocol' => 'ss', 'server' => 'a.example.com', 'port' => 1, 'method' => '2022-blake3-aes-128-gcm', 'password' => $psk32], 'password', '2022 psk length is checked');
assertInvalid(['protocol' => 'ss', 'server' => 'a.example.com', 'port' => 1, 'method' => 'aes-128-gcm', 'password' => 'x', 'plugin' => 'kcptun'], 'plugin', 'unsupported plugin');

// --- SOCKS5 ----------------------------------------------------------------
$socks = HostedNodeSpec::normalize(['uri' => 'socks5://us%3Aer:p%40ss@[2001:DB8::1]:1080#Home']);
assertSame('socks5', $socks['protocol'], 'socks protocol');
assertSame('2001:db8::1', $socks['server'], 'socks ipv6 compressed and unbracketed');
assertSame('us:er', $socks['settings']['username'], 'socks username decoded');
assertSame('p@ss', $socks['settings']['password'], 'socks password decoded');
roundTrip($socks, 'socks5 with auth');
assertSame(true, str_contains(HostedNodeSpec::toShareUri($socks), '@[2001:db8::1]:1080'), 'ipv6 share link is bracketed');

$noAuth = HostedNodeSpec::normalize(['uri' => 'socks://proxy.example.net:1080']);
assertSame(['username' => '', 'password' => ''], $noAuth['settings'], 'socks without auth');
assertSame('socks5://proxy.example.net:1080#proxy.example.net%3A1080', HostedNodeSpec::toShareUri($noAuth), 'socks no-auth link');
roundTrip($noAuth, 'socks5 no auth');

$v2rayn = HostedNodeSpec::normalize(['uri' => 'socks://' . base64_encode('alice:s3cret') . '@9.9.9.9:1080']);
assertSame('alice', $v2rayn['settings']['username'], 'v2rayN base64 socks userinfo');
assertSame('s3cret', $v2rayn['settings']['password'], 'v2rayN base64 socks password');

assertInvalid(['protocol' => 'socks5', 'server' => 'a.example.com', 'port' => 1080, 'username' => 'u'], 'password', 'socks username needs a password');
assertInvalid(['protocol' => 'socks5', 'server' => 'a.example.com', 'port' => 1080, 'password' => 'p'], 'username', 'socks password needs a username');
assertInvalid(['protocol' => 'socks5', 'server' => 'a.example.com', 'port' => 1080, 'username' => str_repeat('u', 256), 'password' => 'p'], 'username', 'socks username over 255 bytes');

// --- VLESS -----------------------------------------------------------------
$uuid = '1b4e28ba-2fa1-11d2-883f-0016d3cca427';
$pbk = 'Z84J2IelR9ch3k8VtlVhhs5ycBUlXA7wHBWcBrjqnAw';
$reality = HostedNodeSpec::normalize([
    'uri' => 'vless://' . strtoupper($uuid) . '@203.0.113.9:443?encryption=none&flow=xtls-rprx-vision&security=reality&sni=www.microsoft.com&fp=chrome&pbk=' . $pbk . '&sid=6ba85179e30d4fc2&type=tcp#Reality%20%E4%B8%9C%E4%BA%AC',
]);
assertSame('vless', $reality['protocol'], 'vless protocol');
assertSame('Reality 东京', $reality['name'], 'utf-8 name from fragment');
assertSame($uuid, $reality['settings']['uuid'], 'uuid lowercased');
assertSame('reality', $reality['settings']['security'], 'reality security');
assertSame('xtls-rprx-vision', $reality['settings']['flow'], 'vision flow');
assertSame($pbk, $reality['settings']['public_key'], 'reality public key');
assertSame('6ba85179e30d4fc2', $reality['settings']['short_id'], 'reality short id');
roundTrip($reality, 'vless reality');

$realityDefaultFp = HostedNodeSpec::normalize(['uri' => 'vless://' . $uuid . '@203.0.113.9:443?security=reality&sni=www.example.com&pbk=' . $pbk]);
assertSame('chrome', $realityDefaultFp['settings']['fingerprint'], 'reality gets a uTLS fingerprint by default');

$ws = HostedNodeSpec::normalize([
    'uri' => 'vless://' . $uuid . '@cdn.example.com:443?security=tls&type=ws&path=%2Fray%3Fed%3D2048&host=cdn.example.com&sni=cdn.example.com&alpn=h2%2Chttp%2F1.1&allowInsecure=1',
]);
assertSame('ws', $ws['settings']['transport'], 'ws transport');
assertSame('/ray?ed=2048', $ws['settings']['path'], 'ws path decoded once');
assertSame(['h2', 'http/1.1'], $ws['settings']['alpn'], 'alpn list');
assertSame(true, $ws['settings']['insecure'], 'allowInsecure flag');
roundTrip($ws, 'vless ws tls');

$grpc = HostedNodeSpec::normalize([
    'protocol' => 'vless', 'server' => 'g.example.com', 'port' => 443, 'uuid' => $uuid,
    'security' => 'tls', 'transport' => 'grpc', 'service_name' => 'grpc-svc', 'path' => '/ignored',
]);
assertSame('grpc-svc', $grpc['settings']['service_name'], 'grpc service name');
assertSame('', $grpc['settings']['path'], 'grpc drops ws-only fields');
roundTrip($grpc, 'vless grpc');

$none = HostedNodeSpec::normalize(['protocol' => 'vless', 'server' => 'n.example.com', 'port' => 80, 'uuid' => $uuid, 'sni' => 'x.example.com']);
assertSame('', $none['settings']['sni'], 'security none drops tls-only fields');
roundTrip($none, 'vless plain');

assertInvalid(['uri' => 'vless://not-a-uuid@a.example.com:443'], 'uuid', 'vless uuid format');
assertInvalid(['uri' => 'vless://' . $uuid . '@a.example.com:443?security=reality&sni=a.example.com&pbk=short'], 'public_key', 'reality key format');
assertInvalid(['uri' => 'vless://' . $uuid . '@a.example.com:443?security=reality&pbk=' . $pbk], 'sni', 'reality needs a server name');
assertInvalid(['uri' => 'vless://' . $uuid . '@a.example.com:443?security=reality&sni=a.example.com&pbk=' . $pbk . '&sid=xyz'], 'short_id', 'reality short id is hex');
assertInvalid(['uri' => 'vless://' . $uuid . '@a.example.com:443?flow=xtls-rprx-vision'], 'flow', 'vision needs tls or reality');
assertInvalid(['uri' => 'vless://' . $uuid . '@a.example.com:443?security=tls&type=ws&flow=xtls-rprx-vision'], 'flow', 'vision needs raw tcp');
assertInvalid(['uri' => 'vless://' . $uuid . '@a.example.com:443?type=kcp'], 'transport', 'unsupported transport');
assertInvalid(['uri' => 'vless://' . $uuid . '@a.example.com:443?type=grpc&security=tls'], 'service_name', 'grpc needs a service name');
assertInvalid(['uri' => 'vless://' . $uuid . '@a.example.com:443?encryption=aes'], 'encryption', 'vless encryption must be none');

// --- Common ----------------------------------------------------------------
assertInvalid(['uri' => 'vmess://e30='], 'uri', 'vmess is not a hosted protocol');
assertInvalid(['protocol' => 'wireguard', 'server' => 'a.example.com', 'port' => 1], 'protocol', 'wireguard is not a hosted protocol');
assertInvalid(['protocol' => 'socks5', 'server' => '127.0.0.1', 'port' => 1080], 'server', 'loopback server refused');
assertInvalid(['protocol' => 'socks5', 'server' => 'localhost', 'port' => 1080], 'server', 'localhost refused');
assertInvalid(['protocol' => 'socks5', 'server' => '::1', 'port' => 1080], 'server', 'ipv6 loopback refused');
assertInvalid(['protocol' => 'socks5', 'server' => 'bad_host', 'port' => 1080], 'server', 'invalid hostname refused');
assertInvalid(['protocol' => 'socks5', 'server' => 'a.example.com', 'port' => 70000], 'port', 'port range');
assertInvalid(['protocol' => 'socks5', 'server' => 'a.example.com', 'port' => '80x'], 'port', 'port must be numeric');
assertInvalid(['uri' => "socks5://a.exa\tmple.com:1080"], 'uri', 'control characters refused');
assertInvalid(['uri' => 'socks5://a.example.com:1080#' . str_repeat('a', HostedNodeSpec::MAX_URI_BYTES)], 'uri', 'oversized uri refused');

$long = HostedNodeSpec::normalize(['protocol' => 'socks5', 'server' => 'a.example.com', 'port' => 1080, 'name' => str_repeat('节', 60)]);
if (strlen($long['name']) > HostedNodeSpec::MAX_NAME_BYTES || preg_match('//u', $long['name']) !== 1) {
    fail('long names are trimmed to whole UTF-8 characters');
}
$override = HostedNodeSpec::normalize(['uri' => 'socks5://a.example.com:1080#from-link', 'name' => 'chosen']);
assertSame('chosen', $override['name'], 'explicit name overrides the fragment');

$summary = HostedNodeSpec::publicSummary($socks);
foreach (['p@ss', 'us:er'] as $secret) {
    if (str_contains((string) json_encode($summary), $secret)) {
        fail('public summary must not contain credentials');
    }
}
assertSame(true, $summary['auth'], 'summary says auth is set');
$realitySummary = (string) json_encode(HostedNodeSpec::publicSummary($reality));
if (str_contains($realitySummary, $uuid) || str_contains($realitySummary, $pbk)) {
    fail('public summary must not contain the uuid or reality key');
}

$source = (string) file_get_contents(__DIR__ . '/../src/Support/HostedNodeSpec.php');
if (preg_match('/\bmb_[a-z_]+\s*\(/', $source) === 1) {
    fail('HostedNodeSpec must not use mbstring (production PHP has none)');
}

echo "ok\n";
