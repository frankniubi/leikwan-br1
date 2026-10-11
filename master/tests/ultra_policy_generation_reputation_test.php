<?php
declare(strict_types=1);

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
use Leikwan\Master\Support\LeigodTokenBroker;
use Leikwan\Master\Support\UltraCandidateReputationService;
use Leikwan\Master\Support\UltraMaterialService;

function failUltraPolicyGeneration(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertUltraPolicyGeneration(bool $condition, string $message): void
{
    if (!$condition) {
        failUltraPolicyGeneration($message);
    }
}

$now = 1_900_100_000;
$envPath = tempnam(sys_get_temp_dir(), 'lw-ultra-generation-env-');
if ($envPath === false) {
    failUltraPolicyGeneration('failed to create env file');
}
file_put_contents(
    $envPath,
    "ULTRA_TOKEN_KEY=token-key-for-generation-tests\n"
    . "ULTRA_SOURCE_ID_KEY=source-a\n"
    . "ULTRA_CANDIDATE_QUARANTINE_ENABLED=1\n"
);
$config = new Config($envPath);
$clock = static function () use (&$now): int {
    return $now;
};
$reputation = new UltraCandidateReputationService(null, $clock);
$materials = new UltraMaterialService($config, null, null, $clock, $reputation);
$ticket = $materials->issueUploadTicket('source-a', [347]);
$accepted = $materials->acceptSourceUpload('source-a', $ticket, [[
    'line_id' => 347,
    'generation' => 'generation-347',
    'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
    'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1800),
    'user' => 'line-user',
    'credential' => 'line-password',
    'relays' => [['ip' => '203.0.113.47', 'port' => 5438]],
]]);
assertUltraPolicyGeneration($accepted === [347], 'source material was not accepted');

$broker = new LeigodTokenBroker($config, null, $materials, $clock);
$forward = [[
    'forward_id' => 42,
    'service_id' => 7,
    'service_status' => 'active',
    'backend_mode' => 'ultra',
    'target' => '198.51.100.10:443',
    'transport' => 'tcp+udp',
    'speed_limit_mbps' => 20,
    '_ultra_lines' => [[
        'line_id' => 347,
        'source_id' => 'source-a',
        'ingress_zone' => 'guangzhou',
        'destination_zone' => 'hong-kong',
    ]],
]];
$firstGrant = $broker->grantsForClientForwards(7, 'device-a', $forward, true)['42'] ?? [];
$firstGeneration = (string) ($firstGrant['policy_generation'] ?? '');
assertUltraPolicyGeneration(preg_match('/^[a-f0-9]{64}$/', $firstGeneration) === 1, 'initial policy generation missing');

$scope = [
    'source_id' => 'source-a',
    'line_id' => 347,
    'ingress_zone' => 'guangzhou',
    'destination_zone' => 'hong-kong',
    'protocol' => 'tcp+udp',
];
$reputation->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'generation-1');
$reputation->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'generation-2');
$reputation->observeFailure($scope, 'relay_connect_timeout', 'observer-b', 'generation-3');
$secondGrant = $broker->grantsForClientForwards(7, 'device-a', $forward, true)['42'] ?? [];
$secondGeneration = (string) ($secondGrant['policy_generation'] ?? '');
assertUltraPolicyGeneration($secondGeneration !== '' && !hash_equals($firstGeneration, $secondGeneration), 'quarantine did not rotate policy generation');

@unlink($envPath);
echo "ok\n";
