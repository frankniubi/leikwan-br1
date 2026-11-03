<?php
declare(strict_types=1);

/**
 * Plan C3: the panel's per-forward external UoT token must equal what the Go
 * sidecar expects. Every expected token below was printed by the sidecar
 * itself (go build ./tools/uot-sidecar; uotsc --print-token --secret <SECRET>
 * --forward-id 170 --target <TARGET>), so this pins PHP normalizeTarget and
 * the HMAC to protocol.go byte for byte.
 */

spl_autoload_register(static function (string $class): void {
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen('Leikwan\\Master\\'))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Support\ExternalUotCredentials;

$failures = 0;
function checkToken(bool $ok, string $label): void
{
    global $failures;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL $label\n");
    }
}

$secret = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
$vectors = [
    '1.2.3.4:53' => ['1.2.3.4:53', 'hmac-sha256:9f735a3755e0e022eee79297d7b4bf875c3b3d562e9d43166d2a7f2966f29c04'],
    ' Example.COM:443 ' => ['example.com:443', 'hmac-sha256:e42189027e1530d35f3e0b97084d5fd9603a89de3ffaf5782d5c19cbed108216'],
    '[2001:DB8::1]:8443' => ['2001:db8::1:8443', 'hmac-sha256:3427798215225b2928923918b54741e663dc9372751e25f601e39a06f9ac3391'],
    '[::ffff:1.2.3.4]:80' => ['::ffff:1.2.3.4:80', 'hmac-sha256:6e60532c9338346915f5a3f3233234e263f076febaeaf52faead3759316d00c5'],
    // Go prints an IPv4-compatible (not mapped) address in hex, unlike glibc inet_ntop.
    '[::1.2.3.4]:80' => ['::102:304:80', 'hmac-sha256:c79bad1c2c6d56fe15a9f7e7c2acb3a81cdb2f47be9bd22abca302ef4c798caf'],
    // Unbracketed IPv6 splits on the last colon; the first longest zero run is compressed.
    '2001:db8:0:0:1:0:0:1:443' => ['2001:db8::1:0:0:1:443', 'hmac-sha256:7c4a58db885c3856c63b0b0f0fbb6b3e766794e4a85e15463920b295ff079ee3'],
    '[2001:0:0:1:0:0:0:1]:443' => ['2001:0:0:1::1:443', 'hmac-sha256:b4aa43db0068281aada9283e3ac0b66e472185f57bf8748a8cd3258afaad1e32'],
    '[fe80::1%Eth0]:53' => ['fe80::1%eth0:53', 'hmac-sha256:f1eef90de5de01face2580bbe936ede79569d6d49836916ed6249cca51927216'],
    // strconv.Atoi accepts a sign and leading zeros.
    '1.2.3.4:+053' => ['1.2.3.4:53', 'hmac-sha256:9f735a3755e0e022eee79297d7b4bf875c3b3d562e9d43166d2a7f2966f29c04'],
];
foreach ($vectors as $target => [$normalized, $token]) {
    checkToken(ExternalUotCredentials::normalizeTarget($target) === $normalized, "normalize $target");
    checkToken(ExternalUotCredentials::token($secret, 170, $target) === $token, "token $target");
}

foreach (['', '1.2.3.4', ':53', '1.2.3.4:0', '1.2.3.4:65536', '1.2.3.4:-1', '1.2.3.4:5x', 'a b:53', '[::1]53', '[::1', "h\xC3\xA9.example:53"] as $bad) {
    checkToken(ExternalUotCredentials::normalizeTarget($bad) === null, 'rejects ' . var_export($bad, true));
}
// Not IPs to Go either, so they stay plain lower-cased hosts.
checkToken(ExternalUotCredentials::normalizeTarget('01.2.3.4:53') === '01.2.3.4:53', 'leading-zero IPv4 is a hostname');
checkToken(ExternalUotCredentials::normalizeTarget('[fe80::1%]:53') === 'fe80::1%:53', 'empty zone is not an IP');
checkToken(ExternalUotCredentials::token('', 170, '1.2.3.4:53') === null, 'no secret, no token');
checkToken(ExternalUotCredentials::token($secret, 0, '1.2.3.4:53') === null, 'no forward, no token');

$envPath = tempnam(sys_get_temp_dir(), 'lw-uot-flag-');
foreach (['' => true, 'EXTERNAL_UOT_ACCEPT_STATIC_TOKEN=1' => true, 'EXTERNAL_UOT_ACCEPT_STATIC_TOKEN=true' => true,
    'EXTERNAL_UOT_ACCEPT_STATIC_TOKEN=0' => false, 'EXTERNAL_UOT_ACCEPT_STATIC_TOKEN=false' => false] as $line => $expected) {
    file_put_contents($envPath, $line . "\n");
    checkToken(ExternalUotCredentials::acceptStaticToken(new Config($envPath)) === $expected, 'static token flag ' . ($line ?: 'default'));
}
@unlink($envPath);

if ($failures > 0) {
    fwrite(STDERR, "external_uot_hmac_token_test: $failures failed\n");
    exit(1);
}
echo "external_uot_hmac_token_test: ok\n";
