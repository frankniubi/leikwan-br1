<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', (string) $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Controllers\ClientAgentController;
use Leikwan\Master\Support\LeigodTokenProvider;
use Leikwan\Master\Support\UltraMode;

function failMacUltra(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertMacUltra(bool $condition, string $message): void
{
    if (!$condition) {
        failMacUltra($message);
    }
}

$controllerType = new ReflectionClass(ClientAgentController::class);
assertMacUltra($controllerType->hasMethod('usesUltraPolicyV2'), 'macOS Ultra v2 platform gate is missing');
$platformGate = $controllerType->getMethod('usesUltraPolicyV2');
foreach (['windows-desktop', 'linux', 'macos-desktop', 'macos-arm64'] as $platform) {
    assertMacUltra($platformGate->invoke(null, $platform) === true, $platform . ' must use Ultra policy-v2');
}
assertMacUltra($platformGate->invoke(null, 'legacy-agent') === false, 'legacy agents must keep their existing contract');

$provider = new class implements LeigodTokenProvider {
    public bool $policyV2 = false;

    public function grantsForClientForwards(int $clientId, string $deviceId, array $forwards, bool $policyV2 = false): array
    {
        $this->policyV2 = $policyV2;
        return [
            '42' => [
                'protocol' => UltraMode::POLICY_PROTOCOL,
                'forward_id' => 42,
                'session_ticket' => 'short-lived-ticket',
                'policy_generation' => str_repeat('a', 64),
                'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 300),
                'service_state' => 'active',
                'transport' => 'tcp',
                'rate_limit_mbps' => 20,
                'quota_remaining_bytes' => 1048576,
                'target_digest' => hash('sha256', '198.51.100.10:443'),
            ],
        ];
    }
};

$controller = $controllerType->newInstanceWithoutConstructor();
$providerProperty = $controllerType->getProperty('ultraTokenProvider');
$providerProperty->setValue($controller, $provider);
$attach = $controllerType->getMethod('attachUltraAccessGrants');
$forwards = [[
    'forward_id' => 42,
    'backend_mode' => 'ultra',
    'service_status' => 'active',
    'mode' => 'single',
    'target' => '198.51.100.10:443',
    '_ultra_lines' => [['ip' => '203.0.113.20', 'credential' => 'provider-password']],
    '_ultra_line_ids' => [9001],
    'client_token' => 'reusable-client-token',
    'credential' => 'provider-password',
    'password' => 'provider-password',
]];
$payload = [
    'forwards' => $attach->invoke($controller, $forwards, 7, 'device-a', $platformGate->invoke(null, 'macos-desktop')),
];
$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
foreach (['_ultra_lines', '_ultra_line_ids', 'client_token', 'credential', 'password', '203.0.113.20'] as $forbidden) {
    assertMacUltra(!str_contains($encoded, $forbidden), 'Mac pull leaked ' . $forbidden);
}
assertMacUltra($provider->policyV2, 'Mac pull did not request a policy-v2 grant');
assertMacUltra(
    ($payload['forwards'][0]['ultra_access']['protocol'] ?? '') === UltraMode::POLICY_PROTOCOL,
    'Mac pull did not select policy-v2'
);

echo "ok\n";
