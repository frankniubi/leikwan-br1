<?php
declare(strict_types=1);

// The forward/client-specific PowerShell wrappers interpolate query values
// into a generated script. PowerShell also treats U+2018..U+201B as single
// quotes, so doubling only ASCII "'" let
//   /artifacts/install-client.ps1?forward_id=1&token=probe%E2%80%99%3BWrite-Host%20INJECTED%3B%23
// break out of the literal. Tokens are now whitelisted and the quoting helper
// doubles the curly quotes too.

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

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\ArtifactController;
use Leikwan\Master\Http\Request;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$livePayload = rawurldecode('probe%E2%80%99%3BWrite-Host%20INJECTED%3B%23');
if ($livePayload !== "probe\u{2019};Write-Host INJECTED;#") {
    fail('live payload decoding drifted');
}

$validTokens = [
    bin2hex(random_bytes(24)),       // clients.agent_token / services.client_token / users.api_token
    bin2hex(random_bytes(32)),
    'agent-token-27',
    'Abc_DEF-123',
    str_repeat('a', 256),
];
$invalidTokens = [
    'live payload' => $livePayload,
    'left single quotation mark' => "probe\u{2018};Write-Host INJECTED;#",
    'single low-9 quotation mark' => "probe\u{201A};Write-Host INJECTED;#",
    'single high-reversed-9 quotation mark' => "probe\u{201B};Write-Host INJECTED;#",
    'ascii quote' => "probe';Write-Host INJECTED;#",
    'subexpression' => 'probe$(calc)',
    'semicolon' => 'a;b',
    'space' => 'a b',
    'backtick' => 'a`b',
    'double quote' => 'a"b',
    'newline' => "ab\ncd",
    'trailing newline' => "abc\n", // isInstallerToken must not accept it (no bare $); the route trims it first
    'too long' => str_repeat('a', 257),
    'empty' => '',
];

foreach ($validTokens as $token) {
    if (!ArtifactController::isInstallerToken($token)) {
        fail('valid token rejected: ' . $token);
    }
}
foreach ($invalidTokens as $label => $token) {
    if (ArtifactController::isInstallerToken($token)) {
        fail('invalid token accepted: ' . $label);
    }
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-installer-token-');
if ($envPath === false) {
    fail('failed to create temp env');
}
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\nAPP_URL=https://panel.example.com\n");
$controller = new ArtifactController(new Config($envPath));

// Exact live payload, both wrappers.
$installAttack = $controller->installClientPowerShell(new Request('GET', '/artifacts/install-client.ps1', ['forward_id' => '1', 'token' => $livePayload], [], [], ''), []);
if ($installAttack->status() !== 400) {
    fail('install-client.ps1 must reject the live payload with 400, got ' . $installAttack->status());
}
if (str_contains($installAttack->body(), 'INJECTED')) {
    fail('rejected install-client.ps1 response must not echo the payload');
}
$manageAttack = $controller->manageClientPowerShell(new Request('GET', '/artifacts/manage-client.ps1', ['client_id' => '1', 'agent_token' => $livePayload], [], [], ''), []);
if ($manageAttack->status() !== 400) {
    fail('manage-client.ps1 must reject the live payload with 400, got ' . $manageAttack->status());
}
foreach ($invalidTokens as $label => $token) {
    if (trim($token) === '' || $label === 'trailing newline') {
        continue; // empty serves the generic installer; surrounding whitespace is trimmed by the route
    }
    $response = $controller->installClientPowerShell(new Request('GET', '/artifacts/install-client.ps1', ['forward_id' => '7', 'token' => $token], [], [], ''), []);
    if ($response->status() !== 400) {
        fail('install-client.ps1 must reject ' . $label . ', got ' . $response->status());
    }
}

// Valid tokens still produce the wrapper.
$hexToken = bin2hex(random_bytes(24));
$install = $controller->installClientPowerShell(new Request('GET', '/artifacts/install-client.ps1', ['forward_id' => '12', 'token' => $hexToken], [], [], ''), []);
if ($install->status() !== 200) {
    fail('install-client.ps1 must accept a hex token, got ' . $install->status());
}
if (!str_contains($install->body(), "-ForwardId 12 -Token '" . $hexToken . "'")) {
    fail('install-client.ps1 wrapper must pass the token as a single-quoted literal');
}
if (($install->headers()['Content-Type'] ?? '') !== 'text/plain; charset=utf-8') {
    fail('install-client.ps1 wrapper content type changed');
}
$manage = $controller->manageClientPowerShell(new Request('GET', '/artifacts/manage-client.ps1', ['client_id' => '5', 'agent_token' => $hexToken], [], [], ''), []);
if ($manage->status() !== 200 || !str_contains($manage->body(), "-ClientId 5 -AgentToken '" . $hexToken . "'")) {
    fail('manage-client.ps1 must accept a hex agent token');
}

// Defense in depth: the quoting helpers double every PowerShell single quote.
$quote = new ReflectionMethod(ArtifactController::class, 'powerShellSingleQuoted');
$quote->setAccessible(true);
$baseQuote = new ReflectionMethod(\Leikwan\Master\Controllers\BaseController::class, 'powershellSingleQuote');
$baseQuote->setAccessible(true);
$input = "a'b\u{2018}c\u{2019}d\u{201A}e\u{201B}f";
$expected = "'a''b\u{2018}\u{2018}c\u{2019}\u{2019}d\u{201A}\u{201A}e\u{201B}\u{201B}f'";
if ($quote->invoke($controller, $input) !== $expected) {
    fail('ArtifactController::powerShellSingleQuoted must double ASCII and curly single quotes');
}
if ($baseQuote->invoke($controller, $input) !== $expected) {
    fail('BaseController::powershellSingleQuote must double ASCII and curly single quotes');
}
$quotedPayload = $quote->invoke($controller, $livePayload);
if ($quotedPayload !== "'probe\u{2019}\u{2019};Write-Host INJECTED;#'") {
    fail('live payload must stay inside the PowerShell literal');
}

// The .sh installers are served verbatim (no query interpolation); make sure
// that stays true so the token whitelist above is the only interpolation path.
$artifactSource = (string) file_get_contents(__DIR__ . '/../src/Controllers/ArtifactController.php');
if (substr_count($artifactSource, 'isInstallerToken($') !== 2) {
    fail('both PowerShell wrapper routes must validate their token');
}
if (preg_match('/function install(?:Client|ClientAgent|Slave|BondSidecar)\(Request \$request, array \$params\): Response\s*\{\s*return \$this->serveRepoFile\(/', $artifactSource) !== 1) {
    fail('shell installers must be served verbatim');
}

@unlink($envPath);
echo "installer_token_injection_test: ok\n";
