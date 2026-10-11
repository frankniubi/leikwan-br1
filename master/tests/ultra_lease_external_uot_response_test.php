<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use Leikwan\Master\Controllers\ClientAgentController;
use Leikwan\Master\Support\ApiException;

function requireUotResponse(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$class = new ReflectionClass(ClientAgentController::class);
requireUotResponse($class->hasMethod('attachExternalUotToLease'), 'v2 lease sidecar attachment is missing');
$controller = $class->newInstanceWithoutConstructor();
$attach = $class->getMethod('attachExternalUotToLease');
$digest = hash('sha256', '203.0.113.9:443');
$lease = ['forward_id' => 7, 'policy_generation' => str_repeat('a', 64), 'target_digest' => $digest];
$external = [
    'enabled' => true, 'sidecar_target' => '192.0.2.10:7443',
    'token' => 'test-only-sidecar-token', 'target_digest' => $digest,
    'queue_packets' => 512, 'keepalive_seconds' => 15,
    'reconnect_grace_seconds' => 45, 'frame_payload_max' => 4096,
];
$grant = $lease + ['external_uot' => $external];
$result = $attach->invoke($controller, $lease, $grant, false);
requireUotResponse(($result['external_uot'] ?? null) === $external, 'desktop lease lost normalized sidecar configuration');
requireUotResponse(array_diff_key($result, ['external_uot' => true]) === $lease, 'attachment modified lease authorization');
requireUotResponse(!isset($attach->invoke($controller, $result, $grant, true)['external_uot']), 'native SOCKS5 received incompatible sidecar configuration');
requireUotResponse(!isset($attach->invoke($controller, $result, $lease, false)['external_uot']), 'absent grant retained stale sidecar credentials');
requireUotResponse(!isset($attach->invoke($controller, $result, $lease + ['external_uot' => ['enabled' => false]], false)['external_uot']), 'disabled grant retained sidecar credentials');

foreach (['forward_id' => 8, 'policy_generation' => str_repeat('b', 64), 'target_digest' => hash('sha256', 'other:443')] as $field => $value) {
    $mismatched = $lease;
    $mismatched[$field] = $value;
    try {
        $attach->invoke($controller, $mismatched, $grant, false);
        throw new RuntimeException('scope mismatch accepted: ' . $field);
    } catch (ApiException $error) {
        requireUotResponse($error->getMessage() === 'external_uot_scope_mismatch', 'unexpected mismatch error');
    }
}
$source = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
requireUotResponse(str_contains($source, '$candidateLease = $this->attachExternalUotToLease($result[\'lease\'], $grant, $isNative);'),
    'real lease endpoint does not call the tested attachment boundary');
echo "ok\n";
