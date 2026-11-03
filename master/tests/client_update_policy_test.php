<?php
declare(strict_types=1);

// The minimum client version is only handed to the GUI clients that ship the update
// gate. Linux, OpenWrt and callers with no recognised platform must never see it, and
// nothing is sent until the operator sets CLIENT_MINIMUM_VERSIONS_JSON.

use Leikwan\Master\Support\ClientUpdatePolicy;

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

$config = '{"windows-desktop":"0.1.20.69","macos-desktop":"1.0.59","android-host":"0.2.45","ios":52,"linux":"2026.10.30.1","openwrt":"1.2.0"}';

$expected = [
    'windows-desktop' => ['minimum_version' => '0.1.20.69'],
    'macos-desktop' => ['minimum_version' => '1.0.59'],
    'android-host' => ['minimum_version' => '0.2.45'],
    'ios' => ['minimum_build' => 52],
];
foreach ($expected as $platform => $payload) {
    if (ClientUpdatePolicy::forPlatform($platform, $config) !== $payload) {
        fail("$platform must receive " . json_encode($payload));
    }
}
if (ClientUpdatePolicy::forPlatform(' IOS ', $config) !== ['minimum_build' => 52]) {
    fail('platform matching must ignore case and surrounding space');
}

foreach (['linux', 'openwrt', '', 'windows', 'macos', 'unknown'] as $platform) {
    if (ClientUpdatePolicy::forPlatform($platform, $config) !== null) {
        fail("'$platform' must never receive client_update, even when the operator lists it");
    }
}

foreach (['', '   ', 'not json', '[]', '{}', '"x"'] as $empty) {
    foreach (array_keys($expected) as $platform) {
        if (ClientUpdatePolicy::forPlatform($platform, $empty) !== null) {
            fail("nothing is sent while the policy is unset or unreadable (input: $empty)");
        }
    }
}

$bad = [
    ['windows-desktop', '{"windows-desktop":"latest"}'],
    ['windows-desktop', '{"windows-desktop":"1.2.3.4.5"}'],
    ['windows-desktop', '{"windows-desktop":"1..2"}'],
    ['windows-desktop', '{"windows-desktop":105}'],
    ['android-host', '{"android-host":"0.2.45-beta"}'],
    ['macos-desktop', '{"macos-desktop":null}'],
    ['ios', '{"ios":0}'],
    ['ios', '{"ios":-3}'],
    ['ios', '{"ios":"abc"}'],
    ['ios', '{"ios":1.5}'],
    ['ios', '{"ios":1000000000}'],
];
foreach ($bad as [$platform, $json]) {
    if (ClientUpdatePolicy::forPlatform($platform, $json) !== null) {
        fail("an invalid value must be dropped, not forwarded: $json");
    }
}

if (ClientUpdatePolicy::forPlatform('ios', '{"ios":{"build":"61"}}') !== ['minimum_build' => 61]) {
    fail('iOS accepts {"build": N} and numeric strings');
}

if (ClientUpdatePolicy::revisionMarker('') !== '' || ClientUpdatePolicy::revisionMarker('  ') !== '') {
    fail('an unset policy must leave the config revision untouched');
}
$a = ClientUpdatePolicy::revisionMarker('{"ios":52}');
$b = ClientUpdatePolicy::revisionMarker('{"ios":53}');
if ($a === '' || $a === $b) {
    fail('changing the policy must change the revision marker so long polls end');
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
if ($source === false) {
    fail('failed to read ClientAgentController');
}
if (substr_count($source, 'ClientUpdatePolicy::forPlatform(') !== 1) {
    fail('the pull response must build client_update in exactly one place');
}
if (!str_contains($source, "\$revisionExtra['client_update_policy']")) {
    fail('the policy must be folded into the config revision');
}

echo "ok\n";
