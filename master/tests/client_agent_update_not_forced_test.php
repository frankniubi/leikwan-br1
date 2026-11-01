<?php
declare(strict_types=1);

// Client agents upgrade themselves whenever the pull payload's desired_* values differ
// from what they run. Publishing a release must not do that on its own: the target
// versions are only handed out while an update requested from the console is pending.

use Leikwan\Master\Controllers\ClientAgentController;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$now = strtotime('2026-10-12 12:00:00 UTC');

if (ClientAgentController::clientUpdateRequestIsPending('', '', $now)) {
    fail('no console request must not count as a pending update');
}
if (ClientAgentController::clientUpdateRequestIsPending('', '2026-10-12 11:00:00', $now)) {
    fail('a timestamp without a token must not count as a pending update');
}
if (ClientAgentController::clientUpdateRequestIsPending('token', '', $now)) {
    fail('a token without a request time must not count as a pending update');
}
if (!ClientAgentController::clientUpdateRequestIsPending('token', '2026-10-12 11:59:00', $now)) {
    fail('a request made a minute ago must be pending');
}
if (!ClientAgentController::clientUpdateRequestIsPending('token', '2026-10-09 12:00:00', $now)) {
    fail('a request stays pending for 72 hours so an offline agent can still pick it up');
}
if (ClientAgentController::clientUpdateRequestIsPending('token', '2026-10-09 11:59:59', $now)) {
    fail('a request older than 72 hours must expire, or later releases would install without being asked for');
}
if (ClientAgentController::clientUpdateRequestIsPending('token', 'not a date', $now)) {
    fail('an unreadable request time must not count as a pending update');
}

$desired = ['desired_agent_version' => '2026.10.12.1', 'desired_agent_sha256' => 'abc'];
if (ClientAgentController::desiredClientArtifacts(true, $desired) !== $desired) {
    fail('a pending request must hand out the target versions');
}
if (ClientAgentController::desiredClientArtifacts(false, $desired) !== ['desired_agent_version' => '', 'desired_agent_sha256' => '']) {
    fail('without a request every desired_* value must be blank (the keys stay for older parsers)');
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
if ($source === false) {
    fail('failed to read ClientAgentController');
}
if (str_contains($source, 'auto-client-agent')) {
    fail('the panel must not synthesize automatic client-agent update tokens');
}
if (!str_contains($source, '...self::desiredClientArtifacts($updateRequested, [')) {
    fail('the pull payload must gate the desired_* fields on a pending console request');
}
if (!str_contains($source, "\$revisionExtra['update_request'] = \$updateRequestToken;")) {
    fail('a pending update request must change config_revision so long-polling agents see the click');
}
if (preg_match("/^\\s{16}'desired_[a-z_]+' =>/m", $source) === 1) {
    fail('a desired_* field is emitted outside the request gate');
}

// Routers: the ipk URL rides inside the same gate, so a blank request blanks it too.
$router = $desired + ['desired_agent_url' => '/artifacts/files/openwrt/arm64/leikwand_1.2.2-r1_aarch64_generic.ipk'];
if (ClientAgentController::desiredClientArtifacts(false, $router)['desired_agent_url'] !== '') {
    fail('without a request the router must not be handed an ipk to install');
}
$gateAt = strpos($source, '...self::desiredClientArtifacts($updateRequested, [');
$urlAt = strpos($source, "'desired_agent_url' =>");
$gateEndAt = strpos($source, "'online_device_limit' =>");
if ($gateAt === false || $urlAt === false || $gateEndAt === false || !($gateAt < $urlAt && $urlAt < $gateEndAt)) {
    fail('desired_agent_url must be emitted inside the request gate');
}
// leikwand keeps a degraded "linux" platform tag for its process lifetime; its
// User-Agent is what keeps it off the python agent's version and file.
if (!str_contains($source, "str_starts_with(\$userAgent, 'leikwan-openwrt-agent/')")) {
    fail('a router that degraded its platform tag must still be recognised as a router');
}

echo "ok\n";
