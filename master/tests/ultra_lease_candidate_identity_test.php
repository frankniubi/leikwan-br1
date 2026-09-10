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
use Leikwan\Master\Support\UltraLeaseService;

function failUltraLeaseIdentity(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertUltraLeaseIdentity(bool $condition, string $message): void
{
    if (!$condition) {
        failUltraLeaseIdentity($message);
    }
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-ultra-lease-identity-');
if ($envPath === false) {
    failUltraLeaseIdentity('failed to create env file');
}
$generation = str_repeat('a', 64);
$candidateHash = str_repeat('b', 64);
file_put_contents($envPath, "ULTRA_TOKEN_KEY=lease-identity-key\n");
$service = new UltraLeaseService(
    new Config($envPath),
    null,
    null,
    static fn(array $request, int $clientId, string $deviceId): array => [
        'selection_ref' => str_repeat('r', 43),
        'candidate_key_hash' => $candidateHash,
        'ip' => '203.0.113.20',
        'port' => 5438,
        'user' => 'line-user',
        'credential' => 'line-password',
    ],
    static fn(): int => 1_900_000_000,
    static fn(int $clientId, string $deviceId, int $forwardId): array => [
        'forward_id' => $forwardId,
        'device_hash' => hash('sha256', $deviceId),
        'service_state' => 'active',
        'policy_generation' => $generation,
        'target' => '198.51.100.10:443',
        'transport' => 'tcp',
        'rate_limit_mbps' => 20,
        'quota_remaining_bytes' => 1024,
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', 1_900_000_300),
    ],
);
$lease = $service->issueLease([
    'forward_id' => 42,
    'session_ticket' => 'ticket-a',
    'policy_generation' => $generation,
    'nonce' => 'abcdef0123456789abcdef0123456789',
    'target_digest' => hash('sha256', '198.51.100.10:443'),
    'transport' => 'tcp',
], 7, 'device-a');
assertUltraLeaseIdentity(($lease['ok'] ?? false) === true, 'valid lease was not issued');
assertUltraLeaseIdentity(($lease['lease']['candidate_key_hash'] ?? '') === $candidateHash, 'candidate hash was not carried in lease');
assertUltraLeaseIdentity(!array_key_exists('line_id', $lease['lease']), 'lease exposed raw line ID');

$migration = (string) file_get_contents(__DIR__ . '/../migrations/20260910_add_ultra_candidate_reputation.sql');
assertUltraLeaseIdentity(str_contains($migration, 'candidate_key_hash'), 'reputation migration lacks candidate key hash');
$leaseMigration = (string) file_get_contents(__DIR__ . '/../migrations/20260910_add_ultra_lease_candidate_identity.sql');
assertUltraLeaseIdentity(str_contains($leaseMigration, 'ultra_client_leases'), 'lease audit migration was not extended');

@unlink($envPath);
echo "ok\n";
