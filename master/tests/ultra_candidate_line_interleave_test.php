<?php
declare(strict_types=1);

// A client is leased at most four candidates. Listing every relay of the first
// line before the next line handed a client three relays of one line and one of
// another, so re-acquiring that one line (which kills its credential) took out
// three of the four at once. Candidates are interleaved across lines instead.

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
use Leikwan\Master\Support\UltraCandidateReputationService;
use Leikwan\Master\Support\UltraMaterialService;

function assertUltraInterleave(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$now = 1_900_000_000;
$envPath = tempnam(sys_get_temp_dir(), 'lw-ultra-interleave-env-');
assertUltraInterleave($envPath !== false, 'failed to create env file');
file_put_contents(
    $envPath,
    "ULTRA_TOKEN_KEY=token-key-for-interleave-tests\n"
    . "ULTRA_SOURCE_ID_KEY=source-a\n"
    . "ULTRA_MATERIAL_MAX_TTL_SECONDS=2100\n"
);
$config = new Config($envPath);
$clock = static function () use (&$now): int {
    return $now;
};
$materials = new UltraMaterialService($config, null, null, $clock, new UltraCandidateReputationService(null, $clock));

$lines = [818 => 10, 15 => 20, 684 => 30];
foreach ($lines as $lineId => $octet) {
    $ticket = $materials->issueUploadTicket('source-a', [$lineId]);
    $relays = [];
    for ($relay = 1; $relay <= 3; $relay++) {
        $relays[] = ['ip' => '203.0.113.' . ($octet + $relay), 'port' => 5438];
    }
    $accepted = $materials->acceptSourceUpload('source-a', $ticket, [[
        'line_id' => $lineId,
        'generation' => 'material-' . $lineId,
        'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1200),
        'user' => 'line-user-' . $lineId,
        'credential' => 'line-password-' . $lineId,
        'relays' => $relays,
    ]]);
    assertUltraInterleave($accepted === [$lineId], 'material was not accepted for line ' . $lineId);
}

$generation = str_repeat('b', 64);
$token = $materials->issueClientToken([
    'client_id' => 7,
    'device_hash' => hash('sha256', 'device-a'),
    'forward_id' => 42,
    'policy_generation' => $generation,
    'transport' => 'tcp',
    'destination_zone' => 'hong-kong',
    'lines' => array_map(
        static fn(int $lineId): array => ['source_id' => 'source-a', 'line_id' => $lineId, 'ingress_zone' => 'shanghai', 'destination_zone' => 'hong-kong'],
        array_keys($lines)
    ),
], $now + 1200);
assertUltraInterleave($token !== '', 'client token was not issued');

$lineOfIp = static function (string $ip) use ($lines): int {
    $octet = (int) substr($ip, strrpos($ip, '.') + 1);
    foreach ($lines as $lineId => $base) {
        if ($octet > $base && $octet <= $base + 3) {
            return $lineId;
        }
    }
    return 0;
};

$order = [];
for ($offset = 0; $offset < 8; $offset++) {
    $candidate = $materials->serverCandidateForClientToken($token, 7, 'device-a', 42, $generation, $offset);
    if ($candidate === []) {
        break;
    }
    $order[] = [$lineOfIp((string) $candidate['ip']), (string) $candidate['ip']];
}
assertUltraInterleave(count($order) === 8, 'expected offsets 0..7 to resolve, got ' . count($order));
$firstFour = array_map(static fn(array $entry): int => $entry[0], array_slice($order, 0, 4));
assertUltraInterleave(
    $firstFour === [818, 15, 684, 818],
    'the four leased candidates must span every line in panel order, got ' . implode(',', $firstFour)
);
assertUltraInterleave($order[0][1] === '203.0.113.11' && $order[3][1] === '203.0.113.12', 'relay order inside a line changed');
assertUltraInterleave(count(array_unique(array_map(static fn(array $entry): string => $entry[1], $order))) === 8, 'a relay was issued twice');

echo "ok\n";
